<?php

namespace App\Filament\Resources;

use App\Enums\OrderStatus;
use App\Enums\OrderProductionStatus;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use App\Models\User;
use App\Models\Branch;
use App\Models\BillingProcess;
use App\Models\Integration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Pelmered\FilamentMoneyField\Forms\Components\MoneyInput;
use Pelmered\FilamentMoneyField\Tables\Columns\MoneyColumn;
use Filament\Forms\Components\Hidden;
use Closure;
use Filament\Forms\Get;
use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Exports\OrderLineConsolidatedExport;
use App\Exports\OrderLineExport;
use App\Services\OrderLineExportService;
use App\Forms\OrderExportFilterForm;
use App\Forms\AdvanceOrderGenerationForm;
use App\Models\ExportProcess;
use App\Models\AdvanceOrder;
use App\Models\ProductionArea;
use App\Models\OrderLine;
use App\Repositories\OrderRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Log;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;
use App\Imports\OrderLinesImport;
use App\Jobs\DeleteOrders;
use App\Jobs\GenerateOrderVouchersJob;
use App\Jobs\SendOrdersEmails;
use App\Models\ImportProcess;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\ActionSize;
use Filament\Tables\Actions\ActionGroup as ActionsActionGroup;
use Maatwebsite\Excel\Facades\Excel;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'iconsax-bro-receipt-item';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('Almacén');
    }

    public static function getLabel(): ?string
    {
        return __('Pedido');
    }

    public static function getNavigationLabel(): string
    {
        return __('Pedidos');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make()
                    ->columns(3)
                    ->schema([
                        Hidden::make('id'),
                        Forms\Components\TextInput::make('order_number')
                            ->label(__('Número de Orden'))
                            ->disabled()
                            ->columnSpan(1),
                        Forms\Components\Select::make('user_id')
                            ->options(User::customers()->pluck('name', 'id'))
                            ->label(__('Cliente'))
                            ->searchable()
                            ->disabledOn('edit'),
                        MoneyInput::make('total')
                            ->label(__('Total Neto'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
                            ->decimals(2)
                            ->rules([
                                fn(Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                    $order = Order::find($get('id'));
                                    if ($order && $order->user->validate_min_price && $order->total < $order->price_list_min) {
                                        $fail(__("El total del pedido debe ser igual o mayor al precio mínimo de la lista de precios."));
                                    }
                                },
                            ])
                            ->disabled(),
                        MoneyInput::make('total_with_tax')
                            ->label(__('Total con impuesto'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
                            ->decimals(2)
                            ->disabled(),
                        MoneyInput::make('price_list_min')
                            ->label(__('Monto mínimo'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
                            ->decimals(2)
                            ->disabled(),
                        MoneyInput::make('dispatch_cost')
                            ->label(__('Costo de despacho'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
                            ->decimals(2)
                            ->disabled(),
                        Forms\Components\Toggle::make('charge_dispatch')
                            ->label(__('Cobrar transporte'))
                            ->default(true)
                            ->disabledOn('create')
                            ->helperText(__('Activar para cobrar el costo de transporte en este pedido. Recarga la página después de guardar para ver los cambios.')),
                        MoneyInput::make('tax_amount')
                            ->label(__('IVA'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
                            ->decimals(2)
                            ->disabled()
                            ->helperText(__('Impuesto sobre productos y despacho')),
                        MoneyInput::make('grand_total')
                            ->label(__('Total Final'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
                            ->decimals(2)
                            ->disabled()
                            ->helperText(__('Total + Despacho + impuestos')),
                        Forms\Components\Select::make('status')
                            ->label(__('Estado'))
                            ->options(OrderStatus::getSelectOptions())
                            ->default(OrderStatus::PENDING->value)
                            ->disabledOn('create'),
                        Forms\Components\DateTimePicker::make('created_at')
                            ->label(__('Fecha de creación'))
                            ->readOnly(),
                        Forms\Components\Select::make('branch_id')
                            ->label(__('Dirección de despacho'))
                            ->options(function (Get $get) {
                                $userId = $get('user_id');
                                if ($userId) {
                                    $user = User::find($userId);
                                    if ($user && $user->company) {
                                        return $user->company->branches->pluck('shipping_address', 'id');
                                    }
                                }
                                return [];
                            })
                            ->searchable()
                            ->disabledOn('create'),
                        Forms\Components\Textarea::make('alternative_address')
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Otra dirección'))
                            ->columnSpanFull()
                            ->disabledOn('create'),
                        Forms\Components\DateTimePicker::make('dispatch_date')
                            ->label(__('Fecha de despacho'))
                            ->disabledOn('create'),
                        Forms\Components\Textarea::make('user_comment')
                            ->label(__('Comentario de usuario'))
                            ->maxLength(240)
                            ->columnSpanFull(),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('dispatch_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('dispatch_date')
                    ->label(__('Fechas'))
                    ->sortable()
                    ->formatStateUsing(fn(string $state) => 'Fecha de despacho: ' . \Carbon\Carbon::parse($state)->format('d/m/Y'))
                    ->searchable()
                    ->description(fn(Order $order) => 'Fecha de pedido: ' . $order->created_at->format('d/m/Y')),
                Tables\Columns\TextColumn::make('id')
                    ->label(__('ID / Número'))
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('id', 'like', "%{$search}%")
                            ->orWhere('order_number', 'like', "%{$search}%");
                    })
                    ->formatStateUsing(fn(string $state) => 'ID: ' . $state)
                    ->description(fn(Order $order) => 'N°: ' . ($order->order_number ? '...' . substr($order->order_number, -10) : 'Sin número')),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('Cliente / Empresa'))
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user', function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%")
                              ->orWhere('nickname', 'like', "%{$search}%");
                        })->orWhereHas('user.company', function (Builder $q) use ($search) {
                            $q->where('fantasy_name', 'like', "%{$search}%");
                        })->orWhereHas('user.branch', function (Builder $q) use ($search) {
                            $q->where('fantasy_name', 'like', "%{$search}%");
                        });
                    })
                    ->formatStateUsing(fn(string $state, Order $order) =>
                        'Cliente: ' . \Illuminate\Support\Str::limit($state, 30) . ' (' . \Illuminate\Support\Str::limit($order->user->email ?: $order->user->nickname, 20) . ')'
                    )
                    ->html()
                    ->description(fn(Order $order) =>
                        new \Illuminate\Support\HtmlString(
                            '<strong>Empresa:</strong> ' . \Illuminate\Support\Str::limit($order->user->company?->fantasy_name ?? 'Sin empresa', 40) . '<br>' .
                            '<strong>Sucursal:</strong> ' . \Illuminate\Support\Str::limit($order->user->branch?->fantasy_name ?? 'Sin sucursal', 40) . '<br>' .
                            '<span class="inline-flex items-center gap-1 mt-1">' .
                            '<span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-1.5 min-w-[theme(spacing.5)] py-0.5 fi-color-custom bg-custom-50 text-custom-600 ring-custom-600/10 dark:bg-custom-400/10 dark:text-custom-400 dark:ring-custom-400/30" style="--c-50:var(--primary-50);--c-400:var(--primary-400);--c-600:var(--primary-600);">
                            <span class="grid"><span class="truncate">' . ($order->user->roles->first()?->name ?? 'Sin rol') . '</span></span>
                            </span>' .
                            '<span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-1.5 min-w-[theme(spacing.5)] py-0.5 fi-color-custom bg-custom-50 text-custom-600 ring-custom-600/10 dark:bg-custom-400/10 dark:text-custom-400 dark:ring-custom-400/30" style="--c-50:var(--success-50);--c-400:var(--success-400);--c-600:var(--success-600);">
                            <span class="grid"><span class="truncate">' . ($order->user->permissions->first()?->name ?? 'Sin permiso') . '</span></span>
                            </span>' .
                            '</span>'
                        )
                    )
                    ->wrap(),
                Tables\Columns\SelectColumn::make('status')
                    ->label(__('Estado'))
                    ->options(OrderStatus::getSelectOptions())
                    ->sortable()
                    ->selectablePlaceholder(false),
                Tables\Columns\TextColumn::make('grand_total')
                    ->label(__('Totales'))
                    ->formatStateUsing(fn(Order $order) =>
                        'Neto: $' . number_format($order->total / 100, 2, ',', '.')
                    )
                    ->html()
                    ->description(fn(Order $order) =>
                        new \Illuminate\Support\HtmlString(
                            'Despacho: $' . number_format($order->dispatch_cost / 100, 2, ',', '.') . '<br>' .
                            'IVA: $' . number_format($order->tax_amount / 100, 2, ',', '.') . '<br>' .
                            '<strong>Total Final: $' . number_format($order->grand_total / 100, 2, ',', '.') . '</strong><br><br>' .
                            '<span class="' . OrderProductionStatus::from($order->production_status)->badgeClasses() . '">' .
                            OrderProductionStatus::from($order->production_status)->label() .
                            '</span>'
                        )
                    )
                    ->sortable(),
            ])
            ->filters([
                DateRangeFilter::make('created_at')
                    ->label(__('Fecha de pedido'))
                    ->columnSpan(1),
                DateRangeFilter::make('dispatch_date')
                    ->label(__('Fecha de despacho'))
                    ->columnSpan(1),
                Tables\Filters\SelectFilter::make('company_id')
                    ->label(__('Empresa'))
                    ->relationship('user.company', 'fantasy_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) =>
                        ($record->company_code ?? 'N/A') . ' - ' . $record->fantasy_name
                    )
                    ->searchable()
                    ->preload()
                    ->columnSpan(1)
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['value'])) {
                            $query->whereHas('user.company', function (Builder $q) use ($data) {
                                $q->where('companies.id', $data['value']);
                            });
                        }
                    }),
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label(__('Sucursal'))
                    ->relationship('user.branch', 'fantasy_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) =>
                        ($record->branch_code ?? 'N/A') . ' - ' . $record->fantasy_name
                    )
                    ->searchable()
                    ->preload()
                    ->columnSpan(1)
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['value'])) {
                            $query->whereHas('user.branch', function (Builder $q) use ($data) {
                                $q->where('branches.id', $data['value']);
                            });
                        }
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->multiple()
                    ->options(OrderStatus::getSelectOptions())
                    ->columnSpan(1),
                Tables\Filters\SelectFilter::make('production_status')
                    ->label(__('Estado de producción'))
                    ->multiple()
                    ->options(OrderProductionStatus::getSelectOptions())
                    ->columnSpan(1),
                Tables\Filters\SelectFilter::make('role')
                    ->label(__('Tipo de usuario'))
                    ->multiple()
                    ->options([
                        RoleName::ADMIN->value => 'Admin',
                        RoleName::CAFE->value => 'Café',
                        RoleName::AGREEMENT->value => 'Convenio',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['values'])) {
                            $query->whereHas('user.roles', function (Builder $q) use ($data) {
                                $q->whereIn('name', $data['values']);
                            });
                        }
                    })
                    ->columnSpan(1),
                Tables\Filters\SelectFilter::make('permission')
                    ->label(__('Tipo de convenio'))
                    ->multiple()
                    ->options([
                        PermissionName::CONSOLIDADO->value => 'Consolidado',
                        PermissionName::INDIVIDUAL->value => 'Individual',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['values'])) {
                            $query->whereHas('user.permissions', function (Builder $q) use ($data) {
                                $q->whereIn('name', $data['values']);
                            });
                        }
                    })
                    ->columnSpan(1),
            ], layout: Tables\Enums\FiltersLayout::AboveContent)
            ->filtersFormColumns(2)
            ->actions([
                ActionsActionGroup::make([
                    Tables\Actions\Action::make('preview')
                        ->label('Vista previa')
                        ->icon('heroicon-o-eye')
                        ->color('info')
                        ->modalHeading(fn(Order $record) => 'Pedido #' . $record->id . ' - ' . ($record->order_number ?? 'Sin número'))
                        ->modalWidth(MaxWidth::FiveExtraLarge)
                        ->modalContent(fn(Order $record) => view('filament.resources.order-resource.preview', [
                            'order' => $record->load(['user.company', 'user.branch', 'orderLines.product.category'])
                        ])),
                    Tables\Actions\Action::make('production_detail')
                        ->label('Ver detalle de producción')
                        ->icon('heroicon-o-chart-bar')
                        ->color('warning')
                        ->modalHeading(fn(Order $record) => 'Detalle de Producción - Pedido #' . $record->id)
                        ->modalWidth(MaxWidth::FiveExtraLarge)
                        ->modalContent(fn(Order $record) => view('filament.resources.order-resource.production-detail', [
                            'order' => $record,
                            'detail' => app(\App\Repositories\OrderRepository::class)->getProductionDetail($record)
                        ])),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->action(function (Order $record) {
                            try {

                                // Crear array con el ID de la orden a eliminar
                                $orderIdToDelete = [$record->id];

                                // Dispatch el job para eliminar la orden en segundo plano
                                DeleteOrders::dispatch($orderIdToDelete);

                                self::makeNotification(
                                    'Eliminación en proceso',
                                    'La orden será eliminada en segundo plano.'
                                )->send();

                            } catch (\Exception $e) {
                                self::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al preparar la eliminación de la orden: ' . $e->getMessage(),
                                    'danger'
                                )->send();

                                // Re-lanzar la excepción para que Filament la maneje
                                throw $e;
                            }
                        }),
                    Tables\Actions\Action::make('send_order_email')
                        ->label('Enviar correo de pedido')
                        ->icon('heroicon-o-envelope')
                        ->color('info')
                        ->action(function (Order $record) {
                            try {
                                // Crear array con el ID de la orden
                                $orderIdToSend = [$record->id];

                                // Dispatch el job para enviar el correo en segundo plano
                                SendOrdersEmails::dispatch($orderIdToSend);

                                self::makeNotification(
                                    'Envío en proceso',
                                    'El correo del pedido será enviado en segundo plano.'
                                )->send();

                            } catch (\Exception $e) {
                                self::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al preparar el envío del correo: ' . $e->getMessage(),
                                    'danger'
                                )->send();
                            }
                        }),
                    Tables\Actions\Action::make('facturar')
                        ->label('Facturar')
                        ->icon('heroicon-o-document-text')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Facturar Pedido')
                        ->modalDescription('¿Estás seguro de que deseas crear un proceso de facturación para este pedido?')
                        ->action(function (Order $record) {
                            try {
                                // Buscar la integración de facturación activa
                                $integration = Integration::where('type', Integration::TYPE_BILLING)
                                    ->where('active', true)
                                    ->first();

                                if (!$integration) {
                                    self::makeNotification(
                                        'Error',
                                        'No hay ninguna integración de facturación activa configurada.',
                                        'danger'
                                    )->send();
                                    return;
                                }

                                $billingProcess = BillingProcess::create([
                                    'order_id' => $record->id,
                                    'status' => BillingProcess::STATUS_PENDING,
                                    'responsible_id' => auth()->id(),
                                    'integration_id' => $integration->id,
                                ]);

                                self::makeNotification(
                                    'Proceso de facturación creado',
                                    'El proceso de facturación ha sido creado exitosamente.'
                                )->send();

                                return redirect()->route('filament.admin.resources.billing-processes.edit', ['record' => $billingProcess->id]);
                            } catch (\Exception $e) {
                                self::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al crear el proceso de facturación: ' . $e->getMessage(),
                                    'danger'
                                )->send();

                                throw $e;
                            }
                        }),
                ])
                    ->label('Acciones')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->size(ActionSize::Small)
                    ->color('primary')
                    ->button()
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('export_order_lines')
                        ->label('Exportar líneas de pedido')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('success')
                        ->form(OrderExportFilterForm::getSchema())
                        ->action(function (Collection $records, array $data) {
                            $exportProcessId = null;
                            try {
                                // Use repository to filter orders
                                $orderRepository = new OrderRepository();
                                $filteredOrderIds = $orderRepository->filterOrdersByRolesAndStatuses($records, $data);

                                if (!$orderRepository->hasFilteredOrders($filteredOrderIds)) {
                                    self::makeNotification(
                                        'Sin registros',
                                        'No hay pedidos que cumplan con los filtros seleccionados',
                                        'warning'
                                    )->send();
                                    return;
                                }

                                // Use the service via DI for export logic
                                $exportService = app(OrderLineExportService::class);
                                $exportProcess = $exportService->exportByOrderIds(collect($filteredOrderIds));
                                $exportProcessId = $exportProcess->id;

                                self::makeNotification(
                                    'Exportación iniciada',
                                    'El proceso de exportación finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {
                                // Usar ExportErrorHandler para registrar el error de manera consistente
                                ExportErrorHandler::handle(
                                    $e,
                                    $exportProcessId ?? 0,
                                    'bulk_export_order_lines'
                                );

                                self::makeNotification(
                                    'Error',
                                    'Error al iniciar la exportación',
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('generate_advance_order')
                        ->label('Generar orden de producción')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->color('warning')
                        ->form(AdvanceOrderGenerationForm::getSchema())
                        ->action(function (Collection $records, array $data) {
                            try {
                                $orderRepository = new OrderRepository();

                                // Create advance order using repository
                                $advanceOrder = $orderRepository->createAdvanceOrderFromOrders(
                                    $records->pluck('id')->toArray(),
                                    $data['preparation_datetime'],
                                    $data['production_area_ids']
                                );

                                self::makeNotification(
                                    'Orden de producción creada',
                                    "Se ha creado la orden de producción #{$advanceOrder->id} exitosamente."
                                )->send();

                            } catch (\Exception $e) {
                                Log::error('Error al crear orden de producción desde pedidos', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                self::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al crear la orden de producción: ' . $e->getMessage(),
                                    'danger'
                                )->send();
                            }
                        }),
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (Collection $records) {
                            try {

                                // Obtener los IDs de las órdenes a eliminar
                                $orderIdsToDelete = $records->pluck('id')->toArray();

                                // Dispatch el job para eliminar las órdenes en segundo plano
                                if (!empty($orderIdsToDelete)) {
                                    DeleteOrders::dispatch($orderIdsToDelete);

                                    self::makeNotification(
                                        'Eliminación en proceso',
                                        'Las órdenes seleccionadas serán eliminadas en segundo plano.'
                                    )->send();

                                } else {
                                    self::makeNotification(
                                        'Información',
                                        'No hay órdenes para eliminar.'
                                    )->send();
                                }
                            } catch (\Exception $e) {
                                Log::error('Error al preparar eliminación de órdenes', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                self::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al preparar la eliminación de órdenes: ' . $e->getMessage(),
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('send_orders_emails_bulk')
                        ->label('Enviar correos de pedidos')
                        ->icon('heroicon-o-envelope')
                        ->color('info')
                        ->form(OrderExportFilterForm::getSchema())
                        ->action(function (Collection $records, array $data) {
                            try {

                                // Use repository to filter orders
                                $orderRepository = new OrderRepository();
                                $filteredOrderIds = $orderRepository->filterOrdersByRolesAndStatuses($records, $data);

                                if (!$orderRepository->hasFilteredOrders($filteredOrderIds)) {
                                    self::makeNotification(
                                        'Sin registros',
                                        'No hay pedidos que cumplan con los filtros seleccionados',
                                        'warning'
                                    )->send();
                                    return;
                                }

                                // Dispatch el job para enviar los correos en segundo plano
                                if (!empty($filteredOrderIds)) {
                                    SendOrdersEmails::dispatch($filteredOrderIds);

                                    self::makeNotification(
                                        'Envío en proceso',
                                        'Los correos de los pedidos filtrados serán enviados en segundo plano.'
                                    )->send();
                                }
                            } catch (\Exception $e) {
                                Log::error('Error al preparar envío masivo de correos de pedidos', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                self::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al preparar el envío masivo de correos: ' . $e->getMessage(),
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('generate_vouchers_pdf')
                        ->label('Generar vouchers individuales')
                        ->icon('heroicon-o-document-text')
                        ->color('warning')
                        ->form(OrderExportFilterForm::getSchema())
                        ->action(function (Collection $records, array $data) {
                            try {
                                if ($records->isEmpty()) {
                                    self::makeNotification(
                                        'Sin registros',
                                        'No hay pedidos seleccionados para generar vouchers',
                                        'warning'
                                    )->send();
                                    return;
                                }

                                // Use repository to filter orders
                                $orderRepository = new OrderRepository();
                                $filteredOrderIds = $orderRepository->filterOrdersByRolesAndStatuses($records, $data);

                                if (!$orderRepository->hasFilteredOrders($filteredOrderIds)) {
                                    self::makeNotification(
                                        'Sin registros',
                                        'No hay pedidos que cumplan con los filtros seleccionados',
                                        'warning'
                                    )->send();
                                    return;
                                }

                                $orders = Order::whereIn('id', $filteredOrderIds)->get();
                                $orderNumbers = $orders->pluck('order_number')->sort()->values();
                                $firstOrder = $orderNumbers->first();
                                $lastOrder = $orderNumbers->last();
                                $totalOrders = $orders->count();

                                $description = $totalOrders === 1
                                    ? "Voucher del pedido #{$firstOrder}"
                                    : "Vouchers individuales de {$totalOrders} pedidos (#{$firstOrder} a #{$lastOrder})";

                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_VOUCHERS,
                                    'description' => $description,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                GenerateOrderVouchersJob::dispatch($filteredOrderIds, false, $exportProcess->id);

                                self::makeNotification(
                                    'Generación de vouchers iniciada',
                                    'Los vouchers PDF individuales se están generando. El proceso finalizará en breve.',
                                    'success'
                                )->send();

                            } catch (\Exception $e) {
                                Log::error('Error al iniciar generación de vouchers PDF', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                self::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al iniciar la generación de vouchers: ' . $e->getMessage(),
                                    'danger'
                                )->send();
                            }
                        }),
                    Tables\Actions\BulkAction::make('generate_consolidated_vouchers_pdf')
                        ->label('Generar vouchers consolidados')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Generar vouchers consolidados')
                        ->modalDescription('¿Está seguro que desea generar vouchers PDF consolidados? Los pedidos se agruparán por empresa.')
                        ->modalSubmitActionLabel('Sí, generar vouchers consolidados')
                        ->form(OrderExportFilterForm::getSchema())
                        ->action(function (Collection $records, array $data) {
                            try {
                                if ($records->isEmpty()) {
                                    self::makeNotification(
                                        'Sin registros',
                                        'No hay pedidos seleccionados para generar vouchers',
                                        'warning'
                                    )->send();
                                    return;
                                }

                                $orderRepository = new OrderRepository();
                                $filteredOrderIds = $orderRepository->filterOrdersByRolesAndStatuses($records, $data);

                                if (!$orderRepository->hasFilteredOrders($filteredOrderIds)) {
                                    self::makeNotification(
                                        'Sin registros',
                                        'No hay pedidos que cumplan con los filtros seleccionados',
                                        'warning'
                                    )->send();
                                    return;
                                }

                                $orders = Order::whereIn('id', $filteredOrderIds)->get();
                                $orderNumbers = $orders->pluck('order_number')->sort()->values();
                                $firstOrder = $orderNumbers->first();
                                $lastOrder = $orderNumbers->last();
                                $totalOrders = $orders->count();

                                $description = $totalOrders === 1
                                    ? "Voucher del pedido #{$firstOrder}"
                                    : "Vouchers consolidados de {$totalOrders} pedidos (#{$firstOrder} a #{$lastOrder})";

                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_VOUCHERS,
                                    'description' => $description,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                GenerateOrderVouchersJob::dispatch($filteredOrderIds, true, $exportProcess->id);

                                self::makeNotification(
                                    'Generación de vouchers consolidados iniciada',
                                    'Los vouchers PDF consolidados se están generando. Los pedidos se agruparán por empresa.',
                                    'success'
                                )->send();

                            } catch (\Exception $e) {
                                Log::error('Error al iniciar generación de vouchers consolidados', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                self::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al iniciar la generación de vouchers consolidados: ' . $e->getMessage(),
                                    'danger'
                                )->send();
                            }
                        }),
                ])->dropdownWidth(MaxWidth::ExtraSmall),
            ])
            ->headerActions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('import_orders')
                        ->label('Importar pedidos')
                        ->color('info')
                        ->icon('tabler-file-upload')
                        ->form([
                            Forms\Components\FileUpload::make('file')
                                ->disk('s3')
                                ->visibility('private')
                                ->maxSize(10240)
                                ->maxFiles(1)
                                ->directory('orders-imports')
                                ->visibility('public')
                                ->label('Archivo')
                                ->required(),
                        ])
                        ->action(function (array $data) {
                            try {
                                $importProcess = ImportProcess::create([
                                    'type' => ImportProcess::TYPE_ORDERS,
                                    'status' => ImportProcess::STATUS_QUEUED,
                                    'file_url' => $data['file'],
                                ]);

                                Excel::import(
                                    new OrderLinesImport($importProcess->id),
                                    $data['file'],
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                self::makeNotification(
                                    'Pedidos importados',
                                    'El proceso de importación finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {
                                // Usar ExportErrorHandler para registrar el error
                                ExportErrorHandler::handle(
                                    $e,
                                    $importProcess->id ?? 0,
                                    'import_orders_action',
                                    'ImportProcess'
                                );

                                self::makeNotification(
                                    'Error',
                                    'El proceso ha fallado',
                                    'danger'
                                )->send();
                            }
                        }),
                    Tables\Actions\Action::make('download_orders_template')
                        ->label('Bajar plantilla de pedidos')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->action(function () {
                            try {
                                return Excel::download(
                                    new OrderLineExport(collect(), 0),
                                    'template_importacion_pedidos.xlsx'
                                );
                            } catch (\Exception $e) {
                                self::makeNotification(
                                    'Error',
                                    'Error al generar la plantilla de pedidos',
                                    'danger'
                                )->send();
                            }
                        }),
                ])->dropdownWidth(MaxWidth::ExtraSmall),
                Tables\Actions\Action::make('reload')
                    ->label('Recargar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function () {
                        return redirect()->back();
                    })
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->emptyStateDescription(__('No hay pedidos actualmente'));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\OrderLinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }

    private static function makeNotification(string $title, string $body, string $color = 'success'): Notification
    {
        return Notification::make()
            ->color($color)
            ->title($title)
            ->body($body);
    }
}

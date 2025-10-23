<?php

namespace App\Filament\Resources;

use App\Enums\OrderStatus;
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
use App\Models\ExportProcess;
use App\Models\OrderLine;
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
                    ->label(__('Fecha de despacho'))
                    ->sortable()
                    ->date('d/m/Y')
                    ->searchable(),
                Tables\Columns\TextColumn::make('id')
                    ->label(__('ID'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('Número de Orden'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('Cliente'))
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user', function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%")
                              ->orWhere('nickname', 'like', "%{$search}%");
                        });
                    })
                    ->description(fn(Order $order) => $order->user->email ?: $order->user->nickname),
                Tables\Columns\TextColumn::make('user.company.fantasy_name')
                    ->label(__('Empresa'))
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user.company', function (Builder $q) use ($search) {
                            $q->where('fantasy_name', 'like', "%{$search}%");
                        })->orWhereHas('user.branch', function (Builder $q) use ($search) {
                            $q->where('fantasy_name', 'like', "%{$search}%");
                        });
                    })
                    ->description(fn(Order $order) => $order->user->branch?->fantasy_name),
                Tables\Columns\SelectColumn::make('status')
                    ->label(__('Estado'))
                    ->options(OrderStatus::getSelectOptions())
                    ->sortable()
                    ->selectablePlaceholder(false),
                Tables\Columns\TextColumn::make('total_products')
                    ->label(__('Total productos'))
                    ->state(fn(Model $order) => $order->orderLines->sum('quantity')),
                MoneyColumn::make('grand_total')
                    ->label(__('Total'))
                    ->currency('USD')
                    ->locale('en_US'),
                MoneyColumn::make('dispatch_cost')
                    ->label(__('Costo de despacho'))
                    ->currency('USD')
                    ->locale('en_US')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Fecha de pedido'))
                    ->sortable()
                    ->dateTime('d/m/Y H:i:s')
                    ->searchable(),
            ])
            ->filters([
                DateRangeFilter::make('created_at')
                    ->label(__('Fecha de pedido')),
                DateRangeFilter::make('dispatch_date')
                    ->label(__('Fecha de despacho')),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label(__('Cliente'))
                    ->options(User::customers()->pluck('name', 'id'))
                    ->searchable(),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->multiple()
                    ->options(OrderStatus::getSelectOptions())
            ])
            ->actions([
                ActionsActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->action(function (Order $record) {
                            try {
                                Log::info('Iniciando proceso de eliminación de orden', [
                                    'order_id' => $record->id,
                                    'client' => $record->user->name ?? 'N/A'
                                ]);

                                // Crear array con el ID de la orden a eliminar
                                $orderIdToDelete = [$record->id];

                                // Dispatch el job para eliminar la orden en segundo plano
                                DeleteOrders::dispatch($orderIdToDelete);

                                self::makeNotification(
                                    'Eliminación en proceso',
                                    'La orden será eliminada en segundo plano.'
                                )->send();

                                Log::info('Job de eliminación de orden enviado a la cola', [
                                    'order_id' => $record->id
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Error al preparar eliminación de orden', [
                                    'order_id' => $record->id,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

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

                                Log::info('Job de envío de correo de pedido enviado a la cola', [
                                    'order_id' => $record->id
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Error al preparar envío de correo de pedido', [
                                    'order_id' => $record->id,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

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

                                Log::info('Proceso de facturación creado', [
                                    'billing_process_id' => $billingProcess->id,
                                    'order_id' => $record->id,
                                    'responsible_id' => auth()->id(),
                                    'integration_id' => $integration->id,
                                ]);

                                return redirect()->route('filament.admin.resources.billing-processes.edit', ['record' => $billingProcess->id]);
                            } catch (\Exception $e) {
                                Log::error('Error al crear proceso de facturación', [
                                    'order_id' => $record->id,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

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
                        ->action(function (Collection $records) {
                            try {
                                Log::info('Iniciando proceso de exportación de líneas de pedido', [
                                    'total_records' => $records->count(),
                                    'order_ids' => $records->pluck('id')->toArray()
                                ]);

                                $orderIds = $records->pluck('id')->toArray();
                                $orderLineIds = OrderLine::whereIn('order_id', $orderIds)
                                    ->pluck('id');

                                Log::info('Obtenidas líneas de pedido para exportar', [
                                    'total_order_lines' => $orderLineIds->count()
                                ]);

                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_ORDER_LINES,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                Log::info('Proceso de exportación creado', [
                                    'export_process_id' => $exportProcess->id
                                ]);

                                $fileName = "exports/order-lines/lineas_pedido_export_{$exportProcess->id}_" . time() . '.xlsx';

                                Excel::store(
                                    new OrderLineExport($orderLineIds, $exportProcess->id),
                                    $fileName,
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                $fileUrl = Storage::disk('s3')->url($fileName);
                                $exportProcess->update([
                                    'file_url' => $fileUrl
                                ]);

                                Log::info('Exportación completada con éxito', [
                                    'export_process_id' => $exportProcess->id,
                                    'file_url' => $fileUrl
                                ]);

                                self::makeNotification(
                                    'Exportación iniciada',
                                    'El proceso de exportación finalizar�� en breve'
                                )->send();
                            } catch (\Exception $e) {
                                Log::error('Error en la exportación de líneas de pedido', [
                                    'export_process_id' => $exportProcess->id ?? 0,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                // Usar ExportErrorHandler para registrar el error de manera consistente
                                ExportErrorHandler::handle(
                                    $e,
                                    $exportProcess->id ?? 0,
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
                    Tables\Actions\BulkAction::make('export_order_lines_consolidated')
                        ->label('Exportar consolidado de pedidos')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('success')
                        ->action(function (Collection $records) {
                            try {
                                Log::info('Iniciando proceso de exportación de líneas de pedido', [
                                    'total_records' => $records->count(),
                                    'order_ids' => $records->pluck('id')->toArray()
                                ]);

                                $orderIds = $records->pluck('id')->toArray();
                                $orderLineIds = OrderLine::whereIn('order_id', $orderIds)
                                    ->pluck('id');

                                Log::info('Obtenidas líneas de pedido para exportar', [
                                    'total_order_lines' => $orderLineIds->count()
                                ]);

                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::ORDER_CONSOLIDATED,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                Log::info('Proceso de exportación creado', [
                                    'export_process_id' => $exportProcess->id
                                ]);

                                $fileName = "exports/order-lines/lineas_pedido_export_{$exportProcess->id}_" . time() . '.xlsx';

                                Excel::store(
                                    new OrderLineConsolidatedExport($orderLineIds, $exportProcess->id),
                                    $fileName,
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                $fileUrl = Storage::disk('s3')->url($fileName);
                                $exportProcess->update([
                                    'file_url' => $fileUrl
                                ]);

                                Log::info('Exportación completada con éxito', [
                                    'export_process_id' => $exportProcess->id,
                                    'file_url' => $fileUrl
                                ]);

                                self::makeNotification(
                                    'Exportación iniciada',
                                    'El proceso de exportación finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {
                                Log::error('Error en la exportación de líneas de pedido', [
                                    'export_process_id' => $exportProcess->id ?? 0,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                // Usar ExportErrorHandler para registrar el error de manera consistente
                                ExportErrorHandler::handle(
                                    $e,
                                    $exportProcess->id ?? 0,
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
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (Collection $records) {
                            try {
                                Log::info('Iniciando proceso de eliminación masiva de órdenes', [
                                    'total_records' => $records->count(),
                                    'order_ids' => $records->pluck('id')->toArray()
                                ]);

                                // Obtener los IDs de las órdenes a eliminar
                                $orderIdsToDelete = $records->pluck('id')->toArray();

                                // Dispatch el job para eliminar las órdenes en segundo plano
                                if (!empty($orderIdsToDelete)) {
                                    DeleteOrders::dispatch($orderIdsToDelete);

                                    self::makeNotification(
                                        'Eliminación en proceso',
                                        'Las órdenes seleccionadas serán eliminadas en segundo plano.'
                                    )->send();

                                    Log::info('Job de eliminación masiva de órdenes enviado a la cola', [
                                        'order_ids' => $orderIdsToDelete
                                    ]);
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
                        ->action(function (Collection $records) {
                            try {
                                Log::info('Iniciando proceso de envío masivo de correos de pedidos', [
                                    'total_records' => $records->count(),
                                    'order_ids' => $records->pluck('id')->toArray()
                                ]);

                                // Obtener los IDs de los pedidos seleccionados
                                $orderIds = $records->pluck('id')->toArray();

                                // Dispatch el job para enviar los correos en segundo plano
                                if (!empty($orderIds)) {
                                    SendOrdersEmails::dispatch($orderIds);

                                    self::makeNotification(
                                        'Envío en proceso',
                                        'Los correos de los pedidos seleccionados serán enviados en segundo plano.'
                                    )->send();

                                    Log::info('Job de envío masivo de correos de pedidos enviado a la cola', [
                                        'order_ids' => $orderIds
                                    ]);
                                } else {
                                    self::makeNotification(
                                        'Información',
                                        'No hay pedidos seleccionados para enviar correos.'
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
                        ->label('Generar vouchers PDF')
                        ->icon('heroicon-o-document-text')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Generar vouchers PDF')
                        ->modalDescription('¿Está seguro que desea generar los vouchers PDF de los pedidos seleccionados?')
                        ->modalSubmitActionLabel('Sí, generar vouchers')
                        ->action(function (Collection $records) {
                            try {
                                if ($records->isEmpty()) {
                                    self::makeNotification(
                                        'Sin registros',
                                        'No hay pedidos seleccionados para generar vouchers',
                                        'warning'
                                    )->send();
                                    return;
                                }

                                $orderIds = $records->pluck('id')->toArray();

                                Log::info('Iniciando generación de vouchers PDF', [
                                    'total_orders' => count($orderIds),
                                    'order_ids' => $orderIds
                                ]);

                                GenerateOrderVouchersJob::dispatch($orderIds);

                                self::makeNotification(
                                    'Generación de vouchers iniciada',
                                    'Los vouchers PDF se están generando. El proceso finalizará en breve.',
                                    'success'
                                )->send();

                                Log::info('Job de generación de vouchers despachado exitosamente', [
                                    'order_ids' => $orderIds
                                ]);

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
                        })
                        ->deselectRecordsAfterCompletion(),
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
                ])->dropdownWidth(MaxWidth::ExtraSmall)
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

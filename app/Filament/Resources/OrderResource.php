<?php

namespace App\Filament\Resources;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use App\Models\User;
use App\Models\Branch;
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
use Maatwebsite\Excel\Facades\Excel;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

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
                        Forms\Components\Select::make('user_id')
                            ->options(User::customers()->pluck('name', 'id'))
                            ->label(__('Cliente'))
                            ->searchable()
                            ->disabledOn('edit'),
                        MoneyInput::make('total')
                            ->label(__('Total'))
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
                        MoneyInput::make('price_list_min')
                            ->label(__('Monto mínimo'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
                            ->decimals(2)
                            ->disabled(),
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
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->searchable()
                    ->prefix('#')
                    ->suffix('#'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('Cliente'))
                    ->sortable()
                    ->searchable(),
                MoneyColumn::make('total')
                    ->label(__('Total'))
                    ->currency('USD')
                    ->locale('en_US'),
                Tables\Columns\TextColumn::make('total_products')
                    ->label(__('Total productos'))
                    ->state(fn(Model $order) => $order->orderLines->sum('quantity')),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Fecha de pedido'))
                    ->sortable()
                    ->dateTime('d/m/Y H:i:s')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('Estado'))
                    ->sortable()
                    ->formatStateUsing(fn(string $state): string => OrderStatus::from($state)->getLabel())
                    ->color(fn(string $state): string => match ($state) {
                        OrderStatus::PENDING->value => 'warning',
                        OrderStatus::PARTIALLY_SCHEDULED->value => 'info',
                        OrderStatus::PROCESSED->value => 'success',
                        OrderStatus::CANCELED->value => 'danger',
                    }),
                Tables\Columns\TextColumn::make('dispatch_date')
                    ->label(__('Fecha de despacho'))
                    ->sortable()
                    ->date('d/m/Y')
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
                    Tables\Actions\BulkAction::make('export_order_lines')
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
                                    'type' => ExportProcess::TYPE_ORDER_LINES,
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
                ]),
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

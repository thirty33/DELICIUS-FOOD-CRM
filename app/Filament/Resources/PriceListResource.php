<?php

namespace App\Filament\Resources;

use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Exports\PriceListDataExport;
use App\Exports\PriceListTemplateExport;
use App\Filament\Resources\PriceListResource\Pages;
use App\Filament\Resources\PriceListResource\RelationManagers;
use App\Models\PriceList;
use App\Models\ExportProcess;
use App\Models\ImportProcess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Pelmered\FilamentMoneyField\Forms\Components\MoneyInput;
use Pelmered\FilamentMoneyField\Tables\Columns\MoneyColumn;
use Filament\Notifications\Notification;
use App\Imports\PriceListImport;
use App\Jobs\BulkDeletePriceLists;

class PriceListResource extends Resource
{
    protected static ?string $model = PriceList::class;

    protected static ?string $navigationIcon = 'polaris-price-list-icon';

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): ?string
    {
        return __('Almacén');
    }

    public static function getLabel(): ?string
    {
        return __('Lista de Precio');
    }

    public static function getNavigationLabel(): string
    {
        return __('Listas de Precio');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->autofocus()
                    ->required()
                    ->minLength(2)
                    ->maxLength(200)
                    ->unique(static::getModel(), 'name', ignoreRecord: true)
                    ->label(__('Nombre')),
                MoneyInput::make('min_price_order')
                    ->label(__('Precio pedido mínimo'))
                    ->currency('USD')
                    ->locale('en_US')
                    ->minValue(0)
                    ->decimals(2),
                Forms\Components\Textarea::make('description')
                    ->nullable()
                    ->minLength(2)
                    ->maxLength(200)
                    ->label(__('Descripción'))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable(),
                MoneyColumn::make('min_price_order')
                    ->label(__('Precio pedido mínimo'))
                    ->currency('USD')
                    ->locale('en_US'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('import_price_lists')
                        ->label('Importar listas de precio')
                        ->color('info')
                        ->icon('tabler-file-upload')
                        ->form([
                            Forms\Components\FileUpload::make('file')
                                ->disk('s3')
                                ->maxSize(10240)
                                ->maxFiles(1)
                                ->directory('price-lists-imports')
                                ->visibility('public')
                                ->label('Archivo')
                                ->required(),
                        ])
                        ->action(function (array $data) {
                            try {
                                $importProcess = ImportProcess::create([
                                    'type' => ImportProcess::TYPE_PRICE_LISTS,
                                    'status' => ImportProcess::STATUS_QUEUED,
                                    'file_url' => $data['file'],
                                ]);

                                Excel::import(
                                    new PriceListImport($importProcess->id),
                                    $data['file'],
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                self::makeNotification(
                                    'Listas de precio importadas',
                                    'El proceso de importación finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {
                                // Usar ExportErrorHandler para registrar el error
                                ExportErrorHandler::handle(
                                    $e,
                                    $importProcess->id ?? 0,
                                    'import_price_lists_action',
                                    'ImportProcess'
                                );

                                self::makeNotification(
                                    'Error',
                                    'El proceso ha fallado',
                                    'danger'
                                )->send();
                            }
                        }),
                    Tables\Actions\Action::make('download_price_lists_template')
                        ->label('Bajar plantilla de listas de precio')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->action(function () {
                            try {
                                // Crear un proceso de exportación para registro

                                return Excel::download(
                                    new PriceListTemplateExport(),
                                    'template_importacion_listas_precio.xlsx'
                                );
                            } catch (\Exception $e) {
                                // Usar ExportErrorHandler para registrar el error

                                self::makeNotification(
                                    'Error',
                                    'Error al generar la plantilla de listas de precio',
                                    'danger'
                                )->send();
                            }
                        }),
                ])->dropdownWidth(MaxWidth::ExtraSmall)
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->action(function (PriceList $record) {
                        try {
                            Log::info('Iniciando proceso de eliminación de lista de precios', [
                                'price_list_id' => $record->id,
                                'price_list_name' => $record->name
                            ]);

                            // Crear array con el ID de la lista de precios a eliminar
                            $priceListIdToDelete = [$record->id];

                            // Dispatch el job para eliminar la lista de precios en segundo plano
                            BulkDeletePriceLists::dispatch($priceListIdToDelete);

                            self::makeNotification(
                                'Eliminación en proceso',
                                'La lista de precios será eliminada en segundo plano.'
                            )->send();

                            Log::info('Job de eliminación de lista de precios enviado a la cola', [
                                'price_list_id' => $record->id
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Error al preparar eliminación de lista de precios', [
                                'price_list_id' => $record->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);

                            self::makeNotification(
                                'Error',
                                'Ha ocurrido un error al preparar la eliminación de la lista de precios: ' . $e->getMessage(),
                                'danger'
                            )->send();

                            // Re-lanzar la excepción para que Filament la maneje
                            throw $e;
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (Collection $records) {
                            try {
                                Log::info('Iniciando proceso de eliminación masiva de listas de precios', [
                                    'total_records' => $records->count(),
                                    'price_list_ids' => $records->pluck('id')->toArray()
                                ]);

                                // Obtener los IDs de las listas de precios a eliminar
                                $priceListIdsToDelete = $records->pluck('id')->toArray();

                                // Dispatch el job para eliminar las listas de precios en segundo plano
                                if (!empty($priceListIdsToDelete)) {
                                    BulkDeletePriceLists::dispatch($priceListIdsToDelete);

                                    self::makeNotification(
                                        'Eliminación en proceso',
                                        'Las listas de precios seleccionadas serán eliminadas en segundo plano.'
                                    )->send();

                                    Log::info('Job de eliminación masiva de listas de precios enviado a la cola', [
                                        'price_list_ids' => $priceListIdsToDelete
                                    ]);
                                } else {
                                    self::makeNotification(
                                        'Información',
                                        'No hay listas de precios para eliminar.'
                                    )->send();
                                }
                            } catch (\Exception $e) {
                                Log::error('Error al preparar eliminación de listas de precios', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                self::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al preparar la eliminación de listas de precios: ' . $e->getMessage(),
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('export_price_lists')
                        ->label('Exportar listas de precio')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('success')
                        ->action(function (Collection $records) {
                            try {
                                $priceListIds = $records->pluck('id')->toArray();
                                $priceListIds = PriceList::whereIn('id', $priceListIds)
                                    ->pluck('id');

                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_PRICE_LISTS,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                $fileName = "exports/price-lists/listas_precio_export_{$exportProcess->id}_" . time() . '.xlsx';

                                Excel::store(
                                    new PriceListDataExport($priceListIds, $exportProcess->id),
                                    $fileName,
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                $fileUrl = Storage::disk('s3')->url($fileName);
                                $exportProcess->update([
                                    'file_url' => $fileUrl
                                ]);

                                self::makeNotification(
                                    'Exportación iniciada',
                                    'El proceso de exportación finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {
                                // Usar ExportErrorHandler para registrar el error de manera consistente
                                ExportErrorHandler::handle(
                                    $e,
                                    $exportProcess->id ?? 0,
                                    'bulk_export_price_lists'
                                );

                                self::makeNotification(
                                    'Error',
                                    'Error al iniciar la exportación',
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion()
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->emptyStateDescription(__('No hay listas de precios disponibles'));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PriceListLinesRelationManager::class,
            RelationManagers\CompaniesRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceLists::route('/'),
            'create' => Pages\CreatePriceList::route('/create'),
            'edit' => Pages\EditPriceList::route('/{record}/edit'),
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

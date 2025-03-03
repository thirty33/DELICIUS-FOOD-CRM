<?php

namespace App\Filament\Resources;

use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Exports\ProductsDataExport;
use App\Exports\ProductsTemplateExport;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Jobs\BulkDeleteProducts;
use App\Jobs\BulkUpdateProductImages;
use App\Models\ExportProcess;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Form;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Pelmered\FilamentMoneyField\Forms\Components\MoneyInput;
use Pelmered\FilamentMoneyField\Tables\Columns\MoneyColumn;
use Filament\Support\Enums\MaxWidth;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\ImportProcess;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'polaris-product-add-icon';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('Almacén');
    }

    public static function getLabel(): ?string
    {
        return __('Producto');
    }

    public static function getNavigationLabel(): string
    {
        return __('Productos');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('image')
                    ->label(__('Imagen'))
                    ->image()
                    ->maxSize(4096)
                    ->placeholder(__('Imagen del producto'))
                    ->columnSpanFull(),
                Forms\Components\Grid::make()
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label(__('Código'))
                            ->unique(static::getModel(), 'code', ignoreRecord: true)
                            ->required()
                            ->columns(1),
                        Forms\Components\TextInput::make('name')
                            ->autofocus()
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->unique(static::getModel(), 'name', ignoreRecord: true)
                            ->label(__('Nombre'))
                            ->columns(1),
                        MoneyInput::make('price')
                            ->label(__('Precio base'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
                            ->decimals(2)
                            ->columns(1),
                        // MoneyInput::make('price_list')
                        //     ->label(__('Precio Lista'))
                        //     ->currency('USD')
                        //     ->locale('en_US')
                        //     ->minValue(0)
                        //     ->decimals(2)
                        //     ->columns(1),
                        Forms\Components\Select::make('category_id')
                            ->relationship('category', 'name')
                            ->required()
                            ->label(__('Categoría'))
                            ->searchable()
                            ->columns(1),
                        Forms\Components\TextInput::make('measure_unit')
                            ->label(__('Unidad de Medida'))
                            ->nullable()
                            ->columns(1),
                        Forms\Components\TextInput::make('stock')
                            ->label(__('Stock'))
                            ->numeric()
                            ->nullable()
                            ->columns(1),
                        Forms\Components\TextInput::make('weight')
                            ->label(__('Peso'))
                            ->numeric()
                            ->nullable()
                            ->columns(1),
                    ])->columns(3),
                Forms\Components\Toggle::make('allow_sales_without_stock')
                    ->label(__('Permitir Ventas sin Stock'))
                    ->default(false),
                Forms\Components\Toggle::make('active')
                    ->label(__('Activo'))
                    ->default(false),
                Forms\Components\Textarea::make('description')
                    ->required()
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
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('Imagen')),
                Tables\Columns\TextColumn::make('code')
                    ->label(__('Código'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable(),
                MoneyColumn::make('price')
                    ->label(__('Precio'))
                    ->currency('USD')
                    ->locale('en_US'),
                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('Categoría'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Creado'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Actualizado'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->relationship('category', 'name')
                    ->label(__('Categoría'))
            ])
            ->headerActions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('import_products')
                        ->label('Importar productos')
                        ->color('info')
                        ->icon('tabler-file-upload')
                        ->form([
                            Forms\Components\FileUpload::make('file')
                                ->disk('s3')
                                ->maxSize(10240)
                                ->maxFiles(1)
                                ->directory('products-imports')
                                ->visibility('public')
                                ->label('Archivo')
                                ->required(),
                        ])
                        ->action(function (array $data) {
                            try {
                                $importProcess = \App\Models\ImportProcess::create([
                                    'type' => ImportProcess::TYPE_PRODUCTS,
                                    'status' => ImportProcess::STATUS_QUEUED,
                                    'file_url' => $data['file'],
                                ]);

                                Excel::import(
                                    new \App\Imports\ProductsImport($importProcess->id),
                                    $data['file'],
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                ProductResource::makeNotification(
                                    'Productos importados',
                                    'El proceso de importación finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {
                                Log::error('Error en importación de productos', [
                                    'message' => $e->getMessage() . ' ' . $e->getLine(),
                                ]);

                                ProductResource::makeNotification(
                                    'Error',
                                    'El proceso ha fallado',
                                    'danger'
                                )->send();
                            }
                        }),
                    Tables\Actions\Action::make('download_products_template')
                        ->label('Bajar plantilla de productos')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->action(function () {
                            return Excel::download(
                                new ProductsTemplateExport(),
                                'template_importacion_productos.xlsx'
                            );
                        }),
                    Tables\Actions\Action::make('bulk_image_upload')
                        ->label('Subir Múltiples Imágenes')
                        ->icon('heroicon-o-photo')
                        ->color('primary')
                        ->form([
                            Forms\Components\FileUpload::make('images')
                                ->label('Imágenes')
                                ->multiple()
                                ->storeFileNamesIn('attachment_file_names')
                                ->image()
                                ->maxSize(8096)
                                ->directory('product-images')
                                ->visibility('public')
                                ->required()
                        ])
                        ->action(function (array $data) {
                            try {


                                $images = $data['images'] ?? [];
                                $originalFileNames = $data['attachment_file_names'] ?? [];

                                if (empty($images)) {
                                    Notification::make()
                                        ->title('Error')
                                        ->body('No se seleccionaron imágenes')
                                        ->color('danger')
                                        ->send();
                                    return;
                                }

                                // Create import process record
                                $importProcess = ImportProcess::create([
                                    'type' => ImportProcess::TYPE_PRODUCTS_IMAGES,
                                    'status' => ImportProcess::STATUS_QUEUED,
                                    // 'file_url' => json_encode($originalFileNames)
                                    'file_url' => '-'
                                ]);

                                // Dispatch the job
                                BulkUpdateProductImages::dispatch(
                                    $importProcess,
                                    $images,
                                    $originalFileNames
                                );

                                // Notification about job dispatch
                                Notification::make()
                                    ->title('Carga de Imágenes Iniciada')
                                    ->body('El proceso de carga de imágenes ha sido iniciado en segundo plano')
                                    ->color('success')
                                    ->send();
                            } catch (\Exception $mainException) {
                                Log::error('Error iniciando carga de imágenes', [
                                    'error' => $mainException->getMessage(),
                                    'trace' => $mainException->getTraceAsString()
                                ]);

                                Notification::make()
                                    ->title('Error')
                                    ->body('No se pudo iniciar la carga de imágenes')
                                    ->color('danger')
                                    ->send();
                            }
                        })
                ])->dropdownWidth(MaxWidth::ExtraSmall)
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->action(function (Product $record) {
                        try {
                            Log::info('Iniciando proceso de eliminación de producto', [
                                'product_id' => $record->id,
                                'product_name' => $record->name
                            ]);

                            // Crear array con el ID del producto a eliminar
                            $productIdToDelete = [$record->id];

                            // Dispatch el job para eliminar el producto en segundo plano
                            BulkDeleteProducts::dispatch($productIdToDelete);

                            self::makeNotification(
                                'Eliminación en proceso',
                                'El producto será eliminado en segundo plano.'
                            )->send();

                            Log::info('Job de eliminación de producto enviado a la cola', [
                                'product_id' => $record->id
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Error al preparar eliminación de producto', [
                                'product_id' => $record->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);

                            self::makeNotification(
                                'Error',
                                'Ha ocurrido un error al preparar la eliminación del producto: ' . $e->getMessage(),
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
                                Log::info('Iniciando proceso de eliminación masiva de productos', [
                                    'total_records' => $records->count(),
                                    'product_ids' => $records->pluck('id')->toArray()
                                ]);

                                // Obtener los IDs de los productos a eliminar
                                $productIdsToDelete = $records->pluck('id')->toArray();

                                // Dispatch el job para eliminar los productos en segundo plano
                                if (!empty($productIdsToDelete)) {
                                    BulkDeleteProducts::dispatch($productIdsToDelete);

                                    self::makeNotification(
                                        'Eliminación en proceso',
                                        'Los productos seleccionados serán eliminados en segundo plano.'
                                    )->send();

                                    Log::info('Job de eliminación masiva de productos enviado a la cola', [
                                        'product_ids' => $productIdsToDelete
                                    ]);
                                } else {
                                    self::makeNotification(
                                        'Información',
                                        'No hay productos para eliminar.'
                                    )->send();
                                }
                            } catch (\Exception $e) {
                                Log::error('Error al preparar eliminación de productos', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                self::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al preparar la eliminación de productos: ' . $e->getMessage(),
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('export_products')
                        ->label('Exportar productos')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('success')
                        ->action(function (Collection $records) {
                            try {

                                $productIds = $records->pluck('id')->toArray();
                                $productsIds = Product::whereIn('id', $productIds)
                                    ->pluck('id');

                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_PRODUCTS,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                $fileName = "exports/products/productos_export_{$exportProcess->id}_" . time() . '.xlsx';

                                Excel::store(
                                    new ProductsDataExport($productsIds, $exportProcess->id),
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
                                ExportErrorHandler::handle($e, $exportProcess->id ?? 0, 'bulk_export_products');

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
            ->emptyStateDescription(__('No hay productos disponibles'));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                ImageEntry::make('image')
                    ->hiddenLabel()
                    ->columnSpanFull(),
                Section::make()->schema([
                    TextEntry::make('name')->label(__('Nombre')),
                    TextEntry::make('price')->label(__('Precio'))->money('eur'),
                    TextEntry::make('category.name')->label(__('Categoría')),
                ])->columns(3),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\IngredientsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
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

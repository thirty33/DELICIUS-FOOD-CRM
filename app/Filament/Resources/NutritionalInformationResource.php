<?php

namespace App\Filament\Resources;

use App\Contracts\ImportServiceInterface;
use App\Contracts\NutritionalInformationRepositoryInterface;
use App\Services\NutritionalInformationDeletionService;
use App\Services\Labels\NutritionalLabelService;
use App\Jobs\DeleteNutritionalInformationJob;
use App\Enums\MeasureUnit;
use App\Models\ExportProcess;
use App\Filament\Resources\NutritionalInformationResource\Pages;
use App\Filament\Resources\NutritionalInformationResource\RelationManagers;
use App\Imports\NutritionalInformationImport;
use App\Models\ImportProcess;
use App\Models\NutritionalInformation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class NutritionalInformationResource extends Resource
{
    protected static ?string $model = NutritionalInformation::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('Producción');
    }

    public static function getLabel(): ?string
    {
        return __('Información Nutricional');
    }

    public static function getNavigationLabel(): string
    {
        return __('Información Nutricional');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Información Nutricional');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Información del Producto'))
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->label(__('Producto'))
                            ->relationship('product', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('barcode')
                            ->label(__('Código de Barras'))
                            ->maxLength(50)
                            ->columnSpan(1),
                        Forms\Components\Toggle::make('generate_label')
                            ->label(__('Generar Etiqueta'))
                            ->default(false)
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('Ingredientes y Alérgenos'))
                    ->schema([
                        Forms\Components\Textarea::make('ingredients')
                            ->label(__('Ingredientes'))
                            ->rows(4)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('allergens')
                            ->label(__('Alérgenos'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('Información de Peso y Vida Útil'))
                    ->schema([
                        Forms\Components\Select::make('measure_unit')
                            ->label(__('Unidad de Medida'))
                            ->options(MeasureUnit::options())
                            ->required()
                            ->default('GR')
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('net_weight')
                            ->label(__('Peso Neto'))
                            ->required()
                            ->numeric()
                            ->default(0.00)
                            ->suffix('gr')
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('gross_weight')
                            ->label(__('Peso Bruto'))
                            ->required()
                            ->numeric()
                            ->default(0.00)
                            ->suffix('gr')
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('shelf_life_days')
                            ->label(__('Vida Útil (días)'))
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->suffix(__('días'))
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('Textos de Advertencia en Etiqueta'))
                    ->schema([
                        Forms\Components\Toggle::make('show_soy_text')
                            ->label(__('Mostrar Texto de Soya'))
                            ->helperText(__('Muestra: "Agitar soya antes de verter"'))
                            ->default(false)
                            ->columnSpan(1),
                        Forms\Components\Toggle::make('show_chicken_text')
                            ->label(__('Mostrar Texto de Pollo'))
                            ->helperText(__('Muestra advertencia sobre restos de huesos en pollo desmenuzado'))
                            ->default(false)
                            ->columnSpan(1),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.code')
                    ->label(__('Código'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.name')
                    ->label(__('Producto'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('barcode')
                    ->label(__('Código de Barras'))
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('measure_unit')
                    ->label(__('Unidad'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('net_weight')
                    ->label(__('Peso Neto'))
                    ->numeric()
                    ->sortable()
                    ->suffix(' gr'),
                Tables\Columns\TextColumn::make('gross_weight')
                    ->label(__('Peso Bruto'))
                    ->numeric()
                    ->sortable()
                    ->suffix(' gr')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('shelf_life_days')
                    ->label(__('Vida Útil'))
                    ->numeric()
                    ->sortable()
                    ->suffix(' días'),
                Tables\Columns\IconColumn::make('generate_label')
                    ->label(__('Generar Etiqueta'))
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('show_soy_text')
                    ->label(__('Texto Soya'))
                    ->boolean()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('show_chicken_text')
                    ->label(__('Texto Pollo'))
                    ->boolean()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Creado'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Actualizado'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\Action::make('import_nutritional_information')
                    ->label(__('Importar Información Nutricional'))
                    ->color('info')
                    ->icon('tabler-file-upload')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->disk('s3')
                            ->visibility('private')
                            ->maxSize(10240)
                            ->maxFiles(1)
                            ->directory('nutritional-information-imports')
                            ->label(__('Archivo'))
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel'
                            ])
                            ->required(),
                    ])
                    ->action(function (
                        array $data,
                        ImportServiceInterface $importService,
                        NutritionalInformationRepositoryInterface $repository
                    ) {
                        try {
                            $importProcess = $importService->importWithRepository(
                                importerClass: NutritionalInformationImport::class,
                                filePath: $data['file'],
                                importType: ImportProcess::TYPE_NUTRITIONAL_INFORMATION,
                                repository: $repository,
                                disk: 's3'
                            );

                            Notification::make()
                                ->title(__('Importación iniciada'))
                                ->body(__('El proceso de importación finalizará en breve'))
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('Error'))
                                ->body(__('El proceso ha fallado'))
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('download_nutritional_information_template')
                    ->label(__('Bajar plantilla de información nutricional'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->action(function (\App\Services\TemplateDownloadService $templateService) {
                        return $templateService->download(
                            \App\Exports\NutritionalInformationTemplateExport::class,
                            'template_importacion_info_nutricional.xlsx'
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('generate_label')
                    ->label(__('Generar Etiqueta'))
                    ->icon('heroicon-o-document-text')
                    ->color('success')
                    ->visible(fn (NutritionalInformation $record): bool => $record->generate_label === true)
                    ->form([
                        Forms\Components\DatePicker::make('elaboration_date')
                            ->label(__('Fecha de Elaboración'))
                            ->default(now())
                            ->displayFormat('d/m/Y')
                            ->format('d/m/Y')
                            ->required(),
                        Forms\Components\TextInput::make('quantity')
                            ->label(__('Cantidad de Etiquetas'))
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->maxValue(100)
                            ->required()
                            ->helperText(__('Especifica cuántas etiquetas deseas generar para este producto')),
                    ])
                    ->action(function (NutritionalInformation $record, array $data, NutritionalLabelService $labelService) {
                        try {
                            $productId = $record->product_id;
                            $elaborationDate = $data['elaboration_date'];
                            $quantity = (int) $data['quantity'];

                            // Create quantities array with structure [product_id => quantity]
                            $quantities = [$productId => $quantity];

                            $exportProcess = $labelService->generateLabels([$productId], $elaborationDate, $quantities);

                            Notification::make()
                                ->title(__('Generación de etiqueta iniciada'))
                                ->body(__('Se generarán :count etiqueta(s) del producto. El proceso finalizará en breve.', ['count' => $quantity]))
                                ->success()
                                ->send();

                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('Error'))
                                ->body(__('Ha ocurrido un error al iniciar la generación de etiqueta: ') . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function ($record) {
                        $deletionService = new NutritionalInformationDeletionService(
                            DeleteNutritionalInformationJob::class
                        );

                        $success = $deletionService->deleteSingle($record->id);

                        if ($success) {
                            Notification::make()
                                ->title(__('Eliminación programada'))
                                ->body(__('La información nutricional se eliminará en breve'))
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title(__('Error'))
                                ->body(__('No se pudo programar la eliminación'))
                                ->danger()
                                ->send();
                        }
                    })
                    ->using(fn () => null),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('generate_labels')
                        ->label(__('Generar Etiquetas'))
                        ->icon('heroicon-o-document-text')
                        ->color('success')
                        ->form([
                            Forms\Components\DatePicker::make('elaboration_date')
                                ->label(__('Fecha de Elaboración'))
                                ->default(now())
                                ->displayFormat('d/m/Y')
                                ->format('d/m/Y')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data, NutritionalLabelService $labelService) {
                            try {
                                if ($records->isEmpty()) {
                                    Notification::make()
                                        ->title(__('Sin registros'))
                                        ->body(__('No hay registros seleccionados para generar etiquetas'))
                                        ->warning()
                                        ->send();
                                    return;
                                }

                                $productIds = $records->pluck('product_id')->toArray();
                                $elaborationDate = $data['elaboration_date'];

                                $exportProcess = $labelService->generateLabels($productIds, $elaborationDate);

                                Notification::make()
                                    ->title(__('Generación de etiquetas iniciada'))
                                    ->body(__('Las etiquetas nutricionales se están generando. El proceso finalizará en breve.'))
                                    ->success()
                                    ->send();

                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title(__('Error'))
                                    ->body(__('Ha ocurrido un error al iniciar la generación de etiquetas: ') . $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            $deletionService = new NutritionalInformationDeletionService(
                                DeleteNutritionalInformationJob::class
                            );

                            $ids = $records->pluck('id')->toArray();
                            $count = $deletionService->deleteMultiple($ids);

                            if ($count > 0) {
                                Notification::make()
                                    ->title(__('Eliminación programada'))
                                    ->body(__(':count registros se eliminarán en breve', ['count' => $count]))
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title(__('Error'))
                                    ->body(__('No se pudo programar la eliminación'))
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->using(fn () => null),
                ]),
            ])
            ->recordUrl(fn ($record) => Pages\EditNutritionalInformation::getUrl([$record]))
            ->defaultSort('product.code');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\NutritionalValuesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNutritionalInformation::route('/'),
            'create' => Pages\CreateNutritionalInformation::route('/create'),
            'edit' => Pages\EditNutritionalInformation::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\AdvanceOrderResource\RelationManagers;

use App\Repositories\AdvanceOrderRepository;
use App\Repositories\OrderRepository;
use App\Services\Labels\NutritionalLabelService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'advanceOrderProducts';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Productos de la Orden de Producción');
    }

    protected static function getRecordLabel(): ?string
    {
        return __('Producto de la orden de producción');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('advance_order_id')
                    ->default($this->ownerRecord->id),
                Forms\Components\Select::make('product_id')
                    ->label(__('Producto'))
                    ->relationship(
                        name: 'product',
                        titleAttribute: 'name'
                    )
                    ->searchable()
                    ->required()
                    ->disabled(fn ($context) => $context === 'edit')
                    ->getOptionLabelFromRecordUsing(fn(Model $record) => "{$record->code} - {$record->name}"),
                Forms\Components\TextInput::make('ordered_quantity')
                    ->label(__('Cantidad Total en Pedidos'))
                    ->numeric()
                    ->disabled()
                    ->dehydrated()
                    ->default(0),
                Forms\Components\TextInput::make('ordered_quantity_new')
                    ->label(__('Cantidad en Pedidos Nuevos'))
                    ->numeric()
                    ->disabled()
                    ->dehydrated()
                    ->default(0),
                Forms\Components\TextInput::make('quantity')
                    ->label(__('Cantidad a Adelantar'))
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->default(0)
                    ->rules([
                        function ($get) {
                            return function (string $attribute, $value, \Closure $fail) use ($get) {
                                $orderedQuantityNew = $get('ordered_quantity_new') ?? 0;

                                if ($value > 0 && $value < $orderedQuantityNew) {
                                    $fail("La cantidad a adelantar debe ser 0 o mayor o igual a la cantidad en pedidos nuevos ({$orderedQuantityNew}).");
                                }
                            };
                        },
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('product.code')
                    ->label(__('Producto'))
                    ->formatStateUsing(fn ($record) => "{$record->product->code} - {$record->product->name}")
                    ->searchable(query: function ($query, $search) {
                        return $query->whereHas('product', function ($q) use ($search) {
                            $q->where('code', 'like', "%{$search}%")
                              ->orWhere('name', 'like', "%{$search}%");
                        });
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('ordered_quantity')
                    ->label(__('Total Pedidos'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->width('120px'),
                Tables\Columns\TextColumn::make('ordered_quantity_new')
                    ->label(__('Pedidos Nuevos'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->width('120px')
                    ->extraAttributes(['class' => 'font-bold text-primary-600']),
                Tables\Columns\TextInputColumn::make('quantity')
                    ->label(__('Adelantar'))
                    ->rules(['required', 'numeric', 'min:0'])
                    ->alignCenter()
                    ->width('120px')
                    ->extraAttributes(['class' => 'font-bold'])
                    ->disabled(fn () => $this->ownerRecord->status === \App\Enums\AdvanceOrderStatus::EXECUTED)
                    ->beforeStateUpdated(function ($record, $state) {
                        $orderedQuantityNew = $record->ordered_quantity_new ?? 0;

                        if ($state > 0 && $state < $orderedQuantityNew) {
                            Notification::make()
                                ->danger()
                                ->title('Error de validación')
                                ->body("La cantidad a adelantar debe ser 0 o mayor o igual a la cantidad en pedidos nuevos ({$orderedQuantityNew}).")
                                ->send();

                            throw ValidationException::withMessages([
                                'quantity' => "La cantidad a adelantar debe ser 0 o mayor o igual a {$orderedQuantityNew}.",
                            ]);
                        }
                    }),
                Tables\Columns\TextColumn::make('total_to_produce')
                    ->label(__('Elaborar'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->width('120px')
                    ->extraAttributes(['class' => 'font-bold text-success-600']),
            ])
            ->defaultSort('product.code')
            ->filters([
                Tables\Filters\SelectFilter::make('has_nutritional_info')
                    ->label(__('Información Nutricional'))
                    ->options([
                        'with' => __('Con Información Nutricional'),
                        'without' => __('Sin Información Nutricional'),
                    ])
                    ->query(function ($query, array $data) {
                        if (!isset($data['value'])) {
                            return $query;
                        }

                        if ($data['value'] === 'with') {
                            return $query->whereHas('product.nutritionalInformation', function ($q) {
                                $q->where('generate_label', true);
                            });
                        }

                        if ($data['value'] === 'without') {
                            return $query->whereDoesntHave('product.nutritionalInformation', function ($q) {
                                $q->where('generate_label', true);
                            });
                        }

                        return $query;
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('Crear'))
                    ->visible(fn () => $this->ownerRecord->status !== \App\Enums\AdvanceOrderStatus::EXECUTED)
                    ->mutateFormDataUsing(function (array $data): array {
                        $advanceOrder = $this->ownerRecord;
                        $productId = $data['product_id'];

                        // Get current ordered quantity from orders
                        $orderRepository = new OrderRepository();
                        $productsData = $orderRepository->getProductsFromOrdersInDateRange(
                            $advanceOrder->initial_dispatch_date->format('Y-m-d'),
                            $advanceOrder->final_dispatch_date->format('Y-m-d')
                        );

                        $productData = $productsData->firstWhere('product_id', $productId);
                        $currentOrderedQuantity = $productData['ordered_quantity'] ?? 0;

                        // Get max ordered quantity from previous advance orders
                        $advanceOrderRepository = app(AdvanceOrderRepository::class);
                        $previousAdvanceOrders = $advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($advanceOrder);
                        $maxPreviousQuantity = $advanceOrderRepository->getMaxOrderedQuantityForProduct($productId, $previousAdvanceOrders, $advanceOrder);

                        $data['ordered_quantity'] = $currentOrderedQuantity;
                        $data['ordered_quantity_new'] = max(0, $currentOrderedQuantity - $maxPreviousQuantity);

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('generate_label')
                    ->label(__('Generar Etiqueta'))
                    ->icon('heroicon-o-document-text')
                    ->color('success')
                    ->visible(function ($record) {
                        // Only show if product has nutritional info with generate_label = true
                        return $record->product->nutritionalInformation
                            && $record->product->nutritionalInformation->generate_label === true;
                    })
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
                            ->default(fn ($record) => $record->total_to_produce ?? 0)
                            ->minValue(1)
                            ->required()
                            ->helperText(__('Cantidad de etiquetas a generar para este producto')),
                    ])
                    ->action(function ($record, array $data, NutritionalLabelService $labelService) {
                        try {
                            $productId = $record->product_id;
                            $elaborationDate = $data['elaboration_date'];
                            $quantity = (int) $data['quantity'];

                            if ($quantity <= 0) {
                                Notification::make()
                                    ->title(__('Cantidad inválida'))
                                    ->body(__('La cantidad debe ser mayor a 0'))
                                    ->warning()
                                    ->send();
                                return;
                            }

                            // Create quantities array with structure [product_id => quantity]
                            $quantities = [$productId => $quantity];

                            $exportProcess = $labelService->generateLabels([$productId], $elaborationDate, $quantities);

                            Notification::make()
                                ->title(__('Generación de etiquetas iniciada'))
                                ->body(__('Se generarán :count etiqueta(s) del producto :product. El proceso finalizará en breve.', [
                                    'count' => $quantity,
                                    'product' => $record->product->name
                                ]))
                                ->success()
                                ->send();

                        } catch (\Exception $e) {
                            Log::error('Error al iniciar generación de etiqueta nutricional desde orden de producción', [
                                'product_id' => $record->product_id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);

                            Notification::make()
                                ->title(__('Error'))
                                ->body(__('Ha ocurrido un error al iniciar la generación de etiquetas: ') . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make()
                    ->label(__('Eliminar'))
                    ->visible(fn () => $this->ownerRecord->status !== \App\Enums\AdvanceOrderStatus::EXECUTED),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('generate_nutritional_labels')
                        ->label(__('Generar Etiquetas Nutricionales'))
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
                                        ->body(__('No hay productos seleccionados para generar etiquetas'))
                                        ->warning()
                                        ->send();
                                    return;
                                }

                                // Build quantities array from total_to_produce field
                                $quantities = [];
                                $productIds = [];

                                foreach ($records as $record) {
                                    $productId = $record->product_id;
                                    $quantity = $record->total_to_produce ?? 0;

                                    if ($quantity > 0) {
                                        $productIds[] = $productId;
                                        $quantities[$productId] = $quantity;
                                    }
                                }

                                if (empty($productIds)) {
                                    Notification::make()
                                        ->title(__('Sin productos a elaborar'))
                                        ->body(__('Los productos seleccionados no tienen cantidad a elaborar'))
                                        ->warning()
                                        ->send();
                                    return;
                                }

                                $elaborationDate = $data['elaboration_date'];
                                $totalLabels = array_sum($quantities);

                                $exportProcess = $labelService->generateLabels($productIds, $elaborationDate, $quantities);

                                Notification::make()
                                    ->title(__('Generación de etiquetas iniciada'))
                                    ->body(__('Se generarán :count etiqueta(s) de :products producto(s). El proceso finalizará en breve.', [
                                        'count' => $totalLabels,
                                        'products' => count($productIds)
                                    ]))
                                    ->success()
                                    ->send();

                            } catch (\Exception $e) {
                                Log::error('Error al iniciar generación de etiquetas nutricionales desde orden de producción', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                Notification::make()
                                    ->title(__('Error'))
                                    ->body(__('Ha ocurrido un error al iniciar la generación de etiquetas: ') . $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->label(__('Eliminar seleccionados'))
                        ->visible(fn () => $this->ownerRecord->status !== \App\Enums\AdvanceOrderStatus::EXECUTED),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()
                    ->visible(fn () => $this->ownerRecord->status !== \App\Enums\AdvanceOrderStatus::EXECUTED)
                    ->mutateFormDataUsing(function (array $data): array {
                        $advanceOrder = $this->ownerRecord;
                        $productId = $data['product_id'];

                        // Get current ordered quantity from orders
                        $orderRepository = new OrderRepository();
                        $productsData = $orderRepository->getProductsFromOrdersInDateRange(
                            $advanceOrder->initial_dispatch_date->format('Y-m-d'),
                            $advanceOrder->final_dispatch_date->format('Y-m-d')
                        );

                        $productData = $productsData->firstWhere('product_id', $productId);
                        $currentOrderedQuantity = $productData['ordered_quantity'] ?? 0;

                        // Get max ordered quantity from previous advance orders
                        $advanceOrderRepository = app(AdvanceOrderRepository::class);
                        $previousAdvanceOrders = $advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($advanceOrder);
                        $maxPreviousQuantity = $advanceOrderRepository->getMaxOrderedQuantityForProduct($productId, $previousAdvanceOrders, $advanceOrder);

                        $data['ordered_quantity'] = $currentOrderedQuantity;
                        $data['ordered_quantity_new'] = max(0, $currentOrderedQuantity - $maxPreviousQuantity);

                        return $data;
                    }),
            ]);
    }
}

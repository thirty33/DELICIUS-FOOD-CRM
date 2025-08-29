<?php

namespace App\Filament\Resources\DispatchRuleResource\RelationManagers;

use App\Models\DispatchRuleRange;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Model;
use Pelmered\FilamentMoneyField\Forms\Components\MoneyInput;
use Pelmered\FilamentMoneyField\Tables\Columns\MoneyColumn;

class RangesRelationManager extends RelationManager
{
    protected static string $relationship = 'ranges';
    
    protected static ?string $title = 'Rangos de Costo';
    
    protected static ?string $modelLabel = 'Rango';
    
    protected static ?string $pluralModelLabel = 'Rangos';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                MoneyInput::make('min_amount')
                    ->label('Monto Mínimo')
                    ->required()
                    ->currency('USD')
                    ->locale('en_US')
                    ->minValue(0)
                    ->decimals(2)
                    ->live(onBlur: true)
                    ->rules([
                        fn (Get $get, ?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                            // Validation 3 and 4: Range overlap and exclusive boundaries
                            $maxAmount = $get('max_amount');
                            // Convert values to cents for validation - handle comma-formatted numbers
                            $cleanValue = $value !== null ? str_replace(',', '', $value) : null;
                            $cleanMaxAmount = $maxAmount !== null ? str_replace(',', '', $maxAmount) : null;
                            $valueInCents = ($cleanValue !== null && is_numeric($cleanValue)) ? (int) ($cleanValue * 100) : null;
                            $maxInCents = ($cleanMaxAmount !== null && is_numeric($cleanMaxAmount)) ? (int) ($cleanMaxAmount * 100) : null;
                            
                            
                            $this->validateRangeOverlap($valueInCents, $maxInCents, $record, $fail);
                        }
                    ]),
                MoneyInput::make('max_amount')
                    ->label('Monto Máximo')
                    ->currency('USD')
                    ->locale('en_US')
                    ->minValue(0)
                    ->decimals(2)
                    ->helperText('Dejar vacío para "sin límite"')
                    ->live(onBlur: true)
                    ->rules([
                        // Validation 1: min_amount < max_amount
                        fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                            $minAmount = $get('min_amount');
                            // MoneyInput converts values to cents (integers) - handle comma-formatted numbers
                            $cleanValue = $value !== null ? str_replace(',', '', $value) : null;
                            $cleanMinAmount = $minAmount !== null ? str_replace(',', '', $minAmount) : null;
                            $valueInCents = ($cleanValue !== null && is_numeric($cleanValue)) ? (int) ($cleanValue * 100) : null;
                            $minInCents = ($cleanMinAmount !== null && is_numeric($cleanMinAmount)) ? (int) ($cleanMinAmount * 100) : null;
                            
                            if ($valueInCents !== null && $minInCents !== null && $valueInCents <= $minInCents) {
                                $fail('El monto máximo debe ser mayor al monto mínimo.');
                            }
                        },
                        // Validation 2: Only one unlimited rule
                        fn (Get $get, ?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                            if ($value === null) {
                                $query = DispatchRuleRange::where('dispatch_rule_id', $this->getOwnerRecord()->id)
                                    ->whereNull('max_amount');
                                
                                if ($record) {
                                    $query->where('id', '!=', $record->id);
                                }
                                
                                if ($query->exists()) {
                                    $fail('Ya existe una regla sin límite máximo para esta regla de despacho.');
                                }
                            }
                        },
                        // Validation 3 and 4: Range overlap and exclusive boundaries
                        fn (Get $get, ?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                            $minAmount = $get('min_amount');
                            // Convert values to cents for validation - handle comma-formatted numbers
                            $cleanValue = $value !== null ? str_replace(',', '', $value) : null;
                            $cleanMinAmount = $minAmount !== null ? str_replace(',', '', $minAmount) : null;
                            $minInCents = ($cleanMinAmount !== null && is_numeric($cleanMinAmount)) ? (int) ($cleanMinAmount * 100) : null;
                            $valueInCents = ($cleanValue !== null && is_numeric($cleanValue)) ? (int) ($cleanValue * 100) : null;
                            
                            
                            $this->validateRangeOverlap($minInCents, $valueInCents, $record, $fail);
                        }
                    ]),
                MoneyInput::make('dispatch_cost')
                    ->label('Costo de Despacho')
                    ->required()
                    ->currency('USD')
                    ->locale('en_US')
                    ->minValue(0)
                    ->decimals(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('min_amount')
            ->defaultSort('min_amount', 'asc')
            ->columns([
                MoneyColumn::make('min_amount')
                    ->label('Monto Mínimo')
                    ->currency('USD')
                    ->locale('en_US')
                    ->sortable(),
                MoneyColumn::make('max_amount')
                    ->label('Monto Máximo')
                    ->currency('USD')
                    ->locale('en_US')
                    ->sortable()
                    ->placeholder('Sin límite'),
                MoneyColumn::make('dispatch_cost')
                    ->label('Costo Despacho')
                    ->currency('USD')
                    ->locale('en_US')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Agregar Rango'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
    
    /**
     * Validates range overlap and exclusive boundaries
     * Values are expected to be in cents (integers)
     */
    private function validateRangeOverlap($minAmount, $maxAmount, ?Model $record, Closure $fail): void
    {
        if ($minAmount === null) {
            return;
        }
        
        $query = DispatchRuleRange::where('dispatch_rule_id', $this->getOwnerRecord()->id);
        
        // Exclude current record if being edited
        if ($record) {
            $query->where('id', '!=', $record->id);
        }
        
        $existingRanges = $query->orderBy('min_amount')->get();
        
        
        foreach ($existingRanges as $existingRange) {
            $existingMin = $existingRange->min_amount; // Already in cents from DB
            $existingMax = $existingRange->max_amount; // Already in cents from DB
            
            // Convert to display format (dollars) for error messages
            $existingMinDisplay = $existingMin / 100;
            $existingMaxDisplay = $existingMax ? ($existingMax / 100) : null;
            
            
            // Validation 3: Detect overlap
            // A range overlaps if: new_min < existing_max AND new_max > existing_min
            if ($maxAmount === null) {
                // If new range has no limit, it overlaps with any range starting after min_amount
                if ($existingMin >= $minAmount) {
                    $fail("El rango se solapa con el rango existente [\${$existingMinDisplay} - " . 
                          ($existingMaxDisplay ? "\${$existingMaxDisplay}" : 'sin límite') . "]");
                    return;
                }
            } else {
                // Check normal overlap
                if ($existingMax === null) {
                    // Existing range has no limit - only overlap if new range max is >= existing min
                    if ($maxAmount >= $existingMin) {
                        $fail("El rango se solapa con el rango existente [\${$existingMinDisplay} - sin límite]");
                        return;
                    }
                } else {
                    // Both ranges have limits - overlap if ranges actually intersect (not just touch)
                    if ($minAmount < $existingMax && $maxAmount > $existingMin) {
                        $fail("El rango se solapa con el rango existente [\${$existingMinDisplay} - \${$existingMaxDisplay}]");
                        return;
                    }
                }
            }
            
            // Validation 4: Check exclusive boundaries (limits cannot be exactly equal)
            if ($existingMax !== null && $minAmount == $existingMax) {
                $suggestedValue = ($existingMax + 1) / 100; // Convert back to dollars
                $fail("El límite inferior coincide con el límite superior del rango [\${$existingMinDisplay} - \${$existingMaxDisplay}]. Use " . 
                      "\${$suggestedValue} como límite inferior.");
                return;
            }
            
            if ($maxAmount !== null && $existingMin == $maxAmount) {
                $suggestedValue = ($existingMin - 1) / 100; // Convert back to dollars
                $fail("El límite superior coincide con el límite inferior del rango [\${$existingMinDisplay} - " . 
                      ($existingMaxDisplay ? "\${$existingMaxDisplay}" : 'sin límite') . "]. Use " . 
                      "\${$suggestedValue} como límite superior.");
                return;
            }
        }
    }
}

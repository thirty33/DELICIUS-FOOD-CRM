<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DispatchRuleResource\Pages;
use App\Filament\Resources\DispatchRuleResource\RelationManagers;
use App\Models\DispatchRule;
use App\Models\Company;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DispatchRuleResource extends Resource
{
    protected static ?string $model = DispatchRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 100;

    public static function getNavigationGroup(): ?string
    {
        return __('Transporte');
    }
    
    public static function getLabel(): ?string
    {
        return __('Regla de despacho');
    }
    
    public static function getNavigationLabel(): string
    {
        return __('Reglas de despacho');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->extraAttributes(['novalidate' => true])
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nombre')
                    ->required(false)
                    ->maxLength(255)
                    ->regex('/^[a-zA-Z0-9\s,.\/_-]+$/')
                    ->rules(['required', 'max:255', 'regex:/^[a-zA-Z0-9\s,.\/_-]+$/'])
                    ->validationMessages([
                        'required' => 'El nombre es obligatorio.',
                        'max' => 'El nombre no puede tener más de 255 caracteres.',
                        'regex' => 'Solo se permiten letras, números, espacios, comas, puntos, slashes y guiones.',
                    ]),
                Forms\Components\TextInput::make('priority')
                    ->label('Prioridad')
                    ->required(false)
                    ->integer()
                    ->minValue(1)
                    ->helperText('Menor número = mayor prioridad')
                    ->rules(['required', 'integer', 'min:1'])
                    ->validationMessages([
                        'required' => 'La prioridad es obligatoria.',
                        'integer' => 'La prioridad debe ser un número entero.',
                        'min' => 'La prioridad debe ser mayor a 0.',
                    ]),
                Forms\Components\Toggle::make('active')
                    ->label('Activo')
                    ->default(false),
                Forms\Components\CheckboxList::make('companies')
                    ->relationship('companies', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Company $record) => $record->name . '-' . $record->registration_number)
                    ->label('Empresas')
                    ->columnSpanFull()
                    ->columns(1)
                    ->searchable()
                    ->bulkToggleable()
                    ->gridDirection('row')
                    ->live()
                    ->required(false)
                    ->minItems(1)
                    ->rules(['required', 'min:1'])
                    ->validationMessages([
                        'required' => 'Debe seleccionar al menos una empresa.',
                        'min' => 'Debe seleccionar al menos una empresa.',
                    ])
                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?array $state) {
                        if (!empty($state)) {
                            // Get all branches from selected companies
                            $branchIds = Branch::whereIn('company_id', $state)
                                ->pluck('id')
                                ->toArray();
                            
                            // Mark all branches as selected
                            $set('branches', $branchIds);
                        } else {
                            // If no companies selected, clear branches
                            $set('branches', []);
                        }
                    })
                    ->extraAttributes(['style' => 'max-height: 300px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 0.375rem; padding: 1rem;']),
                Forms\Components\CheckboxList::make('branches')
                    ->relationship('branches', 'name')
                    ->label('Sucursales')
                    ->columnSpanFull()
                    ->columns(1)
                    ->searchable()
                    ->bulkToggleable()
                    ->gridDirection('row')
                    ->required(false)
                    ->minItems(1)
                    ->rules(['required', 'min:1'])
                    ->validationMessages([
                        'required' => 'Debe seleccionar al menos una sucursal.',
                        'min' => 'Debe seleccionar al menos una sucursal.',
                    ])
                    ->options(function (Forms\Get $get) {
                        $selectedCompanies = $get('companies') ?? [];
                        if (empty($selectedCompanies)) {
                            return [];
                        }
                        return Branch::whereIn('company_id', $selectedCompanies)
                            ->with('company')
                            ->get()
                            ->mapWithKeys(fn (Branch $branch) => [$branch->id => $branch->company->name . '-' . $branch->company->registration_number])
                            ->toArray();
                    })
                    ->descriptions(function (Forms\Get $get) {
                        $selectedCompanies = $get('companies') ?? [];
                        if (empty($selectedCompanies)) {
                            return [];
                        }
                        return Branch::whereIn('company_id', $selectedCompanies)
                            ->get()
                            ->mapWithKeys(fn (Branch $branch) => [$branch->id => $branch->branch_code . '-' . $branch->fantasy_name . '-' . $branch->address])
                            ->toArray();
                    })
                    ->extraAttributes(['style' => 'max-height: 300px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 0.375rem; padding: 1rem;']),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('priority', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioridad')
                    ->numeric()
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\IconColumn::make('active')
                    ->label('Activo')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RangesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDispatchRules::route('/'),
            'create' => Pages\CreateDispatchRule::route('/create'),
            'edit' => Pages\EditDispatchRule::route('/{record}/edit'),
        ];
    }
}

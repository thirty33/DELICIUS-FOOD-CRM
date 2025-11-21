<?php

namespace App\Filament\Resources\ReportConfigurationResource\RelationManagers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ReportGrouper;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
class GroupersRelationManager extends RelationManager
{
    protected static string $relationship = 'groupers';

    protected static ?string $title = 'Agrupadores';

    protected static ?string $modelLabel = 'agrupador';

    protected static ?string $pluralModelLabel = 'agrupadores';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Nombre del agrupador (ej: "CAFETERIA ALMA TERRA")'),

                Forms\Components\TextInput::make('code')
                    ->label('Código')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Código único para este agrupador'),

                Forms\Components\TextInput::make('display_order')
                    ->label('Orden de Visualización')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->helperText('Orden en el que aparecerá en los reportes (menor = primero)'),

                Forms\Components\Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true)
                    ->helperText('Solo los agrupadores activos aparecen en los reportes'),

                Forms\Components\Section::make('Configuración de Empresas y Sucursales')
                    ->description('Configure qué empresas y sucursales pertenecen a este agrupador')
                    ->schema([
                        Forms\Components\Repeater::make('company_configs')
                            ->label('Empresas')
                            ->schema([
                                Forms\Components\Select::make('company_id')
                                    ->label('Empresa')
                                    ->options(
                                        Company::all()
                                            ->mapWithKeys(fn ($company) => [
                                                $company->id => "{$company->company_code} - {$company->fantasy_name}"
                                            ])
                                    )
                                    ->searchable()
                                    ->required()
                                    ->reactive()
                                    ->afterStateUpdated(function (Set $set) {
                                        $set('use_all_branches', true);
                                        $set('branch_ids', []);
                                    })
                                    ->disableOptionWhen(function ($value, $state, Get $get) {
                                        // Disable if company is already selected in another row
                                        $configs = $get('../../company_configs') ?? [];
                                        $currentIndex = array_search($state, array_column($configs, 'company_id'));

                                        foreach ($configs as $index => $config) {
                                            if (isset($config['company_id']) &&
                                                $config['company_id'] == $value &&
                                                $index != $currentIndex) {
                                                return true;
                                            }
                                        }
                                        return false;
                                    }),

                                Forms\Components\Toggle::make('use_all_branches')
                                    ->label('Usar todas las sucursales')
                                    ->default(true)
                                    ->reactive()
                                    ->helperText('Si está activado, se incluirán todas las sucursales de esta empresa'),

                                Forms\Components\Select::make('branch_ids')
                                    ->label('Sucursales específicas')
                                    ->multiple()
                                    ->options(fn (Get $get) =>
                                        Branch::where('company_id', $get('company_id'))
                                            ->get()
                                            ->mapWithKeys(fn ($branch) => [
                                                $branch->id => "{$branch->branch_code} - {$branch->fantasy_name}"
                                            ])
                                    )
                                    ->searchable()
                                    ->visible(fn (Get $get) => !$get('use_all_branches'))
                                    ->required(fn (Get $get) => !$get('use_all_branches'))
                                    ->helperText('Seleccione las sucursales específicas para esta empresa'),
                            ])
                            ->columns(1)
                            ->defaultItems(0)
                            ->addActionLabel('Agregar Empresa')
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string =>
                                isset($state['company_id'])
                                    ? Company::find($state['company_id'])?->fantasy_name
                                    : null
                            ),
                    ]),
            ])
            ->columns(1);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Get the record from the form
        $grouper = $this->getRecord();

        if ($grouper) {
            // Load relationships
            $grouper->load(['companies', 'branches']);

            $companyConfigs = [];

            foreach ($grouper->companies as $company) {
                $useAllBranches = $company->pivot->use_all_branches ?? true;

                // Get branches for this company in this grouper
                $branchIds = $grouper->branches()
                    ->where('branches.company_id', $company->id)
                    ->pluck('branches.id')
                    ->toArray();

                $companyConfigs[] = [
                    'company_id' => $company->id,
                    'use_all_branches' => $useAllBranches,
                    'branch_ids' => $branchIds,
                ];
            }

            $data['company_configs'] = $companyConfigs;
        }

        return $data;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['company_configs']);
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['company_configs']);
        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncCompaniesAndBranches();
    }

    protected function afterSave(): void
    {
        $this->syncCompaniesAndBranches();
    }

    protected function syncCompaniesAndBranches(): void
    {
        $data = $this->form->getState();
        $grouper = $this->record;
        $companyConfigs = $data['company_configs'] ?? [];

        $companySyncData = [];
        $branchSyncData = [];

        foreach ($companyConfigs as $config) {
            $companyId = $config['company_id'];
            $useAllBranches = $config['use_all_branches'] ?? true;
            $branchIds = $config['branch_ids'] ?? [];

            $companySyncData[$companyId] = [
                'use_all_branches' => $useAllBranches,
            ];

            if (!$useAllBranches && !empty($branchIds)) {
                foreach ($branchIds as $branchId) {
                    $branchSyncData[] = $branchId;
                }
            }
        }

        $grouper->companies()->sync($companySyncData);
        $grouper->branches()->sync($branchSyncData);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('display_order')
                    ->label('Orden')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('companies_count')
                    ->label('Empresas')
                    ->counts('companies')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('branches_count')
                    ->label('Sucursales')
                    ->counts('branches')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip('Número de sucursales específicas configuradas'),

                Tables\Columns\TextColumn::make('configuration_summary')
                    ->label('Configuración')
                    ->formatStateUsing(function (ReportGrouper $record): string {
                        $companies = $record->companies;
                        $summary = [];

                        foreach ($companies as $company) {
                            $useAll = $company->pivot->use_all_branches ?? true;
                            $companyName = $company->fantasy_name;

                            if ($useAll) {
                                $summary[] = "{$companyName} (todas)";
                            } else {
                                $branchCount = $record->branches()
                                    ->where('branches.company_id', $company->id)
                                    ->count();
                                $summary[] = "{$companyName} ({$branchCount} suc.)";
                            }
                        }

                        return implode(', ', $summary);
                    })
                    ->wrap()
                    ->limit(50)
                    ->tooltip(function (ReportGrouper $record): ?string {
                        $companies = $record->companies;
                        $details = [];

                        foreach ($companies as $company) {
                            $useAll = $company->pivot->use_all_branches ?? true;
                            $companyName = $company->fantasy_name;

                            if ($useAll) {
                                $details[] = "{$companyName}: Todas las sucursales";
                            } else {
                                $branches = $record->branches()
                                    ->where('branches.company_id', $company->id)
                                    ->get()
                                    ->pluck('fantasy_name')
                                    ->toArray();
                                $branchList = implode(', ', $branches);
                                $details[] = "{$companyName}: {$branchList}";
                            }
                        }

                        return implode("\n", $details);
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activo')
                    ->placeholder('Todos')
                    ->trueLabel('Sí')
                    ->falseLabel('No'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Crear Agrupador'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, ReportGrouper $record): array {
                        $record->load(['companies', 'branches']);

                        $companyConfigs = [];

                        foreach ($record->companies as $company) {
                            $useAllBranches = $company->pivot->use_all_branches ?? true;

                            $branchIds = $record->branches()
                                ->where('branches.company_id', $company->id)
                                ->pluck('branches.id')
                                ->toArray();

                            $companyConfigs[] = [
                                'company_id' => $company->id,
                                'use_all_branches' => $useAllBranches,
                                'branch_ids' => $branchIds,
                            ];
                        }

                        $data['company_configs'] = $companyConfigs;

                        return $data;
                    })
                    ->using(function (ReportGrouper $record, array $data): ReportGrouper {
                        $recordData = $data;
                        $companyConfigs = $recordData['company_configs'] ?? [];
                        unset($recordData['company_configs']);

                        $record->update($recordData);

                        $companySyncData = [];
                        $branchSyncData = [];

                        foreach ($companyConfigs as $config) {
                            $companyId = $config['company_id'];
                            $useAllBranches = $config['use_all_branches'] ?? true;
                            $branchIds = $config['branch_ids'] ?? [];

                            $companySyncData[$companyId] = [
                                'use_all_branches' => $useAllBranches,
                            ];

                            if (!$useAllBranches && !empty($branchIds)) {
                                foreach ($branchIds as $branchId) {
                                    $branchSyncData[] = $branchId;
                                }
                            }
                        }

                        $record->companies()->sync($companySyncData);
                        $record->branches()->sync($branchSyncData);

                        return $record;
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('display_order', 'asc');
    }
}

<?php

namespace App\Filament\Resources;

use App\Enums\CampaignChannel;
use App\Enums\CampaignEventType;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Filament\Resources\ReminderResource\Pages;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\Company;
use App\Repositories\CompanyRepository;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReminderResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?int $navigationSort = 85;

    protected static ?string $slug = 'reminders';

    public static function getNavigationGroup(): ?string
    {
        return 'Canales de Venta';
    }

    public static function getLabel(): ?string
    {
        return 'Recordatorio';
    }

    public static function getNavigationLabel(): string
    {
        return 'Recordatorios';
    }

    public static function getPluralLabel(): ?string
    {
        return 'Recordatorios';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', CampaignType::REMINDER->value);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información básica')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre del recordatorio')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('type')
                                    ->label('Tipo')
                                    ->options(CampaignType::class)
                                    ->default(CampaignType::REMINDER->value)
                                    ->disabled()
                                    ->dehydrated(),
                                Forms\Components\Select::make('channel')
                                    ->label('Canal')
                                    ->options(CampaignChannel::class)
                                    ->default(CampaignChannel::WHATSAPP->value)
                                    ->disabled()
                                    ->dehydrated(),
                            ]),
                    ]),

                Forms\Components\Section::make('Mensaje')
                    ->schema([
                        Forms\Components\Textarea::make('content')
                            ->label('Contenido del mensaje')
                            ->required()
                            ->rows(5)
                            ->helperText('Variables disponibles: {{company.name}}, {{company.fantasy_name}}, {{branch.address}}, {{branch.contact_name}}'),
                    ]),

                Forms\Components\Section::make('Destinatarios')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('filter_role')
                                    ->label('Filtrar por Rol')
                                    ->options(collect(RoleName::cases())
                                        ->filter(fn ($role) => $role !== RoleName::ADMIN)
                                        ->mapWithKeys(fn ($role) => [$role->value => $role->value]))
                                    ->placeholder('Todos los roles')
                                    ->live()
                                    ->afterStateUpdated(fn (Forms\Set $set) => $set('companies', [])),
                                Forms\Components\Select::make('filter_permission')
                                    ->label('Filtrar por Permiso')
                                    ->options(collect(PermissionName::cases())
                                        ->mapWithKeys(fn ($permission) => [$permission->value => $permission->value]))
                                    ->placeholder('Todos los permisos')
                                    ->live()
                                    ->afterStateUpdated(fn (Forms\Set $set) => $set('companies', [])),
                            ]),
                        Forms\Components\CheckboxList::make('companies')
                            ->relationship('companies', 'name')
                            ->label('Empresas')
                            ->columnSpanFull()
                            ->columns(1)
                            ->searchable()
                            ->bulkToggleable()
                            ->gridDirection('row')
                            ->live()
                            ->options(function (Forms\Get $get) {
                                $repository = app(CompanyRepository::class);
                                $companies = $repository->getFiltered([
                                    'role' => $get('filter_role'),
                                    'permission' => $get('filter_permission'),
                                ]);

                                return $companies->mapWithKeys(fn (Company $company) => [
                                    $company->id => $company->name . ' - ' . $company->registration_number
                                ])->toArray();
                            })
                            ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?array $state) {
                                if (!empty($state)) {
                                    $branchIds = Branch::whereIn('company_id', $state)
                                        ->pluck('id')
                                        ->toArray();
                                    $set('branches', $branchIds);
                                } else {
                                    $set('branches', []);
                                }
                            })
                            ->extraAttributes(['style' => 'max-height: 300px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 0.375rem; padding: 1rem;']),
                        Forms\Components\CheckboxList::make('branches')
                            ->relationship('branches', 'id')
                            ->label('Sucursales')
                            ->columnSpanFull()
                            ->columns(1)
                            ->searchable()
                            ->bulkToggleable()
                            ->gridDirection('row')
                            ->options(function (Forms\Get $get) {
                                $selectedCompanies = $get('companies') ?? [];
                                if (empty($selectedCompanies)) {
                                    return [];
                                }
                                return Branch::whereIn('company_id', $selectedCompanies)
                                    ->with('company')
                                    ->get()
                                    ->mapWithKeys(fn (Branch $branch) => [
                                        $branch->id => $branch->company->name . ' - ' . $branch->company->registration_number
                                    ])
                                    ->toArray();
                            })
                            ->descriptions(function (Forms\Get $get) {
                                $selectedCompanies = $get('companies') ?? [];
                                if (empty($selectedCompanies)) {
                                    return [];
                                }
                                return Branch::whereIn('company_id', $selectedCompanies)
                                    ->get()
                                    ->mapWithKeys(fn (Branch $branch) => [
                                        $branch->id => $branch->branch_code . ' - ' . $branch->fantasy_name . ' - ' . $branch->address
                                    ])
                                    ->toArray();
                            })
                            ->extraAttributes(['style' => 'max-height: 300px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 0.375rem; padding: 1rem;']),
                    ]),

                Forms\Components\Section::make('Disparador')
                    ->schema([
                        Forms\Components\Select::make('event_type')
                            ->label('Evento que dispara el recordatorio')
                            ->options(CampaignEventType::class)
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make('hours_after')
                            ->label('Horas después del evento')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Forms\Get $get) => $get('event_type') === CampaignEventType::MENU_CREATED->value)
                            ->helperText('El recordatorio se enviará X horas después de crear el menú'),
                        Forms\Components\TextInput::make('hours_before')
                            ->label('Horas antes del evento')
                            ->numeric()
                            ->minValue(1)
                            ->default(24)
                            ->visible(fn (Forms\Get $get) => in_array($get('event_type'), [
                                CampaignEventType::MENU_CLOSING->value,
                                CampaignEventType::CATEGORY_CLOSING->value,
                                CampaignEventType::NO_ORDER_PLACED->value,
                            ]))
                            ->helperText('El recordatorio se enviará X horas antes del cierre'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('channel')
                    ->label('Canal')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
                Tables\Columns\TextColumn::make('triggers.event_type')
                    ->label('Evento')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '-'),
                Tables\Columns\TextColumn::make('companies_count')
                    ->label('Empresas')
                    ->counts('companies')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('branches_count')
                    ->label('Sucursales')
                    ->counts('branches')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(CampaignStatus::class),
            ])
            ->actions([
                Tables\Actions\Action::make('activate')
                    ->label('Activar')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (Campaign $record) => in_array($record->status, [CampaignStatus::DRAFT, CampaignStatus::PAUSED]))
                    ->requiresConfirmation()
                    ->modalHeading('Activar recordatorio')
                    ->modalDescription('¿Está seguro de activar este recordatorio? Se comenzará a enviar según el evento configurado.')
                    ->action(fn (Campaign $record) => $record->update(['status' => CampaignStatus::ACTIVE])),
                Tables\Actions\Action::make('pause')
                    ->label('Pausar')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn (Campaign $record) => $record->status === CampaignStatus::ACTIVE)
                    ->requiresConfirmation()
                    ->modalHeading('Pausar recordatorio')
                    ->modalDescription('¿Está seguro de pausar este recordatorio? No se enviarán más mensajes hasta que lo reactive.')
                    ->action(fn (Campaign $record) => $record->update(['status' => CampaignStatus::PAUSED])),
                Tables\Actions\Action::make('cancel')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Campaign $record) => !in_array($record->status, [CampaignStatus::CANCELLED, CampaignStatus::EXECUTED]))
                    ->requiresConfirmation()
                    ->modalHeading('Cancelar recordatorio')
                    ->modalDescription('¿Está seguro de cancelar este recordatorio? Esta acción no se puede deshacer.')
                    ->action(fn (Campaign $record) => $record->update(['status' => CampaignStatus::CANCELLED])),
                Tables\Actions\EditAction::make()
                    ->visible(fn (Campaign $record) => in_array($record->status, [CampaignStatus::DRAFT, CampaignStatus::ACTIVE, CampaignStatus::PAUSED])),
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReminders::route('/'),
            'create' => Pages\CreateReminder::route('/create'),
            'edit' => Pages\EditReminder::route('/{record}/edit'),
        ];
    }
}
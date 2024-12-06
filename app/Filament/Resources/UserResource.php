<?php

namespace App\Filament\Resources;

use App\Enums\RoleName;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\Role;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;
use Filament\Forms\Components\Toggle;
use Closure;
use Filament\Forms\Get;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Seguridad');
    }

    public static function getLabel(): ?string
    {
        return __('Usuario');
    }

    public static function getNavigationLabel(): string
    {
        return __('Usuarios');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->autofocus()
                    ->required()
                    ->maxLength(200)
                    ->label(__('Nombre')),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(200)
                    ->unique(static::getModel(), 'email', ignoreRecord: true)
                    ->label(__('Correo electrónico')),
                Forms\Components\Select::make('roles')
                    ->relationship('roles', 'name')
                    // ->multiple()
                    ->label(__('Tipo de usuario'))
                    ->required(),
                Forms\Components\Select::make('permissions')
                    ->relationship('permissions', 'name')
                    // ->multiple()
                    ->label(__('Tipo de Convenio'))
                    ->rules([
                        fn(Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                            $agreementRoleId = Role::where('name', RoleName::AGREEMENT)->first()->id;

                            if (in_array($agreementRoleId, (array)$get('roles')) && empty($value)) {
                                $fail(__('El Tipo de Convenio es obligatorio para este tipo de usuario.'));
                            }
                        }
                    ]),
                Forms\Components\Select::make('company_id')
                    ->relationship('company', 'name')
                    ->required()
                    ->label(__('Compañia'))
                    ->columns(1)
                    ->searchable()
                    ->live(),
                Forms\Components\Select::make('branch_id')
                    ->label(__('Sucursal'))
                    ->relationship(
                        name: 'company.branches',
                        titleAttribute: 'fantasy_name',
                        modifyQueryUsing: fn(Builder $query, callable $get) =>
                        $query->when(
                            $get('company_id'),
                            fn($query, $companyId) => $query->where('company_id', $companyId)
                        )
                    )
                    ->required()
                    ->searchable()
                ,
                TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(fn($state) => Hash::make($state))
                    ->dehydrated(fn($state) => filled($state))
                    ->required(fn(string $context): bool => $context === 'create')
                    ->confirmed()
                    ->minLength(8)
                    ->maxLength(25)
                    ->rule(function () {
                        return function (string $attribute, $value, Closure $fail) {
                            // Check lowercase
                            if (!preg_match('/[a-z]/', $value)) {
                                $fail(__('La contraseña debe contener al menos una letra minúscula.'));
                            }

                            // Check uppercase
                            if (!preg_match('/[A-Z]/', $value)) {
                                $fail(__('La contraseña debe contener al menos una letra mayúscula.'));
                            }

                            // Check number
                            if (!preg_match('/[0-9]/', $value)) {
                                $fail(__('La contraseña debe contener al menos un número.'));
                            }

                            // Check special character
                            if (!preg_match('/[@$!%*?&#]/', $value)) {
                                $fail(__('La contraseña debe contener al menos un carácter especial (@$!%*?&#).'));
                            }
                        };
                    })
                    ->label(__('Contraseña')),
                TextInput::make('password_confirmation')
                    ->password()
                    ->label(__('Confirmar contraseña')),
                Toggle::make('allow_late_orders')
                    ->label(__('Permitir pedidos tardíos'))
                    ->default(true)
                    ->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->sortable()
                    ->searchable()
                    ->description(fn(User $user) => $user->email),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label(__('Tipo de usuario'))
                    ->badge(),
                Tables\Columns\TextColumn::make('permissions.name')
                    ->label(__('Tipo de Convenio'))
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Creado'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('Empresa')),
                Tables\Columns\TextColumn::make('branch.fantasy_name')
                    ->label(__('Sucursal'))
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->label(__('Roles'))
                    ->options(Role::pluck('name', 'id')->toArray()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}

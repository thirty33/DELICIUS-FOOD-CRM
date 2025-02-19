<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MenuResource\Pages;
use App\Filament\Resources\MenuResource\RelationManagers;
use App\Models\Menu;
use App\Models\Role;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use App\Enums\RoleName;
use App\Models\Permission;
use Closure;
use Filament\Forms\Get;

class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static ?string $navigationIcon = 'bxs-food-menu';

    protected static ?int $navigationSort = 70;

    public static function getNavigationGroup(): ?string
    {
        return __('Almacén');
    }

    public static function getLabel(): ?string
    {
        return __('Menú');
    }

    public static function getNavigationLabel(): string
    {
        return __('Menus');
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make()
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label(__('Título'))
                            // ->unique(static::getModel(), 'title', ignoreRecord: true)
                            ->required()
                            ->columns(1),
                        DatePicker::make('publication_date')
                            ->label(__('Fecha de despacho'))
                            ->required()
                            ->columns(1)
                            ->native(false)
                            ->displayFormat('M d, Y'),
                        DateTimePicker::make('max_order_date')
                            ->label(__('Fecha y hora máxima de pedido'))
                            ->required()
                            ->columns(1)
                            ->seconds(true)
                            ->format('Y-m-d H:i:s')
                            ->native(false),
                        Forms\Components\Select::make('rol')
                            ->relationship('rol', 'name')
                            ->label(__('Tipo de usuario'))
                            ->required()
                            ->live(),
                        Forms\Components\Select::make('permission')
                            ->relationship('permission', 'name')
                            ->label(__('Tipo de Convenio'))
                            // ->requiredIf('rol', Role::where('name', RoleName::AGREEMENT)->first()->id)
                            // ->validationMessages([
                            //     'required_if' => __('El Tipo de Convenio es obligatorio para este tipo de usuario.')
                            // ])
                            // ->rules([
                            //     fn(Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                            //         $agreementRoleId = Role::where('name', RoleName::AGREEMENT)->first()->id;
                            //         $cafeRoleId = Role::where('name', RoleName::CAFE)->first()->id;

                            //         $selectedRole = $get('rol');

                            //         if ((in_array($selectedRole, [$agreementRoleId, $cafeRoleId])) && empty($value)) {
                            //             $fail(__('El Tipo de Convenio es obligatorio para este tipo de usuario.'));
                            //         }
                            //     }
                            // ])
                            ->required(function (Get $get) {
                                $agreementRoleId = Role::where('name', RoleName::AGREEMENT)->first()->id;
                                $cafeRoleId = Role::where('name', RoleName::CAFE)->first()->id;

                                $selectedRole = $get('rol');

                                return (in_array($selectedRole, [$agreementRoleId, $cafeRoleId])) && empty($value);
                            })
                        ,
                        Toggle::make('active')
                            ->label(__('Activo'))
                            ->default(true)
                            ->inline(false),
                        Forms\Components\Textarea::make('description')
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Descripción'))
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(__('Título'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('publication_date')
                    ->label(__('Fecha de despacho'))
                    ->sortable()
                    ->date('d/m/Y')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rol.name')
                    ->label(__('Tipo de usuario'))
                    ->badge(),
                Tables\Columns\TextColumn::make('permission.name')
                    ->label(__('Tipo de Convenio'))
                    ->badge(),
                // Tables\Columns\TextColumn::make('end_date')
                //     ->label(__('Fecha de finalización'))
                //     ->sortable()
                //     ->date('d/m/Y H:i'),
                Tables\Columns\ToggleColumn::make('active')
                    ->label(__('Activo'))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('rol')
                    ->relationship('rol', 'name')
                    ->label(__('Tipo de usuario'))
                    ->options(Role::pluck('name', 'id')->toArray()),
                Tables\Filters\SelectFilter::make('permission')
                    ->relationship('permission', 'name')
                    ->label(__('Tipo de Convenioo'))
                    ->options(Permission::pluck('name', 'id')->toArray()),
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
        ;
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CategoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMenus::route('/'),
            'create' => Pages\CreateMenu::route('/create'),
            'edit' => Pages\EditMenu::route('/{record}/edit'),
        ];
    }
}

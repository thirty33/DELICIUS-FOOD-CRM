<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MenuResource\Pages;
use App\Filament\Resources\MenuResource\RelationManagers;
use App\Models\Menu;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Toggle;

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
                            ->unique(static::getModel(), 'title', ignoreRecord: true)
                            ->required()
                            ->columns(1),
                        Forms\Components\DateTimePicker::make('start_date')
                            ->label(__('Fecha de inicio'))
                            ->required()
                            ->columns(1)
                            ->rules([
                                function ($get) {
                                    return function (string $attribute, $value, \Closure $fail) use ($get) {
                                        $endDate = $get('end_date');
                                        $id = $get('id');

                                        if ($value && $endDate) {
                                            $overlappingMenu = Menu::where('active', true)
                                                ->where(function (Builder $query) use ($value, $endDate) {
                                                    $query->whereBetween('start_date', [$value, $endDate])
                                                        ->orWhereBetween('end_date', [$value, $endDate])
                                                        ->orWhere(function (Builder $query) use ($value, $endDate) {
                                                            $query->where('start_date', '<=', $value)
                                                                ->where('end_date', '>=', $endDate);
                                                        });
                                                })
                                                ->when($id, function (Builder $query) use ($id) {
                                                    $query->where('id', '!=', $id);
                                                })
                                                ->first();

                                            if ($overlappingMenu) {
                                                $fail(__("El rango de fechas se solapa con otro menú activo."));
                                            }
                                        }
                                    };
                                },
                            ]),
                        Forms\Components\DateTimePicker::make('end_date')
                            ->label(__('Fecha de finalización'))
                            ->required()
                            ->columns(1)
                            ->rules([
                                'after:start_date',
                                function ($get) {
                                    return function (string $attribute, $value, \Closure $fail) use ($get) {
                                        $startDate = $get('start_date');
                                        $id = $get('id');

                                        if ($startDate && $value) {
                                            $overlappingMenu = Menu::where('active', true)
                                                ->where(function (Builder $query) use ($startDate, $value) {
                                                    $query->whereBetween('start_date', [$startDate, $value])
                                                        ->orWhereBetween('end_date', [$startDate, $value])
                                                        ->orWhere(function (Builder $query) use ($startDate, $value) {
                                                            $query->where('start_date', '<=', $startDate)
                                                                ->where('end_date', '>=', $value);
                                                        });
                                                })
                                                ->when($id, function (Builder $query) use ($id) {
                                                    $query->where('id', '!=', $id);
                                                })
                                                ->first();

                                            if ($overlappingMenu) {
                                                $fail(__("El rango de fechas se solapa con otro menú activo."));
                                            }
                                        }
                                    };
                                },
                            ]),
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
                Tables\Columns\TextColumn::make('start_date')
                    ->label(__('Fecha de inicio'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('end_date')
                    ->label(__('Fecha de finalización'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
                Tables\Columns\ToggleColumn::make('active')
                    ->label(__('Activo'))
                    ->sortable(),
            ])
            ->filters([
                //
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

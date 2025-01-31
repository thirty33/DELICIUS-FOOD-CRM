<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Model;

class CategoryUserLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'categoryUserLines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Reglas de despacho del usuario: :name', ['name' => $ownerRecord->name]);
    }

    protected static function getRecordLabel(): ?string
    {
        return __('Regla de despacho');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('category_id')
                    ->relationship('category', 'name')
                    ->required()
                    ->label(__('Categoría'))
                    ->searchable()
                    ->columns(1),
                Forms\Components\Select::make('weekday')
                    ->label('Día de la semana')
                    ->required()
                    ->required()
                    ->options(self::$daysInSpanish),

                Forms\Components\TextInput::make('preparation_days')
                    ->label('Días de preparación')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(1),

                Forms\Components\TimePicker::make('maximum_order_time')
                    ->label('Hora máxima de pedido')
                    ->required()
                    ->seconds(false)
                    ->native(false),
                Forms\Components\Toggle::make('active')
                    ->label('Activo')
                    ->required()
                    ->default(false)
                    ->onColor('success')
                    ->offColor('danger'),
            ]);
    }

    protected static array $daysInSpanish = [
        'monday' => 'Lunes',
        'tuesday' => 'Martes',
        'wednesday' => 'Miércoles',
        'thursday' => 'Jueves',
        'friday' => 'Viernes',
        'saturday' => 'Sábado',
        'sunday' => 'Domingo',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('category_id')
            ->columns([
                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('Categoría'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('weekday')
                    ->label('Día de la semana')
                    ->formatStateUsing(fn(string $state): string => self::$daysInSpanish[$state])
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('preparation_days')
                    ->label('Días de preparación')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('maximum_order_time')
                    ->label('Hora máxima de creación de pedido')
                    ->time()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('active')
                    ->label('Activo')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
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
}

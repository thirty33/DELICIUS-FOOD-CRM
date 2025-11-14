<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportConfigurationResource\Pages;
use App\Filament\Resources\ReportConfigurationResource\RelationManagers;
use App\Models\ReportConfiguration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ReportConfigurationResource extends Resource
{
    protected static ?string $model = ReportConfiguration::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?string $navigationLabel = 'Configuración de Reportes';

    protected static ?string $modelLabel = 'Configuración';

    protected static ?string $pluralModelLabel = 'Configuraciones';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información General')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('Identificador único para esta configuración'),

                        Forms\Components\Textarea::make('description')
                            ->label('Descripción')
                            ->maxLength(255)
                            ->rows(2)
                            ->helperText('Descripción legible de esta configuración'),
                    ]),

                Forms\Components\Section::make('Opciones de Agrupación')
                    ->schema([
                        Forms\Components\Toggle::make('exclude_cafeterias')
                            ->label('Mostrar columna CAFETERIAS')
                            ->helperText('Mostrar columna para cafeterías que NO están en ningún agrupador')
                            ->default(false),

                        Forms\Components\Toggle::make('exclude_agreements')
                            ->label('Mostrar columna CONVENIOS')
                            ->helperText('Mostrar columna para convenios que NO están en ningún agrupador')
                            ->default(false),
                    ])
                    ->columns(1),

                Forms\Components\Section::make('Estado')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Activa')
                            ->helperText('Solo puede haber una configuración activa a la vez')
                            ->default(false),
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
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Descripción')
                    ->searchable()
                    ->limit(50)
                    ->wrap(),

                Tables\Columns\IconColumn::make('exclude_cafeterias')
                    ->label('Col. Cafeterías')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('exclude_agreements')
                    ->label('Col. Convenios')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activa')
                    ->placeholder('Todas')
                    ->trueLabel('Sí')
                    ->falseLabel('No'),
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
            ->defaultSort('is_active', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\GroupersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReportConfigurations::route('/'),
            'create' => Pages\CreateReportConfiguration::route('/create'),
            'edit' => Pages\EditReportConfiguration::route('/{record}/edit'),
        ];
    }
}

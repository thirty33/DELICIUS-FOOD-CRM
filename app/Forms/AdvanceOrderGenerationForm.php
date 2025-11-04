<?php

namespace App\Forms;

use App\Models\ProductionArea;
use Filament\Forms;

class AdvanceOrderGenerationForm
{
    /**
     * Get the form schema for advance order generation
     *
     * @return array
     */
    public static function getSchema(): array
    {
        return [
            Forms\Components\DateTimePicker::make('preparation_datetime')
                ->label('Fecha y hora de preparación')
                ->required()
                ->native(false)
                ->default(now())
                ->helperText('Fecha y hora en que se iniciará la preparación'),
            Forms\Components\Select::make('production_area_ids')
                ->label('Áreas de producción')
                ->multiple()
                ->options(function () {
                    return ProductionArea::query()
                        ->orderBy('name')
                        ->pluck('name', 'id');
                })
                ->default(function () {
                    return ProductionArea::pluck('id')->toArray();
                })
                ->required()
                ->searchable()
                ->preload()
                ->helperText('Seleccione las áreas de producción a incluir en la orden'),
        ];
    }
}

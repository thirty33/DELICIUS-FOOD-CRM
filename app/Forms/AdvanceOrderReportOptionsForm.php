<?php

namespace App\Forms;

use Filament\Forms;

class AdvanceOrderReportOptionsForm
{
    /**
     * Get the form schema for advance order report options
     *
     * @return array
     */
    public static function getSchema(): array
    {
        return [
            Forms\Components\Section::make('Opciones de Visualización del Reporte')
                ->description('Seleccione las columnas que desea mostrar u ocultar en el reporte consolidado')
                ->schema([
                    Forms\Components\Toggle::make('show_excluded_companies')
                        ->label('Mostrar empresas discriminadas')
                        ->helperText('Muestra las columnas de empresas con discriminación en el reporte consolidado')
                        ->default(true)
                        ->inline(false),

                    Forms\Components\Toggle::make('show_all_adelantos')
                        ->label('Mostrar todos los adelantos')
                        ->helperText('Si se desactiva, solo se mostrará el adelanto inicial. Los demás adelantos estarán ocultos')
                        ->default(true)
                        ->inline(false),

                    Forms\Components\Toggle::make('show_total_elaborado')
                        ->label('Mostrar total elaborado')
                        ->helperText('Muestra la columna de total elaborado en el reporte')
                        ->default(true)
                        ->inline(false),

                    Forms\Components\Toggle::make('show_sobrantes')
                        ->label('Mostrar sobrantes')
                        ->helperText('Muestra la columna de sobrantes (stock actual en bodega)')
                        ->default(true)
                        ->inline(false),
                ])
                ->columns(1),
        ];
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        DB::table('parameters')->insert([
            [
                'name' => 'Categoría Productos Más Vendidos - Auto Generar',
                'description' => 'Habilita la generación automática de la categoría de productos más vendidos en los menús de Café',
                'value_type' => 'boolean',
                'value' => '1',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Categoría Productos Más Vendidos - Cantidad de Productos',
                'description' => 'Número de productos a incluir en la categoría de productos más vendidos',
                'value_type' => 'integer',
                'value' => '10',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Categoría Productos Más Vendidos - Rango de Días',
                'description' => 'Número de días hacia atrás para calcular los productos más vendidos (ej: 30 = último mes)',
                'value_type' => 'integer',
                'value' => '30',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('parameters')->whereIn('name', [
            'Categoría Productos Más Vendidos - Auto Generar',
            'Categoría Productos Más Vendidos - Cantidad de Productos',
            'Categoría Productos Más Vendidos - Rango de Días',
        ])->delete();
    }
};

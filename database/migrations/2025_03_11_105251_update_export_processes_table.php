<?php

use App\Models\ExportProcess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('export_processes', function (Blueprint $table) {
            // Modificamos la columna existente con todos los tipos válidos
            $table->enum('type', ExportProcess::getValidTypes())
                ->comment('Tipo de exportación')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Obtenemos los tipos válidos sin el TYPE_ORDER_LINES
        $previousValidTypes = array_filter(ExportProcess::getValidTypes(), function ($type) {
            return $type !== ExportProcess::TYPE_ORDER_LINES;
        });

        // Primero actualiza todos los registros que tienen el tipo que vas a eliminar
        DB::table('export_processes')
            ->where('type', ExportProcess::TYPE_ORDER_LINES)
            ->update(['type' => $previousValidTypes[0] ?? ExportProcess::TYPE_COMPANIES]);

        // Ahora modifica la columna
        Schema::table('export_processes', function (Blueprint $table) use ($previousValidTypes) {
            $table->enum('type', $previousValidTypes)
                ->comment('Tipo de exportación')
                ->change();
        });
    }
};

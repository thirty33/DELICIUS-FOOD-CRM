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

        Schema::table('export_processes', function (Blueprint $table) use ($previousValidTypes) {
            // Restauramos la columna a su estado anterior sin el nuevo tipo
            $table->enum('type', $previousValidTypes)
                ->comment('Tipo de exportación')
                ->change();
        });
    }
};

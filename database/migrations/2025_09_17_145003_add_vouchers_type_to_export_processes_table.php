<?php

use App\Models\ExportProcess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Agregar el nuevo tipo 'vouchers' al ENUM
        $validTypes = ExportProcess::getValidTypes();
        $enumValues = "'" . implode("','", $validTypes) . "'";

        DB::statement("ALTER TABLE export_processes MODIFY COLUMN type ENUM({$enumValues})");

        // Agregar el campo description
        Schema::table('export_processes', function (Blueprint $table) {
            $table->string('description')->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Eliminar el campo description
        Schema::table('export_processes', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        // Eliminar registros con tipo 'vouchers' antes de quitar el valor del ENUM
        DB::table('export_processes')
            ->where('type', 'vouchers')
            ->delete();

        $originalTypes = [
            'empresas',
            'sucursales',
            'categorias',
            'lineas de despacho',
            'productos',
            'lista de precios',
            'líneas de lista de precio',
            'menús',
            'categorías de menus',
            'usuarios',
            'líneas de pedidos',
            'consolidado de pedidos'
        ];

        $enumValues = "'" . implode("','", $originalTypes) . "'";
        DB::statement("ALTER TABLE export_processes MODIFY COLUMN type ENUM({$enumValues})");
    }
};

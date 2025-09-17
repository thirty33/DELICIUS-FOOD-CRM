<?php

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
            $table->text('file_url')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Eliminar registros con file_url más largo de 255 caracteres antes de cambiar el tipo de columna
        \DB::table('export_processes')
            ->whereRaw('LENGTH(file_url) > 255')
            ->delete();

        Schema::table('export_processes', function (Blueprint $table) {
            $table->string('file_url', 255)->change();
        });
    }
};

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
        Schema::table('companies', function (Blueprint $table) {

            // Agregamos la columna price_list_id como nullable
            $table->unsignedBigInteger('price_list_id')->nullable();

            // Agregamos la clave foránea, permitiendo valores nulos
            $table->foreign('price_list_id')
                ->references('id')
                ->on('price_lists')
                ->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['price_list_id']);
            $table->dropColumn('price_list_id');
        });
    }
};

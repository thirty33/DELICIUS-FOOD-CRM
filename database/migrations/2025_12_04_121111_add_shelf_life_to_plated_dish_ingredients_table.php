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
        Schema::table('plated_dish_ingredients', function (Blueprint $table) {
            $table->integer('shelf_life')->nullable()->after('max_quantity_horeca')->comment('Shelf life in days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plated_dish_ingredients', function (Blueprint $table) {
            $table->dropColumn('shelf_life');
        });
    }
};

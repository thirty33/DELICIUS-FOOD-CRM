<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add warning text fields for allergen/ingredient information display:
     * - show_soy_text: Display soy warning text on label
     * - show_chicken_text: Display chicken warning text on label
     */
    public function up(): void
    {
        Schema::table('nutritional_information', function (Blueprint $table) {
            $table->boolean('show_soy_text')->default(false)->after('high_sugar')->comment('Display soy warning text on nutritional label');
            $table->boolean('show_chicken_text')->default(false)->after('show_soy_text')->comment('Display chicken warning text on nutritional label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nutritional_information', function (Blueprint $table) {
            $table->dropColumn(['show_soy_text', 'show_chicken_text']);
        });
    }
};

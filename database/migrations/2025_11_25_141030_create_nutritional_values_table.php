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
        Schema::create('nutritional_values', function (Blueprint $table) {
            $table->id();

            // Foreign key to nutritional_information
            $table->foreignId('nutritional_information_id')
                ->constrained('nutritional_information')
                ->onDelete('cascade');

            // Type of nutritional value (calories, protein, fat, etc.)
            $table->string('type', 50); // Uses NutritionalValueType enum

            // Numeric value (supports decimals)
            $table->decimal('value', 10, 3)->default(0);

            $table->timestamps();

            // Unique constraint: one value per type per nutritional info
            $table->unique(['nutritional_information_id', 'type'], 'unique_nutritional_value');

            // Index for faster queries by type
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nutritional_values');
    }
};

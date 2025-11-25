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
        Schema::create('nutritional_information', function (Blueprint $table) {
            $table->id();

            // Foreign key to products (one-to-one relationship)
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('cascade');

            // Product identification
            $table->string('barcode', 50)->nullable();

            // Ingredients and allergens
            $table->text('ingredients')->nullable();
            $table->text('allergens')->nullable();

            // Weight information
            $table->string('measure_unit', 10)->default('GR'); // GR, KG, UND
            $table->decimal('net_weight', 10, 2)->default(0); // Peso neto
            $table->decimal('gross_weight', 10, 2)->default(0); // Peso bruto

            // Shelf life and label generation
            $table->integer('shelf_life_days')->default(0); // Vida útil en días
            $table->boolean('generate_label')->default(false); // Generar etiqueta

            $table->timestamps();

            // Unique constraint: one nutritional info per product
            $table->unique('product_id');

            // Index on barcode for quick lookups
            $table->index('barcode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nutritional_information');
    }
};

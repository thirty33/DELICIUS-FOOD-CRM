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
        Schema::create('plated_dish_ingredients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plated_dish_id');
            $table->string('ingredient_name');
            $table->string('measure_unit', 10);
            $table->decimal('quantity', 10, 3);
            $table->decimal('max_quantity_horeca', 10, 3)->nullable();
            $table->integer('order_index')->default(0);
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('plated_dish_id')
                  ->references('id')
                  ->on('plated_dishes')
                  ->onDelete('cascade');

            // Indexes for performance
            $table->index('plated_dish_id');
            $table->index(['plated_dish_id', 'order_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plated_dish_ingredients');
    }
};
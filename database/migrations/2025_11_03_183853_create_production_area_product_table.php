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
        Schema::create('production_area_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_area_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();

            $table->foreign('production_area_id')->references('id')->on('production_areas')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->unique(['production_area_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_area_product');
    }
};

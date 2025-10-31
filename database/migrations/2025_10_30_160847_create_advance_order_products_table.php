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
        Schema::create('advance_order_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advance_order_id')->constrained('advance_orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->integer('quantity')->default(0);
            $table->integer('ordered_quantity')->default(0);
            $table->integer('ordered_quantity_new')->default(0);
            $table->integer('total_to_produce')->default(0);
            $table->timestamps();

            $table->unique(['advance_order_id', 'product_id'], 'adv_order_product_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advance_order_products');
    }
};

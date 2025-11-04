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
        Schema::create('advance_order_order_lines', function (Blueprint $table) {
            $table->id();

            // Foreign keys
            $table->unsignedBigInteger('advance_order_id');
            $table->unsignedBigInteger('order_line_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('order_id'); // Para facilitar queries y mostrar de qué pedido viene

            // Core data
            $table->integer('quantity_covered'); // Cantidad cubierta por esta OP

            // Additional info for display purposes
            $table->string('product_name'); // Nombre del producto (para mostrar sin join)
            $table->string('product_code', 50)->nullable(); // Código del producto
            $table->date('order_dispatch_date'); // Fecha de despacho (denormalizado para filtros)
            $table->string('order_number')->nullable(); // Número de pedido para mostrar
            $table->integer('order_line_unit_price'); // Precio unitario del producto en esa línea
            $table->integer('order_line_total_price'); // Total de la línea (quantity_covered * unit_price)

            $table->timestamps();

            // Foreign key constraints
            $table->foreign('advance_order_id')
                ->references('id')
                ->on('advance_orders')
                ->onDelete('cascade');

            $table->foreign('order_line_id')
                ->references('id')
                ->on('order_lines')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('restrict');

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->onDelete('cascade');

            // Indexes
            $table->unique(['advance_order_id', 'order_line_id']);
            $table->index(['advance_order_id', 'product_id']);
            $table->index('order_line_id');
            $table->index('order_id');
            $table->index('order_dispatch_date');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advance_order_order_lines');
    }
};

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
        Schema::create('advance_order_orders', function (Blueprint $table) {
            $table->id();

            // Foreign keys
            $table->unsignedBigInteger('advance_order_id');
            $table->unsignedBigInteger('order_id');

            // Additional info for display purposes
            $table->string('order_number')->nullable(); // Número de pedido para mostrar
            $table->date('order_dispatch_date'); // Fecha de despacho del pedido
            $table->string('order_status', 50); // Estado del pedido al momento de asociación
            $table->unsignedBigInteger('order_user_id'); // Usuario que hizo el pedido
            $table->string('order_user_nickname')->nullable(); // Nickname del usuario (para mostrar)
            $table->integer('order_total'); // Total del pedido (para referencia)

            $table->timestamps();

            // Foreign key constraints
            $table->foreign('advance_order_id')
                ->references('id')
                ->on('advance_orders')
                ->onDelete('cascade');

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->onDelete('cascade');

            $table->foreign('order_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->unique(['advance_order_id', 'order_id']);
            $table->index('advance_order_id');
            $table->index('order_id');
            $table->index('order_dispatch_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advance_order_orders');
    }
};

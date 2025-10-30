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
        Schema::create('warehouse_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_transaction_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->integer('difference');
            $table->string('unit_of_measure');
            $table->timestamps();

            $table->index(['warehouse_transaction_id', 'product_id'], 'wh_trans_lines_wh_trans_product_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_transaction_lines');
    }
};

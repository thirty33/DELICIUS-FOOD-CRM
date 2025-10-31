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
        Schema::table('warehouse_transactions', function (Blueprint $table) {
            $table->foreignId('advance_order_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('advance_orders')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouse_transactions', function (Blueprint $table) {
            $table->dropForeign(['advance_order_id']);
            $table->dropColumn('advance_order_id');
        });
    }
};

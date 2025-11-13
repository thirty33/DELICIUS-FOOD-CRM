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
        Schema::create('import_order_line_tracking', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_process_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_line_id');
            $table->timestamps();

            // Indexes for fast lookups during import
            $table->index(['import_process_id', 'order_id']);
            $table->index('order_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_order_line_tracking');
    }
};

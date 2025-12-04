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
        Schema::create('import_record_tracking', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_process_id');
            $table->string('record_type', 100); // 'order_line', 'plated_dish_ingredient', etc.
            $table->unsignedBigInteger('parent_id')->nullable(); // order_id, plated_dish_id, etc.
            $table->unsignedBigInteger('record_id'); // order_line_id, ingredient_id, etc.
            $table->timestamps();

            // Indexes for fast lookups during import
            $table->index(['import_process_id', 'record_type'], 'idx_import_rec_proc_type');
            $table->index(['import_process_id', 'record_type', 'parent_id'], 'idx_import_rec_proc_type_parent');
            $table->index(['import_process_id', 'record_id'], 'idx_import_rec_proc_record');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_record_tracking');
    }
};

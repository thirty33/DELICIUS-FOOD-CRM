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
        Schema::create('advance_orders', function (Blueprint $table) {
            $table->id();
            $table->date('initial_dispatch_date');
            $table->date('final_dispatch_date');
            $table->dateTime('preparation_datetime');
            $table->text('description')->nullable();
            $table->string('status', 50);
            $table->boolean('use_products_in_orders')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advance_orders');
    }
};

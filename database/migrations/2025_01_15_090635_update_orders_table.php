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
        Schema::table('orders', function (Blueprint $table) {

            $table->enum('status', [
                \App\Enums\OrderStatus::PENDING->value,
                \App\Enums\OrderStatus::PARTIALLY_SCHEDULED->value,
                \App\Enums\OrderStatus::PROCESSED->value,
                \App\Enums\OrderStatus::CANCELED->value,
            ])->default(\App\Enums\OrderStatus::PENDING->value)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'completed', 'declined'])->default('pending')->change();
        });
    }
};
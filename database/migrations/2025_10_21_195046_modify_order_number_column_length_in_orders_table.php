<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Increase order_number column length from varchar(14) to varchar(50)
     * to support alphanumeric order codes from external systems like "PED20251020679630"
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_number', 50)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Revert order_number column back to varchar(14)
     * WARNING: This may truncate data if there are order numbers longer than 14 characters
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_number', 14)->nullable()->change();
        });
    }
};

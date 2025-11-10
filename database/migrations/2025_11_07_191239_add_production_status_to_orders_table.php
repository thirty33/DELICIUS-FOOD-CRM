<?php

use App\Enums\OrderProductionStatus;
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
            $table->enum('production_status', [
                OrderProductionStatus::NOT_PRODUCED->value,
                OrderProductionStatus::PARTIALLY_PRODUCED->value,
                OrderProductionStatus::FULLY_PRODUCED->value,
            ])
                ->default(OrderProductionStatus::NOT_PRODUCED->value)
                ->after('status')
                ->comment('Estado de producción de la orden');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('production_status');
        });
    }
};

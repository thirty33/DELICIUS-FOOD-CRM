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
            $table->boolean('production_status_needs_update')
                ->default(true)
                ->after('production_status')
                ->comment('Flag indicating if production status needs recalculation');

            $table->index('production_status_needs_update');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['production_status_needs_update']);
            $table->dropColumn('production_status_needs_update');
        });
    }
};

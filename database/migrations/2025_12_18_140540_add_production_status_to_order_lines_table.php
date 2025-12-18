<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds production_status field to track individual order line production coverage.
     * Values: completamente_producido, parcialmente_producido, no_producido, or null
     */
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->string('production_status', 30)->nullable()->after('partially_scheduled');
            $table->index('production_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropIndex(['production_status']);
            $table->dropColumn('production_status');
        });
    }
};

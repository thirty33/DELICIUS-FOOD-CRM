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
        Schema::table('categories', function (Blueprint $table) {
            $table->integer('preparation_days')->default(0);
            $table->integer('preparation_hours')->default(0);
            $table->integer('preparation_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->time('order_start_time')->nullable();
            $table->time('order_end_time')->nullable();
            $table->boolean('is_active_monday')->default(false);
            $table->boolean('is_active_tuesday')->default(false);
            $table->boolean('is_active_wednesday')->default(false);
            $table->boolean('is_active_thursday')->default(false);
            $table->boolean('is_active_friday')->default(false);
            $table->boolean('is_active_saturday')->default(false);
            $table->boolean('is_active_sunday')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn([
                'preparation_days',
                'preparation_hours',
                'preparation_minutes',
                'is_active',
                'order_start_time',
                'order_end_time',
                'is_active_monday',
                'is_active_tuesday',
                'is_active_wednesday',
                'is_active_thursday',
                'is_active_friday',
                'is_active_saturday',
                'is_active_sunday'
            ]);
        });
    }
};

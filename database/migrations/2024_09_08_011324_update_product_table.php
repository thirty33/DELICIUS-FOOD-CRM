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
        Schema::table('products', function (Blueprint $table) {
            $table->string('code')->nullable()->unique();
            $table->boolean('active')->default(false);
            $table->string('measure_unit')->nullable();
            $table->decimal('price_list', 8, 2)->nullable();
            $table->integer('stock')->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->boolean('allow_sales_without_stock')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn([
                'code',
                'active',
                'measure_unit',
                'price_list',
                'stock',
                'weight',
                'allow_sales_without_stock',
            ]);
        });
    }
};

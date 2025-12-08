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
        Schema::table('plated_dishes', function (Blueprint $table) {
            $table->unsignedBigInteger('related_product_id')->nullable()->after('product_id');

            $table->foreign('related_product_id')
                  ->references('id')
                  ->on('products')
                  ->onDelete('set null');

            $table->index('related_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plated_dishes', function (Blueprint $table) {
            $table->dropForeign(['related_product_id']);
            $table->dropIndex(['related_product_id']);
            $table->dropColumn('related_product_id');
        });
    }
};

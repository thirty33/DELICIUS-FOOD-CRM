<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration creates the order_rule_subcategory_limits table to store
     * maximum product limits per subcategory for order rules.
     *
     * Example: "Maximum 2 ENTRADA products per order"
     */
    public function up(): void
    {
        Schema::create('order_rule_subcategory_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_rule_id');
            $table->unsignedBigInteger('subcategory_id');
            $table->integer('max_products')->default(1)->comment('Maximum number of products allowed for this subcategory');
            $table->timestamps();

            // Foreign keys
            $table->foreign('order_rule_id', 'fk_order_rule_subcat_limit_rule')
                ->references('id')->on('order_rules')->onDelete('cascade');

            $table->foreign('subcategory_id', 'fk_order_rule_subcat_limit_subcat')
                ->references('id')->on('subcategories')->onDelete('cascade');

            // Constraints - One limit per subcategory per rule
            $table->unique(['order_rule_id', 'subcategory_id'], 'unique_subcategory_limit');

            // Performance index
            $table->index('order_rule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_rule_subcategory_limits');
    }
};

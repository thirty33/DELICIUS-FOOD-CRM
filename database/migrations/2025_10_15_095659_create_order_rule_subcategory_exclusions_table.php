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
        Schema::create('order_rule_subcategory_exclusions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_rule_id');
            $table->unsignedBigInteger('subcategory_id');
            $table->unsignedBigInteger('excluded_subcategory_id');
            $table->timestamps();

            $table->foreign('order_rule_id', 'fk_order_rule_subcat_excl_rule')
                ->references('id')->on('order_rules')->onDelete('cascade');
            $table->foreign('subcategory_id', 'fk_order_rule_subcat_excl_subcat')
                ->references('id')->on('subcategories')->onDelete('cascade');
            $table->foreign('excluded_subcategory_id', 'fk_order_rule_subcat_excl_excluded')
                ->references('id')->on('subcategories')->onDelete('cascade');

            $table->unique(['order_rule_id', 'subcategory_id', 'excluded_subcategory_id'], 'unique_exclusion_rule');
            $table->index('order_rule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_rule_subcategory_exclusions');
    }
};

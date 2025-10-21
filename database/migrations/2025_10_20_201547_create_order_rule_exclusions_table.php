<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create order_rule_exclusions table with polymorphic relationships.
 *
 * This table replaces order_rule_subcategory_exclusions with a more flexible design
 * that supports exclusions between:
 * - Subcategory → Subcategory
 * - Subcategory → Category
 * - Category → Subcategory
 * - Category → Category
 *
 * Uses Laravel's polymorphic relationships with morphTo():
 * - source_id + source_type → Category or Subcategory
 * - excluded_id + excluded_type → Category or Subcategory
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_rule_exclusions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_rule_id');

            // Polymorphic relation for SOURCE (the element with the restriction)
            $table->morphs('source'); // Creates: source_id, source_type

            // Polymorphic relation for EXCLUDED (the element that cannot be combined)
            $table->morphs('excluded'); // Creates: excluded_id, excluded_type

            $table->timestamps();

            // Foreign key to order_rules
            $table->foreign('order_rule_id')
                ->references('id')
                ->on('order_rules')
                ->onDelete('cascade');

            // Unique constraint: prevent duplicate exclusion rules
            $table->unique(
                ['order_rule_id', 'source_type', 'source_id', 'excluded_type', 'excluded_id'],
                'unique_exclusion_rule'
            );

            // Indexes for performance
            $table->index('order_rule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_rule_exclusions');
    }
};

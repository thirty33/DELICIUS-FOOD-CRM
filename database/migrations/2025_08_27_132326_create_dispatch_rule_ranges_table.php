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
        Schema::create('dispatch_rule_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_rule_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('min_amount');
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->unsignedBigInteger('dispatch_cost')->default(0);
            $table->timestamps();
            
            $table->index(['min_amount', 'max_amount']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispatch_rule_ranges');
    }
};

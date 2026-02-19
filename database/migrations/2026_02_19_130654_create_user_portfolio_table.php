<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_portfolio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('portfolio_id')->constrained('seller_portfolios')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->datetime('assigned_at');
            $table->datetime('branch_created_at')->nullable();
            $table->datetime('first_order_at')->nullable();
            $table->date('month_closed_at')->nullable();
            $table->foreignId('previous_portfolio_id')->nullable()->constrained('seller_portfolios')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index('portfolio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_portfolio');
    }
};

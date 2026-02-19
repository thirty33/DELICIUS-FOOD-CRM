<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_portfolios', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('successor_portfolio_id')->nullable()->constrained('seller_portfolios')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['seller_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_portfolios');
    }
};

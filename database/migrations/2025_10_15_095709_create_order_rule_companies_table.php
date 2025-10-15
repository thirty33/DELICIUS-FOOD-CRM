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
        Schema::create('order_rule_companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_rule_id');
            $table->unsignedBigInteger('company_id');
            $table->timestamps();

            $table->foreign('order_rule_id')->references('id')->on('order_rules')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');

            $table->unique(['order_rule_id', 'company_id']);
            $table->index('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_rule_companies');
    }
};

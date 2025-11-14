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
        Schema::create('company_report_grouper', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('report_grouper_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['company_id', 'report_grouper_id'], 'company_grouper_unique');
            $table->index('report_grouper_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_report_grouper');
    }
};

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
        Schema::create('report_groupers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_configuration_id')->constrained()->onDelete('cascade')->comment('Configuration this grouper belongs to');
            $table->string('name')->comment('Grouper name (e.g., "CAFETERIA ALMA TERRA")');
            $table->string('code')->comment('Grouper code');
            $table->integer('display_order')->default(0)->comment('Order for display in reports');
            $table->boolean('is_active')->default(true)->comment('Whether this grouper is active');
            $table->timestamps();

            $table->unique(['report_configuration_id', 'code'], 'config_code_unique');
            $table->index('is_active');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_groupers');
    }
};

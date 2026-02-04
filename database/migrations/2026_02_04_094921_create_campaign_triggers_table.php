<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('trigger_type', 50)->default('event');
            $table->string('event_type', 50);

            // Time offset for events
            $table->unsignedInteger('hours_before')->nullable(); // menu_closing, category_closing, no_order_placed
            $table->unsignedInteger('hours_after')->nullable();  // menu_created

            // Control fields
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_executed_at')->nullable();

            $table->timestamps();

            $table->index('event_type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_triggers');
    }
};
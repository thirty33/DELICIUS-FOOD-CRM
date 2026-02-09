<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_notified_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trigger_id')->constrained('campaign_triggers')->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->string('phone_number');
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->string('status', 50)->default('pending');
            $table->dateTime('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['trigger_id', 'menu_id', 'phone_number'], 'rnm_trigger_menu_phone_unique');
            $table->index(['trigger_id', 'phone_number', 'status'], 'rnm_trigger_phone_status_index');
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_notified_menus');
    }
};
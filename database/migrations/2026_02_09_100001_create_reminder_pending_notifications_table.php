<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_pending_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trigger_id')->constrained('campaign_triggers')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->string('phone_number');
            $table->text('message_content');
            $table->json('menu_ids');
            $table->string('status', 50)->default('waiting_response');
            $table->timestamps();

            $table->index(['conversation_id', 'status'], 'rpn_conversation_status_index');
            $table->index(['phone_number', 'status'], 'rpn_phone_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_pending_notifications');
    }
};
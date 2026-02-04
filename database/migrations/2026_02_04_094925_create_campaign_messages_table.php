<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained('campaign_executions')->cascadeOnDelete();
            $table->string('recipient_type', 100);
            $table->unsignedBigInteger('recipient_id');
            $table->string('recipient_address');
            $table->string('status', 50)->default('pending');
            $table->string('external_id')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['execution_id', 'status']);
            $table->index(['recipient_type', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_messages');
    }
};
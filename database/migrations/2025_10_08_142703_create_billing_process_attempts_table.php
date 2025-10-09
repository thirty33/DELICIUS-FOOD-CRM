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
        Schema::create('billing_process_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_process_id')->constrained('billing_processes')->onDelete('cascade');
            $table->text('request_body');
            $table->text('response_body')->nullable();
            $table->integer('response_status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_process_attempts');
    }
};

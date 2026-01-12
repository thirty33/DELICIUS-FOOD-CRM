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
        Schema::create('web_registration_requests', function (Blueprint $table) {
            $table->id();

            // Company legal name (optional)
            $table->string('razon_social', 255)->nullable();

            // Chilean tax ID (optional, format: XX.XXX.XXX-X)
            $table->string('rut', 12)->nullable();

            // Trade/brand name (optional)
            $table->string('nombre_fantasia', 255)->nullable();

            // Client type (optional)
            $table->string('tipo_cliente', 50)->nullable();

            // Business activity (optional)
            $table->string('giro', 255)->nullable();

            // Address (optional)
            $table->string('direccion', 500)->nullable();

            // Phone number (required if email is not provided)
            $table->string('telefono', 20)->nullable();

            // Email (required if phone is not provided)
            $table->string('email', 255)->nullable();

            // Message/comments (optional)
            $table->text('mensaje')->nullable();

            // Request status for tracking
            $table->enum('status', ['pending', 'contacted', 'approved', 'rejected'])->default('pending');

            // Notes from admin (optional)
            $table->text('admin_notes')->nullable();

            // Soft deletes for record keeping
            $table->softDeletes();

            $table->timestamps();

            // Indexes for common queries
            $table->index('rut');
            $table->index('email');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_registration_requests');
    }
};

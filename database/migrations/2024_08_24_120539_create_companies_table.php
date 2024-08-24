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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Nombre de la empresa
            $table->string('address')->nullable(); // Dirección de la empresa
            $table->string('email')->unique(); // Email de la empresa
            $table->string('phone_number')->nullable(); // Número de teléfono de la empresa
            $table->string('website')->nullable(); // Sitio web de la empresaa
            $table->string('registration_number')->nullable(); // Número de registro de la empresa
            $table->text('description')->nullable(); // Descripción de la empresa
            $table->string('logo')->nullable(); // Logo de la empresa
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};

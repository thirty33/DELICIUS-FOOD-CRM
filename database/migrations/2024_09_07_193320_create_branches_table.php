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
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('address')->nullable(); // Dirección de la empresa
            $table->string('shipping_address')->nullable(); // Dirección de Despacho
            $table->string('contact_name')->nullable(); // Nombre contacto
            $table->string('contact_last_name')->nullable(); // Apellido contacto
            $table->string('contact_phone_number')->nullable(); // numero de contacto
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};

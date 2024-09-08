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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('tax_id')->nullable()->unique(); // RUT
            $table->string('business_activity')->nullable(); // Giro
            $table->string('acronym')->nullable(); // Sigla
            $table->string('shipping_address')->nullable(); // Dirección de Despacho
            $table->string('district')->nullable(); // Distrito/Comuna
            $table->string('state_region')->nullable(); // Estado/Región
            $table->string('postal_box')->nullable(); // Casilla Postal
            $table->string('city')->nullable(); // Ciudad
            $table->string('country')->nullable(); // País
            $table->string('zip_code')->nullable(); // Código ZIP
            $table->string('fax')->nullable(); // Fax
            $table->string('company_name')->nullable()->unique(); // Razón social
            $table->string('contact_name')->nullable(); // Nombre contacto
            $table->string('contact_last_name')->nullable(); // Apellido contacto
            $table->string('contact_phone_number')->nullable(); //
            $table->string('fantasy_name'); // Nombre de la empresa
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {

            $table->dropUnique(['tax_id']); // Quitar la restricción única de RUT
            $table->dropUnique(['company_name']); // Quitar la restricción única de Razón social

            $table->dropColumn([
                'tax_id', // RUT
                'business_activity', // Giro
                'acronym', // Sigla
                'shipping_address', // Dirección de Despacho
                'district', // Distrito/Comuna
                'state_region', // Estado/Región
                'postal_box', // Casilla Postal
                'city', // Ciudad
                'country', // País
                'zip_code', // Código ZIP
                'fax', // Fax
                'company_name', // Razón social
                'contact_name', // Nombre contacto
                'contact_last_name', // Apellido contacto
                'contact_phone_number', // numero de contacto
                'fantasy_name' // nombre de fantasía
            ]);

        });
    }
};

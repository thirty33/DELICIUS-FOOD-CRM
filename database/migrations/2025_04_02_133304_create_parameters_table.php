<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('parameters', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('Parameter name');
            $table->text('description')->nullable()->comment('Parameter description');
            $table->string('value_type')->comment('Value type: text, numeric, boolean, json, etc.');
            $table->text('value')->nullable()->comment('Parameter value stored as text');
            $table->boolean('active')->default(true)->comment('Indicates if the parameter is active');
            $table->timestamps();
        });

        // Insert the initial parameter "tax value"
        DB::table('parameters')->insert([
            'name' => 'Valor de Impuesto',
            'description' => 'Porcentaje de impuesto aplicado a las ventas',
            'value_type' => 'numeric',
            'value' => '0.19', // Example: 19%
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parameters');
    }
};

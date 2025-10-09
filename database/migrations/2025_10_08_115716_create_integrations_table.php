<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->enum('name', ['defontana', 'facturacion_cl']);
            $table->string('url');
            $table->string('url_test');
            $table->enum('type', ['billing', 'payment_gateway']);
            $table->boolean('production')->default(false);
            $table->boolean('active')->default(false);
            $table->text('temporary_token')->nullable();
            $table->timestamp('token_expiration_time')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Only one active integration per type
            // Including deleted_at in unique constraint allows reusing same type+active after soft delete
            // MySQL treats NULL values as distinct, so multiple soft deleted records can coexist
            $table->unique(['type', 'active', 'deleted_at'], 'unique_active_per_type');
        });

        // Insert Defontana integration
        DB::table('integrations')->insert([
            'name' => 'defontana',
            'url' => 'https://api.defontana.com',
            'url_test' => 'https://replapi.defontana.com',
            'type' => 'billing',
            'production' => false,
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
        Schema::dropIfExists('integrations');
    }
};

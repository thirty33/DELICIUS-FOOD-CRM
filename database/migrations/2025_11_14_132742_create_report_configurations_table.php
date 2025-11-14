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
        Schema::create('report_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('Configuration name identifier');
            $table->string('description')->nullable()->comment('Human-readable description');
            $table->boolean('use_groupers')->default(false)->comment('Use groupers instead of exclude_from_consolidated_report');
            $table->boolean('exclude_cafeterias')->default(false)->comment('Exclude cafeterias from report');
            $table->boolean('exclude_agreements')->default(false)->comment('Exclude agreements from report');
            $table->boolean('is_active')->default(false)->comment('Whether this configuration is currently active');
            $table->timestamps();

            $table->index('is_active');
        });

        // Create default configuration
        DB::table('report_configurations')->insert([
            'name' => 'reporte_consolidado',
            'description' => 'Configuración por defecto para reportes consolidados con agrupadores',
            'use_groupers' => true,
            'exclude_cafeterias' => true,
            'exclude_agreements' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_configurations');
    }
};

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
        Schema::create('dispatch_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('priority');
            $table->boolean('active')->default(false);
            $table->boolean('all_companies')->default(false);
            $table->boolean('all_branches')->default(false);
            $table->timestamps();
            
            $table->index('priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispatch_rules');
    }
};

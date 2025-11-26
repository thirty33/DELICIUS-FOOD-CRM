<?php

use App\Models\ImportProcess;
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
        Schema::table('import_processes', function (Blueprint $table) {
            $table->string('type')->change()->comment('Tipo de importación');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_processes', function (Blueprint $table) {
            $table->enum('type', ImportProcess::getValidTypes())->change()->comment('Tipo de importación');
        });
    }
};
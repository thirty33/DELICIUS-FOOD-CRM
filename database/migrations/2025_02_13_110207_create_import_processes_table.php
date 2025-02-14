<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\ImportProcess;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_processes', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ImportProcess::getValidTypes())
                ->comment('Tipo de importación');
            $table->enum('status', ImportProcess::getValidStatuses())
                ->default(ImportProcess::STATUS_QUEUED)
                ->comment('Estado del proceso de importación');
            $table->json('error_log')->nullable()
                ->comment('Log de errores durante la importación');
            $table->string('file_url')
                ->comment('URL del archivo original en S3');
            $table->string('file_error_url')->nullable()
                ->comment('URL del archivo de errores en S3');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_processes');
    }
};
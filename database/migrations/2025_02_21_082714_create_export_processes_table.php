<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\ExportProcess;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('export_processes', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ExportProcess::getValidTypes())
                ->comment('Tipo de exportación');
            $table->enum('status', ExportProcess::getValidStatuses())
                ->default(ExportProcess::STATUS_QUEUED)
                ->comment('Estado del proceso de exportación');
            $table->json('error_log')->nullable()
                ->comment('Log de errores durante la exportación');
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
        Schema::dropIfExists('export_processes');
    }
};

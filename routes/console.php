<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

Artisan::command('logs:clean', function () {
    $logPath = storage_path('logs');
    $mainLog = $logPath . '/laravel.log';
    
    // Contador de archivos vaciados
    $count = 0;
    
    // Verificar y vaciar específicamente laravel.log
    if (File::exists($mainLog)) {
        try {
            // Vaciar el archivo laravel.log
            file_put_contents($mainLog, '');
            $this->info("Se ha vaciado el archivo laravel.log");
            $count++;
        } catch (\Exception $e) {
            $this->error("Error al vaciar laravel.log: " . $e->getMessage());
            Log::error("Error al vaciar laravel.log: " . $e->getMessage());
        }
    } else {
        $this->info("El archivo laravel.log no existe.");
    }
    
    $this->info("Total: Se han vaciado {$count} archivos de log.");
    Log::info("Comando logs:clean ejecutado: {$count} archivos vaciados");
})->purpose('Vaciar archivos de log')
  ->daily()
  ->withoutOverlapping();

Artisan::command('queue:process', function () {
    $this->info('Iniciando procesamiento de cola...');
    $exitCode = $this->call('queue:work', [
        '--stop-when-empty' => true
    ]);
    $this->info('Procesamiento de cola completado con código: ' . $exitCode);
})->purpose('Procesar todos los trabajos en cola hasta vaciarla')
    ->everyMinute()
    ->withoutOverlapping();

<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;

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
        '--stop-when-empty' => true,
        '--timeout' => 1800, // 30 minutes for large exports (CloseSheet can take 20+ min)
        '--memory' => 512,   // 512MB memory limit for large Excel exports
        '--tries' => 3,      // Retry failed jobs up to 3 times
    ]);
    $this->info('Procesamiento de cola completado con código: ' . $exitCode);
})->purpose('Procesar todos los trabajos en cola hasta vaciarla')
    ->everyMinute()
    ->withoutOverlapping();

// Schedule: Update orders production status every minute
// Schedule::command('orders:update-production-status')
//     ->everyMinute()
//     ->withoutOverlapping();

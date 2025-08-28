<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CheckS3File extends Command
{
    protected $signature = 's3:check {path}';
    protected $description = 'Check if a file exists in S3';

    public function handle()
    {
        $path = $this->argument('path');
        
        try {
            $exists = Storage::disk('s3')->exists($path);
            
            if ($exists) {
                $this->info("✅ File EXISTS in S3: {$path}");
                
                // Get file size
                $size = Storage::disk('s3')->size($path);
                $this->info("📏 Size: " . number_format($size) . " bytes");
                
                // Get URL
                $url = Storage::disk('s3')->url($path);
                $this->info("🔗 S3 URL: {$url}");
                
                // Get temporary URL (1 hour)
                $tempUrl = Storage::disk('s3')->temporaryUrl($path, now()->addHour());
                $this->info("🔐 Temporary URL (1 hour): {$tempUrl}");
                
            } else {
                $this->error("❌ File NOT FOUND in S3: {$path}");
            }
            
            // List files in the directory
            $dir = dirname($path);
            $files = Storage::disk('s3')->files($dir);
            
            if (count($files) > 0) {
                $this->info("\n📁 Files in directory '{$dir}':");
                foreach ($files as $file) {
                    $this->line("  - {$file}");
                }
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}
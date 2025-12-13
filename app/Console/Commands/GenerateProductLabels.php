<?php

namespace App\Console\Commands;

use App\Contracts\Labels\LabelGeneratorInterface;
use App\Contracts\NutritionalLabelDataPreparerInterface;
use App\Facades\ImageSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateProductLabels extends Command
{
    protected $signature = 'labels:generate {product_ids?*} {--copies=1} {--elaboration-date=}';

    protected $description = 'Generate nutritional labels for products (sync, no queue)';

    public function __construct(
        protected NutritionalLabelDataPreparerInterface $dataPreparer,
        protected LabelGeneratorInterface $labelGenerator
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $productIds = $this->argument('product_ids');
        $copies = (int) $this->option('copies');
        $elaborationDate = $this->option('elaboration-date') ?: now()->format('d/m/Y');

        if (empty($productIds)) {
            $this->error('No product IDs provided.');
            return Command::FAILURE;
        }

        // Build quantities array (same structure as service)
        $quantities = [];
        foreach ($productIds as $productId) {
            $quantities[$productId] = $copies;
        }

        try {
            // Use the same helper as the service
            $preparedData = $this->dataPreparer->prepareData($productIds, $quantities);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        // Show not found IDs
        if (!empty($preparedData['not_found_ids'])) {
            $this->warn('Products not found or without nutritional info: ' . implode(', ', $preparedData['not_found_ids']));
        }

        $this->info("Total labels to generate: {$preparedData['total_labels']}");
        $this->info("Elaboration date: {$elaborationDate}");
        $this->info("Chunks: " . count($preparedData['chunks']));

        $generatedFiles = [];

        // Process each chunk (same as service, but sync instead of queued)
        foreach ($preparedData['chunks'] as $index => $chunk) {
            $this->info("\nProcessing chunk " . ($index + 1) . "/" . count($preparedData['chunks']) . ": {$chunk['area_name']}");

            // Get expanded products for this chunk (with label_index starting from start_index)
            $products = $this->dataPreparer->getExpandedProducts($chunk['product_ids'], $chunk['quantities'], $chunk['start_index']);

            // Generate PDF
            $this->labelGenerator->setElaborationDate($elaborationDate);
            $pdfContent = $this->labelGenerator->generate($products);

            // Build filename (same logic as job)
            $productCount = $products->count();
            $productIdsCollection = $products->pluck('id')->unique()->sort()->values();
            $firstProduct = $productIdsCollection->first();
            $lastProduct = $productIdsCollection->last();
            $timestamp = now()->format('Ymd_His');
            $dateStr = now()->format('Y/m/d');

            $fileNameParts = ['etiquetas_nutricionales'];

            // Add production area (sanitize for filename)
            $sanitizedArea = str_replace([' ', '/', '\\'], '_', $chunk['area_name']);
            $fileNameParts[] = $sanitizedArea;

            // Add product count and range
            if ($productCount === 1) {
                $fileNameParts[] = "producto_{$firstProduct}";
            } else {
                $uniqueCount = $productIdsCollection->count();
                $fileNameParts[] = "{$productCount}_etiquetas";
                $fileNameParts[] = "{$uniqueCount}_productos_{$firstProduct}_al_{$lastProduct}";
            }

            $fileNameParts[] = $timestamp . '_' . $index;
            $fileName = implode('_', $fileNameParts) . '.pdf';
            $s3Path = "pdfs/nutritional-labels/{$dateStr}/{$fileName}";

            // Upload to S3
            $uploadResult = Storage::disk('s3')->put($s3Path, $pdfContent, 'private');

            if (!$uploadResult) {
                $this->error("Error uploading chunk {$index} to S3");
                continue;
            }

            // Generate signed URL
            $signedUrlData = ImageSigner::getSignedUrl($s3Path, 1);
            $generatedFiles[] = [
                'area' => $chunk['area_name'],
                'labels' => $chunk['label_count'],
                'url' => $signedUrlData['signed_url']
            ];

            $this->info("  ✓ Generated {$chunk['label_count']} labels");
        }

        // Display results
        $this->newLine();
        $this->info('✅ Labels generated successfully!');
        $this->newLine();

        foreach ($generatedFiles as $file) {
            $this->line("📁 {$file['area']} ({$file['labels']} labels):");
            $this->line("   {$file['url']}");
            $this->newLine();
        }

        return Command::SUCCESS;
    }
}
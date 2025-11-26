<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Labels\Generators\NutritionalLabelGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateProductLabels extends Command
{
    protected $signature = 'labels:generate {product_ids?*} {--copies=1} {--elaboration-date=}';

    protected $description = 'Generate nutritional labels for products';

    public function handle(): int
    {
        $productIds = $this->argument('product_ids');
        $copies = (int) $this->option('copies');
        $elaborationDate = $this->option('elaboration-date') ?: now()->format('d/m/Y');

        if (empty($productIds)) {
            $this->info('No product IDs provided. Generating test label with hardcoded data...');
            $products = collect([new Product()]); // Empty product for test
        } else {
            $products = $this->validateProductIds($productIds);
            if ($products->isEmpty()) {
                return Command::FAILURE;
            }
        }

        $productsWithCopies = $this->generateLabelsWithCopies($products, $copies);

        $this->info("Generating {$productsWithCopies->count()} labels...");
        $this->info("Elaboration date: {$elaborationDate}");

        $generator = app(NutritionalLabelGenerator::class);
        $generator->setElaborationDate($elaborationDate);
        $pdfBinary = $generator->generate($productsWithCopies);

        $url = $this->saveToS3($pdfBinary);

        $this->displayResult($url);

        return Command::SUCCESS;
    }

    protected function validateProductIds(array $productIds): \Illuminate\Support\Collection
    {
        $products = Product::with('nutritionalInformation.nutritionalValues')
            ->whereIn('id', $productIds)
            ->get();

        $notFound = array_diff($productIds, $products->pluck('id')->toArray());

        if (!empty($notFound)) {
            $this->error('Products not found: ' . implode(', ', $notFound));
        }

        $withoutNutritionalInfo = $products->filter(function ($product) {
            return $product->nutritionalInformation === null;
        });

        if ($withoutNutritionalInfo->isNotEmpty()) {
            $this->warn('Products without nutritional information: ' .
                $withoutNutritionalInfo->pluck('id')->implode(', '));
        }

        return $products;
    }

    protected function generateLabelsWithCopies(\Illuminate\Support\Collection $products, int $copies): \Illuminate\Support\Collection
    {
        $result = collect();

        foreach ($products as $product) {
            for ($i = 0; $i < $copies; $i++) {
                $result->push($product);
            }
        }

        return $result;
    }

    protected function saveToS3(string $pdfBinary): string
    {
        $filename = 'labels/label_' . now()->format('YmdHis') . '_' . uniqid() . '.pdf';

        Storage::disk('s3')->put($filename, $pdfBinary);

        // Get S3 temporary signed URL (valid for 24 hours)
        return Storage::disk('s3')->temporaryUrl($filename, now()->addHours(24));
    }

    protected function displayResult(string $url): void
    {
        $this->newLine();
        $this->info('✅ Labels generated successfully!');
        $this->newLine();
        $this->line('Download URL:');
        $this->line($url);
        $this->newLine();
    }
}
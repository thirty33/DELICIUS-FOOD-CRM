<?php

namespace App\Console\Commands;

use App\Contracts\HorecaLabelDataRepositoryInterface;
use App\Models\Order;
use App\Services\Labels\Generators\HorecaLabelGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateHorecaLabels extends Command
{
    protected $signature = 'labels:horeca {order_ids?*} {--elaboration-date=}';

    protected $description = 'Generate HORECA ingredient labels for orders';

    public function __construct(
        protected HorecaLabelDataRepositoryInterface $horecaRepository
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $orderIds = $this->argument('order_ids');
        $elaborationDate = $this->option('elaboration-date') ?: now()->format('d/m/Y');

        if (empty($orderIds)) {
            $this->error('Please provide at least one order ID.');
            $this->info('Usage: php artisan labels:horeca {order_id} {order_id} ...');
            return Command::FAILURE;
        }

        $orders = $this->validateOrderIds($orderIds);
        if ($orders->isEmpty()) {
            return Command::FAILURE;
        }

        $this->info("Fetching HORECA label data for " . $orders->count() . " order(s)...");

        $labelData = $this->horecaRepository->getHorecaLabelDataByOrders($orderIds);

        if ($labelData->isEmpty()) {
            $this->warn('No HORECA ingredients found for these orders.');
            $this->info('Make sure the orders contain products with plated dishes that have ingredients.');
            return Command::FAILURE;
        }

        $this->displayLabelSummary($labelData);

        $labelsCollection = $this->expandLabelsWithWeights($labelData);

        $this->info("Generating {$labelsCollection->count()} HORECA label(s)...");
        $this->info("Elaboration date: {$elaborationDate}");

        $generator = app(HorecaLabelGenerator::class);
        $generator->setElaborationDate($elaborationDate);
        $pdfBinary = $generator->generate($labelsCollection);

        $url = $this->saveToS3($pdfBinary);

        $this->displayResult($url);

        return Command::SUCCESS;
    }

    protected function validateOrderIds(array $orderIds): \Illuminate\Support\Collection
    {
        $orders = Order::with([
            'orderLines.product.platedDish.ingredients',
            'user.branch'
        ])
            ->whereIn('id', $orderIds)
            ->get();

        $notFound = array_diff($orderIds, $orders->pluck('id')->toArray());

        if (!empty($notFound)) {
            $this->error('Orders not found: ' . implode(', ', $notFound));
        }

        $withoutPlatedDish = $orders->filter(function ($order) {
            return $order->orderLines->filter(function ($line) {
                return $line->product && $line->product->platedDish;
            })->isEmpty();
        });

        if ($withoutPlatedDish->isNotEmpty()) {
            $this->warn('Orders without plated dish products: ' .
                $withoutPlatedDish->pluck('id')->implode(', '));
        }

        return $orders;
    }

    protected function displayLabelSummary(\Illuminate\Support\Collection $labelData): void
    {
        $this->newLine();
        $this->info('=== HORECA LABEL SUMMARY ===');
        $this->newLine();

        $totalLabels = $labelData->sum('labels_count');

        $this->table(
            ['Branch', 'Ingredient', 'Total Needed', 'Max per Label', 'Labels Count', 'Weights'],
            $labelData->map(function ($item) {
                return [
                    $item['branch_fantasy_name'],
                    $item['ingredient_name'],
                    number_format($item['total_quantity_needed'], 2) . ' ' . $item['measure_unit'],
                    number_format($item['max_quantity_horeca'], 2) . ' ' . $item['measure_unit'],
                    $item['labels_count'],
                    collect($item['weights'])->map(fn($w) => number_format($w, 2))->implode(', ') . ' ' . $item['measure_unit'],
                ];
            })->toArray()
        );

        $this->info("Total labels to generate: {$totalLabels}");
        $this->newLine();
    }

    protected function expandLabelsWithWeights(\Illuminate\Support\Collection $labelData): \Illuminate\Support\Collection
    {
        $expanded = collect();

        foreach ($labelData as $item) {
            foreach ($item['weights'] as $weight) {
                $expanded->push([
                    'ingredient_name' => $item['ingredient_name'],
                    'ingredient_product_code' => $item['ingredient_product_code'],
                    'branch_fantasy_name' => $item['branch_fantasy_name'],
                    'measure_unit' => $item['measure_unit'],
                    'net_weight' => $weight,
                ]);
            }
        }

        return $expanded;
    }

    protected function saveToS3(string $pdfBinary): string
    {
        $filename = 'labels/horeca_labels_' . now()->format('YmdHis') . '_' . uniqid() . '.pdf';

        Storage::disk('s3')->put($filename, $pdfBinary);

        // Get S3 temporary signed URL (valid for 24 hours)
        return Storage::disk('s3')->temporaryUrl($filename, now()->addHours(24));
    }

    protected function displayResult(string $url): void
    {
        $this->newLine();
        $this->info('✅ HORECA labels generated successfully!');
        $this->newLine();
        $this->line('Download URL:');
        $this->line($url);
        $this->newLine();
    }
}
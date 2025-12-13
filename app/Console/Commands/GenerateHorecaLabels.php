<?php

namespace App\Console\Commands;

use App\Contracts\HorecaLabelDataRepositoryInterface;
use App\Models\AdvanceOrder;
use App\Models\Order;
use App\Services\Labels\Generators\HorecaLabelGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateHorecaLabels extends Command
{
    protected $signature = 'labels:horeca
                            {order_ids?* : Order IDs (legacy mode)}
                            {--advance-order= : AdvanceOrder ID (recommended - uses groupers and discriminates by product)}
                            {--elaboration-date= : Elaboration date in d/m/Y format}';

    protected $description = 'Generate HORECA ingredient labels for orders or advance orders';

    public function __construct(
        protected HorecaLabelDataRepositoryInterface $horecaRepository
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $advanceOrderId = $this->option('advance-order');
        $orderIds = $this->argument('order_ids');
        $elaborationDate = $this->option('elaboration-date') ?: now()->format('d/m/Y');

        // Prefer AdvanceOrder mode (uses groupers and discriminates by product)
        if ($advanceOrderId) {
            return $this->handleAdvanceOrder($advanceOrderId, $elaborationDate);
        }

        // Legacy mode: by order IDs (does NOT discriminate by product)
        if (!empty($orderIds)) {
            return $this->handleOrderIds($orderIds, $elaborationDate);
        }

        $this->error('Please provide either --advance-order=ID or order IDs.');
        $this->info('Usage:');
        $this->info('  php artisan labels:horeca --advance-order=125');
        $this->info('  php artisan labels:horeca 123 124 125 (legacy mode)');
        return Command::FAILURE;
    }

    /**
     * Handle AdvanceOrder mode (recommended)
     * Uses groupers and discriminates weights by product
     */
    protected function handleAdvanceOrder(int $advanceOrderId, string $elaborationDate): int
    {
        $advanceOrder = AdvanceOrder::find($advanceOrderId);

        if (!$advanceOrder) {
            $this->error("AdvanceOrder #{$advanceOrderId} not found.");
            return Command::FAILURE;
        }

        $this->info("Fetching HORECA label data for AdvanceOrder #{$advanceOrderId}...");
        $this->info("Mode: Grouper-based + Product discrimination (recommended)");

        $labelData = $this->horecaRepository->getHorecaLabelDataByAdvanceOrder($advanceOrderId);

        if ($labelData->isEmpty()) {
            $this->warn('No HORECA ingredients found for this AdvanceOrder.');
            $this->info('Make sure the AdvanceOrder contains products with HORECA plated dishes.');
            return Command::FAILURE;
        }

        $this->displayLabelSummaryAdvanceOrder($labelData);

        $labelsCollection = $this->expandLabelsWithWeightsAdvanceOrder($labelData);

        $this->info("Generating {$labelsCollection->count()} HORECA label(s)...");
        $this->info("Elaboration date: {$elaborationDate}");

        $generator = app(HorecaLabelGenerator::class);
        $generator->setElaborationDate($elaborationDate);
        $pdfBinary = $generator->generate($labelsCollection);

        $url = $this->saveToS3($pdfBinary, $advanceOrderId);

        $this->displayResult($url);

        return Command::SUCCESS;
    }

    /**
     * Handle legacy order IDs mode
     * Does NOT use groupers, does NOT discriminate by product
     */
    protected function handleOrderIds(array $orderIds, string $elaborationDate): int
    {
        $this->warn('⚠️  Legacy mode: Using branch-based grouping, NOT discriminating by product.');
        $this->warn('    Consider using --advance-order=ID for better label generation.');
        $this->newLine();

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

    /**
     * Display summary for AdvanceOrder mode (includes product name)
     */
    protected function displayLabelSummaryAdvanceOrder(\Illuminate\Support\Collection $labelData): void
    {
        $this->newLine();
        $this->info('=== HORECA LABEL SUMMARY (AdvanceOrder Mode) ===');
        $this->newLine();

        $totalLabels = $labelData->sum('labels_count');

        $this->table(
            ['Grouper', 'Product', 'Ingredient', 'Total', 'Max/Label', 'Labels', 'Weights'],
            $labelData->map(function ($item) {
                $productName = $item['product_name'] ?? '-';
                // Truncate product name if too long
                if (strlen($productName) > 25) {
                    $productName = substr($productName, 0, 22) . '...';
                }
                return [
                    $item['grouper_name'] ?? $item['branch_fantasy_name'],
                    $productName,
                    $item['ingredient_name'],
                    number_format($item['total_quantity_needed'], 0) . ' ' . $item['measure_unit'],
                    number_format($item['max_quantity_horeca'], 0),
                    $item['labels_count'],
                    collect($item['weights'])->map(fn($w) => number_format($w, 0))->implode(', '),
                ];
            })->toArray()
        );

        $this->info("Total labels to generate: {$totalLabels}");
        $this->newLine();
    }

    /**
     * Display summary for legacy mode (no product name)
     */
    protected function displayLabelSummary(\Illuminate\Support\Collection $labelData): void
    {
        $this->newLine();
        $this->info('=== HORECA LABEL SUMMARY (Legacy Mode) ===');
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

    /**
     * Expand labels for AdvanceOrder mode (includes product info)
     */
    protected function expandLabelsWithWeightsAdvanceOrder(\Illuminate\Support\Collection $labelData): \Illuminate\Support\Collection
    {
        $expanded = collect();

        foreach ($labelData as $item) {
            foreach ($item['weights'] as $weight) {
                $expanded->push([
                    'ingredient_name' => $item['ingredient_name'],
                    'ingredient_product_code' => $item['ingredient_product_code'],
                    'grouper_name' => $item['grouper_name'] ?? $item['branch_fantasy_name'],
                    'branch_fantasy_name' => $item['branch_fantasy_name'],
                    'product_id' => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'] ?? null,
                    'measure_unit' => $item['measure_unit'],
                    'net_weight' => $weight,
                ]);
            }
        }

        return $expanded;
    }

    /**
     * Expand labels for legacy mode (no product info)
     */
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

    protected function saveToS3(string $pdfBinary, ?int $advanceOrderId = null): string
    {
        $suffix = $advanceOrderId ? "_OP{$advanceOrderId}" : '';
        $filename = 'labels/horeca_labels_' . now()->format('YmdHis') . $suffix . '_' . uniqid() . '.pdf';

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
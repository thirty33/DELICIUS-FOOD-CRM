<?php

namespace App\Console\Commands;

use App\Facades\ImageSigner;
use App\Models\Order;
use App\Services\Vouchers\VoucherPdfGenerator;
use App\Services\Vouchers\ConsolidatedVoucherGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateTestVouchers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vouchers:test {order_ids*} {--consolidated : Generate consolidated vouchers grouped by company and dispatch date}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate test vouchers PDF for given order IDs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $orderIds = $this->argument('order_ids');
        $isConsolidated = $this->option('consolidated');

        $voucherType = $isConsolidated ? 'consolidated vouchers' : 'individual vouchers';
        $this->info("Generating {$voucherType} for order IDs: " . implode(', ', $orderIds));

        // Load orders with relationships
        $orders = Order::with(['user.company', 'user.branch', 'user.roles', 'orderLines.product'])
            ->whereIn('id', $orderIds)
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            $this->error("No orders found for the given IDs");
            return 1;
        }

        $this->info("Found {$orders->count()} orders");

        // Generate PDF using appropriate generator
        if ($isConsolidated) {
            $generator = new ConsolidatedVoucherGenerator();
            $pdfContent = $generator->generate($orders);
        } else {
            $generator = new VoucherPdfGenerator();
            $pdfContent = $generator->generateMultiVoucherPdf($orders);
        }

        // Save to S3
        $timestamp = now()->format('Ymd_His');
        $dateStr = now()->format('Y/m/d');

        $firstOrderId = $orders->first()->id;
        $lastOrderId = $orders->last()->id;

        $prefix = $isConsolidated ? 'consolidated' : 'individual';

        if ($orders->count() === 1) {
            $fileName = "test_voucher_{$prefix}_{$firstOrderId}_{$timestamp}.pdf";
        } else {
            $fileName = "test_vouchers_{$prefix}_{$orders->count()}_pedidos_{$firstOrderId}_al_{$lastOrderId}_{$timestamp}.pdf";
        }

        $s3Path = "pdfs/vouchers/{$dateStr}/{$fileName}";

        Storage::disk('s3')->put($s3Path, $pdfContent, 'private');

        $signedUrlData = ImageSigner::getSignedUrl($s3Path, 1);

        $this->newLine();
        $this->info("✅ PDF generated successfully!");
        $this->info("📄 File: {$fileName}");
        $this->info("📊 Total orders: {$orders->count()}");
        $this->info("📦 Type: {$voucherType}");
        $this->newLine();
        $this->line("🔗 URL:");
        $this->line($signedUrlData['signed_url']);
        $this->newLine();

        return 0;
    }
}

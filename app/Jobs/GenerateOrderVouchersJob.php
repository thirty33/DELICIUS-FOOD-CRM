<?php

namespace App\Jobs;

use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Facades\ImageSigner;
use App\Models\ExportProcess;
use App\Models\Order;
use App\Services\Vouchers\VoucherPdfGenerator;
use App\Services\Vouchers\ConsolidatedVoucherGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateOrderVouchersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $orderIds;
    private $exportProcessId;
    private $consolidated;

    public function __construct(array $orderIds, bool $consolidated = false)
    {
        $this->orderIds = $orderIds;
        $this->consolidated = $consolidated;
    }

    public function handle()
    {
        $orders = Order::with([
            'user.company',
            'user.branch',
            'user.roles',
            'orderLines.product'
        ])
        ->whereIn('orders.id', $this->orderIds)
        ->join('users', 'orders.user_id', '=', 'users.id')
        ->join('companies', 'users.company_id', '=', 'companies.id')
        ->orderBy('companies.name')
        ->orderBy('orders.order_number')
        ->select('orders.*')
        ->get();

        if ($orders->isEmpty()) {
            throw new \Exception('No se encontraron órdenes con los IDs proporcionados');
        }

        $orderNumbers = $orders->pluck('order_number')->sort()->values();
        $firstOrder = $orderNumbers->first();
        $lastOrder = $orderNumbers->last();
        $totalOrders = $orders->count();

        $voucherType = $this->consolidated ? 'consolidados' : 'individuales';
        $description = $totalOrders === 1
            ? "Voucher del pedido #{$firstOrder}"
            : "Vouchers {$voucherType} de {$totalOrders} pedidos (#{$firstOrder} a #{$lastOrder})";

        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_VOUCHERS,
            'description' => $description,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-'
        ]);

        $this->exportProcessId = $exportProcess->id;

        $exportProcess->update(['status' => ExportProcess::STATUS_PROCESSING]);

        // Generate PDF using appropriate generator
        if ($this->consolidated) {
            $generator = new ConsolidatedVoucherGenerator();
            $pdfContent = $generator->generate($orders);
        } else {
            $generator = new VoucherPdfGenerator();
            $pdfContent = $generator->generateMultiVoucherPdf($orders);
        }

        $timestamp = now()->format('Ymd_His');
        $dateStr = now()->format('Y/m/d');

        $prefix = $this->consolidated ? 'consolidado' : 'individual';

        if ($totalOrders === 1) {
            $fileName = "voucher_{$prefix}_pedido_{$firstOrder}_{$timestamp}.pdf";
        } else {
            $fileName = "vouchers_{$prefix}_{$totalOrders}_pedidos_{$firstOrder}_al_{$lastOrder}_{$timestamp}.pdf";
        }

        $s3Path = "pdfs/vouchers/{$dateStr}/{$fileName}";


        $uploadResult = Storage::disk('s3')->put($s3Path, $pdfContent, 'private');

        if (!$uploadResult) {
            throw new \Exception('Error al subir PDF a S3');
        }

        $signedUrlData = ImageSigner::getSignedUrl($s3Path, 1);

        $exportProcess->update([
            'status' => ExportProcess::STATUS_PROCESSED,
            'file_url' => $signedUrlData['signed_url']
        ]);

    }

    public function failed(Throwable $e): void
    {
        if ($this->exportProcessId) {
            ExportErrorHandler::handle($e, $this->exportProcessId, 'job_failure');
        }

        Log::error('Job de generación de vouchers falló', [
            'export_process_id' => $this->exportProcessId ?? 0,
            'order_ids' => $this->orderIds,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
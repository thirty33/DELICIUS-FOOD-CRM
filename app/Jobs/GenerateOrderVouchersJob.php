<?php

namespace App\Jobs;

use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Facades\ImageSigner;
use App\Models\ExportProcess;
use App\Models\Order;
use App\Models\Parameter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
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

    public function __construct(array $orderIds)
    {
        $this->orderIds = $orderIds;
    }

    public function handle()
    {
        $exportProcess = null;

        try {
            // Obtener las órdenes ordenadas por compañía para generar la descripción
            $orders = Order::with([
                'user.company',
                'user.branch',
                'orderLines.product'
            ])
            ->whereIn('id', $this->orderIds)
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->join('companies', 'users.company_id', '=', 'companies.id')
            ->orderBy('companies.name')
            ->orderBy('orders.order_number')
            ->select('orders.*')
            ->get();

            if ($orders->isEmpty()) {
                throw new \Exception('No se encontraron órdenes con los IDs proporcionados');
            }

            // Generar descripción con el rango de pedidos
            $orderNumbers = $orders->pluck('order_number')->sort()->values();
            $firstOrder = $orderNumbers->first();
            $lastOrder = $orderNumbers->last();
            $totalOrders = $orders->count();

            $description = $totalOrders === 1
                ? "Voucher del pedido #{$firstOrder}"
                : "Vouchers de {$totalOrders} pedidos (#{$firstOrder} a #{$lastOrder})";

            $exportProcess = ExportProcess::create([
                'type' => ExportProcess::TYPE_VOUCHERS,
                'description' => $description,
                'status' => ExportProcess::STATUS_QUEUED,
                'file_url' => '-'
            ]);

            $this->exportProcessId = $exportProcess->id;

            $exportProcess->update(['status' => ExportProcess::STATUS_PROCESSING]);

            Log::info('Iniciando generación de vouchers', [
                'export_process_id' => $this->exportProcessId,
                'description' => $description,
                'order_ids' => $this->orderIds
            ]);

            Log::info("Órdenes encontradas: {$orders->count()}", [
                'export_process_id' => $this->exportProcessId
            ]);

            $html = $this->generateMultiVoucherHtml($orders);
            $pdf = Pdf::loadHTML($html);
            $pdf->setPaper([0, 0, 226.77, 800], 'portrait');
            $pdfContent = $pdf->output();

            // Generar nombre de archivo más descriptivo
            $timestamp = now()->format('Ymd_His');
            $dateStr = now()->format('Y/m/d');

            if ($totalOrders === 1) {
                $fileName = "voucher_pedido_{$firstOrder}_{$timestamp}.pdf";
            } else {
                $fileName = "vouchers_{$totalOrders}_pedidos_{$firstOrder}_al_{$lastOrder}_{$timestamp}.pdf";
            }

            $s3Path = "pdfs/vouchers/{$dateStr}/{$fileName}";

            Log::info('Subiendo PDF a S3', [
                'export_process_id' => $this->exportProcessId,
                's3_path' => $s3Path
            ]);

            $uploadResult = Storage::disk('s3')->put($s3Path, $pdfContent, 'private');

            if (!$uploadResult) {
                throw new \Exception('Error al subir PDF a S3');
            }

            $signedUrlData = ImageSigner::getSignedUrl($s3Path, 1);

            $exportProcess->update([
                'status' => ExportProcess::STATUS_PROCESSED,
                'file_url' => $signedUrlData['signed_url']
            ]);

            Log::info('Vouchers generados exitosamente', [
                'export_process_id' => $this->exportProcessId,
                'orders_count' => $orders->count(),
                's3_path' => $s3Path,
                'signed_url' => $signedUrlData['signed_url']
            ]);

        } catch (\Exception $e) {
            if ($exportProcess) {
                ExportErrorHandler::handle($e, $this->exportProcessId, 'voucher_generation');
            }

            Log::error('Error generando vouchers', [
                'export_process_id' => $this->exportProcessId ?? 0,
                'order_ids' => $this->orderIds,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
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

    private function generateMultiVoucherHtml($orders): string
    {
        $vouchersHtml = '';

        foreach ($orders as $order) {
            $vouchersHtml .= $this->generateSingleVoucherHtml($order);
        }

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Vouchers PDF</title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: Arial, sans-serif;
                    font-size: 9px;
                    margin: 1mm;
                    width: 78mm;
                    line-height: 1.2;
                }
                .voucher-container {
                    margin-bottom: 3mm;
                }
                .voucher-container:last-child {
                    margin-bottom: 0;
                }
                .header {
                    text-align: center;
                    margin-bottom: 4mm;
                }
                .header h1 {
                    font-size: 14px;
                    margin: 0;
                    font-weight: bold;
                }
                .info-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 4mm;
                    font-size: 9px;
                }
                .info-table td {
                    padding: 2px 3px;
                    border: 1px solid #000;
                    vertical-align: top;
                    word-wrap: break-word;
                }
                .info-table .label {
                    background-color: #f0f0f0;
                    font-weight: bold;
                    width: 30%;
                }
                .products-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 4mm;
                    font-size: 8px;
                }
                .products-table th {
                    padding: 3px 2px;
                    border: 1px solid #000;
                    background-color: #f0f0f0;
                    font-weight: bold;
                    text-align: center;
                    font-size: 8px;
                }
                .products-table .col-code { width: 15%; }
                .products-table .col-product { width: 50%; }
                .products-table .col-qty { width: 15%; }
                .products-table .col-subtotal { width: 20%; }
                .product-cell {
                    padding: 3px 2px;
                    border: 1px solid #000;
                    vertical-align: top;
                    font-size: 8px;
                    word-wrap: break-word;
                }
                .center { text-align: center; }
                .right { text-align: right; }
                .totals {
                    text-align: right;
                    margin-top: 4mm;
                    font-size: 10px;
                    font-weight: bold;
                }
                .totals div {
                    margin: 2px 0;
                }
                .comment {
                    margin-top: 4mm;
                    font-weight: bold;
                    font-size: 8px;
                    text-align: left;
                    border-top: 1px solid #000;
                    padding-top: 2mm;
                }
            </style>
        </head>
        <body>
            {$vouchersHtml}
        </body>
        </html>";

        return $html;
    }

    private function generateSingleVoucherHtml(Order $order): string
    {
        $taxValue = Parameter::getValue(Parameter::TAX_VALUE, 0);

        $orderDate = Carbon::parse($order->created_at)->format('d/m/Y');
        $dispatchDate = Carbon::parse($order->dispatch_date)->format('d/m/Y');

        $subtotal = $order->total;
        $dispatchCost = $order->dispatch_cost / 100;
        $subtotalWithDispatch = $subtotal + $dispatchCost;
        $taxAmount = $subtotalWithDispatch * $taxValue;
        $total = $subtotalWithDispatch + $taxAmount;

        $company = $order->user->company;
        $branch = $order->user->branch;

        $clientName = $company->name ?? 'N/A';
        $clientRut = $company->tax_id ?? 'N/A';
        $clientGiro = 'MINIMARKET';
        $address = $branch->shipping_address ?? $branch->address ?? 'N/A';

        $formattedNeto = number_format($subtotalWithDispatch, 0, ',', '.');
        $formattedIva = number_format($taxAmount, 0, ',', '.');
        $formattedTotal = number_format($total, 0, ',', '.');

        $productRowsHtml = '';
        foreach ($order->orderLines as $line) {
            $product = $line->product;
            $subtotalLine = $line->total_price / 100;
            $productCode = $product->code ?? 'N/A';
            $productName = $product->name ?? 'N/A';
            $formattedSubtotal = number_format($subtotalLine, 0, ',', '.');

            $productRowsHtml .= "
                <tr>
                    <td class='product-cell'>{$productCode}</td>
                    <td class='product-cell'>{$productName}</td>
                    <td class='product-cell center'>{$line->quantity} UN</td>
                    <td class='product-cell right'>$ {$formattedSubtotal}</td>
                </tr>";
        }

        $html = "
        <div class='voucher-container'>
            <div class='header'>
                <h1>Pedido N° {$order->order_number}</h1>
            </div>

            <table class='info-table'>
                <tr>
                    <td class='label'>Fecha pedido</td>
                    <td>{$orderDate}</td>
                </tr>
                <tr>
                    <td class='label'>Cliente</td>
                    <td>{$clientName}</td>
                </tr>
                <tr>
                    <td class='label'>RUT</td>
                    <td>{$clientRut}</td>
                </tr>
                <tr>
                    <td class='label'>Giro</td>
                    <td>{$clientGiro}</td>
                </tr>
                <tr>
                    <td class='label'>Despacho</td>
                    <td>{$address}</td>
                </tr>
                <tr>
                    <td class='label'>Fecha despacho</td>
                    <td>{$dispatchDate}</td>
                </tr>
                <tr>
                    <td class='label'>Vendedor</td>
                    <td>DELICIUS FOOD</td>
                </tr>
                <tr>
                    <td class='label'>Documento</td>
                    <td>Factura</td>
                </tr>
            </table>

            <table class='products-table'>
                <thead>
                    <tr>
                        <th class='col-code'>Cod.</th>
                        <th class='col-product'>Producto</th>
                        <th class='col-qty'>Cant.</th>
                        <th class='col-subtotal'>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    {$productRowsHtml}
                </tbody>
            </table>

            <div class='totals'>
                <div><strong>Neto: $ {$formattedNeto}</strong></div>
                <div><strong>IVA: $ {$formattedIva}</strong></div>
                <div><strong>TOTAL: $ {$formattedTotal}</strong></div>
            </div>

            " . ($order->user_comment ? "<div class='comment'>Comentario despacho: {$order->user_comment}</div>" : "") . "
        </div>";

        return $html;
    }
}
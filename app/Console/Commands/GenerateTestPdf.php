<?php

namespace App\Console\Commands;

use App\Facades\ImageSigner;
use App\Models\Order;
use App\Models\Parameter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class GenerateTestPdf extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pdf:test {--days=1 : Number of days for signed URL}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a voucher PDF from the first order, upload to S3, and create signed URL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $this->info('Starting voucher PDF generation...');

            // Step 1: Get first 5 orders with relationships
            $this->info('📊 Fetching first 5 orders from database...');
            
            $orders = Order::with([
                'user.company', 
                'user.branch', 
                'orderLines.product'
            ])->limit(5)->get();

            if ($orders->isEmpty()) {
                $this->error('❌ No orders found in database');
                return 1;
            }

            $this->info("✅ Found {$orders->count()} orders");

            // Step 2: Generate voucher HTML
            $this->info('📄 Generating voucher PDF...');
            
            $html = $this->generateMultiVoucherHtml($orders);
            $pdf = Pdf::loadHTML($html);
            $pdf->setPaper([0, 0, 226.77, 800], 'portrait'); // 80mm width (226.77 points) with flexible height
            $pdfContent = $pdf->output();

            // Step 3: Upload to S3
            $timestamp = now()->format('Y/m/d/His');
            $orderNumbers = $orders->pluck('order_number')->implode('-');
            $fileName = "vouchers-{$orderNumbers}-{$timestamp}.pdf";
            $s3Path = "pdfs/vouchers/{$fileName}";

            $this->info('☁️  Uploading PDF to S3...');
            $uploadResult = Storage::disk('s3')->put($s3Path, $pdfContent, 'private');

            if (!$uploadResult) {
                $this->error('Failed to upload PDF to S3');
                return 1;
            }

            $this->info("✅ PDF uploaded successfully to: {$s3Path}");

            // Step 4: Generate signed URL using ImageSigner
            $this->info('🔐 Generating signed URL...');
            
            $expiryDays = (int) $this->option('days');
            $signedUrlData = ImageSigner::getSignedUrl($s3Path, $expiryDays);

            // Display results
            $this->newLine();
            $this->info('🎉 Vouchers generated successfully!');
            $this->line('📊 Results:');
            $this->line("   🧾 Orders: {$orders->count()} vouchers");
            foreach ($orders as $order) {
                $clientDisplayName = $order->user->company->name ?? 'N/A';
                $this->line("     • #{$order->order_number} - {$clientDisplayName}");
            }
            $this->line("   📁 S3 Path: {$s3Path}");
            $this->line("   🔗 Signed URL: {$signedUrlData['signed_url']}");
            $this->line("   ⏰ Expires: {$signedUrlData['expires_at']}");
            $this->line("   📅 Valid for: {$expiryDays} day(s)");

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error generating voucher PDF: ' . $e->getMessage());
            $this->error('📍 File: ' . $e->getFile() . ':' . $e->getLine());
            return 1;
        }
    }


    private function generateMultiVoucherHtml($orders): string
    {
        $vouchersHtml = '';
        
        foreach ($orders as $index => $order) {
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
        // Get tax value from parameters
        $taxValue = Parameter::getValue(Parameter::TAX_VALUE, 0);

        // Format dates
        $orderDate = Carbon::parse($order->created_at)->format('d/m/Y');
        $dispatchDate = Carbon::parse($order->dispatch_date)->format('d/m/Y');

        // Calculate totals
        $subtotal = $order->total;
        $dispatchCost = $order->dispatch_cost / 100; // Convert from cents
        $subtotalWithDispatch = $subtotal + $dispatchCost;
        $taxAmount = $subtotalWithDispatch * $taxValue;
        $total = $subtotalWithDispatch + $taxAmount;

        // Company and branch info
        $company = $order->user->company;
        $branch = $order->user->branch;
        
        $clientName = $company->name ?? 'N/A';
        $clientRut = $company->tax_id ?? 'N/A';
        $clientGiro = 'MINIMARKET'; // Default as per original voucher
        $address = $branch->shipping_address ?? $branch->address ?? 'N/A';

        // Format totals
        $formattedNeto = number_format($subtotalWithDispatch, 0, ',', '.');
        $formattedIva = number_format($taxAmount, 0, ',', '.');
        $formattedTotal = number_format($total, 0, ',', '.');

        // Build products table rows
        $productRowsHtml = '';
        foreach ($order->orderLines as $line) {
            $product = $line->product;
            $subtotalLine = $line->total_price / 100; // Convert from cents
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
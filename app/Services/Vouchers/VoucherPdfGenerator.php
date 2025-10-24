<?php

namespace App\Services\Vouchers;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use setasign\Fpdi\Tcpdf\Fpdi;

class VoucherPdfGenerator
{
    /**
     * Generate PDF content for multiple vouchers
     *
     * @param Collection $orders
     * @return string PDF binary content
     */
    public function generateMultiVoucherPdf(Collection $orders): string
    {
        // Generate individual PDFs and merge them
        $pdfPages = [];

        foreach ($orders as $order) {
            $singleHtml = $this->generateSingleVoucherHtml($order);
            $wrappedHtml = $this->wrapSingleVoucherForMeasurement($singleHtml);

            // Render voucher with very tall paper to measure
            $tempPdf = Pdf::loadHTML($wrappedHtml);
            $tempPdf->setPaper([0, 0, 226.77, 9999], 'portrait');

            $dompdf = $tempPdf->getDomPDF();

            // Use callback to capture actual body height
            $bodyHeight = 0;
            $dompdf->setCallbacks([
                'myCallback' => [
                    'event' => 'end_frame',
                    'f' => function ($frame) use (&$bodyHeight) {
                        $node = $frame->get_node();
                        if (strtolower($node->nodeName) === 'body') {
                            $padding_box = $frame->get_padding_box();
                            $bodyHeight = $padding_box['h'];
                        }
                    }
                ]
            ]);

            $dompdf->render();

            $height = $bodyHeight;

            // Add minimal padding (20 points for cutting)
            $finalHeight = $height + 20;

            // Now render with exact height
            $finalPdf = Pdf::loadHTML($wrappedHtml);
            $finalPdf->setPaper([0, 0, 226.77, $finalHeight], 'portrait');

            $pdfPages[] = $finalPdf->output();
        }

        // If single order, return that PDF
        if (count($pdfPages) === 1) {
            return $pdfPages[0];
        }

        // For multiple orders, merge PDFs with FPDI
        return $this->mergePdfs($pdfPages);
    }

    /**
     * Merge multiple PDF binaries into one
     *
     * @param array $pdfPages Array of PDF binary strings
     * @return string Merged PDF binary
     */
    private function mergePdfs(array $pdfPages): string
    {
        // Create temporary files for each PDF
        $tempFiles = [];
        foreach ($pdfPages as $pdfContent) {
            $tempFile = tempnam(sys_get_temp_dir(), 'voucher_') . '.pdf';
            file_put_contents($tempFile, $pdfContent);
            $tempFiles[] = $tempFile;
        }

        // Use FPDI to merge PDFs
        $fpdi = new Fpdi('P', 'pt'); // Portrait, points

        // Disable automatic page breaks
        $fpdi->SetAutoPageBreak(false);

        foreach ($tempFiles as $file) {
            $pageCount = $fpdi->setSourceFile($file);

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $template = $fpdi->importPage($pageNo);
                $size = $fpdi->getTemplateSize($template);

                // Add page with exact size from source
                $fpdi->AddPage(
                    $size['orientation'],
                    [$size['width'], $size['height']]
                );

                // Use template at position 0,0
                $fpdi->useTemplate($template, 0, 0, $size['width'], $size['height']);
            }
        }

        // Save merged PDF to temp file first
        $mergedTempFile = tempnam(sys_get_temp_dir(), 'merged_') . '.pdf';
        $fpdi->Output($mergedTempFile, 'F');

        // Read the merged PDF
        $mergedPdf = file_get_contents($mergedTempFile);

        // Clean up temp files
        foreach ($tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // Clean up merged temp file
        if (file_exists($mergedTempFile)) {
            unlink($mergedTempFile);
        }

        return $mergedPdf;
    }

    /**
     * Generate multi-page PDF with proper page breaks
     *
     * @param Collection $orders
     * @return string
     */
    private function generateMultiPagePdf(Collection $orders): string
    {
        $html = $this->generateMultiVoucherHtml($orders);

        $pdf = Pdf::loadHTML($html);

        $options = $pdf->getDomPDF()->getOptions();
        $options->set([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true
        ]);
        $pdf->getDomPDF()->setOptions($options);

        // Use tall paper and let page-break-after handle the breaks
        $pdf->setPaper([0, 0, 226.77, 2000], 'portrait');

        return $pdf->output();
    }

    /**
     * Wrap single voucher HTML for measurement
     *
     * @param string $voucherHtml
     * @return string
     */
    private function wrapSingleVoucherForMeasurement(string $voucherHtml): string
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                " . $this->getVoucherStyles() . "
            </style>
        </head>
        <body>
            {$voucherHtml}
        </body>
        </html>";
    }

    /**
     * Generate HTML for multiple vouchers
     *
     * @param Collection $orders
     * @return string
     */
    private function generateMultiVoucherHtml(Collection $orders): string
    {
        $vouchersHtml = '';
        $totalOrders = $orders->count();
        $index = 0;

        foreach ($orders as $order) {
            $index++;
            $isLast = ($index === $totalOrders);

            // Wrap each voucher in a div that prevents internal page breaks
            // Add page-break-after to all vouchers except the last one
            $vouchersHtml .= "<div class='voucher-wrapper' style='" .
                ($isLast ? '' : 'page-break-after:always;') .
                "'>";

            $vouchersHtml .= $this->generateSingleVoucherHtml($order);

            $vouchersHtml .= "</div>";
        }

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Vouchers PDF</title>
            <style>
                " . $this->getVoucherStyles() . "
            </style>
        </head>
        <body>
            {$vouchersHtml}
        </body>
        </html>";

        return $html;
    }

    /**
     * Generate HTML for a single voucher
     *
     * @param Order $order
     * @return string
     */
    private function generateSingleVoucherHtml(Order $order): string
    {
        $dispatchDate = Carbon::parse($order->dispatch_date)->format('d/m/Y');

        $neto = $order->total / 100;
        $dispatchCost = $order->dispatch_cost / 100;
        $taxAmount = $order->tax_amount / 100;
        $total = $order->grand_total / 100;

        $company = $order->user->company;
        $branch = $order->user->branch;

        $clientName = $company->name ?? 'N/A';
        $clientRut = $company->tax_id ?? 'N/A';
        $branchFantasyName = $branch->fantasy_name ?? 'N/A';
        $address = $branch->shipping_address ?? $branch->address ?? 'N/A';

        $formattedNeto = number_format($neto, 0, ',', '.');
        $formattedIva = number_format($taxAmount, 0, ',', '.');
        $formattedDispatchCost = number_format($dispatchCost, 0, ',', '.');
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
                    <td>{$productCode}</td>
                    <td>{$productName}</td>
                    <td class='center'>{$line->quantity} UN</td>
                    <td class='right'>$ {$formattedSubtotal}</td>
                </tr>";
        }

        $html = "
        <div class='voucher-container'>
            <div class='header'>
                <h1>Pedido N° {$order->order_number}</h1>
            </div>

            <table class='info-table'>
                <tr>
                    <td class='label'>Cliente</td>
                    <td>{$clientName}</td>
                </tr>
                <tr>
                    <td class='label'>RUT</td>
                    <td>{$clientRut}</td>
                </tr>
                <tr>
                    <td class='label'>Sucursal</td>
                    <td>{$branchFantasyName}</td>
                </tr>
                <tr>
                    <td class='label'>Despacho</td>
                    <td>{$address}</td>
                </tr>
                <tr>
                    <td class='label'>Fecha despacho</td>
                    <td>{$dispatchDate}</td>
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
                <div><strong>Transporte: $ {$formattedDispatchCost}</strong></div>
                <div><strong>TOTAL: $ {$formattedTotal}</strong></div>
            </div>

        </div>";

        return $html;
    }

    /**
     * Get CSS styles for vouchers
     *
     * @return string
     */
    private function getVoucherStyles(): string
    {
        return "
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: Arial, sans-serif;
                font-size: 11px;
                margin: 1mm;
                width: 78mm;
                line-height: 1.3;
            }
            .voucher-wrapper {
                page-break-inside: avoid !important;
            }
            .voucher-container {
                page-break-inside: avoid !important;
            }
            .header {
                text-align: center;
                margin-bottom: 4mm;
                page-break-inside: avoid;
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
                font-size: 11px;
                page-break-inside: avoid;
            }
            .info-table td {
                padding: 3px 4px;
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
                font-size: 10px;
                margin: 0;
            }
            .products-table th {
                padding: 4px 3px;
                border: 1px solid #000;
                background-color: #f0f0f0;
                font-weight: bold;
                text-align: center;
                font-size: 10px;
            }
            .products-table .col-code { width: 15%; }
            .products-table .col-product { width: 50%; }
            .products-table .col-qty { width: 15%; }
            .products-table .col-subtotal { width: 20%; }
            .products-table td {
                padding: 4px 3px;
                border: 1px solid #000;
                vertical-align: top;
                font-size: 10px;
                word-wrap: break-word;
            }
            .product-cell {
                padding: 4px 3px;
                vertical-align: top;
                font-size: 10px;
                word-wrap: break-word;
            }
            .center { text-align: center; }
            .right { text-align: right; }
            .totals {
                text-align: right;
                margin-top: 4mm;
                font-size: 12px;
                font-weight: bold;
                page-break-inside: avoid;
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
                page-break-inside: avoid;
            }
        ";
    }
}

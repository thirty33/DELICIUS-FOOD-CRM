<?php

namespace App\Services\Vouchers;

use App\Contracts\Vouchers\GroupingStrategy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Abstract Voucher Generator (Template Method Pattern)
 *
 * Defines the skeleton of the voucher generation algorithm:
 * 1. Group orders according to strategy
 * 2. Generate HTML for each group
 * 3. Render PDFs and merge them
 *
 * Subclasses implement specific HTML generation logic
 */
abstract class VoucherGenerator
{
    protected GroupingStrategy $groupingStrategy;

    public function __construct(GroupingStrategy $groupingStrategy)
    {
        $this->groupingStrategy = $groupingStrategy;
    }

    /**
     * Template Method - Defines the algorithm skeleton
     *
     * @param Collection $orders
     * @return string PDF binary content
     */
    public function generate(Collection $orders): string
    {
        // Step 1: Group orders according to strategy
        $groupedOrders = $this->groupingStrategy->group($orders);

        // Step 2: Generate PDFs for each group
        $pdfPages = [];
        foreach ($groupedOrders as $orderGroup) {
            $html = $this->generateHtmlForGroup($orderGroup);
            $pdfPages[] = $this->renderSinglePdf($html);
        }

        // Step 3: Merge PDFs if multiple
        if (count($pdfPages) === 1) {
            return $pdfPages[0];
        }

        return $this->mergePdfs($pdfPages);
    }

    /**
     * Generate HTML for a group of orders
     * Subclasses must implement this
     *
     * @param array $orderGroup Array of orders in the same group
     * @return string HTML content
     */
    abstract protected function generateHtmlForGroup(array $orderGroup): string;

    /**
     * Render a single HTML string to PDF binary
     *
     * @param string $html
     * @return string PDF binary
     */
    protected function renderSinglePdf(string $html): string
    {
        $wrappedHtml = $this->wrapHtmlForMeasurement($html);

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

        return $finalPdf->output();
    }

    /**
     * Wrap HTML content for measurement
     *
     * @param string $html
     * @return string
     */
    protected function wrapHtmlForMeasurement(string $html): string
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
            {$html}
        </body>
        </html>";
    }

    /**
     * Get CSS styles for vouchers
     * Can be overridden by subclasses if needed
     *
     * @return string
     */
    protected function getVoucherStyles(): string
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
            .products-table .col-product { width: 65%; }
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

    /**
     * Merge multiple PDF binaries into one
     *
     * @param array $pdfPages Array of PDF binary strings
     * @return string Merged PDF binary
     */
    protected function mergePdfs(array $pdfPages): string
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
}

<?php

namespace App\Services\Labels\Core;

use App\Contracts\Labels\LabelGeneratorInterface;
use App\Services\Labels\Support\PdfMerger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

abstract class AbstractLabelGenerator implements LabelGeneratorInterface
{
    protected PdfMerger $pdfMerger;
    protected LabelGridLayout $gridLayout;

    public function __construct()
    {
        $this->pdfMerger = new PdfMerger();
        $this->gridLayout = new LabelGridLayout(
            $this->getGridConfiguration(),
            $this->getLabelDimensions()
        );
    }

    public function generate(Collection $products): string
    {
        // NEW IMPLEMENTATION: Single multi-page PDF (more efficient)
        // Generates one PDF with multiple pages instead of merging individual PDFs
        // This reduces processing time from ~5 minutes to seconds for 42 labels
        $labelHtmls = [];
        foreach ($products as $product) {
            $labelHtmls[] = $this->generateLabelHtml($product);
        }

        $pageHtmls = $this->gridLayout->arrangeIntoPages($labelHtmls);

        return $this->renderMultiPagePdf($pageHtmls);

        /* OLD IMPLEMENTATION: Merge-based approach (SLOW - kept for rollback)
        // This approach was causing 5-minute delays for 42 labels due to:
        // - Creating N individual PDFs
        // - Writing N temp files to disk
        // - Parsing each PDF with FPDI
        // - Extracting and recompressing ~9N images
        // - Merging into final PDF

        $labelHtmls = [];
        foreach ($products as $product) {
            $labelHtmls[] = $this->generateLabelHtml($product);
        }

        $pageHtmls = $this->gridLayout->arrangeIntoPages($labelHtmls);

        $pdfBinaries = [];
        foreach ($pageHtmls as $pageHtml) {
            $pdfBinaries[] = $this->renderPageToPdf($pageHtml);
        }

        return $this->pdfMerger->merge($pdfBinaries);
        */
    }

    abstract protected function generateLabelHtml($product): string;

    protected function getLabelDimensions(): array
    {
        // Label: 10cm width x 5cm height
        return [
            'width' => 283.46,   // 100mm = 10cm
            'height' => 141.73,  // 50mm = 5cm
        ];
    }

    protected function getGridConfiguration(): array
    {
        // One label per page - exact label dimensions
        return [
            'rows' => 1,
            'cols' => 1,
            'page_width' => 283.46,   // 100mm in points
            'page_height' => 141.73,  // 50mm in points
            'margin_left' => 0,
            'margin_top' => 0,
            'gap_horizontal' => 0,
            'gap_vertical' => 0,
        ];
    }

    /**
     * Renders multiple pages into a single PDF (optimized approach)
     *
     * This method generates one PDF with all pages, which is much faster than
     * creating individual PDFs and merging them with FPDI. DomPDF can optimize
     * image storage by storing each unique image once and referencing it from
     * all pages that use it.
     *
     * @param array $pageHtmls Array of HTML strings, one per page
     * @return string PDF binary content
     */
    protected function renderMultiPagePdf(array $pageHtmls): string
    {
        $multiPageHtml = $this->wrapMultiPageHtmlForPdf($pageHtmls);

        $pdf = Pdf::loadHTML($multiPageHtml);

        // Custom paper size: width=100mm (283.46pt), height=50mm (141.73pt)
        $pdf->setPaper([0, 0, 283.46, 141.73]);

        return $pdf->output();
    }

    /**
     * Wraps multiple page HTMLs into a single HTML document with page breaks
     *
     * @param array $pageHtmls Array of HTML strings, one per page
     * @return string Complete HTML document with all pages
     */
    protected function wrapMultiPageHtmlForPdf(array $pageHtmls): string
    {
        $pagesHtml = '';

        foreach ($pageHtmls as $index => $pageHtml) {
            // Add page break before each page except the first
            if ($index > 0) {
                $pagesHtml .= '<div style="page-break-before: always;"></div>';
            }

            $pagesHtml .= $pageHtml;
        }

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                " . $this->getLabelStyles() . "
            </style>
        </head>
        <body>
            {$pagesHtml}
        </body>
        </html>";
    }

    /**
     * Renders a single page to PDF (old approach, kept for reference)
     *
     * @param string $pageHtml HTML content for one page
     * @return string PDF binary content
     */
    protected function renderPageToPdf(string $pageHtml): string
    {
        $wrappedHtml = $this->wrapHtmlForPdf($pageHtml);

        $pdf = Pdf::loadHTML($wrappedHtml);

        // Custom paper size: width=100mm (283.46pt), height=50mm (141.73pt)
        // DomPDF setPaper expects [x, y, width, height] in points
        $pdf->setPaper([0, 0, 283.46, 141.73]);

        return $pdf->output();
    }

    protected function wrapHtmlForPdf(string $html): string
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                " . $this->getLabelStyles() . "
            </style>
        </head>
        <body>
            {$html}
        </body>
        </html>";
    }

    protected function getLabelStyles(): string
    {
        return "
            @page {
                size: 100mm 50mm;
                margin: 0;
                padding: 0;
            }
            * { box-sizing: border-box; margin: 0; padding: 0; }
            html, body {
                width: 100mm;
                height: 50mm;
                margin: 0;
                padding: 0;
                font-family: Helvetica, Arial, sans-serif;
                font-weight: bold;
                font-size: 10px;
            }
        ";
    }
}
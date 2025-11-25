<?php

namespace App\Services\Labels\Core;

use App\Services\Labels\Support\PdfMerger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

abstract class AbstractLabelGenerator
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
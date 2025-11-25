<?php

namespace App\Services\Labels\Core;

class LabelGridLayout
{
    protected array $gridConfig;
    protected array $labelDimensions;

    public function __construct(array $gridConfig, array $labelDimensions)
    {
        $this->gridConfig = $gridConfig;
        $this->labelDimensions = $labelDimensions;
    }

    public function arrangeIntoPages(array $labelHtmls): array
    {
        $labelsPerPage = $this->gridConfig['rows'] * $this->gridConfig['cols'];
        $pageHtmls = [];

        $chunks = array_chunk($labelHtmls, $labelsPerPage);

        foreach ($chunks as $chunk) {
            $pageHtmls[] = $this->wrapPageWithGrid($chunk);
        }

        return $pageHtmls;
    }

    protected function wrapPageWithGrid(array $labelHtmls): string
    {
        $cellHtmls = [];
        $index = 0;

        for ($row = 0; $row < $this->gridConfig['rows']; $row++) {
            for ($col = 0; $col < $this->gridConfig['cols']; $col++) {
                if (isset($labelHtmls[$index])) {
                    $cellHtmls[] = $this->wrapInGridCell($labelHtmls[$index], $row, $col);
                }
                $index++;
            }
        }

        $gridHtml = implode("\n", $cellHtmls);

        $pageWidth = $this->gridConfig['page_width'];
        $pageHeight = $this->gridConfig['page_height'];

        return "
        <div style='position: relative; width: {$pageWidth}pt; height: {$pageHeight}pt;'>
            {$gridHtml}
        </div>";
    }

    protected function wrapInGridCell(string $labelHtml, int $row, int $col): string
    {
        $position = $this->calculateLabelPosition($row, $col);

        $width = $this->labelDimensions['width'];
        $height = $this->labelDimensions['height'];

        return "
        <div style='position: absolute;
                    left: {$position['x']}pt;
                    top: {$position['y']}pt;
                    width: {$width}pt;
                    height: {$height}pt;'>
            {$labelHtml}
        </div>";
    }

    protected function calculateLabelPosition(int $row, int $col): array
    {
        $x = $this->gridConfig['margin_left'] + ($col * ($this->labelDimensions['width'] + $this->gridConfig['gap_horizontal']));
        $y = $this->gridConfig['margin_top'] + ($row * ($this->labelDimensions['height'] + $this->gridConfig['gap_vertical']));

        return ['x' => $x, 'y' => $y];
    }
}
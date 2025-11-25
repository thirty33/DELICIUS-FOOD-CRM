<?php

namespace App\Services\Labels\Support;

use setasign\Fpdi\Tcpdf\Fpdi;

class PdfMerger
{
    public function merge(array $pdfBinaries): string
    {
        if (count($pdfBinaries) === 1) {
            return $pdfBinaries[0];
        }

        $tempFiles = [];
        $mergedTempFile = null;

        try {
            foreach ($pdfBinaries as $index => $pdfContent) {
                $tempFile = $this->createTempFile("label_{$index}_", '.pdf');
                file_put_contents($tempFile, $pdfContent);
                $tempFiles[] = $tempFile;
            }

            $fpdi = new Fpdi('P', 'pt');
            $fpdi->SetAutoPageBreak(false);

            foreach ($tempFiles as $file) {
                $pageCount = $fpdi->setSourceFile($file);

                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $template = $fpdi->importPage($pageNo);
                    $size = $fpdi->getTemplateSize($template);

                    $fpdi->AddPage(
                        $size['orientation'],
                        [$size['width'], $size['height']]
                    );

                    $fpdi->useTemplate($template, 0, 0, $size['width'], $size['height']);
                }
            }

            $mergedTempFile = $this->createTempFile('merged_labels_', '.pdf');
            $fpdi->Output($mergedTempFile, 'F');

            return file_get_contents($mergedTempFile);

        } finally {
            $this->cleanupTempFiles($tempFiles);

            if ($mergedTempFile && file_exists($mergedTempFile)) {
                unlink($mergedTempFile);
            }
        }
    }

    protected function createTempFile(string $prefix, string $extension): string
    {
        return tempnam(sys_get_temp_dir(), $prefix) . $extension;
    }

    protected function cleanupTempFiles(array $files): void
    {
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
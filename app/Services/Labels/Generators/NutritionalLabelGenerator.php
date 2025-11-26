<?php

namespace App\Services\Labels\Generators;

use App\Repositories\NutritionalInformationRepository;
use App\Services\Labels\Core\AbstractLabelGenerator;
use Picqer\Barcode\Types\TypeEan13;
use Picqer\Barcode\Renderers\PngRenderer;

class NutritionalLabelGenerator extends AbstractLabelGenerator
{
    protected ?string $elaborationDate = null;

    // Cache for base64 images to avoid repeated file reads and encoding
    protected array $imageCache = [];

    // Cache for generated barcodes to avoid regenerating the same barcode
    protected array $barcodeCache = [];

    public function __construct(
        protected NutritionalInformationRepository $repository
    ) {
        parent::__construct();
    }

    public function setElaborationDate(string $elaborationDate): void
    {
        $this->elaborationDate = $elaborationDate;
    }
    protected function generateLabelHtml($product): string
    {
        // Use CloudFront signed URLs from config instead of local files
        // This improves PDF generation performance by using external URLs
        $logoUrl = $this->getLogoUrl('main_logo');  // Main company logo (Negro_Pequeño.png)
        $sodioLogoUrl = $this->getLogoUrl('alto_sodio');
        $grasasSaturadasUrl = $this->getLogoUrl('alto_grasas_saturadas');
        $azucaresLogoUrl = $this->getLogoUrl('alto_azucares');
        $caloriasLogoUrl = $this->getLogoUrl('alto_calorias');

        // Social media icons
        $arrobaUrl = $this->getLogoUrl('arroba');
        $whatsappUrl = $this->getLogoUrl('whatsapp');
        $instagramUrl = $this->getLogoUrl('instagram');
        $worldwideUrl = $this->getLogoUrl('worldwide');

        $logoBase64 = $this->getImageBase64FromUrl($logoUrl);
        $sodioLogoBase64 = $this->getImageBase64FromUrl($sodioLogoUrl);
        $grasasSaturadasBase64 = $this->getImageBase64FromUrl($grasasSaturadasUrl);
        $azucaresLogoBase64 = $this->getImageBase64FromUrl($azucaresLogoUrl);
        $caloriasLogoBase64 = $this->getImageBase64FromUrl($caloriasLogoUrl);
        $arrobaBase64 = $this->getImageBase64FromUrl($arrobaUrl);
        $whatsappBase64 = $this->getImageBase64FromUrl($whatsappUrl);
        $instagramBase64 = $this->getImageBase64FromUrl($instagramUrl);
        $worldwideBase64 = $this->getImageBase64FromUrl($worldwideUrl);

        // Generate real barcode from nutritional_information
        $barcodeNumber = $this->repository->getBarcode($product);
        $barcodeBase64 = $this->generateBarcodeBase64($barcodeNumber);

        // Build barcode HTML only if barcode exists
        $barcodeHtml = '';
        if ($barcodeNumber && $barcodeBase64) {
            $barcodeHtml = "
                        <div class='barcode-section'>
                            <img src='data:image/png;base64,{$barcodeBase64}' class='barcode-image' />
                            <div class='barcode-number'>{$barcodeNumber}</div>
                        </div>";
        }

        // Get dates
        $elaborationDate = $this->elaborationDate ?: now()->format('d/m/Y');
        $expirationDate = $this->repository->getExpirationDate($product, $elaborationDate);

        $html = "
        <div class='label-container'>
            <table class='main-layout' cellpadding='0' cellspacing='0'>
                <tr>
                    <!-- LEFT COLUMN: Barcode + Title + Ingredients + Dates -->
                    <td class='left-column'>
                        {$barcodeHtml}

                        <div class='product-title'>{$product->name}</div>

                        <div class='ingredients-block'>
                            <p><strong>Ingredientes:</strong> {$this->repository->getIngredients($product)}</p>

                            <p><strong>Alérgenos:</strong> {$this->repository->getAllergens($product)}</p>

                            <p>Mantener refrigerado entre 2°C y 5°C.<br>Listo para el consumo.</p>
                        </div>

                        <div class='dates-section'>
                            <p><strong>ELABORACION: {$elaborationDate}</strong></p>
                            <p><strong>VENCIMIENTO: {$expirationDate}</strong></p>
                        </div>
                    </td>

                    <!-- CENTER COLUMN: Logo + Company Info + Weight -->
                    <td class='center-column'>
                        <div class='logo-section'>
                            <img src='data:image/png;base64,{$logoBase64}' class='logo' />
                        </div>

                        <div class='company-info'>
                            <p><strong>Elab. Delicius Food Spa. Sta Laura 1220, Independencia RM País de Origen Chile. SEREMI: 002180</strong></p>
                            <p><strong>FECHA: 29/06/2023 Seremi RM</strong></p>
                            <p class='social-line'><img src='data:image/png;base64,{$arrobaBase64}' class='social-icon' /> ventas@deliciusfood.cl</p>
                            <p><img src='data:image/png;base64,{$whatsappBase64}' class='social-icon' /> +56 9 5189 3815</p>
                            <p><img src='data:image/png;base64,{$instagramBase64}' class='social-icon' /> deliciusfood.cl/deliciuscoffee.cl</p>
                            <p><img src='data:image/png;base64,{$worldwideBase64}' class='social-icon' /> deliciusfood.cl</p>
                        </div>

                        <div class='weight-section'>
                            <p class='weight-value'><strong>Peso {$this->repository->getGrossWeight($product)}</strong></p>
                            <p class='weight-note'>Agitar soya antes de verter</p>
                        </div>
                    </td>

                    <!-- RIGHT COLUMN: Nutritional Table + Warning Icons -->
                    <td class='right-column'>
                        <table class='nutri-table-full' cellpadding='0' cellspacing='0'>
                            <tr class='title-row'>
                                <td colspan='3' class='nutri-title'>Información Nutricional</td>
                            </tr>
                            <tr>
                                <td class='label-cell'>Porción:</td>
                                <td class='value-cell' colspan='2'>{$this->repository->getPortionWeight($product)}</td>
                            </tr>
                            <tr>
                                <td class='label-cell'>Porciones por envase:</td>
                                <td class='value-cell' colspan='2'>1</td>
                            </tr>
                            <tr class='header-row'>
                                <th class='col-name'></th>
                                <th class='col-100g'>100 g</th>
                                <th class='col-portion'>1 porción</th>
                            </tr>";

        // Get nutritional values dynamically
        $nutritionalValues = $this->repository->getNutritionalValuesForLabel($product);
        foreach ($nutritionalValues as $value) {
            $label = $value['label'];
            $per100g = number_format($value['per100g'], 1, ',', '');
            $perPortion = number_format($value['perPortion'], 1, ',', '');

            $html .= "<tr><td>{$label}</td><td>{$per100g}</td><td>{$perPortion}</td></tr>";
        }

        $html .= "
                        </table>

                        <div class='icons-section'>";

        // Get active "Alto en" flags and show only those icons
        $activeFlags = $this->repository->getActiveHighContentFlags($product);

        foreach ($activeFlags as $flag) {
            $iconBase64 = match ($flag) {
                'high_calories' => $caloriasLogoBase64,
                'high_sugar' => $azucaresLogoBase64,
                'high_fat' => $grasasSaturadasBase64,
                'high_sodium' => $sodioLogoBase64,
                default => '',
            };

            if ($iconBase64) {
                $html .= "<img src='data:image/png;base64,{$iconBase64}' class='warning-icon' />";
            }
        }

        $html .= "
                        </div>
                    </td>
                </tr>
            </table>
        </div>";

        return $html;
    }

    protected function getLabelStyles(): string
    {
        return parent::getLabelStyles() . "
            .label-container {
                width: 100mm;
                height: 50mm;
                padding: 3mm 1mm 1mm 1mm;
                font-size: 7px;
                line-height: 1.15;
                overflow: hidden;
                box-sizing: border-box;
                background-color: #ffffff;
            }

            .main-layout {
                width: 100%;
                height: 100%;
                border-collapse: collapse;
                background-color: #ffffff;
            }

            .main-layout .left-column { width: 33%; }
            .main-layout .center-column { width: 33%; }
            .main-layout .right-column { width: 34%; }

            .left-column, .center-column, .right-column {
                vertical-align: top;
                padding: 0;
            }

            .left-column {
                padding-left: 1mm;
                padding-right: 1mm;
                background-color: #ffffff;
            }

            .center-column {
                padding: 0 0.5mm 0 1mm;
                background-color: #ffffff;
            }

            .right-column {
                padding-right: 1mm;
                overflow: hidden;
                background-color: #ffffff;
            }

            /* LEFT COLUMN STYLES */
            .barcode-section {
                text-align: center;
                margin-bottom: 1mm;
                font-family: monospace;
                background-color: #ffffff;
            }

            .barcode-image {
                width: 25mm;
                height: 8mm;
                display: block;
                margin: 0 auto;
                background-color: #ffffff;
            }

            .barcode-number {
                font-size: 9px;
                margin-top: 0.5mm;
                background-color: #ffffff;
                font-weight: bold;
                letter-spacing: 0.5px;
            }

            .product-title {
                font-weight: bold;
                font-size: 9px;
                margin-bottom: 1mm;
                background-color: #ffffff;
            }

            .ingredients-block {
                font-size: 5.5px;
                line-height: 1.2;
                background-color: #ffffff;
            }

            .ingredients-block p {
                margin: 0 0 0.8mm 0;
                background-color: #ffffff;
            }

            .dates-section {
                margin-top: 1mm;
                font-size: 6px;
                background-color: #ffffff;
            }

            .dates-section p {
                margin: 0 0 0.3mm 0;
                background-color: #ffffff;
            }

            /* CENTER COLUMN STYLES */
            .logo-section {
                text-align: center;
                margin-bottom: 1.5mm;
                background-color: #ffffff;
            }

            .logo {
                max-width: 25mm;
                height: auto;
                background-color: #ffffff;
            }

            .company-info {
                font-size: 6px;
                line-height: 1.3;
                background-color: #ffffff;
            }

            .company-info p {
                margin: 0 0 0.5mm 0;
                background-color: #ffffff;
            }

            .company-info .icon {
                font-size: 4.5px;
                background-color: #ffffff;
            }

            .social-icon {
                width: 2.5mm;
                height: 2.5mm;
                vertical-align: middle;
                margin-right: 0.5mm;
            }

            .social-line {
                margin-top: 1mm;
            }

            .weight-section {
                margin-top: 1.5mm;
                text-align: center;
                background-color: #ffffff;
            }

            .weight-value {
                font-size: 7px;
                margin: 0 0 0.3mm 0;
                background-color: #ffffff;
            }

            .weight-note {
                font-size: 5.5px;
                margin: 0;
                background-color: #ffffff;
            }

            /* RIGHT COLUMN STYLES */
            .nutri-table-full {
                width: 95%;
                border-collapse: separate;
                border-spacing: 0;
                font-size: 5px;
                border: 1px solid #000;
                background-color: #ffffff;
            }

            .nutri-table-full .col-name { width: 45%; }
            .nutri-table-full .col-100g { width: 22%; }
            .nutri-table-full .col-portion { width: 33%; }

            .nutri-table-full td,
            .nutri-table-full th {
                padding: 0.2mm 0.3mm;
                background-color: #ffffff;
            }

            .nutri-table-full tr:not(.title-row):not(.header-row) td {
                border-bottom: 0.5px solid #000;
            }

            .nutri-title {
                font-weight: bold;
                text-align: right;
                font-size: 6px;
                padding-right: 0.5mm;
                background-color: #ffffff;
            }

            .title-row td {
                border-bottom: none;
                background-color: #ffffff;
            }

            .label-cell {
                font-weight: bold;
                text-align: left;
                background-color: #ffffff;
            }

            .value-cell {
                text-align: right;
                background-color: #ffffff;
            }

            .header-row {
                background-color: #000;
                color: #fff;
            }

            .header-row th {
                padding: 0.4mm;
                font-size: 5px;
                text-align: center;
                border-bottom: none;
                background-color: #000;
                color: #fff;
            }

            .nutri-table-full tr td:first-child {
                text-align: left;
                border-right: 0.5px solid #000;
                background-color: #ffffff;
            }

            .nutri-table-full tr td:nth-child(2) {
                text-align: right;
                border-right: 0.5px solid #000;
                background-color: #ffffff;
            }

            .nutri-table-full tr td:nth-child(3) {
                text-align: right;
                background-color: #ffffff;
            }

            .nutri-table-full tr:last-child td {
                border-bottom: none;
            }

            .icons-section {
                margin-top: 1mm;
                text-align: center;
                background-color: #ffffff;
                white-space: nowrap;
                padding-right: 1.5mm;
            }

            .warning-icon {
                width: 7.5mm;
                height: 7.5mm;
                margin: 0 0.3mm;
                display: inline-block;
                vertical-align: top;
                background-color: #ffffff;
            }

            .portion-number {
                font-size: 8px;
                font-weight: bold;
                display: inline-block;
                vertical-align: top;
                margin-left: 1mm;
                background-color: #ffffff;
            }
        ";
    }

    /**
     * Get logo URL from configuration
     *
     * @param string $logoKey Key name for the logo (e.g., 'alto_azucares', 'instagram')
     * @return string CloudFront signed URL or empty string if not found
     */
    protected function getLogoUrl(string $logoKey): string
    {
        // Check for logo aliases (e.g., 'altoenzucares' => 'alto_azucares')
        $aliases = config('nutritional_labels.logo_aliases', []);
        $logoKey = $aliases[$logoKey] ?? $logoKey;

        // Get URL from config
        return config("nutritional_labels.logos.{$logoKey}", '');
    }

    /**
     * Get base64 encoded image from URL (CloudFront signed URL)
     *
     * @param string $url CloudFront signed URL
     * @return string Base64 encoded image data
     */
    protected function getImageBase64FromUrl(string $url): string
    {
        if (empty($url)) {
            return '';
        }

        // Use cache to avoid downloading the same image multiple times
        if (isset($this->imageCache[$url])) {
            return $this->imageCache[$url];
        }

        try {
            // Download image from CloudFront
            $imageData = file_get_contents($url);

            if ($imageData === false) {
                \Log::warning("Failed to download image from URL", ['url' => $url]);
                return '';
            }

            $base64 = base64_encode($imageData);

            // Store in cache for reuse
            $this->imageCache[$url] = $base64;

            return $base64;
        } catch (\Exception $e) {
            \Log::error("Error downloading image from URL", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * DEPRECATED: Get base64 encoded image from local file path
     * This method is kept for backward compatibility with local file storage
     *
     * @param string $path Local file path
     * @return string Base64 encoded image data
     */
    protected function getImageBase64(string $path): string
    {
        // Use cache to avoid reading and encoding the same image multiple times
        if (isset($this->imageCache[$path])) {
            return $this->imageCache[$path];
        }

        if (!file_exists($path)) {
            return '';
        }

        $imageData = file_get_contents($path);
        $base64 = base64_encode($imageData);

        // Store in cache for reuse
        $this->imageCache[$path] = $base64;

        return $base64;
    }

    protected function generateBarcodeBase64(?string $code): string
    {
        if (!$code) {
            return '';
        }

        // Validate EAN-13 format (13 digits)
        if (!preg_match('/^\d{13}$/', $code)) {
            \Log::warning("Invalid EAN-13 barcode format", ['code' => $code]);
            return '';
        }

        // Fix checksum digit if invalid
        $code = $this->fixEan13Checksum($code);

        // Use cache to avoid regenerating the same barcode multiple times
        if (isset($this->barcodeCache[$code])) {
            return $this->barcodeCache[$code];
        }

        try {
            $barcodeType = new TypeEan13();
            $barcode = $barcodeType->getBarcode($code);
            $renderer = new PngRenderer();
            $pngData = $renderer->render($barcode, 200, 50);
            $base64 = base64_encode($pngData);

            // Store in cache for reuse
            $this->barcodeCache[$code] = $base64;

            return $base64;
        } catch (\Exception $e) {
            \Log::error("Barcode generation failed", [
                'code' => $code,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return '';
        }
    }

    protected function fixEan13Checksum(string $code): string
    {
        // Get first 12 digits
        $baseCode = substr($code, 0, 12);

        // Calculate correct checksum
        $sum_a = 0;
        for ($i = 1; $i < 12; $i += 2) {
            $sum_a += intval($baseCode[$i]);
        }
        $sum_a *= 3;

        $sum_b = 0;
        for ($i = 0; $i < 12; $i += 2) {
            $sum_b += intval($baseCode[$i]);
        }

        $checksumDigit = ($sum_a + $sum_b) % 10;
        if ($checksumDigit > 0) {
            $checksumDigit = (10 - $checksumDigit);
        }

        // Return code with corrected checksum
        return $baseCode . $checksumDigit;
    }
}
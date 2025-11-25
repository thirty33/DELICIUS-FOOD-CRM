<?php

namespace App\Services\Labels\Generators;

use App\Services\Labels\Core\AbstractLabelGenerator;
use Picqer\Barcode\Types\TypeEan13;
use Picqer\Barcode\Renderers\PngRenderer;

class NutritionalLabelGenerator extends AbstractLabelGenerator
{
    protected function generateLabelHtml($product): string
    {
        $logoPath = storage_path('app/logos/Negro_Pequeño.png');
        $sodioLogoPath = storage_path('app/logos/alto_sodio.png');
        $grasasSaturadasPath = storage_path('app/logos/grasas_saturadas.png');
        $azucaresLogoPath = storage_path('app/logos/alto_azucares.png');
        $caloriasLogoPath = storage_path('app/logos/alto_calorias.png');

        // Social media icons
        $arrobaPath = storage_path('app/logos/arroba.png');
        $whatsappPath = storage_path('app/logos/whatsapp.png');
        $instagramPath = storage_path('app/logos/instagram.png');
        $worldwidePath = storage_path('app/logos/worldwide.png');

        $logoBase64 = $this->getImageBase64($logoPath);
        $sodioLogoBase64 = $this->getImageBase64($sodioLogoPath);
        $grasasSaturadasBase64 = $this->getImageBase64($grasasSaturadasPath);
        $azucaresLogoBase64 = $this->getImageBase64($azucaresLogoPath);
        $caloriasLogoBase64 = $this->getImageBase64($caloriasLogoPath);
        $arrobaBase64 = $this->getImageBase64($arrobaPath);
        $whatsappBase64 = $this->getImageBase64($whatsappPath);
        $instagramBase64 = $this->getImageBase64($instagramPath);
        $worldwideBase64 = $this->getImageBase64($worldwidePath);

        // Generate real barcode
        $barcodeNumber = $product->barcode ?? '7801234567894';
        $barcodeBase64 = $this->generateBarcodeBase64($barcodeNumber);

        return "
        <div class='label-container'>
            <table class='main-layout' cellpadding='0' cellspacing='0'>
                <tr>
                    <!-- LEFT COLUMN: Barcode + Title + Ingredients + Dates -->
                    <td class='left-column'>
                        <div class='barcode-section'>
                            <img src='data:image/png;base64,{$barcodeBase64}' class='barcode-image' />
                            <div class='barcode-number'>{$barcodeNumber}</div>
                        </div>

                        <div class='product-title'>Gohan Pollo Tempura</div>

                        <div class='ingredients-block'>
                            <p><strong>Ingredientes:</strong> Arroz sushi, Vinagre manzana, Azúcar, Sal, Pollo Tempura (Aceite vegetal, Harina, Agua, Sal, Mostaza, Ajo) Palta, Queso crema, Cebollín, Sésamo blanco. Salsa teriyaki.</p>

                            <p><strong>Alérgenos:</strong> Sulfitos, (metabisulfito de sodio), soya, lactosa, gluten. Elaborado en las líneas que contienen huevo, maní, nueces y almendras.</p>

                            <p>Mantener refrigerado entre 2°C y 5°C.<br>Listo para el consumo.</p>
                        </div>

                        <div class='dates-section'>
                            <p><strong>ELABORACION: 09/10/2025</strong></p>
                            <p><strong>VENCIMIENTO: 12/10/2025</strong></p>
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
                            <p class='weight-value'><strong>Peso 410 g.</strong></p>
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
                                <td class='value-cell' colspan='2'>410 gr. aprox</td>
                            </tr>
                            <tr>
                                <td class='label-cell'>Porciones por envase:</td>
                                <td class='value-cell' colspan='2'>1</td>
                            </tr>
                            <tr class='header-row'>
                                <th class='col-name'></th>
                                <th class='col-100g'>100 g</th>
                                <th class='col-portion'>1 porción</th>
                            </tr>
                            <tr><td>Energía (Kcal)</td><td>277</td><td>1122</td></tr>
                            <tr><td>Proteínas (g)</td><td>4,4</td><td>18,0</td></tr>
                            <tr><td>Grasa total (g)</td><td>4,1</td><td>16,8</td></tr>
                            <tr><td>Grasa Saturada</td><td>0,9</td><td>3,8</td></tr>
                            <tr><td>G. Monoinsaturada</td><td>1,7</td><td>7,2</td></tr>
                            <tr><td>G. Poliinsaturada</td><td>0,6</td><td>2,5</td></tr>
                            <tr><td>Grasa Trans</td><td>0,0</td><td>0,0</td></tr>
                            <tr><td>Colesterol (mg)</td><td>5,3</td><td>21,6</td></tr>
                            <tr><td>H. Carbono disp (g)</td><td>54,9</td><td>222,3</td></tr>
                            <tr><td>Fibra (g)</td><td>0,6</td><td>2,7</td></tr>
                            <tr><td>Azúcares totales (g)</td><td>11,5</td><td>46,6</td></tr>
                            <tr><td>Sodio (mg)</td><td>1665</td><td>6745</td></tr>
                        </table>

                        <div class='icons-section'>
                            <img src='data:image/png;base64,{$caloriasLogoBase64}' class='warning-icon' />
                            <img src='data:image/png;base64,{$azucaresLogoBase64}' class='warning-icon' />
                            <img src='data:image/png;base64,{$grasasSaturadasBase64}' class='warning-icon' />
                            <img src='data:image/png;base64,{$sodioLogoBase64}' class='warning-icon' />
                            <!-- <span class='portion-number'>1</span> -->
                        </div>
                    </td>
                </tr>
            </table>
        </div>";
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

    protected function getImageBase64(string $path): string
    {
        if (!file_exists($path)) {
            return '';
        }

        $imageData = file_get_contents($path);
        return base64_encode($imageData);
    }

    protected function generateBarcodeBase64(string $code): string
    {
        // Validate EAN-13 format (13 digits)
        if (!preg_match('/^\d{13}$/', $code)) {
            // Return empty if invalid code
            return '';
        }

        try {
            $barcode = (new TypeEan13())->getBarcode($code);
            $renderer = new PngRenderer();
            $pngData = $renderer->render($barcode, 200, 50);
            return base64_encode($pngData);
        } catch (\Exception $e) {
            return '';
        }
    }
}
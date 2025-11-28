<?php

namespace App\Repositories;

use App\Contracts\NutritionalInformationRepositoryInterface;
use App\Enums\NutritionalValueType;
use App\Models\NutritionalInformation;
use App\Models\NutritionalValue;
use App\Models\Product;

class NutritionalInformationRepository implements NutritionalInformationRepositoryInterface
{
    /**
     * Find a product by its code
     *
     * @param string $productCode
     * @return Product|null
     */
    public function findProductByCode(string $productCode): ?Product
    {
        return Product::where('code', $productCode)->first();
    }

    /**
     * Create or update nutritional information for a product
     *
     * @param int $productId
     * @param array $data
     * @return NutritionalInformation
     */
    public function createOrUpdateNutritionalInformation(int $productId, array $data): NutritionalInformation
    {
        return NutritionalInformation::updateOrCreate(
            ['product_id' => $productId],
            $data
        );
    }

    /**
     * Create or update a nutritional value
     *
     * @param int $nutritionalInformationId
     * @param NutritionalValueType $type
     * @param float $value
     * @return NutritionalValue
     */
    public function createOrUpdateNutritionalValue(
        int $nutritionalInformationId,
        NutritionalValueType $type,
        float $value
    ): NutritionalValue {
        return NutritionalValue::updateOrCreate(
            [
                'nutritional_information_id' => $nutritionalInformationId,
                'type' => $type->value,
            ],
            [
                'value' => $value,
            ]
        );
    }

    /**
     * Create or update multiple nutritional values at once
     *
     * @param int $nutritionalInformationId
     * @param array $values Array of ['type' => value]
     * @return void
     */
    public function createOrUpdateNutritionalValues(int $nutritionalInformationId, array $values): void
    {
        $flagUpdates = [];
        $nutritionalUpdates = [];

        // Separate flag types from nutritional types
        foreach ($values as $type => $value) {
            $typeEnum = $type instanceof NutritionalValueType
                ? $type
                : NutritionalValueType::from($type);

            if ($typeEnum->isFlag()) {
                // Flags are stored as boolean fields in nutritional_information table
                $flagUpdates[$this->mapFlagTypeToField($typeEnum)] = (bool) $value;
            } else {
                // Nutritional values are stored in nutritional_values table
                $nutritionalUpdates[$typeEnum->value] = (float) $value;
            }
        }

        // Update boolean flags in nutritional_information table
        if (!empty($flagUpdates)) {
            NutritionalInformation::where('id', $nutritionalInformationId)->update($flagUpdates);
        }

        // Create/update nutritional value records
        foreach ($nutritionalUpdates as $type => $value) {
            $typeEnum = NutritionalValueType::from($type);
            $this->createOrUpdateNutritionalValue($nutritionalInformationId, $typeEnum, $value);
        }
    }

    /**
     * Map flag type enum to database field name
     *
     * @param NutritionalValueType $type
     * @return string
     */
    private function mapFlagTypeToField(NutritionalValueType $type): string
    {
        return match ($type) {
            NutritionalValueType::HIGH_SODIUM => 'high_sodium',
            NutritionalValueType::HIGH_CALORIES => 'high_calories',
            NutritionalValueType::HIGH_FAT => 'high_fat',
            NutritionalValueType::HIGH_SUGAR => 'high_sugar',
            default => throw new \InvalidArgumentException("Type {$type->value} is not a flag type"),
        };
    }

    /**
     * Get nutritional information by product ID
     *
     * @param int $productId
     * @return NutritionalInformation|null
     */
    public function getNutritionalInformationByProductId(int $productId): ?NutritionalInformation
    {
        return NutritionalInformation::where('product_id', $productId)
            ->with('nutritionalValues')
            ->first();
    }

    /**
     * Get net weight for label portion display
     *
     * @param Product $product
     * @return string
     */
    public function getPortionWeight(Product $product): string
    {
        $nutritionalInfo = $product->nutritionalInformation;

        if (!$nutritionalInfo) {
            return '0 gr. aprox';
        }

        $netWeight = $nutritionalInfo->net_weight;
        $unit = strtolower($nutritionalInfo->measure_unit);

        return "{$netWeight} {$unit}. aprox";
    }

    /**
     * Get nutritional value per portion
     *
     * @param Product $product
     * @param NutritionalValueType $type
     * @return float
     */
    public function getValuePerPortion(Product $product, NutritionalValueType $type): float
    {
        $nutritionalInfo = $product->nutritionalInformation;

        if (!$nutritionalInfo) {
            return 0.0;
        }

        return $nutritionalInfo->getValue($type) ?? 0.0;
    }

    /**
     * Calculate nutritional value per 100g using rule of three
     *
     * @param Product $product
     * @param NutritionalValueType $type
     * @return float
     */
    public function getValuePer100g(Product $product, NutritionalValueType $type): float
    {
        $nutritionalInfo = $product->nutritionalInformation;

        if (!$nutritionalInfo || $nutritionalInfo->net_weight <= 0) {
            return 0.0;
        }

        $valuePerPortion = $this->getValuePerPortion($product, $type);
        $netWeight = $nutritionalInfo->net_weight;

        // Rule of three: (value * 100) / net_weight
        return ($valuePerPortion * 100) / $netWeight;
    }

    /**
     * Get all nutritional values for label display
     * Returns array with type, label, per100g, and perPortion values
     *
     * @param Product $product
     * @return array
     */
    public function getNutritionalValuesForLabel(Product $product): array
    {
        $types = [
            NutritionalValueType::CALORIES,
            NutritionalValueType::PROTEIN,
            NutritionalValueType::FAT_TOTAL,
            NutritionalValueType::FAT_SATURATED,
            NutritionalValueType::FAT_MONOUNSATURATED,
            NutritionalValueType::FAT_POLYUNSATURATED,
            NutritionalValueType::FAT_TRANS,
            NutritionalValueType::CHOLESTEROL,
            NutritionalValueType::CARBOHYDRATE,
            NutritionalValueType::FIBER,
            NutritionalValueType::SUGAR,
            NutritionalValueType::SODIUM,
        ];

        $values = [];
        foreach ($types as $type) {
            $label = $type->label();
            $unit = $type->unit();

            // Concatenate unit to label (e.g., "Calorías (kcal)", "Proteínas (g)")
            $labelWithUnit = $unit ? "{$label} ({$unit})" : $label;

            $values[] = [
                'type' => $type->value,
                'label' => $labelWithUnit,
                'unit' => $unit,
                'per100g' => round($this->getValuePer100g($product, $type), 1),
                'perPortion' => round($this->getValuePerPortion($product, $type), 1),
            ];
        }

        return $values;
    }

    /**
     * Get active "Alto en" flags for label display
     *
     * @param Product $product
     * @return array Array with flag names that are active
     */
    public function getActiveHighContentFlags(Product $product): array
    {
        $nutritionalInfo = $product->nutritionalInformation;

        if (!$nutritionalInfo) {
            return [];
        }

        $activeFlags = [];

        if ($nutritionalInfo->high_sodium) {
            $activeFlags[] = 'high_sodium';
        }

        if ($nutritionalInfo->high_calories) {
            $activeFlags[] = 'high_calories';
        }

        if ($nutritionalInfo->high_fat) {
            $activeFlags[] = 'high_fat';
        }

        if ($nutritionalInfo->high_sugar) {
            $activeFlags[] = 'high_sugar';
        }

        return $activeFlags;
    }

    /**
     * Get product gross weight for label display from nutritional_information table
     *
     * @param Product $product
     * @return string
     */
    public function getGrossWeight(Product $product): string
    {
        $nutritionalInfo = $product->nutritionalInformation;

        $weight = $nutritionalInfo->gross_weight ?? 0;
        $unit = strtolower($nutritionalInfo->measure_unit ?? 'g');

        return "{$weight} {$unit}.";
    }

    /**
     * Get product ingredients
     *
     * @param Product $product
     * @return string
     */
    public function getIngredients(Product $product): string
    {
        $nutritionalInfo = $product->nutritionalInformation;

        return $nutritionalInfo?->ingredients ?? 'No especificado';
    }

    /**
     * Get product allergens
     *
     * @param Product $product
     * @return string
     */
    public function getAllergens(Product $product): string
    {
        $nutritionalInfo = $product->nutritionalInformation;

        return $nutritionalInfo?->allergens ?? 'No especificado';
    }

    /**
     * Get product barcode from nutritional_information table
     *
     * @param Product $product
     * @return string|null
     */
    public function getBarcode(Product $product): ?string
    {
        $nutritionalInfo = $product->nutritionalInformation;

        return $nutritionalInfo?->barcode;
    }

    /**
     * Calculate expiration date based on elaboration date and shelf life
     *
     * @param Product $product
     * @param string $elaborationDate Date in format d/m/Y
     * @return string Expiration date in format d/m/Y
     */
    public function getExpirationDate(Product $product, string $elaborationDate): string
    {
        $nutritionalInfo = $product->nutritionalInformation;

        if (!$nutritionalInfo || !$nutritionalInfo->shelf_life_days) {
            return $elaborationDate;
        }

        try {
            $elaborationDateTime = \DateTime::createFromFormat('d/m/Y', $elaborationDate);
            if (!$elaborationDateTime) {
                return $elaborationDate;
            }

            $elaborationDateTime->modify("+{$nutritionalInfo->shelf_life_days} days");
            return $elaborationDateTime->format('d/m/Y');
        } catch (\Exception $e) {
            \Log::error("Error calculating expiration date", [
                'elaboration_date' => $elaborationDate,
                'shelf_life_days' => $nutritionalInfo->shelf_life_days,
                'error' => $e->getMessage()
            ]);
            return $elaborationDate;
        }
    }

    /**
     * Get products for label generation
     * Filters by product IDs and generate_label flag
     *
     * @param array $productIds
     * @param array $quantities Array with structure [product_id => quantity]. If empty, returns one instance per product.
     * @return \Illuminate\Support\Collection
     */
    public function getProductsForLabelGeneration(array $productIds, array $quantities = []): \Illuminate\Support\Collection
    {
        $products = Product::with(['nutritionalInformation.nutritionalValues', 'productionAreas'])
            ->whereIn('id', $productIds)
            ->whereHas('nutritionalInformation', function ($query) {
                $query->where('generate_label', true);
            })
            ->get();

        // If no quantities specified, return products as-is (one per product)
        if (empty($quantities)) {
            return $products;
        }

        // Repeat products according to quantities
        $repeatedProducts = collect();
        foreach ($products as $product) {
            $quantity = $quantities[$product->id] ?? 1;
            for ($i = 0; $i < $quantity; $i++) {
                $repeatedProducts->push(clone $product);
            }
        }

        return $repeatedProducts;
    }

    /**
     * Calculate total number of labels that will be generated
     * Filters products by generate_label flag and sums their quantities
     *
     * @param array $productIds Array of product IDs
     * @param array $quantities Array with structure [product_id => quantity]
     * @return int Total number of labels that will be generated
     */
    public function calculateTotalLabelsToGenerate(array $productIds, array $quantities): int
    {
        // Get only products with nutritional information and generate_label = true
        $validProductIds = Product::whereIn('id', $productIds)
            ->whereHas('nutritionalInformation', function ($query) {
                $query->where('generate_label', true);
            })
            ->pluck('id')
            ->toArray();

        // Sum quantities only for valid products
        $totalLabels = 0;
        foreach ($validProductIds as $productId) {
            $totalLabels += $quantities[$productId] ?? 0;
        }

        return $totalLabels;
    }
}
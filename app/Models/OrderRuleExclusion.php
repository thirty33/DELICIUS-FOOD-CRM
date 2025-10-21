<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * OrderRuleExclusion Model
 *
 * Defines exclusion rules between categories and/or subcategories using polymorphic relationships.
 *
 * Examples:
 * - Subcategory → Subcategory: "ENTRADA" cannot be combined with "ENTRADA"
 * - Subcategory → Category: "PLATO DE FONDO" cannot be combined with "Postres" category
 * - Category → Subcategory: "Ensaladas" category cannot be combined with "SANDWICH"
 * - Category → Category: "Bebidas" cannot be combined with "Postres"
 *
 * Relationships:
 * - belongsTo OrderRule
 * - morphTo source (Category or Subcategory)
 * - morphTo excluded (Category or Subcategory)
 */
class OrderRuleExclusion extends Model
{
    protected $fillable = [
        'order_rule_id',
        'source_id',
        'source_type',
        'excluded_id',
        'excluded_type',
    ];

    protected $casts = [
        'order_rule_id' => 'integer',
        'source_id' => 'integer',
        'excluded_id' => 'integer',
    ];

    /**
     * Get the order rule that owns this exclusion.
     */
    public function orderRule(): BelongsTo
    {
        return $this->belongsTo(OrderRule::class);
    }

    /**
     * Get the source entity (Category or Subcategory).
     *
     * This is the element that has the restriction.
     * Polymorphic relationship - can be:
     * - App\Models\Category
     * - App\Models\Subcategory
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the excluded entity (Category or Subcategory).
     *
     * This is the element that cannot be combined with the source.
     * Polymorphic relationship - can be:
     * - App\Models\Category
     * - App\Models\Subcategory
     */
    public function excluded(): MorphTo
    {
        return $this->morphTo();
    }

    // ========== HELPER METHODS ==========

    /**
     * Get the name of the source entity.
     */
    public function getSourceName(): string
    {
        return $this->source?->name ?? 'N/A';
    }

    /**
     * Get the name of the excluded entity.
     */
    public function getExcludedName(): string
    {
        return $this->excluded?->name ?? 'N/A';
    }

    /**
     * Get the type label for the source (e.g., "Categoría" or "Subcategoría").
     */
    public function getSourceTypeLabel(): string
    {
        return $this->source_type === Category::class ? 'Categoría' : 'Subcategoría';
    }

    /**
     * Get the type label for the excluded entity.
     */
    public function getExcludedTypeLabel(): string
    {
        return $this->excluded_type === Category::class ? 'Categoría' : 'Subcategoría';
    }

    /**
     * Check if source is a Category.
     */
    public function isSourceCategory(): bool
    {
        return $this->source_type === Category::class;
    }

    /**
     * Check if source is a Subcategory.
     */
    public function isSourceSubcategory(): bool
    {
        return $this->source_type === Subcategory::class;
    }

    /**
     * Check if excluded is a Category.
     */
    public function isExcludedCategory(): bool
    {
        return $this->excluded_type === Category::class;
    }

    /**
     * Check if excluded is a Subcategory.
     */
    public function isExcludedSubcategory(): bool
    {
        return $this->excluded_type === Subcategory::class;
    }

    /**
     * Get a human-readable description of the exclusion rule.
     */
    public function getDescription(): string
    {
        return sprintf(
            '%s "%s" no puede combinarse con %s "%s"',
            $this->getSourceTypeLabel(),
            $this->getSourceName(),
            $this->getExcludedTypeLabel(),
            $this->getExcludedName()
        );
    }

    /**
     * Check if a product matches the source of this exclusion.
     *
     * @param array $product Product data with 'category' and 'subcategories'
     * @return bool
     */
    public function productMatchesSource(array $product): bool
    {
        if ($this->isSourceCategory()) {
            // Source is a Category - check product's category
            return $product['category']->id === $this->source_id;
        }

        if ($this->isSourceSubcategory()) {
            // Source is a Subcategory - check product's subcategories
            $sourceName = $this->source->name;
            return in_array($sourceName, $product['subcategories']);
        }

        return false;
    }

    /**
     * Check if a product matches the excluded entity of this exclusion.
     *
     * @param array $product Product data with 'category' and 'subcategories'
     * @return bool
     */
    public function productMatchesExcluded(array $product): bool
    {
        if ($this->isExcludedCategory()) {
            // Excluded is a Category - check product's category
            return $product['category']->id === $this->excluded_id;
        }

        if ($this->isExcludedSubcategory()) {
            // Excluded is a Subcategory - check product's subcategories
            $excludedName = $this->excluded->name;
            return in_array($excludedName, $product['subcategories']);
        }

        return false;
    }
}

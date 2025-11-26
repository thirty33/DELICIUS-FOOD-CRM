<?php

namespace App\Contracts\Labels;

use Illuminate\Support\Collection;

/**
 * Interface for label generators
 *
 * Defines the contract for generating PDF labels from products
 */
interface LabelGeneratorInterface
{
    /**
     * Generate PDF labels from a collection of products
     *
     * @param Collection $products Collection of Product models
     * @return string PDF binary content
     */
    public function generate(Collection $products): string;

    /**
     * Set elaboration date for labels
     *
     * @param string $elaborationDate Date in format d/m/Y
     * @return void
     */
    public function setElaborationDate(string $elaborationDate): void;
}
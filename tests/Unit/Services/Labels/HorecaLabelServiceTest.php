<?php

namespace Tests\Unit\Services\Labels;

use App\Contracts\HorecaLabelDataRepositoryInterface;
use App\Repositories\AdvanceOrderRepository;
use App\Services\Labels\HorecaLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Unit tests for HorecaLabelService
 *
 * Tests the label expansion logic that converts grouped label data
 * into individual label entries for PDF generation.
 */
class HorecaLabelServiceTest extends TestCase
{
    use RefreshDatabase;

    private HorecaLabelService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HorecaLabelService(
            $this->createMock(HorecaLabelDataRepositoryInterface::class),
            $this->createMock(AdvanceOrderRepository::class)
        );
    }

    /**
     * TDD RED PHASE: Test that shelf_life is preserved when expanding labels
     *
     * BUG DESCRIPTION:
     * Products are imported with shelf_life of 3 days and the system shows 3 days correctly,
     * but when generating labels, the expiration date equals the elaboration date.
     *
     * Example: elaboration 18-12, expiration 18-12 (should be 21-12 with 3 days shelf_life)
     *
     * ROOT CAUSE:
     * The expandLabelsWithWeights() method does NOT include shelf_life in the expanded array.
     * This causes HorecaInformationRepository::getExpirationDate() to receive null for shelf_life,
     * which returns the elaboration date as expiration date.
     *
     * CODE LOCATION:
     * - Bug: app/Services/Labels/HorecaLabelService.php::expandLabelsWithWeights() line 134-144
     * - Missing: 'shelf_life' => $item['shelf_life'] in the expanded array
     */
    public function test_expand_labels_preserves_shelf_life(): void
    {
        $labelData = collect([
            [
                'ingredient_name' => 'MZC - CONSOME DE POLLO GRANEL',
                'ingredient_product_code' => 'MZC',
                'grouper_name' => 'Sucursal Centro',
                'branch_fantasy_name' => 'Sucursal Centro',
                'product_id' => 1,
                'product_name' => 'HORECA BOWL COMPLETO',
                'measure_unit' => 'GR',
                'max_quantity_horeca' => 1000,
                'shelf_life' => 3,
                'total_quantity_needed' => 600,
                'labels_count' => 1,
                'weights' => [600],
            ],
        ]);

        $method = new ReflectionMethod(HorecaLabelService::class, 'expandLabelsWithWeights');
        $method->setAccessible(true);
        $expandedLabels = $method->invoke($this->service, $labelData);

        $this->assertCount(1, $expandedLabels, 'Should have 1 expanded label');

        $expandedLabel = $expandedLabels->first();

        // TDD RED: This assertion will FAIL because shelf_life is not passed
        $this->assertArrayHasKey(
            'shelf_life',
            $expandedLabel,
            'Expanded label MUST contain shelf_life key for expiration date calculation'
        );

        $this->assertEquals(
            3,
            $expandedLabel['shelf_life'],
            'Expanded label shelf_life should be 3 days (same as input)'
        );
    }
}

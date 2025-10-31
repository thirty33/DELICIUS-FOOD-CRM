<?php

namespace App\Filament\Resources\AdvanceOrderResource\Pages;

use App\Filament\Resources\AdvanceOrderResource;
use App\Repositories\AdvanceOrderProductRepository;
use App\Repositories\AdvanceOrderRepository;
use App\Repositories\OrderRepository;
use Filament\Resources\Pages\CreateRecord;

class CreateAdvanceOrder extends CreateRecord
{
    protected static string $resource = AdvanceOrderResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function afterCreate(): void
    {
        $advanceOrder = $this->record;

        // Only load products if use_products_in_orders is true
        if ($advanceOrder->use_products_in_orders) {
            $orderRepository = new OrderRepository();
            $advanceOrderProductRepository = new AdvanceOrderProductRepository();
            $advanceOrderRepository = new AdvanceOrderRepository();

            // Get products from orders in the date range with quantities
            $productsData = $orderRepository->getProductsFromOrdersInDateRange(
                $advanceOrder->initial_dispatch_date->format('Y-m-d'),
                $advanceOrder->final_dispatch_date->format('Y-m-d')
            );

            // Associate products with calculated ordered_quantity_new
            $advanceOrderProductRepository->associateProductsWithDefaultQuantity(
                $advanceOrder,
                $productsData,
                $advanceOrderRepository
            );
        }
    }
}

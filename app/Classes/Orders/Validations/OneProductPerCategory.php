<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use Carbon\Carbon;
use Exception;

class OneProductPerCategory extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {

        if (UserPermissions::IsAgreement($user)) {

            // Get all the categories of the products in the order
            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'category_id' => $orderLine->product->category->id,
                    'category_name' => $orderLine->product->category->name,
                    'product_name' => $orderLine->product->name,
                ];
            });

            // Group the products by category
            $groupedByCategory = $categoriesInOrder->groupBy('category_id');

            // Check that there is no more than one product per category
            foreach ($groupedByCategory as $categoryId => $products) {
                if ($products->count() > 1) {
                    $categoryName = $products->first()['category_name'];
                    $productNames = $products->pluck('product_name')->implode(', ');
                    throw new Exception("Solo se permite un producto por categoría. Categoría: {$categoryName}. Productos: {$productNames}.");
                }
            }
            
        }
    }
}

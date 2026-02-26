<?php

namespace App\Actions\Products;

use App\Actions\Contracts\UpdateAction;
use App\Models\CategoryMenu;
use Illuminate\Support\Facades\DB;

final class ApplyDisplayOrderToPivotAction implements UpdateAction
{
    /**
     * Apply product.display_order to category_menu_product.display_order
     * for all products in the given CategoryMenu.
     *
     * @param  array  $data  ['category_menu_id' => int]
     */
    public static function execute(array $data = []): CategoryMenu
    {
        $categoryMenu = CategoryMenu::with('products')->findOrFail(data_get($data, 'category_menu_id'));

        foreach ($categoryMenu->products as $product) {
            DB::table('category_menu_product')
                ->where('category_menu_id', $categoryMenu->id)
                ->where('product_id', $product->id)
                ->update(['display_order' => $product->display_order]);
        }

        return $categoryMenu;
    }
}

<?php

namespace App\Actions\Products;

use App\Actions\Contracts\UpdateAction;
use App\Models\Menu;

final class MarkMenuProductsOrderedAction implements UpdateAction
{
    /**
     * Mark a menu as having its products ordered (display_order applied).
     *
     * @param  array  $data  ['menu_id' => int]
     */
    public static function execute(array $data = []): Menu
    {
        $menu = Menu::findOrFail(data_get($data, 'menu_id'));

        $menu->update(['products_ordered' => true]);

        return $menu;
    }
}

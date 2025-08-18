<?php

namespace App\Services;

use App\Models\Menu;

class MenuCloneService
{
    public static function cloneMenu(Menu $originalMenu, array $newData): Menu
    {
        // Verificar si ya existe un menú con la misma combinación
        $existingMenu = Menu::where('publication_date', $newData['publication_date'])
            ->where('role_id', $originalMenu->role_id)
            ->where('permissions_id', $originalMenu->permissions_id)
            ->first();
        
        if ($existingMenu) {
            throw new \Exception('Ya existe este menú con la misma fecha de despacho, tipo de usuario y tipo de convenio.');
        }
        
        $newMenu = Menu::create([
            'title' => $newData['title'],
            'publication_date' => $newData['publication_date'],
            'max_order_date' => $newData['max_order_date'],
            'description' => $originalMenu->description,
            'role_id' => $originalMenu->role_id,
            'permissions_id' => $originalMenu->permissions_id,
            'active' => $originalMenu->active,
        ]);

        $categoryMenus = $originalMenu->categoryMenus;
        foreach ($categoryMenus as $categoryMenu) {
            $newMenu->categoryMenus()->create([
                'category_id' => $categoryMenu->category_id,
                'show_all_products' => $categoryMenu->show_all_products,
                'display_order' => $categoryMenu->display_order,
                'mandatory_category' => $categoryMenu->mandatory_category,
                'is_active' => $categoryMenu->is_active,
            ]);
            
            if ($categoryMenu->products->isNotEmpty()) {
                $productIds = $categoryMenu->products->pluck('id')->toArray();
                $newCategoryMenu = $newMenu->categoryMenus()
                    ->where('category_id', $categoryMenu->category_id)
                    ->first();
                $newCategoryMenu->products()->attach($productIds);
            }
        }

        return $newMenu;
    }
}
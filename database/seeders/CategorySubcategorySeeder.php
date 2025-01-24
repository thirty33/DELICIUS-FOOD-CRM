<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\CategorySubcategory;

class CategorySubcategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear las subcategorías
        $subcategories = [
            'PLATO DE FONDO',
            'SANDWICH',
            'PAN',
            'ENSALADA',
            'MINI-ENSALADA'
        ];

        foreach ($subcategories as $subcategoryName) {
            Subcategory::firstOrCreate(['name' => $subcategoryName]);
        }

        // Buscar las categorías existentes
        $categories = [
            ['name' => 'Appetizers', 'description' => 'Start your meal with our delicious appetizers'],
            ['name' => 'Salads', 'description' => 'Fresh and healthy salads'],
            ['name' => 'Soups', 'description' => 'Warm and comforting soups'],
            ['name' => 'Sandwiches', 'description' => 'Tasty and filling sandwiches'],
            ['name' => 'Burgers', 'description' => 'Juicy and flavorful burgers'],
        ];

        foreach ($categories as $categoryData) {
            $category = Category::where('name', $categoryData['name'])->first();

            if ($category) {
                // Asociar subcategorías con categorías
                switch ($categoryData['name']) {
                    case 'Appetizers':
                        $this->associateSubcategories($category, ['PLATO DE FONDO', 'PAN']);
                        break;
                    case 'Salads':
                        $this->associateSubcategories($category, ['ENSALADA', 'MINI-ENSALADA']);
                        break;
                    case 'Soups':
                        $this->associateSubcategories($category, ['PLATO DE FONDO']);
                        break;
                    case 'Sandwiches':
                        $this->associateSubcategories($category, ['SANDWICH']);
                        break;
                    case 'Burgers':
                        $this->associateSubcategories($category, ['SANDWICH']);
                        break;
                }
            }
        }
    }

    /**
     * Asociar subcategorías a una categoría.
     *
     * @param Category $category
     * @param array $subcategoryNames
     */
    private function associateSubcategories(Category $category, array $subcategoryNames): void
    {
        foreach ($subcategoryNames as $subcategoryName) {
            $subcategory = Subcategory::where('name', $subcategoryName)->first();
    
            if ($subcategory && !$category->subcategories()->where('subcategory_id', $subcategory->id)->exists()) {
                $category->subcategories()->attach($subcategory->id);
            }
        }
    }
}
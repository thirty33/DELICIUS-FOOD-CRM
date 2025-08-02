<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\CategorySubcategory;
use Illuminate\Support\Facades\DB;
use App\Enums\Subcategory as SubcategoryEnum;

class CategorySubcategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('category_subcategory')->delete();
        
        Subcategory::query()->delete();

        foreach (SubcategoryEnum::cases() as $subcategory) {
            Subcategory::create(['name' => $subcategory->value]);
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
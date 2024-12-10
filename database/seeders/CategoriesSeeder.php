<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;

class CategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
    */
    public function run(): void
    {

        $categories = [
            ['name' => 'Appetizers', 'description' => 'Start your meal with our delicious appetizers'],
            ['name' => 'Salads', 'description' => 'Fresh and healthy salads'],
            ['name' => 'Soups', 'description' => 'Warm and comforting soups'],
            ['name' => 'Sandwiches', 'description' => 'Tasty and filling sandwiches'],
            ['name' => 'Burgers', 'description' => 'Juicy and flavorful burgers'],
            ['name' => 'Pizzas', 'description' => 'Hot and cheesy pizzas'],
            ['name' => 'Pasta', 'description' => 'Classic and delicious pasta dishes'],
            ['name' => 'Seafood', 'description' => 'Fresh and tasty seafood'],
            ['name' => 'Steaks', 'description' => 'Tender and juicy steaks'],
            ['name' => 'Chicken', 'description' => 'Delicious chicken dishes'],
            ['name' => 'Vegetarian', 'description' => 'Healthy and tasty vegetarian options'],
            ['name' => 'Vegan', 'description' => 'Delicious vegan dishes'],
            ['name' => 'Gluten-Free', 'description' => 'Tasty gluten-free options'],
            ['name' => 'Desserts', 'description' => 'Sweet and delicious desserts'],
            ['name' => 'Beverages', 'description' => 'Refreshing beverages'],
            ['name' => 'Breakfast', 'description' => 'Start your day with our delicious breakfast options'],
            ['name' => 'Brunch', 'description' => 'Enjoy a relaxing brunch'],
            ['name' => 'Lunch', 'description' => 'Tasty lunch options'],
            ['name' => 'Dinner', 'description' => 'Delicious dinner options'],
            ['name' => 'Kids Menu', 'description' => 'Tasty options for kids'],
            ['name' => 'Sides', 'description' => 'Perfect sides to complement your meal'],
            ['name' => 'Sauces', 'description' => 'Delicious sauces to enhance your meal'],
            ['name' => 'Specials', 'description' => 'Our daily specials'],
            ['name' => 'Seasonal', 'description' => 'Seasonal dishes'],
            ['name' => 'Grill', 'description' => 'Grilled to perfection'],
            ['name' => 'BBQ', 'description' => 'Delicious BBQ dishes'],
            ['name' => 'Asian', 'description' => 'Tasty Asian cuisine'],
            ['name' => 'Mexican', 'description' => 'Delicious Mexican dishes'],
            ['name' => 'Italian', 'description' => 'Classic Italian cuisine'],
            ['name' => 'Indian', 'description' => 'Flavorful Indian dishes'],
            ['name' => 'Mediterranean', 'description' => 'Healthy Mediterranean cuisine'],
        ];

        foreach ($categories as $categoryData) {
            $category = Category::firstOrCreate(['name' => $categoryData['name']], $categoryData);
        }
        
    }
}

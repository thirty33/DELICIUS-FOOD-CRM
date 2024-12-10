<?php

namespace Database\Seeders;

use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PriceListLinesSeeder extends Seeder
{
    private $categories = [
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

    // private $products = [
    //     ['name' => 'Appetizers Product 1'],
    //     ['name' => 'Salads Product 1'],
    //     ['name' => 'Salads Product 2'],
    //     ['name' => 'Salads Product 3'],
    //     ['name' => 'Salads Product 4'], 
    //     ['name' => 'Salads Product 5'],
    //     ['name' => 'Salads Product 6'],
    //     ['name' => 'Soups Product 1'],
    //     ['name' => 'Desserts Product 1'],
    //     ['name' => 'Desserts Product 2'],
    //     ['name' => 'Desserts Product 3'],
    //     ['name' => 'Desserts Product 4'],
    //     ['name' => 'Desserts Product 5'],
    //     ['name' => 'Desserts Product 6'],
    //     ['name' => 'Desserts Product 7'],
    //     ['name' => 'Brunch Product 1'],
    //     ['name' => 'Brunch Product 2'],
    //     ['name' => 'Brunch Product 3'],
    //     ['name' => 'Brunch Product 4'],
    //     ['name' => 'Brunch Product 5'],
    //     ['name' => 'Brunch Product 6'],
    //     ['name' => 'Brunch Product 7'],
    //     ['name' => 'Breakfast Product 1'],
    //     ['name' => 'Breakfast Product 2'],
    //     ['name' => 'Breakfast Product 3'],
    //     ['name' => 'Breakfast Product 4'],
    //     ['name' => 'BBQ Product 1'],
    //     ['name' => 'BBQ Product 2'],
    //     ['name' => 'BBQ Product 3'],
    //     ['name' => 'BBQ Product 4'],
    //     ['name' => 'BBQ Product 5'],
    //     ['name' => 'Desserts Product 1'],
    //     ['name' => 'Asian Product 1'],
    //     ['name' => 'Beverages Product 1'],
    //     ['name' => 'Dinner Product 1'],
    //     ['name' => 'Sandwiches Product 1'],
    //     ['name' => 'Mexican Product 1'],
    //     ['name' => 'Grill Product 1'],
    //     ['name' => 'Indian Product 1'],
    //     ['name' => 'Vegan Product 1'],
    //     ['name' => 'Specials Product 1'],
    //     ['name' => 'Vegetarian Product 1'],
    //     ['name' => 'Seafood Product 1'],
    // ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $priceListOne = PriceList::where('name', 'Price List 1')->first();
        $priceListTwo = PriceList::where('name', 'Price List 2')->first();

        $products = [];

        foreach ($this->categories as $category) {
            $products[] = [
                'name' => $category['name'] . ' Product 1',
            ];
            $products[] = [
                'name' => $category['name'] . ' Product 2',
            ];
        }

        foreach ($products as $product) {

            $productItem = Product::where('name', $product['name'])->first();

            PriceListLine::firstOrCreate([
                'price_list_id' => $priceListOne->id,
                'product_id' => $productItem->id
            ], [
                'unit_price' => rand(1000, 10000)
            ]);

            PriceListLine::firstOrCreate([
                'price_list_id' => $priceListTwo->id,
                'product_id' => $productItem->id
            ], [
                'unit_price' => rand(1000, 10000)
            ]);
        }
    }
}

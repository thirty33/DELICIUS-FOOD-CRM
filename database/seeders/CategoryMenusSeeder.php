<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\CategoryMenu;
use App\Models\Category;
use Carbon\Carbon;

class CategoryMenusSeeder extends Seeder
{   

    private function getRandomCategories($categories)
    {
        $numCategories = rand(3, 7);
        $randomCategoriesKeys = array_rand($categories, $numCategories);
        $randomCategories = array_intersect_key($categories, array_flip($randomCategoriesKeys));

        return $randomCategories;
    }

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

        $today = Carbon::today();
        $adminRole = Role::where('name', 'Admin')->first()->id;
        $cafeRole = Role::where('name', 'Café')->first()->id;
        $convenioRole = Role::where('name', 'Convenio')->first()->id;

        $consolidadoPermission = Permission::where('name', 'Consolidado')->first()->id;
        $individualPermission = Permission::where('name', 'Individual')->first()->id;
        
        $menuConfigurations = [
            // Admin Role Menus (30 days)
            ...array_map(function($day) use ($adminRole, $categories, $today) {
                $date = $today->copy()->addDays($day);
                return [
                    'title' => 'Admin Menu',
                    'role' => $adminRole,
                    'permission' => null,
                    'categories' => $this->getRandomCategories($categories),
                    'publication_date' => $date,
                    'max_order_date' => $date->copy()->subDays(3),
                ];
            }, range(0, 29)),
        
            // Cafe Consolidado Role Menus (30 days)
            ...array_map(function($day) use ($cafeRole, $categories, $today, $consolidadoPermission) {
                $date = $today->copy()->addDays($day);
                return [
                    'title' => 'Café Consolidado Menu',
                    'role' => $cafeRole,
                    'permission' => $consolidadoPermission,
                    'categories' => $this->getRandomCategories($categories),
                    'publication_date' => $date,
                    'max_order_date' => $date->copy()->subDays(3),
                ];
            }, range(0, 29)),

            // Cafe Individual Role Menus (30 days)
            ...array_map(function($day) use ($cafeRole, $categories, $today, $individualPermission) {
                $date = $today->copy()->addDays($day);
                return [
                    'title' => 'Café Individual Menu',
                    'role' => $cafeRole,
                    'permission' => $individualPermission,
                    'categories' => $this->getRandomCategories($categories),
                    'publication_date' => $date,
                    'max_order_date' => $date->copy()->subDays(3),
                ];
            }, range(0, 29)),
        
            // Convenio Consolidado Menus (30 days)
            ...array_map(function($day) use ($convenioRole, $consolidadoPermission, $categories, $today) {
                $date = $today->copy()->addDays($day);
                return [
                    'title' => 'Convenio Consolidado Menu',
                    'role' => $convenioRole,
                    'permission' => $consolidadoPermission,
                    'categories' => $this->getRandomCategories($categories),
                    'publication_date' => $date,
                    'max_order_date' => $date->copy()->subDays(3),
                ];
            }, range(0, 29)),
        
            // Convenio Individual Menus (30 days)
            ...array_map(function($day) use ($convenioRole, $individualPermission, $categories, $today) {
                $date = $today->copy()->addDays($day);
                return [
                    'title' => 'Convenio Individual Menu',
                    'role' => $convenioRole,
                    'permission' => $individualPermission,
                    'categories' => $this->getRandomCategories($categories),
                    'publication_date' => $date,
                    'max_order_date' => $date->copy()->subDays(3),
                ];
            }, range(0, 29))
        ];

        foreach ($menuConfigurations as $menuConfiguration) {
            $menu = Menu::firstOrCreate([
                'title' => $menuConfiguration['title'],
                'role_id' => $menuConfiguration['role'],
                'permissions_id' => $menuConfiguration['permission'],
                'publication_date' => $menuConfiguration['publication_date'],
                'max_order_date' => $menuConfiguration['max_order_date'],
            ], [
                'description' => 'Description for ' . $menuConfiguration['title'],
                'active' => true,
            ]);

            foreach ($menuConfiguration['categories'] as $categoryData) {
                $category = Category::firstOrCreate([
                    'name' => $categoryData['name'],
                ], [
                    'description' => $categoryData['description'],
                ]);

                $isMandatoryCategory = $menuConfiguration['role'] === $cafeRole && $menuConfiguration['permission'] === $individualPermission;

                $data = [
                    'display_order' => rand(1, 100),
                    'show_all_products' => rand(0, 1),
                    'mandatory_category' => $isMandatoryCategory,
                ];

                $categoryMenu = CategoryMenu::firstOrCreate([
                    'category_id' => $category->id,
                    'menu_id' => $menu->id,
                ], $data);

                if(!$data['show_all_products']) {
                    $products = $category->products;
                    foreach ($products as $product) {
                        $categoryMenu->products()->attach($product);
                    }
                }
            }
        }
    }
}
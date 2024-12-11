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

        $adminRole = Role::where('name', 'Admin')->first()->id;
        $cafeRole = Role::where('name', 'Café')->first()->id;
        $convenioRole = Role::where('name', 'Convenio')->first()->id;

        $consolidadoPermission = Permission::where('name', 'Consolidado')->first()->id;
        $individualPermission = Permission::where('name', 'Individual')->first()->id;


        // $menuConfigurations = [
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $adminRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 1),
        //         'max_order_date' => Carbon::create(2024, 12, 1)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $cafeRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 2),
        //         'max_order_date' => Carbon::create(2024, 12, 2)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Convenio Consolidado Menu 1',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 3),
        //         'max_order_date' => Carbon::create(2024, 12, 3)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Convenio Individual Menu 1',
        //         'role' => $convenioRole,
        //         'permission' => $individualPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 4),
        //         'max_order_date' => Carbon::create(2024, 12, 4)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 5),
        //         'max_order_date' => Carbon::create(2024, 12, 5)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 6),
        //         'max_order_date' => Carbon::create(2024, 12, 6)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 7),
        //         'max_order_date' => Carbon::create(2024, 12, 7)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 8),
        //         'max_order_date' => Carbon::create(2024, 12, 8)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $cafeRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 9),
        //         'max_order_date' => Carbon::create(2024, 12, 9)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 10),
        //         'max_order_date' => Carbon::create(2024, 12, 10)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $individualPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 11),
        //         'max_order_date' => Carbon::create(2024, 12, 11)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $cafeRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 12),
        //         'max_order_date' => Carbon::create(2024, 12, 12)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 13),
        //         'max_order_date' => Carbon::create(2024, 12, 13)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $cafeRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 14),
        //         'max_order_date' => Carbon::create(2024, 12, 14)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $adminRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 15),
        //         'max_order_date' => Carbon::create(2024, 12, 15)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 16),
        //         'max_order_date' => Carbon::create(2024, 12, 16)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $cafeRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 17),
        //         'max_order_date' => Carbon::create(2024, 12, 17)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $individualPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 18),
        //         'max_order_date' => Carbon::create(2024, 12, 18)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $cafeRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 19),
        //         'max_order_date' => Carbon::create(2024, 12, 19)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 20),
        //         'max_order_date' => Carbon::create(2024, 12, 20)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $cafeRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 21),
        //         'max_order_date' => Carbon::create(2024, 12, 21)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 22),
        //         'max_order_date' => Carbon::create(2024, 12, 22)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $individualPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 23),
        //         'max_order_date' => Carbon::create(2024, 12, 23)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 24),
        //         'max_order_date' => Carbon::create(2024, 12, 24)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $cafeRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 25),
        //         'max_order_date' => Carbon::create(2024, 12, 25)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $individualPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 26),
        //         'max_order_date' => Carbon::create(2024, 12, 26)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 27),
        //         'max_order_date' => Carbon::create(2024, 12, 27)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $cafeRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 28),
        //         'max_order_date' => Carbon::create(2024, 12, 28)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 29),
        //         'max_order_date' => Carbon::create(2024, 12, 29)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $cafeRole,
        //         'permission' => null,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 30),
        //         'max_order_date' => Carbon::create(2024, 12, 30)->subDays(3),
        //     ],
        //     [
        //         'title' => 'Daily Menu',
        //         'role' => $convenioRole,
        //         'permission' => $consolidadoPermission,
        //         'categories' => $this->getRandomCategories($categories),
        //         'publication_date' => Carbon::create(2024, 12, 31),
        //         'max_order_date' => Carbon::create(2024, 12, 31)->subDays(3),
        //     ],
        // ];

        $menuConfigurations = [
            // Admin Role Menus (31 days)
            ...array_map(function($day) use ($adminRole, $categories) {
                return [
                    'title' => 'Daily Menu',
                    'role' => $adminRole,
                    'permission' => null,
                    'categories' => $this->getRandomCategories($categories),
                    'publication_date' => Carbon::create(2024, 12, $day),
                    'max_order_date' => Carbon::create(2024, 12, $day)->subDays(3),
                ];
            }, range(1, 31)),
        
            // Cafe Role Menus (31 days)
            ...array_map(function($day) use ($cafeRole, $categories) {
                return [
                    'title' => 'Daily Menu',
                    'role' => $cafeRole,
                    'permission' => null,
                    'categories' => $this->getRandomCategories($categories),
                    'publication_date' => Carbon::create(2024, 12, $day),
                    'max_order_date' => Carbon::create(2024, 12, $day)->subDays(3),
                ];
            }, range(1, 31)),
        
            // Convenio Consolidado Menus (31 days)
            ...array_map(function($day) use ($convenioRole, $consolidadoPermission, $categories) {
                return [
                    'title' => 'Convenio Consolidado Menu',
                    'role' => $convenioRole,
                    'permission' => $consolidadoPermission,
                    'categories' => $this->getRandomCategories($categories),
                    'publication_date' => Carbon::create(2024, 12, $day),
                    'max_order_date' => Carbon::create(2024, 12, $day)->subDays(3),
                ];
            }, range(1, 31)),
        
            // Convenio Individual Menus (31 days)
            ...array_map(function($day) use ($convenioRole, $individualPermission, $categories) {
                return [
                    'title' => 'Convenio Individual Menu',
                    'role' => $convenioRole,
                    'permission' => $individualPermission,
                    'categories' => $this->getRandomCategories($categories),
                    'publication_date' => Carbon::create(2024, 12, $day),
                    'max_order_date' => Carbon::create(2024, 12, $day)->subDays(3),
                ];
            }, range(1, 31))
        ];

        foreach ($menuConfigurations as $menuConfiguration) {
            $menu = Menu::create([
                'title' => $menuConfiguration['title'],
                'description' => 'Description for ' . $menuConfiguration['title'],
                'active' => true,
                'publication_date' => $menuConfiguration['publication_date'],
                'max_order_date' => $menuConfiguration['max_order_date'],
                'role_id' => $menuConfiguration['role'],
                'permissions_id' => $menuConfiguration['permission'],
            ]);

            foreach ($menuConfiguration['categories'] as $categoryData) {
                $category = Category::where('name', $categoryData['name'])->first();

                $data = [
                    'display_order' => rand(1, 100),
                    'show_all_products' => rand(0, 1),
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

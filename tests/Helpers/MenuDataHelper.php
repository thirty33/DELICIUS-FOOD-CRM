<?php

namespace Tests\Helpers;

use App\Models\Menu;
use App\Models\Role;
use App\Models\Permission;

class MenuDataHelper
{
    /**
     * Get menu data based on the Excel data.
     *
     * @param Role $convenioRole
     * @param Permission $consolidadoPermission
     * @return array
     */
    public static function getMenuData(Role $convenioRole, Permission $consolidadoPermission): array
    {
        return [
            [
                'title' => 'Menú 14/03 - Convenio-Consolidado',
                'description' => 'Menú tradicional con opciones clásicas de la gastronomía chilena.',
                'publication_date' => '2025-05-20',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-05-14 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 17/03 - Convenio-Consolidado',
                'description' => 'Opciones vegetarianas y veganas para una alimentación consciente.',
                'publication_date' => '2025-05-21',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-05-17 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 18/03 - Convenio-Consolidado',
                'description' => 'Selección gourmet con ingredientes premium y presentaciones elegantes.',
                'publication_date' => '2025-05-22',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-05-17 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 19/03 - Convenio-Consolidado',
                'description' => 'Menú saludable con opciones bajas en calorías y alto valor nutricional.',
                'publication_date' => '2025-05-23',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-03-18 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 20/03 - Convenio-Consolidado',
                'description' => 'Selección de platos internacionales con influencias asiáticas y europeas.',
                'publication_date' => '2025-05-24',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-03-19 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 21/03 - Convenio-Consolidado',
                'description' => 'Menú tradicional con opciones clásicas de la gastronomía chilena.',
                'publication_date' => '2025-05-25',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-03-20 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 24/03 - Convenio-Consolidado',
                'description' => 'Opciones vegetarianas y veganas para una alimentación consciente.',
                'publication_date' => '2025-05-26',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-03-21 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 25/03 - Convenio-Consolidado',
                'description' => 'Selección gourmet con ingredientes premium y presentaciones elegantes.',
                'publication_date' => '2025-05-27',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-03-24 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 26/03 - Convenio-Consolidado',
                'description' => 'Menú saludable con opciones bajas en calorías y alto valor nutricional.',
                'publication_date' => '2025-05-28',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-03-25 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 27/03 - Convenio-Consolidado',
                'description' => 'Selección de platos internacionales con influencias asiáticas y europeas.',
                'publication_date' => '2025-05-29',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-03-26 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 28/03 - Convenio-Consolidado',
                'description' => 'Menú tradicional con opciones clásicas de la gastronomía chilena.',
                'publication_date' => '2025-05-30',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-03-27 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 31/03 - Convenio-Consolidado',
                'description' => 'Opciones vegetarianas y veganas para una alimentación consciente.',
                'publication_date' => '2025-05-31',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-03-28 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 02/04 - Convenio-Consolidado',
                'description' => 'Menú saludable con opciones bajas en calorías y alto valor nutricional.',
                'publication_date' => '2025-06-01',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-01 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 03/04 - Convenio-Consolidado',
                'description' => 'Selección de platos internacionales con influencias asiáticas y europeas.',
                'publication_date' => '2025-06-02',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-02 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 04/04 - Convenio-Consolidado',
                'description' => 'Menú tradicional con opciones clásicas de la gastronomía chilena.',
                'publication_date' => '2025-06-03',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-03 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 07/04 - Convenio-Consolidado',
                'description' => 'Opciones vegetarianas y veganas para una alimentación consciente.',
                'publication_date' => '2025-06-04',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-04 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 08/04 - Convenio-Consolidado',
                'description' => 'Selección gourmet con ingredientes premium y presentaciones elegantes.',
                'publication_date' => '2025-06-05',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-07 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 09/04 - Convenio-Consolidado',
                'description' => 'Menú saludable con opciones bajas en calorías y alto valor nutricional.',
                'publication_date' => '2025-06-06',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-08 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 10/04 - Convenio-Consolidado',
                'description' => 'Selección de platos internacionales con influencias asiáticas y europeas.',
                'publication_date' => '2025-06-07',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-09 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 11/04 - Convenio-Consolidado',
                'description' => 'Menú tradicional con opciones clásicas de la gastronomía chilena.',
                'publication_date' => '2025-06-08',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-10 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 14/04 - Convenio-Consolidado',
                'description' => 'Opciones vegetarianas y veganas para una alimentación consciente.',
                'publication_date' => '2025-06-09',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-11 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 15/04 - Convenio-Consolidado',
                'description' => 'Selección gourmet con ingredientes premium y presentaciones elegantes.',
                'publication_date' => '2025-06-10',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-14 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 26/04 - Convenio-Consolidado',
                'description' => 'Menú tradicional con opciones clásicas de la gastronomía chilena.',
                'publication_date' => '2025-06-11',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-26 12:00:00',
                'active' => true,
            ],
            [
                'title' => 'Menú 27/04 - Convenio-Consolidado',
                'description' => 'Opciones vegetarianas y veganas para una alimentación consciente.',
                'publication_date' => '2025-06-12',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-26 12:00:01',
                'active' => true,
            ],
            [
                'title' => 'Menú 28/04 - Convenio-Consolidado',
                'description' => 'Selección gourmet con ingredientes premium y presentaciones elegantes.',
                'publication_date' => '2025-06-13',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-26 12:00:02',
                'active' => true,
            ],
            [
                'title' => 'Menú 29/04 - Convenio-Consolidado',
                'description' => 'Menú saludable con opciones bajas en calorías y alto valor nutricional.',
                'publication_date' => '2025-06-14',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-26 12:00:03',
                'active' => true,
            ],
            [
                'title' => 'Menú 30/04 - Convenio-Consolidado',
                'description' => 'Selección de platos internacionales con influencias asiáticas y europeas.',
                'publication_date' => '2025-06-15',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-26 12:00:04',
                'active' => true,
            ],
            [
                'title' => 'Menú 02/05 - Convenio-Consolidado',
                'description' => 'Opciones vegetarianas y veganas para una alimentación consciente.',
                'publication_date' => '2025-06-16',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-26 12:00:06',
                'active' => true,
            ],
            [
                'title' => 'Menú 03/05 - Convenio-Consolidado',
                'description' => 'Selección gourmet con ingredientes premium y presentaciones elegantes.',
                'publication_date' => '2025-06-17',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-26 12:00:07',
                'active' => true,
            ],
            [
                'title' => 'Menú 04/05 - Convenio-Consolidado',
                'description' => 'Menú saludable con opciones bajas en calorías y alto valor nutricional.',
                'publication_date' => '2025-06-18',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-26 12:00:08',
                'active' => true,
            ],
            [
                'title' => 'Menú 05/05 - Convenio-Consolidado',
                'description' => 'Selección de platos internacionales con influencias asiáticas y europeas.',
                'publication_date' => '2025-06-19',
                'role_id' => $convenioRole->id,
                'permissions_id' => $consolidadoPermission->id,
                'max_order_date' => '2025-04-26 12:00:09',
                'active' => true,
            ],
        ];
    }

    /**
     * Create menus from data.
     *
     * @param Role $convenioRole
     * @param Permission $consolidadoPermission
     * @return array Returns an array of created menu IDs
     */
    public static function createMenus(Role $convenioRole, Permission $consolidadoPermission): array
    {
        $menuIds = [];
        $menusData = self::getMenuData($convenioRole, $consolidadoPermission);

        foreach ($menusData as $menuData) {
            $menu = Menu::create($menuData);
            $menuIds[] = $menu->id;
        }

        return $menuIds;
    }
}

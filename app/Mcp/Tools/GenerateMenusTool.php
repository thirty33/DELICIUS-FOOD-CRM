<?php

namespace App\Mcp\Tools;

use App\Classes\Menus\MenuHelper;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GenerateMenusTool extends Tool
{
    protected string $name = 'generate-menus';

    protected string $description = <<<'MARKDOWN'
        Creates menus in the database for the specified dates. Each menu gets a title, publication_date, role, permission, and max_order_date. Returns the list of created menu IDs. Available roles: Convenio, Café. Available permissions: Individual, Consolidado.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $dates = $request->get('dates', []);
        $roleName = $request->get('role');
        $permissionName = $request->get('permission');
        $titlePattern = $request->get('title_pattern', 'Menu {day_name} {date}');
        $description = $request->get('description');
        $maxOrderTime = $request->get('max_order_time', '18:00');
        $active = $request->get('active', true);

        if (empty($dates)) {
            return Response::error('The "dates" parameter is required and cannot be empty.');
        }

        if (empty($roleName) || empty($permissionName)) {
            return Response::error('Both "role" and "permission" parameters are required.');
        }

        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            return Response::error("Role \"{$roleName}\" not found. Available: Convenio, Café.");
        }

        $permission = Permission::where('name', $permissionName)->first();
        if (! $permission) {
            return Response::error("Permission \"{$permissionName}\" not found. Available: Individual, Consolidado.");
        }

        $timeParts = explode(':', $maxOrderTime);
        if (count($timeParts) !== 2) {
            return Response::error("Invalid max_order_time format: \"{$maxOrderTime}\". Use HH:MM.");
        }

        $dayNames = [
            'Monday' => 'Lunes',
            'Tuesday' => 'Martes',
            'Wednesday' => 'Miércoles',
            'Thursday' => 'Jueves',
            'Friday' => 'Viernes',
            'Saturday' => 'Sábado',
            'Sunday' => 'Domingo',
        ];

        $created = [];
        $skipped = [];

        foreach ($dates as $dateStr) {
            try {
                $date = Carbon::parse($dateStr);
            } catch (\Exception $e) {
                $skipped[] = "{$dateStr}: invalid date format";
                continue;
            }

            $maxOrderDate = $date->copy()->subDay()->setTimeFromTimeString($maxOrderTime . ':00');

            $dayName = $dayNames[$date->format('l')] ?? $date->format('l');
            $title = str_replace(
                ['{day_name}', '{date}'],
                [$dayName, $date->format('Y-m-d')],
                $titlePattern
            );

            $duplicate = MenuHelper::checkDuplicateMenuForImport(
                $date->format('Y-m-d'),
                $role->id,
                $permission->id,
                $active,
                $maxOrderDate->format('Y-m-d H:i:s'),
                []
            );

            if ($duplicate) {
                $skipped[] = "{$dateStr}: duplicate menu already exists for this date/role/permission combination";
                continue;
            }

            $menu = Menu::create([
                'title' => $title,
                'description' => $description,
                'publication_date' => $date->format('Y-m-d'),
                'role_id' => $role->id,
                'permissions_id' => $permission->id,
                'max_order_date' => $maxOrderDate->format('Y-m-d H:i:s'),
                'active' => $active,
            ]);

            $created[] = [
                'id' => $menu->id,
                'title' => $menu->title,
                'publication_date' => $menu->publication_date,
                'max_order_date' => $menu->max_order_date,
            ];
        }

        $lines = [];
        $lines[] = 'Created ' . count($created) . ' menus:';

        foreach ($created as $m) {
            $lines[] = "- ID {$m['id']}: {$m['title']} (publication: {$m['publication_date']}, max_order: {$m['max_order_date']})";
        }

        if (! empty($skipped)) {
            $lines[] = '';
            $lines[] = 'Skipped ' . count($skipped) . ':';
            foreach ($skipped as $s) {
                $lines[] = "- {$s}";
            }
        }

        $menuIds = array_column($created, 'id');
        $lines[] = '';
        $lines[] = 'Menu IDs: [' . implode(', ', $menuIds) . ']';

        return Response::text(implode("\n", $lines));
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'dates' => $schema->array()
                ->items($schema->string())
                ->description('Array of dates in YYYY-MM-DD format for which to create menus.')
                ->required(),
            'role' => $schema->string()
                ->description('Role name: "Convenio" or "Café".')
                ->required(),
            'permission' => $schema->string()
                ->description('Permission name: "Individual" or "Consolidado".')
                ->required(),
            'title_pattern' => $schema->string()
                ->description('Title pattern. Use {day_name} and {date} as placeholders. Default: "Menu {day_name} {date}".'),
            'description' => $schema->string()
                ->description('Description for all menus. Default: empty.'),
            'max_order_time' => $schema->string()
                ->description('Time HH:MM for max_order_date (set on the day before publication_date). Default: "18:00".'),
            'active' => $schema->boolean()
                ->description('Whether menus should be active. Default: true.'),
        ];
    }
}
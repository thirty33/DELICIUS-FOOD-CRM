<?php

namespace App\Mcp\Tools;

use App\Models\Menu;
use App\Models\Role;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListRecentMenusTool extends Tool
{
    protected string $name = 'list-recent-menus';

    protected string $description = <<<'MARKDOWN'
        Lists recent menus filtered by role and/or date range. Use this BEFORE generating new menus to check which dates already have menus and avoid duplicates. Returns menu ID, title, publication_date, role, permission, active status, and max_order_date.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $roleName = $request->get('role');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');
        $limit = (int) $request->get('limit', 20);

        $query = Menu::with(['rol', 'permission'])
            ->orderBy('publication_date', 'desc');

        if ($roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                return Response::error("Role \"{$roleName}\" not found. Available: Convenio, Café.");
            }
            $query->where('role_id', $role->id);
        }

        if ($dateFrom) {
            $query->where('publication_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('publication_date', '<=', $dateTo);
        }

        if ($dateFrom || $dateTo) {
            $query->orderBy('publication_date', 'asc');
        }

        $menus = $query->limit(min($limit, 100))->get();

        if ($menus->isEmpty()) {
            return Response::text('No menus found matching the criteria.');
        }

        $lines = [];
        $lines[] = "Found {$menus->count()} menus:";
        $lines[] = '';

        foreach ($menus as $menu) {
            $rolLabel = $menu->rol ? $menu->rol->name : 'N/A';
            $permLabel = $menu->permission ? $menu->permission->name : 'N/A';
            $active = $menu->active ? 'Yes' : 'No';

            $lines[] = "- ID {$menu->id}: {$menu->title}";
            $lines[] = "  publication_date: {$menu->publication_date} | role: {$rolLabel} | permission: {$permLabel} | active: {$active} | max_order: {$menu->max_order_date}";
        }

        return Response::text(implode("\n", $lines));
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'role' => $schema->string()
                ->description('Filter by role name: "Convenio" or "Café". If omitted, returns all roles.'),
            'date_from' => $schema->string()
                ->description('Filter menus with publication_date >= this date (YYYY-MM-DD).'),
            'date_to' => $schema->string()
                ->description('Filter menus with publication_date <= this date (YYYY-MM-DD).'),
            'limit' => $schema->integer()
                ->description('Max number of menus to return (default: 20, max: 100).'),
        ];
    }
}
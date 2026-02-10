<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ExportMenusTool;
use App\Mcp\Tools\GenerateMenusTool;
use App\Mcp\Tools\ListRecentMenusTool;
use Laravel\Mcp\Server;

class MenuServer extends Server
{
    protected string $name = 'DeliciusFood Menu Server';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        This server provides tools to generate menus for specific dates and export them as Excel files compatible with the import system.
    MARKDOWN;

    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    protected array $tools = [
        ListRecentMenusTool::class,
        GenerateMenusTool::class,
        ExportMenusTool::class,
    ];
}

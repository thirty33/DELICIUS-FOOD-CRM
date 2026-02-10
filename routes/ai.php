<?php

use App\Mcp\Servers\MenuServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('menus', MenuServer::class);
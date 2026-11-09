<?php

use App\Mcp\Servers\Ledgerly;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('mcp/ledgerly', Ledgerly::class)->middleware([
    'auth:sanctum',
    'throttle:ai',
]);

Mcp::local('ledgerly', Ledgerly::class);

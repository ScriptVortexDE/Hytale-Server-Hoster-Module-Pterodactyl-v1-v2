<?php

namespace Pterodactyl\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminAuthenticate
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless((bool) $request->user()?->root_admin, 403);

        return $next($request);
    }
}

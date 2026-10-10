<?php

namespace Pterodactyl\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireTwoFactorAuthentication
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}

<?php

namespace HytaleHosting\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Restricts the module's admin pages to root administrators.
 */
class RequireRootAdmin
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless((bool) $request->user()?->root_admin, 403);

        return $next($request);
    }
}

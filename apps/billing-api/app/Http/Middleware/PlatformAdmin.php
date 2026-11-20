<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PlatformAdmin
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless($r->user()?->is_admin, 403, 'Platform administrator required.');

        return $next($r);
    }
}

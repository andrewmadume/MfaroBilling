<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->isProduction() && ! $request->secure()) {
            abort(400, 'HTTPS is required.');
        }
        $r = $next($request);
        $r->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('X-Frame-Options', 'DENY');
        $r->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if ($request->secure()) {
            $r->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

return $r;
    }
}

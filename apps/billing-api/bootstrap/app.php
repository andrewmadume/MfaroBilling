<?php

use App\Http\Middleware\PlatformAdmin;
use App\Http\Middleware\ProductClient;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->validateCsrfTokens(except: ['api/v1/webhooks/payfast', 'api/v1/access/*', 'api/v1/auth/backchannel-logout']);
        $middleware->alias(['tenant' => TenantContext::class, 'platform.admin' => PlatformAdmin::class, 'product.client' => ProductClient::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r, Throwable $e) => $r->is('api/*') || $r->expectsJson());
        $exceptions->render(function (AuthenticationException $e, Request $r) {
            return response()->json(['message' => 'Authentication required.', 'code' => 'unauthenticated'], 401);
        });
    })->create();

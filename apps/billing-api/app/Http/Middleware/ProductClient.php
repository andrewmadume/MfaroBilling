<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductClient
{
    public function handle(Request $r, Closure $next)
    {
        $token = $r->bearerToken();
        abort_unless($token, 401);
        $client = DB::table('api_client_credentials')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($client, 401);
        $app = DB::table('api_clients')->where('id', $client->api_client_id)->where('status', 'active')->first();
        abort_unless($app, 403);
        $v = $r->validate(['organisation_id' => 'required|uuid', 'product' => 'required|string|max:60']);
        abort_unless($app->product_code === $v['product'], 403, 'Product credential scope mismatch.');
        abort_unless(DB::table('product_organisation_access')->where('api_client_id', $app->id)->where('organisation_id', $v['organisation_id'])->exists(), 403, 'Organisation is not authorised for this client.');
        $r->attributes->set('product_client', $app);

        return $next($r);
    }
}

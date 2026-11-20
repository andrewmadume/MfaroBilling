<?php

namespace App\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Jumbojett\OpenIDConnectClient;

// Use Laravel's encrypted, server-side session rather than a separate native PHP session cookie.
class OidcClient extends OpenIDConnectClient
{
    protected function startSession(): void {}

    protected function commitSession(): void
    {
        session()->save();
    }

    protected function getSessionKey($key)
    {
        return session()->get('oidc.'.$key, false);
    }

    protected function setSessionKey($key, $value): void
    {
        session()->put('oidc.'.$key, $value);
    }

    protected function unsetSessionKey($key): void
    {
        session()->forget('oidc.'.$key);
    }

    public function redirect(string $url)
    {
        throw new HttpResponseException(redirect()->away($url));
    }
}

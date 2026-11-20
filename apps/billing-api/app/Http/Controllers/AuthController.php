<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OidcClient;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController
{
    public function session(Request $r)
    {
        return ['csrf_token' => csrf_token(), 'user' => $r->user(), 'local_auth' => app()->environment('local') && config('billing.local_auth'), 'oidc_configured' => (bool) config('billing.oidc.issuer'), 'account_url' => config('billing.oidc.account_url')];
    }

    public function login(Request $r)
    {
        abort_unless(app()->environment('local') && config('billing.local_auth'), 404);
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        abort_unless(Auth::attempt($data), 422, 'Invalid email or password.');
        $r->session()->regenerate();

        return ['user' => $r->user(), 'csrf_token' => csrf_token()];
    }

    public function oidc(Request $r)
    {
        abort_unless(config('billing.oidc.issuer') && config('billing.oidc.client_id'), 503, 'Identity provider is not configured.');
        $oidc = new OidcClient(config('billing.oidc.issuer'), config('billing.oidc.client_id'), config('billing.oidc.client_secret'));
        $oidc->setRedirectURL(config('billing.oidc.redirect_uri'));
        $oidc->setCodeChallengeMethod('S256');
        $oidc->addScope(['openid', 'profile', 'email']);
        if (! $r->has('code')) {
            $r->session()->put('login_destination', $r->query('portal') === 'admin' ? 'admin' : 'customer');
        }
        $oidc->authenticate();
        $sub = $oidc->getVerifiedClaims('sub');
        $email = $oidc->getVerifiedClaims('email');
        $verified = $oidc->getVerifiedClaims('email_verified');
        abort_unless($sub && $email && $verified === true, 403, 'Verify your email at Mfaro Accounts before continuing.');
        $user = DB::transaction(function () use ($sub, $email, $oidc) {
            $link = DB::table('identity_provider_links')->where('issuer', config('billing.oidc.issuer'))->where('subject', $sub)->first();
            if ($link) {
                return User::findOrFail($link->user_id);
            }
            abort_if(User::where('email', $email)->exists(), 409, 'An account already uses this email. Contact support to link identities.');
            $u = User::create(['email' => $email, 'name' => $oidc->getVerifiedClaims('name') ?: $email, 'password' => Hash::make(Str::random(64)), 'email_verified_at' => now()]);
            DB::table('identity_provider_links')->insert(['user_id' => $u->id, 'issuer' => config('billing.oidc.issuer'), 'subject' => $sub, 'created_at' => now(), 'updated_at' => now()]);

            return $u;
        });
        Auth::login($user);
        $r->session()->regenerate();
        $r->session()->put('oidc_id_token', $oidc->getIdToken());
        DB::table('user_sessions')->insert(['user_id' => $user->id, 'session_id' => $r->session()->getId(), 'issuer' => config('billing.oidc.issuer'), 'subject' => $sub, 'provider_sid' => $oidc->getVerifiedClaims('sid'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('audit_logs')->insert(['id' => Str::uuid(), 'user_id' => $user->id, 'action' => 'login', 'payload' => '{}', 'ip_address' => $r->ip(), 'created_at' => now()]);
        $destination = $r->session()->pull('login_destination');

        return redirect($destination === 'admin' ? config('billing.admin_url') : config('billing.portal_url'));
    }

    public function logout(Request $r)
    {
        $token = $r->session()->get('oidc_id_token');
        DB::table('user_sessions')->where('session_id', $r->session()->getId())->delete();
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        $logout = null;
        if ($token && config('billing.oidc.issuer')) {
            try {
                $oidc = new OidcClient(config('billing.oidc.issuer'), config('billing.oidc.client_id'), config('billing.oidc.client_secret'));
                $oidc->signOut($token, config('billing.portal_url').'/');
            } catch (HttpResponseException $e) {
                $logout = $e->getResponse()->headers->get('Location');
            } catch (\Throwable $e) {
                report($e);
            }
        }

return ['message' => 'Billing session ended.', 'logout_url' => $logout];
    }

    public function backchannel(Request $r)
    {
        $d = $r->validate(['logout_token' => 'required|string|max:16000']);
        abort_unless(config('billing.oidc.issuer'), 503);
        $oidc = new OidcClient(config('billing.oidc.issuer'), config('billing.oidc.client_id'), config('billing.oidc.client_secret'));
        // The upstream verifier consumes the standard POST parameter through PHP's request bag.
        $_REQUEST['logout_token'] = $d['logout_token'];
        try {
            abort_unless($oidc->verifyLogoutToken(), 400, 'Invalid logout token.');
        } catch (\Throwable $e) {
            abort(400, 'Invalid logout token.');
        } finally {
            unset($_REQUEST['logout_token']);
        }
        $jti = $oidc->getVerifiedClaims('jti');
        $iat = $oidc->getVerifiedClaims('iat');
        $events = (array) $oidc->getVerifiedClaims('events');
        abort_unless($jti && is_int($iat) && $iat >= time() - 300 && $iat <= time() + 30 && array_key_exists('http://schemas.openid.net/event/backchannel-logout', $events), 400, 'Invalid logout claims.');
        if (! Cache::add('oidc-logout:'.hash('sha256', $jti), true, 600)) {
            return response('', 200);
        }
        $sid = $oidc->getVerifiedClaims('sid');
        $sub = $oidc->getVerifiedClaims('sub');
        abort_unless($sid || $sub, 400);
        $q = DB::table('user_sessions')->where('issuer', config('billing.oidc.issuer'));
        $sid ? $q->where('provider_sid', $sid) : $q->where('subject', $sub);
        $ids = (clone $q)->pluck('session_id');
        DB::table('sessions')->whereIn('id', $ids)->delete();
        $q->delete();

        return response('', 200);
    }

    public function export(Request $r)
    {
        $orgs = DB::table('organisation_members')->where('user_id', $r->user()->id)->pluck('organisation_id');

        return response()->json(['user' => $r->user(), 'memberships' => DB::table('organisation_members')->where('user_id', $r->user()->id)->get(), 'organisations' => DB::table('organisations')->whereIn('id', $orgs)->get()])->header('Content-Disposition', 'attachment; filename="mfaro-personal-data.json"');
    }

    public function deletion(Request $r)
    {
        DB::table('audit_logs')->insert(['id' => Str::uuid(), 'user_id' => $r->user()->id, 'action' => 'account_deletion_requested', 'payload' => json_encode(['reason' => $r->validate(['reason' => 'nullable|string|max:2000'])['reason'] ?? '']), 'ip_address' => $r->ip(), 'created_at' => now()]);

        return response()->json(['message' => 'Deletion request recorded for identity and financial-retention review.'], 202);
    }

    public function sessions(Request $r)
    {
        return DB::table('sessions')->where('user_id', $r->user()->id)->get(['id', 'ip_address', 'user_agent', 'last_activity'])->map(fn ($s) => ['id' => hash('sha256', $s->id), 'ip_address' => $s->ip_address, 'user_agent' => $s->user_agent, 'last_activity' => $s->last_activity, 'current' => hash_equals($s->id, $r->session()->getId())]);
    }

    public function revokeSessions(Request $r)
    {
        DB::table('sessions')->where('user_id',$r->user()->id)->where('id','!=',$r->session()->getId())->delete();

        return ['message' => 'Other billing sessions revoked.'];
    }
}

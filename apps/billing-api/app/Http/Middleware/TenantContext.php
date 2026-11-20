<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantContext
{
    public function handle(Request $r, Closure $next)
    {
        $id = $r->header('X-Organisation-ID');
        abort_unless($id, 422, 'Select an organisation.');
        $member = DB::table('organisation_members')->where('organisation_id', $id)->where('user_id', $r->user()->id)->first();
        abort_unless($member, 403, 'You are not a member of this organisation.');
        $r->attributes->set('organisation_id', $id);
        $r->attributes->set('organisation_role', $member->role);

        return $next($r);
    }
}

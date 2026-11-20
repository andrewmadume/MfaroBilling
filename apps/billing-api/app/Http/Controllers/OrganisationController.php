<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganisationController
{
    public static function permit(Request $r, array $roles): void
    {
        abort_unless(in_array($r->attributes->get('organisation_role'), $roles, true), 403, 'Your organisation role does not permit this action.');
    }

    public function index(Request $r)
    {
        return DB::table('organisations')->join('organisation_members', 'organisations.id', '=', 'organisation_members.organisation_id')->where('user_id', $r->user()->id)->select('organisations.*', 'organisation_members.role')->get();
    }

    public function create(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:160', 'billing_email' => 'required|email|max:200']);
        $org = DB::transaction(function () use ($r, $d) {
            $o = Organisation::create($d);
            DB::table('organisation_members')->insert(['organisation_id' => $o->id, 'user_id' => $r->user()->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);

            return $o;
        });

        return response()->json($org, 201);
    }

    public function update(Request $r)
    {
        self::permit($r, ['owner', 'administrator']);
        $o = Organisation::findOrFail($r->attributes->get('organisation_id'));
        $d = $r->validate(['name' => 'required|string|max:160', 'billing_email' => 'required|email|max:200', 'registration_number' => 'nullable|string|max:100', 'vat_number' => 'nullable|string|max:100', 'billing_address' => 'nullable|array']);
        $o->update($d);

        return $o;
    }

    public function members(Request $r)
    {
        return DB::table('organisation_members')->join('users', 'users.id', '=', 'organisation_members.user_id')->where('organisation_id', $r->attributes->get('organisation_id'))->get(['users.id', 'users.name', 'users.email', 'organisation_members.role']);
    }

    public function invitations(Request $r)
    {
        self::permit($r, ['owner', 'administrator']);

        return DB::table('organisation_invitations')->where('organisation_id', $r->attributes->get('organisation_id'))->get(['id', 'email', 'role', 'expires_at', 'accepted_at']);
    }

    public function invite(Request $r)
    {
        self::permit($r, ['owner', 'administrator']);
        $d = $r->validate(['email' => 'required|email|max:200', 'role' => 'required|in:administrator,billing_manager,member']);
        $token = Str::random(64);
        $id = Str::uuid();
        $org = $r->attributes->get('organisation_id');
        DB::transaction(function () use ($d, $token, $id, $org) {
            DB::table('organisation_invitations')->insert(['id' => $id, 'organisation_id' => $org, 'email' => strtolower($d['email']), 'role' => $d['role'], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('outbox_events')->insert(['id' => Str::uuid(), 'organisation_id' => $org, 'type' => 'organisation_invitation', 'recipient' => $d['email'], 'payload' => json_encode(['organisation' => Organisation::findOrFail($org)->name, 'portal_url' => config('billing.portal_url').'/organisation?invite='.$token]), 'deduplication_key' => 'invite:'.$id, 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['message' => 'Invitation queued for delivery.'], 201);
    }

    public function accept(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|size:64']);
        DB::transaction(function () use ($r, $d) {
            $i = DB::table('organisation_invitations')->where('token_hash', hash('sha256', $d['token']))->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($i && strtolower($r->user()->email) === $i->email && $r->user()->email_verified_at, 403, 'Invitation is invalid or belongs to another verified email.');
            DB::table('organisation_members')->insertOrIgnore(['organisation_id' => $i->organisation_id, 'user_id' => $r->user()->id, 'role' => $i->role, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('organisation_invitations')->where('id', $i->id)->update(['accepted_at' => now()]);
        });

        return ['message' => 'Organisation joined.'];
    }

    public function role(Request $r, string $user)
    {
        self::permit($r, ['owner']);
        $d = $r->validate(['role' => 'required|in:administrator,billing_manager,member']);
        $q = DB::table('organisation_members')->where('organisation_id', $r->attributes->get('organisation_id'))->where('user_id', $user);
        abort_if((clone $q)->where('role', 'owner')->exists(), 422, 'Owner role cannot be changed here.');
        abort_unless($q->update(['role' => $d['role'], 'updated_at' => now()]), 404);

        return ['message' => 'Role updated.'];
    }

    public function remove(Request $r, string $user)
    {
        self::permit($r, ['owner', 'administrator']);
        $q = DB::table('organisation_members')->where('organisation_id', $r->attributes->get('organisation_id'))->where('user_id', $user);
        $m = (clone $q)->first();
        abort_unless($m, 404);
        abort_if($m->role === 'owner' || ($m->role === 'administrator' && $r->attributes->get('organisation_role') !== 'owner'), 403);
        $q->delete();

        return ['message' => 'Member removed.'];
    }
}

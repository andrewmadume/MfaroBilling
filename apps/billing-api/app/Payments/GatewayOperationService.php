<?php

namespace App\Payments;

use App\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GatewayOperationService
{
    public const UNRESOLVED = ['processing', 'needs_reconciliation'];

    public function replay(string $organisation, string $key, string $kind, array $payload): ?array
    {
        $row = DB::table('gateway_operations')->where('organisation_id', $organisation)->where('idempotency_key', $key)->first();
        if (! $row) {
            return null;
        }abort_unless(hash_equals($row->request_hash, $this->hash($kind, $payload)), 409, 'Idempotency key was used for another operation.');

        return $this->view($row->id);
    }

    public function begin(string $organisation, string $key, string $kind, array $payload, ?string $subscription, ?string $payment, ?int $user, callable $validate): array
    {
        return DB::transaction(function () use ($organisation, $key, $kind, $payload, $subscription, $payment, $user, $validate) {
            Organisation::whereKey($organisation)->lockForUpdate()->firstOrFail();
            $replay = $this->replay($organisation, $key, $kind, $payload);
            if ($replay) {
                return ['new' => false, 'operation' => $replay];
            }
            if ($subscription) {
                abort_if(DB::table('gateway_operations')->where('subscription_id', $subscription)->whereIn('status', self::UNRESOLVED)->exists(), 409, 'A provider operation is unresolved. Reconcile it before continuing.');
                abort_if(DB::table('subscription_changes')->where('subscription_id', $subscription)->whereIn('status', ['applying', 'needs_reconciliation', 'scheduled'])->exists(), 409, 'A plan change is pending. Resolve it before changing the provider schedule.');
            }
            $validate();
            $id = (string) Str::uuid();
            DB::table('gateway_operations')->insert(['id' => $id, 'organisation_id' => $organisation, 'subscription_id' => $subscription, 'payment_id' => $payment, 'requested_by' => $user, 'kind' => $kind, 'idempotency_key' => $key, 'request_hash' => $this->hash($kind, $payload), 'status' => 'processing', 'payload' => json_encode($payload), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($id, $organisation, $user, $kind.'.requested');

            return ['new' => true, 'operation' => $this->view($id)];
        }, 3);
    }

    private function hash(string $kind, array $payload): string
    {
        return hash('sha256', json_encode(['kind' => $kind, 'payload' => $payload], JSON_THROW_ON_ERROR));
    }

    public function complete(string $id, array $result = []): array
    {
        DB::table('gateway_operations')->where('id', $id)->update(['status' => 'succeeded', 'result' => json_encode($result), 'failure_code' => null, 'completed_at' => now(), 'updated_at' => now()]);
        $row = DB::table('gateway_operations')->find($id);
        $this->audit($id, $row->organisation_id, $row->requested_by, $row->kind.'.succeeded');

        return $this->view($id);
    }

    public function uncertain(string $id): array
    {
        DB::table('gateway_operations')->where('id', $id)->update(['status' => 'needs_reconciliation', 'failure_code' => 'provider_outcome_uncertain', 'updated_at' => now()]);
        $row = DB::table('gateway_operations')->find($id);
        $this->audit($id, $row->organisation_id, $row->requested_by, $row->kind.'.uncertain');

        return $this->view($id);
    }

    public function view(string $id): array
    {
        $r = DB::table('gateway_operations')->find($id);
        abort_unless($r, 404);

        return ['id' => $r->id, 'organisation_id' => $r->organisation_id, 'subscription_id' => $r->subscription_id, 'payment_id' => $r->payment_id, 'kind' => $r->kind, 'status' => $r->status, 'result' => $r->result ? json_decode($r->result, true) : null, 'failure_code' => $r->failure_code, 'created_at' => $r->created_at, 'completed_at' => $r->completed_at];
    }

    public function audit(string $id, string $organisation, ?int $user, string $action, array $extra = []): void
    {
        DB::table('audit_logs')->insert(['id' => Str::uuid(), 'organisation_id' => $organisation, 'user_id' => $user, 'action' => $action, 'payload' => json_encode(['operation_id' => $id] + $extra), 'created_at' => now()]);
    }
}

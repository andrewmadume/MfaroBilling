<?php

namespace App\Subscriptions;

use App\Models\Subscription;
use App\Payments\GatewayOperationService;
use App\Payments\PayFastGateway;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LifecycleService
{
    public function __construct(private GatewayOperationService $operations, private PayFastGateway $gateway, private BillingService $billing) {}

    public function perform(Subscription $subscription, string $kind, array $data, string $key, ?int $user): array
    {
        $payload = ['subscription_id' => $subscription->id] + $data;
        $replay = $this->operations->replay($subscription->organisation_id, $key, $kind, $payload);
        if ($replay) {
            return $replay;
        }
        $this->gateway->ensureConfigured();
        $intent = $this->operations->begin($subscription->organisation_id, $key, $kind, $payload, $subscription->id, null, $user, function () use ($subscription, $kind) {
            $s = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->gateway_token, 422, 'This subscription has no recurring payment authorisation.');
            if ($kind === 'pause') {
                abort_unless($s->status === 'active' && ! $s->cancel_at_period_end && $s->period_end?->isFuture() && $s->gateway_status === 'active', 409, 'Only an active paid subscription can be paused.');
            } elseif ($kind === 'resume') {
                abort_unless($s->paused_at && $s->gateway_status === 'paused' && ! $s->cancel_at_period_end && ! in_array($s->status, ['cancelled', 'expired']), 409, 'Only a voluntarily paused subscription can resume.');
            } elseif ($kind === 'cancel') {
                abort_unless(! in_array($s->status, ['cancelled', 'expired']), 409, 'The subscription is already terminated.');
            }
        });
        if (! $intent['new']) {
            return $intent['operation'];
        }$id = $intent['operation']['id'];
        try {
            if ($kind === 'pause') {
                $this->gateway->pause($subscription->gateway_token, $data['cycles']);
            } elseif ($kind === 'resume') {
                $this->gateway->resume($subscription->gateway_token);
            } elseif ($kind === 'cancel') {
                $this->gateway->cancel($subscription->gateway_token);
            }
        } catch (\Throwable $exception) {
            report($exception);

            return $this->operations->uncertain($id);
        }

        return $this->apply($id);
    }

    public function apply(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $op = DB::table('gateway_operations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($op, 404);
            if ($op->status === 'succeeded') {
                return $this->operations->view($id);
            }
            $s = Subscription::whereKey($op->subscription_id)->lockForUpdate()->firstOrFail();
            $payload = json_decode($op->payload, true);
            if ($op->kind === 'pause') {
                $s->update(['paused_at' => now(), 'pause_cycles' => $payload['cycles'], 'gateway_status' => 'paused']);
                if ($s->period_end?->isPast() && in_array($s->status, ['active', 'past_due', 'grace_period'], true)) {
                    $this->billing->transition($s, 'suspended');
                }
            } elseif ($op->kind === 'resume') {
                $s->update(['paused_at' => null, 'pause_cycles' => null, 'gateway_status' => 'active']);
                if ($s->status === 'suspended' && $s->period_end?->isFuture()) {
                    $this->billing->transition($s, 'active');
                }
            } elseif ($op->kind === 'cancel') {
                $s->update(['gateway_status' => 'cancelled', 'cancel_at_period_end' => true, 'paused_at' => null]);
                if (($payload['immediate'] ?? false) || ! $s->period_end || $s->period_end->isPast()) {
                    $this->billing->transition($s, 'cancelled');
                }
            }
            $this->billing->event($s, 'provider_'.$op->kind, ['operation_id' => $id]);
            $this->billing->notify($s, 'subscription_'.$op->kind, 'operation:'.$id);

            return $this->operations->complete($id, ['subscription_status' => $s->status, 'gateway_status' => $s->gateway_status, 'paid_access_until' => $s->period_end?->toIso8601String()]);
        }, 3);
    }

    public function reconcile(string $id): array
    {
        $op = DB::table('gateway_operations')->find($id);
        abort_unless($op && in_array($op->kind, ['pause', 'resume', 'cancel'], true), 404);
        if (! in_array($op->status, GatewayOperationService::UNRESOLVED, true)) {
            return $this->operations->view($id);
        }
        abort_if($op->status === 'processing' && now()->diffInSeconds(Carbon::parse($op->updated_at), true) < 120, 409, 'An operation may still be running. Wait before reconciliation.');
        $s = Subscription::findOrFail($op->subscription_id);
        $remote = $this->gateway->fetch($s->gateway_token);
        $status = strtolower($remote['data']['response']['status_text'] ?? '');
        $wanted = ['pause' => 'paused', 'resume' => 'active', 'cancel' => 'cancelled'][$op->kind];
        if ($status === $wanted) {
            return $this->apply($id);
        }if ($op->status === 'processing') {
            return $this->operations->uncertain($id);
        }

        return $this->operations->view($id);
    }

    public function resolve(string $id, int $user, string $evidence, string $outcome): array
    {
        $op = DB::table('gateway_operations')->find($id);
        abort_unless($op && in_array($op->kind, ['pause', 'resume', 'cancel'], true) && $op->status === 'needs_reconciliation', 409, 'Only uncertain subscription operations can be resolved.');
        if ($outcome === 'confirmed_processed') {
            $result = $this->reconcile($id);
            abort_unless($result['status'] === 'succeeded', 409, 'The provider did not confirm the requested schedule state.');
            $this->operations->audit($id, $op->organisation_id, $user, 'provider.operator_resolution', ['evidence_reference' => $evidence, 'outcome' => $outcome]);

            return $result;
        }

        return DB::transaction(function () use ($id, $user, $evidence, $op) {
            $current = DB::table('gateway_operations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($current->status === 'needs_reconciliation', 409, 'Operation state changed.');
            DB::table('gateway_operations')->where('id', $id)->update(['status' => 'failed', 'failure_code' => 'operator_confirmed_not_processed', 'completed_at' => now(), 'updated_at' => now()]);
            $this->operations->audit($id, $op->organisation_id, $user, 'provider.operator_resolution', ['evidence_reference' => $evidence, 'outcome' => 'confirmed_not_processed']);

            return $this->operations->view($id);
        });
    }

    public function recover(Subscription $s): array
    {
        abort_unless(in_array($s->status, ['past_due', 'grace_period', 'suspended'], true) && ! $s->paused_at, 409, 'This subscription does not need failed-payment recovery.');
        abort_unless($s->gateway_token && $s->gateway_status !== 'cancelled', 409, 'No active mandate. Contact support before creating a new agreement.');
        $remote = $this->gateway->fetch($s->gateway_token);
        $status = strtolower($remote['data']['response']['status_text'] ?? 'unknown');

        return ['subscription_id' => $s->id, 'provider_status' => $status, 'card_update_url' => $this->gateway->cardUpdateUrl($s->gateway_token), 'needs_merchant_assistance' => in_array($status, ['paused', 'locked', 'cancelled'], true), 'collection_owner' => 'payfast', 'paid_access_restored' => false, 'message' => 'Update your card at PayFast. Provider-managed retries confirm payment; no extra checkout or charge is created here.'];
    }
}

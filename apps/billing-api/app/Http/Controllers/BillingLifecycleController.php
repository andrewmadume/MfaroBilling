<?php

namespace App\Http\Controllers;

use App\Http\Requests\LifecycleRequest;
use App\Models\Subscription;
use App\Payments\GatewayOperationService;
use App\Payments\PayFastGateway;
use App\Services\BillingService;
use App\Subscriptions\LifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingLifecycleController
{
    private function subscription(Request $r, string $id): Subscription
    {
        OrganisationController::permit($r, ['owner', 'administrator', 'billing_manager']);

        return Subscription::where('organisation_id', $r->attributes->get('organisation_id'))->findOrFail($id);
    }

    public function pause(LifecycleRequest $r, string $id, LifecycleService $service): JsonResponse
    {
        $op = $service->perform($this->subscription($r, $id), 'pause', ['cycles' => (int) $r->validated('cycles', 1)], $r->operationKey(), $r->user()->id);

        return response()->json($op, $op['status'] === 'succeeded' ? 200 : 202);
    }

    public function resume(LifecycleRequest $r, string $id, LifecycleService $service): JsonResponse
    {
        $op = $service->perform($this->subscription($r, $id), 'resume', [], $r->operationKey(), $r->user()->id);

        return response()->json($op, $op['status'] === 'succeeded' ? 200 : 202);
    }

    public function cancel(LifecycleRequest $r, string $id, LifecycleService $service): JsonResponse
    {
        $s = $this->subscription($r, $id);
        if (! $s->gateway_token) {
            $updated = app(PortalController::class)->cancel($r, $id, app(PayFastGateway::class), app(BillingService::class));

            return response()->json(['status' => 'succeeded', 'result' => ['subscription_status' => $updated->status, 'cancel_at_period_end' => $updated->cancel_at_period_end]]);
        }$op = $service->perform($s, 'cancel', ['immediate' => $r->boolean('immediate')], $r->operationKey(), $r->user()->id);

        return response()->json($op, $op['status'] === 'succeeded' ? 200 : 202);
    }

    public function recovery(Request $r, string $id, LifecycleService $service): array
    {
        return $service->recover($this->subscription($r, $id));
    }

    public function operations(Request $r, string $id, GatewayOperationService $operations): array
    {
        $s = $this->subscription($r, $id);

        return DB::table('gateway_operations')->where('subscription_id', $s->id)->orderByDesc('created_at')->pluck('id')->map(fn ($id) => $operations->view($id))->all();
    }
}

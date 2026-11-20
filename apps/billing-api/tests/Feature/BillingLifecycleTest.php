<?php

namespace Tests\Feature;

use App\Entitlements\EntitlementService;
use App\Models\Offer;
use App\Models\OfferPrice;
use App\Models\Organisation;
use App\Models\User;
use App\Payments\PaymentProcessor;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->freezeTime();
        $this->seed();
        config(['billing.payfast' => ['enabled' => true, 'sandbox' => true, 'merchant_id' => 'test', 'merchant_key' => 'test-key', 'passphrase' => 'test-pass']]);
        $user = User::factory()->create();
        $org = Organisation::create(['name' => 'Lifecycle business', 'billing_email' => $user->email]);
        DB::table('organisation_members')->insert(['organisation_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $offer = Offer::whereNotNull('plan_id')->firstOrFail();
        $price = OfferPrice::where('offer_id', $offer->id)->where('interval', 'monthly')->firstOrFail();
        $s = app(BillingService::class)->create($org->id, ['offer_price_id' => $price->id]);
        app(PaymentProcessor::class)->process(['m_payment_id' => $s->id, 'pf_payment_id' => 'lifecycle-first', 'payment_status' => 'COMPLETE', 'amount_gross' => '199.00', 'token' => '11111111-2222-3333-4444-555555555555']);
        $this->actingAs($user)->withHeader('X-Organisation-ID', $org->id);

        return [$user, $org, $s->fresh()];
    }

    private function fake(string $action, mixed $body = true, int $code = 200): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.payfast.co.za/subscriptions/*/'.$action.'?testing=true' => Http::response(['status' => $code === 200 ? 'success' : 'failed', 'data' => ['response' => $body]], $code)]);
    }

    public function test_pause_preserves_current_paid_access_and_is_idempotent(): void
    {
        [$user,$org,$s] = $this->context();
        $this->fake('pause');
        $r = $this->withHeader('Idempotency-Key', 'pause-one')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', ['cycles' => 2])->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertSame('paused', $s->fresh()->gateway_status);
        $this->assertSame('active', $s->fresh()->status);
        $this->assertTrue(app(EntitlementService::class)->effective($org->id, $s->snapshot['items'][0]['product_code'])['allowed']);
        $this->withHeader('Idempotency-Key', 'pause-one')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', ['cycles' => 2])->assertOk()->assertJsonPath('id', $r->json('id'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['cycles'] === 2);
    }

    public function test_pause_expires_paid_access_without_extending_grace(): void
    {
        [$u,$org,$s] = $this->context();
        $this->fake('pause');
        $this->withHeader('Idempotency-Key', 'pause')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', [])->assertOk();
        $this->travelTo($s->period_end->addMinute());
        $this->artisan('billing:tick')->assertSuccessful();
        $this->assertSame('suspended', $s->fresh()->status);
        $this->assertNull($s->fresh()->grace_ends_at);
        $this->assertFalse(app(EntitlementService::class)->effective($org->id, $s->snapshot['items'][0]['product_code'])['allowed']);
        Http::assertSentCount(1);
    }

    public function test_resuming_expired_paid_period_never_grants_free_access(): void
    {
        [$u,$org,$s] = $this->context();
        $s->update(['paused_at' => now(), 'gateway_status' => 'paused', 'status' => 'suspended', 'period_end' => now()->subDay()]);
        $this->fake('unpause');
        $this->withHeader('Idempotency-Key', 'resume')->postJson('/api/v1/subscriptions/'.$s->id.'/resume', [])->assertOk();
        $this->assertNull($s->fresh()->paused_at);
        $this->assertSame('suspended', $s->fresh()->status);
        $this->assertFalse(app(EntitlementService::class)->effective($org->id, $s->snapshot['items'][0]['product_code'])['allowed']);
        Http::assertSentCount(1);
    }

    public function test_resuming_unexpired_paid_period_restores_only_paid_access(): void
    {
        [$u,$org,$s] = $this->context();
        $s->update(['paused_at' => now(), 'gateway_status' => 'paused', 'status' => 'suspended']);
        $end = $s->period_end;
        $this->fake('unpause');
        $this->withHeader('Idempotency-Key', 'resume')->postJson('/api/v1/subscriptions/'.$s->id.'/resume', [])->assertOk();
        $this->assertSame('active', $s->fresh()->status);
        $this->assertEquals($end, $s->fresh()->period_end);
        Http::assertSentCount(1);
    }

    public function test_uncertain_pause_is_not_retried_and_blocks_competing_operation(): void
    {
        [$u,$org,$s] = $this->context();
        $this->fake('pause', false, 500);
        $r = $this->withHeader('Idempotency-Key', 'uncertain')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', [])->assertAccepted()->assertJsonPath('status', 'needs_reconciliation');
        $this->assertNull($s->fresh()->paused_at);
        $this->withHeader('Idempotency-Key', 'uncertain')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', [])->assertAccepted()->assertJsonPath('id', $r->json('id'));
        $this->withHeader('Idempotency-Key', 'another')->postJson('/api/v1/subscriptions/'.$s->id.'/cancel', [])->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_read_only_reconciliation_applies_confirmed_pause(): void
    {
        [$u,$org,$s] = $this->context();
        $this->fake('pause', false, 500);
        $r = $this->withHeader('Idempotency-Key', 'uncertain')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', [])->assertAccepted();
        $u->is_admin = true;
        $u->save();
        Http::fake(['https://api.payfast.co.za/subscriptions/*/fetch?testing=true' => Http::response(['status' => 'success', 'data' => ['response' => ['status_text' => 'PAUSED']]])]);
        $this->postJson('/api/v1/admin/gateway-operations/'.$r->json('id').'/reconcile', [])->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertSame('paused', $s->fresh()->gateway_status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_cancellation_is_durable_and_only_calls_provider_once(): void
    {
        [$u,$org,$s] = $this->context();
        $this->fake('cancel');
        $this->withHeader('Idempotency-Key', 'cancel-one')->postJson('/api/v1/subscriptions/'.$s->id.'/cancel', [])->assertOk();
        $this->withHeader('Idempotency-Key', 'cancel-one')->postJson('/api/v1/subscriptions/'.$s->id.'/cancel', [])->assertOk();
        $this->assertTrue($s->fresh()->cancel_at_period_end);
        $this->assertSame('cancelled', $s->fresh()->gateway_status);
        $this->assertSame('active', $s->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_recovery_fetches_provider_without_charging_or_activating(): void
    {
        [$u,$org,$s] = $this->context();
        $s->update(['status' => 'suspended', 'period_end' => now()->subDay()]);
        $this->fake('fetch', ['status_text' => 'ACTIVE']);
        $this->getJson('/api/v1/subscriptions/'.$s->id.'/recovery')->assertOk()->assertJsonPath('collection_owner', 'payfast')->assertJsonPath('paid_access_restored', false);
        $this->assertSame('suspended', $s->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        Http::assertSentCount(1);
    }

    public function test_repeated_distinct_failures_preserve_grace_deadline(): void
    {
        [$u,$org,$s] = $this->context();
        $processor = app(PaymentProcessor::class);
        $event = ['m_payment_id' => $s->id, 'pf_payment_id' => 'failure-a', 'payment_status' => 'FAILED', 'amount_gross' => '199.00', 'token' => $s->gateway_token];
        $processor->process($event);
        $this->artisan('billing:tick')->assertSuccessful();
        $deadline = $s->fresh()->grace_ends_at;
        $this->travel(2)->days();
        $event['pf_payment_id'] = 'failure-b';
        $processor->process($event);
        $this->assertSame('grace_period', $s->fresh()->status);
        $this->assertEquals($deadline, $s->fresh()->grace_ends_at);
    }

    public function test_pause_requires_membership_role_and_valid_parameters(): void
    {
        [$u,$org,$s] = $this->context();
        Http::preventStrayRequests();
        $this->withHeader('Idempotency-Key', 'invalid')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', ['cycles' => 0])->assertUnprocessable()->assertJsonValidationErrors('cycles');
        DB::table('organisation_members')->where('user_id', $u->id)->update(['role' => 'member']);
        $this->postJson('/api/v1/subscriptions/'.$s->id.'/pause', [])->assertForbidden();
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/v1/subscriptions/'.$s->id.'/pause', [])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/subscriptions/'.$s->id.'/operations')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_foreign_subscription_is_hidden_and_key_conflicts_are_rejected(): void
    {
        [$u,$org,$s] = $this->context();
        Http::preventStrayRequests();
        $foreign = Organisation::create(['name' => 'Other', 'billing_email' => 'other@example.test']);
        $s->update(['organisation_id' => $foreign->id]);
        $this->withHeader('Idempotency-Key', 'foreign')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', [])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_same_key_cannot_change_pause_parameters(): void
    {
        [$u, $org, $s] = $this->context();
        $this->fake('pause');
        $this->withHeader('Idempotency-Key', 'one-intent')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', ['cycles' => 1])->assertOk();
        $this->withHeader('Idempotency-Key', 'one-intent')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', ['cycles' => 2])->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_verified_payment_restores_a_recovered_expired_subscription(): void
    {
        [$u, $org, $s] = $this->context();
        $s->update(['status' => 'suspended', 'period_end' => now()->subDay()]);
        app(PaymentProcessor::class)->process(['m_payment_id' => $s->id, 'pf_payment_id' => 'recovered-payment', 'payment_status' => 'COMPLETE', 'amount_gross' => '199.00', 'token' => $s->gateway_token]);
        $this->assertSame('active', $s->fresh()->status);
        $this->assertTrue(app(EntitlementService::class)->effective($org->id, $s->snapshot['items'][0]['product_code'])['allowed']);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_operator_can_release_a_confirmed_unprocessed_pause(): void
    {
        [$u, $org, $s] = $this->context();
        $this->fake('pause', false, 500);
        $r = $this->withHeader('Idempotency-Key', 'uncertain')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', [])->assertAccepted();
        $u->is_admin = true;
        $u->save();
        $this->postJson('/api/v1/admin/gateway-operations/'.$r->json('id').'/resolve', ['outcome' => 'confirmed_not_processed', 'attested' => true, 'evidence_reference' => 'merchant-support-123'])->assertOk()->assertJsonPath('status', 'failed');
        $this->assertNull($s->fresh()->paused_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'provider.operator_resolution']);
        Http::assertSentCount(1);
    }

    public function test_trial_cancellation_does_not_require_a_provider_mandate(): void
    {
        [$u, $org, $s] = $this->context();
        $s->update(['status' => 'trialing', 'gateway_token' => null]);
        Http::preventStrayRequests();
        $this->postJson('/api/v1/subscriptions/'.$s->id.'/cancel', [])->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertTrue($s->fresh()->cancel_at_period_end);
        Http::assertNothingSent();
    }

    public function test_unconfigured_gateway_does_not_leave_an_uncertain_operation(): void
    {
        [$u, $org, $s] = $this->context();
        config(['billing.payfast.enabled' => false]);
        Http::preventStrayRequests();
        $this->withHeader('Idempotency-Key', 'disabled')->postJson('/api/v1/subscriptions/'.$s->id.'/pause', [])->assertStatus(503);
        $this->assertDatabaseCount('gateway_operations', 0);
        Http::assertNothingSent();
    }
}

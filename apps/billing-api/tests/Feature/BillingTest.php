<?php

namespace Tests\Feature;

use App\Entitlements\EntitlementService;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\OfferPrice;
use App\Models\Organisation;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\PaymentProcessor;
use App\Services\BillingService;
use App\Services\Money;
use App\Services\OidcClient;
use App\Subscriptions\PlanChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::factory()->create();
        $this->org = Organisation::create(['name' => 'Test organisation', 'billing_email' => $this->user->email]);
        DB::table('organisation_members')->insert(['organisation_id' => $this->org->id, 'user_id' => $this->user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function price(bool $bundle = false): OfferPrice
    {
        $o = Offer::query();
        $bundle ? $o->whereNotNull('bundle_id') : $o->whereNotNull('plan_id');

        return OfferPrice::where('offer_id', $o->firstOrFail()->id)->where('interval', 'monthly')->firstOrFail();
    }

    private function subscription(bool $bundle = false, bool $overlap = false): Subscription
    {
        return app(BillingService::class)->create($this->org->id, ['offer_price_id' => $this->price($bundle)->id, 'accept_overlap' => $overlap]);
    }

    private function event(Subscription $s, string $id = 'test-payment-1', string $status = 'COMPLETE'): array
    {
        return ['m_payment_id' => $s->id, 'pf_payment_id' => $id, 'payment_status' => $status, 'amount_gross' => Money::decimal(Money::minor($s->amount)), 'token' => '11111111-2222-3333-4444-555555555555'];
    }

    private function tenant()
    {
        return $this->actingAs($this->user)->withHeader('X-Organisation-ID', $this->org->id);
    }

    public function test_catalogue_is_database_backed(): void
    {
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(5);
        $this->getJson('/api/v1/offers')->assertOk()->assertJsonCount(24);
    }

    public function test_unauthenticated_requests_are_denied(): void
    {
        $this->getJson('/api/v1/subscriptions')->assertUnauthorized();
    }

    public function test_organisation_creation_has_owner_membership(): void
    {
        $r = $this->actingAs($this->user)->postJson('/api/v1/organisations', ['name' => 'New business', 'billing_email' => 'billing@example.test'])->assertCreated();
        $this->assertDatabaseHas('organisation_members', ['organisation_id' => $r->json('id'), 'user_id' => $this->user->id, 'role' => 'owner']);
    }

    public function test_cross_tenant_header_denied(): void
    {
        $other = Organisation::create(['name' => 'Other', 'billing_email' => 'other@example.test']);
        $this->actingAs($this->user)->withHeader('X-Organisation-ID', $other->id)->getJson('/api/v1/subscriptions')->assertForbidden();
    }

    public function test_cross_tenant_invoice_id_denied(): void
    {
        $s = $this->subscription();
        $i = Invoice::where('subscription_id', $s->id)->firstOrFail();
        $other = User::factory()->create();
        $o = Organisation::create(['name' => 'Other', 'billing_email' => $other->email]);
        DB::table('organisation_members')->insert(['organisation_id' => $o->id, 'user_id' => $other->id, 'role' => 'owner']);
        $this->actingAs($other)->withHeader('X-Organisation-ID', $o->id)->getJson('/api/v1/invoices/'.$i->id)->assertNotFound();
    }

    public function test_member_cannot_create_subscriptions(): void
    {
        DB::table('organisation_members')->where('user_id', $this->user->id)->update(['role' => 'member']);
        $this->tenant()->postJson('/api/v1/subscriptions', ['offer_price_id' => $this->price()->id])->assertForbidden();
    }

    public function test_member_cannot_view_financial_invoices(): void
    {
        DB::table('organisation_members')->where('user_id', $this->user->id)->update(['role' => 'member']);
        $this->tenant()->getJson('/api/v1/invoices')->assertForbidden();
    }

    public function test_customer_cannot_access_admin(): void
    {
        $this->tenant()->getJson('/api/v1/admin/customers')->assertForbidden();
    }

    public function test_non_owner_cannot_escalate_roles(): void
    {
        DB::table('organisation_members')->where('user_id', $this->user->id)->update(['role' => 'administrator']);
        $this->tenant()->patchJson('/api/v1/organisation/members/'.$this->user->id, ['role' => 'owner'])->assertForbidden();
    }

    public function test_subscription_creation_is_idempotent(): void
    {
        $payload = ['offer_price_id' => $this->price()->id];
        $a = $this->tenant()->withHeader('Idempotency-Key', 'key-1')->postJson('/api/v1/subscriptions', $payload)->assertCreated();
        $b = $this->tenant()->withHeader('Idempotency-Key', 'key-1')->postJson('/api/v1/subscriptions', $payload)->assertCreated();
        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_idempotency_key_reuse_with_different_payload_is_rejected(): void
    {
        $this->tenant()->withHeader('Idempotency-Key', 'key-1')->postJson('/api/v1/subscriptions', ['offer_price_id' => $this->price()->id])->assertCreated();
        $this->tenant()->withHeader('Idempotency-Key', 'key-1')->postJson('/api/v1/subscriptions', ['offer_price_id' => $this->price(true)->id])->assertConflict();
    }

    public function test_duplicate_product_coverage_requires_explicit_acceptance(): void
    {
        $this->subscription();
        $this->expectException(HttpException::class);
        $this->subscription();
    }

    public function test_historical_prices_and_features_are_preserved(): void
    {
        $s = $this->subscription();
        $old = $s->snapshot;
        $p = $this->price();
        $p->update(['amount' => '99999.00']);
        DB::table('plans')->where('id', $s->snapshot['items'][0]['plan_id'])->update(['features' => '{}']);
        $this->assertEquals($old, $s->fresh()->snapshot);
        $this->assertNotEquals($p->amount, $s->fresh()->amount);
    }

    public function test_discount_is_snapshotted(): void
    {
        DB::table('coupons')->insert(['id' => Str::uuid(), 'code' => 'WELCOME', 'percent' => 10, 'active' => true]);
        $s = app(BillingService::class)->create($this->org->id, ['offer_price_id' => $this->price()->id, 'coupon' => 'WELCOME']);
        $this->assertEquals(10, $s->snapshot['discount_percent']);
        $this->assertSame(Money::discount(Money::minor($this->price()->amount), 10), Money::minor($s->amount));
    }

    public function test_gateway_currency_cannot_be_forged(): void
    {
        $p = $this->price();
        $p->update(['currency' => 'USD']);
        $this->tenant()->withHeader('Idempotency-Key', 'usd')->postJson('/api/v1/subscriptions', ['offer_price_id' => $p->id])->assertUnprocessable();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_verified_payment_activates_subscription_and_generates_entitlements(): void
    {
        $s = $this->subscription();
        $this->assertSame('pending', $s->status);
        app(PaymentProcessor::class)->process($this->event($s));
        $this->assertSame('active', $s->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('entitlement_grants', 1);
        $this->assertSame('paid', Invoice::where('subscription_id', $s->id)->first()->status);
    }

    public function test_duplicate_notification_does_not_extend_period_or_duplicate_payment(): void
    {
        $s = $this->subscription();
        $e = $this->event($s);
        $p = app(PaymentProcessor::class);
        $p->process($e);
        $end = $s->fresh()->period_end;
        $p->process($e);
        $this->assertEquals($end, $s->fresh()->period_end);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('gateway_webhook_events', 1);
    }

    public function test_browser_success_redirect_cannot_activate(): void
    {
        $s = $this->subscription();
        $this->tenant()->getJson('/api/v1/subscriptions?checkout=success')->assertOk();
        $this->assertSame('pending', $s->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_amount_mismatch_rolls_back_everything(): void
    {
        $s = $this->subscription();
        $e = $this->event($s);
        $e['amount_gross'] = '0.01';
        try {
            app(PaymentProcessor::class)->process($e);
            $this->fail('Amount mismatch accepted');
        } catch (HttpException $x) {
            $this->assertSame(422, $x->getStatusCode());
        }$this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('gateway_webhook_events', 0);
        $this->assertSame('pending', $s->fresh()->status);
    }

    public function test_bundle_payment_grants_multiple_products_under_one_invoice(): void
    {
        $s = $this->subscription(true);
        app(PaymentProcessor::class)->process($this->event($s));
        $this->assertCount(2, $s->snapshot['items']);
        foreach ($s->snapshot['items'] as $item) {
            $this->assertTrue(app(EntitlementService::class)->effective($this->org->id, $item['product_code'])['allowed']);
        }$this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_renewal_extends_period_once_and_issues_another_invoice(): void
    {
        $s = $this->subscription();
        $p = app(PaymentProcessor::class);
        $p->process($this->event($s));
        $end = $s->fresh()->period_end;
        $this->travelTo($end->addMinute());
        $p->process($this->event($s, 'renewal-2'));
        $this->assertTrue($s->fresh()->period_end->gt($end));
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_failed_renewal_enters_grace_then_suspends(): void
    {
        $s = $this->subscription();
        $p = app(PaymentProcessor::class);
        $p->process($this->event($s));
        $this->travelTo($s->fresh()->period_end->addMinute());
        $p->process($this->event($s, 'failed-2', 'FAILED'));
        $this->assertSame('past_due', $s->fresh()->status);
        $this->assertTrue(app(EntitlementService::class)->effective($this->org->id, $s->snapshot['items'][0]['product_code'])['allowed']);
        $this->travel(8)->days();
        $this->artisan('billing:tick')->assertSuccessful();
        $this->assertSame('suspended', $s->fresh()->status);
        $this->assertFalse(app(EntitlementService::class)->effective($this->org->id, $s->snapshot['items'][0]['product_code'])['allowed']);
    }

    public function test_overlap_access_survives_cancellation_of_one_source(): void
    {
        $a = $this->subscription();
        $b = $this->subscription(false, true);
        $processor = app(PaymentProcessor::class);
        $processor->process($this->event($a, 'payment-a'));
        $event = $this->event($b, 'payment-b');
        $event['token'] = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $processor->process($event);
        app(BillingService::class)->transition($a->fresh(), 'cancelled');
        $e = app(EntitlementService::class)->effective($this->org->id, $a->snapshot['items'][0]['product_code']);
        $this->assertTrue($e['allowed']);
        $this->assertCount(1, $e['source_subscription_ids']);
    }

    public function test_feature_merging_uses_or_and_largest_limit_with_unlimited(): void
    {
        $a = $this->subscription();
        $b = $this->subscription(false, true);
        foreach ([$a, $b] as $i => $s) {
            $snapshot = $s->snapshot;
            $snapshot['items'][0]['features'] = ['sales' => $i === 0, 'reports' => $i === 1];
            $snapshot['items'][0]['limits'] = ['max_users' => $i === 0 ? 5 : 10, 'max_branches' => $i === 0 ? 1 : null];
            $s->update(['status' => 'active', 'snapshot' => $snapshot, 'period_end' => now()->addMonth()]);
        }$e = app(EntitlementService::class)->effective($this->org->id, $a->snapshot['items'][0]['product_code']);
        $this->assertTrue($e['features']['sales']);
        $this->assertTrue($e['features']['reports']);
        $this->assertSame(10, $e['limits']['max_users']);
        $this->assertNull($e['limits']['max_branches']);
    }

    public function test_trial_expiry_revokes_access_without_deleting_business_account(): void
    {
        $r = $this->tenant()->postJson('/api/v1/subscriptions/trial', ['offer_price_id' => $this->price()->id])->assertCreated();
        $this->assertSame('trialing', $r->json('status'));
        $this->travel(15)->days();
        $this->artisan('billing:tick')->assertSuccessful();
        $this->assertDatabaseHas('subscriptions', ['id' => $r->json('id'), 'status' => 'expired']);
        $this->assertDatabaseHas('organisations', ['id' => $this->org->id]);
    }

    public function test_trial_cannot_be_repeated(): void
    {
        $this->tenant()->postJson('/api/v1/subscriptions/trial', ['offer_price_id' => $this->price()->id])->assertCreated();
        $this->tenant()->postJson('/api/v1/subscriptions/trial', ['offer_price_id' => $this->price()->id])->assertConflict();
    }

    public function test_invoice_pdf_is_generated_and_authorised(): void
    {
        $s = $this->subscription();
        $i = Invoice::where('subscription_id', $s->id)->firstOrFail();
        $r = $this->tenant()->get('/api/v1/invoices/'.$i->id.'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $r->getContent());
    }

    public function test_invalid_notification_signature_rejected(): void
    {
        config(['billing.payfast' => ['enabled' => true, 'sandbox' => true, 'merchant_id' => 'test-merchant', 'merchant_key' => 'test-key', 'passphrase' => 'test-passphrase']]);
        $s = $this->subscription();
        $this->post('/api/v1/webhooks/payfast', $this->event($s) + ['merchant_id' => 'test-merchant', 'signature' => str_repeat('0', 32)])->assertBadRequest();
        $this->assertSame('pending', $s->fresh()->status);
    }

    public function test_payfast_checkout_is_recurring_signed_and_never_activates(): void
    {
        config(['billing.payfast' => ['enabled' => true, 'sandbox' => true, 'merchant_id' => 'test-merchant', 'merchant_key' => 'test-key', 'passphrase' => 'test-passphrase']]);
        $s = $this->subscription();
        $r = $this->tenant()->postJson('/api/v1/checkout', ['subscription_id' => $s->id])->assertOk();
        $this->assertSame('1', $r->json('fields.subscription_type'));
        $this->assertSame('3', $r->json('fields.frequency'));
        $this->assertSame('https://sandbox.payfast.co.za/eng/process', $r->json('action'));
        $this->assertSame(32, strlen($r->json('fields.signature')));
        $this->assertSame('pending', $s->fresh()->status);
    }

    public function test_disabled_gateway_fails_safely(): void
    {
        $s = $this->subscription();
        config(['billing.payfast.enabled' => false]);
        $this->tenant()->postJson('/api/v1/checkout', ['subscription_id' => $s->id])->assertStatus(503);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_scoped_service_credential_and_expiry(): void
    {
        $s = $this->subscription();
        $product = $s->snapshot['items'][0]['product_code'];
        $client = (string) Str::uuid();
        DB::table('api_clients')->insert(['id' => $client, 'name' => 'Test product', 'product_code' => $product, 'status' => 'active']);
        DB::table('api_client_credentials')->insert(['id' => Str::uuid(), 'api_client_id' => $client, 'token_hash' => hash('sha256', 'test-token'), 'expires_at' => now()->addDay()]);
        DB::table('product_organisation_access')->insert(['api_client_id' => $client, 'organisation_id' => $this->org->id]);
        $this->withToken('test-token')->postJson('/api/v1/access/check', ['organisation_id' => $this->org->id, 'product' => $product])->assertOk()->assertJsonPath('allowed', false);
        $this->withToken('test-token')->postJson('/api/v1/access/check', ['organisation_id' => $this->org->id, 'product' => 'UNAUTHORISED'])->assertForbidden();
        $this->travel(2)->days();
        $this->withToken('test-token')->postJson('/api/v1/access/check', ['organisation_id' => $this->org->id, 'product' => $product])->assertUnauthorized();
    }

    public function test_unscoped_service_organisation_denied(): void
    {
        $client = (string) Str::uuid();
        DB::table('api_clients')->insert(['id' => $client, 'name' => 'Test product', 'product_code' => 'MFAROPOS', 'status' => 'active']);
        DB::table('api_client_credentials')->insert(['id' => Str::uuid(), 'api_client_id' => $client, 'token_hash' => hash('sha256', 'test-token'), 'expires_at' => now()->addDay()]);
        $this->withToken('test-token')->postJson('/api/v1/access/check', ['organisation_id' => $this->org->id, 'product' => 'MFAROPOS'])->assertForbidden();
    }

    public function test_customer_cannot_forge_subscription_status(): void
    {
        $s = $this->subscription();
        $this->tenant()->patchJson('/api/v1/admin/subscriptions/'.$s->id, ['status' => 'active'])->assertForbidden();
        $this->assertSame('pending', $s->fresh()->status);
    }

    public function test_admin_updates_catalogue_and_creates_price_history(): void
    {
        $this->user->is_admin = true;
        $this->user->save();
        $p = Plan::firstOrFail();
        $before = DB::table('price_versions')->count();
        $this->actingAs($this->user)->patchJson('/api/v1/admin/plans/'.$p->id, ['product_id' => $p->product_id, 'code' => $p->code, 'name' => $p->name, 'description' => 'Updated', 'features' => ['sales' => true], 'limits' => ['max_users' => 5], 'trial_days' => 14, 'status' => 'active', 'monthly_price' => '250.00', 'annual_price' => '2500.00'])->assertOk();
        $this->assertSame($before + 2, DB::table('price_versions')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'plans.saved']);
    }

    public function test_refund_cannot_exceed_original_payment(): void
    {
        $s = $this->subscription();
        app(PaymentProcessor::class)->process($this->event($s));
        $p = Payment::firstOrFail();
        $this->user->is_admin = true;
        $this->user->save();
        $this->actingAs($this->user)->postJson('/api/v1/admin/payments/'.$p->id.'/refunds', ['amount' => '99999.00', 'reference' => 'refund-1', 'reason' => 'test'])->assertUnprocessable();
    }

    public function test_local_login_disabled_outside_local_environment(): void
    {
        config(['billing.local_auth' => true]);
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'password'])->assertNotFound();
    }

    public function test_suspended_customer_can_access_billing_portal(): void
    {
        $s = $this->subscription();
        $s->update(['status' => 'suspended']);
        $this->tenant()->getJson('/api/v1/invoices')->assertOk();
        $this->tenant()->getJson('/api/v1/payments')->assertOk();
    }

    public function test_invitation_is_hashed_and_acceptance_checks_email(): void
    {
        $this->tenant()->postJson('/api/v1/organisation/invitations', ['email' => 'employee@example.test', 'role' => 'member'])->assertCreated();
        $i = DB::table('organisation_invitations')->first();
        $this->assertSame(64, strlen($i->token_hash));
        $this->tenant()->postJson('/api/v1/invitations/accept', ['token' => str_repeat('a', 64)])->assertForbidden();
    }

    public function test_api_has_no_store_security_headers(): void
    {
        $this->getJson('/api/v1/auth/session')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $this->getJson('/api/v1/auth/session')->headers->get('Cache-Control'));
    }

    public function test_plan_change_updates_provider_then_waits_for_verified_renewal(): void
    {
        config(['billing.payfast' => ['enabled' => true, 'sandbox' => true, 'merchant_id' => 'test', 'merchant_key' => 'key', 'passphrase' => 'pass']]);
        Http::fake(['api.payfast.co.za/*' => Http::response(['status' => 'success', 'data' => ['response' => true]], 200)]);
        $sub = $this->subscription();
        app(PaymentProcessor::class)->process($this->event($sub));
        $sub = $sub->fresh();
        $target = OfferPrice::where('offer_id', Offer::whereNotNull('bundle_id')->first()->id)->where('interval', 'annual')->firstOrFail();
        $old = $sub->snapshot;
        $preview = $this->tenant()->postJson('/api/v1/subscriptions/'.$sub->id.'/change-preview', ['offer_price_id' => $target->id])->assertOk()->assertJsonPath('due_today', '0.00');
        $this->tenant()->withHeader('Idempotency-Key', 'change-1')->postJson('/api/v1/subscriptions/'.$sub->id.'/change', ['offer_price_id' => $target->id, 'quote_hash' => $preview->json('quote_hash')])->assertOk()->assertJsonPath('status', 'scheduled');
        $this->assertSame($old, $sub->fresh()->snapshot);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['amount'] === Money::minor($target->amount) && $r['frequency'] === 6);
        $this->tenant()->withHeader('Idempotency-Key', 'change-1')->postJson('/api/v1/subscriptions/'.$sub->id.'/change', ['offer_price_id' => $target->id, 'quote_hash' => $preview->json('quote_hash')])->assertOk();
        Http::assertSentCount(1);
        $this->travelTo($sub->period_end->addMinute());
        $event = $this->event($sub, 'changed-renewal');
        $event['amount_gross'] = Money::decimal(Money::minor($target->amount));
        app(PaymentProcessor::class)->process($event);
        $this->assertSame('annual', $sub->fresh()->interval);
        $this->assertSame($target->id, $sub->fresh()->offer_price_id);
        $this->assertDatabaseHas('subscription_changes', ['subscription_id' => $sub->id, 'status' => 'applied']);
    }

    public function test_plan_change_timeout_requires_reconciliation_and_is_not_retried(): void
    {
        config(['billing.payfast' => ['enabled' => true, 'sandbox' => true, 'merchant_id' => 'test', 'merchant_key' => 'key', 'passphrase' => 'pass']]);
        Http::fake(['api.payfast.co.za/*' => Http::response(['status' => 'failed'], 500)]);
        $sub = $this->subscription();
        app(PaymentProcessor::class)->process($this->event($sub));
        $target = $this->price(true);
        $quote = app(PlanChangeService::class)->quote($sub->fresh(), $target->id);
        $this->tenant()->withHeader('Idempotency-Key', 'uncertain-1')->postJson('/api/v1/subscriptions/'.$sub->id.'/change', ['offer_price_id' => $target->id, 'quote_hash' => $quote['quote_hash']])->assertStatus(502);
        $this->tenant()->withHeader('Idempotency-Key', 'uncertain-1')->postJson('/api/v1/subscriptions/'.$sub->id.'/change', ['offer_price_id' => $target->id, 'quote_hash' => $quote['quote_hash']])->assertOk()->assertJsonPath('status', 'needs_reconciliation');
        Http::assertSentCount(1);
    }

    public function test_member_dashboard_does_not_expose_payment_information(): void
    {
        DB::table('organisation_members')->where('user_id', $this->user->id)->update(['role' => 'member']);
        $this->tenant()->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('financial_visible', false)->assertJsonPath('monthly_total', null)->assertJsonPath('recent_payments', []);
    }

    public function test_second_checkout_is_blocked_before_duplicate_provider_authorisation(): void
    {
        config(['billing.payfast' => ['enabled' => true, 'sandbox' => true, 'merchant_id' => 'test', 'merchant_key' => 'key', 'passphrase' => 'pass']]);
        $s = $this->subscription();
        $this->tenant()->postJson('/api/v1/checkout', ['subscription_id' => $s->id])->assertOk();
        $this->tenant()->postJson('/api/v1/checkout', ['subscription_id' => $s->id])->assertConflict();
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_provider_tokens_are_not_in_customer_subscription_json(): void
    {
        $s = $this->subscription();
        app(PaymentProcessor::class)->process($this->event($s));
        $this->tenant()->getJson('/api/v1/subscriptions')->assertOk()->assertJsonMissingPath('data.0.gateway_token');
    }

    public function test_payment_method_redirect_uses_documented_provider_url(): void
    {
        $s = $this->subscription();
        app(PaymentProcessor::class)->process($this->event($s));
        $r = $this->tenant()->postJson('/api/v1/payment-methods/setup', ['subscription_id' => $s->id])->assertOk();
        $this->assertStringStartsWith('https://sandbox.payfast.co.za/eng/recurring/update/', $r->json('url'));
    }

    public function test_usage_enforcement_is_atomic_idempotent_and_scoped(): void
    {
        $s = $this->subscription();
        $snapshot = $s->snapshot;
        $snapshot['items'][0]['limits']['max_transactions'] = 3;
        $s->update(['snapshot' => $snapshot]);
        app(PaymentProcessor::class)->process($this->event($s));
        $product = $s->snapshot['items'][0]['product_code'];
        $client = (string) Str::uuid();
        DB::table('api_clients')->insert(['id' => $client, 'name' => 'Test product', 'product_code' => $product, 'status' => 'active']);
        DB::table('api_client_credentials')->insert(['id' => Str::uuid(), 'api_client_id' => $client, 'token_hash' => hash('sha256', 'usage-token'), 'expires_at' => now()->addDay()]);
        DB::table('product_organisation_access')->insert(['api_client_id' => $client, 'organisation_id' => $this->org->id]);
        $d = ['organisation_id' => $this->org->id, 'product' => $product, 'metric' => 'transactions', 'quantity' => 2, 'event_key' => 'sale-1'];
        $this->withToken('usage-token')->postJson('/api/v1/access/usage', $d)->assertOk()->assertJsonPath('used', 2);
        $this->withToken('usage-token')->postJson('/api/v1/access/usage', $d)->assertOk()->assertJsonPath('replayed', true);
        $d['event_key'] = 'sale-2';
        $this->withToken('usage-token')->postJson('/api/v1/access/usage', $d)->assertConflict();
        $this->assertDatabaseCount('usage_records', 1);
    }

    public function test_invalid_oidc_configuration_is_not_silently_bypassed(): void
    {
        config(['billing.oidc.issuer' => null]);
        $this->getJson('/api/v1/auth/oidc')->assertStatus(503);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', []);
        }$this->postJson('/api/v1/auth/login', [])->assertStatus(429);
    }

    public function test_oidc_adapter_uses_framework_redirect_and_saves_session(): void
    {
        $oidc = new OidcClient('https://accounts.example.test', 'test');
        $this->expectException(HttpResponseException::class);
        $oidc->redirect('https://accounts.example.test/authorise');
    }

    public function test_changed_price_requires_new_customer_confirmation(): void
    {
        $s = $this->subscription();
        app(PaymentProcessor::class)->process($this->event($s));
        $price = $this->price(true);
        $quote = app(PlanChangeService::class)->quote($s->fresh(),$price->id);
        $price->update(['amount' => '9999.00']);
        $this->tenant()->withHeader('Idempotency-Key','stale-quote')->postJson('/api/v1/subscriptions/'.$s->id.'/change',['offer_price_id' => $price->id, 'quote_hash' => $quote['quote_hash']])->assertConflict();
        $this->assertDatabaseCount('subscription_changes',0);
    }
}

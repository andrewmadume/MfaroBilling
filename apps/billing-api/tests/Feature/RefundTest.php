<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Offer;
use App\Models\OfferPrice;
use App\Models\Organisation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\PaymentProcessor;
use App\Payments\RefundService;
use App\Services\BillingService;
use App\Services\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    private function context(bool $tax = false): array
    {
        $this->freezeTime();
        $this->seed();
        config(['billing.tax_basis_points' => $tax ? 1500 : 0, 'billing.payfast' => ['enabled' => true, 'refunds_enabled' => true, 'sandbox' => true, 'merchant_id' => 'test', 'merchant_key' => 'test-key', 'passphrase' => 'test-pass']]);
        $u = User::factory()->create();
        $u->is_admin = true;
        $u->save();
        $o = Organisation::create(['name' => 'Refund business', 'billing_email' => $u->email]);
        DB::table('organisation_members')->insert(['organisation_id' => $o->id, 'user_id' => $u->id, 'role' => 'owner']);
        $offer = Offer::whereNotNull('plan_id')->firstOrFail();
        $price = OfferPrice::where('offer_id', $offer->id)->where('interval', 'monthly')->firstOrFail();
        $s = app(BillingService::class)->create($o->id, ['offer_price_id' => $price->id]);
        app(PaymentProcessor::class)->process(['m_payment_id' => $s->id, 'pf_payment_id' => 'refund-payment', 'payment_status' => 'COMPLETE', 'amount_gross' => $tax ? '228.85' : '199.00', 'token' => '11111111-2222-3333-4444-555555555555']);
        $this->actingAs($u)->withHeader('X-Organisation-ID', $o->id);

        return [$u, $o, Payment::firstOrFail()];
    }

    private function eligibility(int $original = 19900, int $available = 19900, string $method = 'PAYMENT_SOURCE', int $postCode = 200): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.payfast.co.za/refunds/query/refund-payment?testing=true' => Http::response(['status' => 'REFUNDABLE', 'amount_original' => $original, 'amount_available_for_refund' => $available, 'refund_full' => ['method' => $method], 'refund_partial' => ['method' => $method]]), 'https://api.payfast.co.za/refunds/refund-payment?testing=true' => Http::response(['status' => $postCode === 200 ? 'success' : 'failed', 'data' => ['response' => $postCode === 200]], $postCode)]);
    }

    private function quote(Payment $p, string $amount = '50.00'): array
    {
        return $this->postJson('/api/v1/admin/payments/'.$p->id.'/refund-quote', ['amount' => $amount, 'reason' => 'Customer requested refund'])->assertOk()->json();
    }

    public function test_gateway_refund_is_idempotent_and_creates_one_credit_note(): void
    {
        [$u,$o,$p] = $this->context();
        $this->eligibility();
        $q = $this->quote($p);
        $d = ['amount' => '50.00', 'reason' => 'Customer requested refund', 'quote_hash' => $q['quote_hash']];
        $r = $this->withHeader('Idempotency-Key', 'refund-one')->postJson('/api/v1/admin/payments/'.$p->id.'/refund-requests', $d)->assertOk()->assertJsonPath('status', 'succeeded');
        $this->withHeader('Idempotency-Key', 'refund-one')->postJson('/api/v1/admin/payments/'.$p->id.'/refund-requests', $d)->assertOk()->assertJsonPath('id', $r->json('id'));
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('credit_notes', 1);
        $this->assertDatabaseHas('credit_notes', ['total' => '50', 'tax' => '0']);
        $this->assertSame('active', Subscription::find($p->subscription_id)->status);
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['amount'] === 5000 && $r['notify_buyer'] === 1);
    }

    public function test_uncertain_refund_is_never_automatically_resent(): void
    {
        [$u,$o,$p] = $this->context();
        $this->eligibility(postCode: 500);
        $q = $this->quote($p);
        $d = ['amount' => '50.00', 'reason' => $q['reason'], 'quote_hash' => $q['quote_hash']];
        $r = $this->withHeader('Idempotency-Key', 'uncertain')->postJson('/api/v1/admin/payments/'.$p->id.'/refund-requests', $d)->assertAccepted()->assertJsonPath('status', 'needs_reconciliation');
        $this->withHeader('Idempotency-Key', 'uncertain')->postJson('/api/v1/admin/payments/'.$p->id.'/refund-requests', $d)->assertAccepted();
        $this->postJson('/api/v1/admin/gateway-operations/'.$r->json('id').'/reconcile', [])->assertOk()->assertJsonPath('status', 'needs_reconciliation');
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertDatabaseCount('credit_notes', 0);
        Http::assertSentCount(3);
    }

    public function test_operator_resolution_requires_attestation_and_records_evidence(): void
    {
        [$u,$o,$p] = $this->context();
        $this->eligibility(postCode: 500);
        $q = $this->quote($p);
        $r = $this->withHeader('Idempotency-Key', 'uncertain')->postJson('/api/v1/admin/payments/'.$p->id.'/refund-requests', ['amount' => '50.00', 'reason' => $q['reason'], 'quote_hash' => $q['quote_hash']])->assertAccepted();
        $url = '/api/v1/admin/gateway-operations/'.$r->json('id').'/resolve';
        $this->postJson($url, ['outcome' => 'confirmed_processed', 'evidence_reference' => 'merchant-ticket-123'])->assertUnprocessable();
        $this->postJson($url, ['outcome' => 'confirmed_processed', 'evidence_reference' => 'merchant-ticket-123', 'attested' => true])->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertDatabaseCount('credit_notes', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'refund.operator_resolution']);
        Http::assertSentCount(3);
    }

    public function test_partial_refunds_allocate_tax_without_rounding_drift(): void
    {
        [$u,$o,$p] = $this->context(true);
        $service = app(RefundService::class);
        $a = $service->recordExternal($p, '100.00', 'external-a', 'First partial refund', $u->id);
        $b = $service->recordExternal($p, '128.85', 'external-b', 'Remaining refund', $u->id);
        $this->assertSame('13.04', Money::decimal(Money::minor((string) $a->tax)));
        $this->assertSame('16.81', Money::decimal(Money::minor((string) $b->tax)));
        $this->assertSame(2985, DB::table('credit_notes')->get()->sum(fn ($n) => Money::minor((string) $n->tax)));
        $this->assertDatabaseCount('credit_notes', 2);
    }

    public function test_external_refund_reference_cannot_be_reused_for_another_amount(): void
    {
        [$u,$o,$p] = $this->context();
        $d = ['amount' => '25.00', 'reference' => 'external-ref', 'reason' => 'External refund completed'];
        $this->postJson('/api/v1/admin/payments/'.$p->id.'/refunds', $d)->assertOk();
        $this->postJson('/api/v1/admin/payments/'.$p->id.'/refunds', $d)->assertOk();
        $d['amount'] = '30.00';
        $this->postJson('/api/v1/admin/payments/'.$p->id.'/refunds', $d)->assertConflict();
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('credit_notes', 1);
    }

    public function test_credit_note_download_is_tenant_scoped_and_escapes_reason(): void
    {
        [$u,$o,$p] = $this->context();
        $note = app(RefundService::class)->recordExternal($p, '50.00', 'external', '<script>alert(1)</script>', $u->id);
        $this->get('/api/v1/credit-notes/'.$note->id.'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $html = view('credit-note', ['note' => $note, 'invoice' => Invoice::find($p->invoice_id), 'customer' => ['name' => 'Business', 'billing_email' => 'a@example.test'], 'seller' => ['name' => 'Mfaro', 'vat_number' => '']])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $other = Organisation::create(['name' => 'Other', 'billing_email' => 'other@example.test']);
        DB::table('organisation_members')->insert(['organisation_id' => $other->id, 'user_id' => $u->id, 'role' => 'owner']);
        $this->withHeader('X-Organisation-ID', $other->id)->get('/api/v1/credit-notes/'.$note->id.'/download')->assertNotFound();
    }

    public function test_refund_limits_and_bank_payouts_are_rejected_before_mutation(): void
    {
        [$u,$o,$p] = $this->context();
        $this->eligibility(method: 'BANK_PAYOUT');
        $this->postJson('/api/v1/admin/payments/'.$p->id.'/refund-quote', ['amount' => '50.00', 'reason' => 'Refund request'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/payments/'.$p->id.'/refund-quote', ['amount' => '200.00', 'reason' => 'Refund request'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/payments/'.$p->id.'/refunds', ['amount' => '0.00', 'reference' => 'zero', 'reason' => 'Refund request'])->assertUnprocessable();
        $this->assertDatabaseCount('credit_notes', 0);
        Http::assertSentCount(1);
    }

    public function test_disabled_refund_execution_and_stale_quote_never_send_money(): void
    {
        [$u,$o,$p] = $this->context();
        $available = 19900;
        Http::preventStrayRequests();
        Http::fake(['https://api.payfast.co.za/refunds/query/refund-payment?testing=true' => function () use (&$available) {
            return Http::response(['status' => 'REFUNDABLE', 'amount_original' => 19900, 'amount_available_for_refund' => $available, 'refund_full' => ['method' => 'PAYMENT_SOURCE'], 'refund_partial' => ['method' => 'PAYMENT_SOURCE']]);
        }]);
        $q = $this->quote($p);
        $d = ['amount' => '50.00', 'reason' => $q['reason'], 'quote_hash' => $q['quote_hash']];
        config(['billing.payfast.refunds_enabled' => false]);
        $this->withHeader('Idempotency-Key', 'disabled')->postJson('/api/v1/admin/payments/'.$p->id.'/refund-requests', $d)->assertStatus(503);
        config(['billing.payfast.refunds_enabled' => true]);
        $available = 10000;
        $this->withHeader('Idempotency-Key', 'stale')->postJson('/api/v1/admin/payments/'.$p->id.'/refund-requests', $d)->assertConflict();
        $this->assertDatabaseCount('gateway_operations', 0);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_non_admin_cannot_refund_or_resolve_operations(): void
    {
        [$u,$o,$p] = $this->context();
        Http::preventStrayRequests();
        $u->is_admin = false;
        $u->save();
        $this->postJson('/api/v1/admin/payments/'.$p->id.'/refund-quote', ['amount' => '1.00', 'reason' => 'Refund request'])->assertForbidden();
        $this->postJson('/api/v1/admin/gateway-operations/unknown/resolve', [])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_backfill_preserves_existing_refunds_and_is_repeatable(): void
    {
        [$u,$o,$p] = $this->context();
        DB::table('payment_refunds')->insert(['id' => (string) Str::uuid(), 'payment_id' => $p->id, 'amount' => '10.00', 'reference' => 'legacy', 'reason' => 'Legacy completed refund', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('billing:backfill-credit-notes')->assertSuccessful();
        $this->artisan('billing:backfill-credit-notes')->assertSuccessful();
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('credit_notes', 1);
    }

    public function test_crashed_refund_becomes_uncertain_without_a_second_post(): void
    {
        [$u, $org, $p] = $this->context();
        $this->eligibility(postCode: 500);
        $q = $this->quote($p);
        $r = $this->withHeader('Idempotency-Key', 'crash')->postJson('/api/v1/admin/payments/'.$p->id.'/refund-requests', ['amount' => '50.00', 'reason' => $q['reason'], 'quote_hash' => $q['quote_hash']])->assertAccepted();
        DB::table('gateway_operations')->where('id', $r->json('id'))->update(['status' => 'processing', 'updated_at' => now()->subMinutes(3)]);
        $this->postJson('/api/v1/admin/gateway-operations/'.$r->json('id').'/reconcile', [])->assertOk()->assertJsonPath('status', 'needs_reconciliation');
        $this->assertDatabaseCount('credit_notes', 0);
        Http::assertSentCount(3);
    }

    public function test_another_refund_key_cannot_bypass_an_uncertain_operation(): void
    {
        [$u, $org, $p] = $this->context();
        $this->eligibility(postCode: 500);
        $q = $this->quote($p);
        $body = ['amount' => '50.00', 'reason' => $q['reason'], 'quote_hash' => $q['quote_hash']];
        $this->withHeader('Idempotency-Key', 'first')->postJson('/api/v1/admin/payments/'.$p->id.'/refund-requests', $body)->assertAccepted();
        $this->withHeader('Idempotency-Key', 'second')->postJson('/api/v1/admin/payments/'.$p->id.'/refund-requests', $body)->assertConflict();
        $this->assertDatabaseCount('gateway_operations', 1);
        Http::assertSentCount(4);
    }
}

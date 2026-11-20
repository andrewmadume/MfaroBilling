<?php

namespace App\Payments;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PayFastGateway implements PaymentGateway
{
    private function config(): array
    {
        $c = config('billing.payfast');
        abort_unless($c['enabled'] && $c['merchant_id'] && $c['merchant_key'] && $c['passphrase'], 503, 'PayFast is not configured.');

        return $c;
    }

    private function base(): string
    {
        return config('billing.payfast.sandbox') ? 'https://sandbox.payfast.co.za' : 'https://www.payfast.co.za';
    }

    public function signature(array $fields, string $passphrase): string
    {
        $pairs = [];
        foreach ($fields as $k => $v) {
            if ($k !== 'signature' && $v !== '' && $v !== null) {
                $pairs[] = $k.'='.urlencode(trim((string) $v));
            }
        }$pairs[] = 'passphrase='.urlencode(trim($passphrase));

        return md5(implode('&', $pairs));
    }

    public function checkout(Subscription $s, Invoice $i): array
    {
        $c = $this->config();
        abort_unless($s->currency === 'ZAR' && $s->status === 'pending', 422, 'Subscription is not eligible for checkout.');
        $fields = ['merchant_id' => $c['merchant_id'], 'merchant_key' => $c['merchant_key'], 'return_url' => config('billing.portal_url').'/subscriptions?checkout=returned', 'cancel_url' => config('billing.portal_url').'/subscriptions?checkout=cancelled', 'notify_url' => config('app.url').'/api/v1/webhooks/payfast', 'm_payment_id' => $s->id, 'amount' => $s->amount, 'item_name' => substr($s->snapshot['name'], 0, 100), 'subscription_type' => '1', 'billing_date' => ($s->interval === 'annual' ? now()->addYearNoOverflow() : now()->addMonthNoOverflow())->format('Y-m-d'), 'recurring_amount' => $s->amount, 'frequency' => $s->interval === 'annual' ? '6' : '3', 'cycles' => '0'];
        $fields['signature'] = $this->signature($fields, $c['passphrase']);

        return ['method' => 'POST', 'action' => $this->base().'/eng/process', 'fields' => $fields, 'provider' => 'payfast', 'sandbox' => $c['sandbox']];
    }

    public function verify(Request $r): array
    {
        $c = $this->config();
        $data = $r->request->all();
        foreach ($data as $v) {
            abort_unless(is_string($v), 400, 'Invalid notification fields.');
        }
        $required = $r->validate(['signature' => 'required|string|size:32', 'merchant_id' => 'required|string', 'm_payment_id' => 'required|uuid', 'pf_payment_id' => 'required|string|max:120', 'payment_status' => 'required|in:COMPLETE,FAILED,CANCELLED', 'amount_gross' => 'required|regex:/^\d{1,10}\.\d{2}$/']);
        abort_unless(hash_equals((string) $c['merchant_id'], $data['merchant_id']), 400, 'Merchant mismatch.');
        // ITN includes empty fields; preserve received form-field order, excluding signature.
        $pairs = [];
        foreach ($data as $k => $v) {
            if ($k !== 'signature') {
                $pairs[] = $k.'='.urlencode($v);
            }
        }$body = implode('&', $pairs);
        abort_unless(hash_equals(md5($body.'&passphrase='.urlencode($c['passphrase'])), $data['signature']), 400, 'Invalid notification signature.');
        $allowed = [];
        foreach (['www.payfast.co.za', 'sandbox.payfast.co.za', 'w1w.payfast.co.za', 'w2w.payfast.co.za'] as $host) {
            $allowed = array_merge($allowed, gethostbynamel($host) ?: []);
        }
        abort_unless(in_array($r->ip(), $allowed, true), 400, 'Unrecognised PayFast source.');
        $response = Http::timeout(15)->withOptions(['allow_redirects' => false])->withBody($body, 'application/x-www-form-urlencoded')->post($this->base().'/eng/query/validate');
        abort_unless($response->successful() && trim($response->body()) === 'VALID', 400, 'PayFast server validation failed.');

        return $data;
    }

    private function api(string $method, string $token, string $action, array $body = []): array
    {
        $c = $this->config();
        abort_unless(preg_match('/^[a-zA-Z0-9-]{16,64}$/', $token), 422, 'Invalid provider token.');
        $headers = ['merchant-id' => $c['merchant_id'], 'version' => 'v1', 'timestamp' => now('Africa/Johannesburg')->format('Y-m-d\TH:i:s')];
        $sign = array_merge($headers, $body);
        $sign['passphrase'] = $c['passphrase'];
        ksort($sign);
        $pairs = [];
        foreach ($sign as $k => $v) {
            $pairs[] = $k.'='.urlencode($v);
        }$headers['signature'] = md5(implode('&', $pairs));
        $url = 'https://api.payfast.co.za/subscriptions/'.$token.'/'.$action.($c['sandbox'] ? '?testing=true' : '');
        $response = Http::timeout(20)->withHeaders($headers)->send($method, $url, $body ? ['json' => $body] : []);
        abort_unless($response->successful() && $response->json('status') === 'success' && $response->json('data.response') !== false, 502, 'PayFast subscription operation failed; no local change was committed.');

        return $response->json();
    }

    public function cancel(string $token): void
    {
        $this->api('PUT', $token, 'cancel');
    }

    public function update(string $token, array $fields): array
    {
        return $this->api('PATCH', $token, 'update', $fields);
    }

    public function fetch(string $token): array
    {
        return $this->api('GET', $token, 'fetch');
    }
}

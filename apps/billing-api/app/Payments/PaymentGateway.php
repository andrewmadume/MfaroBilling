<?php

namespace App\Payments;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function checkout(Subscription $s, Invoice $invoice): array;

    public function verify(Request $r): array;

    public function cancel(string $token): void;

    public function pause(string $token, int $cycles = 1): void;

    public function resume(string $token): void;

    public function refundEligibility(string $reference): array;

    public function refund(string $reference, int $amount, string $reason): void;

    public function fetch(string $token): array;
}

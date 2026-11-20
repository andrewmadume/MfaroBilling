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

    public function fetch(string $token): array;
}

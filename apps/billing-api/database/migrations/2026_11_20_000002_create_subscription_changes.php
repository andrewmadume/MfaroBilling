<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('offer_price_id')->constrained()->restrictOnDelete();
            $t->string('idempotency_key');
            $t->string('status');
            $t->decimal('amount', 14, 2);
            $t->json('snapshot');
            $t->timestamp('effective_at');
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
            $t->unique(['organisation_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only financial history.');
    }
};

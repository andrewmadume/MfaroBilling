<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->timestamp('paused_at')->nullable();
            $t->unsignedInteger('pause_cycles')->nullable();
        });
        Schema::create('gateway_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('payment_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('kind');
            $t->string('idempotency_key', 120);
            $t->string('request_hash', 64);
            $t->string('status')->index();
            $t->json('payload');
            $t->json('result')->nullable();
            $t->string('failure_code')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['organisation_id', 'idempotency_key']);
            $t->index(['subscription_id', 'status']);
            $t->index(['payment_id', 'status']);
        });
        Schema::create('credit_notes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('payment_refund_id')->unique()->constrained()->restrictOnDelete();
            $t->string('number')->unique();
            $t->char('currency', 3);
            $t->decimal('subtotal', 14, 2);
            $t->decimal('tax', 14, 2);
            $t->decimal('total', 14, 2);
            $t->string('reason', 500);
            $t->timestamp('issued_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Do not delete payment operation or credit-note history.');
    }
};

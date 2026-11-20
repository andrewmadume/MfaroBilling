<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false);
        });
        Schema::create('organisations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('billing_email');
            $t->string('registration_number')->nullable();
            $t->string('vat_number')->nullable();
            $t->json('billing_address')->nullable();
            $t->timestamps();
        });
        Schema::create('organisation_members', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('role');
            $t->timestamps();
            $t->unique(['organisation_id', 'user_id']);
        });
        Schema::create('organisation_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->string('email');
            $t->string('role');
            $t->string('token_hash')->unique();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('identity_provider_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('issuer', 160);
            $t->string('subject', 160);
            $t->timestamps();
            $t->unique(['issuer', 'subject']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description');
            $t->string('website_url')->nullable();
            $t->string('icon')->default('boxes');
            $t->string('status')->default('active');
            $t->json('features');
            $t->timestamps();
        });
        Schema::create('plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->string('code');
            $t->string('name');
            $t->text('description')->nullable();
            $t->json('features');
            $t->json('limits');
            $t->unsignedInteger('trial_days')->default(0);
            $t->boolean('featured')->default(false);
            $t->unsignedInteger('display_order')->default(0);
            $t->string('status')->default('active');
            $t->timestamps();
            $t->unique(['product_id', 'code']);
        });
        Schema::create('bundles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description');
            $t->string('status')->default('active');
            $t->unsignedInteger('trial_days')->default(0);
            $t->timestamps();
        });
        Schema::create('bundle_items', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('bundle_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $t->unique(['bundle_id', 'plan_id']);
        });
        Schema::create('offers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('plan_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('bundle_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('status')->default('active');
            $t->timestamps();
            $t->unique('plan_id');
            $t->unique('bundle_id');
        });
        Schema::create('offer_prices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('offer_id')->constrained()->restrictOnDelete();
            $t->string('interval');
            $t->char('currency', 3);
            $t->decimal('amount', 14, 2);
            $t->timestamps();
            $t->unique(['offer_id', 'interval', 'currency']);
        });
        Schema::create('price_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('offer_price_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->char('currency', 3);
            $t->timestamp('effective_at');
        });
        Schema::create('coupons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->unsignedInteger('percent');
            $t->timestamp('expires_at')->nullable();
            $t->unsignedInteger('max_redemptions')->nullable();
            $t->unsignedInteger('redemptions')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('offer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('offer_price_id')->constrained()->restrictOnDelete();
            $t->string('status')->index();
            $t->string('interval');
            $t->char('currency', 3);
            $t->decimal('amount', 14, 2);
            $t->json('snapshot');
            $t->timestamp('period_start')->nullable();
            $t->timestamp('period_end')->nullable()->index();
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('grace_ends_at')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->string('gateway_token')->nullable()->unique();
            $t->string('gateway_status')->nullable();
            $t->timestamps();
            $t->index(['organisation_id', 'status']);
        });
        Schema::create('subscription_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $t->string('product_code');
            $t->json('snapshot');
            $t->timestamps();
            $t->unique(['subscription_id', 'product_code']);
        });
        Schema::create('subscription_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $t->string('type');
            $t->json('payload');
            $t->timestamp('created_at');
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $t->string('number')->unique();
            $t->string('status');
            $t->char('currency', 3);
            $t->decimal('subtotal', 14, 2);
            $t->decimal('tax', 14, 2);
            $t->decimal('total', 14, 2);
            $t->json('customer_snapshot');
            $t->json('seller_snapshot');
            $t->timestamp('issued_at');
            $t->timestamp('due_at');
            $t->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $t->string('description');
            $t->unsignedInteger('quantity');
            $t->decimal('unit_price', 14, 2);
            $t->decimal('total', 14, 2);
        });
        Schema::create('payment_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $t->string('status');
            $t->decimal('amount', 14, 2);
            $t->char('currency', 3);
            $t->timestamps();
        });
        Schema::create('gateway_webhook_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('provider');
            $t->string('event_key');
            $t->json('payload');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'event_key']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $t->string('provider');
            $t->string('provider_reference');
            $t->decimal('amount', 14, 2);
            $t->char('currency', 3);
            $t->string('status');
            $t->timestamp('paid_at');
            $t->timestamps();
            $t->unique(['provider', 'provider_reference']);
        });
        Schema::create('payment_refunds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->string('reference')->unique();
            $t->string('reason');
            $t->timestamps();
        });
        Schema::create('entitlement_grants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $t->string('product_code');
            $t->json('features');
            $t->json('limits');
            $t->timestamp('valid_until');
            $t->timestamps();
            $t->unique(['subscription_id', 'product_code']);
            $t->index(['organisation_id', 'product_code']);
        });
        Schema::create('usage_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->string('product_code');
            $t->string('metric');
            $t->unsignedBigInteger('quantity');
            $t->string('event_key')->unique();
            $t->timestamp('recorded_at');
            $t->index(['organisation_id', 'product_code', 'metric']);
        });
        Schema::create('api_clients', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('product_code');
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::create('api_client_credentials', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('api_client_id')->constrained()->restrictOnDelete();
            $t->string('token_hash')->unique();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('product_organisation_access', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('api_client_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->unique(['api_client_id', 'organisation_id']);
        });
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->string('key');
            $t->string('request_hash');
            $t->json('response');
            $t->timestamps();
            $t->unique(['organisation_id', 'key']);
        });
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type');
            $t->string('recipient');
            $t->json('payload');
            $t->string('deduplication_key')->unique();
            $t->timestamp('delivered_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->text('message');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->uuid('organisation_id')->nullable()->index();
            $t->string('action');
            $t->json('payload');
            $t->string('ip_address')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('system_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
            $t->timestamps();
        });
        Schema::create('support_tickets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('subject');
            $t->text('message');
            $t->string('status')->default('open');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Financial schema is forward-only. Restore a verified backup for rollback.');
    }
};

<?php

use App\Entitlements\EntitlementService;
use App\Http\Controllers\AdminController as Admin;
use App\Http\Controllers\AuthController as Auth;
use App\Http\Controllers\CatalogueController as Catalogue;
use App\Http\Controllers\OrganisationController as Org;
use App\Http\Controllers\PlanChangeController;
use App\Http\Controllers\PortalController as Portal;
use App\Http\Controllers\UsageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware('throttle:120,1')->group(function () {
    Route::post('auth/backchannel-logout', [Auth::class, 'backchannel']);
    Route::get('auth/session', [Auth::class, 'session']);
    Route::post('auth/login', [Auth::class, 'login'])->middleware('throttle:5,1');
    Route::get('auth/oidc', [Auth::class, 'oidc']);
    Route::get('auth/callback', [Auth::class, 'oidc']);
    Route::get('products', [Catalogue::class, 'products']);
    Route::get('offers', [Catalogue::class, 'offers']);
    Route::post('webhooks/payfast', [Portal::class, 'webhook'])->middleware('throttle:120,1');
    Route::middleware('product.client')->group(function () {
        Route::post('access/usage', [UsageController::class, 'consume']);
        Route::post('access/check', function (Request $r, EntitlementService $e) {
            return $e->effective($r->input('organisation_id'), $r->input('product'));
        });
    });
    Route::middleware('auth')->group(function () {
        Route::get('auth/me', fn (Request $r) => $r->user());
        Route::post('auth/logout', [Auth::class, 'logout']);
        Route::get('auth/export', [Auth::class, 'export']);
        Route::post('auth/deletion-request', [Auth::class, 'deletion']);
        Route::get('auth/sessions', [Auth::class, 'sessions']);
        Route::delete('auth/sessions', [Auth::class, 'revokeSessions']);
        Route::get('organisations', [Org::class, 'index']);
        Route::post('organisations', [Org::class, 'create']);
        Route::post('invitations/accept', [Org::class, 'accept']);
        Route::middleware('tenant')->group(function () {
            Route::get('dashboard', [Portal::class, 'dashboard']);
            Route::patch('organisation', [Org::class, 'update']);
            Route::get('organisation/members', [Org::class, 'members']);
            Route::get('organisation/invitations', [Org::class, 'invitations']);
            Route::post('organisation/invitations', [Org::class, 'invite']);
            Route::patch('organisation/members/{user}', [Org::class, 'role']);
            Route::delete('organisation/members/{user}', [Org::class, 'remove']);
            Route::get('subscriptions', [Portal::class, 'subscriptions']);
            Route::post('subscriptions', [Portal::class, 'create']);
            Route::post('subscriptions/trial', [Portal::class, 'trial']);
            Route::post('subscriptions/{id}/change-preview', [PlanChangeController::class, 'preview']);
            Route::post('subscriptions/{id}/change', [PlanChangeController::class, 'schedule']);
            Route::get('subscriptions/{id}/changes', [PlanChangeController::class, 'history']);
            Route::get('subscriptions/{id}', [Portal::class, 'detail']);
            Route::post('subscriptions/{id}/cancel', [Portal::class, 'cancel']);
            Route::post('checkout', [Portal::class, 'checkout']);
            Route::get('invoices', [Portal::class, 'invoices']);
            Route::get('invoices/{id}', [Portal::class, 'invoice']);
            Route::get('invoices/{id}/download', [Portal::class, 'pdf']);
            Route::get('payments', [Portal::class, 'payments']);
            Route::get('payment-methods', [Portal::class, 'paymentMethods']);
            Route::post('payment-methods/setup', [Portal::class, 'paymentMethodSetup']);
            Route::get('entitlements', [Portal::class, 'entitlements']);
            Route::get('support', [Portal::class, 'support']);
            Route::post('support', [Portal::class, 'ticket']);
        });
        Route::prefix('admin')->middleware('platform.admin')->group(function () {
            Route::get('dashboard', [Admin::class, 'dashboard']);
            Route::get('settings', [Admin::class, 'settings']);
            Route::patch('settings', [Admin::class, 'saveSettings']);
            Route::get('reports/payments.csv', [Admin::class, 'csv']);
            Route::post('api-clients', [Admin::class, 'credential']);
            Route::delete('api-clients/{id}', [Admin::class, 'revoke']);
            Route::post('payments/{id}/refunds', [Admin::class, 'refund']);
            Route::get('{resource}', [Admin::class, 'index']);
            Route::post('{resource}', [Admin::class, 'save']);
            Route::patch('{resource}/{id}',[Admin::class, 'save']);
            Route::delete('{resource}/{id}',[Admin::class, 'retire']);
        });
    });
});

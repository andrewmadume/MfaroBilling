<?php

return [
    'portal_url' => env('PORTAL_URL', 'https://billing.mfarosoftware.africa'),
    'admin_url' => env('ADMIN_URL', 'https://admin.billing.mfarosoftware.africa'),
    'company_name' => env('COMPANY_NAME', 'Mfaro Software'),
    'company_address' => env('COMPANY_ADDRESS', ''),
    'vat_number' => env('COMPANY_VAT_NUMBER', ''),
    'tax_basis_points' => (int) env('TAX_BASIS_POINTS', 0),
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 7),
    'local_auth' => (bool) env('LOCAL_AUTH_ENABLED', false),
    'oidc' => ['issuer' => env('OIDC_ISSUER'), 'client_id' => env('OIDC_CLIENT_ID'), 'client_secret' => env('OIDC_CLIENT_SECRET'), 'redirect_uri' => env('OIDC_REDIRECT_URI'), 'account_url' => env('OIDC_ACCOUNT_URL')],
    'payfast' => ['enabled' => (bool) env('PAYFAST_ENABLED', false), 'sandbox' => (bool) env('PAYFAST_SANDBOX', true), 'merchant_id' => env('PAYFAST_MERCHANT_ID'), 'merchant_key' => env('PAYFAST_MERCHANT_KEY'), 'passphrase' => env('PAYFAST_PASSPHRASE')],
];

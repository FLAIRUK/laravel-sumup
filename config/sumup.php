<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Create an API key at https://me.sumup.com/settings/api-keys. Use a key
    | from a sandbox merchant (https://me.sumup.com/settings/developer) to test
    | without processing real transactions.
    | SUMUP_ACCESS_TOKEN, if set, is used instead of the API key, e.g. a token
    | from the OAuth client credentials flow.
    |
    | The merchant code (e.g. MH4H92C7) is shown in the dashboard and is needed
    | for checkouts, transactions, refunds, readers and the merchant profile.
    |
    | @see https://developer.sumup.com/api/authentication
    |
    */

    'api_key' => env('SUMUP_API_KEY'),

    'access_token' => env('SUMUP_ACCESS_TOKEN'),

    'merchant_code' => env('SUMUP_MERCHANT_CODE'),

    // The default checkout currency and the one SumUp::money() uses.
    'currency' => env('SUMUP_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | OAuth 2.0 (apps that act for other merchants)
    |--------------------------------------------------------------------------
    |
    | Register an OAuth application at
    | https://me.sumup.com/settings/oauth2-applications. Scopes are
    | space-separated in SUMUP_SCOPES; leave it empty for SumUp's defaults.
    | "payments" and "payment_instruments" need manual verification by SumUp
    | before your app can request them.
    |
    | @see https://developer.sumup.com/tools/authorization/oauth
    |
    */

    'oauth' => [
        'client_id' => env('SUMUP_CLIENT_ID'),
        'client_secret' => env('SUMUP_CLIENT_SECRET'),
        'redirect_uri' => env('SUMUP_REDIRECT_URI'),
        'scopes' => array_values(array_filter(explode(' ', (string) env('SUMUP_SCOPES', '')))),
        'authorize_url' => 'https://api.sumup.com/authorize',
        'token_url' => 'https://api.sumup.com/token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | SumUp posts a notification to a checkout's `return_url`. The route below
    | receives it, confirms it with the API and dispatches a Laravel event; use
    | SumUp::webhookUrl() as the return_url. It is registered without the "web"
    | middleware group, so CSRF does not apply. SumUp must be able to reach it:
    | it has to be public and HTTPS.
    |
    | Notifications are unsigned and each one costs an API call to confirm, so
    | the route is rate limited by IP. SumUp sends one per status change, so
    | 60 a minute is generous; raise it if many checkouts share one address.
    |
    */

    'webhooks' => [
        'enabled' => env('SUMUP_WEBHOOKS', true),
        'path' => env('SUMUP_WEBHOOK_PATH', 'sumup/webhook'),
        'middleware' => ['throttle:60,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Widget
    |--------------------------------------------------------------------------
    |
    | Used by the <x-sumup-card> Blade component. A null locale uses the
    | widget's default (en-GB).
    |
    | @see https://developer.sumup.com/online-payments/checkouts/card-widget
    |
    */

    'widget' => [
        'script_url' => 'https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js',
        'locale' => env('SUMUP_WIDGET_LOCALE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'base_url' => env('SUMUP_BASE_URL', 'https://api.sumup.com'),

    'timeout' => (int) env('SUMUP_TIMEOUT', 30),

    'connect_timeout' => 10,

    // GET requests are retried on connection errors and 5xx responses: [times, sleep milliseconds].
    // Writes are never retried automatically, so a payment is never refunded twice.
    'retry' => [2, 250],

];

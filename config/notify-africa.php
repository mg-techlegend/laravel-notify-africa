<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API token
    |--------------------------------------------------------------------------
    |
    | Bearer token from your Notify Africa dashboard. Never commit this value.
    |
    */
    'api_token' => env('NOTIFY_AFRICA_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Default sender ID
    |--------------------------------------------------------------------------
    |
    | Provisioned sender ID from Notify Africa. You may override per message.
    |
    */
    'sender_id' => env('NOTIFY_AFRICA_SENDER_ID'),

    /*
    |--------------------------------------------------------------------------
    | API base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('NOTIFY_AFRICA_BASE_URL', 'https://api.notify.africa'),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeouts (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => (float) env('NOTIFY_AFRICA_TIMEOUT', 10),

    'connect_timeout' => (float) env('NOTIFY_AFRICA_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | HTTP retries (transient failures only)
    |--------------------------------------------------------------------------
    |
    | Total attempts per request (1 = no retries). Retries apply only to
    | connection errors and selected status codes (408, 425, 429, 5xx).
    | Failed responses are still returned after the last attempt so API errors
    | map to package exceptions as usual. SMS may duplicate if a request
    | succeeded upstream but the connection dropped before the response—
    | keep attempts low unless you accept that risk.
    |
    */
    'http_retry_attempts' => max(1, (int) env('NOTIFY_AFRICA_HTTP_RETRY_ATTEMPTS', 1)),

    'http_retry_delay_ms' => max(0, (int) env('NOTIFY_AFRICA_HTTP_RETRY_DELAY_MS', 250)),

    /*
    |--------------------------------------------------------------------------
    | Default country calling code (optional)
    |--------------------------------------------------------------------------
    |
    | When the number looks local (9–10 digits after stripping non-digits),
    | this prefix is prepended. This is not a full libphonenumber replacement.
    |
    */
    'default_country_calling_code' => env('NOTIFY_AFRICA_DEFAULT_COUNTRY_CODE'),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business (WABA)
    |--------------------------------------------------------------------------
    |
    | WABA uses its OWN base URL and API key — a different host and credential
    | from SMS. Do not reuse NOTIFY_AFRICA_API_TOKEN here. The HTTP retry and
    | default country calling code settings above are shared with this client.
    |
    */
    'waba' => [
        'base_url' => env('NOTIFY_WABA_BASE_URL', 'https://notify-web-assistant-api.beagile.africa'),
        'api_key' => env('NOTIFY_WABA_API_KEY'),
        'timeout' => (float) env('NOTIFY_WABA_TIMEOUT', 10),
        'connect_timeout' => (float) env('NOTIFY_WABA_CONNECT_TIMEOUT', 5),
        'webhook_secret' => env('NOTIFY_WABA_WEBHOOK_SECRET'),
        'signature_header' => env('NOTIFY_WABA_SIGNATURE_HEADER', 'X-Notify-Signature'),
    ],

];

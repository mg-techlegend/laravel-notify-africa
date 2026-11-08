<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API token
    |--------------------------------------------------------------------------
    |
    | ntfy_ API key from the Notify Africa Portal. Never commit this value.
    | A key with both SMS and WABA access also serves WhatsApp (see below).
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
    | Same host as SMS (https://api.notify.africa, paths under /v1/waba-api).
    | Since 2026-09-07 one ntfy_ key can carry both SMS and WABA access, so
    | api_key falls back to NOTIFY_AFRICA_API_TOKEN when NOTIFY_WABA_API_KEY
    | is unset. The HTTP retry and default country calling code settings above
    | are shared with this client.
    |
    | webhook_secret is the Portal-generated whsec_ signing secret. Callbacks
    | are rejected when it is unset. webhook_tolerance is the replay window in
    | seconds (0 turns the timestamp age check off).
    |
    */
    'waba' => [
        'base_url' => env('NOTIFY_WABA_BASE_URL', env('NOTIFY_AFRICA_BASE_URL', 'https://api.notify.africa')),
        'api_key' => env('NOTIFY_WABA_API_KEY', env('NOTIFY_AFRICA_API_TOKEN')),
        'timeout' => (float) env('NOTIFY_WABA_TIMEOUT', 10),
        'connect_timeout' => (float) env('NOTIFY_WABA_CONNECT_TIMEOUT', 5),
        'webhook_secret' => env('NOTIFY_WABA_WEBHOOK_SECRET'),
        'webhook_tolerance' => (int) env('NOTIFY_WABA_WEBHOOK_TOLERANCE', 300),
    ],

];

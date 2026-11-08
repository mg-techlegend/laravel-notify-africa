# Laravel Notify Africa

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mg-techlegend/laravel-notify-africa.svg?style=flat-square)](https://packagist.org/packages/mg-techlegend/laravel-notify-africa)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mg-techlegend/laravel-notify-africa/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mg-techlegend/laravel-notify-africa/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/mg-techlegend/laravel-notify-africa/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/mg-techlegend/laravel-notify-africa/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/mg-techlegend/laravel-notify-africa.svg?style=flat-square)](https://packagist.org/packages/mg-techlegend/laravel-notify-africa)

Send SMS and WhatsApp (WABA) messages through the [Notify Africa](https://notify.africa/) API from Laravel apps. This package provides a small typed client, a fluent message builder, structured response objects, WhatsApp template management, signed-webhook verification, Laravel Notification channel support, and a facade. It uses Laravel’s HTTP client, so your code never calls Guzzle directly.

It follows the Notify Africa docs at [docs.notify.africa](https://docs.notify.africa) as of the WABA contract of 2026-09-07.

## Installation

```bash
composer require mg-techlegend/laravel-notify-africa
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="laravel-notify-africa-config"
```

Laravel discovers the service provider and facade automatically from `composer.json` (`extra.laravel.providers` and `extra.laravel.aliases`). No manual registration is required in typical apps.

Set the following in your `.env` (values are read **only** through `config/notify-africa.php`):

| Variable | Description |
|----------|-------------|
| `NOTIFY_AFRICA_API_TOKEN` | `ntfy_` API key from the Notify Africa Portal. A key with both SMS and WABA access also serves WhatsApp |
| `NOTIFY_AFRICA_SENDER_ID` | Default sender ID (can be overridden per message) |
| `NOTIFY_AFRICA_BASE_URL` | Optional; default `https://api.notify.africa` |
| `NOTIFY_AFRICA_TIMEOUT` | Request timeout in seconds (default `10`) |
| `NOTIFY_AFRICA_CONNECT_TIMEOUT` | Connect timeout in seconds (default `5`) |
| `NOTIFY_AFRICA_HTTP_RETRY_ATTEMPTS` | Total HTTP attempts per call (default `1` = no retries); see [HTTP retries](#http-retries) |
| `NOTIFY_AFRICA_HTTP_RETRY_DELAY_MS` | Delay in milliseconds between retries (default `250`) |
| `NOTIFY_AFRICA_DEFAULT_COUNTRY_CODE` | Optional; see [Phone numbers](#phone-numbers) |
| `NOTIFY_WABA_API_KEY` | Optional; a separate key for WhatsApp. Falls back to `NOTIFY_AFRICA_API_TOKEN` when unset; see [WhatsApp (WABA)](#whatsapp-waba) |
| `NOTIFY_WABA_BASE_URL` | Optional; falls back to `NOTIFY_AFRICA_BASE_URL`, then `https://api.notify.africa` |
| `NOTIFY_WABA_TIMEOUT` | WABA request timeout in seconds (default `10`) |
| `NOTIFY_WABA_CONNECT_TIMEOUT` | WABA connect timeout in seconds (default `5`) |
| `NOTIFY_WABA_WEBHOOK_SECRET` | The Portal's `whsec_…` webhook signing secret. Callbacks are rejected while it is unset |
| `NOTIFY_WABA_WEBHOOK_TOLERANCE` | Webhook replay window in seconds (default `300`; `0` turns the timestamp age check off) |

## Configuration

Published config: `config/notify-africa.php`.

- **`api_token`** — required for real API calls (missing token throws when the client is built).
- **`sender_id`** — default sender; omit on the message object to use this value.
- **`http_retry_attempts`** / **`http_retry_delay_ms`** — optional resilient requests; see below.
- **`default_country_calling_code`** — digits only, no `+` (e.g. `255`). Used only for “local-looking” numbers; see below.

## HTTP retries

When `http_retry_attempts` is greater than `1`, the client retries **only** on connection failures and on HTTP **408**, **425**, **429**, and **5xx** responses. **4xx** errors such as **401** and **422** are not retried.

Retries use Laravel’s HTTP client (`throw: false` on the pending request) so the last response is always parsed and mapped to the same package exceptions. Increasing retries can mean duplicate SMS if a request succeeds at the gateway but the response never reaches your server—keep attempts conservative unless you accept that trade-off.

## Direct usage

Inject the entry service or use the facade:

```php
use TechLegend\LaravelNotifyAfrica\Facades\LaravelNotifyAfrica;
use TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica as NotifyAfrica;
use TechLegend\LaravelNotifyAfrica\NotifyAfricaMessage;

// Facade
$response = LaravelNotifyAfrica::sendSms(
    LaravelNotifyAfrica::message()
        ->to('255689737459')
        ->content('Hello from Laravel!')
        // ->senderId('CUSTOM') // optional override
);

// Container (class or string alias registered by the package)
$notify = app(NotifyAfrica::class);
// $notify = app('notify-africa');

$response = $notify->sendSms(
    NotifyAfricaMessage::make()
        ->to('255689737459')
        ->content('Hello!')
);
```

`$response` is a `SendSmsResponse` with `messageId` (a UUID), `deliveryStatus` (e.g. `PROCESSING`), and envelope metadata. The API answers a send with HTTP `202`. Any 2xx whose envelope `status` is also 2xx counts as success.

There is no idempotency key for SMS, so a retried send can go out (and be charged) twice. Reconcile an uncertain send before resending.

### Bulk SMS (same text)

Uses the batch endpoint `POST /api/v1/api/messages/batch` (one request, not a client-side loop). It returns counts and the balance but **no per-recipient message IDs**:

```php
use TechLegend\LaravelNotifyAfrica\Facades\LaravelNotifyAfrica;

$response = LaravelNotifyAfrica::sendBulkSms(
    ['255763765548', '255689737839'],
    'Same text for everyone',
    // optional third argument: sender ID override; otherwise config default is used
);

// $response->messageCount, creditsDeducted, remainingBalance
```

### Personalised or scheduled bulk SMS

Uses `POST /api/v1/api/messages/send-bulk`. Each message has its own text, and the result gives one message UUID per accepted recipient:

```php
$response = LaravelNotifyAfrica::sendPersonalizedSms(
    [
        ['to' => '255712345678', 'message' => 'Hello Asha, your hearing is on Monday.'],
        ['to' => '255713456789', 'message' => 'Hello Baraka, your fee note is ready.'],
    ],
    senderId: null,                                   // optional; config default otherwise
    scheduledAt: now()->addDay()->setTime(8, 0),      // optional; one schedule for the batch
);

$response->acceptedCount;   // 2
$response->rejectedCount;   // 0
$response->messageIds();    // ['d9b54c80-…', 'fe245daf-…']
$response->results[0];      // PersonalizedSmsResult: messageId, status ("scheduled"), estimatedSegments, debitApplied
$response->creditsDeducted; $response->remainingBalance;
```

`recipient` / `content` are accepted as aliases of `to` / `message`. Numbers are normalised and sent in E.164 with `+`, as this endpoint requires. A `DateTimeInterface` is sent as UTC ISO 8601; a string is passed through unchanged. `results[].status` is lowercase in this operation.

### Delivery status

```php
$status = LaravelNotifyAfrica::getMessageStatus('d9b54c80-8a63-4f8b-83c8-71f2439f5429');
// $status->status: PROCESSING | SCHEDULED | SENT | PARTIAL_SUCCESS | DELIVERED | FAILED
// $status->sentAt, $status->deliveredAt
```

There is no SMS delivery webhook, so poll until `DELIVERED` or `FAILED`. Unknown, malformed and other-customer IDs all return HTTP `403`, which throws `NotifyAfricaNotFoundException`.

## WhatsApp (WABA)

WhatsApp uses the same host as SMS: `https://api.notify.africa`, with paths under `/v1/waba-api`. Since 2026-09-07 one `ntfy_` key can carry both SMS and WABA access, so `NOTIFY_WABA_API_KEY` is optional. When it is unset, the client uses `NOTIFY_AFRICA_API_TOKEN`. The HTTP retry and default country calling code settings are shared with the SMS client.

The WhatsApp service is resolved lazily, so SMS-only apps need no WABA setup until they call `whatsapp()`. WABA onboarding (Meta Embedded Signup, phone number, webhook endpoints, signing secret) happens in the Notify Africa Portal. Each account sends from its one selected number.

### Sending

```php
use TechLegend\LaravelNotifyAfrica\Facades\LaravelNotifyAfrica;

$wa = LaravelNotifyAfrica::whatsapp();

// Free text: only inside the 24-hour window after the customer's last message.
$wa->sendText('255700000001', 'Habari! Karibu.', idempotencyKey: 'support-update-1001');

// Approved template, flat body values ({{1}}, {{2}}). Language defaults to "en".
$wa->sendTemplate('255700000001', 'order_update', [1 => 'Jane', 2 => 'ORD-1001']);

// Swahili template.
$wa->sendTemplate('255700000001', 'order_update', ['Jane', 'ORD-1001'], 'sw');   // a 0-based list is re-keyed from 1

// AUTHENTICATION (OTP) template: the code shortcut works for copy-code and one-tap.
$wa->sendOtp('255700000001', 'login_otp', '123456', 'sw', idempotencyKey: 'login-42-1');
// same as: $wa->sendTemplate('255700000001', 'login_otp', ['code' => '123456'], 'sw', 'login-42-1');

// Grouped header / body / buttons.
$wa->sendTemplate('255700000001', 'order_update', [
    'header' => [1 => 'Acme'],
    'body' => [1 => 'Jane', 2 => 'ORD-1001'],
    'buttons' => [['sub_type' => 'quick_reply', 'parameters' => [['type' => 'payload', 'payload' => 'order_1001']]]],
]);

// Several recipients (1–500 per request; normalised like SMS, duplicates removed).
$wa->sendText(['255700000001', '255700000002'], 'Hi');
```

Both calls return a `WhatsAppSendResponse`. Its `results` holds one `WhatsAppRecipientResult` per recipient, with `to`, `success`, `messageId`, `wamid`, `status` and `error`.

- An HTTP `200` can mix accepted and rejected recipients, so check `allSucceeded()` or `failures()`.
- `success: true` means **queued**. The final state (`sent`, `delivered`, `read`, `failed`) arrives only by webhook; the API has no message-status endpoint.
- `wamid` is often missing at send time. Match webhooks to sends by `messageId`, or by `wamid` once the webhook supplies it.
- With an `idempotencyKey`, Notify deduplicates per recipient as `<key>:<recipient>`. Reuse the same key when retrying one logical send, and use a new key for a new send.
- Template parameter keys are `int|string`, because PHP turns `'1'` into `1`. An empty parameter array omits `template_parameters`.
- Media headers cannot be filled at send time. They are fixed when the template is created (`sampleMediaServiceId`).

### Templates

Templates are created and managed under `/v1/waba-api/templates`. Creating a template submits it to Meta straight away, with status `pending`. AUTHENTICATION templates can only be created through the API, not the Portal.

```php
$template = $wa->createTemplate([
    'name' => 'Login OTP',
    'externalTemplateName' => 'login_otp',
    'externalTemplateLanguage' => 'sw',
    'externalTemplateCategory' => 'AUTHENTICATION',     // UTILITY | MARKETING | AUTHENTICATION
    'componentSchema' => [
        ['type' => 'body', 'addSecurityRecommendation' => true],
        ['type' => 'footer', 'codeExpirationMinutes' => 10],
        ['type' => 'buttons', 'buttons' => [['type' => 'otp', 'otpType' => 'copy_code']]],
    ],
    'variableSchema' => [],
]);                                                     // channel defaults to "whatsapp"

$wa->getTemplateStatus($template->id)->isApproved();    // pending | approved | rejected
$wa->getTemplate($template->id);                        // WabaTemplate: providerStatus, statusInfo (rejection reasons), componentSchema…
$wa->listTemplates(['status' => 'approved', 'pageSize' => 25]);   // WabaTemplateList with pagination

$client = $wa->client();                                // WabaClient
$client->updateTemplate($id, [...]);
$client->syncTemplate($id);                             // refresh from Meta (does not resubmit)
$client->validateTemplateBindings($id, [1 => 'Jane']);  // ['valid' => bool, 'errors' => [...]]
$client->previewTemplate($id, [1 => 'Jane']);           // local placeholder substitution
$client->deleteTemplate($id);
```

### Webhooks

Webhook endpoints, event subscriptions and the `whsec_…` secret are set up in the Portal, which can also queue a test delivery. Events are `message.received`, `message.status.updated` and `template.status.updated`.

```php
use Illuminate\Http\Request;
use TechLegend\LaravelNotifyAfrica\Waba\WabaWebhookHandler;

Route::post('/webhooks/notify/whatsapp', function (Request $request, WabaWebhookHandler $handler) {
    $result = $handler->handle($request);

    if (! $result['successful']) {
        return response()->json(['message' => $result['message']], $result['status_code']);
    }

    $event = $result['event'];   // WabaWebhookEvent

    // Delivery is at least once and may arrive out of order: insert $event->eventId under a
    // unique constraint, skip when it already exists, and compare $event->occurredAt before
    // overwriting a newer status.
    match (true) {
        $event->isTest => null,
        $event->isMessageReceived() => /* $event->customerPhoneNumber, $event->text, $event->wamid */ null,
        $event->isMessageStatus() => /* $event->messageId, $event->wamid, $event->status, $event->errorCode, $event->retryable */ null,
        $event->isTemplateStatus() => /* $event->templateName, $event->templateLanguage, $event->status */ null,
        default => null,
    };

    return response()->json(['ok' => true]);
});
```

Verification follows the docs:

- headers `x-notify-webhook-timestamp` (unix seconds) and `x-notify-webhook-signature: sha256=<hex>`;
- HMAC-SHA256 of `<timestamp>.<raw body>` with the secret, compared in constant time on the raw bytes before any JSON parsing;
- the timestamp must be within `NOTIFY_WABA_WEBHOOK_TOLERANCE` seconds of now (default 300).

`handle()` returns these status codes:

| Result | `status_code` | Notify's behaviour |
|---|---|---|
| Accepted | `200` | Delivered |
| Bad, missing or stale signature/timestamp (`reason`: `signature_mismatch`, `malformed_signature`, `missing_signature`, `missing_timestamp`, `stale_timestamp`) | `401` | Discarded |
| Signed body is not JSON (`invalid_json`) | `400` | Discarded |
| `NOTIFY_WABA_WEBHOOK_SECRET` not set (`missing_secret`) | `500` | Retried for up to 24 h |

The handler logs nothing from the request. A rejection logs one `warning` with only the `reason`. For custom routing, `verify($rawBody, $timestamp, $signature)`, `verifyAndParse($request)` and `parse($payload)` are also public.

To send yourself a signed test request:

```bash
body='{"eventId":"test-event","eventType":"message.received","data":{"test":true}}'
ts="$(date +%s)"
sig="sha256=$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$NOTIFY_WABA_WEBHOOK_SECRET" -hex | awk '{print $2}')"
curl -X POST "$YOUR_WEBHOOK_URL" -H 'Content-Type: application/json' \
  -H "x-notify-webhook-timestamp: $ts" -H "x-notify-webhook-signature: $sig" --data "$body"
```

## Exceptions

| Exception | When |
|-----------|------|
| `NotifyAfricaAuthenticationException` | HTTP 401, and 403 except on SMS status lookups |
| `NotifyAfricaNotFoundException` (extends `NotifyAfricaRequestException`) | SMS status lookup 403 ("Message not found or not authorized"); WABA 404 (template not found) |
| `NotifyAfricaSenderException` (extends `NotifyAfricaValidationException`) | SMS send/batch/send-bulk 404, 409 or 422. `reason` is `not_found`, `limit_reached` or `not_approved`; fix it in the Portal |
| `NotifyAfricaValidationException` | HTTP 400 / 422 (WABA: also 409 duplicate template, 422 WABA not ready), or an HTTP 2xx whose envelope `status` is not 2xx |
| `NotifyAfricaRequestException` | Other failures, non-JSON responses, 5xx, connection issues |
| `NotifyAfricaWebhookException` | Thrown by `WabaWebhookHandler::verify()` / `verifyAndParse()`; `reason` is one of the `REASON_*` constants. `handle()` catches it and returns the result shape above |

API exceptions extend `NotifyAfricaException`. Each exposes `?array $payload` (the decoded error envelope), `?int $httpStatus`, and `apiCode()` (the envelope `code`, e.g. `BAD_REQUEST`, `FORBIDDEN`, `VALIDATION_ERROR`).

Local validation throws `InvalidArgumentException` before any HTTP call. It covers an empty phone or message, a missing sender, an invalid notification setup, more than 500 WhatsApp recipients, text over 4,096 characters, and an idempotency key over 128 characters. Many messages are prefixed with `[Notify Africa]` so logs are easy to filter.

## Laravel notifications

Use the channel class in `via()` and implement `toNotifyAfrica()` on your notification. The notifiable must define `routeNotificationForNotifyAfrica()` returning the recipient number (string).

```php
use Illuminate\Notifications\Notification;
use TechLegend\LaravelNotifyAfrica\Channels\NotifyAfricaChannel;
use TechLegend\LaravelNotifyAfrica\NotifyAfricaMessage;

class OrderShippedSms extends Notification
{
    public function via(object $notifiable): array
    {
        return [NotifyAfricaChannel::class];
    }

    public function toNotifyAfrica(object $notifiable): NotifyAfricaMessage
    {
        return NotifyAfricaMessage::make()
            ->content('Your order has shipped.');
        // Phone comes from routeNotificationForNotifyAfrica() when `to()` is omitted
    }
}
```

On your notifiable (e.g. `User` model):

```php
public function routeNotificationForNotifyAfrica(): string
{
    return $this->phone; // e.g. 2557… (see below)
}
```

If `toNotifyAfrica()` already sets `->to(...)`, that number is used; otherwise the channel applies the routed number.

## Phone numbers

The API expects international format **without** a leading `+` (e.g. `255XXXXXXXXX`). The package strips spaces and non-digits and removes a leading `+`.

If you set `default_country_calling_code` (e.g. `255`) and the number looks “local” (9–10 digits after normalization), that prefix is prepended. This is a simple heuristic—not a substitute for [libphonenumber](https://github.com/giggsey/libphonenumber-for-php) or full validation. Prefer passing fully qualified international numbers in production.

## Testing your app

In tests, use Laravel’s HTTP client fakes so no real SMS is sent:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'https://api.notify.africa/api/v1/api/messages/send' => Http::response([
        'status' => 202,
        'message' => 'SMS accepted',
        'data' => ['messageId' => 'd9b54c80-8a63-4f8b-83c8-71f2439f5429', 'status' => 'PROCESSING'],
    ], 202),
    'https://api.notify.africa/v1/waba-api/messages/*' => Http::response([
        'status' => 200,
        'data' => ['results' => [['to' => '255700000001', 'success' => true, 'messageId' => 'fe245daf-…', 'status' => 'processing']]],
    ], 200),
]);
```

If you change `notify-africa` config inside a test, clear resolved singletons (`NotifyAfricaClient`, `WabaClient`, `NotifyWhatsApp`, `WabaWebhookHandler`, `LaravelNotifyAfrica`) or boot a fresh application, because they are registered as singletons.

## Testing this package

```bash
composer test
composer analyse   # PHPStan
composer format    # Pint
```

## API contract notes

- Contract: [SMS API](https://docs.notify.africa/docs/api/sms) and [WhatsApp Business API](https://docs.notify.africa/docs/api/waba). The WABA contract was reissued on 2026-09-07; see the [docs changelog](https://docs.notify.africa/docs/changelog).
- Success codes: SMS `send` / `send-bulk` return `202`, `batch` / `status` return `200`, WABA template create and actions return `201`, and other WABA calls return `200`.
- Not exposed by Notify's API (Portal only): funding, sender-ID requests, key rotation, WABA onboarding, and webhook endpoint management, secret rotation and test deliveries. There is no balance endpoint; `batch` and `send-bulk` return `remainingBalance`.
- The package creates no database tables or queues. Webhook dedupe on `eventId` is left to the app.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Thomson Maguru](https://github.com/mg-techlegend)
- [All Contributors](../../contributors)

### Notify Africa and iPF Softwares

The **SMS and WhatsApp APIs** behind this package are **[Notify Africa](https://notify.africa/)**, built and operated by **[iPF Softwares](https://github.com/iPFSoftwares)**. Documentation: [SMS API](https://docs.notify.africa/docs/api/sms), [WhatsApp Business API](https://docs.notify.africa/docs/api/waba). Official PHP client (separate from this Laravel package): [notify-africa-php](https://github.com/iPFSoftwares/notify-africa-php).

## License

The MIT License. See [LICENSE.md](LICENSE.md).

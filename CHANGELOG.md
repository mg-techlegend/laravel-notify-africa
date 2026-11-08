# Changelog

All notable changes to `laravel-notify-africa` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Brings the package in line with Notify Africa's current docs (docs.notify.africa, relaunched 2026-08-27; WABA contract reissued 2026-09-07). Proposed as **v1.2.0**.

### Fixed

- **SMS sends no longer report a documented success as a failure.** `/messages/send` and `/messages/send-bulk` return HTTP `202`; both clients now treat any HTTP 2xx whose envelope `status` (when present) is also 2xx as success. Before, a `202` threw `NotifyAfricaValidationException`, which could trigger a duplicate send through a backup provider.
- **WABA webhook verification follows the docs**: headers `x-notify-webhook-timestamp` and `x-notify-webhook-signature: sha256=<hex>`, HMAC-SHA256 of `<timestamp>.<raw body>` with the `whsec_` secret, checked on the raw body before JSON parsing, with a replay window (default 300 s, `NOTIFY_WABA_WEBHOOK_TOLERANCE`; `0` turns the age check off).
- **WABA webhook parsing reads the documented envelope** (`eventId`, `eventType`, `aggregateType`, `aggregateId`, `dedupeKey`, `occurredAt`, `authUserId`, `data.customerPhoneNumber`, `data.content.text`, `data.status`, `data.wamid`, `data.errorCode`…). Before, every event read as `inbound_message` with null fields.
- The webhook handler no longer logs the request headers or payload. Rejections log only the reason.
- WABA template sends no longer nest every parameter under `body`. That broke OTP `code` sends and grouped header/button parameters.

### Changed

- **WABA default host is `https://api.notify.africa`** (paths under `/v1/waba-api`), replacing `notify-web-assistant-api.beagile.africa`.
- **One key for SMS and WhatsApp**: `notify-africa.waba.api_key` falls back to `NOTIFY_AFRICA_API_TOKEN` when `NOTIFY_WABA_API_KEY` is unset (config default and in `WabaClient::fromConfig`, so older published configs also fall back). `NOTIFY_WABA_BASE_URL` falls back to `NOTIFY_AFRICA_BASE_URL`.
- SMS status lookups: HTTP `403` ("Message not found or not authorized") now throws `NotifyAfricaNotFoundException` instead of `NotifyAfricaAuthenticationException`. `401` is still authentication.
- SMS sends (`send`, `batch`, `send-bulk`): HTTP `404` / `409` / `422` throw `NotifyAfricaSenderException` with `reason` set to `not_found` / `limit_reached` / `not_approved`. It extends `NotifyAfricaValidationException`, so existing catches of that class still work; `404` was a `NotifyAfricaRequestException` before.
- WABA: `404` throws `NotifyAfricaNotFoundException`; `409` (duplicate template) maps to `NotifyAfricaValidationException`.
- `sendTemplate()` parameter docblock is `array<int|string, mixed>` (PHP turns `'1'` keys into ints). Flat parameters are sent as-is; a 0-based list is re-keyed from `1`; an empty array omits `template_parameters`.
- WABA recipients are de-duplicated after normalisation. More than 500 recipients, text over 4,096 characters, or an idempotency key over 128 characters throw `InvalidArgumentException` before any HTTP call.
- Error messages fall back to the envelope `error` (string or array of strings) when `message` is absent.
- **Webhooks fail closed**: with no `NOTIFY_WABA_WEBHOOK_SECRET`, `handle()` returns a `500` result (`reason: missing_secret`). Notify retries 5xx, so events arrive once the secret is set. Before, a missing secret skipped verification.
- `WabaWebhookHandler` constructor is now `(string $secret, int $toleranceSeconds = 300, ?Closure $now = null)`. The `signature_header` config key and `NOTIFY_WABA_SIGNATURE_HEADER` are removed because the header names are fixed by Notify.
- `handle()` returns `successful`, `status_code`, `message`, and either `reason` (rejected) or `event_id`, `event_type`, `event` (a `WabaWebhookEvent`) and `data` (snake-case array, with `from` / `wa_message_id` kept as aliases). `business_number` is gone; the payload does not carry it.
- SMS and WABA clients share HTTP and envelope handling (`Concerns\CallsNotifyApi`).

### Added

- `sendPersonalizedSms(array $messages, ?string $senderId = null, DateTimeInterface|string|null $scheduledAt = null)` on the client, entry service and facade, for `POST /api/v1/api/messages/send-bulk`. Each message is `['to' => …, 'message' => …]` (`recipient` / `content` also accepted). Recipients are sent in E.164 with `+`. A `DateTimeInterface` schedule is sent as UTC ISO 8601. Returns `PersonalizedSmsResponse` (`acceptedCount`, `rejectedCount`, `results[]` of `PersonalizedSmsResult` with `messageId`, `status`, `estimatedSegments`, `debitApplied`, plus `creditsDeducted`, `remainingBalance`, `messageIds()`).
- `NotifyWhatsApp::sendTemplate($to, $name, $parameters = [], $language = 'en', $idempotencyKey = null)`: `language` (`en`, `sw`, `xx_YY`…), the AUTHENTICATION `['code' => '123456']` shortcut, grouped `header` / `body` / `buttons`, and `idempotency_key`. `sendText()` also takes an `idempotency_key`.
- `NotifyWhatsApp::sendOtp($to, $template, $code, $language = 'en', $idempotencyKey = null)`.
- `WhatsAppRecipientResult::$wamid` and `::$status`. `WhatsAppSendResponse::allSucceeded()` and `::failures()`.
- Template management on `WabaClient` (`/v1/waba-api/templates`): `createTemplate`, `listTemplates`, `getTemplate`, `getTemplateStatus`, `updateTemplate`, `deleteTemplate`, `syncTemplate`, `validateTemplateBindings`, `previewTemplate`. Create, list, get and status are also on `NotifyWhatsApp`, which exposes the rest via `client()`. Adds DTOs `WabaTemplate`, `WabaTemplateList`, `WabaTemplateStatus`.
- `WabaWebhookEvent` DTO with `eventId` for dedupe, event-type constants and helpers (`isMessageReceived()`, `isMessageStatus()`, `isTemplateStatus()`, `isTest`).
- `WabaWebhookHandler::verifyAndParse()`, `verify($rawBody, $timestamp, $signature)`, `sign()`, `parse()`. `processInboundMessage()` is kept as a deprecated array view.
- Exceptions: `NotifyAfricaNotFoundException`, `NotifyAfricaSenderException`, `NotifyAfricaWebhookException`. Every `NotifyAfricaException` now carries `?int $httpStatus` and `apiCode()` (the envelope `code`, e.g. `FORBIDDEN`).

## [1.1.0] - 2026-06-20

### Added

- WhatsApp Business (WABA) support via a multi-service accessor: `LaravelNotifyAfrica::whatsapp()->sendText(...)` and `->sendTemplate(...)`.
- `WabaClient` HTTP layer with its own base URL and API key (separate from SMS), reusing the existing exceptions, phone normalisation, timeouts, and HTTP retry settings.
- `NotifyWhatsApp` service accepting a single string or array of recipients, and readonly DTOs `WhatsAppSendResponse` / `WhatsAppRecipientResult`.
- `WabaWebhookHandler` for inbound and delivery webhooks: optional HMAC-SHA256 signature verification, defensive parsing into normalised fields (`from`, `text`, `wa_message_id`, `business_number`, `event_type`), and full raw-payload logging.
- `notify-africa.waba` config block and `NOTIFY_WABA_*` env vars (`NOTIFY_WABA_BASE_URL`, `NOTIFY_WABA_API_KEY`, `NOTIFY_WABA_TIMEOUT`, `NOTIFY_WABA_CONNECT_TIMEOUT`, `NOTIFY_WABA_WEBHOOK_SECRET`, `NOTIFY_WABA_SIGNATURE_HEADER`).

### Changed

- README and `composer.json` authors credit [iPF Softwares](https://github.com/iPFSoftwares) for the Notify Africa SMS API; documented suggested wording for release tags and GitHub Releases.

## [1.0.0] - 2026-03-21

### Added

- Initial stable release: Notify Africa SMS integration for Laravel (PHP ^8.4, `illuminate/*` ^11 through ^13).
- HTTP client for single send, batch send, and message status lookup using Laravel’s HTTP client and Bearer auth.
- `config/notify-africa.php` for API token, sender ID, base URL, timeouts, optional default country calling code, and optional HTTP retries.
- Fluent `NotifyAfricaMessage` builder and readonly response DTOs (`SendSmsResponse`, `BulkSmsResponse`, `MessageStatusResponse`).
- Custom exceptions: `NotifyAfricaAuthenticationException`, `NotifyAfricaValidationException`, `NotifyAfricaRequestException` (base `NotifyAfricaException`).
- `LaravelNotifyAfrica` entry service, `LaravelNotifyAfrica` facade, and `NotifyAfricaChannel` for `toNotifyAfrica()` / `routeNotificationForNotifyAfrica()`.
- `PhoneNumberNormalizer` for international-style numbers without a leading `+`.
- Optional transient HTTP retries (`http_retry_attempts`, `http_retry_delay_ms` and matching `NOTIFY_AFRICA_*` env vars).
- Container alias `notify-africa` for `TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica`.
- Pest tests with `Http::fake` and CI workflow (Laravel 13, PHP 8.4).

### Changed

- Validation and API error messages use a consistent `[Notify Africa]` prefix where helpful.

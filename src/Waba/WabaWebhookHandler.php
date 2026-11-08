<?php

namespace TechLegend\LaravelNotifyAfrica\Waba;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use TechLegend\LaravelNotifyAfrica\Data\WabaWebhookEvent;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaWebhookException;

/**
 * Verifies and parses Notify Africa WABA developer webhooks.
 *
 * Signing (docs.notify.africa/docs/api/waba/webhooks):
 *   x-notify-webhook-timestamp: <unix seconds>
 *   x-notify-webhook-signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>" with the whsec_ secret>
 *
 * The signature is checked on the raw body before JSON parsing, and the
 * timestamp must fall inside the replay window. Nothing from the body is
 * logged; rejections log only the reason.
 */
final class WabaWebhookHandler
{
    public const string TIMESTAMP_HEADER = 'x-notify-webhook-timestamp';

    public const string SIGNATURE_HEADER = 'x-notify-webhook-signature';

    public const string SIGNATURE_PREFIX = 'sha256=';

    public const int DEFAULT_TOLERANCE_SECONDS = 300;

    public const string REASON_MISSING_SECRET = 'missing_secret';

    public const string REASON_MISSING_TIMESTAMP = 'missing_timestamp';

    public const string REASON_STALE_TIMESTAMP = 'stale_timestamp';

    public const string REASON_MISSING_SIGNATURE = 'missing_signature';

    public const string REASON_MALFORMED_SIGNATURE = 'malformed_signature';

    public const string REASON_SIGNATURE_MISMATCH = 'signature_mismatch';

    public const string REASON_INVALID_JSON = 'invalid_json';

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param  string  $secret  The Portal-generated `whsec_…` signing secret.
     * @param  int  $toleranceSeconds  Replay window either side of now; 0 turns the timestamp age check off.
     * @param  (Closure(): int)|null  $now  Clock (unix seconds), for tests.
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * Verify, parse and summarise a callback.
     *
     * status_code: 200 accepted; 401 signature/timestamp rejected (Notify discards);
     * 400 body not JSON; 500 secret not configured (Notify retries, so events
     * resume once the secret is set).
     *
     * @return array{successful: bool, status_code: int, message: string, reason?: string, event_id?: ?string, event_type?: ?string, event?: WabaWebhookEvent, data?: array<string, mixed>}
     */
    public function handle(Request $request): array
    {
        try {
            $event = $this->verifyAndParse($request);
        } catch (NotifyAfricaWebhookException $e) {
            Log::warning('[Notify Africa] WABA webhook rejected', ['reason' => $e->reason]);

            return [
                'successful' => false,
                'status_code' => match ($e->reason) {
                    self::REASON_MISSING_SECRET => 500,
                    self::REASON_INVALID_JSON => 400,
                    default => 401,
                },
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ];
        }

        return [
            'successful' => true,
            'status_code' => 200,
            'message' => 'Webhook processed successfully',
            'event_id' => $event->eventId,
            'event_type' => $event->eventType,
            'event' => $event,
            'data' => $event->toArray(),
        ];
    }

    /**
     * @throws NotifyAfricaWebhookException
     */
    public function verifyAndParse(Request $request): WabaWebhookEvent
    {
        $raw = $request->getContent();

        $this->verify($raw, $request->header(self::TIMESTAMP_HEADER), $request->header(self::SIGNATURE_HEADER));

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new NotifyAfricaWebhookException('Webhook body is not valid JSON', self::REASON_INVALID_JSON);
        }

        if (! is_array($payload)) {
            throw new NotifyAfricaWebhookException('Webhook body is not a JSON object', self::REASON_INVALID_JSON);
        }

        /** @var array<string, mixed> $payload */
        return $this->parse($payload);
    }

    /**
     * Check timestamp and signature against the raw body bytes.
     *
     * @throws NotifyAfricaWebhookException
     */
    public function verify(string $rawBody, ?string $timestamp, ?string $signature): void
    {
        if ($this->secret === '') {
            throw new NotifyAfricaWebhookException('Webhook secret is not configured (NOTIFY_WABA_WEBHOOK_SECRET)', self::REASON_MISSING_SECRET);
        }

        $timestamp = trim((string) $timestamp);
        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            throw new NotifyAfricaWebhookException('Missing or malformed webhook timestamp', self::REASON_MISSING_TIMESTAMP);
        }

        if ($this->toleranceSeconds > 0) {
            $seconds = (int) $timestamp;
            // Tolerate a millisecond timestamp as well as the documented seconds.
            if ($seconds > 100_000_000_000) {
                $seconds = intdiv($seconds, 1000);
            }

            if (abs(($this->now)() - $seconds) > $this->toleranceSeconds) {
                throw new NotifyAfricaWebhookException('Webhook timestamp is outside the replay window', self::REASON_STALE_TIMESTAMP);
            }
        }

        $signature = trim((string) $signature);
        if ($signature === '') {
            throw new NotifyAfricaWebhookException('Missing webhook signature', self::REASON_MISSING_SIGNATURE);
        }

        if (! str_starts_with(strtolower($signature), self::SIGNATURE_PREFIX)) {
            throw new NotifyAfricaWebhookException('Malformed webhook signature', self::REASON_MALFORMED_SIGNATURE);
        }

        $received = strtolower(substr($signature, strlen(self::SIGNATURE_PREFIX)));
        if (! ctype_xdigit($received)) {
            throw new NotifyAfricaWebhookException('Malformed webhook signature', self::REASON_MALFORMED_SIGNATURE);
        }

        if (! hash_equals($this->sign($timestamp, $rawBody), $received)) {
            throw new NotifyAfricaWebhookException('Invalid webhook signature', self::REASON_SIGNATURE_MISMATCH);
        }
    }

    public function verifySignature(Request $request): bool
    {
        try {
            $this->verify($request->getContent(), $request->header(self::TIMESTAMP_HEADER), $request->header(self::SIGNATURE_HEADER));

            return true;
        } catch (NotifyAfricaWebhookException) {
            return false;
        }
    }

    /**
     * Hex HMAC-SHA256 of "<timestamp>.<raw body>" (without the `sha256=` prefix).
     */
    public function sign(string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);
    }

    /**
     * Parse an already-verified payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload): WabaWebhookEvent
    {
        return WabaWebhookEvent::fromPayload($payload);
    }

    /**
     * @deprecated since 1.2; use parse() for a typed event.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function processInboundMessage(array $payload): array
    {
        return $this->parse($payload)->toArray();
    }
}

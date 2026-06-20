<?php

namespace TechLegend\LaravelNotifyAfrica\Waba;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

final class WabaWebhookHandler
{
    public function __construct(
        private readonly string $secret,
        private readonly string $signatureHeader = 'X-Notify-Signature',
    ) {}

    /**
     * @return array{successful: bool, event_type?: string, data?: array<string, mixed>, message: string, status_code: int}
     */
    public function handle(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        // The inbound payload schema is undocumented, so always log the raw
        // body to confirm field names against the first real delivery.
        Log::info('[Notify Africa] WABA webhook received', [
            'headers' => $this->loggableHeaders($request),
            'payload' => $payload,
        ]);

        if ($this->secret !== '' && ! $this->verifySignature($request)) {
            return [
                'successful' => false,
                'message' => 'Invalid webhook signature',
                'status_code' => 401,
            ];
        }

        $normalized = $this->processInboundMessage($payload);

        return [
            'successful' => true,
            'event_type' => $normalized['event_type'],
            'data' => $normalized,
            'message' => 'Webhook processed successfully',
            'status_code' => 200,
        ];
    }

    public function verifySignature(Request $request): bool
    {
        $signature = $request->header($this->signatureHeader);

        if (empty($signature)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $this->secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Parse a raw WABA webhook payload into normalised fields. Defensive: the
     * inbound schema is undocumented, so several common key shapes are tried.
     *
     * @param  array<string, mixed>  $payload
     * @return array{from: ?string, text: ?string, wa_message_id: ?string, business_number: ?string, event_type: string, raw: array<string, mixed>}
     */
    public function processInboundMessage(array $payload): array
    {
        return [
            'from' => $this->firstString($payload, ['from', 'sender', 'msisdn', 'wa_id', 'data.from', 'message.from', 'contacts.0.wa_id']),
            'text' => $this->firstString($payload, ['text', 'message', 'body', 'text.body', 'message.text.body', 'data.text', 'messages.0.text.body']),
            'wa_message_id' => $this->firstString($payload, ['wa_message_id', 'messageId', 'message_id', 'id', 'data.messageId', 'messages.0.id']),
            'business_number' => $this->firstString($payload, ['business_number', 'to', 'business', 'phone_number_id', 'metadata.display_phone_number', 'data.to']),
            'event_type' => $this->firstString($payload, ['event_type', 'type', 'event', 'data.event_type']) ?? 'inbound_message',
            'raw' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function loggableHeaders(Request $request): array
    {
        return array_map(
            static fn (array $values): string => implode(', ', $values),
            $request->headers->all(),
        );
    }
}

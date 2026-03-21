<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

readonly class SendSmsResponse
{
    public function __construct(
        public string $messageId,
        public string $deliveryStatus,
        public string $envelopeMessage,
        public int $apiStatus,
        public ?string $timestamp = null,
        public ?string $path = null,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromApiPayload(array $decoded): self
    {
        /** @var array<string, mixed> $data */
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return new self(
            messageId: (string) ($data['messageId'] ?? ''),
            deliveryStatus: (string) ($data['status'] ?? ''),
            envelopeMessage: (string) ($decoded['message'] ?? ''),
            apiStatus: (int) ($decoded['status'] ?? 0),
            timestamp: isset($decoded['timestamp']) ? (string) $decoded['timestamp'] : null,
            path: isset($decoded['path']) ? (string) $decoded['path'] : null,
        );
    }
}

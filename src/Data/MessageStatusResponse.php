<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

readonly class MessageStatusResponse
{
    public function __construct(
        public string $messageId,
        public string $status,
        public ?string $sentAt,
        public ?string $deliveredAt,
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
            status: (string) ($data['status'] ?? ''),
            sentAt: isset($data['sentAt']) ? (is_string($data['sentAt']) ? $data['sentAt'] : null) : null,
            deliveredAt: isset($data['deliveredAt']) ? (is_string($data['deliveredAt']) ? $data['deliveredAt'] : null) : null,
            envelopeMessage: (string) ($decoded['message'] ?? ''),
            apiStatus: (int) ($decoded['status'] ?? 0),
            timestamp: isset($decoded['timestamp']) ? (string) $decoded['timestamp'] : null,
            path: isset($decoded['path']) ? (string) $decoded['path'] : null,
        );
    }
}

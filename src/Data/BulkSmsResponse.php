<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

readonly class BulkSmsResponse
{
    public function __construct(
        public int $messageCount,
        public int $creditsDeducted,
        public int $remainingBalance,
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
            messageCount: (int) ($data['messageCount'] ?? 0),
            creditsDeducted: (int) ($data['creditsDeducted'] ?? 0),
            remainingBalance: (int) ($data['remainingBalance'] ?? 0),
            envelopeMessage: (string) ($decoded['message'] ?? ''),
            apiStatus: (int) ($decoded['status'] ?? 0),
            timestamp: isset($decoded['timestamp']) ? (string) $decoded['timestamp'] : null,
            path: isset($decoded['path']) ? (string) $decoded['path'] : null,
        );
    }
}

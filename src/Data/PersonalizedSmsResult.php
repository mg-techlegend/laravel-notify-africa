<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

readonly class PersonalizedSmsResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $messageId,
        public string $status,
        public ?int $estimatedSegments,
        public ?bool $debitApplied,
        public ?string $recipient = null,
        public ?string $error = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function fromApiResult(array $result): self
    {
        $string = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : (is_int($v) ? (string) $v : null);
        $error = $result['error'] ?? null;

        return new self(
            messageId: $string($result['messageId'] ?? null),
            status: (string) ($string($result['status'] ?? null) ?? ''),
            estimatedSegments: is_numeric($result['estimatedSegments'] ?? null) ? (int) $result['estimatedSegments'] : null,
            debitApplied: is_bool($result['debitApplied'] ?? null) ? $result['debitApplied'] : null,
            recipient: $string($result['recipient'] ?? $result['to'] ?? null),
            error: is_string($error) && $error !== '' ? $error : (is_array($error) ? (json_encode($error) ?: null) : null),
            raw: $result,
        );
    }

    /**
     * Accepted results carry a message UUID to poll with getMessageStatus().
     */
    public function accepted(): bool
    {
        return $this->messageId !== null && strtolower($this->status) !== 'rejected' && $this->error === null;
    }
}

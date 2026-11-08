<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

readonly class WhatsAppRecipientResult
{
    /**
     * @param  string|null  $messageId  Notify Africa message ID (correlates with webhook `data.messageId` / `aggregateId`).
     * @param  string|null  $wamid  Meta message ID; often absent in the initial response and supplied later by webhooks.
     * @param  string|null  $status  Local processing state at send time (e.g. "processing", "queued").
     */
    public function __construct(
        public string $to,
        public bool $success,
        public ?string $messageId = null,
        public ?string $error = null,
        public ?string $wamid = null,
        public ?string $status = null,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function fromApiResult(array $result): self
    {
        $string = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : (is_int($v) ? (string) $v : null);

        return new self(
            to: (string) ($result['to'] ?? ''),
            success: (bool) ($result['success'] ?? false),
            messageId: $string($result['messageId'] ?? null),
            error: $string($result['error'] ?? null),
            wamid: $string($result['wamid'] ?? null),
            status: $string($result['status'] ?? null),
        );
    }
}

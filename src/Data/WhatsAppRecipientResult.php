<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

readonly class WhatsAppRecipientResult
{
    public function __construct(
        public string $to,
        public bool $success,
        public ?string $messageId = null,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function fromApiResult(array $result): self
    {
        return new self(
            to: (string) ($result['to'] ?? ''),
            success: (bool) ($result['success'] ?? false),
            messageId: isset($result['messageId']) && is_string($result['messageId']) && $result['messageId'] !== ''
                ? $result['messageId']
                : null,
            error: isset($result['error']) && is_string($result['error']) && $result['error'] !== ''
                ? $result['error']
                : null,
        );
    }
}

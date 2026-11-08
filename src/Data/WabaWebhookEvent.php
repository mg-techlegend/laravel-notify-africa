<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

/**
 * A verified Notify Africa WABA callback.
 *
 * Delivery is at least once and may arrive out of order: dedupe on `eventId`
 * (unique constraint) and compare `occurredAt` before overwriting newer state.
 */
readonly class WabaWebhookEvent
{
    public const string MESSAGE_RECEIVED = 'message.received';

    public const string MESSAGE_STATUS_UPDATED = 'message.status.updated';

    public const string TEMPLATE_STATUS_UPDATED = 'template.status.updated';

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $eventId,
        public ?string $eventType,
        public ?string $aggregateType,
        public ?string $aggregateId,
        public ?string $dedupeKey,
        public ?string $occurredAt,
        public ?int $authUserId,
        public ?string $messageId,
        public ?string $wamid,
        public ?string $customerPhoneNumber,
        public ?string $customerName,
        public ?string $direction,
        public ?string $messageType,
        public ?string $text,
        public ?string $status,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?bool $retryable,
        public ?string $templateName,
        public ?string $templateLanguage,
        public bool $isTest,
        public array $data,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        /** @var array<string, mixed> $data */
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        /** @var array<string, mixed> $content */
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];

        $s = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : (is_int($v) || is_float($v) ? (string) $v : null);

        return new self(
            eventId: $s($payload['eventId'] ?? null),
            eventType: $s($payload['eventType'] ?? null),
            aggregateType: $s($payload['aggregateType'] ?? null),
            aggregateId: $s($payload['aggregateId'] ?? null),
            dedupeKey: $s($payload['dedupeKey'] ?? null),
            occurredAt: $s($payload['occurredAt'] ?? null),
            authUserId: is_numeric($payload['authUserId'] ?? null) ? (int) $payload['authUserId'] : null,
            messageId: $s($data['messageId'] ?? null),
            wamid: $s($data['wamid'] ?? null),
            customerPhoneNumber: $s($data['customerPhoneNumber'] ?? null),
            customerName: $s($data['customerName'] ?? null),
            direction: $s($data['direction'] ?? null),
            messageType: $s($data['messageType'] ?? null),
            text: $s($content['text'] ?? null),
            status: $s($data['status'] ?? null),
            errorCode: $s($data['errorCode'] ?? null),
            errorMessage: $s($data['errorMessage'] ?? null),
            retryable: is_bool($data['retryable'] ?? null) ? $data['retryable'] : null,
            templateName: $s($data['externalTemplateName'] ?? null),
            templateLanguage: $s($data['externalTemplateLanguage'] ?? null),
            isTest: ($data['test'] ?? false) === true || ($payload['aggregateId'] ?? null) === 'test',
            data: $data,
            raw: $payload,
        );
    }

    public function isMessageReceived(): bool
    {
        return $this->eventType === self::MESSAGE_RECEIVED;
    }

    public function isMessageStatus(): bool
    {
        return $this->eventType === self::MESSAGE_STATUS_UPDATED;
    }

    public function isTemplateStatus(): bool
    {
        return $this->eventType === self::TEMPLATE_STATUS_UPDATED;
    }

    /**
     * Snake-case array view. `from` and `wa_message_id` are kept as aliases of
     * `customer_phone_number` and `wamid` for v1.1 callers.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'dedupe_key' => $this->dedupeKey,
            'occurred_at' => $this->occurredAt,
            'auth_user_id' => $this->authUserId,
            'message_id' => $this->messageId,
            'wamid' => $this->wamid,
            'customer_phone_number' => $this->customerPhoneNumber,
            'customer_name' => $this->customerName,
            'direction' => $this->direction,
            'message_type' => $this->messageType,
            'text' => $this->text,
            'status' => $this->status,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'retryable' => $this->retryable,
            'template_name' => $this->templateName,
            'template_language' => $this->templateLanguage,
            'is_test' => $this->isTest,
            'from' => $this->customerPhoneNumber,
            'wa_message_id' => $this->wamid,
            'data' => $this->data,
            'raw' => $this->raw,
        ];
    }
}

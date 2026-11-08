<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

/**
 * A WhatsApp template as returned by /v1/waba-api/templates. `status` is the
 * normalised state (pending | approved | rejected); the raw Meta state is in
 * `providerStatus`, and rejection details in `statusInfo`.
 */
readonly class WabaTemplate
{
    /**
     * @param  array<string, mixed>|null  $statusInfo
     * @param  array<int, mixed>  $componentSchema
     * @param  array<int, mixed>  $variableSchema
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?bool $isActive,
        public ?string $name,
        public ?string $externalTemplateName,
        public ?string $externalTemplateLanguage,
        public ?string $externalTemplateCategory,
        public ?string $externalTemplateId,
        public ?string $providerStatus,
        public ?array $statusInfo,
        public ?string $syncStatus,
        public ?string $lastSyncError,
        public array $componentSchema,
        public array $variableSchema,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded  Full envelope.
     */
    public static function fromApiPayload(array $decoded): self
    {
        /** @var array<string, mixed> $data */
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return self::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data  A single template object.
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $config */
        $config = is_array($data['whatsappConfig'] ?? null) ? $data['whatsappConfig'] : [];
        /** @var array<string, mixed> $sync */
        $sync = is_array($data['sync'] ?? null) ? $data['sync'] : [];

        $string = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : (is_int($v) ? (string) $v : null);

        /** @var array<string, mixed>|null $statusInfo */
        $statusInfo = is_array($config['statusInfo'] ?? null) ? $config['statusInfo'] : null;

        return new self(
            id: (string) ($string($data['id'] ?? null) ?? ''),
            status: (string) ($string($data['status'] ?? null) ?? ''),
            isActive: is_bool($data['isActive'] ?? null) ? $data['isActive'] : null,
            name: $string($data['name'] ?? null),
            externalTemplateName: $string($config['externalTemplateName'] ?? null),
            externalTemplateLanguage: $string($config['externalTemplateLanguage'] ?? null),
            externalTemplateCategory: $string($config['externalTemplateCategory'] ?? null),
            externalTemplateId: $string($config['externalTemplateId'] ?? null),
            providerStatus: $string($config['providerStatus'] ?? null),
            statusInfo: $statusInfo,
            syncStatus: $string($sync['syncStatus'] ?? $data['syncStatus'] ?? null),
            lastSyncError: $string($sync['lastSyncError'] ?? $data['lastSyncError'] ?? null),
            componentSchema: is_array($config['componentSchema'] ?? null) ? array_values($config['componentSchema']) : [],
            variableSchema: is_array($data['variableSchema'] ?? null) ? array_values($data['variableSchema']) : [],
            raw: $data,
        );
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}

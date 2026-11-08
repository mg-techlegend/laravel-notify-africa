<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

readonly class WabaTemplateStatus
{
    public function __construct(
        public string $id,
        public string $status,
        public ?bool $isActive,
        public ?string $syncStatus,
        public ?string $lastSyncedAt,
        public ?string $lastSyncError,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromApiPayload(array $decoded): self
    {
        /** @var array<string, mixed> $data */
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $string = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;

        return new self(
            id: (string) ($string($data['id'] ?? null) ?? ''),
            status: (string) ($string($data['status'] ?? null) ?? ''),
            isActive: is_bool($data['isActive'] ?? null) ? $data['isActive'] : null,
            syncStatus: $string($data['syncStatus'] ?? null),
            lastSyncedAt: $string($data['lastSyncedAt'] ?? null),
            lastSyncError: $string($data['lastSyncError'] ?? null),
        );
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}

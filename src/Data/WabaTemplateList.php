<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

readonly class WabaTemplateList
{
    /**
     * @param  array<int, WabaTemplate>  $templates
     */
    public function __construct(
        public array $templates,
        public int $total,
        public int $currentPage,
        public int $totalPages,
        public int $pageSize,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromApiPayload(array $decoded): self
    {
        /** @var array<int, mixed> $items */
        $items = is_array($decoded['data'] ?? null) ? array_values($decoded['data']) : [];
        /** @var array<string, mixed> $pagination */
        $pagination = is_array($decoded['pagination'] ?? null) ? $decoded['pagination'] : [];

        $templates = array_map(
            static fn (mixed $t): WabaTemplate => WabaTemplate::fromArray(is_array($t) ? $t : []),
            $items,
        );

        return new self(
            templates: $templates,
            total: (int) ($pagination['total'] ?? count($templates)),
            currentPage: (int) ($pagination['currentPage'] ?? 1),
            totalPages: (int) ($pagination['totalPages'] ?? 1),
            pageSize: (int) ($pagination['pageSize'] ?? count($templates)),
        );
    }
}

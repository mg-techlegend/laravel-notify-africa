<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

readonly class WhatsAppSendResponse
{
    /**
     * @param  array<int, WhatsAppRecipientResult>  $results
     */
    public function __construct(
        public int $apiStatus,
        public string $envelopeMessage,
        public array $results,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromApiPayload(array $decoded): self
    {
        /** @var array<string, mixed> $data */
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        /** @var array<int, mixed> $rawResults */
        $rawResults = is_array($data['results'] ?? null) ? array_values($data['results']) : [];

        $results = array_map(
            static fn (mixed $result): WhatsAppRecipientResult => WhatsAppRecipientResult::fromApiResult(
                is_array($result) ? $result : [],
            ),
            $rawResults,
        );

        return new self(
            apiStatus: (int) ($decoded['status'] ?? 0),
            envelopeMessage: (string) ($decoded['message'] ?? ''),
            results: $results,
        );
    }
}

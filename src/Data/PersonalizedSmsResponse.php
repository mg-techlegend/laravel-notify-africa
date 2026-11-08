<?php

namespace TechLegend\LaravelNotifyAfrica\Data;

/**
 * Response from POST /api/v1/api/messages/send-bulk (personalised or scheduled bulk).
 * `results[].status` is lowercase in this operation (e.g. "scheduled", "processing").
 */
readonly class PersonalizedSmsResponse
{
    /**
     * @param  array<int, PersonalizedSmsResult>  $results
     */
    public function __construct(
        public int $acceptedCount,
        public int $rejectedCount,
        public array $results,
        public ?int $creditsDeducted,
        public ?int $remainingBalance,
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

        /** @var array<int, mixed> $rawResults */
        $rawResults = is_array($data['results'] ?? null) ? array_values($data['results']) : [];

        return new self(
            acceptedCount: (int) ($data['acceptedCount'] ?? 0),
            rejectedCount: (int) ($data['rejectedCount'] ?? 0),
            results: array_map(
                static fn (mixed $r): PersonalizedSmsResult => PersonalizedSmsResult::fromApiResult(is_array($r) ? $r : []),
                $rawResults,
            ),
            creditsDeducted: is_numeric($data['creditsDeducted'] ?? null) ? (int) $data['creditsDeducted'] : null,
            remainingBalance: is_numeric($data['remainingBalance'] ?? null) ? (int) $data['remainingBalance'] : null,
            envelopeMessage: (string) ($decoded['message'] ?? ''),
            apiStatus: (int) ($decoded['status'] ?? 0),
            timestamp: isset($decoded['timestamp']) ? (string) $decoded['timestamp'] : null,
            path: isset($decoded['path']) ? (string) $decoded['path'] : null,
        );
    }

    /**
     * @return array<int, string>
     */
    public function messageIds(): array
    {
        return array_values(array_filter(array_map(
            static fn (PersonalizedSmsResult $r): ?string => $r->messageId,
            $this->results,
        )));
    }
}

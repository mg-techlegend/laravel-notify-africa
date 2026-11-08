<?php

namespace TechLegend\LaravelNotifyAfrica\Exceptions;

use Exception;

abstract class NotifyAfricaException extends Exception
{
    /**
     * @param  array<string, mixed>|null  $payload  Decoded JSON error envelope when available.
     * @param  int|null  $httpStatus  HTTP status of the failed response (null for non-HTTP failures).
     */
    public function __construct(
        string $message,
        public readonly ?array $payload = null,
        ?\Throwable $previous = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Machine-readable error code from the envelope (e.g. "BAD_REQUEST", "FORBIDDEN"), when present.
     */
    public function apiCode(): ?string
    {
        $code = $this->payload['code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}

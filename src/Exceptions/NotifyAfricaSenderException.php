<?php

namespace TechLegend\LaravelNotifyAfrica\Exceptions;

/**
 * The SMS sender ID cannot be used for this send.
 *
 * Reasons (from the documented SMS errors):
 * - `not_found` (HTTP 404): sender not found or not approved for this customer.
 * - `limit_reached` (HTTP 409): sender usage limit exhausted.
 * - `not_approved` (HTTP 422): sender exists but is not approved yet.
 *
 * Each is fixed in the Notify Africa Portal; resending unchanged gives the same result.
 */
class NotifyAfricaSenderException extends NotifyAfricaValidationException
{
    public const string NOT_FOUND = 'not_found';

    public const string LIMIT_REACHED = 'limit_reached';

    public const string NOT_APPROVED = 'not_approved';

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        ?array $payload = null,
        ?int $httpStatus = null,
    ) {
        parent::__construct($message, $payload, null, $httpStatus);
    }
}

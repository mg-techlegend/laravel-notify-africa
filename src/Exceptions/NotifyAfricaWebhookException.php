<?php

namespace TechLegend\LaravelNotifyAfrica\Exceptions;

use RuntimeException;

/**
 * A WABA webhook callback failed signature, timestamp or body checks.
 * `reason` is one of the WabaWebhookHandler::REASON_* constants.
 */
class NotifyAfricaWebhookException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}

<?php

namespace TechLegend\LaravelNotifyAfrica\Exceptions;

use Exception;

abstract class NotifyAfricaException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?array $payload = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

<?php

namespace TechLegend\LaravelNotifyAfrica\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaRequestException;
use Throwable;

/**
 * Shared HTTP plumbing for the SMS and WABA clients: bearer auth, timeouts,
 * transient retries and the Notify Africa JSON envelope.
 *
 * @internal
 */
trait CallsNotifyApi
{
    abstract protected function apiLabel(): string;

    /**
     * @param  array<string, mixed>  $decoded
     */
    abstract protected function mapFailure(int $httpStatus, string $errorMessage, array $decoded, string $operation): NotifyAfricaException;

    protected function newPendingRequest(string $baseUrl, string $token, float $timeout, float $connectTimeout, int $retryAttempts, int $retryDelayMs): PendingRequest
    {
        $pending = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout($connectTimeout);

        if ($retryAttempts > 1) {
            $pending->retry(
                $retryAttempts,
                $retryDelayMs,
                fn (?Throwable $exception, PendingRequest $request): bool => $this->shouldRetryHttpAttempt($exception),
                throw: false,
            );
        }

        return $pending;
    }

    /**
     * Success is any HTTP 2xx whose envelope `status` (when present) is also 2xx.
     * The docs return 202 for SMS send/send-bulk, 201 for WABA template
     * create/actions, and 200 elsewhere.
     *
     * @template T of object
     *
     * @param  callable(array<string, mixed>): T  $factory
     * @return T
     */
    protected function handleJsonResponse(Response $response, callable $factory, string $operation = 'default'): object
    {
        $httpStatus = $response->status();

        /** @var mixed $decoded */
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new NotifyAfricaRequestException(
                sprintf('[Notify Africa] Expected JSON from the %s but the response was not valid JSON (HTTP %d).', $this->apiLabel(), $httpStatus),
                ['http_status' => $httpStatus, 'body' => $response->body()],
                null,
                $httpStatus,
            );
        }

        /** @var array<string, mixed> $decoded */
        $apiStatus = isset($decoded['status']) && is_numeric($decoded['status']) ? (int) $decoded['status'] : null;
        $envelopeOk = $apiStatus === null || ($apiStatus >= 200 && $apiStatus < 300);

        if ($response->successful() && $envelopeOk) {
            return $factory($decoded);
        }

        throw $this->mapFailure($httpStatus, $this->errorMessage($decoded, $httpStatus), $decoded, $operation);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function errorMessage(array $decoded, int $httpStatus): string
    {
        foreach (['message', 'error'] as $key) {
            $value = $decoded[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_array($value) && $value !== [] && is_string(reset($value))) {
                return implode('; ', array_filter($value, 'is_string'));
            }
        }

        return sprintf('[Notify Africa] %s request failed (HTTP %d).', $this->apiLabel(), $httpStatus);
    }

    private function shouldRetryHttpAttempt(?Throwable $exception): bool
    {
        if ($exception === null) {
            return false;
        }

        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            return in_array($exception->response->status(), [408, 425, 429, 500, 502, 503, 504], true);
        }

        return false;
    }
}

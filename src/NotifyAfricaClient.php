<?php

namespace TechLegend\LaravelNotifyAfrica;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use TechLegend\LaravelNotifyAfrica\Data\BulkSmsResponse;
use TechLegend\LaravelNotifyAfrica\Data\MessageStatusResponse;
use TechLegend\LaravelNotifyAfrica\Data\SendSmsResponse;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaAuthenticationException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaRequestException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaValidationException;
use Throwable;

final class NotifyAfricaClient
{
    private const string SINGLE_PATH = '/api/v1/api/messages/send';

    private const string BATCH_PATH = '/api/v1/api/messages/batch';

    public function __construct(
        private readonly string $apiToken,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly float $connectTimeout,
        private readonly int $httpRetryAttempts,
        private readonly int $httpRetryDelayMs,
        private readonly ?string $defaultSenderId,
        private readonly PhoneNumberNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $token = $config['api_token'] ?? '';
        if (! is_string($token) || $token === '') {
            throw new InvalidArgumentException('[Notify Africa] Missing API token. Set NOTIFY_AFRICA_API_TOKEN in your environment and ensure config is published (notify-africa.api_token).');
        }

        $rawBase = $config['base_url'] ?? 'https://api.notify.africa';
        $baseUrl = rtrim(is_string($rawBase) ? $rawBase : 'https://api.notify.africa', '/');

        $timeout = is_numeric($config['timeout'] ?? null) ? (float) $config['timeout'] : 10.0;
        $connectTimeout = is_numeric($config['connect_timeout'] ?? null) ? (float) $config['connect_timeout'] : 5.0;

        $defaultSender = $config['sender_id'] ?? null;
        $defaultSenderId = is_string($defaultSender) && trim($defaultSender) !== '' ? trim($defaultSender) : null;

        $cc = $config['default_country_calling_code'] ?? null;
        $countryCode = is_string($cc) && $cc !== '' ? $cc : null;

        $retryAttempts = isset($config['http_retry_attempts']) && is_numeric($config['http_retry_attempts'])
            ? max(1, (int) $config['http_retry_attempts'])
            : 1;
        $retryDelayMs = isset($config['http_retry_delay_ms']) && is_numeric($config['http_retry_delay_ms'])
            ? max(0, (int) $config['http_retry_delay_ms'])
            : 250;

        return new self(
            apiToken: $token,
            baseUrl: $baseUrl,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            httpRetryAttempts: $retryAttempts,
            httpRetryDelayMs: $retryDelayMs,
            defaultSenderId: $defaultSenderId,
            normalizer: new PhoneNumberNormalizer($countryCode),
        );
    }

    public function sendSms(NotifyAfricaMessage $message): SendSmsResponse
    {
        $message->assertComplete();
        $senderId = $message->resolvedSenderId($this->defaultSenderId);

        $response = $this->pendingRequest()->post(self::SINGLE_PATH, [
            'phone_number' => $this->normalizer->normalize($message->phone()),
            'message' => $message->getContent(),
            'sender_id' => $senderId,
        ]);

        return $this->handleJsonResponse($response, [SendSmsResponse::class, 'fromApiPayload']);
    }

    /**
     * @param  array<int, string>  $phoneNumbers
     */
    public function sendBulkSms(array $phoneNumbers, string $message, ?string $senderId = null): BulkSmsResponse
    {
        if ($phoneNumbers === []) {
            throw new InvalidArgumentException('[Notify Africa] Bulk SMS requires at least one phone number.');
        }
        if (trim($message) === '') {
            throw new InvalidArgumentException('[Notify Africa] Bulk SMS message cannot be empty.');
        }

        $resolvedSender = $senderId ?? $this->defaultSenderId;
        if ($resolvedSender === null || trim($resolvedSender) === '') {
            throw new InvalidArgumentException('[Notify Africa] Sender ID is required for bulk SMS. Set notify-africa.sender_id or pass a non-empty sender ID as the third argument.');
        }

        $normalized = $this->normalizer->normalizeMany($phoneNumbers);

        $response = $this->pendingRequest()->post(self::BATCH_PATH, [
            'phone_numbers' => $normalized,
            'message' => $message,
            'sender_id' => trim($resolvedSender),
        ]);

        return $this->handleJsonResponse($response, [BulkSmsResponse::class, 'fromApiPayload']);
    }

    public function getMessageStatus(string $messageId): MessageStatusResponse
    {
        if (trim($messageId) === '') {
            throw new InvalidArgumentException('[Notify Africa] Message ID cannot be empty.');
        }

        $encodedId = rawurlencode($messageId);
        $response = $this->pendingRequest()->get("/api/v1/api/messages/status/{$encodedId}");

        return $this->handleJsonResponse($response, [MessageStatusResponse::class, 'fromApiPayload']);
    }

    /**
     * @param  callable(array<string, mixed>): object  $factory
     */
    private function handleJsonResponse(Response $response, callable $factory): object
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new NotifyAfricaRequestException(
                sprintf('[Notify Africa] Expected JSON from the API but the response was not valid JSON (HTTP %d).', $response->status()),
                ['http_status' => $response->status(), 'body' => $response->body()],
            );
        }

        $httpStatus = $response->status();
        $apiStatus = isset($decoded['status']) ? (int) $decoded['status'] : null;
        $ok = $response->successful() && $apiStatus === 200;

        if ($ok) {
            return $factory($decoded);
        }

        $rawMessage = $decoded['message'] ?? null;
        $errorMessage = is_string($rawMessage) && $rawMessage !== ''
            ? $rawMessage
            : sprintf('[Notify Africa] Request failed (HTTP %d).', $httpStatus);

        throw $this->mapFailure($httpStatus, $errorMessage, $decoded);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function mapFailure(int $httpStatus, string $errorMessage, array $decoded): NotifyAfricaAuthenticationException|NotifyAfricaValidationException|NotifyAfricaRequestException
    {
        if ($httpStatus === 401 || $httpStatus === 403) {
            return new NotifyAfricaAuthenticationException($errorMessage, $decoded);
        }

        if (in_array($httpStatus, [400, 422], true)) {
            return new NotifyAfricaValidationException($errorMessage, $decoded);
        }

        if ($httpStatus === 200) {
            return new NotifyAfricaValidationException($errorMessage, $decoded);
        }

        if ($httpStatus === 0 || $httpStatus >= 500) {
            return new NotifyAfricaRequestException($errorMessage, $decoded);
        }

        return new NotifyAfricaRequestException($errorMessage, $decoded);
    }

    private function pendingRequest(): PendingRequest
    {
        $pending = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiToken)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        if ($this->httpRetryAttempts > 1) {
            $pending->retry(
                $this->httpRetryAttempts,
                $this->httpRetryDelayMs,
                fn (?Throwable $exception, PendingRequest $request): bool => $this->shouldRetryHttpAttempt($exception),
                throw: false,
            );
        }

        return $pending;
    }

    private function shouldRetryHttpAttempt(?Throwable $exception): bool
    {
        if ($exception === null) {
            return false;
        }

        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException && $exception->response !== null) {
            $status = $exception->response->status();

            return in_array($status, [408, 425, 429, 500, 502, 503, 504], true);
        }

        return false;
    }
}

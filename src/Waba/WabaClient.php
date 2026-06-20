<?php

namespace TechLegend\LaravelNotifyAfrica\Waba;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use TechLegend\LaravelNotifyAfrica\Data\WhatsAppSendResponse;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaAuthenticationException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaRequestException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaValidationException;
use TechLegend\LaravelNotifyAfrica\PhoneNumberNormalizer;
use Throwable;

final class WabaClient
{
    private const string TEXT_PATH = '/v1/waba-api/messages/text';

    private const string TEMPLATE_PATH = '/v1/waba-api/messages/template';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly float $connectTimeout,
        private readonly int $httpRetryAttempts,
        private readonly int $httpRetryDelayMs,
        private readonly PhoneNumberNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        /** @var array<string, mixed> $waba */
        $waba = is_array($config['waba'] ?? null) ? $config['waba'] : [];

        $apiKey = $waba['api_key'] ?? '';
        if (! is_string($apiKey) || $apiKey === '') {
            throw new InvalidArgumentException('[Notify Africa] Missing WABA API key. Set NOTIFY_WABA_API_KEY in your environment and ensure config is published (notify-africa.waba.api_key).');
        }

        $rawBase = $waba['base_url'] ?? 'https://notify-web-assistant-api.beagile.africa';
        $baseUrl = rtrim(is_string($rawBase) ? $rawBase : 'https://notify-web-assistant-api.beagile.africa', '/');

        $timeout = is_numeric($waba['timeout'] ?? null) ? (float) $waba['timeout'] : 10.0;
        $connectTimeout = is_numeric($waba['connect_timeout'] ?? null) ? (float) $waba['connect_timeout'] : 5.0;

        // Retry and country-code settings are shared with the SMS client config.
        $retryAttempts = isset($config['http_retry_attempts']) && is_numeric($config['http_retry_attempts'])
            ? max(1, (int) $config['http_retry_attempts'])
            : 1;
        $retryDelayMs = isset($config['http_retry_delay_ms']) && is_numeric($config['http_retry_delay_ms'])
            ? max(0, (int) $config['http_retry_delay_ms'])
            : 250;

        $cc = $config['default_country_calling_code'] ?? null;
        $countryCode = is_string($cc) && $cc !== '' ? $cc : null;

        return new self(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            httpRetryAttempts: $retryAttempts,
            httpRetryDelayMs: $retryDelayMs,
            normalizer: new PhoneNumberNormalizer($countryCode),
        );
    }

    /**
     * @param  array<int, string>  $to
     */
    public function sendText(array $to, string $text): WhatsAppSendResponse
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('[Notify Africa] WhatsApp text message cannot be empty.');
        }

        $response = $this->pendingRequest()->post(self::TEXT_PATH, [
            'to' => $this->normalizeRecipients($to),
            'text' => $text,
        ]);

        return $this->handleJsonResponse($response, [WhatsAppSendResponse::class, 'fromApiPayload']);
    }

    /**
     * @param  array<int, string>  $to
     * @param  array<string, mixed>  $parameters
     */
    public function sendTemplate(array $to, string $templateName, array $parameters = []): WhatsAppSendResponse
    {
        if (trim($templateName) === '') {
            throw new InvalidArgumentException('[Notify Africa] WhatsApp template name cannot be empty.');
        }

        $response = $this->pendingRequest()->post(self::TEMPLATE_PATH, [
            'to' => $this->normalizeRecipients($to),
            'template_name' => $templateName,
            'template_parameters' => ['body' => (object) $parameters],
        ]);

        return $this->handleJsonResponse($response, [WhatsAppSendResponse::class, 'fromApiPayload']);
    }

    /**
     * @param  array<int, string>  $to
     * @return array<int, string>
     */
    private function normalizeRecipients(array $to): array
    {
        if ($to === []) {
            throw new InvalidArgumentException('[Notify Africa] WhatsApp message requires at least one recipient.');
        }

        return $this->normalizer->normalizeMany(array_values($to));
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
                sprintf('[Notify Africa] Expected JSON from the WABA API but the response was not valid JSON (HTTP %d).', $response->status()),
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
            : sprintf('[Notify Africa] WABA request failed (HTTP %d).', $httpStatus);

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

        return new NotifyAfricaRequestException($errorMessage, $decoded);
    }

    private function pendingRequest(): PendingRequest
    {
        $pending = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
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

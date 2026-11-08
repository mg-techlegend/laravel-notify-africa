<?php

namespace TechLegend\LaravelNotifyAfrica\Waba;

use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use TechLegend\LaravelNotifyAfrica\Concerns\CallsNotifyApi;
use TechLegend\LaravelNotifyAfrica\Data\WabaTemplate;
use TechLegend\LaravelNotifyAfrica\Data\WabaTemplateList;
use TechLegend\LaravelNotifyAfrica\Data\WabaTemplateStatus;
use TechLegend\LaravelNotifyAfrica\Data\WhatsAppSendResponse;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaAuthenticationException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaNotFoundException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaRequestException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaValidationException;
use TechLegend\LaravelNotifyAfrica\PhoneNumberNormalizer;

/**
 * Notify Africa WhatsApp Business API (WABA) Developer API, v1 contract of 2026-09-07.
 * Host https://api.notify.africa, prefix /v1/waba-api, Bearer `ntfy_…` key with WABA access.
 */
final class WabaClient
{
    use CallsNotifyApi;

    public const string DEFAULT_BASE_URL = 'https://api.notify.africa';

    public const string DEFAULT_LANGUAGE = 'en';

    public const int MAX_RECIPIENTS = 500;

    public const int MAX_TEXT_LENGTH = 4096;

    public const int MAX_IDEMPOTENCY_KEY_LENGTH = 128;

    private const string PREFIX = '/v1/waba-api';

    private const array GROUPED_PARAMETER_KEYS = ['header', 'body', 'buttons'];

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
     * The WABA key falls back to the SMS `api_token`: since 2026-09-07 one
     * `ntfy_` key can carry both SMS and WABA access.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        /** @var array<string, mixed> $waba */
        $waba = is_array($config['waba'] ?? null) ? $config['waba'] : [];

        $apiKey = self::firstFilledString($waba['api_key'] ?? null, $config['api_token'] ?? null);
        if ($apiKey === null) {
            throw new InvalidArgumentException('[Notify Africa] Missing WABA API key. Set NOTIFY_WABA_API_KEY, or NOTIFY_AFRICA_API_TOKEN for a key with both SMS and WABA access (notify-africa.waba.api_key / notify-africa.api_token).');
        }

        $baseUrl = rtrim(self::firstFilledString($waba['base_url'] ?? null) ?? self::DEFAULT_BASE_URL, '/');

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

    // ---------------------------------------------------------------------
    // Messages
    // ---------------------------------------------------------------------

    /**
     * Free-form text. Only delivered inside the 24-hour customer-service
     * window; otherwise that recipient's result has `success: false`.
     *
     * @param  array<int, string>  $to
     */
    public function sendText(array $to, string $text, ?string $idempotencyKey = null): WhatsAppSendResponse
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('[Notify Africa] WhatsApp text message cannot be empty.');
        }
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException(sprintf('[Notify Africa] WhatsApp text is limited to %d characters.', self::MAX_TEXT_LENGTH));
        }

        $body = [
            'to' => $this->normalizeRecipients($to),
            'text' => $text,
        ];
        $this->addIdempotencyKey($body, $idempotencyKey);

        $response = $this->pendingRequest()->post(self::PREFIX.'/messages/text', $body);

        return $this->handleJsonResponse($response, [WhatsAppSendResponse::class, 'fromApiPayload']);
    }

    /**
     * Approved template send.
     *
     * `$parameters` takes any documented shape:
     * - flat body values: `[1 => 'Jane', 2 => 'ORD-1001']` (or a 0-based list, re-keyed from 1);
     * - AUTHENTICATION (OTP) templates: `['code' => '123456']`;
     * - grouped: `['header' => [...], 'body' => [...], 'buttons' => [[...]]]`.
     * PHP turns numeric-string keys such as `'1'` into ints, so keys are `int|string`.
     *
     * @param  array<int, string>  $to
     * @param  array<int|string, mixed>  $parameters
     * @param  string  $language  Meta locale of the approved template, e.g. `en`, `sw`, `en_US`.
     * @param  string|null  $idempotencyKey  Stable key (max 128) so retries of one logical send are deduplicated per recipient.
     */
    public function sendTemplate(array $to, string $templateName, array $parameters = [], string $language = self::DEFAULT_LANGUAGE, ?string $idempotencyKey = null): WhatsAppSendResponse
    {
        if (trim($templateName) === '') {
            throw new InvalidArgumentException('[Notify Africa] WhatsApp template name cannot be empty.');
        }
        if (trim($language) === '') {
            throw new InvalidArgumentException('[Notify Africa] WhatsApp template language cannot be empty.');
        }

        $body = [
            'to' => $this->normalizeRecipients($to),
            'template_name' => trim($templateName),
            'language' => trim($language),
        ];

        $templateParameters = $this->templateParameters($parameters);
        if ($templateParameters !== null) {
            $body['template_parameters'] = $templateParameters;
        }

        $this->addIdempotencyKey($body, $idempotencyKey);

        $response = $this->pendingRequest()->post(self::PREFIX.'/messages/template', $body);

        return $this->handleJsonResponse($response, [WhatsAppSendResponse::class, 'fromApiPayload']);
    }

    // ---------------------------------------------------------------------
    // Templates
    // ---------------------------------------------------------------------

    /**
     * Create a template; it is submitted to Meta straight away (status `pending`).
     * `channel` defaults to `whatsapp`. AUTHENTICATION templates can only be
     * created through this API, not the Portal.
     *
     * @param  array<string, mixed>  $definition  name, externalTemplateName, externalTemplateLanguage,
     *                                            externalTemplateCategory, componentSchema, variableSchema, …
     */
    public function createTemplate(array $definition): WabaTemplate
    {
        $definition['channel'] ??= 'whatsapp';

        $response = $this->pendingRequest()->post(self::PREFIX.'/templates', $definition);

        return $this->handleJsonResponse($response, [WabaTemplate::class, 'fromApiPayload']);
    }

    /**
     * @param  array<string, mixed>  $query  status, sourceOfTruth, q, currentPage, pageSize (max 25), sortBy, sortOrder.
     *                                       `channel` defaults to `whatsapp`.
     */
    public function listTemplates(array $query = []): WabaTemplateList
    {
        $query['channel'] ??= 'whatsapp';

        $response = $this->pendingRequest()->get(self::PREFIX.'/templates', $query);

        return $this->handleJsonResponse($response, [WabaTemplateList::class, 'fromApiPayload']);
    }

    public function getTemplate(string $templateId): WabaTemplate
    {
        $response = $this->pendingRequest()->get($this->templatePath($templateId));

        return $this->handleJsonResponse($response, [WabaTemplate::class, 'fromApiPayload']);
    }

    public function getTemplateStatus(string $templateId): WabaTemplateStatus
    {
        $response = $this->pendingRequest()->get($this->templatePath($templateId, 'status'));

        return $this->handleJsonResponse($response, [WabaTemplateStatus::class, 'fromApiPayload']);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateTemplate(string $templateId, array $changes): WabaTemplate
    {
        $response = $this->pendingRequest()->patch($this->templatePath($templateId), $changes);

        return $this->handleJsonResponse($response, [WabaTemplate::class, 'fromApiPayload']);
    }

    /**
     * @return array<string, mixed> The `data` object of the response.
     */
    public function deleteTemplate(string $templateId): array
    {
        $response = $this->pendingRequest()->delete($this->templatePath($templateId));

        return $this->handleJsonResponse($response, [self::class, 'envelopeData'])->data;
    }

    /**
     * Refresh the stored provider projection (does not resubmit).
     */
    public function syncTemplate(string $templateId): WabaTemplate
    {
        $response = $this->pendingRequest()->post($this->templatePath($templateId, 'sync'));

        return $this->handleJsonResponse($response, [WabaTemplate::class, 'fromApiPayload']);
    }

    /**
     * Check binding keys against the stored variableSchema.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return array<string, mixed> `['valid' => bool, 'errors' => [...]]`
     */
    public function validateTemplateBindings(string $templateId, array $bindings = []): array
    {
        $response = $this->pendingRequest()->post($this->templatePath($templateId, 'validate'), ['bindings' => (object) $bindings]);

        return $this->handleJsonResponse($response, [self::class, 'envelopeData'])->data;
    }

    /**
     * Local placeholder substitution (not a provider-rendered preview).
     *
     * @param  array<int|string, mixed>  $bindings
     * @return array<string, mixed> `['channel' => 'whatsapp', 'renderedComponents' => [...]]`
     */
    public function previewTemplate(string $templateId, array $bindings = []): array
    {
        $response = $this->pendingRequest()->post($this->templatePath($templateId, 'preview'), ['bindings' => (object) $bindings]);

        return $this->handleJsonResponse($response, [self::class, 'envelopeData'])->data;
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $decoded
     * @return object{data: array<string, mixed>}
     */
    public static function envelopeData(array $decoded): object
    {
        /** @var array<string, mixed> $data */
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return new class($data)
        {
            /**
             * @param  array<string, mixed>  $data
             */
            public function __construct(public array $data) {}
        };
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    protected function apiLabel(): string
    {
        return 'WABA API';
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    protected function mapFailure(int $httpStatus, string $errorMessage, array $decoded, string $operation): NotifyAfricaException
    {
        return match (true) {
            $httpStatus === 401, $httpStatus === 403 => new NotifyAfricaAuthenticationException($errorMessage, $decoded, null, $httpStatus),
            $httpStatus === 404 => new NotifyAfricaNotFoundException($errorMessage, $decoded, null, $httpStatus),
            // 400 validation, 409 duplicate template name+language, 422 WABA not ready,
            // or an HTTP 2xx whose envelope reports a non-2xx status.
            in_array($httpStatus, [400, 409, 422], true), $httpStatus >= 200 && $httpStatus < 300 => new NotifyAfricaValidationException($errorMessage, $decoded, null, $httpStatus),
            default => new NotifyAfricaRequestException($errorMessage, $decoded, null, $httpStatus),
        };
    }

    /**
     * @param  array<int|string, mixed>  $parameters
     * @return array<int|string, mixed>|object|null
     */
    private function templateParameters(array $parameters): array|object|null
    {
        if ($parameters === []) {
            return null;
        }

        if (array_is_list($parameters)) {
            return (object) $this->oneBased($parameters);
        }

        $grouped = array_intersect(array_map('strval', array_keys($parameters)), self::GROUPED_PARAMETER_KEYS) !== [];

        if (! $grouped) {
            return (object) $parameters;
        }

        foreach (['header', 'body'] as $component) {
            if (is_array($parameters[$component] ?? null)) {
                /** @var array<int|string, mixed> $values */
                $values = $parameters[$component];
                $parameters[$component] = (object) (array_is_list($values) ? $this->oneBased($values) : $values);
            }
        }

        return $parameters;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, mixed>
     */
    private function oneBased(array $values): array
    {
        $keyed = [];
        foreach ($values as $i => $value) {
            $keyed[$i + 1] = $value;
        }

        return $keyed;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function addIdempotencyKey(array &$body, ?string $idempotencyKey): void
    {
        if ($idempotencyKey === null) {
            return;
        }

        $key = trim($idempotencyKey);
        if ($key === '' || strlen($key) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf('[Notify Africa] WhatsApp idempotency key must be 1-%d characters.', self::MAX_IDEMPOTENCY_KEY_LENGTH));
        }

        $body['idempotency_key'] = $key;
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

        $normalized = array_values(array_unique($this->normalizer->normalizeMany(array_values($to))));

        if (count($normalized) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException(sprintf('[Notify Africa] WhatsApp sends are limited to %d recipients per request.', self::MAX_RECIPIENTS));
        }

        return $normalized;
    }

    private function templatePath(string $templateId, ?string $action = null): string
    {
        if (trim($templateId) === '') {
            throw new InvalidArgumentException('[Notify Africa] WhatsApp template ID cannot be empty.');
        }

        return self::PREFIX.'/templates/'.rawurlencode(trim($templateId)).($action !== null ? '/'.$action : '');
    }

    private static function firstFilledString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function pendingRequest(): PendingRequest
    {
        return $this->newPendingRequest(
            $this->baseUrl,
            $this->apiKey,
            $this->timeout,
            $this->connectTimeout,
            $this->httpRetryAttempts,
            $this->httpRetryDelayMs,
        );
    }
}

<?php

namespace TechLegend\LaravelNotifyAfrica;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use TechLegend\LaravelNotifyAfrica\Concerns\CallsNotifyApi;
use TechLegend\LaravelNotifyAfrica\Data\BulkSmsResponse;
use TechLegend\LaravelNotifyAfrica\Data\MessageStatusResponse;
use TechLegend\LaravelNotifyAfrica\Data\PersonalizedSmsResponse;
use TechLegend\LaravelNotifyAfrica\Data\SendSmsResponse;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaAuthenticationException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaNotFoundException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaRequestException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaSenderException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaValidationException;

final class NotifyAfricaClient
{
    use CallsNotifyApi;

    private const string SINGLE_PATH = '/api/v1/api/messages/send';

    private const string BATCH_PATH = '/api/v1/api/messages/batch';

    private const string SEND_BULK_PATH = '/api/v1/api/messages/send-bulk';

    private const string OP_SEND = 'send';

    private const string OP_STATUS = 'status';

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

        return $this->handleJsonResponse($response, [SendSmsResponse::class, 'fromApiPayload'], self::OP_SEND);
    }

    /**
     * Same text to many recipients (POST /messages/batch). The response has
     * counts and balance but no per-recipient message IDs; use
     * sendPersonalizedSms() when each message must be tracked.
     *
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

        return $this->handleJsonResponse($response, [BulkSmsResponse::class, 'fromApiPayload'], self::OP_SEND);
    }

    /**
     * Personalised and/or scheduled bulk send (POST /messages/send-bulk).
     * Returns one result per accepted message with its UUID for status polling.
     *
     * Each message is `['to' => '2557…', 'message' => 'Hello Asha']`
     * (`recipient` / `content` are accepted as aliases). Numbers are normalised
     * like single sends and sent in E.164 with a leading `+`, as the endpoint requires.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  DateTimeInterface|string|null  $scheduledAt  One schedule for the whole batch (ISO 8601 when a string).
     */
    public function sendPersonalizedSms(array $messages, ?string $senderId = null, DateTimeInterface|string|null $scheduledAt = null): PersonalizedSmsResponse
    {
        if ($messages === []) {
            throw new InvalidArgumentException('[Notify Africa] Personalised bulk SMS requires at least one message.');
        }

        $resolvedSender = $senderId ?? $this->defaultSenderId;
        if ($resolvedSender === null || trim($resolvedSender) === '') {
            throw new InvalidArgumentException('[Notify Africa] Sender ID is required for bulk SMS. Set notify-africa.sender_id or pass a non-empty sender ID.');
        }

        $payloadMessages = [];
        foreach (array_values($messages) as $i => $item) {
            $to = $item['to'] ?? $item['recipient'] ?? null;
            $content = $item['message'] ?? $item['content'] ?? null;

            if (! is_string($to) || trim($to) === '') {
                throw new InvalidArgumentException(sprintf('[Notify Africa] Message %d has no recipient (`to`).', $i));
            }
            if (! is_string($content) || trim($content) === '') {
                throw new InvalidArgumentException(sprintf('[Notify Africa] Message %d has empty content (`message`).', $i));
            }

            $payloadMessages[] = [
                'recipient' => '+'.$this->normalizer->normalize($to),
                'content' => $content,
            ];
        }

        $body = [
            'senderId' => trim($resolvedSender),
            'messages' => $payloadMessages,
        ];

        if ($scheduledAt !== null) {
            $body['scheduledAt'] = $scheduledAt instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($scheduledAt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z')
                : $scheduledAt;
        }

        $response = $this->pendingRequest()->post(self::SEND_BULK_PATH, $body);

        return $this->handleJsonResponse($response, [PersonalizedSmsResponse::class, 'fromApiPayload'], self::OP_SEND);
    }

    /**
     * Status by the message UUID returned from a send. Unknown, malformed and
     * other-customer IDs all come back as HTTP 403 and throw
     * NotifyAfricaNotFoundException.
     */
    public function getMessageStatus(string $messageId): MessageStatusResponse
    {
        if (trim($messageId) === '') {
            throw new InvalidArgumentException('[Notify Africa] Message ID cannot be empty.');
        }

        $encodedId = rawurlencode($messageId);
        $response = $this->pendingRequest()->get("/api/v1/api/messages/status/{$encodedId}");

        return $this->handleJsonResponse($response, [MessageStatusResponse::class, 'fromApiPayload'], self::OP_STATUS);
    }

    protected function apiLabel(): string
    {
        return 'SMS API';
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    protected function mapFailure(int $httpStatus, string $errorMessage, array $decoded, string $operation): NotifyAfricaException
    {
        if ($operation === self::OP_STATUS && in_array($httpStatus, [403, 404], true)) {
            return new NotifyAfricaNotFoundException($errorMessage, $decoded, null, $httpStatus);
        }

        if ($operation === self::OP_SEND) {
            $reason = match ($httpStatus) {
                404 => NotifyAfricaSenderException::NOT_FOUND,
                409 => NotifyAfricaSenderException::LIMIT_REACHED,
                422 => NotifyAfricaSenderException::NOT_APPROVED,
                default => null,
            };

            if ($reason !== null) {
                return new NotifyAfricaSenderException($errorMessage, $reason, $decoded, $httpStatus);
            }
        }

        if ($httpStatus === 401 || $httpStatus === 403) {
            return new NotifyAfricaAuthenticationException($errorMessage, $decoded, null, $httpStatus);
        }

        // 400/422, or an HTTP 2xx whose envelope reports a non-2xx status.
        if (in_array($httpStatus, [400, 422], true) || ($httpStatus >= 200 && $httpStatus < 300)) {
            return new NotifyAfricaValidationException($errorMessage, $decoded, null, $httpStatus);
        }

        return new NotifyAfricaRequestException($errorMessage, $decoded, null, $httpStatus);
    }

    private function pendingRequest(): PendingRequest
    {
        return $this->newPendingRequest(
            $this->baseUrl,
            $this->apiToken,
            $this->timeout,
            $this->connectTimeout,
            $this->httpRetryAttempts,
            $this->httpRetryDelayMs,
        );
    }
}

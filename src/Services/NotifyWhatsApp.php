<?php

namespace TechLegend\LaravelNotifyAfrica\Services;

use TechLegend\LaravelNotifyAfrica\Data\WabaTemplate;
use TechLegend\LaravelNotifyAfrica\Data\WabaTemplateList;
use TechLegend\LaravelNotifyAfrica\Data\WabaTemplateStatus;
use TechLegend\LaravelNotifyAfrica\Data\WhatsAppSendResponse;
use TechLegend\LaravelNotifyAfrica\Waba\WabaClient;

final class NotifyWhatsApp
{
    public function __construct(
        private readonly WabaClient $client,
    ) {}

    /**
     * @param  string|array<int, string>  $to
     */
    public function sendText(string|array $to, string $text, ?string $idempotencyKey = null): WhatsAppSendResponse
    {
        return $this->client->sendText($this->normalizeTo($to), $text, $idempotencyKey);
    }

    /**
     * @param  string|array<int, string>  $to
     * @param  array<int|string, mixed>  $parameters  Flat body values, `['code' => '123456']` for OTP templates,
     *                                                or grouped `header` / `body` / `buttons`.
     */
    public function sendTemplate(string|array $to, string $templateName, array $parameters = [], string $language = WabaClient::DEFAULT_LANGUAGE, ?string $idempotencyKey = null): WhatsAppSendResponse
    {
        return $this->client->sendTemplate($this->normalizeTo($to), $templateName, $parameters, $language, $idempotencyKey);
    }

    /**
     * Send a one-time code through an approved AUTHENTICATION template.
     *
     * @param  string|array<int, string>  $to
     */
    public function sendOtp(string|array $to, string $templateName, string $code, string $language = WabaClient::DEFAULT_LANGUAGE, ?string $idempotencyKey = null): WhatsAppSendResponse
    {
        return $this->sendTemplate($to, $templateName, ['code' => $code], $language, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function createTemplate(array $definition): WabaTemplate
    {
        return $this->client->createTemplate($definition);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function listTemplates(array $query = []): WabaTemplateList
    {
        return $this->client->listTemplates($query);
    }

    public function getTemplate(string $templateId): WabaTemplate
    {
        return $this->client->getTemplate($templateId);
    }

    public function getTemplateStatus(string $templateId): WabaTemplateStatus
    {
        return $this->client->getTemplateStatus($templateId);
    }

    /**
     * The underlying client, for the remaining template operations
     * (update, delete, sync, validate, preview).
     */
    public function client(): WabaClient
    {
        return $this->client;
    }

    /**
     * @param  string|array<int, string>  $to
     * @return array<int, string>
     */
    private function normalizeTo(string|array $to): array
    {
        return is_array($to) ? array_values($to) : [$to];
    }
}

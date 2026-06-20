<?php

namespace TechLegend\LaravelNotifyAfrica\Services;

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
    public function sendText(string|array $to, string $text): WhatsAppSendResponse
    {
        return $this->client->sendText($this->normalizeTo($to), $text);
    }

    /**
     * @param  string|array<int, string>  $to
     * @param  array<string, mixed>  $parameters
     */
    public function sendTemplate(string|array $to, string $templateName, array $parameters = []): WhatsAppSendResponse
    {
        return $this->client->sendTemplate($this->normalizeTo($to), $templateName, $parameters);
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

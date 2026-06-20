<?php

namespace TechLegend\LaravelNotifyAfrica;

use Closure;
use TechLegend\LaravelNotifyAfrica\Data\BulkSmsResponse;
use TechLegend\LaravelNotifyAfrica\Data\MessageStatusResponse;
use TechLegend\LaravelNotifyAfrica\Data\SendSmsResponse;
use TechLegend\LaravelNotifyAfrica\Services\NotifyWhatsApp;

final class LaravelNotifyAfrica
{
    /**
     * @param  Closure(): NotifyWhatsApp  $whatsAppResolver
     */
    public function __construct(
        private readonly NotifyAfricaClient $client,
        private readonly Closure $whatsAppResolver,
    ) {}

    public function message(): NotifyAfricaMessage
    {
        return NotifyAfricaMessage::make();
    }

    /**
     * Resolve the WhatsApp (WABA) service lazily so SMS-only apps never need
     * WABA credentials configured.
     */
    public function whatsapp(): NotifyWhatsApp
    {
        return ($this->whatsAppResolver)();
    }

    public function sendSms(NotifyAfricaMessage $message): SendSmsResponse
    {
        return $this->client->sendSms($message);
    }

    /**
     * @param  array<int, string>  $recipients
     */
    public function sendBulkSms(array $recipients, string $message, ?string $senderId = null): BulkSmsResponse
    {
        return $this->client->sendBulkSms($recipients, $message, $senderId);
    }

    public function getMessageStatus(string $messageId): MessageStatusResponse
    {
        return $this->client->getMessageStatus($messageId);
    }
}

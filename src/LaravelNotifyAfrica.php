<?php

namespace TechLegend\LaravelNotifyAfrica;

use TechLegend\LaravelNotifyAfrica\Data\BulkSmsResponse;
use TechLegend\LaravelNotifyAfrica\Data\MessageStatusResponse;
use TechLegend\LaravelNotifyAfrica\Data\SendSmsResponse;

final class LaravelNotifyAfrica
{
    public function __construct(
        private readonly NotifyAfricaClient $client,
    ) {}

    public function message(): NotifyAfricaMessage
    {
        return NotifyAfricaMessage::make();
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

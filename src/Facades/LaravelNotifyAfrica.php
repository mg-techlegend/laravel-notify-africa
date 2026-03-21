<?php

namespace TechLegend\LaravelNotifyAfrica\Facades;

use Illuminate\Support\Facades\Facade;
use TechLegend\LaravelNotifyAfrica\Data\BulkSmsResponse;
use TechLegend\LaravelNotifyAfrica\Data\MessageStatusResponse;
use TechLegend\LaravelNotifyAfrica\Data\SendSmsResponse;
use TechLegend\LaravelNotifyAfrica\NotifyAfricaMessage;

/**
 * @method static NotifyAfricaMessage message()
 * @method static SendSmsResponse sendSms(NotifyAfricaMessage $message)
 * @method static BulkSmsResponse sendBulkSms(array $recipients, string $message, ?string $senderId = null)
 * @method static MessageStatusResponse getMessageStatus(string $messageId)
 *
 * @see \TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica
 */
class LaravelNotifyAfrica extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica::class;
    }
}

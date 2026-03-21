<?php

namespace TechLegend\LaravelNotifyAfrica\Channels;

use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica;
use TechLegend\LaravelNotifyAfrica\NotifyAfricaMessage;

final class NotifyAfricaChannel
{
    public function __construct(
        private readonly LaravelNotifyAfrica $notifyAfrica,
    ) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! is_object($notifiable) || ! method_exists($notifiable, 'routeNotificationForNotifyAfrica')) {
            throw new InvalidArgumentException('[Notify Africa] The notifiable must implement routeNotificationForNotifyAfrica().');
        }

        $phone = $notifiable->routeNotificationForNotifyAfrica();

        if (! is_string($phone) || trim($phone) === '') {
            throw new InvalidArgumentException('[Notify Africa] routeNotificationForNotifyAfrica() must return a non-empty string.');
        }

        if (! method_exists($notification, 'toNotifyAfrica')) {
            throw new InvalidArgumentException('[Notify Africa] The notification must implement toNotifyAfrica().');
        }

        $message = $notification->toNotifyAfrica($notifiable);

        if (! $message instanceof NotifyAfricaMessage) {
            throw new InvalidArgumentException('[Notify Africa] toNotifyAfrica() must return an instance of '.NotifyAfricaMessage::class.'.');
        }

        $withRecipient = trim($message->phone()) === ''
            ? $message->to($phone)
            : $message;

        $this->notifyAfrica->sendSms($withRecipient);
    }
}

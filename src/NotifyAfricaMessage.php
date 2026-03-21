<?php

namespace TechLegend\LaravelNotifyAfrica;

use InvalidArgumentException;

final class NotifyAfricaMessage
{
    private function __construct(
        private readonly ?string $phone,
        private readonly ?string $content,
        private readonly ?string $senderId,
    ) {}

    public static function make(): self
    {
        return new self(null, null, null);
    }

    public function to(string $phone): self
    {
        return new self($phone, $this->content, $this->senderId);
    }

    public function content(string $content): self
    {
        return new self($this->phone, $content, $this->senderId);
    }

    public function senderId(?string $senderId): self
    {
        return new self($this->phone, $this->content, $senderId);
    }

    public function phone(): string
    {
        return $this->phone ?? '';
    }

    public function getContent(): string
    {
        return $this->content ?? '';
    }

    public function getSenderId(): ?string
    {
        return $this->senderId;
    }

    public function assertComplete(): void
    {
        if (trim($this->phone()) === '') {
            throw new InvalidArgumentException('[Notify Africa] Recipient phone number is required.');
        }
        if (trim($this->getContent()) === '') {
            throw new InvalidArgumentException('[Notify Africa] Message content cannot be empty.');
        }
    }

    public function resolvedSenderId(?string $defaultSenderId): string
    {
        $fromMessage = $this->senderId;
        if (is_string($fromMessage) && trim($fromMessage) !== '') {
            return trim($fromMessage);
        }

        if (is_string($defaultSenderId) && trim($defaultSenderId) !== '') {
            return trim($defaultSenderId);
        }

        throw new InvalidArgumentException('[Notify Africa] Sender ID is required. Set notify-africa.sender_id or call senderId() on the message.');
    }
}

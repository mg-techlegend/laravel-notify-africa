<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use TechLegend\LaravelNotifyAfrica\Data\WabaWebhookEvent;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaWebhookException;
use TechLegend\LaravelNotifyAfrica\Waba\WabaWebhookHandler;

const WHSEC = 'whsec_test_secret';
const NOW = 1788942600;

function webhookHandler(int $tolerance = 300, string $secret = WHSEC): WabaWebhookHandler
{
    return new WabaWebhookHandler($secret, $tolerance, fn (): int => NOW);
}

function signedRequest(string $body, ?string $timestamp = null, ?string $signature = null, string $secret = WHSEC): Request
{
    $timestamp ??= (string) NOW;
    $signature ??= 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($timestamp !== '') {
        $server['HTTP_X_NOTIFY_WEBHOOK_TIMESTAMP'] = $timestamp;
    }
    if ($signature !== '') {
        $server['HTTP_X_NOTIFY_WEBHOOK_SIGNATURE'] = $signature;
    }

    return Request::create('/webhooks/waba', 'POST', server: $server, content: $body);
}

function messageReceivedBody(): string
{
    // Exact bytes matter: the signature covers the raw body.
    return '{"eventId":"bd8e8c07-3630-4d28-8660-82cf5cb1a5e6","authUserId":12345,"eventType":"message.received","aggregateType":"message","aggregateId":"501","dedupeKey":"developer-webhook:waba:message:received:wamid-example","occurredAt":"2026-09-09T10:45:00.000Z","data":{"conversationId":77,"messageId":501,"wamid":"wamid.HBgLMjU1NzAwMDAwMDAxFQIAERgS","customerPhoneNumber":"255700000001","customerName":"Jane","direction":"inbound","messageType":"text","sentAt":"2026-09-09T10:44:59.000Z","content":{"text":"Hello"}}}';
}

it('verifies the documented signature and parses a message.received event', function () {
    $result = webhookHandler()->handle(signedRequest(messageReceivedBody()));

    expect($result['successful'])->toBeTrue()
        ->and($result['status_code'])->toBe(200)
        ->and($result['event_id'])->toBe('bd8e8c07-3630-4d28-8660-82cf5cb1a5e6')
        ->and($result['event_type'])->toBe('message.received');

    /** @var WabaWebhookEvent $event */
    $event = $result['event'];

    expect($event->isMessageReceived())->toBeTrue()
        ->and($event->authUserId)->toBe(12345)
        ->and($event->aggregateType)->toBe('message')
        ->and($event->aggregateId)->toBe('501')
        ->and($event->dedupeKey)->toBe('developer-webhook:waba:message:received:wamid-example')
        ->and($event->occurredAt)->toBe('2026-09-09T10:45:00.000Z')
        ->and($event->messageId)->toBe('501')
        ->and($event->wamid)->toBe('wamid.HBgLMjU1NzAwMDAwMDAxFQIAERgS')
        ->and($event->customerPhoneNumber)->toBe('255700000001')
        ->and($event->customerName)->toBe('Jane')
        ->and($event->direction)->toBe('inbound')
        ->and($event->messageType)->toBe('text')
        ->and($event->text)->toBe('Hello')
        ->and($event->isTest)->toBeFalse()
        ->and($result['data']['from'])->toBe('255700000001')
        ->and($result['data']['wa_message_id'])->toBe('wamid.HBgLMjU1NzAwMDAwMDAxFQIAERgS')
        ->and($result['data']['text'])->toBe('Hello');
});

it('parses a message.status.updated event', function () {
    $event = webhookHandler()->parse([
        'eventId' => '7c61520b-7c77-4df6-989a-3b784d246f66',
        'authUserId' => 12345,
        'eventType' => 'message.status.updated',
        'aggregateType' => 'message',
        'aggregateId' => 'd9b54c80-8a63-4f8b-83c8-71f2439f5429',
        'occurredAt' => '2026-09-09T10:46:00.000Z',
        'data' => [
            'messageId' => 501,
            'wamid' => 'wamid.X',
            'customerPhoneNumber' => '255700000001',
            'direction' => 'outbound',
            'messageType' => 'template',
            'status' => 'failed',
            'errorCode' => '131026',
            'errorMessage' => 'Message undeliverable',
            'retryable' => false,
        ],
    ]);

    expect($event->isMessageStatus())->toBeTrue()
        ->and($event->status)->toBe('failed')
        ->and($event->errorCode)->toBe('131026')
        ->and($event->errorMessage)->toBe('Message undeliverable')
        ->and($event->retryable)->toBeFalse()
        ->and($event->wamid)->toBe('wamid.X');
});

it('parses a template.status.updated event', function () {
    $event = webhookHandler()->parse([
        'eventId' => '4ac9ed86-8f75-4b02-bf89-a357d66ab193',
        'eventType' => 'template.status.updated',
        'aggregateType' => 'template',
        'aggregateId' => 'order_update:en',
        'data' => [
            'externalTemplateName' => 'order_update',
            'externalTemplateLanguage' => 'en',
            'providerStatus' => 'APPROVED',
            'status' => 'approved',
        ],
    ]);

    expect($event->isTemplateStatus())->toBeTrue()
        ->and($event->templateName)->toBe('order_update')
        ->and($event->templateLanguage)->toBe('en')
        ->and($event->status)->toBe('approved');
});

it('flags the Portal test delivery', function () {
    $body = '{"eventId":"7c61520b-7c77-4df6-989a-3b784d246f66","authUserId":12345,"eventType":"message.received","aggregateType":"message","aggregateId":"test","dedupeKey":"test:x","occurredAt":"2026-09-09T10:40:00.000Z","data":{"test":true,"message":"This is a test delivery from Notify."}}';

    $result = webhookHandler()->handle(signedRequest($body));

    expect($result['successful'])->toBeTrue()
        ->and($result['event']->isTest)->toBeTrue();
});

it('rejects bad signatures and timestamps with a reason', function (Closure $request, string $reason, int $status) {
    $result = webhookHandler()->handle($request());

    expect($result['successful'])->toBeFalse()
        ->and($result['reason'])->toBe($reason)
        ->and($result['status_code'])->toBe($status)
        ->and($result)->not->toHaveKey('event');
})->with([
    'wrong secret' => [fn () => signedRequest(messageReceivedBody(), secret: 'whsec_other'), WabaWebhookHandler::REASON_SIGNATURE_MISMATCH, 401],
    'tampered body' => [fn () => signedRequest(
        messageReceivedBody(),
        signature: 'sha256='.hash_hmac('sha256', NOW.'.'.str_replace('Hello', 'Hellp', messageReceivedBody()), WHSEC),
    ), WabaWebhookHandler::REASON_SIGNATURE_MISMATCH, 401],
    'body-only HMAC (v1.1 scheme)' => [fn () => signedRequest(
        messageReceivedBody(),
        signature: 'sha256='.hash_hmac('sha256', messageReceivedBody(), WHSEC),
    ), WabaWebhookHandler::REASON_SIGNATURE_MISMATCH, 401],
    'missing sha256= prefix' => [fn () => signedRequest(
        messageReceivedBody(),
        signature: hash_hmac('sha256', NOW.'.'.messageReceivedBody(), WHSEC),
    ), WabaWebhookHandler::REASON_MALFORMED_SIGNATURE, 401],
    'non-hex signature' => [fn () => signedRequest(messageReceivedBody(), signature: 'sha256=zz'), WabaWebhookHandler::REASON_MALFORMED_SIGNATURE, 401],
    'missing signature' => [fn () => signedRequest(messageReceivedBody(), signature: ''), WabaWebhookHandler::REASON_MISSING_SIGNATURE, 401],
    'missing timestamp' => [fn () => signedRequest(messageReceivedBody(), timestamp: ''), WabaWebhookHandler::REASON_MISSING_TIMESTAMP, 401],
    'old timestamp' => [fn () => signedRequest(messageReceivedBody(), timestamp: (string) (NOW - 301)), WabaWebhookHandler::REASON_STALE_TIMESTAMP, 401],
    'future timestamp' => [fn () => signedRequest(messageReceivedBody(), timestamp: (string) (NOW + 301)), WabaWebhookHandler::REASON_STALE_TIMESTAMP, 401],
]);

it('accepts a timestamp inside the replay window', function () {
    $result = webhookHandler()->handle(signedRequest(messageReceivedBody(), timestamp: (string) (NOW - 299)));

    expect($result['successful'])->toBeTrue();
});

it('skips the age check when tolerance is 0 but still checks the signature', function () {
    $old = (string) (NOW - 86_400);

    expect(webhookHandler(0)->handle(signedRequest(messageReceivedBody(), timestamp: $old))['successful'])->toBeTrue()
        ->and(webhookHandler(0)->handle(signedRequest(messageReceivedBody(), timestamp: $old, secret: 'whsec_other'))['successful'])->toBeFalse();
});

it('rejects every callback with a 500 when no secret is configured', function () {
    $result = webhookHandler(secret: '')->handle(signedRequest(messageReceivedBody(), secret: ''));

    expect($result['successful'])->toBeFalse()
        ->and($result['reason'])->toBe(WabaWebhookHandler::REASON_MISSING_SECRET)
        ->and($result['status_code'])->toBe(500);
});

it('returns 400 for a correctly signed body that is not JSON', function () {
    $result = webhookHandler()->handle(signedRequest('not json'));

    expect($result['status_code'])->toBe(400)
        ->and($result['reason'])->toBe(WabaWebhookHandler::REASON_INVALID_JSON);
});

it('never logs the payload, only the rejection reason', function () {
    Log::spy();

    webhookHandler()->handle(signedRequest(messageReceivedBody()));
    webhookHandler()->handle(signedRequest(messageReceivedBody(), secret: 'whsec_other'));

    Log::shouldNotHaveReceived('info');
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context === ['reason' => WabaWebhookHandler::REASON_SIGNATURE_MISMATCH],
    );
});

it('exposes verify() for callers that read the raw body themselves', function () {
    $handler = webhookHandler();
    $body = messageReceivedBody();

    $handler->verify($body, (string) NOW, 'sha256='.$handler->sign((string) NOW, $body));

    expect(fn () => $handler->verify($body, (string) NOW, 'sha256=00'))
        ->toThrow(NotifyAfricaWebhookException::class)
        ->and($handler->verifySignature(signedRequest($body)))->toBeTrue();
});

it('resolves the handler from the container with the configured secret and tolerance', function () {
    config([
        'notify-africa.waba.webhook_secret' => 'whsec_container',
        'notify-africa.waba.webhook_tolerance' => 300,
    ]);

    if (app()->bound(WabaWebhookHandler::class)) {
        app()->forgetInstance(WabaWebhookHandler::class);
    }

    $body = messageReceivedBody();
    $now = (string) time();

    $request = signedRequest($body, timestamp: $now, secret: 'whsec_container');

    expect(app(WabaWebhookHandler::class)->handle($request)['successful'])->toBeTrue();
});

it('keeps processInboundMessage() as an array view of parse()', function () {
    $data = webhookHandler()->processInboundMessage(json_decode(messageReceivedBody(), true));

    expect($data['event_id'])->toBe('bd8e8c07-3630-4d28-8660-82cf5cb1a5e6')
        ->and($data['customer_phone_number'])->toBe('255700000001')
        ->and($data['raw']['eventType'])->toBe('message.received');
});

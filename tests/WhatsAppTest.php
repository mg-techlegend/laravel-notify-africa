<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaAuthenticationException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaRequestException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaValidationException;
use TechLegend\LaravelNotifyAfrica\Facades\LaravelNotifyAfrica as LaravelNotifyAfricaFacade;
use TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica;
use TechLegend\LaravelNotifyAfrica\Services\NotifyWhatsApp;
use TechLegend\LaravelNotifyAfrica\Waba\WabaClient;
use TechLegend\LaravelNotifyAfrica\Waba\WabaWebhookHandler;

beforeEach(function () {
    foreach ([LaravelNotifyAfrica::class, NotifyWhatsApp::class, WabaClient::class, WabaWebhookHandler::class] as $abstract) {
        if (app()->bound($abstract)) {
            app()->forgetInstance($abstract);
        }
    }

    config([
        'notify-africa.api_token' => 'test-token',
        'notify-africa.waba.base_url' => 'https://notify-web-assistant-api.beagile.africa',
        'notify-africa.waba.api_key' => 'waba-test-key',
        'notify-africa.waba.timeout' => 10,
        'notify-africa.waba.connect_timeout' => 5,
        'notify-africa.waba.webhook_secret' => null,
        'notify-africa.waba.signature_header' => 'X-Notify-Signature',
        'notify-africa.http_retry_attempts' => 1,
        'notify-africa.http_retry_delay_ms' => 0,
        'notify-africa.default_country_calling_code' => null,
    ]);

    Http::preventStrayRequests();
});

function wabaSuccessPayload(): array
{
    return [
        'status' => 200,
        'message' => 'WhatsApp message sent successfully',
        'data' => [
            'results' => [
                [
                    'to' => '255700000001',
                    'success' => true,
                    'messageId' => 'wamid.HBgL123',
                    'error' => null,
                ],
            ],
        ],
    ];
}

it('sends a WhatsApp text message', function () {
    Http::fake([
        'https://notify-web-assistant-api.beagile.africa/v1/waba-api/messages/text' => Http::response(wabaSuccessPayload(), 200),
    ]);

    $response = app(LaravelNotifyAfrica::class)->whatsapp()->sendText('255700000001', 'Habari! Karibu BrightSmile.');

    expect($response->apiStatus)->toBe(200)
        ->and($response->results)->toHaveCount(1)
        ->and($response->results[0]->to)->toBe('255700000001')
        ->and($response->results[0]->success)->toBeTrue()
        ->and($response->results[0]->messageId)->toBe('wamid.HBgL123')
        ->and($response->results[0]->error)->toBeNull();

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $request->url() === 'https://notify-web-assistant-api.beagile.africa/v1/waba-api/messages/text'
            && $request->hasHeader('Authorization', 'Bearer waba-test-key')
            && $data['to'] === ['255700000001']
            && $data['text'] === 'Habari! Karibu BrightSmile.';
    });
});

it('sends a WhatsApp template message', function () {
    Http::fake([
        'https://notify-web-assistant-api.beagile.africa/v1/waba-api/messages/template' => Http::response(wabaSuccessPayload(), 200),
    ]);

    $response = app(LaravelNotifyAfrica::class)->whatsapp()->sendTemplate(
        '255700000001',
        'hello_world',
        ['1' => 'John', '2' => 'BrightSmile'],
    );

    expect($response->results[0]->messageId)->toBe('wamid.HBgL123');

    Http::assertSent(function ($request) {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://notify-web-assistant-api.beagile.africa/v1/waba-api/messages/template'
            && $request->hasHeader('Authorization', 'Bearer waba-test-key')
            && $body['to'] === ['255700000001']
            && $body['template_name'] === 'hello_world'
            && $body['template_parameters'] === ['body' => ['1' => 'John', '2' => 'BrightSmile']];
    });
});

it('normalises a single string recipient into an array and applies phone normalisation', function () {
    config(['notify-africa.default_country_calling_code' => '255']);

    foreach ([LaravelNotifyAfrica::class, NotifyWhatsApp::class, WabaClient::class] as $abstract) {
        if (app()->bound($abstract)) {
            app()->forgetInstance($abstract);
        }
    }

    Http::fake([
        'https://notify-web-assistant-api.beagile.africa/v1/waba-api/messages/text' => Http::response(wabaSuccessPayload(), 200),
    ]);

    app(LaravelNotifyAfrica::class)->whatsapp()->sendText('0712345678', 'Local style');

    Http::assertSent(fn ($request) => $request->data()['to'] === ['2550712345678']);
});

it('sends to multiple recipients', function () {
    Http::fake([
        'https://notify-web-assistant-api.beagile.africa/v1/waba-api/messages/text' => Http::response(wabaSuccessPayload(), 200),
    ]);

    app(NotifyWhatsApp::class)->sendText(['255700000001', '+255 700 000 002'], 'Hi');

    Http::assertSent(fn ($request) => $request->data()['to'] === ['255700000001', '255700000002']);
});

it('throws on WABA authentication failure', function () {
    Http::fake([
        'https://notify-web-assistant-api.beagile.africa/v1/waba-api/messages/text' => Http::response([
            'status' => 401,
            'message' => 'Unauthenticated',
        ], 401),
    ]);

    expect(fn () => app(NotifyWhatsApp::class)->sendText('255700000001', 'Hi'))
        ->toThrow(NotifyAfricaAuthenticationException::class, 'Unauthenticated');
});

it('throws on WABA validation failure', function () {
    Http::fake([
        'https://notify-web-assistant-api.beagile.africa/v1/waba-api/messages/text' => Http::response([
            'status' => 422,
            'message' => 'Invalid recipient',
        ], 422),
    ]);

    expect(fn () => app(NotifyWhatsApp::class)->sendText('255700000001', 'Hi'))
        ->toThrow(NotifyAfricaValidationException::class, 'Invalid recipient');
});

it('throws a request exception on WABA server errors', function () {
    Http::fake([
        'https://notify-web-assistant-api.beagile.africa/v1/waba-api/messages/text' => Http::response([
            'status' => 500,
            'message' => 'Gateway down',
        ], 503),
    ]);

    expect(fn () => app(NotifyWhatsApp::class)->sendText('255700000001', 'Hi'))
        ->toThrow(NotifyAfricaRequestException::class, 'Gateway down');
});

it('resolves whatsapp() via the facade', function () {
    expect(LaravelNotifyAfricaFacade::whatsapp())->toBeInstanceOf(NotifyWhatsApp::class);
});

it('throws when the WABA api key is missing from config', function () {
    config(['notify-africa.waba.api_key' => null]);

    foreach ([NotifyWhatsApp::class, WabaClient::class] as $abstract) {
        if (app()->bound($abstract)) {
            app()->forgetInstance($abstract);
        }
    }

    expect(fn () => app(WabaClient::class))->toThrow(InvalidArgumentException::class);
});

it('processes an inbound webhook into normalised fields', function () {
    $handler = new WabaWebhookHandler('');

    $request = Request::create('/webhook', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
        'event_type' => 'inbound_message',
        'from' => '255700000001',
        'text' => 'Hello back',
        'wa_message_id' => 'wamid.IN123',
        'business_number' => '255800000000',
    ]));

    $result = $handler->handle($request);

    expect($result['successful'])->toBeTrue()
        ->and($result['status_code'])->toBe(200)
        ->and($result['event_type'])->toBe('inbound_message')
        ->and($result['data']['from'])->toBe('255700000001')
        ->and($result['data']['text'])->toBe('Hello back')
        ->and($result['data']['wa_message_id'])->toBe('wamid.IN123')
        ->and($result['data']['business_number'])->toBe('255800000000');
});

it('parses nested inbound payload shapes defensively', function () {
    $handler = new WabaWebhookHandler('');

    $normalized = $handler->processInboundMessage([
        'data' => ['from' => '255711111111'],
        'messages' => [['id' => 'wamid.NEST', 'text' => ['body' => 'nested text']]],
        'metadata' => ['display_phone_number' => '255822222222'],
    ]);

    expect($normalized['from'])->toBe('255711111111')
        ->and($normalized['text'])->toBe('nested text')
        ->and($normalized['wa_message_id'])->toBe('wamid.NEST')
        ->and($normalized['business_number'])->toBe('255822222222')
        ->and($normalized['event_type'])->toBe('inbound_message');
});

it('accepts a valid webhook signature when a secret is set', function () {
    $secret = 'shh-secret';
    $handler = new WabaWebhookHandler($secret);

    $body = json_encode(['event_type' => 'inbound_message', 'from' => '255700000001']);
    $signature = hash_hmac('sha256', $body, $secret);

    $request = Request::create('/webhook', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NOTIFY_SIGNATURE' => $signature,
    ], content: $body);

    $result = $handler->handle($request);

    expect($result['successful'])->toBeTrue()
        ->and($result['status_code'])->toBe(200);
});

it('rejects a bad or missing webhook signature when a secret is set', function () {
    $handler = new WabaWebhookHandler('shh-secret');

    $body = json_encode(['event_type' => 'inbound_message']);

    $bad = Request::create('/webhook', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NOTIFY_SIGNATURE' => 'wrong',
    ], content: $body);

    $missing = Request::create('/webhook', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

    expect($handler->handle($bad)['status_code'])->toBe(401)
        ->and($handler->handle($bad)['successful'])->toBeFalse()
        ->and($handler->handle($missing)['status_code'])->toBe(401);
});

it('resolves the webhook handler from the container with the configured secret', function () {
    config(['notify-africa.waba.webhook_secret' => 'container-secret']);

    if (app()->bound(WabaWebhookHandler::class)) {
        app()->forgetInstance(WabaWebhookHandler::class);
    }

    $handler = app(WabaWebhookHandler::class);

    $body = json_encode(['event_type' => 'inbound_message']);
    $request = Request::create('/webhook', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NOTIFY_SIGNATURE' => hash_hmac('sha256', $body, 'container-secret'),
    ], content: $body);

    expect($handler->handle($request)['successful'])->toBeTrue();
});

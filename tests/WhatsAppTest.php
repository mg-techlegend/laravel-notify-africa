<?php

use Illuminate\Support\Facades\Http;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaAuthenticationException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaNotFoundException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaRequestException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaValidationException;
use TechLegend\LaravelNotifyAfrica\Facades\LaravelNotifyAfrica as LaravelNotifyAfricaFacade;
use TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica;
use TechLegend\LaravelNotifyAfrica\Services\NotifyWhatsApp;
use TechLegend\LaravelNotifyAfrica\Waba\WabaClient;
use TechLegend\LaravelNotifyAfrica\Waba\WabaWebhookHandler;

const WABA = 'https://api.notify.africa/v1/waba-api';

function forgetWaba(): void
{
    foreach ([LaravelNotifyAfrica::class, NotifyWhatsApp::class, WabaClient::class, WabaWebhookHandler::class] as $abstract) {
        if (app()->bound($abstract)) {
            app()->forgetInstance($abstract);
        }
    }
}

beforeEach(function () {
    forgetWaba();

    config([
        'notify-africa.api_token' => 'ntfy_sms_key',
        'notify-africa.waba.base_url' => 'https://api.notify.africa',
        'notify-africa.waba.api_key' => 'ntfy_waba_key',
        'notify-africa.waba.timeout' => 10,
        'notify-africa.waba.connect_timeout' => 5,
        'notify-africa.waba.webhook_secret' => null,
        'notify-africa.http_retry_attempts' => 1,
        'notify-africa.http_retry_delay_ms' => 0,
        'notify-africa.default_country_calling_code' => null,
    ]);

    Http::preventStrayRequests();
});

function wabaSuccessPayload(string $path = '/v1/waba-api/messages/text'): array
{
    return [
        'status' => 200,
        'message' => 'Success',
        'timestamp' => '2026-09-09T10:30:00.000Z',
        'path' => $path,
        'data' => [
            'results' => [
                [
                    'to' => '255700000001',
                    'success' => true,
                    'messageId' => 'd9b54c80-8a63-4f8b-83c8-71f2439f5429',
                    'status' => 'processing',
                    'error' => null,
                ],
            ],
        ],
    ];
}

function templatePayload(string $status = 'pending', int $http = 200): array
{
    return [
        'status' => $http,
        'message' => 'Success',
        'data' => [
            'id' => 'b3f1c2a0-1234-4a5b-9c6d-7e8f9a0b1c2d',
            'channel' => 'whatsapp',
            'sourceOfTruth' => 'waba',
            'status' => $status,
            'isActive' => true,
            'name' => 'Login OTP',
            'variableSchema' => [],
            'whatsappConfig' => [
                'wabaId' => '9876543210',
                'externalTemplateId' => null,
                'externalTemplateName' => 'login_otp',
                'externalTemplateLanguage' => 'sw',
                'externalTemplateCategory' => 'AUTHENTICATION',
                'componentSchema' => [['type' => 'body', 'addSecurityRecommendation' => true]],
                'providerStatus' => null,
                'statusInfo' => null,
            ],
            'sync' => ['syncStatus' => null, 'lastSyncedAt' => null, 'lastSyncError' => null],
        ],
    ];
}

/** @return array<string, mixed> */
function sentJson($request): array
{
    return json_decode($request->body(), true);
}

// --- Host, key -------------------------------------------------------------

it('defaults the WABA host to api.notify.africa', function () {
    config(['notify-africa.waba.base_url' => null]);
    forgetWaba();

    Http::fake([WABA.'/messages/text' => Http::response(wabaSuccessPayload(), 200)]);

    app(NotifyWhatsApp::class)->sendText('255700000001', 'Hi');

    Http::assertSent(fn ($request) => $request->url() === WABA.'/messages/text');
});

it('ships a config default of api.notify.africa', function () {
    $config = require __DIR__.'/../config/notify-africa.php';

    expect($config['waba']['base_url'])->toBe('https://api.notify.africa');
});

it('falls back to the SMS api_token when no WABA key is set', function () {
    config(['notify-africa.waba.api_key' => null]);
    forgetWaba();

    Http::fake([WABA.'/messages/text' => Http::response(wabaSuccessPayload(), 200)]);

    app(NotifyWhatsApp::class)->sendText('255700000001', 'Hi');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ntfy_sms_key'));
});

it('prefers the WABA key when both are set', function () {
    Http::fake([WABA.'/messages/text' => Http::response(wabaSuccessPayload(), 200)]);

    app(NotifyWhatsApp::class)->sendText('255700000001', 'Hi');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ntfy_waba_key'));
});

it('throws when neither a WABA key nor an api_token is configured', function () {
    config(['notify-africa.waba.api_key' => null, 'notify-africa.api_token' => null]);
    forgetWaba();

    expect(fn () => app(WabaClient::class))->toThrow(InvalidArgumentException::class);
});

// --- Text --------------------------------------------------------------------

it('sends a WhatsApp text message and exposes status and wamid', function () {
    $payload = wabaSuccessPayload();
    $payload['data']['results'][0]['wamid'] = 'wamid.HBgL123';

    Http::fake([WABA.'/messages/text' => Http::response($payload, 200)]);

    $response = app(LaravelNotifyAfrica::class)->whatsapp()->sendText('255700000001', 'Habari! Karibu.', 'support-update-1001');

    expect($response->apiStatus)->toBe(200)
        ->and($response->allSucceeded())->toBeTrue()
        ->and($response->results[0]->messageId)->toBe('d9b54c80-8a63-4f8b-83c8-71f2439f5429')
        ->and($response->results[0]->status)->toBe('processing')
        ->and($response->results[0]->wamid)->toBe('wamid.HBgL123')
        ->and($response->results[0]->error)->toBeNull();

    Http::assertSent(function ($request) {
        $data = sentJson($request);

        return $request->url() === WABA.'/messages/text'
            && $data['to'] === ['255700000001']
            && $data['text'] === 'Habari! Karibu.'
            && $data['idempotency_key'] === 'support-update-1001';
    });
});

it('reports mixed per-recipient results from a 200', function () {
    Http::fake([WABA.'/messages/text' => Http::response([
        'status' => 200,
        'message' => 'Success',
        'data' => ['results' => [
            ['to' => '255700000001', 'success' => true, 'messageId' => 'a', 'status' => 'processing', 'error' => null],
            ['to' => '255700000002', 'success' => false, 'error' => 'Freeform text is not currently permitted for this recipient. Use an approved template send instead.'],
        ]],
    ], 200)]);

    $response = app(NotifyWhatsApp::class)->sendText(['255700000001', '255700000002'], 'Hi');

    expect($response->allSucceeded())->toBeFalse()
        ->and($response->failures())->toHaveCount(1)
        ->and($response->failures()[0]->to)->toBe('255700000002')
        ->and($response->failures()[0]->wamid)->toBeNull();
});

it('omits idempotency_key when none is given', function () {
    Http::fake([WABA.'/messages/text' => Http::response(wabaSuccessPayload(), 200)]);

    app(NotifyWhatsApp::class)->sendText('255700000001', 'Hi');

    Http::assertSent(fn ($request) => ! array_key_exists('idempotency_key', sentJson($request)));
});

it('normalises and de-duplicates recipients', function () {
    config(['notify-africa.default_country_calling_code' => '255']);
    forgetWaba();

    Http::fake([WABA.'/messages/text' => Http::response(wabaSuccessPayload(), 200)]);

    app(NotifyWhatsApp::class)->sendText(['255700000001', '+255 700 000 001', '+255 700 000 002'], 'Hi');

    Http::assertSent(fn ($request) => sentJson($request)['to'] === ['255700000001', '255700000002']);
});

it('rejects bad text-send input locally', function (Closure $call) {
    Http::fake();

    expect($call)->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'empty text' => [fn () => app(NotifyWhatsApp::class)->sendText('255700000001', ' ')],
    'text too long' => [fn () => app(NotifyWhatsApp::class)->sendText('255700000001', str_repeat('a', 4097))],
    'no recipients' => [fn () => app(NotifyWhatsApp::class)->sendText([], 'Hi')],
    'idempotency key too long' => [fn () => app(NotifyWhatsApp::class)->sendText('255700000001', 'Hi', str_repeat('k', 129))],
    'too many recipients' => [fn () => app(NotifyWhatsApp::class)->sendText(array_map(fn ($i) => '255700'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), range(1, 501)), 'Hi')],
]);

// --- Templates (send) ------------------------------------------------------

it('sends flat int-keyed template parameters as body values with language en by default', function () {
    Http::fake([WABA.'/messages/template' => Http::response(wabaSuccessPayload('/v1/waba-api/messages/template'), 200)]);

    // Genesis passes 1-based int keys; PHP also turns '1' into 1.
    app(NotifyWhatsApp::class)->sendTemplate('255700000001', 'order_update', [1 => 'Jane', '2' => 'ORD-1001']);

    Http::assertSent(function ($request) {
        $data = sentJson($request);

        return $request->url() === WABA.'/messages/template'
            && $data['template_name'] === 'order_update'
            && $data['language'] === 'en'
            && $data['template_parameters'] === ['1' => 'Jane', '2' => 'ORD-1001']
            && str_contains($request->body(), '"template_parameters":{"1":"Jane","2":"ORD-1001"}');
    });
});

it('re-keys a 0-based list of parameters from 1', function () {
    Http::fake([WABA.'/messages/template' => Http::response(wabaSuccessPayload(), 200)]);

    app(NotifyWhatsApp::class)->sendTemplate('255700000001', 'order_update', ['Jane', 'ORD-1001'], 'sw');

    Http::assertSent(fn ($request) => str_contains($request->body(), '"template_parameters":{"1":"Jane","2":"ORD-1001"}')
        && sentJson($request)['language'] === 'sw');
});

it('sends an AUTHENTICATION code with sendOtp in Swahili with an idempotency key', function () {
    Http::fake([WABA.'/messages/template' => Http::response(wabaSuccessPayload(), 200)]);

    LaravelNotifyAfricaFacade::whatsapp()->sendOtp('255700000001', 'login_otp', '123456', 'sw', 'login-42-attempt-1');

    Http::assertSent(function ($request) {
        $data = sentJson($request);

        return $data['template_name'] === 'login_otp'
            && $data['language'] === 'sw'
            && $data['template_parameters'] === ['code' => '123456']
            && $data['idempotency_key'] === 'login-42-attempt-1';
    });
});

it('passes grouped header/body/buttons parameters through', function () {
    Http::fake([WABA.'/messages/template' => Http::response(wabaSuccessPayload(), 200)]);

    $buttons = [['sub_type' => 'quick_reply', 'parameters' => [['type' => 'payload', 'payload' => 'order_1001']]]];

    app(NotifyWhatsApp::class)->sendTemplate('255700000001', 'order_update', [
        'header' => ['Acme'],
        'body' => [1 => 'Jane', 2 => 'ORD-1001'],
        'buttons' => $buttons,
    ]);

    Http::assertSent(function ($request) use ($buttons) {
        $params = sentJson($request)['template_parameters'];

        return $params['header'] === ['1' => 'Acme']
            && $params['body'] === ['1' => 'Jane', '2' => 'ORD-1001']
            && $params['buttons'] === $buttons
            && str_contains($request->body(), '"header":{"1":"Acme"}');
    });
});

it('omits template_parameters when none are given', function () {
    Http::fake([WABA.'/messages/template' => Http::response(wabaSuccessPayload(), 200)]);

    app(NotifyWhatsApp::class)->sendTemplate('255700000001', 'hello_world');

    Http::assertSent(fn ($request) => ! array_key_exists('template_parameters', sentJson($request)));
});

// --- Errors ------------------------------------------------------------------

it('throws on WABA authentication failure', function () {
    Http::fake([WABA.'/messages/text' => Http::response(['status' => 401, 'message' => 'Unauthenticated'], 401)]);

    expect(fn () => app(NotifyWhatsApp::class)->sendText('255700000001', 'Hi'))
        ->toThrow(NotifyAfricaAuthenticationException::class, 'Unauthenticated');
});

it('throws on WABA validation, conflict and readiness failures', function (int $status) {
    Http::fake([WABA.'/messages/text' => Http::response(['status' => $status, 'message' => 'Nope', 'code' => 'VALIDATION_ERROR'], $status)]);

    expect(fn () => app(NotifyWhatsApp::class)->sendText('255700000001', 'Hi'))
        ->toThrow(NotifyAfricaValidationException::class, 'Nope');
})->with([400, 409, 422]);

it('throws a request exception on WABA server errors', function () {
    Http::fake([WABA.'/messages/text' => Http::response(['status' => 503, 'message' => 'Gateway down'], 503)]);

    expect(fn () => app(NotifyWhatsApp::class)->sendText('255700000001', 'Hi'))
        ->toThrow(NotifyAfricaRequestException::class, 'Gateway down');
});

it('resolves whatsapp() via the facade', function () {
    expect(LaravelNotifyAfricaFacade::whatsapp())->toBeInstanceOf(NotifyWhatsApp::class);
});

// --- Template management ---------------------------------------------------

it('creates a template with channel whatsapp and accepts the 201', function () {
    Http::fake([WABA.'/templates' => Http::response(templatePayload('pending', 201), 201)]);

    $template = app(NotifyWhatsApp::class)->createTemplate([
        'name' => 'Login OTP',
        'externalTemplateName' => 'login_otp',
        'externalTemplateLanguage' => 'sw',
        'externalTemplateCategory' => 'AUTHENTICATION',
        'componentSchema' => [
            ['type' => 'body', 'addSecurityRecommendation' => true],
            ['type' => 'footer', 'codeExpirationMinutes' => 10],
            ['type' => 'buttons', 'buttons' => [['type' => 'otp', 'otpType' => 'copy_code']]],
        ],
        'variableSchema' => [],
    ]);

    expect($template->id)->toBe('b3f1c2a0-1234-4a5b-9c6d-7e8f9a0b1c2d')
        ->and($template->status)->toBe('pending')
        ->and($template->isApproved())->toBeFalse()
        ->and($template->externalTemplateName)->toBe('login_otp')
        ->and($template->externalTemplateLanguage)->toBe('sw')
        ->and($template->externalTemplateCategory)->toBe('AUTHENTICATION');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === WABA.'/templates'
        && sentJson($request)['channel'] === 'whatsapp'
        && sentJson($request)['externalTemplateCategory'] === 'AUTHENTICATION');
});

it('lists templates filtered to whatsapp with pagination', function () {
    Http::fake([WABA.'/templates*' => Http::response([
        'status' => 200,
        'message' => 'Success',
        'data' => [
            ['id' => 'b3f1c2a0-1234-4a5b-9c6d-7e8f9a0b1c2d', 'channel' => 'whatsapp', 'status' => 'approved', 'isActive' => true, 'name' => 'Order Update'],
        ],
        'pagination' => ['total' => 1, 'currentPage' => 1, 'totalPages' => 1, 'pageSize' => 10],
    ], 200)]);

    $list = app(NotifyWhatsApp::class)->listTemplates(['status' => 'approved', 'pageSize' => 10]);

    expect($list->templates)->toHaveCount(1)
        ->and($list->templates[0]->isApproved())->toBeTrue()
        ->and($list->total)->toBe(1)
        ->and($list->pageSize)->toBe(10);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_starts_with($request->url(), WABA.'/templates?')
        && $request['channel'] === 'whatsapp'
        && $request['status'] === 'approved');
});

it('gets a template and its status', function () {
    Http::fake([
        WABA.'/templates/b3f1c2a0-1234-4a5b-9c6d-7e8f9a0b1c2d/status' => Http::response([
            'status' => 200,
            'data' => [
                'id' => 'b3f1c2a0-1234-4a5b-9c6d-7e8f9a0b1c2d',
                'status' => 'approved',
                'isActive' => true,
                'syncStatus' => 'synced',
                'lastSyncedAt' => '2026-09-09T10:17:30.000Z',
                'lastSyncError' => null,
            ],
        ], 200),
        WABA.'/templates/b3f1c2a0-1234-4a5b-9c6d-7e8f9a0b1c2d' => Http::response(templatePayload('approved'), 200),
    ]);

    $whatsApp = app(NotifyWhatsApp::class);
    $template = $whatsApp->getTemplate('b3f1c2a0-1234-4a5b-9c6d-7e8f9a0b1c2d');
    $status = $whatsApp->getTemplateStatus('b3f1c2a0-1234-4a5b-9c6d-7e8f9a0b1c2d');

    expect($template->isApproved())->toBeTrue()
        ->and($template->componentSchema)->toHaveCount(1)
        ->and($status->isApproved())->toBeTrue()
        ->and($status->syncStatus)->toBe('synced')
        ->and($status->lastSyncedAt)->toBe('2026-09-09T10:17:30.000Z');
});

it('maps a missing template to not-found', function () {
    Http::fake([WABA.'/templates/*' => Http::response(['status' => 404, 'message' => 'Template not found', 'code' => 'NOT_FOUND'], 404)]);

    expect(fn () => app(NotifyWhatsApp::class)->getTemplate('b3f1c2a0-1234-4a5b-9c6d-7e8f9a0b1c2d'))
        ->toThrow(NotifyAfricaNotFoundException::class, 'Template not found');
});

it('updates, syncs, validates, previews and deletes templates', function () {
    $id = 'b3f1c2a0-1234-4a5b-9c6d-7e8f9a0b1c2d';

    Http::fake([
        WABA."/templates/{$id}/sync" => Http::response(templatePayload('approved', 201), 201),
        WABA."/templates/{$id}/validate" => Http::response(['status' => 201, 'data' => ['valid' => false, 'errors' => ['Missing binding 2']]], 201),
        WABA."/templates/{$id}/preview" => Http::response(['status' => 201, 'data' => ['channel' => 'whatsapp', 'renderedComponents' => [['type' => 'body', 'text' => 'Hello Jane']]]], 201),
        WABA."/templates/{$id}" => Http::sequence()
            ->push(templatePayload('pending'), 200)
            ->push(['status' => 200, 'message' => 'Success', 'data' => ['id' => $id, 'deleted' => true]], 200),
    ]);

    $client = app(NotifyWhatsApp::class)->client();

    expect($client->updateTemplate($id, ['description' => 'New'])->status)->toBe('pending')
        ->and($client->syncTemplate($id)->status)->toBe('approved')
        ->and($client->validateTemplateBindings($id, [1 => 'Jane']))->toBe(['valid' => false, 'errors' => ['Missing binding 2']])
        ->and($client->previewTemplate($id, [1 => 'Jane'])['renderedComponents'][0]['text'])->toBe('Hello Jane')
        ->and($client->deleteTemplate($id))->toBe(['id' => $id, 'deleted' => true]);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && sentJson($request) === ['description' => 'New']);
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === WABA."/templates/{$id}");
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/validate') && str_contains($request->body(), '"bindings":{"1":"Jane"}'));
});

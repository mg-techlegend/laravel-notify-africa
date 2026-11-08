<?php

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use TechLegend\LaravelNotifyAfrica\Channels\NotifyAfricaChannel;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaAuthenticationException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaNotFoundException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaRequestException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaSenderException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaValidationException;
use TechLegend\LaravelNotifyAfrica\Facades\LaravelNotifyAfrica as LaravelNotifyAfricaFacade;
use TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica;
use TechLegend\LaravelNotifyAfrica\NotifyAfricaClient;
use TechLegend\LaravelNotifyAfrica\NotifyAfricaMessage;

beforeEach(function () {
    foreach ([NotifyAfricaChannel::class, LaravelNotifyAfrica::class, NotifyAfricaClient::class] as $abstract) {
        if (app()->bound($abstract)) {
            app()->forgetInstance($abstract);
        }
    }

    config([
        'notify-africa.api_token' => 'test-token',
        'notify-africa.sender_id' => '137',
        'notify-africa.base_url' => 'https://api.notify.africa',
        'notify-africa.timeout' => 10,
        'notify-africa.connect_timeout' => 5,
        'notify-africa.http_retry_attempts' => 1,
        'notify-africa.http_retry_delay_ms' => 0,
        'notify-africa.default_country_calling_code' => null,
    ]);

    Http::preventStrayRequests();
});

function singleSuccessPayload(): array
{
    return [
        'status' => 200,
        'message' => 'SMS sent successfully',
        'timestamp' => '2025-11-13T12:36:09.751Z',
        'path' => '/api/v1/api/messages/send',
        'data' => [
            'messageId' => '156023',
            'status' => 'PROCESSING',
        ],
    ];
}

it('sends a single SMS successfully', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response(singleSuccessPayload(), 200),
    ]);

    $entry = app(LaravelNotifyAfrica::class);
    $message = NotifyAfricaMessage::make()
        ->to('255689737459')
        ->content('Hello from API!');

    $response = $entry->sendSms($message);

    expect($response->messageId)->toBe('156023')
        ->and($response->deliveryStatus)->toBe('PROCESSING')
        ->and($response->apiStatus)->toBe(200);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $request->url() === 'https://api.notify.africa/api/v1/api/messages/send'
            && ($data['phone_number'] ?? null) === '255689737459'
            && ($data['message'] ?? null) === 'Hello from API!'
            && ($data['sender_id'] ?? null) === '137';
    });
});

it('throws on authentication failure', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response([
            'status' => 401,
            'message' => 'Unauthenticated',
        ], 401),
    ]);

    $entry = app(LaravelNotifyAfrica::class);
    $message = NotifyAfricaMessage::make()->to('255689737459')->content('Hi');

    expect(fn () => $entry->sendSms($message))->toThrow(NotifyAfricaAuthenticationException::class, 'Unauthenticated');
});

it('throws on validation failure', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response([
            'status' => 400,
            'message' => 'Invalid phone number',
            'code' => 'BAD_REQUEST',
        ], 400),
    ]);

    $entry = app(LaravelNotifyAfrica::class);
    $message = NotifyAfricaMessage::make()->to('255689737459')->content('Hi');

    expect(fn () => $entry->sendSms($message))->toThrow(NotifyAfricaValidationException::class, 'Invalid phone number');
});

it('maps non-200 API status with HTTP 200 to validation exception', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response([
            'status' => 400,
            'message' => 'Invalid sender',
        ], 200),
    ]);

    $entry = app(LaravelNotifyAfrica::class);
    $message = NotifyAfricaMessage::make()->to('255689737459')->content('Hi');

    expect(fn () => $entry->sendSms($message))->toThrow(NotifyAfricaValidationException::class, 'Invalid sender');
});

it('uses default sender_id from config when omitted on message', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response(singleSuccessPayload(), 200),
    ]);

    app(LaravelNotifyAfrica::class)->sendSms(
        NotifyAfricaMessage::make()->to('255689737459')->content('Test')
    );

    Http::assertSent(fn ($request) => ($request->data()['sender_id'] ?? null) === '137');
});

it('overrides sender_id when set on message', function () {
    config(['notify-africa.sender_id' => '137']);

    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response(singleSuccessPayload(), 200),
    ]);

    app(LaravelNotifyAfrica::class)->sendSms(
        NotifyAfricaMessage::make()
            ->to('255689737459')
            ->content('Test')
            ->senderId('CUSTOM')
    );

    Http::assertSent(fn ($request) => ($request->data()['sender_id'] ?? null) === 'CUSTOM');
});

it('sends via notification channel using routeNotificationForNotifyAfrica', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response(singleSuccessPayload(), 200),
    ]);

    $notifiable = new class
    {
        public function routeNotificationForNotifyAfrica(): string
        {
            return '+255 700 111 222';
        }
    };

    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return [NotifyAfricaChannel::class];
        }

        public function toNotifyAfrica(object $notifiable): NotifyAfricaMessage
        {
            return NotifyAfricaMessage::make()->content('Channel body');
        }
    };

    app(NotifyAfricaChannel::class)->send($notifiable, $notification);

    Http::assertSent(function ($request) {
        return ($request->data()['phone_number'] ?? null) === '255700111222'
            && ($request->data()['message'] ?? null) === 'Channel body'
            && ($request->data()['sender_id'] ?? null) === '137';
    });
});

it('sends bulk SMS using batch endpoint', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/batch' => Http::response([
            'status' => 200,
            'message' => 'Batch messages sent successfully',
            'timestamp' => '2025-11-13T12:47:09.596Z',
            'path' => '/api/v1/api/messages/batch',
            'data' => [
                'messageCount' => 2,
                'creditsDeducted' => 2,
                'remainingBalance' => 1475,
            ],
        ], 200),
    ]);

    $response = app(LaravelNotifyAfrica::class)->sendBulkSms(
        ['255763765548', '255689737839'],
        'Bulk message',
    );

    expect($response->messageCount)->toBe(2)
        ->and($response->creditsDeducted)->toBe(2)
        ->and($response->remainingBalance)->toBe(1475);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), '/messages/batch')
            && $data['phone_numbers'] === ['255763765548', '255689737839']
            && $data['message'] === 'Bulk message'
            && $data['sender_id'] === '137';
    });
});

it('fetches message status', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/status/156022' => Http::response([
            'status' => 200,
            'message' => 'Message status retrieved successfully',
            'timestamp' => '2025-11-13T12:48:03.876Z',
            'path' => '/api/v1/api/messages/status/156022',
            'data' => [
                'messageId' => '156022',
                'status' => 'DELIVERED',
                'sentAt' => null,
                'deliveredAt' => '2025-11-13T12:34:08.540Z',
            ],
        ], 200),
    ]);

    $response = app(LaravelNotifyAfrica::class)->getMessageStatus('156022');

    expect($response->messageId)->toBe('156022')
        ->and($response->status)->toBe('DELIVERED')
        ->and($response->deliveredAt)->toBe('2025-11-13T12:34:08.540Z');
});

it('applies default country calling code for local-looking numbers', function () {
    config([
        'notify-africa.default_country_calling_code' => '255',
    ]);

    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response(singleSuccessPayload(), 200),
    ]);

    app(LaravelNotifyAfrica::class)->sendSms(
        NotifyAfricaMessage::make()->to('0712345678')->content('Local style')
    );

    Http::assertSent(fn ($request) => ($request->data()['phone_number'] ?? null) === '2550712345678');
});

it('works via facade', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response(singleSuccessPayload(), 200),
    ]);

    $response = LaravelNotifyAfricaFacade::sendSms(
        LaravelNotifyAfricaFacade::message()->to('255689737459')->content('Facade')
    );

    expect($response->messageId)->toBe('156023');
});

it('resolves the entrypoint from the container alias', function () {
    expect(app('notify-africa'))->toBeInstanceOf(LaravelNotifyAfrica::class);
});

it('throws when the api token is missing from config', function () {
    config(['notify-africa.api_token' => '']);

    foreach ([NotifyAfricaChannel::class, LaravelNotifyAfrica::class, NotifyAfricaClient::class] as $abstract) {
        if (app()->bound($abstract)) {
            app()->forgetInstance($abstract);
        }
    }

    expect(fn () => app(NotifyAfricaClient::class))->toThrow(InvalidArgumentException::class);
});

it('retries transient HTTP failures when http_retry_attempts is greater than one', function () {
    config([
        'notify-africa.http_retry_attempts' => 2,
        'notify-africa.http_retry_delay_ms' => 0,
    ]);

    foreach ([NotifyAfricaChannel::class, LaravelNotifyAfrica::class, NotifyAfricaClient::class] as $abstract) {
        if (app()->bound($abstract)) {
            app()->forgetInstance($abstract);
        }
    }

    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::sequence()
            ->push(['status' => 500, 'message' => 'Temporary'], 503)
            ->push(singleSuccessPayload(), 200),
    ]);

    $response = app(LaravelNotifyAfrica::class)->sendSms(
        NotifyAfricaMessage::make()->to('255689737459')->content('Retry me')
    );

    expect($response->messageId)->toBe('156023');
    Http::assertSentCount(2);
});

it('does not retry client validation errors', function () {
    config([
        'notify-africa.http_retry_attempts' => 3,
        'notify-africa.http_retry_delay_ms' => 0,
    ]);

    foreach ([NotifyAfricaChannel::class, LaravelNotifyAfrica::class, NotifyAfricaClient::class] as $abstract) {
        if (app()->bound($abstract)) {
            app()->forgetInstance($abstract);
        }
    }

    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response([
            'status' => 422,
            'message' => 'Invalid',
        ], 422),
    ]);

    expect(fn () => app(LaravelNotifyAfrica::class)->sendSms(
        NotifyAfricaMessage::make()->to('255689737459')->content('x')
    ))->toThrow(NotifyAfricaValidationException::class);

    Http::assertSentCount(1);
});

it('throws request exception on server errors when retries are exhausted', function () {
    config([
        'notify-africa.http_retry_attempts' => 2,
        'notify-africa.http_retry_delay_ms' => 0,
    ]);

    foreach ([NotifyAfricaChannel::class, LaravelNotifyAfrica::class, NotifyAfricaClient::class] as $abstract) {
        if (app()->bound($abstract)) {
            app()->forgetInstance($abstract);
        }
    }

    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::sequence()
            ->push(['status' => 500, 'message' => 'Still down'], 503)
            ->push(['status' => 500, 'message' => 'Still down'], 503),
    ]);

    expect(fn () => app(LaravelNotifyAfrica::class)->sendSms(
        NotifyAfricaMessage::make()->to('255689737459')->content('x')
    ))->toThrow(NotifyAfricaRequestException::class, 'Still down');

    Http::assertSentCount(2);
});

it('rejects notifiables without routeNotificationForNotifyAfrica', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response(singleSuccessPayload(), 200),
    ]);

    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return [NotifyAfricaChannel::class];
        }

        public function toNotifyAfrica(object $notifiable): NotifyAfricaMessage
        {
            return NotifyAfricaMessage::make()->content('Body');
        }
    };

    expect(fn () => app(NotifyAfricaChannel::class)->send(new stdClass, $notification))
        ->toThrow(InvalidArgumentException::class, '[Notify Africa] The notifiable must implement routeNotificationForNotifyAfrica().');
});

// ---------------------------------------------------------------------------
// Notify Africa SMS contract of 2026-09 (docs.notify.africa/docs/api/sms)
// ---------------------------------------------------------------------------

it('accepts the documented 202 from /messages/send', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response([
            'status' => 202,
            'message' => 'SMS accepted',
            'timestamp' => '2026-09-09T13:00:00.000Z',
            'path' => '/api/v1/api/messages/send',
            'data' => ['messageId' => 'd9b54c80-8a63-4f8b-83c8-71f2439f5429', 'status' => 'PROCESSING'],
        ], 202),
    ]);

    $response = app(LaravelNotifyAfrica::class)->sendSms(
        NotifyAfricaMessage::make()->to('255712345678')->content('Your order has shipped'),
    );

    expect($response->messageId)->toBe('d9b54c80-8a63-4f8b-83c8-71f2439f5429')
        ->and($response->deliveryStatus)->toBe('PROCESSING')
        ->and($response->apiStatus)->toBe(202);

    Http::assertSentCount(1);
});

it('treats an HTTP 2xx without an envelope status as success', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response([
            'data' => ['messageId' => 'abc', 'status' => 'PROCESSING'],
        ], 202),
    ]);

    $response = app(LaravelNotifyAfrica::class)->sendSms(
        NotifyAfricaMessage::make()->to('255712345678')->content('Hi'),
    );

    expect($response->messageId)->toBe('abc');
});

it('maps a 403 on status lookup to not-found, not authentication', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/status/*' => Http::response([
            'status' => 403,
            'message' => 'Message not found or not authorized',
            'code' => 'FORBIDDEN',
            'error' => 'Message not found or not authorized',
        ], 403),
    ]);

    try {
        app(LaravelNotifyAfrica::class)->getMessageStatus('not-an-owned-message');
        $this->fail('Expected NotifyAfricaNotFoundException');
    } catch (NotifyAfricaNotFoundException $e) {
        expect($e)->not->toBeInstanceOf(NotifyAfricaAuthenticationException::class)
            ->and($e)->toBeInstanceOf(NotifyAfricaRequestException::class)
            ->and($e->getMessage())->toBe('Message not found or not authorized')
            ->and($e->httpStatus)->toBe(403)
            ->and($e->apiCode())->toBe('FORBIDDEN');
    }
});

it('keeps 401 on status lookup as an authentication failure', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/status/*' => Http::response(['status' => 401, 'message' => 'Invalid API key'], 401),
    ]);

    expect(fn () => app(LaravelNotifyAfrica::class)->getMessageStatus('d9b54c80-8a63-4f8b-83c8-71f2439f5429'))
        ->toThrow(NotifyAfricaAuthenticationException::class, 'Invalid API key');
});

it('maps sender failures on send to NotifyAfricaSenderException', function (int $http, string $reason) {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response([
            'status' => $http,
            'message' => 'Sender problem',
        ], $http),
    ]);

    try {
        app(LaravelNotifyAfrica::class)->sendSms(NotifyAfricaMessage::make()->to('255712345678')->content('Hi'));
        $this->fail('Expected NotifyAfricaSenderException');
    } catch (NotifyAfricaSenderException $e) {
        expect($e->reason)->toBe($reason)
            ->and($e->httpStatus)->toBe($http)
            ->and($e)->toBeInstanceOf(NotifyAfricaValidationException::class);
    }
})->with([
    'not found' => [404, NotifyAfricaSenderException::NOT_FOUND],
    'limit reached' => [409, NotifyAfricaSenderException::LIMIT_REACHED],
    'not approved' => [422, NotifyAfricaSenderException::NOT_APPROVED],
]);

it('maps sender failures on batch sends too', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/batch' => Http::response(['status' => 409, 'message' => 'Sender usage limit reached'], 409),
    ]);

    expect(fn () => app(LaravelNotifyAfrica::class)->sendBulkSms(['255712345678'], 'Hi'))
        ->toThrow(NotifyAfricaSenderException::class, 'Sender usage limit reached');
});

it('uses the first string of an array error when message is absent', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send' => Http::response([
            'status' => 400,
            'error' => ['content must be shorter than or equal to 918 characters'],
        ], 400),
    ]);

    expect(fn () => app(LaravelNotifyAfrica::class)->sendSms(NotifyAfricaMessage::make()->to('255712345678')->content('Hi')))
        ->toThrow(NotifyAfricaValidationException::class, 'content must be shorter than or equal to 918 characters');
});

it('sends personalised bulk SMS via send-bulk with per-recipient ids', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send-bulk' => Http::response([
            'status' => 202,
            'message' => 'Bulk messages accepted',
            'timestamp' => '2026-09-09T13:10:00.000Z',
            'path' => '/api/v1/api/messages/send-bulk',
            'data' => [
                'acceptedCount' => 2,
                'rejectedCount' => 0,
                'results' => [
                    ['messageId' => 'd9b54c80-8a63-4f8b-83c8-71f2439f5429', 'status' => 'scheduled', 'estimatedSegments' => 1, 'debitApplied' => true],
                    ['messageId' => 'fe245daf-b447-4de7-9af4-35a2ab6cdd71', 'status' => 'scheduled', 'estimatedSegments' => 2, 'debitApplied' => true],
                ],
                'creditsDeducted' => 3,
                'remainingBalance' => 497,
            ],
        ], 202),
    ]);

    $response = app(LaravelNotifyAfrica::class)->sendPersonalizedSms(
        [
            ['to' => '255712345678', 'message' => 'Hello Asha'],
            ['recipient' => '+255 713 456 789', 'content' => 'Hello Baraka'],
        ],
        scheduledAt: new DateTimeImmutable('2026-09-10 11:00:00', new DateTimeZone('Africa/Dar_es_Salaam')),
    );

    expect($response->acceptedCount)->toBe(2)
        ->and($response->rejectedCount)->toBe(0)
        ->and($response->creditsDeducted)->toBe(3)
        ->and($response->remainingBalance)->toBe(497)
        ->and($response->apiStatus)->toBe(202)
        ->and($response->results)->toHaveCount(2)
        ->and($response->results[0]->status)->toBe('scheduled')
        ->and($response->results[1]->estimatedSegments)->toBe(2)
        ->and($response->results[1]->debitApplied)->toBeTrue()
        ->and($response->results[0]->accepted())->toBeTrue()
        ->and($response->messageIds())->toBe(['d9b54c80-8a63-4f8b-83c8-71f2439f5429', 'fe245daf-b447-4de7-9af4-35a2ab6cdd71']);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $request->url() === 'https://api.notify.africa/api/v1/api/messages/send-bulk'
            && $data['senderId'] === '137'
            && $data['messages'] === [
                ['recipient' => '+255712345678', 'content' => 'Hello Asha'],
                ['recipient' => '+255713456789', 'content' => 'Hello Baraka'],
            ]
            && $data['scheduledAt'] === '2026-09-10T08:00:00.000Z';
    });
});

it('sends personalised bulk without scheduledAt when not given and honours a sender override', function () {
    Http::fake([
        'https://api.notify.africa/api/v1/api/messages/send-bulk' => Http::response([
            'status' => 202,
            'data' => ['acceptedCount' => 1, 'rejectedCount' => 0, 'results' => [['messageId' => 'x', 'status' => 'processing']]],
        ], 202),
    ]);

    app(LaravelNotifyAfrica::class)->sendPersonalizedSms([['to' => '255712345678', 'message' => 'Hi']], 'GENESIS');

    Http::assertSent(fn ($request) => $request->data()['senderId'] === 'GENESIS'
        && ! array_key_exists('scheduledAt', $request->data()));
});

it('validates personalised bulk input before calling the API', function (array $messages) {
    Http::fake();

    expect(fn () => app(LaravelNotifyAfrica::class)->sendPersonalizedSms($messages))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'empty' => [[]],
    'no recipient' => [[['message' => 'Hi']]],
    'empty content' => [[['to' => '255712345678', 'message' => '  ']]],
]);

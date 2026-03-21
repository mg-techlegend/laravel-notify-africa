<?php

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use TechLegend\LaravelNotifyAfrica\Channels\NotifyAfricaChannel;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaAuthenticationException;
use TechLegend\LaravelNotifyAfrica\Exceptions\NotifyAfricaRequestException;
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
            'status' => 422,
            'message' => 'Invalid phone number',
        ], 422),
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

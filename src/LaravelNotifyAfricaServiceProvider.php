<?php

namespace TechLegend\LaravelNotifyAfrica;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TechLegend\LaravelNotifyAfrica\Channels\NotifyAfricaChannel;
use TechLegend\LaravelNotifyAfrica\Services\NotifyWhatsApp;
use TechLegend\LaravelNotifyAfrica\Waba\WabaClient;
use TechLegend\LaravelNotifyAfrica\Waba\WabaWebhookHandler;

class LaravelNotifyAfricaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-notify-africa')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(NotifyAfricaClient::class, function ($app) {
            return NotifyAfricaClient::fromConfig($app['config']->get('notify-africa', []));
        });

        $this->app->singleton(WabaClient::class, function ($app) {
            return WabaClient::fromConfig($app['config']->get('notify-africa', []));
        });

        $this->app->singleton(NotifyWhatsApp::class, function ($app) {
            return new NotifyWhatsApp($app->make(WabaClient::class));
        });

        $this->app->singleton(WabaWebhookHandler::class, function ($app) {
            return new WabaWebhookHandler(
                (string) ($app['config']->get('notify-africa.waba.webhook_secret') ?? ''),
                (string) ($app['config']->get('notify-africa.waba.signature_header') ?? 'X-Notify-Signature'),
            );
        });

        $this->app->singleton(LaravelNotifyAfrica::class, function ($app) {
            return new LaravelNotifyAfrica(
                $app->make(NotifyAfricaClient::class),
                fn () => $app->make(NotifyWhatsApp::class),
            );
        });

        $this->app->singleton(NotifyAfricaChannel::class, function ($app) {
            return new NotifyAfricaChannel($app->make(LaravelNotifyAfrica::class));
        });

        $this->app->alias(LaravelNotifyAfrica::class, 'notify-africa');
    }
}

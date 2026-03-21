<?php

namespace TechLegend\LaravelNotifyAfrica;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TechLegend\LaravelNotifyAfrica\Channels\NotifyAfricaChannel;

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

        $this->app->singleton(LaravelNotifyAfrica::class, function ($app) {
            return new LaravelNotifyAfrica($app->make(NotifyAfricaClient::class));
        });

        $this->app->singleton(NotifyAfricaChannel::class, function ($app) {
            return new NotifyAfricaChannel($app->make(LaravelNotifyAfrica::class));
        });

        $this->app->alias(LaravelNotifyAfrica::class, 'notify-africa');
    }
}

<?php

namespace TechLegend\LaravelNotifyAfrica;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TechLegend\LaravelNotifyAfrica\Commands\LaravelNotifyAfricaCommand;

class LaravelNotifyAfricaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-notify-africa')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_notify_africa_table')
            ->hasCommand(LaravelNotifyAfricaCommand::class);
    }
}

<?php

namespace TechLegend\LaravelNotifyAfrica\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use TechLegend\LaravelNotifyAfrica\LaravelNotifyAfricaServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LaravelNotifyAfricaServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }
}

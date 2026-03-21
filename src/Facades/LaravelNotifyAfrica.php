<?php

namespace TechLegend\LaravelNotifyAfrica\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica
 */
class LaravelNotifyAfrica extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica::class;
    }
}

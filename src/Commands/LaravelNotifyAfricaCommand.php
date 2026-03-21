<?php

namespace TechLegend\LaravelNotifyAfrica\Commands;

use Illuminate\Console\Command;

class LaravelNotifyAfricaCommand extends Command
{
    public $signature = 'laravel-notify-africa';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}

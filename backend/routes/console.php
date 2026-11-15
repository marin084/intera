<?php

use App\Console\Commands\SetupE2ETestCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('test:setup-e2e', function () {
    $this->call(SetupE2ETestCommand::class);
})->purpose('Prepare E2E test database and users');

Schedule::command('webchat:sessions:prune')
    ->daily()
    ->withoutOverlapping();

Schedule::command('conversations:check-inactivity')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('whatsapp:monitor-outbox')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('whatsapp:monitor-token-expiry')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

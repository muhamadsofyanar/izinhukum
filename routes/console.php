<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment('Legalitas yang rapi membuat bisnis tumbuh lebih tenang.');
})->purpose('Display an inspiring quote');

Schedule::command('whatsapp:dispatch-due --limit=1000')->everyMinute()->withoutOverlapping();
Schedule::command('whatsapp:run-automations')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('whatsapp:sync-status --limit=100')->everyTenMinutes()->withoutOverlapping();
Schedule::command('whatsapp:sync-devices')->hourly()->withoutOverlapping();

Schedule::command('whatsapp:prune')->dailyAt('02:20')->withoutOverlapping();

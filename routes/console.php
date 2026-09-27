<?php

use App\Domain\Transaction\Actions\ClosePayin;
use App\Domain\Webhook\Jobs\DeliverWebhook;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pay-ins nobody paid in time: release their accounts' capacity (Phase 6).
Schedule::call(fn () => app(ClosePayin::class)->expireDue())
    ->name('payins:expire')
    ->everyMinute()
    ->withoutOverlapping();

// Webhooks whose retry time has come (the outbox safety net, Phase 7).
Schedule::call(fn () => DeliverWebhook::dispatchDue())
    ->name('webhooks:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping();

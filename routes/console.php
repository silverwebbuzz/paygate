<?php

use App\Domain\Notification\AlertDispatcher;
use App\Domain\Platform\Settings;
use App\Domain\Reporting\Actions\ManageReportExports;
use App\Domain\Settlement\Actions\CalculateSettlement;
use App\Domain\Transaction\Actions\ClosePayin;
use App\Domain\Webhook\Jobs\DeliverWebhook;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

// Daily settlements, once per cut-off (time and timezone in Admin › Global
// Settings, G-09). Checked every minute so a changed cut-off applies at once;
// running twice creates nothing new (Phase 10).
Schedule::call(function () {
    $cutoff = app(Settings::class)->lastCutoff();

    if (Cache::get('settlements:last-cutoff') === $cutoff->toIso8601String()) {
        return;
    }

    app(CalculateSettlement::class)->daily($cutoff);
    Cache::forever('settlements:last-cutoff', $cutoff->toIso8601String());
})
    ->name('settlements:daily')
    ->everyMinute()
    ->withoutOverlapping();

// Exported report files are deleted after 7 days (G-49, Phase 11).
Schedule::call(fn () => app(ManageReportExports::class)->prune())
    ->name('reports:prune-exports')
    ->hourly()
    ->withoutOverlapping();

// Deposits waiting longer than the Global Settings threshold: alert the branch (G-47, Phase 12).
Schedule::call(fn () => app(AlertDispatcher::class)->depositsWaiting())
    ->name('alerts:deposits-waiting')
    ->everyFiveMinutes()
    ->withoutOverlapping();

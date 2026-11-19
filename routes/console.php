<?php

use App\Domain\Notification\AlertDispatcher;
use App\Domain\Platform\Settings;
use App\Domain\Reconciliation\Actions\AutoApproveDeposit;
use App\Domain\Reconciliation\StatementMatcher;
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

// Open "UTR not found" cases whose deposit arrived later on another account
// or branch: match their lines again (client report 2026-11-18). Safe to run
// any time; it only updates cases.
// Then open "Wrong branch" cases try the automatic move again (a different
// amount moves to the branch that has the money, client request 2026-11-19).
Artisan::command('reconciliation:recheck', function (StatementMatcher $matcher, AutoApproveDeposit $autoApprove) {
    $this->info($matcher->recheckUnfound().' “UTR not found” case(s) now point at their deposit.');
    $this->info($autoApprove->recheckWrongBranch().' “Wrong branch” deposit(s) moved to the branch that has the money.');
})->purpose('Re-match open “UTR not found” bank lines and retry moving “Wrong branch” deposits');

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

// Approved deposits no bank statement line has confirmed after the Global Settings hours: alert Admin.
Schedule::call(fn () => app(AlertDispatcher::class)->approvedWithoutBankLine())
    ->name('alerts:approved-without-bank-line')
    ->hourly()
    ->withoutOverlapping();

// Payouts partly paid with no transfer for a day: alert the branch and Admin (2026-10-23).
Schedule::call(fn () => app(AlertDispatcher::class)->payoutsPartPaid())
    ->name('alerts:payouts-part-paid')
    ->hourly()
    ->withoutOverlapping();

// Deposits waiting longer than the Global Settings threshold: alert the branch (G-47, Phase 12).
Schedule::call(fn () => app(AlertDispatcher::class)->depositsWaiting())
    ->name('alerts:deposits-waiting')
    ->everyFiveMinutes()
    ->withoutOverlapping();

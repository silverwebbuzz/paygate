<?php

use App\Domain\Transaction\Actions\ClosePayin;
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

<?php

namespace App\Domain\Reporting\Jobs;

use App\Domain\Platform\Models\StoredFile;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\ReportCatalog;
use App\Domain\Reporting\ReportWriter;
use App\Domain\Reporting\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Prepares one report export (queue `reports`). Runs as the person who
 * asked (their scope), so an export never contains more than their screen.
 * Safe to run twice: a finished export is left alone.
 */
class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $exportId)
    {
        // The long-running supervisor (config/horizon.php "slow") serves
        // `reports` on redis-long; tests run jobs synchronously.
        $this->onConnection(config('queue.default') === 'sync' ? 'sync' : 'redis-long');
        $this->onQueue('reports');
    }

    public function handle(ReportCatalog $catalog, ReportWriter $writer): void
    {
        $export = ReportExport::query()->with('user')->find($this->exportId);

        if ($export === null || ! in_array($export->status, ['queued', 'running'], true)) {
            return;
        }

        $export->forceFill(['status' => 'running'])->save();
        $relative = 'exports/tmp/'.Str::uuid()->toString().'.'.$export->format;

        try {
            $report = $catalog->find($export->user->type, $export->report) ?? throw new \RuntimeException('This report is not available to you.');
            $parameters = $export->parameters;
            $period = Period::between(CarbonImmutable::parse($parameters['from']), CarbonImmutable::parse($parameters['to']), $parameters['range']);

            Storage::disk('local')->makeDirectory('exports/tmp');
            $rows = $writer->write($report, Scope::of($export->user), $period, $parameters['filters'], $export->format, Storage::disk('local')->path($relative));

            $name = Str::slug($report->title()).'-'.CarbonImmutable::now(config('app.business_timezone'))->format('Ymd-His').'.'.$export->format;
            $file = StoredFile::adopt($relative, $name, $export->format === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'export', $export, 'user', $export->user_id);

            $export->forceFill([
                'status' => 'ready',
                'rows' => $rows,
                'file_id' => $file->id,
                'completed_at' => now(),
                'expires_at' => now()->addDays(ReportExport::KEEP_DAYS),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
            $export->forceFill(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500), 'completed_at' => now()])->save();
        } finally {
            Storage::disk('local')->delete($relative);
        }
    }
}

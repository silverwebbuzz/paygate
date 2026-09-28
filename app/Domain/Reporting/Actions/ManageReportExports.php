<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Reporting\Jobs\GenerateReportExport;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Reports\Report;
use Illuminate\Support\Facades\Storage;

/**
 * Asking for an export (audited, then prepared in the background) and
 * deleting exported files after 7 days (G-49). The export record stays.
 */
class ManageReportExports
{
    /**
     * @param  array<string, string|null>  $filters
     */
    public function request(User $actor, Report $report, Period $period, array $filters, string $format): ReportExport
    {
        $export = ReportExport::create([
            'user_id' => $actor->id,
            'report' => $report->key(),
            'format' => $format,
            'parameters' => [
                'range' => $period->range,
                'from' => $period->from->toIso8601String(),
                'to' => $period->to->toIso8601String(),
                'filters' => $filters,
            ],
            'status' => 'queued',
        ]);

        AuditLog::record('report.exported', $export, [], ['report' => $report->key(), 'format' => $format, 'period' => [$period->from->toIso8601String(), $period->to->toIso8601String()], 'filters' => $filters], $actor);

        GenerateReportExport::dispatch($export->id)->afterCommit();

        return $export;
    }

    /**
     * @return int files deleted
     */
    public function prune(): int
    {
        $count = 0;

        foreach (ReportExport::query()->where('status', 'ready')->where('expires_at', '<', now())->with('file')->get() as $export) {
            $file = $export->file;
            $export->forceFill(['status' => 'expired', 'file_id' => null])->save();

            if ($file !== null) {
                Storage::disk($file->disk)->delete($file->path);
                $file->delete();
            }

            $count++;
        }

        return $count;
    }
}

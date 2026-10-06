<?php

namespace App\Services\Ops;

use App\Models\DailyClosingReport;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/** The field app and approved assistant drafts write the same site/date report. */
class DailyFieldReportService
{
    public function save(int $siteId, string $date, array $attributes): DailyClosingReport
    {
        return DB::transaction(function () use ($siteId, $date, $attributes): DailyClosingReport {
            // The shared site lock also serializes an empty day's first field draft.
            Site::query()->whereKey($siteId)->lockForUpdate()->firstOrFail();
            $report = DailyClosingReport::query()->where('site_id', $siteId)
                ->whereDate('report_date', $date)->lockForUpdate()->first();
            if ($report) {
                $report->update($attributes);
            } else {
                $report = $this->create($siteId, $date, $attributes);
            }

            return $report->refresh();
        });
    }

    /** A concurrent date-key winner must raise a uniqueness conflict, never be overwritten. */
    public function create(int $siteId, string $date, array $attributes): DailyClosingReport
    {
        return DailyClosingReport::query()->create($attributes + [
            'site_id' => $siteId, 'report_date' => $date,
            'field_status' => 'draft', 'status' => DailyClosingReport::OPEN,
        ])->refresh();
    }
}

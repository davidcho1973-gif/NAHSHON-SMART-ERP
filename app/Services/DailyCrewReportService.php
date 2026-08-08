<?php

namespace App\Services;

use App\Models\AttendanceQrCode;
use App\Models\DailyCrewReport;
use App\Models\User;
use App\Services\Attendance\DailyHeadcountService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DailyCrewReportService
{
    public function __construct(
        private readonly AttendanceQrService $attendanceQrService,
        private readonly DailyHeadcountService $headcount,
    ) {}

    /**
     * @return array{
     *     report: DailyCrewReport|null,
     *     work_date: string,
     *     scanned_headcount: int,
     *     external_headcount: int,
     *     manual_adjustment: int,
     *     final_headcount: int
     * }
     */
    public function summary(AttendanceQrCode $qrCode, ?string $workDate = null): array
    {
        $date = $workDate ?: $this->todayFor($qrCode);
        $report = $qrCode->team_id
            ? DailyCrewReport::query()
                ->where('team_id', $qrCode->team_id)
                ->whereDate('work_date', $date)
                ->first()
            : null;
        $scanned = $this->scannedHeadcount($qrCode, $date);
        $external = (int) ($report?->external_headcount ?? 0);
        $adjustment = (int) ($report?->manual_adjustment ?? 0);

        return [
            'report' => $report,
            'work_date' => $date,
            'scanned_headcount' => $scanned,
            'external_headcount' => $external,
            'manual_adjustment' => $adjustment,
            'final_headcount' => max(0, $scanned + $external + $adjustment),
        ];
    }

    /**
     * Close or update one team's daily headcount without creating Employee or payroll records.
     *
     * @param  array{
     *     external_headcount?: int|string|null,
     *     manual_adjustment?: int|string|null,
     *     work_description?: string|null,
     *     adjustment_reason?: string|null,
     *     notes?: string|null
     * }  $data
     */
    public function closeDay(User $user, AttendanceQrCode $qrCode, array $data): DailyCrewReport
    {
        if (! $this->attendanceQrService->canProcessCrew($user, $qrCode)) {
            throw new RuntimeException('You do not have crew attendance permission for this QR.');
        }

        if (! $qrCode->team_id) {
            throw new RuntimeException('A team must be connected to the QR before the daily crew report can be closed.');
        }

        $adjustment = (int) ($data['manual_adjustment'] ?? 0);
        if ($adjustment !== 0 && blank($data['adjustment_reason'] ?? null)) {
            throw new RuntimeException('인원 보정이 있으면 보정 사유를 입력해 주세요.');
        }

        $workDate = $this->todayFor($qrCode);
        $scannedHeadcount = $this->scannedHeadcount($qrCode, $workDate);

        return DB::transaction(function () use ($user, $qrCode, $data, $adjustment, $workDate, $scannedHeadcount): DailyCrewReport {
            $report = DailyCrewReport::query()->firstOrNew([
                'team_id' => $qrCode->team_id,
                'work_date' => $workDate,
            ]);

            $report->fill([
                'company_id' => $qrCode->team?->company_id ?: $qrCode->site?->company_id,
                'site_id' => $qrCode->site_id,
                'site_contractor_id' => $qrCode->site_contractor_id,
                'attendance_qr_code_id' => $qrCode->id,
                'scanned_headcount' => $scannedHeadcount,
                'external_headcount' => max(0, (int) ($data['external_headcount'] ?? 0)),
                'manual_adjustment' => $adjustment,
                'status' => 'closed',
                'work_description' => $this->trimOrNull($data['work_description'] ?? null),
                'adjustment_reason' => $this->trimOrNull($data['adjustment_reason'] ?? null),
                'notes' => $this->trimOrNull($data['notes'] ?? null),
                'reported_by_id' => $user->id,
                'reported_at' => now(),
                'closed_by_id' => $user->id,
                'closed_at' => now(),
                'payload' => [
                    'payroll_separated' => true,
                    'source' => 'team_qr_daily_close',
                ],
            ]);
            $report->save();

            return $report->refresh();
        });
    }

    /**
     * 인원은 직접 세지 않고 DailyHeadcountService 에 묻는다.
     *
     * 예전에는 여기에 집계 쿼리가 따로 있었다. 같은 attendance_logs 를 보면서도
     * 규칙이 두 벌이라, 모바일앱에서 본 인원과 상황실에서 본 인원이 어긋날 수 있었다.
     * 세는 규칙은 한 곳에만 있어야 한다.
     */
    public function scannedHeadcount(AttendanceQrCode $qrCode, string $workDate): int
    {
        if (! $qrCode->team_id) {
            return 0;
        }

        return $this->headcount->presentCount($qrCode->site_id, $workDate, $qrCode->team_id);
    }

    public function todayFor(AttendanceQrCode $qrCode): string
    {
        return Carbon::today($qrCode->site?->timezone ?: config('app.timezone'))->toDateString();
    }

    private function trimOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

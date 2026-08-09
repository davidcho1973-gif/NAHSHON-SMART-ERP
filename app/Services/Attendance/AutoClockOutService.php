<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * 간접고용(협력사) 퇴근 자동 마감.
 *
 * 협력사 인원은 "오늘 몇 명 왔나"가 핵심이라 퇴근을 자주 빠뜨린다. 출근만 있고 퇴근이 없으면
 * 그날 기록이 미완으로 남으므로, 현장 기준 16:00 으로 자동 퇴근 처리한다.
 *
 * 직접고용은 대상이 아니다 — 시급이 임금으로 직결되어 임의 시각으로 마감하면 급여가 틀어진다.
 * 그쪽은 미마감으로 남겨 관리자가 확인하게 한다.
 */
class AutoClockOutService
{
    /** 자동 퇴근 처리 시각(현장 로컬 기준, 24h). */
    public const CUTOFF_HOUR = 16;

    /**
     * @return array{closed: int, pendingDirect: int, date: string}
     */
    public function run(?Carbon $date = null): array
    {
        $closed = 0;
        $pendingDirect = 0;
        $dateArg = $date;

        foreach (Site::query()->where('status', 'active')->get() as $site) {
            $tz = $site->timezone ?: config('app.timezone');
            $workDate = ($dateArg ?? Carbon::now($tz))->copy()->timezone($tz)->toDateString();
            $cutoff = Carbon::parse($workDate.' '.str_pad((string) self::CUTOFF_HOUR, 2, '0', STR_PAD_LEFT).':00:00', $tz);

            // 그날 출근만 있고 퇴근이 없는 사람.
            $open = $this->openEmployees($site->id, $workDate);
            if ($open === []) {
                continue;
            }

            $employees = Employee::query()->whereIn('id', $open)->get();
            foreach ($employees as $emp) {
                if ($emp->employment_type !== Employee::TYPE_INDIRECT) {
                    // 직접고용 등은 자동 마감하지 않는다(임금 왜곡 방지) — 관리자 확인 대상.
                    $pendingDirect++;

                    continue;
                }

                AttendanceLog::create([
                    'employee_id' => $emp->id,
                    'company_id' => $emp->company_id,
                    'site_id' => $site->id,
                    'attendance_date' => $workDate,
                    'event_type' => 'clock_out',
                    'event_at' => $cutoff,
                    'source' => 'auto_clockout',
                    'status' => 'approved',
                    'notes' => '퇴근 미기록 — 16:00 자동 마감(간접고용)',
                ]);
                $closed++;
            }
        }

        return [
            'closed' => $closed,
            'pendingDirect' => $pendingDirect,
            'date' => ($dateArg ?? Carbon::now())->toDateString(),
        ];
    }

    /**
     * 그날 마지막 기록이 clock_in 인(=퇴근 안 찍은) 직원 id 목록.
     *
     * @return array<int, int>
     */
    private function openEmployees(int $siteId, string $workDate): array
    {
        $logs = AttendanceLog::query()
            ->where('site_id', $siteId)
            ->where('attendance_date', $workDate)
            ->where('status', '!=', 'rejected')
            ->orderBy('event_at')->orderBy('id')
            ->get(['employee_id', 'event_type']);

        $last = [];
        foreach ($logs as $log) {
            $last[$log->employee_id] = $log->event_type;
        }

        return array_keys(array_filter($last, fn (string $t) => $t === 'clock_in'));
    }
}

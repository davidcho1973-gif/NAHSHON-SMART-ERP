<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * 오늘 출역 현황 — 고용 형태에 따라 보는 관점이 다르다.
 *
 *  직접고용(우리 회사 시급) : 몇 시간 일했나 — 근무시간 합계·평균, 퇴근 미기록자 노출이 핵심.
 *  간접고용(협력사)         : 몇 명 왔나 — 업체별 인원수가 핵심.
 *
 * 한 화면에서 둘 다 보되 강조점을 나눠 보여준다.
 */
class DailyHeadcountService
{
    /**
     * "그날 왔다" 의 정의 — 이 규칙은 여기 한 곳에만 있다.
     *
     * 출근(clock_in) 기록이 있어야 온 것으로 센다. 퇴근만 찍힌 사람(전날 야간조가
     * 새벽에 퇴근한 경우)은 그날 온 사람이 아니다. 반려된 기록은 어느 쪽도 안 센다.
     *
     * 전에는 모바일 출퇴근앱(DailyCrewReportService)이 이 규칙을 따로 갖고 있어서,
     * 같은 attendance_logs 를 보면서도 앱에서 본 인원과 상황실에서 본 인원이
     * 어긋날 수 있었다. 인원을 세는 규칙이 두 곳에 있으면 언젠가 갈라진다.
     *
     * @return array<int, array{seconds: int, openAt: mixed, firstIn: mixed, lastOut: mixed}>
     */
    private function attendanceState(?int $siteId, string $workDate, ?int $teamId = null): array
    {
        $logs = AttendanceLog::query()
            ->where('attendance_date', $workDate)
            ->where('status', '!=', 'rejected')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->orderBy('event_at')->orderBy('id')
            ->get(['employee_id', 'event_type', 'event_at']);

        // 직원별 근무 구간을 접는다: clock_in 을 열고 clock_out 에서 닫으며 초를 누적.
        $state = [];
        foreach ($logs as $log) {
            $id = (int) $log->employee_id;

            if ($log->event_type === 'clock_in') {
                $state[$id] ??= ['seconds' => 0, 'openAt' => null, 'firstIn' => null, 'lastOut' => null];
                $state[$id]['openAt'] ??= $log->event_at;
                $state[$id]['firstIn'] ??= $log->event_at;
            } elseif ($log->event_type === 'clock_out' && ($state[$id]['openAt'] ?? null)) {
                $state[$id]['seconds'] += max(0, $state[$id]['openAt']->diffInSeconds($log->event_at));
                $state[$id]['openAt'] = null;
                $state[$id]['lastOut'] = $log->event_at;
            }
        }

        return $state;
    }

    /**
     * 그날 그 현장(또는 그 팀)에 몇 명이 왔나 — 인원을 세는 단 하나의 진입점.
     */
    public function presentCount(?int $siteId, string $workDate, ?int $teamId = null): int
    {
        return count($this->attendanceState($siteId, $workDate, $teamId));
    }

    /**
     * @return array{
     *   date: string, siteId: ?int, siteName: ?string,
     *   direct: array{count: int, workedHours: float, avgHours: float, open: int},
     *   indirect: array{count: int, open: int},
     *   other: array{count: int},
     *   companies: array<int, array{company: string, type: string, typeLabel: string, count: int, hours: float, open: int}>,
     *   workers: array<int, array<string, mixed>>
     * }
     */
    public function today(?int $siteId = null, ?string $date = null, ?int $teamId = null): array
    {
        $site = $siteId ? Site::query()->find($siteId) : null;
        $tz = $site?->timezone ?: config('app.timezone');
        $workDate = $date ?: Carbon::now($tz)->toDateString();

        $state = $this->attendanceState($siteId, $workDate, $teamId);

        if ($state === []) {
            return $this->empty($workDate, $siteId, $site?->name);
        }

        $employees = Employee::query()->whereIn('id', array_keys($state))
            ->with('company:id,name')->get();

        $direct = ['count' => 0, 'workedHours' => 0.0, 'avgHours' => 0.0, 'open' => 0];
        $indirect = ['count' => 0, 'open' => 0];
        $other = ['count' => 0];
        $byCompany = [];
        $workers = [];

        foreach ($employees as $emp) {
            $s = $state[$emp->id];
            $type = (string) ($emp->employment_type ?: Employee::TYPE_DIRECT);
            $hours = round($s['seconds'] / 3600, 2);
            $open = $s['openAt'] !== null;
            $company = $emp->company?->name ?: '소속 미지정';

            if ($type === Employee::TYPE_DIRECT) {
                $direct['count']++;
                $direct['workedHours'] += $hours;
                $direct['open'] += $open ? 1 : 0;
            } elseif ($type === Employee::TYPE_INDIRECT) {
                $indirect['count']++;
                $indirect['open'] += $open ? 1 : 0;
            } else {
                $other['count']++;
            }

            $key = $type.'|'.$company;
            $byCompany[$key] ??= [
                'company' => $company,
                'type' => $type,
                'typeLabel' => Employee::EMPLOYMENT_TYPES[$type] ?? $type,
                'count' => 0,
                'hours' => 0.0,
                'open' => 0,
            ];
            $byCompany[$key]['count']++;
            $byCompany[$key]['hours'] = round($byCompany[$key]['hours'] + $hours, 2);
            $byCompany[$key]['open'] += $open ? 1 : 0;

            $workers[] = [
                'id' => $emp->id,
                'name' => trim((string) ($emp->name ?: $emp->first_name.' '.$emp->last_name)),
                'company' => $company,
                'trade' => (string) $emp->role,
                'type' => $type,
                'typeLabel' => Employee::EMPLOYMENT_TYPES[$type] ?? $type,
                'in' => $s['firstIn']?->format('H:i'),
                'out' => $s['lastOut']?->format('H:i'),
                'hours' => $hours,
                'open' => $open,
            ];
        }

        $direct['workedHours'] = round($direct['workedHours'], 2);
        $direct['avgHours'] = $direct['count'] > 0 ? round($direct['workedHours'] / $direct['count'], 2) : 0.0;

        // 협력사(인원 많은 순) → 직접고용 순으로 정렬해 "오늘 몇 명" 이 위로 오게 한다.
        $companies = array_values($byCompany);
        usort($companies, function (array $a, array $b): int {
            return [$a['type'] === Employee::TYPE_INDIRECT ? 0 : 1, -$a['count']]
               <=> [$b['type'] === Employee::TYPE_INDIRECT ? 0 : 1, -$b['count']];
        });

        usort($workers, fn (array $a, array $b) => [$a['company'], $a['name']] <=> [$b['company'], $b['name']]);

        return [
            'date' => $workDate,
            'siteId' => $siteId,
            'siteName' => $site?->name,
            'direct' => $direct,
            'indirect' => $indirect,
            'other' => $other,
            'companies' => $companies,
            'workers' => $workers,
        ];
    }

    /** @return array<string, mixed> */
    private function empty(string $workDate, ?int $siteId, ?string $siteName): array
    {
        return [
            'date' => $workDate,
            'siteId' => $siteId,
            'siteName' => $siteName,
            'direct' => ['count' => 0, 'workedHours' => 0.0, 'avgHours' => 0.0, 'open' => 0],
            'indirect' => ['count' => 0, 'open' => 0],
            'other' => ['count' => 0],
            'companies' => [],
            'workers' => [],
        ];
    }
}

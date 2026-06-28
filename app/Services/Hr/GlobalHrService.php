<?php

namespace App\Services\Hr;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 글로벌 인사·출퇴근 현황 집계 — 국가 → 현장 → 원청사(소속사) → 팀.
 *
 * 다국가(미국/한국/캐나다 …) 다현장 운영을 한 화면에서 보기 위한 읽기 모델.
 * 출근 판정: 오늘(현장 기준일) 마지막 출퇴근 이벤트가 clock_in 이면 "출근 중".
 */
class GlobalHrService
{
    public const COUNTRIES = [
        'US' => ['label' => 'United States', 'flag' => '🇺🇸'],
        'KR' => ['label' => 'Korea', 'flag' => '🇰🇷'],
        'CA' => ['label' => 'Canada', 'flag' => '🇨🇦'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function overview(string $country = 'ALL'): array
    {
        $siteQuery = Site::query()->where('status', 'active');
        if ($country !== 'ALL') {
            $siteQuery->where('country', $country);
        }
        $sites = $siteQuery->orderBy('country')->orderBy('code')->get();

        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->whereIn('site_id', $sites->pluck('id'))
            ->with(['company:id,name', 'team:id,name'])
            ->get();

        $presence = $this->presenceMap($employees->pluck('id')->all());
        $isPresent = fn (Employee $e): bool => ($presence[$e->id] ?? null) === 'clock_in';

        $bySite = $employees->groupBy('site_id');

        $countries = [];
        $matrix = [];
        $companySet = [];

        foreach ($sites as $site) {
            $emps = $bySite->get($site->id, collect());
            $cc = $site->country ?: 'US';

            $companies = $emps->groupBy(fn (Employee $e) => $e->company?->name ?: '미지정')
                ->map(function (Collection $cEmps, string $cName) use ($isPresent, $site, &$matrix, &$companySet): array {
                    $companySet[$cName] = true;
                    $teams = $cEmps->groupBy(fn (Employee $e) => $e->team?->name ?: '미배정')
                        ->map(function (Collection $tEmps, string $tName) use ($isPresent, $site, $cName, &$matrix): array {
                            $present = $tEmps->filter($isPresent)->count();
                            $matrix[] = [
                                'site' => $site->code, 'company' => $cName, 'team' => $tName,
                                'total' => $tEmps->count(), 'present' => $present,
                                'rate' => $this->rate($present, $tEmps->count()),
                            ];

                            return ['team' => $tName, 'total' => $tEmps->count(), 'present' => $present];
                        })->values()->all();

                    return [
                        'company' => $cName,
                        'total' => $cEmps->count(),
                        'present' => $cEmps->filter($isPresent)->count(),
                        'teams' => $teams,
                    ];
                })->values()->all();

            $present = $emps->filter($isPresent)->count();

            $countries[$cc] ??= [
                'country' => $cc,
                'label' => self::COUNTRIES[$cc]['label'] ?? $cc,
                'flag' => self::COUNTRIES[$cc]['flag'] ?? '🏳️',
                'employees' => 0, 'present' => 0, 'sites' => [],
            ];
            $countries[$cc]['employees'] += $emps->count();
            $countries[$cc]['present'] += $present;
            $countries[$cc]['sites'][] = [
                'code' => $site->code,
                'name' => $site->name,
                'primaryCompany' => $companies[0]['company'] ?? '-',
                'total' => $emps->count(),
                'present' => $present,
                'rate' => $this->rate($present, $emps->count()),
                'companies' => $companies,
            ];
        }

        $totalEmp = $employees->count();
        $totalPresent = $employees->filter($isPresent)->count();

        return [
            'success' => true,
            'asOf' => Carbon::now()->toIso8601String(),
            'totals' => [
                'employees' => $totalEmp,
                'present' => $totalPresent,
                'absent' => $totalEmp - $totalPresent,
                'rate' => $this->rate($totalPresent, $totalEmp),
                'sites' => $sites->count(),
                'countries' => count($countries),
                'companies' => count($companySet),
            ],
            'countries' => array_values($countries),
            'matrix' => $matrix,
            'recent' => $this->recentFeed($sites, $employees),
        ];
    }

    /**
     * 직원별 오늘의 마지막 이벤트 타입 맵 (clock_in/clock_out).
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, string>
     */
    private function presenceMap(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $logs = AttendanceLog::query()
            ->where('attendance_date', Carbon::today()->toDateString())
            ->where('status', 'approved')
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('event_at')
            ->get(['employee_id', 'event_type']);

        $map = [];
        foreach ($logs as $log) {
            $map[$log->employee_id] = $log->event_type; // 시간순이라 마지막 값이 최신
        }

        return $map;
    }

    /**
     * 실시간 출퇴근 피드 (오늘, 최신순).
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentFeed(Collection $sites, Collection $employees): array
    {
        $siteByPk = $sites->keyBy('id');
        $empByPk = $employees->keyBy('id');

        return AttendanceLog::query()
            ->where('attendance_date', Carbon::today()->toDateString())
            ->whereIn('employee_id', $employees->pluck('id'))
            ->orderByDesc('event_at')
            ->limit(20)
            ->get(['employee_id', 'site_id', 'event_type', 'event_at', 'source', 'status'])
            ->map(function (AttendanceLog $log) use ($siteByPk, $empByPk): array {
                $emp = $empByPk->get($log->employee_id);
                $site = $siteByPk->get($log->site_id);
                $cc = $site?->country ?: 'US';

                return [
                    'name' => $emp?->name ?? '직원',
                    'team' => $emp?->team?->name ?? '-',
                    'company' => $emp?->company?->name ?? '-',
                    'site' => $site?->code ?? '-',
                    'flag' => self::COUNTRIES[$cc]['flag'] ?? '🏳️',
                    'event_type' => $log->event_type,
                    'source' => $log->source,
                    'status' => $log->status,
                    'time' => $log->event_at?->format('H:i'),
                ];
            })->all();
    }

    private function rate(int $present, int $total): int
    {
        return $total > 0 ? (int) round($present / $total * 100) : 0;
    }
}

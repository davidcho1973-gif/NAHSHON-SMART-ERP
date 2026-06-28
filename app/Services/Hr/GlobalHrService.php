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
        $sites = $siteQuery->with('client:id,name')->orderBy('country')->orderBy('code')->get();

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
            // 원청사(client) = 현장 발주처. 미지정 시 표시는 '-' (소속사 employer 와 구분).
            $clientName = $site->client?->name ?: '-';

            // 소속사(employer)별 → 팀별 (원청사는 현장 단위라 매트릭스에 별도 컬럼으로 표기).
            $companies = $emps->groupBy(fn (Employee $e) => $e->company?->name ?: '미지정')
                ->map(function (Collection $cEmps, string $cName) use ($isPresent, $site, $clientName, &$matrix, &$companySet): array {
                    $companySet[$cName] = true;
                    $teams = $cEmps->groupBy(fn (Employee $e) => $e->team?->name ?: '미배정')
                        ->map(function (Collection $tEmps, string $tName) use ($isPresent, $site, $cName, $clientName, &$matrix): array {
                            $present = $tEmps->filter($isPresent)->count();
                            $matrix[] = [
                                'site' => $site->code, 'client' => $clientName, 'company' => $cName, 'team' => $tName,
                                'total' => $tEmps->count(), 'present' => $present,
                                'rate' => $this->rate($present, $tEmps->count()),
                            ];

                            return ['team' => $tName, 'total' => $tEmps->count(), 'present' => $present];
                        })->values()->all();

                    return [
                        'company' => $cName, // 소속사 (employer)
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
                'client' => $clientName,                         // 원청사 (발주처)
                'primaryCompany' => $companies[0]['company'] ?? '-', // 소속사 (대표 고용사)
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

    /**
     * 팀 편성 보드 — 현장의 팀별 인원 + 미배치 인원.
     *
     * @return array<string, mixed>
     */
    public function teamBoard(string $siteCode): array
    {
        $site = Site::query()->where('code', $siteCode)->first();
        if (! $site) {
            return ['success' => false, 'error' => "현장을 찾을 수 없습니다: {$siteCode}"];
        }

        $teams = \App\Models\Team::query()->where('site_id', $site->id)->where('status', 'active')->orderBy('name')->get();
        $employees = Employee::query()->where('employment_status', 'active')->where('site_id', $site->id)
            ->with('company:id,name')->get();
        $byTeam = $employees->groupBy('team_id');

        $card = fn (Employee $e): array => [
            'id' => $e->id,
            'name' => $e->name,
            'role' => $e->role ?: '미정',
            'company' => $e->company?->name ?: '-',
            'flag' => $this->nationalityFlag($e->nationality),
        ];

        return [
            'success' => true,
            'site' => ['code' => $site->code, 'name' => $site->name],
            'teams' => $teams->map(fn (\App\Models\Team $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'members' => ($byTeam[$t->id] ?? collect())->map($card)->values()->all(),
            ])->values()->all(),
            'unassigned' => ($byTeam[null] ?? collect())->map($card)->values()->all(),
        ];
    }

    /**
     * 직원을 팀에 배치/이동 (같은 현장 내).
     *
     * @return array<string, mixed>
     */
    public function assignTeam(int $employeeId, ?int $teamId): array
    {
        $employee = Employee::query()->find($employeeId);
        if (! $employee) {
            return ['success' => false, 'message' => '직원을 찾을 수 없습니다.'];
        }

        if ($teamId !== null) {
            $team = \App\Models\Team::query()->find($teamId);
            if (! $team || $team->site_id !== $employee->site_id) {
                return ['success' => false, 'message' => '같은 현장의 팀에만 배치할 수 있습니다.'];
            }
        }

        $employee->team_id = $teamId;
        $employee->save();

        return ['success' => true, 'message' => '팀 배치가 변경되었습니다.'];
    }

    private function nationalityFlag(?string $nationality): string
    {
        $n = strtolower((string) $nationality);
        if (str_contains($n, 'korea') || str_contains($n, '한국') || $n === 'kr') {
            return '🇰🇷';
        }
        if (str_contains($n, 'canad') || $n === 'ca') {
            return '🇨🇦';
        }
        if (str_contains($n, 'us') || str_contains($n, 'america') || $n === 'usa') {
            return '🇺🇸';
        }

        return '🏳️';
    }
}

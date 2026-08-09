<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollTimesheet;
use App\Models\Payslip;
use App\Models\Site;
use App\Models\SiteWifiAccessPoint;
use Illuminate\Support\Carbon;

/**
 * 작업자 화면의 뒷단 — 오늘 내 출퇴근.
 *
 * 이 화면의 규칙은 하나다: **작업자에게 방법을 고르게 하지 않는다.** 자동이 되면 아무것도
 * 누를 필요가 없고, 안 되면 앱이 알아서 다음 방법을 내민다. 그래서 화면에는 늘 큰 버튼
 * 하나와 "왜 이 방법인지" 한 줄만 보인다.
 *
 * 내려가는 순서:
 *   1단 자동 — 현장 반경 안 또는 현장 네트워크. 아무것도 안 눌러도 찍힌다.
 *   2단 직접 — 위치 권한이 없거나 실내라 신호가 약할 때. 버튼 하나.
 *   3단 QR   — 인터넷이 끊겼을 때. 내 폰은 아무것도 못 보내므로 반장이 스캔한다.
 *
 * 2단을 반경 밖에서 누르면 승인 대기로 남긴다. 막지는 않는다 — 지오펜스가 아직 없는
 * 새 현장이나 신호가 안 잡히는 구석에서 일하는 사람의 길을 끊으면 안 되기 때문이다.
 * 대신 반장이 한 번 보게 만든다.
 */
class WorkerAttendanceService
{
    public function __construct(private readonly AttendanceGeoService $geo) {}

    /**
     * 홈 화면에 필요한 모든 것.
     *
     * @return array<string, mixed>
     */
    public function home(Employee $employee): array
    {
        $site = $employee->site_id ? Site::find($employee->site_id) : null;
        $tz = $site?->timezone ?: config('app.timezone');
        $today = Carbon::now($tz)->toDateString();

        $session = $site
            ? AttendanceSession::query()
                ->where('employee_id', $employee->id)
                ->where('work_date', $today)
                ->first()
            : null;

        $logs = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $today)
            ->orderBy('event_at')
            ->get()
            ->map(fn (AttendanceLog $l): array => [
                'id' => $l->id,
                'type' => $l->event_type,
                'typeLabel' => $l->event_type === 'clock_in' ? '출근' : '퇴근',
                'at' => $l->event_at?->timezone($tz)->format('H:i'),
                'source' => $l->source,
                'sourceLabel' => self::SOURCE_LABELS[$l->source] ?? $l->source,
                'status' => $l->status,
                'needsReview' => $l->status === 'pending',
            ])->values()->all();

        $clockedIn = collect($logs)->firstWhere('type', 'clock_in') !== null
            && collect($logs)->firstWhere('type', 'clock_out') === null;

        // 화면이 초를 세려면 "지금까지 몇 초"가 한 숫자로 와야 한다. 자동은 세션이,
        // 직접 누른 경우는 출근 기록이 기준이라 여기서 합쳐 준다 — 화면에서 두 갈래로
        // 계산하게 두면 자동과 직접의 시간이 다르게 보인다.
        $elapsed = 0;
        if ($session && $session->status === 'on_site' && $session->last_enter_at) {
            $elapsed = (int) $session->on_site_seconds + $session->last_enter_at->diffInSeconds(Carbon::now());
        } elseif ($session) {
            $elapsed = (int) $session->on_site_seconds;
        } elseif ($clockedIn) {
            $openedAt = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->where('attendance_date', $today)
                ->where('event_type', 'clock_in')
                ->orderBy('event_at')
                ->value('event_at');
            $elapsed = $openedAt ? Carbon::parse($openedAt)->diffInSeconds(Carbon::now()) : 0;
        }

        return [
            'success' => true,
            'employee' => [
                'name' => $employee->name,
                'number' => $employee->employee_number,
                'trade' => $employee->role,
                'lang' => $employee->preferred_language ?: 'ko',
            ],
            'site' => $site ? [
                'code' => $site->code,
                'name' => $site->name,
                'radius' => $site->radius_meters ? (int) $site->radius_meters : null,
                // 자동이 가능한 조건 두 가지. 둘 다 없으면 이 현장은 아직 자동이 안 된다.
                'hasGeofence' => $site->latitude !== null && $site->longitude !== null && (bool) $site->radius_meters,
                'hasNetwork' => SiteWifiAccessPoint::query()
                    ->where('site_id', $site->id)->where('active', true)->exists(),
            ] : null,
            'today' => $today,
            'state' => $session?->status ?? 'off_site',
            'onSiteSeconds' => (int) ($session?->on_site_seconds ?? 0),
            'elapsedSeconds' => max(0, $elapsed),
            'firstEnterAt' => $session?->first_enter_at?->timezone($tz)->format('H:i')
                ?? collect($logs)->firstWhere('type', 'clock_in')['at'] ?? null,
            'clockedIn' => $clockedIn,
            'logs' => $logs,
            'week' => $this->week($employee, $tz),
            'pay' => $this->pay($employee),
        ];
    }

    /**
     * 이번 주 근무 — 탭 하나를 채우는 만큼만.
     *
     * 시간은 급여 타임시트에서 온다. 출퇴근 기록을 화면에서 다시 계산하지 않는다 —
     * 급여가 보는 숫자와 작업자가 보는 숫자가 다르면 그 차이를 설명할 사람이 없다.
     *
     * @return array<string, mixed>
     */
    private function week(Employee $employee, string $tz): array
    {
        $start = Carbon::now($tz)->startOfWeek();
        $end = Carbon::now($tz)->endOfWeek();

        $sheets = PayrollTimesheet::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('work_date')
            ->get();

        $days = $sheets->map(function (PayrollTimesheet $t) use ($tz): array {
            $regular = (int) $t->regular_minutes;
            $overtime = (int) $t->overtime_minutes;

            return [
                'date' => $t->work_date?->toDateString(),
                'label' => $t->work_date?->timezone($tz)->format('m/d'),
                'weekday' => $t->work_date ? self::WEEKDAYS[(int) $t->work_date->dayOfWeek] : null,
                'in' => $t->check_in_at?->timezone($tz)->format('H:i'),
                'out' => $t->check_out_at?->timezone($tz)->format('H:i'),
                'regularHours' => round($regular / 60, 1),
                'overtimeHours' => round($overtime / 60, 1),
                'status' => $t->status,
                // 아직 안 닫힌 날은 숫자가 바뀔 수 있다. 확정처럼 보이면 안 된다.
                'settled' => in_array($t->status, ['approved', 'locked', 'paid'], true),
            ];
        })->values()->all();

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'regularHours' => round($sheets->sum('regular_minutes') / 60, 1),
            'overtimeHours' => round($sheets->sum('overtime_minutes') / 60, 1),
            'days' => $days,
        ];
    }

    private const WEEKDAYS = ['일', '월', '화', '수', '목', '금', '토'];

    /**
     * 급여 — 예상액과 지난 명세.
     *
     * 예상액은 말 그대로 예상이다. 세금·공제 전이고 마감 전이라 바뀐다. 화면에서도
     * 그렇게 말해야 한다 — 숫자만 크게 띄우면 작업자는 그 금액을 받는 줄 안다.
     *
     * @return array<string, mixed>
     */
    private function pay(Employee $employee): array
    {
        $profile = EmployeePayrollProfile::query()->where('employee_id', $employee->id)->first();
        $rate = (float) ($profile?->base_rate ?? 0);
        $multiplier = (float) ($profile?->overtime_multiplier ?: 1.5);
        $currency = $profile?->pay_currency ?: 'USD';

        $tz = $employee->site?->timezone ?: config('app.timezone');
        $week = $this->week($employee, $tz);

        $regularPay = $rate * $week['regularHours'];
        $overtimePay = $rate * $multiplier * $week['overtimeHours'];

        $slips = Payslip::query()
            ->where('employee_id', $employee->id)
            ->with('run:id,period_start,period_end')
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(fn (Payslip $p): array => [
                'net' => (float) $p->net_pay,
                'from' => $p->run?->period_start?->toDateString(),
                'to' => $p->run?->period_end?->toDateString(),
                'status' => $p->status,
            ])->values()->all();

        return [
            // 단가가 없으면 금액을 지어내지 않는다. 화면이 "아직 정해지지 않았다"고 말한다.
            'hasRate' => $rate > 0,
            'rate' => $rate,
            'multiplier' => $multiplier,
            'currency' => $currency,
            'regularPay' => round($regularPay, 2),
            'overtimePay' => round($overtimePay, 2),
            'estimated' => round($regularPay + $overtimePay, 2),
            'payslips' => $slips,
        ];
    }

    private const SOURCE_LABELS = [
        'geo_auto' => '자동',
        'web_portal' => '직접',
        'qr' => 'QR',
        'field_app' => '현장앱',
        'central_control' => '관리자',
    ];

    /**
     * 2단 — 직접 누르는 출퇴근.
     *
     * 위치를 같이 보내면 반경 안인지 판정해 승인 여부를 가른다. 위치가 없으면(권한을 껐거나
     * 실내라 안 잡히거나) 승인 대기로 둔다 — 확인할 방법이 없는 기록을 그냥 통과시키면
     * 나중에 아무도 그 시간을 설명하지 못한다.
     *
     * @param  array<string, mixed>  $signal
     * @return array<string, mixed>
     */
    public function punch(Employee $employee, string $direction, array $signal = []): array
    {
        if (! in_array($direction, ['in', 'out'], true)) {
            return ['success' => false, 'error' => '출근/퇴근 중 하나를 선택해 주세요.'];
        }

        $site = $employee->site_id ? Site::find($employee->site_id) : null;
        if (! $site) {
            return ['success' => false, 'error' => '배정된 현장이 없습니다. 관리자에게 현장 배정을 요청해 주세요.'];
        }

        $tz = $site->timezone ?: config('app.timezone');
        $now = Carbon::now();
        $today = $now->copy()->timezone($tz)->toDateString();
        $eventType = $direction === 'in' ? 'clock_in' : 'clock_out';

        $existing = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $today)
            ->where('status', '!=', 'rejected')
            ->get();

        if ($direction === 'in' && $existing->where('event_type', 'clock_in')->isNotEmpty()) {
            return ['success' => false, 'error' => '오늘은 이미 출근이 기록되었습니다.'];
        }
        if ($direction === 'out') {
            if ($existing->where('event_type', 'clock_in')->isEmpty()) {
                return ['success' => false, 'error' => '출근 기록이 없습니다. 먼저 출근을 눌러 주세요.'];
            }
            if ($existing->where('event_type', 'clock_out')->isNotEmpty()) {
                return ['success' => false, 'error' => '오늘은 이미 퇴근이 기록되었습니다.'];
            }
        }

        $verified = $this->verifyOnSite($site, $signal);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'site_id' => $site->id,
            'team_id' => $employee->team_id,
            'attendance_date' => $today,
            'event_type' => $eventType,
            'event_at' => $now,
            'source' => 'web_portal',
            // 현장에 있는 것이 확인되면 그대로 승인, 아니면 반장이 한 번 본다.
            'status' => $verified ? 'approved' : 'pending',
            'payload' => [
                'verified_on_site' => $verified,
                'lat' => $signal['lat'] ?? null,
                'lng' => $signal['lng'] ?? null,
                'accuracy' => $signal['accuracy'] ?? null,
                'ip' => $signal['ip'] ?? null,
            ],
        ]);

        return [
            'success' => true,
            'verified' => $verified,
            'message' => $direction === 'in'
                ? ($verified ? '출근이 기록되었습니다.' : '출근을 접수했습니다. 현장 확인이 안 되어 반장 승인을 기다립니다.')
                : ($verified ? '퇴근이 기록되었습니다.' : '퇴근을 접수했습니다. 현장 확인이 안 되어 반장 승인을 기다립니다.'),
        ];
    }

    /**
     * 지금 이 사람이 현장에 있다고 볼 수 있는가.
     *
     * 자동 판정과 같은 규칙을 쓴다 — 규칙이 두 벌이면 언젠가 어긋나고, 그때 "자동은 되는데
     * 직접은 안 된다" 같은 설명하기 어려운 상황이 생긴다.
     *
     * @param  array<string, mixed>  $signal
     */
    private function verifyOnSite(Site $site, array $signal): bool
    {
        $probe = $this->geo->probe($site, $signal);

        return $probe['network'] || $probe['gps'] === 'in';
    }
}

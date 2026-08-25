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

        $snap = $this->todaySnapshot($employee, $tz);
        $session = $snap['session'];

        $logs = $snap['logs']
            ->map(fn (AttendanceLog $l): array => [
                'id' => $l->id,
                'type' => $l->event_type,
                'typeLabel' => $l->event_type === 'clock_in' ? '출근' : '퇴근',
                'at' => $l->event_at?->timezone($tz)->format('H:i'),
                'source' => $l->source,
                'sourceLabel' => self::SOURCE_LABELS[$l->source] ?? $l->source,
                'status' => $l->status,
                'needsReview' => $l->status === 'pending',
                'correctionRequested' => is_array($l->payload) && isset($l->payload['correction_request']),
            ])->values()->all();

        $clockedIn = $snap['firstIn'] !== null && $snap['lastOut'] === null;
        $elapsed = $snap['seconds'];

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
            // 정정 요청 칸에 미리 채워 줄 평소 출근 시각 — 대부분 확인만 누르면 되게.
            // 타이핑을 시키면 그 기능은 안 쓰인다.
            'usualTime' => $snap['firstIn']
                ? app(ClockInReminderService::class)->usualClockIn($employee, $tz, Carbon::now($tz))?->format('H:i')
                : null,
            'week' => $this->week($employee, $tz),
            'pay' => $this->pay($employee),
        ];
    }

    /**
     * 오늘 하루의 실시간 스냅샷 — 세션(자동)과 출퇴근 기록(직접)을 한 숫자로.
     *
     * 상태 카드와 근무 탭이 <b>같은 계산</b>을 봐야 한다. 전에는 카드만 이 계산을 쓰고
     * 근무 탭은 급여 타임시트(하루가 끝나야 확정)를 봤다 — 카드에는 2시간 36분이
     * 흐르는데 근무 탭은 비어 있어, 연동이 끊긴 것으로 보고됐다. 계산이 한 곳에 있으면
     * 두 탭이 다른 말을 할 수 없다.
     *
     * @return array{session: ?AttendanceSession, logs: \Illuminate\Support\Collection<int, AttendanceLog>, firstIn: ?Carbon, lastOut: ?Carbon, seconds: int}
     */
    private function todaySnapshot(Employee $employee, string $tz): array
    {
        $today = Carbon::now($tz)->toDateString();

        $session = $employee->site_id
            ? AttendanceSession::query()
                ->where('employee_id', $employee->id)
                ->where('work_date', $today)
                ->first()
            : null;

        $logs = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $today)
            ->orderBy('event_at')
            ->get();

        $firstIn = $logs->firstWhere('event_type', 'clock_in')?->event_at;
        $lastOut = $logs->where('event_type', 'clock_out')->last()?->event_at;

        $seconds = 0;
        if ($session && $session->status === 'on_site' && $session->last_enter_at) {
            $seconds = (int) $session->on_site_seconds + $session->last_enter_at->diffInSeconds(Carbon::now());
        } elseif ($session) {
            $seconds = (int) $session->on_site_seconds;
        } elseif ($firstIn) {
            // 직접 찍은 경우 — 퇴근 전이면 지금까지, 퇴근했으면 출근→퇴근.
            $seconds = Carbon::parse($firstIn)->diffInSeconds($lastOut ? Carbon::parse($lastOut) : Carbon::now());
        }

        return [
            'session' => $session,
            'logs' => $logs,
            'firstIn' => $firstIn ? Carbon::parse($firstIn) : null,
            'lastOut' => $lastOut ? Carbon::parse($lastOut) : null,
            'seconds' => max(0, (int) $seconds),
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
                'live' => false,
                // 아직 안 닫힌 날은 숫자가 바뀔 수 있다. 확정처럼 보이면 안 된다.
                'settled' => in_array($t->status, ['approved', 'locked', 'paid'], true),
            ];
        })->values();

        // 오늘 줄 — 타임시트는 하루가 끝나야 채워지는데(자동 근무는 저녁 마감 때,
        // 시급 정산도 퇴근을 찍어야 시간이 계산된다), 홈 카드는 실시간으로 초를 세고
        // 있다. 같은 앱의 두 탭이 다른 말을 하면 사람은 연동이 끊겼다고 생각한다 —
        // 실제로 그렇게 보고됐다. 그래서 오늘만은 카드와 같은 스냅샷으로 만들어 넣고
        // '진행 중' 표시로 확정 숫자와 구별한다.
        $today = Carbon::now($tz)->toDateString();
        $settled = $days->first(fn (array $d): bool => $d['date'] === $today
            && ($d['regularHours'] + $d['overtimeHours']) > 0);

        if (! $settled) {
            $snap = $this->todaySnapshot($employee, $tz);

            if ($snap['seconds'] > 0 || $snap['firstIn']) {
                $now = Carbon::now($tz);
                $days = $days->reject(fn (array $d): bool => $d['date'] === $today)
                    ->push([
                        'date' => $today,
                        'label' => $now->format('m/d'),
                        'weekday' => self::WEEKDAYS[(int) $now->dayOfWeek],
                        'in' => ($snap['session']?->first_enter_at ?? $snap['firstIn'])?->timezone($tz)->format('H:i'),
                        'out' => $snap['lastOut']?->timezone($tz)->format('H:i'),
                        // 연장 구분은 마감이 한다 — 진행 중에는 전부 한 줄의 시간일 뿐이다.
                        'regularHours' => round($snap['seconds'] / 3600, 1),
                        'overtimeHours' => 0.0,
                        'status' => 'live',
                        'live' => true,
                        'settled' => false,
                    ])
                    ->sortBy('date')->values();
            }
        }

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            // 합계는 화면의 줄들과 같은 숫자에서 나와야 한다 — 줄 따로 합계 따로면
            // 더해 본 사람이 "안 맞는다" 고 한다.
            'regularHours' => round($days->sum('regularHours'), 1),
            'overtimeHours' => round($days->sum('overtimeHours'), 1),
            'days' => $days->all(),
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
    public function punch(Employee $employee, string $direction, array $signal = [], string $lang = 'ko'): array
    {
        $t = self::PUNCH_MESSAGES[$lang] ?? self::PUNCH_MESSAGES['ko'];

        if (! in_array($direction, ['in', 'out'], true)) {
            return ['success' => false, 'error' => $t['pick']];
        }

        // 재직 중이 아니면 찍을 수 없다. 게이트와 QR 은 이미 막는데 이 길만 열려
        // 있었다 — 퇴사 처리 뒤에도 계정이 살아 있으면(정지 누락) 여기로 계속 찍혀
        // 타임시트·급여까지 흘러갔다.
        if ($employee->employment_status !== 'active') {
            return ['success' => false, 'error' => $t['not_active']];
        }

        $site = $employee->site_id ? Site::find($employee->site_id) : null;
        if (! $site) {
            return ['success' => false, 'error' => $t['no_site']];
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
            return ['success' => false, 'error' => $t['dup_in']];
        }
        if ($direction === 'out') {
            if ($existing->where('event_type', 'clock_in')->isEmpty()) {
                return ['success' => false, 'error' => $t['no_in']];
            }
            if ($existing->where('event_type', 'clock_out')->isNotEmpty()) {
                return ['success' => false, 'error' => $t['dup_out']];
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
                ? ($verified ? $t['in_ok'] : $t['in_pending'])
                : ($verified ? $t['out_ok'] : $t['out_pending']),
        ];
    }

    /**
     * 출근 시각 정정 요청 — "실제로는 더 일찍 왔다".
     *
     * 웹 앱은 주머니 속에서 위치를 못 보내므로, 5시에 도착해도 11시에 앱을 열면
     * 그때가 출근으로 찍힌다. 그 사람이 실제 도착 시각을 말하면 기록을 <b>바로
     * 고치지 않고</b> 확인 대기(pending)로 돌린다 — 임금 기록을 본인 신고만으로
     * 고치면 나중에 아무도 그 시간을 설명하지 못한다. 반장이 보고 승인한다.
     * 요청 내용은 기록의 메모와 payload 에 남아 관리 화면에서 그대로 보인다.
     *
     * @return array<string, mixed>
     */
    public function requestCorrection(Employee $employee, string $time, string $lang = 'ko'): array
    {
        $t = self::CORRECTION_MESSAGES[$lang] ?? self::CORRECTION_MESSAGES['ko'];

        if (! preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time)) {
            return ['success' => false, 'error' => $t['bad_time']];
        }

        $site = $employee->site_id ? Site::find($employee->site_id) : null;
        $tz = $site?->timezone ?: config('app.timezone');
        $today = Carbon::now($tz)->toDateString();

        $log = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $today)
            ->where('event_type', 'clock_in')
            ->where('status', '!=', 'rejected')
            ->orderBy('event_at')
            ->first();

        if (! $log) {
            return ['success' => false, 'error' => $t['no_log']];
        }
        if (is_array($log->payload) && isset($log->payload['correction_request'])) {
            return ['success' => false, 'error' => $t['already']];
        }

        $recordedLocal = $log->event_at?->timezone($tz)->format('H:i');
        if ($recordedLocal !== null && $time >= $recordedLocal) {
            // 더 늦게 왔다는 정정은 받지 않는다 — 그건 반장이 기록을 줄이는 방향이라
            // 본인 요청으로 시작할 일이 아니다.
            return ['success' => false, 'error' => $t['not_earlier']];
        }

        $payload = is_array($log->payload) ? $log->payload : [];
        $payload['correction_request'] = [
            'requested_time' => $time,
            'recorded_time' => $recordedLocal,
            'requested_at' => Carbon::now()->toIso8601String(),
        ];

        $log->update([
            'status' => 'pending',   // 반장 확인 대기 — 화면에는 "확인 필요" 로 뜬다.
            'notes' => trim(($log->notes ? $log->notes."\n" : '')
                ."[작업자 정정 요청] 실제 도착 {$time} (기록 {$recordedLocal})"),
            'payload' => $payload,
        ]);

        return ['success' => true, 'message' => $t['ok']];
    }

    private const CORRECTION_MESSAGES = [
        'ko' => [
            'bad_time' => '시각을 05:00 처럼 입력해 주세요.',
            'no_log' => '오늘 출근 기록이 없습니다. 먼저 출근을 찍어 주세요.',
            'already' => '오늘 정정 요청이 이미 접수돼 있습니다.',
            'not_earlier' => '기록된 시각보다 이른 시각만 요청할 수 있습니다.',
            'ok' => '정정 요청을 접수했습니다. 반장 확인 후 반영됩니다.',
        ],
        'en' => [
            'bad_time' => 'Enter the time like 05:00.',
            'no_log' => 'No clock-in today yet. Clock in first.',
            'already' => 'A correction request is already in for today.',
            'not_earlier' => 'Only an earlier time than the recorded one can be requested.',
            'ok' => 'Request received. It applies after foreman review.',
        ],
        'es' => [
            'bad_time' => 'Escriba la hora como 05:00.',
            'no_log' => 'Aún no hay entrada hoy. Marque la entrada primero.',
            'already' => 'Ya hay una solicitud de corrección para hoy.',
            'not_earlier' => 'Solo puede pedir una hora anterior a la registrada.',
            'ok' => 'Solicitud recibida. Se aplica tras la revisión del capataz.',
        ],
    ];

    /**
     * 출퇴근 응답 문구 — 작업자의 언어로.
     *
     * 화면 글자는 화면(JS 사전)이 바꾸지만, 이 문구는 서버가 만든다. 화면이 서버의
     * 한국어 문장을 받아 다시 번역하게 두면 문구가 바뀔 때마다 두 곳이 어긋난다 —
     * 만든 쪽이 언어까지 책임진다.
     */
    private const PUNCH_MESSAGES = [
        'ko' => [
            'pick' => '출근/퇴근 중 하나를 선택해 주세요.',
            'no_site' => '배정된 현장이 없습니다. 관리자에게 현장 배정을 요청해 주세요.',
            'not_active' => '재직 상태가 아니라 출퇴근을 기록할 수 없습니다. 관리자에게 문의해 주세요.',
            'dup_in' => '오늘은 이미 출근이 기록되었습니다.',
            'no_in' => '출근 기록이 없습니다. 먼저 출근을 눌러 주세요.',
            'dup_out' => '오늘은 이미 퇴근이 기록되었습니다.',
            'in_ok' => '출근이 기록되었습니다.',
            'in_pending' => '출근을 접수했습니다. 현장 확인이 안 되어 반장 승인을 기다립니다.',
            'out_ok' => '퇴근이 기록되었습니다.',
            'out_pending' => '퇴근을 접수했습니다. 현장 확인이 안 되어 반장 승인을 기다립니다.',
        ],
        'en' => [
            'pick' => 'Please choose clock-in or clock-out.',
            'no_site' => 'No site assigned. Ask your manager to assign you to a site.',
            'not_active' => 'Your employment is not active — clock-in is disabled. Contact your manager.',
            'dup_in' => 'You already clocked in today.',
            'no_in' => 'No clock-in yet. Please clock in first.',
            'dup_out' => 'You already clocked out today.',
            'in_ok' => 'Clock-in recorded.',
            'in_pending' => 'Clock-in received. Site could not be verified — waiting for foreman approval.',
            'out_ok' => 'Clock-out recorded.',
            'out_pending' => 'Clock-out received. Site could not be verified — waiting for foreman approval.',
        ],
        'es' => [
            'pick' => 'Elija entrada o salida.',
            'no_site' => 'No tiene sitio asignado. Pida a su supervisor que le asigne uno.',
            'not_active' => 'Su empleo no está activo — no puede marcar. Contacte a su supervisor.',
            'dup_in' => 'Ya registró su entrada hoy.',
            'no_in' => 'No hay entrada registrada. Marque la entrada primero.',
            'dup_out' => 'Ya registró su salida hoy.',
            'in_ok' => 'Entrada registrada.',
            'in_pending' => 'Entrada recibida. No se pudo verificar el sitio — pendiente de aprobación del capataz.',
            'out_ok' => 'Salida registrada.',
            'out_pending' => 'Salida recibida. No se pudo verificar el sitio — pendiente de aprobación del capataz.',
        ],
    ];

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

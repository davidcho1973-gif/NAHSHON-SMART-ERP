<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Support\Org;

/**
 * 게이트 QR 출퇴근 — 현장 출입구에 붙인 QR 을 스캔하면(로그인 불필요) 출근/퇴근을 찍는다.
 * 휴대폰 앱을 켜두지 않아도(백그라운드 GPS 없이) 동작한다.
 *
 * "나가며 QR 1초 스캔 = 퇴근" — 웹 GPS 의 백그라운드 한계를 우회하는 현실적 대안.
 *
 * 계정이 없는 사람(오늘 처음 온 협력사 인원, 구글 계정이 없는 사람)이 들어오는 문이라
 * 로그인을 요구하지 않는다. 그래서 나머지 두 가지를 대신 확인한다:
 *
 *   누구인가 — 전화번호 뒷 4자리(identify). 예전의 이름 고르기는 확인이 아니었다.
 *   어디인가 — 작업자 앱과 같은 판정(AttendanceGeoService::verdict).
 */
class GateAttendanceService
{
    /** 같은 사람의 연속 스캔을 무시하는 창(분) — 중복 태그 방지. */
    public const DUPLICATE_WINDOW_MINUTES = 5;

    public function __construct(private readonly AttendanceGeoService $geo) {}

    /**
     * 전화번호 뒷 4자리로 본인을 찾는다 — 게이트의 기본 확인 방법.
     *
     * 예전에는 이름을 쳐서 목록에서 골랐다. 그건 확인이 아니라 <b>고르기</b>였다.
     * 남의 이름도 똑같이 고를 수 있었고(대리 출근), 현장 명단이 통째로 노출됐고,
     * 철자를 모르면 본인을 못 찾았다(Rodríguez / Rodriguez). 매일 같은 사람이 같은
     * 이름을 다시 치는 일도 그대로 남았다.
     *
     * 번호 뒷자리는 그 셋을 한 번에 없앤다: 남이 모르고, 숫자라 철자 문제가 없고,
     * 찾는 사람만 나오므로 명단이 열리지 않는다. 등록 폼이 전화번호를 반드시 받으므로
     * 이미 갖고 있는 값이기도 하다.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function identify(Site $site, string $last4): Collection
    {
        $last4 = preg_replace('/\D/', '', $last4) ?: '';
        if (strlen($last4) !== 4) {
            return collect();
        }

        // 표기(하이픈·괄호·국가번호)가 제각각이라 숫자만 남겨 뒤에서 비교한다.
        $digits = "regexp_replace(coalesce(phone, ''), '\\D', '', 'g')";

        $rows = Employee::query()
            ->where('site_id', $site->id)
            ->where('employment_status', 'active')
            ->whereRaw("length({$digits}) >= 4")
            ->whereRaw("right({$digits}, 4) = ?", [$last4])
            ->with('company:id,name')
            ->orderBy('name')
            ->limit(10)
            ->get();

        return $this->present($rows, $site);
    }

    /**
     * 현장의 활성 작업자를 이름/소속으로 검색한다.
     *
     * 번호가 등록되지 않은 사람을 위한 예비 통로다(관리자가 만든 오래된 기록 등).
     * 기본 통로가 아니므로 짧은 글자로 명단을 훑지 못하게 <b>두 글자 이상</b>을 요구한다.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function search(Site $site, string $q, int $limit = 12): Collection
    {
        $q = trim($q);

        $query = Employee::query()
            ->where('site_id', $site->id)
            ->where('employment_status', 'active');

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $like)
                ->orWhere('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhere('badge_company_name', 'ilike', $like));
        }

        return $this->present($query->with('company:id,name')->orderBy('name')->limit($limit)->get(), $site);
    }

    /**
     * 찾은 사람들을 화면이 쓰는 모양으로. 두 통로(번호·이름)가 같은 모양을 내려주므로
     * 화면은 어느 쪽으로 들어왔는지 신경 쓰지 않는다.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, array<string, mixed>>
     */
    private function present(Collection $employees, Site $site): Collection
    {
        $tz = $site->timezone ?: config('app.timezone');
        $workDate = Carbon::now($tz)->toDateString();

        return $employees->map(function (Employee $e) use ($workDate, $tz): array {
            $last = $this->lastTodayLog($e->id, $workDate);

            return [
                'id' => $e->id,
                'name' => $e->name ?: trim($e->first_name.' '.$e->last_name),
                'company' => $e->company?->name ?: ($e->badge_company_name ?: ''),
                'role' => $e->role ?: '',
                'lastEvent' => $last?->event_type,
                'lastAt' => $last?->event_at?->timezone($tz)->format('H:i'),
                'next' => ($last && $last->event_type === 'clock_in') ? 'clock_out' : 'clock_in',
            ];
        })->values();
    }

    /**
     * 오늘 상태 + 다음에 찍을 이벤트(출근/퇴근) 안내.
     *
     * @return array<string, mixed>
     */
    public function status(Employee $employee, Site $site): array
    {
        $tz = $site->timezone ?: config('app.timezone');
        $workDate = Carbon::now($tz)->toDateString();
        $last = $this->lastTodayLog($employee->id, $workDate);
        $next = ($last && $last->event_type === 'clock_in') ? 'clock_out' : 'clock_in';

        return [
            'success' => true,
            'name' => $employee->name ?: trim($employee->first_name.' '.$employee->last_name),
            'lastEvent' => $last?->event_type,
            'lastAt' => $last?->event_at?->timezone($tz)->format('H:i'),
            'next' => $next,
        ];
    }

    /**
     * 출근/퇴근을 자동 판별해 기록한다(마지막이 출근이면 퇴근, 아니면 출근).
     *
     * @return array<string, mixed>
     */
    public function punch(Employee $employee, Site $site, array $signal = []): array
    {
        if ($employee->employment_status !== 'active') {
            return ['success' => false, 'error' => '활성 작업자가 아닙니다. 관리자에게 문의하세요.'];
        }
        if ((int) $employee->site_id !== (int) $site->id) {
            return ['success' => false, 'error' => '이 현장에 배정된 작업자가 아닙니다.'];
        }

        $tz = $site->timezone ?: config('app.timezone');
        $now = Carbon::now($tz);
        $workDate = $now->toDateString();

        // 중복 스캔(5분 내) → 무시.
        $recent = AttendanceLog::query()->where('employee_id', $employee->id)
            ->where('event_at', '>=', $now->copy()->subMinutes(Org::int('attendance.duplicate_window_minutes', self::DUPLICATE_WINDOW_MINUTES)))
            ->where('status', '!=', 'rejected')->latest('event_at')->first();
        if ($recent) {
            return [
                'success' => true, 'ignored' => true,
                'name' => $employee->name, 'event' => $recent->event_type,
                'at' => $recent->event_at->timezone($tz)->format('H:i'),
                'message' => '방금 처리되어 중복 스캔을 무시했습니다.',
            ];
        }

        $last = $this->lastTodayLog($employee->id, $workDate);
        $event = ($last && $last->event_type === 'clock_in') ? 'clock_out' : 'clock_in';

        // 어디에 있었는가 — 작업자 앱과 <b>같은 판정</b>을 쓴다(AttendanceGeoService).
        // 예전에는 게이트가 자체 거리 계산을 갖고 있었고, 결과를 기록에 적어 두기만 하고
        // 아무 데서도 읽지 않았다. 규칙이 두 벌이면 같은 자리에서 입구에 따라 다른 답이 나온다.
        $verdict = $this->geo->verdict($site, $signal);
        $offSite = $verdict === AttendanceGeoService::OFF_SITE;

        // 처분: 현장 밖인 것이 확인되면 보류한다 — 급여의 근거가 될 기록이라 사람이 한 번 본다.
        //
        // 확인이 <b>안 되는</b> 경우(현장에 좌표·WiFi 미등록, 위치 거부)는 보류하지 않는다.
        // 게이트는 출입구에 붙은 종이이고, 확인 수단이 없는 현장에서 전원을 보류로 돌리면
        // 그 목록은 매일 전원이 쌓여 아무도 안 보게 된다 — 그러면 진짜 이상한 기록도 묻힌다.
        // 현장 좌표를 넣는 순간부터 그 현장은 걸러지기 시작한다.
        AttendanceLog::create([
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'site_id' => $site->id,
            'attendance_date' => $workDate,
            'event_type' => $event,
            'event_at' => $now,
            'source' => 'gate_qr',
            'status' => $offSite ? 'pending' : 'approved',
            'payload' => [
                'gate' => true,
                'lat' => $signal['lat'] ?? null,
                'lng' => $signal['lng'] ?? null,
                'accuracy' => $signal['accuracy'] ?? null,
                'ip' => $signal['ip'] ?? null,
                // 작업자 앱과 같은 칸 이름을 쓴다 — 화면이 입구별로 다른 칸을 읽지 않게.
                'verified_on_site' => match ($verdict) {
                    AttendanceGeoService::ON_SITE => true,
                    AttendanceGeoService::OFF_SITE => false,
                    default => null,
                },
                'geo_verdict' => $verdict,
                'identified_by' => $signal['identified_by'] ?? null,
            ],
        ]);

        return [
            'success' => true,
            'name' => $employee->name ?: trim($employee->first_name.' '.$employee->last_name),
            'event' => $event,
            'at' => $now->format('H:i'),
            'date' => $workDate,
            'verdict' => $verdict,
            'pending' => $offSite,
            'withinSite' => $verdict === AttendanceGeoService::ON_SITE ? true : ($offSite ? false : null),
        ];
    }

    private function lastTodayLog(int $employeeId, string $workDate): ?AttendanceLog
    {
        return AttendanceLog::query()->where('employee_id', $employeeId)
            ->where('attendance_date', $workDate)->where('status', '!=', 'rejected')
            ->orderByDesc('event_at')->orderByDesc('id')->first();
    }

    // 예전에 여기 있던 자체 거리 계산(withinSite)은 지웠다 — 같은 질문에 답하는 코드가
    // 두 벌이었고(작업자 앱은 AttendanceGeoService), 여유 반경도 서로 달랐다(여기 80m).
    // 판정은 AttendanceGeoService::verdict() 한 곳에서만 한다.
}

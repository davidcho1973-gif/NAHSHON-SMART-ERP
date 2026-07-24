<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 게이트 QR 출퇴근 — 현장 출입구에 붙인 QR 을 스캔하면(로그인 불필요), 이름으로 본인을 찾아
 * 출근/퇴근을 찍는다. 휴대폰 앱을 켜두지 않아도(백그라운드 GPS 없이) 동작한다.
 *
 * "나가며 QR 1초 스캔 = 퇴근" — 웹 GPS 의 백그라운드 한계를 우회하는 현실적 대안.
 */
class GateAttendanceService
{
    /** 같은 사람의 연속 스캔을 무시하는 창(분) — 중복 태그 방지. */
    public const DUPLICATE_WINDOW_MINUTES = 5;

    /**
     * 현장의 활성 작업자를 이름/소속으로 검색한다(게이트 페이지 자동완성).
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
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $like)
                ->orWhere('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhere('badge_company_name', 'ilike', $like));
        }

        $tz = $site->timezone ?: config('app.timezone');
        $workDate = Carbon::now($tz)->toDateString();

        return $query->orderBy('name')->limit($limit)->get()
            ->map(function (Employee $e) use ($workDate, $tz): array {
                $last = $this->lastTodayLog($e->id, $workDate);

                return [
                    'id' => $e->id,
                    'name' => $e->name ?: trim($e->first_name . ' ' . $e->last_name),
                    'company' => $e->badge_company_name ?: '',
                    'role' => $e->role ?: '',
                    'lastEvent' => $last?->event_type,
                    'lastAt' => $last?->event_at?->timezone($tz)->format('H:i'),
                    'next' => ($last && $last->event_type === 'clock_in') ? 'clock_out' : 'clock_in',
                ];
            });
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
            'name' => $employee->name ?: trim($employee->first_name . ' ' . $employee->last_name),
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
    public function punch(Employee $employee, Site $site, ?float $lat = null, ?float $lng = null): array
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
            ->where('event_at', '>=', $now->copy()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
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
        $within = $this->withinSite($site, $lat, $lng);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'site_id' => $site->id,
            'attendance_date' => $workDate,
            'event_type' => $event,
            'event_at' => $now,
            'source' => 'gate_qr',
            'status' => 'approved',
            'payload' => ['gate' => true, 'lat' => $lat, 'lng' => $lng, 'within_site' => $within],
        ]);

        return [
            'success' => true,
            'name' => $employee->name ?: trim($employee->first_name . ' ' . $employee->last_name),
            'event' => $event,
            'at' => $now->format('H:i'),
            'withinSite' => $within,
        ];
    }

    private function lastTodayLog(int $employeeId, string $workDate): ?AttendanceLog
    {
        return AttendanceLog::query()->where('employee_id', $employeeId)
            ->where('attendance_date', $workDate)->where('status', '!=', 'rejected')
            ->orderByDesc('event_at')->orderByDesc('id')->first();
    }

    /** 게이트에서 위치를 허용한 경우 현장 반경 안인지 확인(참고용 소프트 체크). null=위치 없음. */
    private function withinSite(Site $site, ?float $lat, ?float $lng): ?bool
    {
        if ($lat === null || $lng === null || $site->latitude === null || $site->longitude === null || ! $site->radius_meters) {
            return null;
        }
        $r = 6371000.0;
        $dLat = deg2rad((float) $site->latitude - $lat);
        $dLng = deg2rad((float) $site->longitude - $lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad((float) $site->latitude)) * sin($dLng / 2) ** 2;
        $dist = $r * 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $dist <= (float) $site->radius_meters + 80; // 게이트 경계 여유 80m
    }
}

<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\AttendanceReminder;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\PushSubscription;
use App\Models\Site;
use App\Models\User;
use App\Services\Push\WebPushSender;
use App\Support\Org;
use Illuminate\Support\Carbon;

/**
 * 아침 출근 알림 — 사람마다 <b>자기 시간</b>에 "도착하셨으면 눌러 주세요".
 *
 * 왜 필요한가 — 웹 앱은 주머니 속에서 위치를 못 보낸다(OS 제한). 5시에 현장에
 * 도착해도 11시에 앱을 열어야 그때 출근이 찍힌다(실제로 그랬다). 푸시는 앱이
 * 닫혀 있어도 닿으므로, 알림을 누르는 순간 앱이 열리며 그 자리에서 찍힌다.
 *
 * <b>알림 시각은 입력받지 않는다 — 기록이 가르친다.</b> 출근 시간이 사람마다
 * 다른데 관리자에게 수십 명의 시간을 입력·유지시키면 결국 안 맞는 데이터가 된다.
 * 대신 그 사람의 최근 2주 출근 기록의 <b>중간값</b>을 쓴다(평균이 아니다 —
 * 어쩌다 11시에 찍힌 하루가 전체를 끌고 가면 안 된다). 요일도 기록에서 배운다:
 * 토요일에 나온 적 없는 사람에게 토요일 아침 알림을 울리지 않는다.
 *
 * 소음 상한: 하루 2번. 첫 알림 뒤 일정 시간이 지나도 기록이 없으면 한 번만 더
 * 묻고 조용히 물러난다 — 결근·휴가일 수 있고, 세 번째부터는 도움이 아니라
 * 잔소리다. 잔소리가 된 알림은 꺼지고, 꺼진 알림은 영영 다시 못 켠다.
 */
class ClockInReminderService
{
    /** 기록에서 배우는 기간. */
    private const HISTORY_DAYS = 14;

    /** 중간값을 믿으려면 최소 며칠의 기록이 필요한가. */
    private const MIN_HISTORY = 3;

    /** 재알림까지 기다리는 시간(분). */
    private const NUDGE_AFTER_MINUTES = 40;

    /** 하루 최대 알림 수. */
    private const MAX_PER_DAY = 2;

    /** 아침이 아닐 때는 아예 보지 않는다 — 밤에 울리는 출근 알림은 사고다. */
    private const WINDOW_FROM_HOUR = 4;

    private const WINDOW_TO_HOUR = 12;

    public function __construct(private readonly WebPushSender $push) {}

    /**
     * 지금 이 순간 보내야 할 알림을 골라 보낸다. 스케줄러가 10분마다 부른다.
     *
     * @return array{checked: int, sent: int}
     */
    public function run(?Carbon $now = null): array
    {
        if (! $this->push->available()) {
            return ['checked' => 0, 'sent' => 0];   // 열쇠 없는 배포 — 조용히 아무것도 안 한다.
        }

        $now ??= Carbon::now();
        $checked = 0;
        $sent = 0;

        foreach ($this->candidates() as $employee) {
            $checked++;

            $payload = $this->due($employee, $now);
            if ($payload === null) {
                continue;
            }

            $delivered = $this->push->sendToUsers([$payload['user']], $payload['message']);
            if ($delivered > 0) {
                $this->markSent($employee, $payload['workDate'], $now);
                $sent++;
            }
        }

        return ['checked' => $checked, 'sent' => $sent];
    }

    /**
     * 알림 대상자 — 앱을 설치하고 알림을 켠(구독이 있는) 현장 배정자만.
     *
     * 구독이 없는 사람은 보낼 방법 자체가 없으므로 계산도 하지 않는다.
     *
     * @return \Illuminate\Support\Collection<int, Employee>
     */
    private function candidates()
    {
        $userIds = PushSubscription::query()->distinct()->pluck('user_id');

        $employeeIds = User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('employee_id')
            ->pluck('employee_id');

        return Employee::query()
            ->whereIn('id', $employeeIds)
            ->where('employment_status', 'active')
            ->whereNotNull('site_id')
            ->with('site')
            ->get()
            // 원청(client) 소속은 출퇴근을 찍지 않는다.
            ->filter(fn (Employee $e): bool => $e->attendancePolicy() !== Employee::POLICY_NONE)
            ->values();
    }

    /**
     * 이 사람에게 지금 알림을 보내야 하는가 — 보내야 하면 보낼 내용을 돌려준다.
     *
     * @return array{user: User, workDate: string, message: array<string, string>}|null
     */
    public function due(Employee $employee, Carbon $now): ?array
    {
        $site = $employee->site instanceof Site ? $employee->site : null;
        $tz = $site?->timezone ?: config('app.timezone');
        $local = $now->copy()->timezone($tz);

        if ($local->hour < self::WINDOW_FROM_HOUR || $local->hour >= self::WINDOW_TO_HOUR) {
            return null;
        }

        $today = $local->toDateString();

        // 이미 출근했으면(직접이든 자동이든) 조용히.
        $clockedIn = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $today)
            ->where('event_type', 'clock_in')
            ->where('status', '!=', 'rejected')
            ->exists()
            || AttendanceSession::query()
                ->where('employee_id', $employee->id)
                ->where('work_date', $today)
                ->exists();

        if ($clockedIn) {
            return null;
        }

        $usual = $this->usualClockIn($employee, $tz, $local);
        if ($usual === null) {
            return null;   // 이 요일에 나온 적 없는 사람 — 울리지 않는다.
        }

        if ($local->lessThan($usual)) {
            return null;   // 아직 그 사람의 출근 시간 전이다.
        }

        $ledger = AttendanceReminder::query()->firstOrCreate(
            ['employee_id' => $employee->id, 'work_date' => $today, 'kind' => AttendanceReminder::KIND_CLOCK_IN],
        );

        if ($ledger->sent_count >= self::MAX_PER_DAY) {
            return null;
        }
        if ($ledger->sent_count > 0
            && $ledger->last_sent_at
            && $ledger->last_sent_at->diffInMinutes($now) < self::NUDGE_AFTER_MINUTES) {
            return null;   // 첫 알림 후 잠시 기다린다 — 연달아 두 번 울리면 잔소리다.
        }

        $user = User::query()->where('employee_id', $employee->id)->first();
        if (! $user) {
            return null;
        }

        return [
            'user' => $user,
            'workDate' => $today,
            'message' => $this->message($employee, $site),
        ];
    }

    /**
     * 이 사람의 평소 출근 시각 — 최근 2주 기록의 중간값. 오늘 요일에 나온 적이
     * 없으면 null(알림 없음). 기록이 부족한 신입은 현장 기본 시간으로 시작한다.
     */
    public function usualClockIn(Employee $employee, string $tz, Carbon $localNow): ?Carbon
    {
        $since = $localNow->copy()->subDays(self::HISTORY_DAYS)->toDateString();

        $firstIns = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('event_type', 'clock_in')
            ->where('status', '!=', 'rejected')
            ->where('attendance_date', '>=', $since)
            ->where('attendance_date', '<', $localNow->toDateString())
            ->orderBy('event_at')
            ->get(['attendance_date', 'event_at'])
            ->groupBy(fn (AttendanceLog $l): string => Carbon::parse($l->attendance_date)->toDateString())
            ->map(fn ($logs) => $logs->first()->event_at->timezone($tz));

        if ($firstIns->count() < self::MIN_HISTORY) {
            // 신입·복귀자 — 아직 배울 기록이 없다. 현장 기본 시간으로 시작하되
            // 일요일은 부르지 않는다(기본값으로 주말 새벽에 울리는 것이 최악이다).
            if ($localNow->dayOfWeek === Carbon::SUNDAY) {
                return null;
            }

            [$h, $m] = array_map('intval', explode(':', Org::time('attendance.reminder_default_at', '06:00')));

            return $localNow->copy()->setTime($h, $m, 0);
        }

        // 이 요일에 나온 적이 있는가 — 없으면 오늘은 쉬는 요일로 본다.
        $workedThisWeekday = $firstIns->keys()
            ->map(fn (string $d): int => Carbon::parse($d)->dayOfWeek)
            ->contains($localNow->dayOfWeek);

        if (! $workedThisWeekday) {
            return null;
        }

        // 중간값 — 하루의 예외(늦잠·반차)가 전체를 끌고 가지 않는다.
        $minutes = $firstIns->values()
            ->map(fn (Carbon $t): int => $t->hour * 60 + $t->minute)
            ->sort()->values();
        $median = $minutes[(int) floor(($minutes->count() - 1) / 2)];

        return $localNow->copy()->setTime(intdiv($median, 60), $median % 60, 0);
    }

    /** @return array<string, string> */
    private function message(Employee $employee, ?Site $site): array
    {
        $lang = in_array($employee->preferred_language, ['ko', 'en', 'es'], true)
            ? $employee->preferred_language : 'ko';

        $texts = [
            'ko' => ['title' => '🔔 출근 확인', 'body' => '도착하셨으면 눌러 주세요 — 열면 바로 기록됩니다.'],
            'en' => ['title' => '🔔 Clock-in check', 'body' => 'Arrived? Tap to open — it records right away.'],
            'es' => ['title' => '🔔 Registro de entrada', 'body' => '¿Llegó? Toque para abrir — se registra al instante.'],
        ][$lang];

        return [
            'title' => $texts['title'],
            'body' => ($site ? $site->code.' · ' : '').$texts['body'],
            'url' => route('attendance-app.index', absolute: false),
            'tag' => 'clockin-reminder',
        ];
    }

    private function markSent(Employee $employee, string $workDate, Carbon $now): void
    {
        AttendanceReminder::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', $workDate)
            ->where('kind', AttendanceReminder::KIND_CLOCK_IN)
            ->update([
                'sent_count' => \Illuminate\Support\Facades\DB::raw('sent_count + 1'),
                'last_sent_at' => $now,
            ]);
    }
}

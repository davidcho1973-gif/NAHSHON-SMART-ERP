<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\AttendanceReminder;
use App\Models\Employee;
use App\Models\PushSubscription;
use App\Models\Site;
use App\Models\User;
use App\Services\Push\WebPushSender;
use App\Support\Org;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 퇴근 알림 — "아직 퇴근이 안 찍혔습니다".
 *
 * 현장에서 제일 잦은 사고다: 출근은 찍고 퇴근은 그냥 간다. 그런데 시급 직영은
 * 자동 마감을 하지 않는다(임금 왜곡을 막으려고 일부러 그렇게 두었다) — 그래서
 * 미마감으로 남고, 급여 마감날 관리자가 기억에 의존해 손으로 채운다. 그것이
 * 급여 분쟁의 씨앗이다. 그날 저녁 한 번의 알림이 그 분쟁을 없앤다.
 *
 * 출근 알림과 같은 뼈대를 쓴다 — 시각을 입력받지 않고 <b>기록에서 배운다</b>.
 * 그 사람의 최근 2주 퇴근 시각 중간값에 유예를 더한 때가 지나도 기록이 없으면
 * 묻는다. 사람마다 퇴근 시간이 다르고(잔업·조퇴), 관리자에게 수십 명의 시간을
 * 입력·유지시키면 결국 안 맞는 데이터가 된다.
 *
 * 지키는 것:
 *  · 출근 기록이 있는 사람에게만. 오늘 안 나온 사람에게 퇴근을 묻지 않는다.
 *  · 저녁 시간대에만. 새벽에 울리는 퇴근 알림은 그 자체가 사고다.
 *  · 하루 2번까지. 세 번째부터는 도움이 아니라 잔소리고, 잔소리가 된 알림은
 *    꺼지고, 꺼진 알림은 영영 다시 못 켠다.
 */
class ClockOutReminderService
{
    /** 기록에서 배우는 기간. */
    private const HISTORY_DAYS = 14;

    /** 중간값을 믿으려면 최소 며칠의 기록이 필요한가. */
    private const MIN_HISTORY = 3;

    /**
     * 평소 퇴근 시각에서 이만큼 지나야 묻는다.
     *
     * 정시에 울리면 아직 정리 중인 사람에게 잔소리가 된다. 30분은 "오늘은 늦네"
     * 와 "잊고 갔네" 를 가르는 선이다.
     */
    private const GRACE_MINUTES = 30;

    /** 재알림까지 기다리는 시간(분). */
    private const NUDGE_AFTER_MINUTES = 45;

    /** 하루 최대 알림 수. */
    private const MAX_PER_DAY = 2;

    /** 오후·저녁에만 본다 — 이 밖에서는 아무것도 하지 않는다. */
    private const WINDOW_FROM_HOUR = 12;

    private const WINDOW_TO_HOUR = 23;

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

        // 오늘 마지막 기록이 출근인가 — 그래야 "아직 안 찍고 있는" 사람이다.
        // 오늘 아예 안 나온 사람에게 퇴근을 묻는 것은 그 자체로 틀린 질문이다.
        if (! $this->stillClockedIn($employee->id, $today)) {
            return null;
        }

        $usual = $this->usualClockOut($employee, $tz, $local);
        if ($usual === null) {
            return null;   // 배울 기록도 기본값도 없다 — 짐작으로 울리지 않는다.
        }

        if ($local->lessThan($usual->copy()->addMinutes(self::GRACE_MINUTES))) {
            return null;   // 아직 그 사람의 퇴근 시간 + 유예 전이다.
        }

        $ledger = AttendanceReminder::query()->firstOrCreate(
            ['employee_id' => $employee->id, 'work_date' => $today, 'kind' => AttendanceReminder::KIND_CLOCK_OUT],
        );

        if ($ledger->sent_count >= self::MAX_PER_DAY) {
            return null;
        }
        if ($ledger->sent_count > 0
            && $ledger->last_sent_at
            && $ledger->last_sent_at->diffInMinutes($now) < self::NUDGE_AFTER_MINUTES) {
            return null;
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

    /** 오늘 마지막 출퇴근 기록이 '출근' 인가(= 퇴근을 안 찍었는가). */
    public function stillClockedIn(int $employeeId, string $workDate): bool
    {
        $last = AttendanceLog::query()
            ->where('employee_id', $employeeId)
            ->where('attendance_date', $workDate)
            ->where('status', '!=', 'rejected')
            ->whereIn('event_type', ['clock_in', 'clock_out'])
            ->orderByDesc('event_at')->orderByDesc('id')
            ->value('event_type');

        return $last === 'clock_in';
    }

    /**
     * 이 사람의 평소 퇴근 시각 — 최근 2주 기록의 중간값.
     *
     * 중간값을 쓰는 이유는 출근 알림과 같다: 어쩌다 자정까지 남은 하루가 전체를
     * 끌고 가면, 그날부터 아무에게도 제때 묻지 못한다.
     */
    public function usualClockOut(Employee $employee, string $tz, Carbon $localNow): ?Carbon
    {
        $since = $localNow->copy()->subDays(self::HISTORY_DAYS)->toDateString();

        $lastOuts = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('event_type', 'clock_out')
            ->where('status', '!=', 'rejected')
            // 자동 마감은 그 사람의 습관이 아니라 시스템이 찍은 시각이다.
            // 그것으로 배우면 16:00 이 모두의 퇴근 시각이 되어 버린다.
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'auto_clockout'))
            ->where('attendance_date', '>=', $since)
            ->where('attendance_date', '<', $localNow->toDateString())
            ->orderBy('event_at')
            ->get(['attendance_date', 'event_at'])
            ->groupBy(fn (AttendanceLog $l): string => Carbon::parse($l->attendance_date)->toDateString())
            ->map(fn ($logs) => $logs->last()->event_at->timezone($tz));

        if ($lastOuts->count() < self::MIN_HISTORY) {
            // 아직 배울 기록이 없다 — 현장 기본 퇴근 시각으로 시작한다.
            $default = Org::time('attendance.clockout_reminder_default_at', '17:00');
            [$h, $m] = array_map('intval', explode(':', $default));

            return $localNow->copy()->setTime($h, $m, 0);
        }

        $minutes = $lastOuts->values()
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

        // 시급 직영에게는 이유까지 말한다 — 왜 눌러야 하는지 알아야 누른다.
        $hourly = $employee->attendancePolicy() === Employee::POLICY_HOURLY;

        $texts = [
            'ko' => [
                'title' => '🔔 퇴근이 아직 안 찍혔습니다',
                'body' => $hourly
                    ? '눌러서 퇴근을 기록해 주세요 — 근무시간이 그대로 급여가 됩니다.'
                    : '오늘 일 마치셨으면 눌러 주세요 — 열면 바로 기록됩니다.',
            ],
            'en' => [
                'title' => '🔔 You have not clocked out',
                'body' => $hourly
                    ? 'Tap to clock out — your hours become your pay.'
                    : 'Done for the day? Tap to open — it records right away.',
            ],
            'es' => [
                'title' => '🔔 No ha registrado su salida',
                'body' => $hourly
                    ? 'Toque para registrar la salida — sus horas son su pago.'
                    : '¿Terminó por hoy? Toque para abrir — se registra al instante.',
            ],
        ][$lang];

        return [
            'title' => $texts['title'],
            'body' => ($site ? $site->code.' · ' : '').$texts['body'],
            'url' => route('attendance-app.index', absolute: false),
            'tag' => 'clockout-reminder',
        ];
    }

    private function markSent(Employee $employee, string $workDate, Carbon $now): void
    {
        AttendanceReminder::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', $workDate)
            ->where('kind', AttendanceReminder::KIND_CLOCK_OUT)
            ->update([
                'sent_count' => DB::raw('sent_count + 1'),
                'last_sent_at' => $now,
            ]);
    }
}

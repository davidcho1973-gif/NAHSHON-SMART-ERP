<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Site;
use App\Support\WorkRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 게이트로 찍힌 옛 기록의 시각을 바로잡는다 — 사바나에서 3시간 밀린 그것.
 *
 * ── 무엇이 잘못됐나 ────────────────────────────────────────────────────
 * 게이트가 <b>현장 시간대</b>의 벽시계를 저장했다. 날짜 칸은 앱 시간대의 벽시계로
 * 읽히므로, 두 시계의 차이만큼 기록이 밀렸다(사바나 ↔ 피닉스 = 3시간).
 * 코드는 고쳤지만 <b>이미 저장된 줄</b>은 그대로 남아 있다. 그건 근무시간이고,
 * 근무시간은 임금이다.
 *
 * 같은 원인으로 <b>자동 퇴근 마감</b>(source = auto_clockout)도 밀렸다. 근무시간은
 * 출근과 퇴근 두 끝으로 재므로, 한쪽만 고치면 임금은 여전히 틀린다.
 *
 * ── 어떻게 고르나 ──────────────────────────────────────────────────────
 * 고칠 대상은 세 가지를 모두 만족하는 줄뿐이다:
 *   ① 이 사고가 닿은 경로(gate_qr · auto_clockout) — 나머지는 처음부터 옳았다
 *   ② 현장 시간대 ≠ 앱 시간대                       — 같으면 어긋날 수가 없다
 *   ③ <b>그 줄 자신이 어긋났다는 증거를 갖고 있을 것</b> — 아래
 *
 * ③ 이 이 명령의 핵심이다. 게이트는 찍히는 그 순간에 줄을 만든다. 그래서 «언제 적혔나»
 * (created_at, 앱 시계로 옳게 적힌다)와 «언제였다고 적혔나»(event_at)는 같은 순간이어야
 * 한다. 어긋난 줄은 정확히 두 시계의 차이만큼 어긋나 있다 — 그게 이 사고의 모양이다.
 * 반대로 고친 코드가 찍은 줄은 둘이 같으므로 그냥 지나간다.
 *
 * 자동 마감은 찍히는 순간에 만들어지지 않으므로(밤에 한꺼번에 돈다) 증거가 다르다:
 * 그 줄의 시각은 «현장 시계의 마감 시각» 이어야 한다. 앱 시계 쪽이 마감 시각이고
 * 현장 시계 쪽이 아니면, 그 줄은 현장 벽시계 숫자가 그대로 들어간 줄이다.
 *
 * 이것은 «며칠 이전» 같은 날짜 자르기보다 안전하다. 사람이 배포 시각을 잘못 적으면
 * 멀쩡한 기록이 3시간 망가지는데, 그 실수는 조용하다. 줄 스스로가 증거를 갖고 있으면
 * 사람이 시각을 기억할 필요가 없다. 같은 이유로 두 번 돌려도 안전하다 — 고친 줄은
 * 더 이상 어긋나 있지 않다(표시도 남기지만, 그건 두 번째 자물쇠다).
 *
 * 기본은 <b>미리보기</b>다. 실제로 바꾸려면 --apply 를 준다.
 */
class FixGateAttendanceClock extends Command
{
    protected $signature = 'attendance:fix-gate-clock
        {--apply : 실제로 고친다(주지 않으면 무엇이 바뀔지 보여주기만 한다)}
        {--before= : 이 시각 이전에 만들어진 줄만 본다(선택 — 범위를 더 좁히고 싶을 때)}
        {--site= : 현장 코드 하나만}';

    protected $description = '게이트·자동마감 출퇴근 기록의 시간대 오차를 바로잡는다 (기본: 미리보기)';

    /** payload 에 남기는 표시 — 두 번 고치지 않기 위한 두 번째 자물쇠. */
    private const MARK = 'gate_clock_fixed_at';

    /**
     * 「적힌 시각 = 적은 시각」 으로 볼 수 있는 여유(초).
     *
     * 게이트는 한 요청 안에서 둘을 같이 쓰므로 실제 차이는 1초 안쪽이다. 여유를 크게
     * 잡아도 오판이 생기지 않는다 — 어긋난 줄은 몇 시간씩 벌어져 있기 때문이다.
     */
    private const SAME_MOMENT_SECONDS = 300;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $appTz = (string) config('app.timezone');

        $before = $this->option('before') ? Carbon::parse((string) $this->option('before')) : null;

        $sites = Site::query()
            ->when($this->option('site'), fn ($q) => $q->where('code', $this->option('site')))
            ->get()
            ->filter(fn (Site $s): bool => ($s->timezone ?: $appTz) !== $appTz);

        if ($sites->isEmpty()) {
            $this->info("앱 시간대({$appTz})와 다른 현장이 없습니다 — 고칠 것이 없습니다.");

            return self::SUCCESS;
        }

        $totalFixed = 0;

        foreach ($sites as $site) {
            $tz = (string) $site->timezone;

            $logs = AttendanceLog::query()
                ->where('site_id', $site->id)
                ->whereIn('source', ['gate_qr', 'auto_clockout'])
                ->when($before, fn ($q) => $q->where('created_at', '<=', $before))
                ->orderBy('event_at')
                ->get()
                ->reject(fn (AttendanceLog $l): bool => filled(($l->payload ?? [])[self::MARK] ?? null))
                ->filter(fn (AttendanceLog $l): bool => $this->wasWrittenOnTheSiteClock($l, $tz, $appTz));

            if ($logs->isEmpty()) {
                $this->line("  {$site->code} ({$tz}) — 고칠 줄 없음");

                continue;
            }

            $this->newLine();
            $this->info("■ {$site->code} · {$site->name} ({$tz}) — {$logs->count()}줄");

            foreach ($logs as $log) {
                // 저장된 문자열은 «현장 시계의 벽시계» 였다. 그 숫자를 현장 시계로 다시
                // 읽어 진짜 순간을 얻고, 앱 시간대로 옮겨 저장한다.
                $corrected = $this->corrected($log, $tz, $appTz);

                $wasShown = $log->event_at->timezone($tz)->format('m/d H:i');
                $nowShown = $corrected->copy()->timezone($tz)->format('m/d H:i');

                if ($wasShown === $nowShown) {
                    continue; // 바뀌는 것이 없으면 건드리지 않는다.
                }

                $this->line(sprintf('   %-8s %s  %s → %s',
                    $log->event_type === 'clock_in' ? '출근' : '퇴근',
                    str_pad((string) $log->employee_id, 5, ' ', STR_PAD_LEFT),
                    $wasShown, $nowShown));

                if ($apply) {
                    DB::transaction(function () use ($log, $corrected, $tz, $wasShown): void {
                        $log->forceFill([
                            'event_at' => $corrected,
                            'attendance_date' => $corrected->copy()->timezone($tz)->toDateString(),
                            'payload' => array_merge($log->payload ?? [], [
                                self::MARK => Carbon::now()->toIso8601String(),
                                'gate_clock_was' => $wasShown,
                            ]),
                        ])->saveQuietly(); // 조용히 — 이건 새 사건이 아니라 같은 사건의 정정이다.
                    });
                }

                $totalFixed++;
            }
        }

        $this->newLine();
        $this->info($apply
            ? "{$totalFixed}줄을 바로잡았습니다."
            : "{$totalFixed}줄이 바뀝니다. 실제로 고치려면 --apply 를 붙여 다시 실행하세요.");

        return self::SUCCESS;
    }

    /**
     * 이 줄은 «현장 시계의 벽시계» 로 적힌 줄인가 — 줄 스스로가 가진 증거로 판정한다.
     *
     * 게이트는 찍히는 그 순간에 줄을 만든다. created_at 은 앱 시계로 옳게 적히므로,
     * event_at 을 바로잡은 값이 created_at 과 같은 순간이면 그 줄은 어긋나 있었던 것이다.
     * 이미 옳은 줄은 바로잡은 값이 created_at 보다 시간대 차이만큼 <b>앞서므로</b> 걸리지 않는다.
     */
    private function wasWrittenOnTheSiteClock(AttendanceLog $log, string $tz, string $appTz): bool
    {
        if ($log->event_at === null) {
            return false;
        }

        if ($log->source === 'auto_clockout') {
            return $this->autoCloseUsedTheSiteWallClock($log, $tz);
        }

        // 언제 적힌 줄인지 모르면 판단할 근거가 없다 — 건드리지 않는다.
        if ($log->created_at === null) {
            return false;
        }

        $corrected = $this->corrected($log, $tz, $appTz);

        return abs($log->created_at->diffInSeconds($corrected, false)) <= self::SAME_MOMENT_SECONDS;
    }

    /**
     * 자동 마감 줄의 증거 — 마감 시각이 어느 시계에 찍혀 있는가.
     *
     * 자동 마감은 밤에 한꺼번에 돌므로 «적은 시각» 이 증거가 되지 못한다. 대신 그 줄의
     * 시각은 반드시 <b>현장 시계의 마감 시각</b>(기본 저녁 6시)이어야 한다. 앱 시계 쪽이
     * 마감 시각이면 현장 벽시계 숫자가 그대로 들어간 줄이다 — 고치면 앱 시계 쪽이
     * 달라지므로 두 번 걸리지 않는다.
     */
    private function autoCloseUsedTheSiteWallClock(AttendanceLog $log, string $tz): bool
    {
        // 마감 시각은 현장마다 다르다 — 회사 기본값으로 판정하면 다른 시각에
        // 마감하는 현장의 밀린 줄을 못 찾거나, 엉뚱한 줄을 고르게 된다.
        $cutoffHour = (int) explode(':', WorkRules::forSite($log->site_id)->end)[0];

        return (int) $log->event_at->format('G') === $cutoffHour
            && (int) $log->event_at->copy()->timezone($tz)->format('G') !== $cutoffHour;
    }

    /** 저장된 벽시계 숫자를 현장 시계로 다시 읽어 얻은 진짜 순간(앱 시간대로). */
    private function corrected(AttendanceLog $log, string $tz, string $appTz): Carbon
    {
        return Carbon::parse($log->event_at->format('Y-m-d H:i:s'), $tz)->setTimezone($appTz);
    }
}

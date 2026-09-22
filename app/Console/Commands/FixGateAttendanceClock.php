<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Site;
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
 * ── 어떻게 고르나 ──────────────────────────────────────────────────────
 * 고칠 대상은 세 가지를 모두 만족하는 줄뿐이다:
 *   ① 게이트가 만든 줄(source = gate_qr)     — 다른 경로는 처음부터 옳았다
 *   ② 현장 시간대 ≠ 앱 시간대                 — 같으면 어긋날 수가 없다
 *   ③ 코드를 고치기 전에 만들어진 줄          — 그 뒤 줄은 이미 옳다
 *
 * ── 두 번 고치지 않기 ──────────────────────────────────────────────────
 * 고친 줄에는 payload 에 표시를 남긴다. 명령을 두 번 돌려도 표시된 줄은 건너뛴다.
 * 시각을 고치는 명령이 두 번 돌면 오차가 두 배가 된다 — 처음보다 나빠진다.
 *
 * 기본은 <b>미리보기</b>다. 실제로 바꾸려면 --apply 를 준다.
 */
class FixGateAttendanceClock extends Command
{
    protected $signature = 'attendance:fix-gate-clock
        {--apply : 실제로 고친다(주지 않으면 무엇이 바뀔지 보여주기만 한다)}
        {--before= : 이 시각 이전에 만들어진 줄만. --apply 에는 반드시 준다(고친 코드가 배포된 시각)}
        {--site= : 현장 코드 하나만}';

    protected $description = '게이트 출퇴근 기록의 시간대 오차를 바로잡는다 (기본: 미리보기)';

    /** payload 에 남기는 표시 — 두 번 고치지 않기 위한 것. */
    private const MARK = 'gate_clock_fixed_at';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $appTz = (string) config('app.timezone');

        // --apply 에는 «언제까지의 줄인가» 를 반드시 받는다.
        //
        // 고친 코드가 배포된 뒤에 찍힌 줄은 <b>이미 옳다</b>. 그것까지 옮기면 멀쩡한
        // 기록을 3시간 망가뜨린다 — 고치려다 반대로 만드는 것이다. 기본값을 «지금»
        // 으로 두면 그 사고가 조용히 일어나므로, 사람이 직접 적게 한다.
        if ($apply && ! $this->option('before')) {
            $this->error('--apply 에는 --before 가 필요합니다.');
            $this->line('  고친 코드가 배포된 시각을 주세요. 그 뒤에 찍힌 기록은 이미 옳습니다.');
            $this->line('  예: php artisan attendance:fix-gate-clock --apply --before="2026-09-22 06:00"');

            return self::FAILURE;
        }

        $before = $this->option('before') ? Carbon::parse((string) $this->option('before')) : Carbon::now();

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
                ->where('source', 'gate_qr')
                ->where('created_at', '<=', $before)
                ->orderBy('event_at')
                ->get()
                ->reject(fn (AttendanceLog $l): bool => filled(($l->payload ?? [])[self::MARK] ?? null));

            if ($logs->isEmpty()) {
                $this->line("  {$site->code} ({$tz}) — 고칠 줄 없음");

                continue;
            }

            $this->newLine();
            $this->info("■ {$site->code} · {$site->name} ({$tz}) — {$logs->count()}줄");

            foreach ($logs as $log) {
                // 저장된 문자열은 «현장 시계의 벽시계» 였다. 그 숫자를 현장 시계로 다시
                // 읽어 진짜 순간을 얻고, 앱 시간대로 옮겨 저장한다.
                $wall = $log->event_at->format('Y-m-d H:i:s');
                $trueMoment = Carbon::parse($wall, $tz);
                $corrected = $trueMoment->copy()->setTimezone($appTz);

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
}

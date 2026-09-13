<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 큐가 실제로 일하고 있는가 — 배포할 때마다 숫자로 본다.
 *
 * ── 왜 필요한가 ────────────────────────────────────────────────────────
 * 문서를 올리면 AI 분석은 뒤에서 도는 일꾼(queue worker)이 처리한다. 그 일꾼은
 * 코드가 아니라 <b>배포 환경의 프로세스</b>라서, 안 만들면 아무 일도 안 일어난다.
 * 그런데 화면은 멀쩡하다 — 문서는 올라가고 「읽는 중」이라고 적힌 채 영원히 멈춘다.
 *
 * 2026-09-06 나손에서 실제로 그랬다. 일꾼이 한 번도 떠 있지 않아 85건이 쌓여 있었는데,
 * 화면·스케줄러·저장소가 전부 초록이라 아무도 몰랐다. 스케줄러·캐시·도메인·업로드
 * 한도는 이미 배포 때 숫자로 확인하는데 큐만 빠져 있었다.
 *
 * ── 무엇으로 판정하나 ──────────────────────────────────────────────────
 * <b>가장 오래 기다린 작업의 나이</b>다. 일꾼이 돌고 있으면 작업은 몇 초 안에 집혀
 * 나가므로 이 숫자가 크게 자랄 수 없다. 며칠씩 기다리는 작업이 있다면 일꾼이 없다.
 *
 * 큐가 비어 있을 때는 «일꾼이 있다» 와 «일꾼은 없는데 마침 할 일도 없다» 를 구별하지
 * 못한다. 그래도 괜찮다 — 구별이 필요해지는 순간(일이 들어오는 순간) 바로 드러난다.
 * 없는 것을 안다고 말하지 않는 편이 낫다.
 */
final class QueueHealth
{
    /** 이 분을 넘겨 기다리는 작업이 있으면 일꾼이 없다고 본다. */
    public const STALE_MINUTES = 15;

    /** 화면에 이름을 적어 줄 큐 — 문서 분석이 여기로 들어온다. */
    public const DOCUMENT_QUEUE = 'documents';

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        try {
            if (! Schema::hasTable('jobs')) {
                return self::unknown('jobs 표가 없습니다 — 큐가 데이터베이스를 쓰지 않는 설정입니다.');
            }

            $now = time();
            $pending = (int) DB::table('jobs')->whereNull('reserved_at')->count();
            $reserved = (int) DB::table('jobs')->whereNotNull('reserved_at')->count();
            $oldestAt = DB::table('jobs')->whereNull('reserved_at')->min('created_at');

            $oldestMinutes = $oldestAt === null ? null : (int) floor(($now - (int) $oldestAt) / 60);
            $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;

            // 큐 이름별로 나눠 둔다 — 「documents 만 쌓였다」와 「전부 쌓였다」는
            // 고칠 자리가 다르다(문서 일꾼만 없는 것 / 큐 전체가 안 도는 것).
            $byQueue = DB::table('jobs')
                ->selectRaw('queue, count(*) as n')
                ->whereNull('reserved_at')
                ->groupBy('queue')
                ->pluck('n', 'queue')
                ->map(fn ($n): int => (int) $n)
                ->all();

            $stuck = $oldestMinutes !== null && $oldestMinutes >= self::STALE_MINUTES;

            return [
                'known' => true,
                'working' => ! $stuck,
                'pending' => $pending,
                'reserved' => $reserved,
                'failed' => $failed,
                'oldest_pending_minutes' => $oldestMinutes,
                'by_queue' => $byQueue,
                'stale_after_minutes' => self::STALE_MINUTES,
                'message' => match (true) {
                    $stuck => sprintf(
                        '큐에 %d건이 밀려 있고 가장 오래된 것이 %d분째 기다립니다 — 큐 일꾼(queue:work)이 돌고 있지 않습니다. 문서 AI 분석이 전부 멈춥니다.',
                        $pending,
                        $oldestMinutes,
                    ),
                    $pending > 0 => sprintf('큐에 %d건이 있고 가장 오래된 것이 %d분째입니다 — 처리 중입니다.', $pending, $oldestMinutes ?? 0),
                    $reserved > 0 => sprintf('지금 %d건을 처리하고 있습니다.', $reserved),
                    // 비어 있으면 «일꾼이 있다» 고 말하지 않는다 — 알 수 없기 때문이다.
                    default => '큐가 비어 있습니다. 밀린 작업이 없습니다(일꾼이 떠 있는지는 이것만으로 알 수 없습니다).',
                },
            ];
        } catch (Throwable $e) {
            return self::unknown('큐 상태를 읽지 못했습니다: '.$e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function unknown(string $message): array
    {
        return [
            'known' => false,
            'working' => null,
            'pending' => null,
            'reserved' => null,
            'failed' => null,
            'oldest_pending_minutes' => null,
            'by_queue' => [],
            'stale_after_minutes' => self::STALE_MINUTES,
            'message' => $message,
        ];
    }
}

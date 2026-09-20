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
 * 그런데 화면은 멀쩡하다 — 문서는 올라가고 「접수됨」이라고 적힌 채 영원히 멈춘다.
 *
 * 2026-09-06 나손에서 실제로 그랬다. 일꾼이 한 번도 떠 있지 않아 85건이 쌓여 있었는데,
 * 화면·스케줄러·저장소가 전부 초록이라 아무도 몰랐다.
 *
 * ── 어느 줄을 보고 판정하나 ────────────────────────────────────────────
 * 문서 일꾼은 documents, 회의 일꾼은 meetings 큐를 각각 듣는다.
 * 공용 default 큐는 일부러 안 듣는다 — 오늘은 문서 작업만 들어 있어도 앞으로 전혀
 * 다른 업무 작업이 그 줄에 들어오기 때문이다.
 *
 * 그래서 대기 지연은 <b>처리하도록 설정된 큐</b>만 보고 판정한다. 처음에는 줄을 가리지
 * 않고 전부 세었는데, 그러면 2026-08-27 에 default 로 들어간 옛 작업 85건 때문에
 * 일꾼이 정상으로 돌아도 영원히 «일꾼 없음» 이라고 말한다. 경고가 거짓말을 하면
 * 사람은 곧 그 경고를 통째로 무시하고, 그때는 진짜 고장도 같이 묻힌다.
 *
 * 아무도 듣지 않는 줄에 쌓인 일은 <b>다른 문제</b>라서 따로 센다. 고칠 방법이 다르다 —
 * 일꾼을 만드는 게 아니라 옛 작업을 정리하는 일이다.
 *
 * ── 무엇으로 판정하나 ──────────────────────────────────────────────────
 * <b>가장 오래 기다린 작업의 나이</b>다. 일꾼이 돌고 있으면 작업은 몇 초 안에 집혀
 * 나간다. 오래 기다리면 지연으로 알리되, 장시간 분석이나 처리량 부족도 원인일 수
 * 있으므로 대기 시간만으로 일꾼이 죽었다고 단정하지 않는다.
 *
 * 큐가 비어 있을 때는 «일꾼이 있다» 와 «일꾼은 없는데 마침 할 일도 없다» 를 구별하지
 * 못한다. 그래도 괜찮다 — 구별이 필요해지는 순간(일이 들어오는 순간) 바로 드러난다.
 * 없는 것을 안다고 말하지 않는 편이 낫다.
 */
final class QueueHealth
{
    /** 이 분을 넘겨 기다리는 작업이 있으면 일꾼이 없다고 본다. */
    public const STALE_MINUTES = 15;

    /** 일꾼이 실제로 듣는 큐. 문서 분석이 여기로 들어온다. */
    public const DOCUMENT_QUEUE = 'documents';

    /**
     * 일꾼이 듣기로 되어 있는 큐 전부. 여기 없는 줄에 쌓인 일은 «밀린 일» 이 아니라
     * «아무도 안 듣는 일» 이다 — 경고 문구도, 고치는 방법도 다르다.
     *
     * @var list<string>
     */
    public const SERVED_QUEUES = [self::DOCUMENT_QUEUE, 'meetings'];

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

            // 큐 이름별로 건수와 «가장 오래된 것의 나이» 를 함께 모은다. 건수만으로는
            // 「documents 가 막혔다」와 「아무도 안 듣는 줄에 옛날 것이 남았다」를 구별할 수 없다.
            $rows = DB::table('jobs')
                ->selectRaw('queue, count(*) as n, min(created_at) as oldest')
                ->whereNull('reserved_at')
                ->groupBy('queue')
                ->get();

            $byQueue = [];
            $ageByQueue = [];
            foreach ($rows as $row) {
                $byQueue[$row->queue] = (int) $row->n;
                $ageByQueue[$row->queue] = (int) floor(($now - (int) $row->oldest) / 60);
            }

            $served = self::bucket($byQueue, $ageByQueue, fn (string $q): bool => in_array($q, self::SERVED_QUEUES, true));
            $unserved = self::bucket($byQueue, $ageByQueue, fn (string $q): bool => ! in_array($q, self::SERVED_QUEUES, true));

            // 판정은 «일꾼이 듣는 줄» 만 본다. 아무도 안 듣는 줄이 얼마나 오래됐든
            // 그것은 일꾼이 죽었다는 증거가 아니다.
            $stuck = $served['oldest_pending_minutes'] !== null
                && $served['oldest_pending_minutes'] >= self::STALE_MINUTES;

            return [
                'known' => true,
                'working' => ! $stuck,
                'pending' => $pending,
                'reserved' => $reserved,
                'failed' => $failed,
                'oldest_pending_minutes' => $oldestMinutes,
                'by_queue' => $byQueue,
                'stale_after_minutes' => self::STALE_MINUTES,
                'served_queues' => self::SERVED_QUEUES,
                // 일꾼이 듣는 줄 — 여기 숫자가 «일꾼이 도는가» 를 말해 준다.
                'served' => $served,
                // 아무도 듣지 않는 줄 — 여기 숫자는 «치워야 할 옛 작업» 을 말해 준다.
                'unserved' => $unserved,
                'message' => self::describe($stuck, $served, $unserved, $reserved),
            ];
        } catch (Throwable $e) {
            return self::unknown('큐 상태를 읽지 못했습니다: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, int>  $byQueue
     * @param  array<string, int>  $ageByQueue
     * @param  callable(string): bool  $keep
     * @return array{queues: list<string>, pending: int, oldest_pending_minutes: int|null}
     */
    private static function bucket(array $byQueue, array $ageByQueue, callable $keep): array
    {
        $queues = [];
        $pending = 0;
        $oldest = null;

        foreach ($byQueue as $queue => $count) {
            if (! $keep((string) $queue)) {
                continue;
            }
            $queues[] = (string) $queue;
            $pending += $count;
            $age = $ageByQueue[$queue] ?? null;
            if ($age !== null && ($oldest === null || $age > $oldest)) {
                $oldest = $age;
            }
        }

        sort($queues);

        return ['queues' => $queues, 'pending' => $pending, 'oldest_pending_minutes' => $oldest];
    }

    /**
     * @param  array{queues: list<string>, pending: int, oldest_pending_minutes: int|null}  $served
     * @param  array{queues: list<string>, pending: int, oldest_pending_minutes: int|null}  $unserved
     */
    private static function describe(bool $stuck, array $served, array $unserved, int $reserved): string
    {
        // 아무도 안 듣는 줄 이야기는 항상 «덧붙이는 말» 이다. 그것만으로 일꾼을
        // 만들라고 말하면 안 된다 — 고칠 자리가 다르기 때문이다.
        $tail = '';
        if ($unserved['pending'] > 0) {
            $tail = sprintf(
                ' 그리고 아무도 듣지 않는 줄(%s)에 %d건이 남아 있습니다%s — 일꾼과는 별개로 정리할 옛 작업입니다.',
                implode(', ', $unserved['queues']),
                $unserved['pending'],
                $unserved['oldest_pending_minutes'] === null
                    ? ''
                    : sprintf(', 가장 오래된 것이 %d분째', $unserved['oldest_pending_minutes']),
            );
        }

        $head = match (true) {
            $stuck => sprintf(
                '%s 큐에 %d건이 밀려 있고 가장 오래된 것이 %d분째 기다립니다 — 처리 지연입니다. 큐 일꾼(queue:work), 진행 중인 장시간 분석과 처리량을 확인하세요.',
                implode(', ', $served['queues']),
                $served['pending'],
                $served['oldest_pending_minutes'] ?? 0,
            ),
            $served['pending'] > 0 => sprintf(
                '%s 큐에 %d건이 있고 가장 오래된 것이 %d분째입니다 — 처리 중입니다.',
                implode(', ', $served['queues']),
                $served['pending'],
                $served['oldest_pending_minutes'] ?? 0,
            ),
            $reserved > 0 => sprintf('지금 %d건을 처리하고 있습니다.', $reserved),
            // 비어 있으면 «일꾼이 있다» 고 말하지 않는다 — 알 수 없기 때문이다.
            default => sprintf(
                '%s 큐가 비어 있습니다. 밀린 작업이 없습니다(일꾼이 떠 있는지는 이것만으로 알 수 없습니다).',
                implode(', ', self::SERVED_QUEUES),
            ),
        };

        return $head.$tail;
    }

    /**
     * @return array<string, mixed>
     */
    private static function unknown(string $message): array
    {
        $empty = ['queues' => [], 'pending' => null, 'oldest_pending_minutes' => null];

        return [
            'known' => false,
            'working' => null,
            'pending' => null,
            'reserved' => null,
            'failed' => null,
            'oldest_pending_minutes' => null,
            'by_queue' => [],
            'stale_after_minutes' => self::STALE_MINUTES,
            'served_queues' => self::SERVED_QUEUES,
            'served' => $empty,
            'unserved' => $empty,
            'message' => $message,
        ];
    }
}

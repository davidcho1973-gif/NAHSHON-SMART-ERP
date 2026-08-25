<?php

namespace App\Console\Commands;

use App\Models\WbsItem;
use App\Services\Wbs\CpmEngine;
use Illuminate\Console\Command;
use Throwable;

/**
 * 전 프로젝트 CPM 재계산 — 매일 새벽 안전망 + 수동 점검용.
 *
 * 평소에는 편집·수입 순간마다 즉시 재계산되므로 이 명령이 바꿀 것이 없어야 정상이다.
 * 그래도 매일 한 번 돌리는 이유: 다른 경로(직접 DB 수정·과거 배포분)로 어긋난 값을
 * 하루 안에 스스로 바로잡기 위해서다.
 */
class RecomputeCpm extends Command
{
    protected $signature = 'wbs:recompute-cpm {project? : 프로젝트 코드 (생략하면 전체)}';

    protected $description = '공정표 여유·주공정·예상 준공을 CPM 엔진으로 재계산합니다';

    public function handle(CpmEngine $engine): int
    {
        $codes = $this->argument('project')
            ? [(string) $this->argument('project')]
            : WbsItem::query()->whereNotNull('project_code')->distinct()->pluck('project_code')->all();

        foreach ($codes as $code) {
            try {
                $r = $engine->recompute((string) $code);
                $this->line(sprintf(
                    '%s: %s',
                    $code,
                    ($r['skipped'] ?? false)
                        ? '생략 — '.($r['reason'] ?? '')
                        : '이동 '.($r['movedCount'] ?? 0).'건 · 주공정 '.($r['criticalCount'] ?? 0).'건 · 예상 준공 '.($r['projectedEnd'] ?? '-'),
                ));
                foreach (($r['warnings'] ?? []) as $w) {
                    $this->warn('  '.$w);
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("{$code}: 재계산 실패 — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}

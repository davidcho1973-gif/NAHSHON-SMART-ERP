<?php

namespace App\Console\Commands;

use App\Models\BoqItem;
use App\Models\Project;
use App\Models\Submittal;
use Illuminate\Console\Command;

/**
 * 대장에 들어갔지만 화면에 안 보이는 줄 찾기 — "넣었다는데 못 찾겠다" 의 원인.
 *
 * 물량·제출물 대장은 프로젝트를 골라 보는 구조라, 프로젝트가 비어 있는 줄은
 * 어느 화면에도 뜨지 않는다. 도면·시방 판독이 프로젝트 없는 문서에서 돌면
 * 그런 줄이 생겼다(지금은 애초에 넣지 않도록 막았지만, 그전에 들어간 것이 남는다).
 *
 * 기본은 세어 보기만 한다. --fix 를 주면 현장에 프로젝트가 하나뿐인 경우에만
 * 그 프로젝트로 옮긴다 — 여럿이면 어느 쪽인지 사람이 알아야 하므로 손대지 않는다.
 */
class FindOrphanTakeoff extends Command
{
    protected $signature = 'erp:find-orphan-takeoff {--fix : 현장 프로젝트가 하나뿐인 줄을 그 프로젝트로 옮긴다}';

    protected $description = '프로젝트가 없어 대장 화면에 안 보이는 물량·제출물 줄을 찾는다';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $moved = 0;

        foreach ([['물량', BoqItem::class], ['제출물', Submittal::class]] as [$label, $model]) {
            $rows = $model::query()->whereNull('project_id')->get();

            if ($rows->isEmpty()) {
                $this->info("{$label}: 안 보이는 줄 없음");

                continue;
            }

            $this->warn("{$label}: 프로젝트 없는 줄 {$rows->count()}개 — 화면에 뜨지 않습니다");

            foreach ($rows->groupBy('site_id') as $siteId => $group) {
                $candidates = $siteId ? Project::query()->where('site_id', $siteId)->get() : collect();

                if ($candidates->count() === 1) {
                    $p = $candidates->first();
                    $this->line("  현장 {$siteId} · {$group->count()}줄 → [{$p->project_code}] {$p->name}"
                        .($fix ? ' (옮김)' : ' (--fix 로 옮길 수 있음)'));

                    if ($fix) {
                        $model::query()->whereIn('id', $group->pluck('id'))->update(['project_id' => $p->id]);
                        $moved += $group->count();
                    }
                } else {
                    $this->line("  현장 ".($siteId ?: '없음')." · {$group->count()}줄 → 프로젝트를 정할 수 없음"
                        ." (후보 {$candidates->count()}개). 화면에서 직접 지정하세요.");
                }
            }
        }

        if ($fix) {
            $this->info("옮긴 줄: {$moved}개");
        }

        return self::SUCCESS;
    }
}

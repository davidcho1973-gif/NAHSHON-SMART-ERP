<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\SafetyWorkIssue;
use App\Models\SafetyWorkItem;
use App\Models\SafetyWorkSignature;
use App\Models\WbsItem;
use App\Models\WbsManual;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WbsClear extends Command
{
    protected $signature = 'wbs:clear
        {project? : 초기화할 project_code (예: LGENERGY-MI-2026-001)}
        {--all : 모든 프로젝트의 공정 데이터를 초기화}
        {--with-manuals : 분석된 매뉴얼(wbs_manuals) 기록과 업로드 파일도 함께 삭제}
        {--force : 실제로 삭제(미지정 시 dry-run으로 대상만 표시)}';

    protected $description = '공정관리(WBS) 데이터를 프로젝트 단위로 초기화한다 — WBS 트리와 파생 안전카드(서명·이슈 포함)를 지워 처음부터 다시 시작할 수 있게 한다. 프로젝트·현장·직원·급여 데이터는 건드리지 않는다. 기본은 dry-run.';

    public function handle(): int
    {
        $code = $this->argument('project');
        $all = (bool) $this->option('all');
        $force = (bool) $this->option('force');
        $withManuals = (bool) $this->option('with-manuals');

        if (! $all && ! $code) {
            $this->error('project_code 를 지정하거나 --all 을 사용하세요. 예) php artisan wbs:clear LGENERGY-MI-2026-001');

            return self::FAILURE;
        }

        if ($all && $code) {
            $this->error('project_code 와 --all 은 함께 쓸 수 없습니다.');

            return self::FAILURE;
        }

        $codes = $all
            ? WbsItem::query()->distinct()->pluck('project_code')->filter()->values()
            : collect([$code]);

        if (! $all && ! WbsItem::query()->where('project_code', $code)->exists()
            && ! Project::query()->where('project_code', $code)->exists()) {
            $this->error("'{$code}' 에 해당하는 프로젝트/공정 데이터가 없습니다.");

            return self::FAILURE;
        }

        if ($codes->isEmpty()) {
            $this->info('초기화할 공정 데이터가 없습니다.');

            return self::SUCCESS;
        }

        // 삭제 대상 집계 — 안전카드는 wbs_code 로만 연결하므로 (자유텍스트 project 컬럼은
        // 안전관리 모듈 단독 카드와 겹칠 수 있어 쓰지 않는다) 트리 삭제 전에 코드를 확보한다.
        $wbsCodes = WbsItem::query()->whereIn('project_code', $codes)->pluck('wbs_code');
        $cardIds = SafetyWorkItem::query()->whereIn('wbs_code', $wbsCodes)->pluck('id');

        $counts = [
            'wbs_items' => WbsItem::query()->whereIn('project_code', $codes)->count(),
            'safety_work_items' => $cardIds->count(),
            'safety_work_signatures' => SafetyWorkSignature::query()->whereIn('safety_work_item_id', $cardIds)->count(),
            'safety_work_issues' => SafetyWorkIssue::query()->whereIn('safety_work_item_id', $cardIds)->count(),
            'wbs_manuals' => $withManuals ? WbsManual::query()->whereIn('project_code', $codes)->count() : 0,
        ];

        $this->table(
            ['Project', 'WBS 항목', '안전카드', 'TBM 서명', '이슈', '매뉴얼'.($withManuals ? '' : ' (유지)')],
            $codes->map(function (string $c) use ($withManuals): array {
                $codesOfProject = WbsItem::query()->where('project_code', $c)->pluck('wbs_code');
                $cardsOfProject = SafetyWorkItem::query()->whereIn('wbs_code', $codesOfProject)->pluck('id');

                return [
                    $c,
                    $codesOfProject->count(),
                    $cardsOfProject->count(),
                    SafetyWorkSignature::query()->whereIn('safety_work_item_id', $cardsOfProject)->count(),
                    SafetyWorkIssue::query()->whereIn('safety_work_item_id', $cardsOfProject)->count(),
                    $withManuals
                        ? WbsManual::query()->where('project_code', $c)->count()
                        : WbsManual::query()->where('project_code', $c)->count().' (유지)',
                ];
            })->all()
        );

        if ($counts['safety_work_signatures'] > 0) {
            $this->warn("주의: TBM 서명 {$counts['safety_work_signatures']}건이 함께 삭제됩니다. 서명은 법적 기록이므로 실제 진행된 프로젝트라면 삭제 전 보관/내보내기를 검토하세요.");
        }

        if (! $force) {
            $this->warn('DRY-RUN: 위 데이터가 삭제 대상입니다. 실제 삭제하려면 --force 를 붙이세요.');
            $this->line('예) php artisan wbs:clear '.($all ? '--all' : $code).' --force');

            return self::SUCCESS;
        }

        $deletedManualFiles = 0;

        DB::transaction(function () use ($codes, $cardIds, $withManuals, &$deletedManualFiles): void {
            // FK 순서: 자식(서명·이슈) → 안전카드 → WBS 트리 → (선택) 매뉴얼.
            // 카드 삭제 시 DB cascade 로도 지워지지만, 코드베이스 관례(2026_07_16_000100)대로 명시 삭제한다.
            SafetyWorkSignature::query()->whereIn('safety_work_item_id', $cardIds)->delete();
            SafetyWorkIssue::query()->whereIn('safety_work_item_id', $cardIds)->delete();
            SafetyWorkItem::query()->whereIn('id', $cardIds)->delete();

            WbsItem::query()->whereIn('project_code', $codes)->delete();

            if ($withManuals) {
                WbsManual::query()->whereIn('project_code', $codes)->get()
                    ->each(function (WbsManual $manual) use (&$deletedManualFiles): void {
                        if ($manual->path && Storage::disk($manual->disk ?: 'public')->delete($manual->path)) {
                            $deletedManualFiles++;
                        }
                        $manual->delete();
                    });
            }
        });

        $this->info(sprintf(
            '초기화 완료 — WBS %d, 안전카드 %d (서명 %d · 이슈 %d)%s. 프로젝트·현장·직원·급여 데이터는 그대로입니다.',
            $counts['wbs_items'],
            $counts['safety_work_items'],
            $counts['safety_work_signatures'],
            $counts['safety_work_issues'],
            $withManuals ? sprintf(', 매뉴얼 %d(파일 %d)', $counts['wbs_manuals'], $deletedManualFiles) : ''
        ));
        $this->line('이제 공정관리 화면의 "AI 메뉴얼 분석"으로 매뉴얼을 다시 업로드하거나, wbs:import-schedule 로 공정표를 다시 임포트해 새로 시작할 수 있습니다.');

        return self::SUCCESS;
    }
}

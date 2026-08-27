<?php

namespace App\Console\Commands;

use App\Models\BoqItem;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 703K 물량/BOQ 의 "미확정" 행에 치수 기반 개산 수량을 채운다 (1회성 보정).
 *
 * database/data/703k/qty_fill.json 의 {seq, qty, unit, unit_price, note} 를
 * qty_basis 가 '미확정' 인 행에만 적용한다 — 현장이 이미 손댄 행(다른 basis)은
 * 절대 건드리지 않는다. 임포트 커맨드의 "진행 기록 보존" 철학과 같은 결.
 * amount 는 모델 saving 훅이 qty × unit_price 로 자동 재계산.
 */
class Fill703kQuantities extends Command
{
    protected $signature = 'erp:fill-703k-quantities
        {--dry-run : 트랜잭션으로 실행 후 롤백}';

    protected $description = '703K BOQ 미확정 행에 개산 수량(5~10% 여유) 일괄 반영';

    public function handle(): int
    {
        $path = database_path('data/703k/qty_fill.json');
        if (! is_file($path)) {
            $this->error("파일이 없습니다: {$path}");

            return self::FAILURE;
        }

        $project = Project::query()->where('project_code', '703K-KITCHEN')->first();
        if (! $project) {
            $this->error('703K-KITCHEN 프로젝트가 없습니다. erp:import-703k 먼저.');

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->error('qty_fill.json 파싱 실패');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        DB::beginTransaction();

        $filled = 0;
        $skipped = 0;
        $missing = 0;
        foreach ($rows as $r) {
            $item = BoqItem::query()
                ->where('project_id', $project->id)
                ->where('seq', (int) ($r['seq'] ?? 0))
                ->first();

            if (! $item) {
                $missing++;

                continue;
            }
            // 현장이 이미 고친 행은 보존 — 미확정만 채운다.
            if ($item->qty_basis !== '미확정') {
                $skipped++;

                continue;
            }

            $item->fill([
                'qty' => (float) $r['qty'],
                'unit' => (string) $r['unit'],
                'unit_price' => (float) $r['unit_price'],
                'qty_basis' => '개산추정',
                'note' => (string) ($r['note'] ?? ''),
            ])->save();
            $filled++;
        }

        $total = (float) BoqItem::query()->where('project_id', $project->id)->sum('amount');

        if ($dry) {
            DB::rollBack();
            $this->warn('dry-run — 롤백했습니다.');
        } else {
            DB::commit();
        }

        $this->line("채움 {$filled} / 보존(이미 수정됨) {$skipped} / 대상없음 {$missing}");
        $this->line('직접비 합계: $'.number_format($total));

        return self::SUCCESS;
    }
}

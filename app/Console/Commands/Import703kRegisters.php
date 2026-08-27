<?php

namespace App\Console\Commands;

use App\Models\BoqItem;
use App\Models\Company;
use App\Models\Project;
use App\Models\Site;
use App\Models\Submittal;
use App\Support\Org;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 703K 제출물 대장(276행) + 물량/BOQ(437행) 적재.
 *
 * database/data/703k/submittals.json·boq.json 을 읽어 대장 행을 만든다.
 * (project_id, seq) 기준 updateOrCreate 라 재실행해도 안전하되, 현장이 고친
 * 진행 필드(상태·담당·날짜·메모 / 수량·단가·수량근거)는 재실행 시 보존한다 —
 * 임포트가 정본인 것은 "조항 내용"이지 "진행 기록"이 아니다.
 */
class Import703kRegisters extends Command
{
    protected $signature = 'erp:import-703k-registers
        {--dry-run : 트랜잭션으로 실행 후 롤백}';

    protected $description = '703K 제출물 대장 276행 + 물량/BOQ 437행 적재 — 멱등, 진행기록 보존';

    public function handle(): int
    {
        $dir = database_path('data/703k');
        foreach (['submittals.json', 'boq.json'] as $f) {
            if (! is_file("{$dir}/{$f}")) {
                $this->error("번들 파일이 없습니다: {$dir}/{$f}");

                return self::FAILURE;
            }
        }

        $own = Company::query()->where('code', Org::code())->first();
        $site = Site::query()->where('code', '703K')->first();
        $project = Project::query()->where('project_code', '703K-KITCHEN')->first();
        if (! $own || ! $site || ! $project) {
            $this->error('선행 데이터가 없습니다 — 먼저 erp:import-703k 를 실행하세요.');

            return self::FAILURE;
        }

        $submittals = json_decode(file_get_contents("{$dir}/submittals.json"), true);
        $boq = json_decode(file_get_contents("{$dir}/boq.json"), true);
        $dry = (bool) $this->option('dry-run');

        DB::beginTransaction();
        try {
            $sNew = 0;
            $sUpd = 0;
            foreach ($submittals as $row) {
                $existing = Submittal::query()
                    ->where('project_id', $project->id)->where('seq', $row['seq'])->first();

                if ($existing) {
                    // 조항 내용만 갱신, 진행 기록(status·담당·날짜·메모)은 보존.
                    $existing->fill([
                        'csi' => $row['csi'],
                        'section' => $row['section'],
                        'category' => $row['category'],
                        'title' => $row['title'],
                        'gate' => $row['gate'],
                    ])->save();
                    $sUpd++;

                    continue;
                }

                Submittal::query()->create([
                    'company_id' => $own->id,
                    'site_id' => $site->id,
                    'project_id' => $project->id,
                    'seq' => $row['seq'],
                    'csi' => $row['csi'],
                    'section' => $row['section'],
                    'category' => $row['category'],
                    'title' => $row['title'],
                    'gate' => $row['gate'],
                ]);
                $sNew++;
            }

            $bNew = 0;
            $bUpd = 0;
            foreach ($boq as $row) {
                $existing = BoqItem::query()
                    ->where('project_id', $project->id)->where('seq', $row['seq'])->first();

                if ($existing) {
                    // 품명·근거만 갱신, 현장이 고친 수량·단가·수량근거·메모는 보존.
                    $existing->fill([
                        'discipline_code' => $row['discipline_code'],
                        'discipline' => $row['discipline'],
                        'name_kr' => $row['name_kr'],
                        'name_en' => $row['name_en'],
                        'spec' => $row['spec'],
                        'unit' => $row['unit'],
                        'source' => $row['source'],
                        'flagged' => $row['flagged'],
                    ])->save();
                    $bUpd++;

                    continue;
                }

                BoqItem::query()->create([
                    'company_id' => $own->id,
                    'site_id' => $site->id,
                    'project_id' => $project->id,
                    'seq' => $row['seq'],
                    'discipline_code' => $row['discipline_code'],
                    'discipline' => $row['discipline'],
                    'name_kr' => $row['name_kr'],
                    'name_en' => $row['name_en'],
                    'spec' => $row['spec'],
                    'unit' => $row['unit'],
                    'qty' => $row['qty'],
                    'qty_basis' => $row['qty_basis'],
                    'unit_price' => $row['unit_price'],
                    'source' => $row['source'],
                    'note' => $row['note'],
                    'flagged' => $row['flagged'],
                ]);
                $bNew++;
            }

            $total = (float) BoqItem::query()->where('project_id', $project->id)->sum('amount');

            if ($dry) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            $this->info('703K 대장 적재'.($dry ? ' (dry-run — 롤백됨)' : '').' 결과');
            $this->line("  제출물 대장 : 신규 {$sNew} / 갱신 {$sUpd} (총 ".count($submittals).'행)');
            $this->line("  물량/BOQ    : 신규 {$bNew} / 갱신 {$bUpd} (총 ".count($boq).'행)');
            $this->line('  직접비 합계 : $'.number_format($total, 0));
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('적재 실패(전부 롤백): '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

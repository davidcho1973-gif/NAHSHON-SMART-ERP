<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Equipment;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Support\Org;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 조지아 703K 주방동 프로젝트 초기 데이터 적재.
 *
 * database/data/703k/ 에 번들된 JSON(메타·주방기기 80대)을 읽어
 * 원청사·현장·프로젝트·수주계약(ROM)·장비를 만든다. 코드(unique key) 기준
 * updateOrCreate 라 몇 번을 다시 돌려도 안전하고, 수정된 번들로 재실행하면
 * 값이 갱신된다. 공정표는 별도로 기존 임포터를 쓴다:
 *
 *   php artisan wbs:import-schedule database/data/703k/703K_schedule.xlsx 703K-KITCHEN --site=703K
 *
 * 주의: 서비스 계층(auth 게이트)을 거치지 않고 Eloquent 로 직접 만든다 —
 * 모델 훅(코드 자동채번·파생 필드)이 불변식을 지켜준다. DB::table() 삽입 금지.
 */
class Import703k extends Command
{
    protected $signature = 'erp:import-703k
        {--dry-run : 트랜잭션으로 실행 후 롤백(무엇이 만들어질지 확인만)}';

    protected $description = '703K 주방동 프로젝트(현장·프로젝트·계약·주방기기 80대) 초기 적재 — 멱등';

    public function handle(): int
    {
        $dir = database_path('data/703k');
        foreach (['meta.json', 'equipment.json'] as $f) {
            if (! is_file("{$dir}/{$f}")) {
                $this->error("번들 파일이 없습니다: {$dir}/{$f}");

                return self::FAILURE;
            }
        }

        $meta = json_decode(file_get_contents("{$dir}/meta.json"), true);
        $equipment = json_decode(file_get_contents("{$dir}/equipment.json"), true);
        if (! is_array($meta) || ! is_array($equipment)) {
            $this->error('번들 JSON 파싱 실패 — 파일이 손상됐는지 확인하세요.');

            return self::FAILURE;
        }

        $own = Company::query()->where('code', Org::code())->first();
        if (! $own) {
            $this->error('자사 법인(code='.Org::code().')이 없습니다. 먼저 php artisan org:provision 을 실행하세요.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $summary = [];

        DB::beginTransaction();
        try {
            // 1. 원청사 (수주 계약 상대는 vendors 가 아니라 companies 행이 정본)
            $client = Company::query()->updateOrCreate(
                ['code' => $meta['client_company']['code']],
                [
                    'name' => $meta['client_company']['name'],
                    'legal_name' => $meta['client_company']['legal_name'],
                    'company_type' => $meta['client_company']['company_type'],
                    'status' => 'active',
                ],
            );
            $summary[] = "원청사   : {$client->name} (#{$client->id})";

            // 2. 현장 — timezone 을 명시하지 않으면 DB 기본(America/Phoenix)으로
            //    떨어져 조지아 출퇴근 마감이 하루 밀린다.
            $site = Site::query()->updateOrCreate(
                ['code' => $meta['site']['code']],
                [
                    'name' => $meta['site']['name'],
                    'country' => $meta['site']['country'],
                    'timezone' => $meta['site']['timezone'],
                    'address' => $meta['site']['address'],
                    'status' => $meta['site']['status'],
                    'company_id' => $own->id,
                    'client_company_id' => $client->id,
                ],
            );
            $summary[] = "현장     : [{$site->code}] {$site->name} (tz {$site->timezone})";

            // 3. 프로젝트
            $project = Project::query()->updateOrCreate(
                ['project_code' => $meta['project']['project_code']],
                [
                    'name' => $meta['project']['name'],
                    'construction_type' => $meta['project']['construction_type'],
                    'project_stage' => $meta['project']['project_stage'],
                    'state' => $meta['project']['state'],
                    'contract_amount' => $meta['project']['contract_amount'],
                    'site_id' => $site->id,
                    'company_id' => $own->id,
                    'upper_contractor_company_id' => $client->id,
                    'payload' => $meta['project']['payload'],
                ],
            );
            $summary[] = "프로젝트 : [{$project->project_code}] {$project->name} — \${$meta['project']['contract_amount']} ({$project->project_stage})";

            // 4. 수주계약 (ROM 임시 — status draft)
            $contract = ProjectContract::query()->updateOrCreate(
                ['contract_number' => $meta['contract']['contract_number']],
                [
                    'title' => $meta['contract']['title'],
                    'direction' => $meta['contract']['direction'],
                    'counterparty_role' => $meta['contract']['counterparty_role'],
                    'contract_type' => $meta['contract']['contract_type'],
                    'status' => $meta['contract']['status'],
                    'original_amount' => $meta['contract']['original_amount'],
                    'currency' => $meta['contract']['currency'],
                    'retainage_percent' => $meta['contract']['retainage_percent'],
                    'scope_of_work' => $meta['contract']['scope_of_work'],
                    'notes' => $meta['contract']['notes'],
                    'company_id' => $own->id,
                    'counterparty_company_id' => $client->id,
                    'site_id' => $site->id,
                    'project_id' => $project->id,
                ],
            );
            $summary[] = "수주계약 : {$contract->internal_reference} ({$contract->contract_number}, {$contract->status})";

            // 5. 주방기기 — equipment_code(703K-###) 기준 멱등
            $created = 0;
            $updated = 0;
            foreach ($equipment as $row) {
                $eq = Equipment::query()->updateOrCreate(
                    ['equipment_code' => $row['equipment_code']],
                    [
                        'equipment_type' => $row['equipment_type'],
                        'model' => $row['model'],
                        'vendor' => $row['vendor'],
                        'quantity' => $row['quantity'],
                        'is_bulk' => $row['is_bulk'],
                        'category_group' => $row['category_group'],
                        'trade' => $row['trade'],
                        'acquisition_type' => $row['acquisition_type'],
                        'status' => $row['status'],
                        'asset_value' => $row['asset_value'],
                        'company_id' => $own->id,
                        'site_id' => $site->id,
                        'purchased_for_site_id' => $site->id,
                        'project_id' => $project->id,
                        'payload' => $row['payload'],
                    ],
                );
                $eq->wasRecentlyCreated ? $created++ : $updated++;
            }
            $summary[] = '주방기기 : 신규 '.$created.' / 갱신 '.$updated.' (총 '.count($equipment).'대)';

            if ($dry) {
                DB::rollBack();
                $summary[] = '';
                $summary[] = '(dry-run — 전부 롤백했습니다. 실제 적재는 --dry-run 없이 다시 실행)';
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('적재 실패(전부 롤백): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('703K 적재'.($dry ? ' (dry-run)' : '').' 결과');
        foreach ($summary as $line) {
            $this->line('  '.$line);
        }
        $this->newLine();
        $this->line('다음 단계: php artisan wbs:import-schedule database/data/703k/703K_schedule.xlsx 703K-KITCHEN --site=703K');

        return self::SUCCESS;
    }
}

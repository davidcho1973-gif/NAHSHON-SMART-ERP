<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Wbs\ScheduleImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * 외부 CPM 공정표(엑셀) → 공정관리(WBS) 적재.
 *
 * 예) php artisan wbs:import-schedule "D:/공정표.xlsx" LGES-AZ-2026-002
 */
class ImportSchedule extends Command
{
    protected $signature = 'wbs:import-schedule
        {file : 공정표 엑셀(.xlsx) 경로}
        {project : 프로젝트 코드 (projects.project_code)}
        {--site=ALL : 현장 코드로 스코프 지정}
        {--dry-run : 읽기만 하고 저장하지 않음}';

    protected $description = 'CPM 공정표 엑셀을 읽어 공정관리(WBS) 트리로 적재합니다.';

    public function handle(ScheduleImporter $importer): int
    {
        $file = (string) $this->argument('file');
        $project = (string) $this->argument('project');

        if (! Project::query()->where('project_code', $project)->exists()) {
            $this->error("프로젝트를 찾을 수 없습니다: {$project}");
            $this->line('  관리자 화면에서 프로젝트를 먼저 등록하세요.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn('--dry-run 은 아직 지원하지 않습니다. 저장 없이 확인하려면 테스트 DB 를 쓰세요.');

            return self::FAILURE;
        }

        $this->info("공정표를 읽는 중: {$file}");

        try {
            $res = $importer->importFromXlsx($file, $project, (string) $this->option('site'));
        } catch (Throwable $e) {
            $this->error('임포트 실패: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("  액티비티   : {$res['activities']}개");
        $this->line("  마일스톤   : {$res['milestones']}개 → Stage");
        $this->line("  생성 결과  : Stage {$res['stages']} / Task {$res['tasks']} / SubTask {$res['subtasks']}");

        foreach ($res['warnings'] as $w) {
            $this->warn('  ! ' . $w);
        }

        $this->newLine();
        $this->info('완료. 공정 관리(WBS) 화면에서 확인하세요.');

        return self::SUCCESS;
    }
}

<?php

use App\Models\WbsItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * HFF-02 데모 WBS(공정관리) 트리 — 화면이 비어 보이지 않도록 시드.
 * SubTask 하나(컨트롤 패널 앵커)를 안전 작업카드 WRK-2605-002 에 연결해 안전↔공정 병합(롤업)을 시연한다.
 * Idempotent — 이미 데이터가 있으면 건드리지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wbs_items')) {
            return;
        }

        if (WbsItem::query()->where('project_code', 'HFF-02')->exists()) {
            return;
        }

        foreach (self::tree() as $stageOrder => $stage) {
            $stageItem = WbsItem::query()->create([
                'project_code' => 'HFF-02', 'parent_id' => null, 'level' => WbsItem::LEVEL_STAGE,
                'wbs_code' => "HFF-02-S-{$stage['no']}", 'node_no' => $stage['no'],
                'name' => $stage['name'], 'sort_order' => $stageOrder, 'source' => 'manual',
            ]);

            foreach ($stage['tasks'] as $taskOrder => $task) {
                $taskItem = WbsItem::query()->create([
                    'project_code' => 'HFF-02', 'parent_id' => $stageItem->id, 'level' => WbsItem::LEVEL_TASK,
                    'wbs_code' => "HFF-02-T-{$task['no']}", 'node_no' => $task['no'],
                    'name' => $task['name'], 'sort_order' => $taskOrder, 'source' => 'manual',
                ]);

                foreach ($task['subs'] as $subOrder => $sub) {
                    WbsItem::query()->create([
                        'project_code' => 'HFF-02', 'parent_id' => $taskItem->id, 'level' => WbsItem::LEVEL_SUBTASK,
                        'wbs_code' => "HFF-02-W-{$sub['no']}", 'node_no' => $sub['no'], 'name' => $sub['name'],
                        'company' => $sub['company'], 'manhours' => $sub['mh'], 'days' => $sub['days'],
                        'ehs' => $sub['ehs'], 'status' => $sub['status'], 'progress' => $sub['progress'],
                        'safety_work_code' => $sub['safety'] ?? null, 'sort_order' => $subOrder, 'source' => 'manual',
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Demo data left in place on rollback.
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function tree(): array
    {
        return [
            ['no' => '1', 'name' => '반입 및 준비 (Mobilization)', 'tasks' => [
                ['no' => '1.1', 'name' => '자재 반입', 'subs' => [
                    ['no' => '1.1.1', 'name' => '장비 하역 및 검수', 'company' => 'NAHSHON', 'mh' => 24, 'days' => 2, 'ehs' => 'medium', 'status' => '완료', 'progress' => 100],
                    ['no' => '1.1.2', 'name' => '작업구역 양생 및 통제', 'company' => 'NAHSHON', 'mh' => 16, 'days' => 1, 'ehs' => 'low', 'status' => '완료', 'progress' => 100],
                ]],
            ]],
            ['no' => '2', 'name' => '설치 (Installation)', 'tasks' => [
                ['no' => '2.1', 'name' => '컨트롤 패널 설치', 'subs' => [
                    ['no' => '2.1.1', 'name' => '컨트롤 패널 앵커 설치', 'company' => 'AUTORICA', 'mh' => 40, 'days' => 3, 'ehs' => 'high', 'status' => '진행중', 'progress' => 30, 'safety' => 'WRK-2605-002'],
                    ['no' => '2.1.2', 'name' => '케이블 트레이 보강', 'company' => 'AUTORICA', 'mh' => 32, 'days' => 2, 'ehs' => 'medium', 'status' => '검수완료', 'progress' => 0],
                ]],
                ['no' => '2.2', 'name' => '기계 본체 설치', 'subs' => [
                    ['no' => '2.2.1', 'name' => '베이스 프레임 정렬', 'company' => 'M-SOL', 'mh' => 48, 'days' => 3, 'ehs' => 'high', 'status' => '검수완료', 'progress' => 0],
                    ['no' => '2.2.2', 'name' => '장비 본체 리깅 및 거치', 'company' => 'M-SOL', 'mh' => 64, 'days' => 4, 'ehs' => 'high', 'status' => 'AI생성', 'progress' => 0],
                ]],
            ]],
            ['no' => '3', 'name' => '배관/배선 (MEP)', 'tasks' => [
                ['no' => '3.1', 'name' => '전기 결선', 'subs' => [
                    ['no' => '3.1.1', 'name' => '동력 케이블 포설', 'company' => 'AI-KOREA', 'mh' => 36, 'days' => 3, 'ehs' => 'high', 'status' => 'AI생성', 'progress' => 0],
                    ['no' => '3.1.2', 'name' => '제어 배선 및 단자 결선', 'company' => 'AI-KOREA', 'mh' => 28, 'days' => 2, 'ehs' => 'medium', 'status' => 'AI생성', 'progress' => 0],
                ]],
            ]],
            ['no' => '4', 'name' => '시운전 (Commissioning)', 'tasks' => [
                ['no' => '4.1', 'name' => '시운전 지원', 'subs' => [
                    ['no' => '4.1.1', 'name' => '단독 시운전 입회', 'company' => 'NAHSHON', 'mh' => 24, 'days' => 2, 'ehs' => 'medium', 'status' => 'AI생성', 'progress' => 0],
                ]],
            ]],
        ];
    }
};

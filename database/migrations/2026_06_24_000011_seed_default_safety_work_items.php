<?php

use App\Models\SafetyWorkItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the three demo safety work cards so the persisted module isn't empty on first
 * load (mirrors the previous localStorage defaults). Idempotent — keyed by work_code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('safety_work_items')) {
            return;
        }

        foreach (self::defaults() as $data) {
            $item = SafetyWorkItem::query()->firstOrNew(['work_code' => $data['work_code']]);

            if ($item->exists) {
                continue; // never clobber real edits
            }

            $item->fill($data)->save();

            foreach ($data['signatures'] as $index => $sig) {
                $item->signatures()->create($sig + ['sort_order' => $index]);
            }
            foreach ($data['issues'] as $index => $issue) {
                $item->issues()->create($issue + ['sort_order' => $index]);
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
    private static function defaults(): array
    {
        return [
            [
                'work_code' => 'WRK-2605-001', 'project' => 'LGES-AZ 오피스 전기', 'location' => '2층 사무실',
                'title' => '천장 전기 배선 정리 및 신규 케이블 포설', 'crew' => 3, 'unit' => 'm',
                'planned_qty' => 30, 'done_qty' => 18, 'total_qty' => 30, 'progress' => 60, 'due_label' => '오늘 17:00',
                'plan_status' => '승인완료', 'tbm_status' => '완료', 'close_status' => '마감대기', 'progress_status' => '미분석',
                'work_text' => '천장 전기 배선 정리 및 신규 케이블 포설. 작업자는 3명이고 사다리를 사용합니다. 예정 작업량은 30m입니다.',
                'close_text' => '천장 배선 18m 포설 완료. 자재 부족으로 나머지 12m는 내일 진행 예정. 천장 내부 장애물로 작업 속도 지연.',
                'signatures' => [
                    ['name' => '김철수', 'role' => '전기공', 'signed' => true],
                    ['name' => '이민준', 'role' => '보조', 'signed' => true],
                    ['name' => '임성훈', 'role' => '감시자', 'signed' => false],
                ],
                'issues' => [
                    ['type' => '미조치', 'body' => '자재 부족으로 잔여 12m 대기', 'owner' => '구매팀', 'status' => '조치중'],
                ],
            ],
            [
                'work_code' => 'WRK-2605-002', 'project' => 'HFF-02 장비 설치', 'location' => 'Production Bay B',
                'title' => '컨트롤 패널 앵커 설치 및 케이블 트레이 보강', 'crew' => 4, 'unit' => 'ea',
                'planned_qty' => 10, 'done_qty' => 0, 'total_qty' => 10, 'progress' => 35, 'due_label' => '오늘 13:00',
                'plan_status' => '검토중', 'tbm_status' => '대기', 'close_status' => '시작전', 'progress_status' => '미분석',
                'work_text' => '컨트롤 패널 앵커 설치 및 케이블 트레이 보강. 해머드릴, 앵커볼트, 사다리 사용. 예정 작업량은 10개소입니다.',
                'close_text' => '',
                'signatures' => [
                    ['name' => '박지호', 'role' => '팀리더', 'signed' => false],
                    ['name' => '최동혁', 'role' => '설치', 'signed' => false],
                    ['name' => '강승우', 'role' => '장비', 'signed' => false],
                    ['name' => '임성훈', 'role' => '보조', 'signed' => false],
                ],
                'issues' => [
                    ['type' => '위험상황', 'body' => '케이블 트레이 모서리 날카로움', 'owner' => '박소장', 'status' => '조치중'],
                ],
            ],
            [
                'work_code' => 'WRK-2605-003', 'project' => 'SST-03 배관 수정', 'location' => 'Utility Room',
                'title' => '기존 배관 철거 후 신규 라인 12m 설치', 'crew' => 5, 'unit' => 'm',
                'planned_qty' => 12, 'done_qty' => 0, 'total_qty' => 12, 'progress' => 15, 'due_label' => '내일',
                'plan_status' => '초안', 'tbm_status' => '대기', 'close_status' => '시작전', 'progress_status' => '미분석',
                'work_text' => '기존 배관 철거 후 신규 라인 12m 설치. 절단 공구와 리프트를 사용합니다.',
                'close_text' => '',
                'signatures' => [
                    ['name' => '김철수', 'role' => '배관공', 'signed' => false],
                    ['name' => '이민준', 'role' => '보조', 'signed' => false],
                ],
                'issues' => [
                    ['type' => '아차사고', 'body' => '배관 자재 이동 중 통로 협소', 'owner' => '현장팀', 'status' => '완료'],
                ],
            ],
        ];
    }
};

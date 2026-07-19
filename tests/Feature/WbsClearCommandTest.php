<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SafetyWorkItem;
use App\Models\SafetyWorkSignature;
use App\Models\WbsItem;
use App\Models\WbsManual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WbsClearCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Stage→Task→SubTask 트리 + wbs_code 로 연결된 안전카드(서명 포함) 한 벌을 만든다. */
    private function seedProject(string $code): void
    {
        Project::create(['project_code' => $code, 'name' => '테스트 '.$code, 'construction_type' => 'equipment_setting']);

        $stage = WbsItem::create([
            'project_code' => $code, 'level' => 'stage', 'wbs_code' => $code.'-S-1',
            'node_no' => '1', 'name' => '설치', 'sort_order' => 0,
        ]);
        $task = WbsItem::create([
            'project_code' => $code, 'parent_id' => $stage->id, 'level' => 'task', 'wbs_code' => $code.'-T-1.1',
            'node_no' => '1.1', 'name' => '패널 설치', 'sort_order' => 0,
        ]);
        WbsItem::create([
            'project_code' => $code, 'parent_id' => $task->id, 'level' => 'subtask',
            'wbs_code' => $code.'-W-1.1.1', 'node_no' => '1.1.1', 'name' => '앵커 설치',
            'manhours' => 40, 'days' => 1, 'status' => 'AI생성', 'progress' => 0, 'sort_order' => 0,
        ]);

        $card = SafetyWorkItem::create([
            'work_code' => 'WRK-'.$code, 'title' => '앵커 설치 작업', 'wbs_code' => $code.'-W-1.1.1',
            'work_date' => now()->toDateString(),
            'plan_status' => '검토중', 'tbm_status' => '대기', 'close_status' => '시작전', 'progress_status' => '추천완료',
        ]);
        SafetyWorkSignature::create([
            'safety_work_item_id' => $card->id, 'name' => '김철수', 'role' => 'worker', 'sort_order' => 0,
        ]);

        WbsManual::create([
            'project_code' => $code, 'original_name' => 'manual.pdf', 'disk' => 'public',
            'path' => 'wbs-manuals/'.$code.'.pdf', 'engine' => 'claude', 'status' => 'completed',
        ]);
    }

    public function test_dry_run_by_default_deletes_nothing(): void
    {
        $this->seedProject('CLR-01');

        $this->artisan('wbs:clear CLR-01')->assertSuccessful();

        $this->assertSame(3, WbsItem::where('project_code', 'CLR-01')->count());
        $this->assertSame(1, SafetyWorkItem::where('work_code', 'WRK-CLR-01')->count());
        $this->assertSame(1, WbsManual::where('project_code', 'CLR-01')->count());
    }

    public function test_force_clears_one_project_and_keeps_others_and_masters(): void
    {
        $this->seedProject('CLR-01');
        $this->seedProject('CLR-02');

        $this->artisan('wbs:clear CLR-01 --force')->assertSuccessful();

        // 대상 프로젝트: 트리 + 안전카드(서명 포함) 삭제, 매뉴얼은 기본 유지.
        $this->assertSame(0, WbsItem::where('project_code', 'CLR-01')->count());
        $this->assertSame(0, SafetyWorkItem::where('work_code', 'WRK-CLR-01')->count());
        $this->assertSame(1, SafetyWorkSignature::count()); // CLR-02 카드의 서명만 남는다.
        $this->assertSame(1, WbsManual::where('project_code', 'CLR-01')->count());

        // 다른 프로젝트와 마스터(프로젝트 행)는 그대로.
        $this->assertSame(3, WbsItem::where('project_code', 'CLR-02')->count());
        $this->assertSame(1, SafetyWorkItem::where('work_code', 'WRK-CLR-02')->count());
        $this->assertTrue(Project::where('project_code', 'CLR-01')->exists());
    }

    public function test_with_manuals_also_deletes_manual_rows_and_files(): void
    {
        Storage::fake('public');
        $this->seedProject('CLR-01');
        Storage::disk('public')->put('wbs-manuals/CLR-01.pdf', 'pdf');

        $this->artisan('wbs:clear CLR-01 --with-manuals --force')->assertSuccessful();

        $this->assertSame(0, WbsManual::where('project_code', 'CLR-01')->count());
        Storage::disk('public')->assertMissing('wbs-manuals/CLR-01.pdf');
    }

    public function test_all_clears_every_project(): void
    {
        $this->seedProject('CLR-01');
        $this->seedProject('CLR-02');

        $this->artisan('wbs:clear --all --force')->assertSuccessful();

        $this->assertSame(0, WbsItem::count());
        $this->assertSame(0, SafetyWorkItem::whereNotNull('wbs_code')->count());
        // 프로젝트 마스터는 유지 — 화면에서 같은 프로젝트로 새로 시작할 수 있다.
        $this->assertSame(2, Project::count());
    }

    public function test_unknown_project_fails(): void
    {
        $this->artisan('wbs:clear NOPE-99')->assertFailed();
    }
}

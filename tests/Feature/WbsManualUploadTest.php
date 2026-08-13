<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeWbsManualJob;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Models\WbsManual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 공정관리 AI 매뉴얼 분석: 업로드→분석(Claude 엔진)→WBS 생성 + 이력, 그리고 리스트 조회.
 */
class WbsManualUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // 매뉴얼은 관리 권한이 있어야 다룰 수 있다(팩토리 기본값은 worker 라 403 이 난다).
        $this->user = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);
        Project::query()->create(['project_code' => 'MAN-01', 'name' => 'Manual Project', 'construction_type' => 'mechanical']);
    }

    private function fakeClaudeWbs(): void
    {
        config(['services.anthropic.api_key' => 'sk-ant-test', 'services.wbs.ai_engine' => 'claude']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode(['stages' => [[
                        'stage_no' => '1', 'stage_name' => '설치',
                        'tasks' => [[
                            'task_no' => '1.1', 'task_name' => '반입',
                            'sub_tasks' => [
                                ['sub_no' => '1.1.1', 'sub_name' => '양중', 'company' => 'ABC ENG', 'manhours' => 40, 'days' => 2, 'ehs' => 'high'],
                            ],
                        ]],
                    ]]]),
                ]],
            ]),
        ]);
    }

    private function fakeClaudeEmptyResult(): void
    {
        config(['services.anthropic.api_key' => 'sk-ant-test', 'services.wbs.ai_engine' => 'claude']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode(['activities' => [], 'milestones' => [], 'stages' => []]),
                ]],
            ]),
        ]);
    }

    public function test_upload_requires_auth(): void
    {
        $this->post(route('wbs-manual.upload'))->assertRedirect(route('login'));
    }

    public function test_upload_queues_analysis_and_returns_immediately(): void
    {
        // 동기 분석은 게이트웨이 504 를 유발하므로, 업로드는 즉시 202(analyzing)를 돌려주고
        // 실제 분석은 응답 후 백그라운드(afterResponse) 잡이 처리한다.
        Storage::fake('public');
        $this->fakeClaudeWbs();

        $file = UploadedFile::fake()->create('setup-manual.pdf', 200, 'application/pdf');

        $response = $this->actingAs($this->user)->postJson(route('wbs-manual.upload'), [
            'manual' => $file,
            'project_code' => 'MAN-01',
            'site_id' => 'ALL',
        ]);

        // 응답 계약: 즉시 202 + status=analyzing (실제 분석은 응답 후 잡이 처리).
        $response->assertStatus(202)->assertJson(['success' => true, 'status' => 'analyzing']);
        $response->assertJsonPath('manual.status', 'analyzing');

        $manual = WbsManual::first();
        $this->assertNotNull($manual);
        Storage::disk('public')->assertExists($manual->path);
    }

    public function test_analyze_job_completes_manual_and_persists_wbs(): void
    {
        Storage::fake('public');
        $this->fakeClaudeWbs();

        Storage::disk('public')->put('wbs-manuals/setup.pdf', '%PDF-1.4 fake manual');
        $manual = WbsManual::create([
            'project_code' => 'MAN-01', 'original_name' => 'setup.pdf', 'disk' => 'public',
            'path' => 'wbs-manuals/setup.pdf', 'mime_type' => 'application/pdf', 'status' => 'analyzing',
        ]);

        (new AnalyzeWbsManualJob($manual->id, 'MAN-01', 'ALL'))->handle();

        $manual->refresh();
        $this->assertSame('completed', $manual->status);
        $this->assertSame('claude', $manual->engine);
        $this->assertSame(1, $manual->stages);
        $this->assertSame(1, $manual->subtasks);
        $this->assertSame(1, WbsItem::where('project_code', 'MAN-01')->where('level', 'stage')->count());
    }

    public function test_analyze_job_marks_failed_on_error(): void
    {
        Storage::fake('public');
        // 파일이 없으면 실패로 기록.
        $manual = WbsManual::create([
            'project_code' => 'MAN-01', 'original_name' => 'gone.pdf', 'disk' => 'public',
            'path' => 'wbs-manuals/gone.pdf', 'mime_type' => 'application/pdf', 'status' => 'analyzing',
        ]);

        (new AnalyzeWbsManualJob($manual->id, 'MAN-01', 'ALL'))->handle();

        $manual->refresh();
        $this->assertSame('failed', $manual->status);
        $this->assertNotEmpty($manual->error);
    }

    public function test_index_lists_analyzed_manuals_for_project(): void
    {
        WbsManual::create(['project_code' => 'MAN-01', 'original_name' => 'a.pdf', 'path' => 'wbs-manuals/a.pdf', 'engine' => 'claude', 'status' => 'completed', 'stages' => 2, 'tasks' => 3, 'subtasks' => 8]);
        WbsManual::create(['project_code' => 'OTHER', 'original_name' => 'b.pdf', 'path' => 'wbs-manuals/b.pdf', 'engine' => 'gemini', 'status' => 'completed']);

        $response = $this->actingAs($this->user)->getJson(route('wbs-manual.index', ['project' => 'MAN-01']));

        $response->assertOk()->assertJson(['success' => true]);
        $response->assertJsonCount(1, 'manuals');
        $response->assertJsonPath('manuals.0.original_name', 'a.pdf');
        $response->assertJsonPath('manuals.0.stages', 2);
    }

    public function test_upload_rejects_disallowed_file_type(): void
    {
        Storage::fake('public');
        $this->fakeClaudeWbs();

        $file = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');

        $this->actingAs($this->user)->postJson(route('wbs-manual.upload'), [
            'manual' => $file,
            'project_code' => 'MAN-01',
        ])->assertStatus(422);
    }

    public function test_worker_cannot_upload_or_list_wbs_manuals(): void
    {
        // 매뉴얼에는 원청 도면·시방·계약 조건이 들어 있다. 로그인만 했다고 열려선 안 된다.
        Storage::fake('public');
        $worker = User::factory()->create([
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'active',
        ]);

        $this->actingAs($worker)->postJson(route('wbs-manual.upload'), [
            'manual' => UploadedFile::fake()->create('manual.pdf', 20, 'application/pdf'),
            'project_code' => 'MAN-01',
        ])->assertForbidden();

        $this->actingAs($worker)->getJson(route('wbs-manual.index'))->assertForbidden();
    }

    public function test_site_manager_cannot_open_another_sites_manual(): void
    {
        // 아이디만 바꿔 남의 현장 매뉴얼을 내려받는 경로(IDOR).
        Storage::fake('public');
        $allowed = Site::create(['code' => 'ALLOWED', 'name' => 'Allowed']);
        $other = Site::create(['code' => 'OTHER', 'name' => 'Other']);
        $manager = User::factory()->create([
            'access_role' => 'site_manager',
            'access_scope' => 'site',
            'allowed_site_id' => $allowed->id,
            'account_status' => 'active',
        ]);
        Storage::disk('public')->put('wbs-manuals/other.pdf', '%PDF-1.4 other');
        $manual = WbsManual::create([
            'project_code' => 'MAN-01',
            'site_id' => $other->id,
            'original_name' => 'other.pdf',
            'path' => 'wbs-manuals/other.pdf',
        ]);

        $this->actingAs($manager)->get(route('wbs-manual.show', $manual))->assertForbidden();
    }

    public function test_site_manager_only_lists_their_own_sites_manuals(): void
    {
        $allowed = Site::create(['code' => 'ALLOWED2', 'name' => 'Allowed']);
        $other = Site::create(['code' => 'OTHER2', 'name' => 'Other']);
        $manager = User::factory()->create([
            'access_role' => 'site_manager',
            'access_scope' => 'site',
            'allowed_site_id' => $allowed->id,
            'account_status' => 'active',
        ]);
        WbsManual::create(['project_code' => 'MAN-01', 'site_id' => $allowed->id, 'original_name' => 'mine.pdf', 'path' => 'wbs-manuals/mine.pdf']);
        WbsManual::create(['project_code' => 'MAN-01', 'site_id' => $other->id, 'original_name' => 'theirs.pdf', 'path' => 'wbs-manuals/theirs.pdf']);

        $response = $this->actingAs($manager)->getJson(route('wbs-manual.index'));

        $response->assertOk()->assertJsonCount(1, 'manuals');
        $response->assertJsonPath('manuals.0.original_name', 'mine.pdf');
    }

    public function test_analyze_job_marks_empty_ai_result_as_failed(): void
    {
        // AI 가 아무것도 못 뽑았는데 "분석 완료" 로 보이면, 담당자가 WBS 가 생긴 줄 알고 넘어간다.
        Storage::fake('public');
        $this->fakeClaudeEmptyResult();

        Storage::disk('public')->put('wbs-manuals/empty.pdf', '%PDF-1.4 empty manual');
        $manual = WbsManual::create([
            'project_code' => 'MAN-01', 'original_name' => 'empty.pdf', 'disk' => 'public',
            'path' => 'wbs-manuals/empty.pdf', 'mime_type' => 'application/pdf', 'status' => 'analyzing',
        ]);

        (new AnalyzeWbsManualJob($manual->id, 'MAN-01', 'ALL'))->handle();

        $manual->refresh();
        $this->assertSame('failed', $manual->status);
        $this->assertStringContainsString('비어', (string) $manual->error);
    }
}

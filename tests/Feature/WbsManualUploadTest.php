<?php

namespace Tests\Feature;

use App\Models\Project;
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
        $this->user = User::factory()->create();
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
                                ['sub_no' => '1.1.1', 'sub_name' => '양중', 'company' => 'NAHSHON', 'manhours' => 40, 'days' => 2, 'ehs' => 'high'],
                            ],
                        ]],
                    ]]]),
                ]],
            ]),
        ]);
    }

    public function test_upload_requires_auth(): void
    {
        $this->post(route('wbs-manual.upload'))->assertRedirect(route('login'));
    }

    public function test_upload_pdf_analyzes_and_records_manual(): void
    {
        Storage::fake('public');
        $this->fakeClaudeWbs();

        $file = UploadedFile::fake()->create('setup-manual.pdf', 200, 'application/pdf');

        $response = $this->actingAs($this->user)->postJson(route('wbs-manual.upload'), [
            'manual' => $file,
            'project_code' => 'MAN-01',
            'site_id' => 'ALL',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $response->assertJsonPath('manual.engine', 'claude');
        $response->assertJsonPath('manual.stages', 1);
        $response->assertJsonPath('manual.subtasks', 1);

        // Manual row + stored file + persisted WBS.
        $manual = WbsManual::first();
        $this->assertNotNull($manual);
        $this->assertSame('completed', $manual->status);
        Storage::disk('public')->assertExists($manual->path);
        $this->assertSame(1, WbsItem::where('project_code', 'MAN-01')->where('level', 'stage')->count());
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
}

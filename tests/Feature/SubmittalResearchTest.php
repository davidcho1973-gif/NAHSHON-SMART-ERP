<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 제품 제출물 자료 AI 웹 조사 → 사람이 고른 후보만 받아 문서함 편철·연결.
 *
 * 지키는 것: AI 가 찾은 것은 후보일 뿐 편철은 사람이 고른 뒤에만,
 * 서버는 조사 결과에 있던 주소만 내려받는다(SSRF 차단),
 * PDF 가 아니면 편철하지 않는다(다운로드 페이지를 자료로 착각하지 않게).
 */
class SubmittalResearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'document-intelligence.disk' => 'local',
            'services.anthropic.api_key' => 'test-key',
            'services.gemini.api_key' => '',
            'services.openai.api_key' => '',
        ]);
        Storage::fake('local');
    }

    /**
     * 조사는 접수(번호표) 방식이다 — 요청 안에서 기다리면 게이트웨이가 504 로 끊는다.
     * 접수 → 번호표 → 상태 조회에서 결과를 받는 흐름을 그대로 검증한다.
     */
    public function test_research_returns_candidates_via_job_ticket(): void
    {
        [$admin, $row] = $this->fixture();
        $this->fakeClaude([
            ['maker' => 'Owens Corning', 'product' => 'EcoTouch PINK', 'url' => 'https://www.example-maker.com/specs/ecotouch.pdf', 'file' => 'pdf', 'why' => 'ASTM C665 Type I 충족'],
            ['maker' => 'Knauf', 'product' => 'EcoBatt', 'url' => 'https://www.example-knauf.com/ecobatt', 'file' => 'page', 'why' => '규격 동일, 페이지에서 PDF 제공'],
        ]);

        $ticket = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_researchSubmittal', ['args' => [$row->id]])
            ->assertOk()->json();
        $this->assertTrue($ticket['success']);
        $this->assertIsInt($ticket['jobId']);

        // afterResponse 로 접수된 작업은 응답 종료 시 실행됐다 — 번호표로 결과를 받는다.
        $status = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_getAiJob', ['args' => [$ticket['jobId']]])
            ->assertOk()->json();

        $this->assertTrue($status['done']);
        $this->assertSame('done', $status['status']);
        $data = $status['result'];
        $this->assertSame('claude', $data['engine']);
        $this->assertCount(2, $data['candidates']);
        $this->assertSame('Owens Corning', $data['candidates'][0]['maker']);
        $this->assertSame('pdf', $data['candidates'][0]['file']);
    }

    public function test_chosen_candidate_is_downloaded_filed_and_linked(): void
    {
        [$admin, $row] = $this->fixture();
        $this->fakeClaude([
            ['maker' => 'Owens Corning', 'product' => 'EcoTouch', 'url' => 'https://www.example-maker.com/specs/ecotouch.pdf', 'file' => 'pdf', 'why' => '규격 일치'],
        ]);
        Http::fake(['www.example-maker.com/*' => Http::response("%PDF-1.7\nfake spec sheet", 200, ['Content-Type' => 'application/pdf'])]);

        $this->actingAs($admin)->postJson('/smart-company-api/api_researchSubmittal', ['args' => [$row->id]])->assertOk();

        $data = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_fileSubmittalResearch', ['args' => [$row->id, 0]])
            ->assertOk()->json();

        $this->assertTrue($data['success']);
        $document = IntelligentDocument::query()->findOrFail($data['documentId']);
        $this->assertSame($row->site_id, $document->site_id);
        $this->assertSame($row->project_id, $document->project_id);
        $this->assertSame('ai_research', $document->source);

        // 대장에 연결됐고, 미착수였던 상태가 작성중으로 움직였다.
        $this->assertTrue($row->fresh()->documents->contains('id', $document->id));
        $this->assertSame('작성중', $row->fresh()->status);

        // 목록에도 자료가 실려 나온다.
        $rows = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_getSubmittals', ['args' => [$row->project_id]])
            ->assertOk()->json('rows');
        $this->assertSame($document->id, $rows[0]['documents'][0]['id']);
    }

    public function test_html_page_is_not_filed_as_product_data(): void
    {
        [$admin, $row] = $this->fixture();
        $this->fakeClaude([
            ['maker' => 'Knauf', 'product' => 'EcoBatt', 'url' => 'https://www.example-knauf.com/download.pdf', 'file' => 'pdf', 'why' => '규격 일치'],
        ]);
        Http::fake(['www.example-knauf.com/*' => Http::response('<html>download page</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->actingAs($admin)->postJson('/smart-company-api/api_researchSubmittal', ['args' => [$row->id]])->assertOk();

        $data = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_fileSubmittalResearch', ['args' => [$row->id, 0]])
            ->assertOk()->json();

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('PDF 가 아니라', $data['error']);
        $this->assertSame(0, IntelligentDocument::query()->count());
    }

    public function test_filing_without_prior_research_or_bad_index_is_refused(): void
    {
        [$admin, $row] = $this->fixture();

        $data = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_fileSubmittalResearch', ['args' => [$row->id, 3]])
            ->assertOk()->json();

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('만료', $data['error']);
    }

    public function test_research_requires_manage_role(): void
    {
        [, $row] = $this->fixture();
        $viewer = $this->user('payroll');

        // 접수는 되지만(번호표 방식), 작업은 접수한 사람의 권한으로 돌므로 실패로 남는다.
        $ticket = $this->actingAs($viewer)
            ->postJson('/smart-company-api/api_researchSubmittal', ['args' => [$row->id]])
            ->assertOk()->json();

        $status = $this->actingAs($viewer)
            ->postJson('/smart-company-api/api_getAiJob', ['args' => [$ticket['jobId']]])
            ->assertOk()->json();

        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('권한', $status['error']);
    }

    public function test_without_any_api_key_the_error_says_so(): void
    {
        config(['services.anthropic.api_key' => '']);
        [$admin, $row] = $this->fixture();

        $ticket = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_researchSubmittal', ['args' => [$row->id]])
            ->assertOk()->json();

        $status = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_getAiJob', ['args' => [$ticket['jobId']]])
            ->assertOk()->json();

        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('API 키', $status['error']);
    }

    /** @param list<array<string, string>> $candidates */
    private function fakeClaude(array $candidates): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-opus-4-8',
                'content' => [
                    ['type' => 'text', 'text' => "검색 결과를 확인했습니다.\n".json_encode(['candidates' => $candidates])],
                ],
                'usage' => ['input_tokens' => 900, 'output_tokens' => 300],
            ]),
        ]);
    }

    /** @return array{0: User, 1: Submittal} */
    private function fixture(): array
    {
        $company = Company::query()->create(['code' => 'XYZ', 'name' => 'XYZ MEP', 'status' => 'active']);
        $site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'LGES-AZ', 'name' => 'LGES Arizona',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'project_code' => 'LGES-AZ-2026-001', 'name' => 'Arizona Module',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);
        $row = Submittal::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id,
            'seq' => 1, 'csi' => '07 2100.1', 'section' => '단열', 'category' => 'Action 제출물',
            'title' => '제품자료(Product Data) — 유리섬유 블랭킷 (ASTM C665 Type I)', 'status' => '미착수',
        ]);

        return [$this->user('admin'), $row];
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'name' => str($role)->headline()->toString(),
            'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'access_role' => $role,
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);
    }
}

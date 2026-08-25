<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Equipment;
use App\Models\IntegratedDocument;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 연계 수선 R4 — 문서·대시보드 정합.
 */
class IntegrationRepairR4Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'account_status' => 'active', 'access_scope' => 'all_sites']));
    }

    // ── 이중 분석 제거 ────────────────────────────────────────────────

    public function test_이미_판독된_편철은_AI_를_다시_부르지_않는다(): void
    {
        Bus::fake();
        Storage::fake('public');
        Storage::disk('public')->put('integrated-documents/receipt.png', 'PNG bytes A');

        // 영수증처럼 자기 모듈이 이미 판독을 끝내고(analyzed_at 보유) 편철되는 문서.
        IntegratedDocument::create([
            'title' => 'Home Depot 영수증', 'disk' => 'public', 'path' => 'integrated-documents/receipt.png',
            'original_name' => 'receipt.png', 'mime_type' => 'image/png', 'size' => 11,
            'status' => 'needs_review', 'document_type' => 'receipt', 'analyzed_at' => now(),
        ]);

        Bus::assertNotDispatched(\App\Jobs\AnalyzeIntelligentDocumentJob::class);

        $mirror = IntelligentDocument::query()->first();
        $this->assertNotNull($mirror, 'AI 문서함 인덱싱 자체는 되어야 한다(검색·액션 큐)');
        $this->assertSame('ready', $mirror->ai_status, '재분석 없이 완료로 — 같은 파일을 두 AI 가 읽던 낭비 제거');
        $this->assertSame('receipt', $mirror->document_type, '어휘 매핑표를 지나 복사된다');
    }

    public function test_판독_전_문서는_기존대로_분석을_탄다(): void
    {
        Bus::fake();
        Storage::fake('public');
        Storage::disk('public')->put('integrated-documents/new.pdf', '%PDF bytes B');

        IntegratedDocument::create([
            'title' => '새 문서', 'disk' => 'public', 'path' => 'integrated-documents/new.pdf',
            'original_name' => 'new.pdf', 'mime_type' => 'application/pdf', 'size' => 12,
            'status' => 'analyzing',
        ]);

        Bus::assertDispatched(\App\Jobs\AnalyzeIntelligentDocumentJob::class);
        $this->assertSame('queued', IntelligentDocument::query()->value('ai_status'));
    }

    // ── 대시보드 정직성 ───────────────────────────────────────────────

    public function test_공구_가짜_숫자가_사라졌다(): void
    {
        $stats = SmartCompanyData::toolStats();

        $this->assertSame(0, $stats['total'], '가짜 숫자는 빈 것보다 나쁘다');
        $this->assertTrue($stats['notConfigured']);
        $this->assertSame([], SmartCompanyData::toolList());
        $this->assertSame([], SmartCompanyData::toolTransactions());
    }

    public function test_렌탈_통계는_소유_장비를_빼고_센다(): void
    {
        Site::create(['code' => 'S1', 'name' => '현장', 'status' => 'active']);
        Equipment::create(['equipment_type' => '지게차', 'model' => 'F1', 'status' => '사용중', 'acquisition_type' => '임대']);
        Equipment::create(['equipment_type' => '용접기', 'model' => 'W1', 'status' => '사용중', 'acquisition_type' => '소유']);
        Equipment::create(['equipment_type' => '리프트', 'model' => 'L1', 'status' => '대기중']); // 미기재 → 임대로 본다

        $stats = SmartCompanyData::rentalStats();

        $this->assertSame(2, $stats['total'], '구매(소유) 장비가 임대 총계에 섞이면 임대료 검증이 틀어진다');
    }

    public function test_장비_점검_도래가_상수_0_이_아니라_실측이다(): void
    {
        Equipment::create(['equipment_type' => '크레인', 'model' => 'C1', 'status' => '사용중',
            'inspection_due_on' => now()->subDay()->toDateString()]);
        Equipment::create(['equipment_type' => '지게차', 'model' => 'F2', 'status' => '사용중',
            'inspection_due_on' => now()->addMonth()->toDateString()]);

        $stats = SmartCompanyData::equipmentStats();

        $this->assertSame(1, $stats['todayInspections']);
    }

    public function test_작업허가_목록은_정본_모델에서_나온다(): void
    {
        $site = Site::create(['code' => 'S1', 'name' => '현장', 'status' => 'active']);
        \App\Models\SafetyPermit::create([
            'site_id' => $site->id, 'permit_no' => 'PTW-2026-001', 'type' => '화기작업',
            'title' => 'B구역 배관 용접', 'status' => 'issued',
        ]);

        $list = SmartCompanyData::ptwList();

        $this->assertCount(1, $list, '하드코딩 견본 두 줄이 진짜 허가 시스템을 가리면 안 된다');
        $this->assertSame('PTW-2026-001', $list[0]['id']);
        $this->assertSame('B구역 배관 용접', $list[0]['job']);
    }

    // ── 진척률 정본 하나 ──────────────────────────────────────────────

    public function test_지휘본부_진척률이_공정_화면과_같은_산식을_쓴다(): void
    {
        $site = Site::create(['code' => 'S1', 'name' => '현장', 'status' => 'active']);
        $project = \App\Models\Project::create(['project_code' => 'R4-P', 'name' => '정합', 'construction_type' => 'equipment_setting', 'site_id' => $site->id]);

        // 공수는 없고 공기만 있는 흔한 공정표: 17일짜리 완료 + 1일짜리 미완.
        // 정본(공수→공기→균등)이면 94%, 지휘본부의 옛 산식(공수만→균등 폴백)이면 50%.
        \App\Models\WbsItem::create(['project_code' => 'R4-P', 'project_id' => $project->id, 'site_id' => $site->id,
            'level' => 'subtask', 'wbs_code' => 'R4-P-W-A', 'name' => '큰 공사', 'days' => 17, 'status' => '완료', 'progress' => 100, 'sort_order' => 1]);
        \App\Models\WbsItem::create(['project_code' => 'R4-P', 'project_id' => $project->id, 'site_id' => $site->id,
            'level' => 'subtask', 'wbs_code' => 'R4-P-W-B', 'name' => '작은 공사', 'days' => 1, 'status' => '검수완료', 'progress' => 0, 'sort_order' => 2]);

        $summary = app(\App\Services\Wbs\WbsService::class)->progressSummary('R4-P');
        $center = app(\App\Services\CommandCenter\ConstructionCommandCenterService::class)
            ->snapshot(auth()->user(), 'ALL');
        $row = collect($center['projects'] ?? [])->firstWhere('code', 'R4-P');

        $this->assertNotNull($row, '지휘본부에 프로젝트 줄이 있어야 한다');
        $this->assertSame($summary['progress'], (int) $row['progress'], '같은 프로젝트에 화면마다 다른 진척률이 뜨면 어느 쪽도 못 믿는다');
    }
}

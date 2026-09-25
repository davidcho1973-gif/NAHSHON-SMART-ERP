<?php

namespace Tests\Feature;

use App\Models\ContractBoqLine;
use App\Models\DrawingMark;
use App\Models\DrawingSheet;
use App\Models\IntelligentDocument;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Drawings\DrawingMarkService;
use App\Services\Drawings\SectionDrawingService;
use App\Services\Finance\ClaimEvidenceService;
use App\Services\Finance\ContractSheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 도면 위 표시 — 사장 지시(2026-09-25): «각 줄에 사진이나 증거 자료를 넣었을 때 도면에 직접 내용이 넣어지게».
 *
 * 잠그는 것:
 *  - 표시는 도면 번호에 붙고 좌표는 쪽 대비 0~1 이다. 쪽 밖·모양에 안 맞는 점 수는 거절한다.
 *  - 색은 적지 않는다 — 연결된 기록이 확인되면 같은 표시가 «확인됨» 으로 바뀐다. 반입 확인은 따로 보인다.
 *  - 다른 현장의 줄·다른 줄의 기록은 붙일 수 없다. 보기 권한만 있으면 더하지 못한다.
 *  - 공정 카드의 도면 칩이 표시 수를 안다.
 */
class DrawingMarkTest extends TestCase
{
    use ContractWorkbookFixture { setUp as fixtureSetUp; }
    use RefreshDatabase;

    private ContractBoqLine $wall;

    private IntelligentDocument $sheetDoc;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        $contract = ProjectContract::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'title' => 'Prime',
            'direction' => 'receivable', 'original_amount' => 3000, 'currency' => 'USD']);
        $this->sheetDoc = $this->workbook();
        $this->assertTrue(app(ContractSheetImportService::class)->import(['documentId' => $this->sheetDoc->id, 'contractId' => $contract->id, 'confirmContract' => true])['success']);
        $this->wall = ContractBoqLine::where('line_no', '2')->firstOrFail();
    }

    private function marks(): DrawingMarkService
    {
        return app(DrawingMarkService::class);
    }

    private function record(string $stage, float $qty): int
    {
        $r = app(ClaimEvidenceService::class)->saveRecord(['lineId' => $this->wall->id, 'recordKind' => 'actual', 'workDate' => '2026-09-01', 'location' => 'Pantry',
            'stage' => $stage, 'reportedQty' => $qty, 'sourceRef' => 'm:'.$stage.$qty, 'evidence' => [['type' => 'document', 'id' => $this->sheetDoc->id, 'locator' => 'photo']]]);
        $this->assertTrue($r['success'], $r['error'] ?? '');

        return $r['id'];
    }

    public function test_a_mark_follows_its_record_from_pending_to_done(): void
    {
        $recordId = $this->record('installation', 40);
        $saved = $this->marks()->save(['siteId' => $this->site->id, 'sheetNo' => ' 703k-a01-01 ', 'shape' => 'line',
            'points' => [[0.1, 0.2], [0.4, 0.2]], 'lineId' => $this->wall->id, 'recordId' => $recordId]);
        $this->assertTrue($saved['success'], $saved['error'] ?? '');
        $this->assertSame('pending', $saved['mark']['status']);
        $this->assertSame('703K-A01-01', DrawingMark::firstOrFail()->sheet_no, '번호로 저장한다 — 개정판이 와도 산다');
        $this->assertSame($this->wall->work_section_id, DrawingMark::firstOrFail()->work_section_id);

        $this->assertTrue(app(ClaimEvidenceService::class)->reviewRecord(['id' => $recordId, 'action' => 'verify', 'verifiedQty' => 40, 'reviewNote' => 'ok'])['success']);
        $sheet = $this->marks()->sheet($this->site->id, '703K-A01-01');
        $this->assertTrue($sheet['success']);
        $this->assertSame('done', $sheet['marks'][0]['status'], '색은 기록에서 나온다 — 확인되면 초록');
        $this->assertSame('#2', '#'.$sheet['marks'][0]['line']['lineNo']);
        $this->assertSame('Pantry', $sheet['marks'][0]['record']['location']);
        $this->assertNull($sheet['sheet'], '도면 파일이 아직 없으면 표시만 있다');

        $stored = $this->record('stored', 40);
        app(ClaimEvidenceService::class)->reviewRecord(['id' => $stored, 'action' => 'verify', 'verifiedQty' => 40, 'reviewNote' => 'invoice']);
        $this->marks()->save(['siteId' => $this->site->id, 'sheetNo' => '703K-A01-01', 'shape' => 'area',
            'points' => [[0.1, 0.1], [0.2, 0.1], [0.2, 0.2]], 'lineId' => $this->wall->id, 'recordId' => $stored]);
        $this->marks()->save(['siteId' => $this->site->id, 'sheetNo' => '703K-A01-01', 'shape' => 'point', 'points' => [[0.5, 0.5]], 'lineId' => $this->wall->id]);
        $this->marks()->save(['siteId' => $this->site->id, 'sheetNo' => '703K-A01-01', 'shape' => 'note', 'points' => [[0.6, 0.6]], 'label' => '천장 개구부 확인']);
        $statuses = collect($this->marks()->sheet($this->site->id, '703K-A01-01')['marks'])->pluck('status')->all();
        $this->assertSame(['done', 'stored', 'plan', 'note'], $statuses);
    }

    public function test_bad_geometry_and_foreign_links_are_rejected(): void
    {
        $base = ['siteId' => $this->site->id, 'sheetNo' => '703K-A01-01', 'lineId' => $this->wall->id];
        $this->assertFalse($this->marks()->save($base + ['shape' => 'line', 'points' => [[0.1, 0.1]]])['success'], '선은 두 점 이상');
        $this->assertFalse($this->marks()->save($base + ['shape' => 'area', 'points' => [[0.1, 0.1], [0.2, 0.2]]])['success'], '영역은 세 점 이상');
        $this->assertFalse($this->marks()->save($base + ['shape' => 'point', 'points' => [[1.2, 0.1]]])['success'], '쪽 밖');
        $this->assertFalse($this->marks()->save($base + ['shape' => 'point', 'points' => 'x'])['success']);
        $this->assertFalse($this->marks()->save(['siteId' => $this->site->id, 'sheetNo' => '703K-A01-01', 'shape' => 'note', 'points' => [[0.1, 0.1]]])['success'], '빈 메모');

        $other = ContractBoqLine::where('line_no', '4')->firstOrFail();
        $recordOfWall = $this->record('installation', 5);
        $this->assertFalse($this->marks()->save(['lineId' => $other->id, 'recordId' => $recordOfWall, 'shape' => 'point', 'points' => [[0.1, 0.1]]] + $base)['success'], '다른 줄의 기록');

        $elsewhere = Site::create(['code' => 'X9', 'name' => 'Other', 'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active', 'company_id' => $this->company->id]);
        $this->assertFalse($this->marks()->save(['siteId' => $elsewhere->id, 'sheetNo' => 'A-1', 'shape' => 'point', 'points' => [[0.1, 0.1]], 'lineId' => $this->wall->id])['success'], '다른 현장의 줄');
        $this->assertSame(0, DrawingMark::count());
    }

    public function test_viewers_see_marks_but_cannot_add_and_cards_count_marks(): void
    {
        $this->marks()->save(['siteId' => $this->site->id, 'sheetNo' => '703K-A01-01', 'shape' => 'point', 'points' => [[0.3, 0.3]], 'lineId' => $this->wall->id]);
        $doc = IntelligentDocument::create(['uuid' => (string) Str::uuid(), 'source' => 'dropzone', 'disk' => 'local', 'file_path' => 'docs/a.pdf',
            'original_file_name' => 'a.pdf', 'stored_file_name' => 'a.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_size' => 1,
            'sha256' => hash('sha256', Str::random()), 'title' => 'a.pdf', 'received_at' => now(), 'ai_status' => 'ready',
            'site_id' => $this->site->id, 'company_id' => $this->company->id, 'category' => 'drawing_spec']);
        DrawingSheet::create(['intelligent_document_id' => $doc->id, 'site_id' => $this->site->id, 'page_no' => 2, 'sheet_no' => '703K-A01-01', 'status' => 'done']);

        $board = app(SectionDrawingService::class)->board((string) $this->site->id);
        $this->assertSame(1, ((array) $board['markCounts'])['703K-A01-01']);

        $this->actingAs(User::factory()->create(['access_role' => 'safety_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $sheet = $this->marks()->sheet($this->site->id, '703k-a01-01');
        $this->assertTrue($sheet['success']);
        $this->assertCount(1, $sheet['marks']);
        $this->assertSame(2, $sheet['sheet']['pageNo'], '도면 번호로 그 장을 찾는다');
        $this->assertStringContainsString('/documents/'.$doc->id.'/preview', $sheet['sheet']['pdfUrl']);
        $this->assertFalse($sheet['canManage']);
        $this->assertFalse($this->marks()->save(['siteId' => $this->site->id, 'sheetNo' => '703K-A01-01', 'shape' => 'note', 'points' => [[0.1, 0.1]], 'label' => 'x'])['success']);
        $this->assertFalse($this->marks()->delete(DrawingMark::firstOrFail()->id)['success']);
    }
}

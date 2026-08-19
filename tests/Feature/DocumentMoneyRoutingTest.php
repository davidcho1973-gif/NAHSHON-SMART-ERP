<?php

namespace Tests\Feature;

use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\Project;
use App\Models\Site;
use App\Services\Finance\DocumentExpenseConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 돈이 나간 문서는 문서함에서 끝나지 않는다.
 *
 * 영수증·인보이스·급여 지급 내역을 문서함에 올리면 AI 가 분류·편철까지는 했지만
 * 거기서 끝났다. 문서는 "정리완료" 인데 그 돈은 재무 어디에도 없어서, 같은 내용을
 * 재무 화면에 사람이 다시 입력해야 했다 — 두 번 일하거나, 안 하면 지출이 장부에서
 * 빠졌다.
 *
 * 원인은 분석이 금액을 구조화해서 뽑지 않은 것이다(문장 속에만 있었다). 그래서
 * 분석 스키마에 money 를 넣고, 분석 완료 지점 한 곳에서 돈이 나간 문서(flow=out)를
 * 경비 pending 으로 등록한다. 여기서 그 길이 계속 살아 있는지 지킨다.
 */
class DocumentMoneyRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function analyzedDocument(array $money, array $attributes = []): IntelligentDocument
    {
        return IntelligentDocument::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'source' => 'dropzone',
            'disk' => 'local',
            'file_path' => 'document-intelligence/inbox/'.Str::uuid().'/doc.pdf',
            'original_file_name' => '지급내역.pdf',
            'stored_file_name' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 100,
            'sha256' => hash('sha256', (string) Str::uuid()),
            'title' => '8월 1주 급여 지급 내역',
            'document_type' => 'payroll_record',
            'received_at' => now(),
            'ai_status' => 'ready',
            'ai_payload' => ['money' => $money],
            'document_date' => '2026-08-14',
        ], $attributes));
    }

    // ── 돈이 나간 문서가 재무에 닿는가 ─────────────────────────────────

    public function test_a_paid_document_lands_on_the_expense_ledger_as_pending(): void
    {
        $doc = $this->analyzedDocument([
            'flow' => 'out', 'amount' => 18420.55, 'currency' => 'USD',
            'paid_on' => '2026-08-15', 'payee' => 'ADP Payroll',
            'purpose' => '8월 1주 현장 급여', 'category_hint' => 'payroll',
        ]);

        app(DocumentExpenseConnector::class)->sync($doc);

        $expense = MobileExpense::query()->where('source_ref', "document:{$doc->id}")->first();

        $this->assertNotNull($expense, '급여 지급 내역이 재무로 넘어가지 않았습니다 — 문서함에서 끝났습니다.');
        $this->assertSame(18420.55, (float) $expense->amount);
        $this->assertSame('pending', $expense->status, '자동 등록은 사람 승인 전(pending)이어야 합니다.');
        $this->assertSame('5101 Gross Wages - Field', $expense->category);
        $this->assertSame('2026-08-15', $expense->expense_date instanceof \Carbon\CarbonInterface
            ? $expense->expense_date->toDateString() : (string) $expense->expense_date);
        $this->assertStringContainsString('문서함', (string) $expense->description);
    }

    public function test_the_site_and_project_ride_along_so_the_cost_has_an_owner(): void
    {
        $site = Site::create(['code' => 'S-DOC', 'name' => '문서 현장', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $project = Project::create([
            'project_code' => 'PRJ-DOC', 'name' => '문서 프로젝트',
            'site_id' => $site->id, 'construction_type' => 'mechanical',
        ]);

        $doc = $this->analyzedDocument(
            ['flow' => 'out', 'amount' => 500, 'category_hint' => 'materials'],
            ['site_id' => $site->id, 'project_id' => $project->id],
        );

        app(DocumentExpenseConnector::class)->sync($doc);

        $expense = MobileExpense::query()->where('source_ref', "document:{$doc->id}")->firstOrFail();
        $this->assertSame($site->id, $expense->site_id);
        $this->assertSame($project->id, $expense->project_id);
        $this->assertSame('5201 Materials & Supplies', $expense->category);
    }

    // ── 돈이 아닌/들어오는 문서는 건드리지 않는가 ──────────────────────

    public function test_incoming_money_is_not_recorded_as_an_expense(): void
    {
        // 우리가 발행한 기성청구는 받을 돈이다. 경비로 잡으면 수입이 지출로 둔갑한다.
        $doc = $this->analyzedDocument(['flow' => 'in', 'amount' => 250000, 'payee' => Str::random(8)]);

        app(DocumentExpenseConnector::class)->sync($doc);

        $this->assertSame(0, MobileExpense::query()->count());
    }

    public function test_a_document_with_no_money_creates_nothing(): void
    {
        $doc = $this->analyzedDocument(['flow' => 'none']);
        app(DocumentExpenseConnector::class)->sync($doc);

        $this->assertSame(0, MobileExpense::query()->count());
    }

    public function test_a_zero_amount_is_not_worth_a_ledger_row(): void
    {
        $doc = $this->analyzedDocument(['flow' => 'out', 'amount' => 0]);
        app(DocumentExpenseConnector::class)->sync($doc);

        $this->assertSame(0, MobileExpense::query()->count());
    }

    // ── 재분석해도 한 건인가 ───────────────────────────────────────────

    public function test_reanalyzing_the_document_does_not_duplicate_the_expense(): void
    {
        $doc = $this->analyzedDocument(['flow' => 'out', 'amount' => 900, 'category_hint' => 'fuel']);

        app(DocumentExpenseConnector::class)->sync($doc);
        app(DocumentExpenseConnector::class)->sync($doc);

        $this->assertSame(1, MobileExpense::query()->where('source_ref', "document:{$doc->id}")->count());
    }

    public function test_a_corrected_amount_updates_the_pending_row(): void
    {
        $doc = $this->analyzedDocument(['flow' => 'out', 'amount' => 900, 'category_hint' => 'fuel']);
        app(DocumentExpenseConnector::class)->sync($doc);

        $doc->update(['ai_payload' => ['money' => ['flow' => 'out', 'amount' => 950, 'category_hint' => 'fuel']]]);
        app(DocumentExpenseConnector::class)->sync($doc->fresh());

        $this->assertSame(950.0, (float) MobileExpense::query()
            ->where('source_ref', "document:{$doc->id}")->value('amount'));
    }

    public function test_an_approved_expense_is_never_overwritten_by_reanalysis(): void
    {
        // 장부 확정 후 소급 변경 금지 — 다른 커넥터들과 같은 원칙이다.
        $doc = $this->analyzedDocument(['flow' => 'out', 'amount' => 900, 'category_hint' => 'fuel']);
        app(DocumentExpenseConnector::class)->sync($doc);
        MobileExpense::query()->where('source_ref', "document:{$doc->id}")->update(['status' => 'approved']);

        $doc->update(['ai_payload' => ['money' => ['flow' => 'out', 'amount' => 1, 'category_hint' => 'fuel']]]);
        app(DocumentExpenseConnector::class)->sync($doc->fresh());

        $this->assertSame(900.0, (float) MobileExpense::query()
            ->where('source_ref', "document:{$doc->id}")->value('amount'));
    }

    // ── 영수증 원본이 경비 줄에 붙는가 ─────────────────────────────────

    public function test_the_original_file_rides_along_as_the_receipt(): void
    {
        // 파일이 문서함에만 있으면 경비 목록에 사진 버튼이 안 떠서,
        // 승인하는 사람이 근거를 못 보고 승인하게 된다.
        $disk = (string) config('document-intelligence.disk', 'local');
        \Illuminate\Support\Facades\Storage::fake($disk);
        $path = 'document-intelligence/inbox/'.Str::uuid().'/sunbelt.pdf';
        \Illuminate\Support\Facades\Storage::disk($disk)->put($path, '%PDF-1.4 sunbelt rental invoice');

        $doc = $this->analyzedDocument(
            ['flow' => 'out', 'amount' => 3186, 'payee' => 'Sunbelt Rentals', 'category_hint' => 'equipment'],
            ['disk' => $disk, 'file_path' => $path, 'original_file_name' => 'Sunbelt_186971353.pdf',
                'mime_type' => 'application/pdf', 'file_size' => 31],
        );

        app(DocumentExpenseConnector::class)->sync($doc);

        $expense = MobileExpense::query()->where('source_ref', "document:{$doc->id}")->firstOrFail();
        $this->assertSame('%PDF-1.4 sunbelt rental invoice',
            \App\Support\ReceiptFilePayload::decode($expense->receipt_file),
            '영수증 원본이 경비 줄에 안 붙었습니다 — 사진 버튼이 안 뜹니다.');
        $this->assertSame('application/pdf', $expense->receipt_mime_type);
        $this->assertSame('Sunbelt_186971353.pdf', $expense->receipt_original_name);
    }

    public function test_a_missing_file_still_registers_the_expense_without_a_receipt(): void
    {
        // 사본 실패가 경비 등록 자체를 막으면 안 된다 — 금액은 장부에 올라야 한다.
        $doc = $this->analyzedDocument(
            ['flow' => 'out', 'amount' => 500, 'category_hint' => 'materials'],
            ['file_path' => 'document-intelligence/inbox/없는/파일.pdf'],
        );

        app(DocumentExpenseConnector::class)->sync($doc);

        $expense = MobileExpense::query()->where('source_ref', "document:{$doc->id}")->firstOrFail();
        $this->assertNull($expense->receipt_file);
        $this->assertSame(500.0, (float) $expense->amount);
    }

    // ── 길 자체가 살아 있는가 ──────────────────────────────────────────

    public function test_the_analysis_pipeline_actually_calls_the_connector(): void
    {
        // 커넥터가 있어도 분석 완료 지점이 안 부르면 없는 것과 같다.
        $service = (string) file_get_contents(base_path('app/Services/Documents/DocumentIntelligenceService.php'));

        $this->assertStringContainsString('DocumentExpenseConnector', $service,
            '분석 완료 지점이 재무 커넥터를 부르지 않습니다 — 돈 문서가 다시 문서함에서 끝납니다.');
    }

    public function test_the_analyzer_asks_for_structured_money(): void
    {
        // 금액이 문장(key_facts) 속에만 있으면 코드가 집어갈 수 없다 — 그게 원인이었다.
        $analyzer = (string) file_get_contents(base_path('app/Services/Documents/DocumentIntelligenceAnalyzer.php'));

        foreach (["'money'", "'flow'", "'category_hint'"] as $needle) {
            $this->assertStringContainsString($needle, $analyzer,
                "분석 스키마에서 {$needle} 가 빠졌습니다 — 돈 추출이 다시 비구조화로 돌아갔습니다.");
        }

        $this->assertArrayHasKey('payroll_record', IntelligentDocument::TYPE_OPTIONS,
            '급여 지급 내역 문서 유형이 사라졌습니다.');
        $this->assertArrayHasKey('receipt', IntelligentDocument::TYPE_OPTIONS);
    }
}

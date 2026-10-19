<?php

namespace Tests\Feature;

use App\Models\ClaimEvidenceInboxItem;
use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\DocumentActionItem;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Communication\ChatDocumentReplyConnector;
use App\Services\Documents\DocumentCrossCheck;
use App\Services\Documents\DocumentIntelligenceAnalyzer;
use App\Services\Documents\DocumentIntelligenceService;
use App\Services\Documents\KnowledgeKeeper;
use App\Services\Equipment\DocumentEquipmentConnector;
use App\Services\Finance\BillingInflowConnector;
use App\Services\Finance\ClaimEvidenceInboxService;
use App\Services\Finance\DocumentExpenseConnector;
use App\Services\Ocr\OcrEngine;
use App\Support\EvidenceImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class EvidenceBatchClaimBridgeTest extends TestCase
{
    use RefreshDatabase;

    private ProjectContract $contract;

    private ContractBoqLine $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
        Storage::fake('local');
        config(['document-intelligence.disk' => 'local']);
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $company = Company::create(['code' => 'BATCH', 'name' => 'Batch company', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'BATCH', 'name' => 'Batch site', 'timezone' => 'UTC', 'status' => 'active']);
        $project = Project::create(['company_id' => $company->id, 'site_id' => $site->id, 'project_code' => 'BATCH', 'name' => 'Batch project', 'construction_type' => 'piping']);
        $this->contract = ProjectContract::create(['company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id,
            'title' => 'Accepted claim workbook', 'direction' => 'receivable', 'status' => 'active', 'original_amount' => 2000]);
        $this->line = ContractBoqLine::create(['project_contract_id' => $this->contract->id, 'line_no' => 'M1',
            'description' => 'Stainless pipe installation', 'spec' => '6 inch', 'unit' => 'LF', 'contract_qty' => 100, 'unit_price' => 20,
            'recognition_basis' => 'quantity', 'status' => 'accepted']);
    }

    public static function evidenceSources(): array
    {
        return [['evidence_batch'], ['claim_evidence']];
    }

    #[DataProvider('evidenceSources')]
    public function test_evidence_analysis_and_reanalysis_preserve_original_notes_without_any_business_effects(string $source): void
    {
        $history = ['history' => [['reviewed_by' => auth()->id(), 'note' => 'Reviewed original before this run']]];
        $document = $this->document(['source' => $source, 'ai_status' => 'queued', 'ai_payload' => ['evidence_review' => $history]]);
        $original = Storage::disk('local')->get($document->file_path);
        $existingAction = DocumentActionItem::create(['intelligent_document_id' => $document->id, 'action_type' => 'review', 'title' => 'Existing manual task']);
        $data = $this->analysisData();
        $data['evidence_review'] = ['history' => [['reviewed_by' => 99999, 'note' => 'AI cannot approve']]];
        $this->mock(DocumentIntelligenceAnalyzer::class)->shouldReceive('analyze')->twice()->andReturn([
            'data' => $data, 'extracted_text' => 'Printed original Stainless pipe 6 inch', 'engine' => 'test', 'model' => 'test',
        ]);
        $this->mock(DocumentCrossCheck::class)->shouldReceive('check')->twice()->andReturnNull();
        $this->prohibitIntegrations();

        $service = app(DocumentIntelligenceService::class);
        $first = $service->process($document);
        $result = $service->process($first);

        $this->assertSame($source, $result->source);
        $this->assertSame('ready', $result->ai_status);
        $this->assertSame($this->contract->id, $result->project_contract_id);
        $this->assertSame(120, $result->ai_payload['money']['amount']);
        $this->assertSame($history, $result->ai_payload['evidence_review']);
        $this->assertSame($original, Storage::disk('local')->get($result->file_path));
        $this->assertSame(hash('sha256', $original), $result->sha256);
        $this->assertStringStartsWith('document-intelligence/library/', $result->file_path);
        $this->assertStringContainsString('[인쇄 내용]', $result->extracted_text);
        $this->assertStringContainsString('[수기 메모 · 인쇄 내용과 구분] 703K 배관팀 / 개인카드', $result->extracted_text);
        $this->assertStringContainsString('[공정 후보 · 확정 아님] speculative-process', $result->search_text);
        $this->assertStringNotContainsString('speculative-process', $result->extracted_text);
        $this->assertDatabaseHas('document_action_items', ['id' => $existingAction->id, 'title' => 'Existing manual task']);
        $this->assertDatabaseCount('document_action_items', 1);
        foreach (['mobile_expenses', 'billing_receipts', 'claim_work_records', 'pay_applications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $meta = app(ClaimEvidenceInboxService::class)->resolveSource($this->contract, 'document', $result->id);
        $this->assertSame(['printed-fact:1', 'handwritten-note:1'], array_column($meta['items'], 'locator'));
        $this->assertStringNotContainsString('speculative-process', $meta['text']);
    }

    public function test_unresolved_original_conflict_survives_reanalysis_and_blocks_claim_linking(): void
    {
        $document = $this->document(['ai_status' => 'review_required', 'ai_payload' => ['source_review_reason' => 'Printed total conflicts with handwritten total']]);
        $this->mock(DocumentIntelligenceAnalyzer::class)->shouldReceive('analyze')->once()->andReturn([
            'data' => $this->analysisData(), 'extracted_text' => 'Stainless pipe 6 inch', 'engine' => 'test', 'model' => 'test',
        ]);
        $this->mock(DocumentCrossCheck::class)->shouldReceive('check')->once()->andReturnNull();
        $this->prohibitIntegrations();
        $this->mock(OcrEngine::class)->shouldNotReceive('analyze');

        $result = app(DocumentIntelligenceService::class)->process($document);
        $service = app(ClaimEvidenceInboxService::class);
        $matched = $service->match($this->contract->id, [$result->id]);

        $this->assertSame('review_required', $result->ai_status);
        $this->assertSame('Printed total conflicts with handwritten total', $result->ai_payload['source_review_reason']);
        $this->assertTrue($matched['success']);
        $this->assertSame(0, $matched['linked']);
        $item = ClaimEvidenceInboxItem::where('source_id', $result->id)->firstOrFail();
        $this->assertSame('review', $item->status);
        $this->assertFalse($service->review(['id' => $item->id, 'action' => 'link', 'lineIds' => [$this->line->id]])['success']);
    }

    // This verifies persistence of a real compressed copy. Keep the intentionally
    // memory-sensitive decoder independent of allocations from earlier tests.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_private_derivative_is_verified_and_smaller_while_original_hash_is_retained(): void
    {
        $image = imagecreatetruecolor(1000, 1000);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image, null, 0);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        $copy = EvidenceImage::prepare($bytes, 'image/png');
        $this->assertTrue($copy['resized']);
        $document = $this->document(['mime_type' => 'image/png', 'extension' => 'png', 'extracted_text' => null], $bytes);
        $data = $this->analysisData() + ['image_derivative' => ['disk' => 'public', 'path' => '../../untrusted.jpg']];
        $this->mock(DocumentIntelligenceAnalyzer::class)->shouldReceive('analyze')->once()->andReturn([
            'data' => $data, 'image_derivative' => $copy, 'extracted_text' => null, 'engine' => 'test', 'model' => 'test',
        ]);
        $this->mock(DocumentCrossCheck::class)->shouldReceive('check')->once()->andReturnNull();
        $this->prohibitIntegrations();

        $result = app(DocumentIntelligenceService::class)->process($document);
        $derivative = $result->ai_payload['image_derivative'];

        $this->assertSame('local', $derivative['disk']);
        // Windows does not expose POSIX mode bits; the production Linux suite verifies them.
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame('private', Storage::disk('local')->getVisibility($derivative['path']));
        }
        $this->assertStringStartsWith('document-intelligence/derivatives/'.$document->uuid.'/', $derivative['path']);
        $this->assertArrayNotHasKey('data', $derivative);
        $this->assertSame($copy['data'], Storage::disk('local')->get($derivative['path']));
        $this->assertSame($bytes, Storage::disk('local')->get($result->file_path));
        $this->assertSame($result->sha256, $derivative['original_sha256']);
        $this->assertLessThan(strlen($bytes), $derivative['size']);
    }

    public function test_independent_reading_disagreement_requires_review_and_changed_original_cannot_be_analyzed(): void
    {
        $document = $this->document();
        $this->mock(DocumentIntelligenceAnalyzer::class)->shouldReceive('analyze')->once()->andReturn([
            'data' => $this->analysisData(), 'extracted_text' => 'Stainless pipe 6 inch', 'engine' => 'test', 'model' => 'test',
        ]);
        $this->mock(DocumentCrossCheck::class)->shouldReceive('check')->once()->andReturn(['status' => 'disagreed', 'disagreements' => ['amount']]);
        $this->prohibitIntegrations();
        $result = app(DocumentIntelligenceService::class)->process($document);
        $this->assertSame('review_required', $result->ai_status);
        $this->assertStringContainsString('교차검증', $result->ai_payload['source_review_reason']);

        Storage::disk('local')->put($result->file_path, 'Changed content after intake');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('접수 당시와 다릅니다');
        app(DocumentIntelligenceService::class)->process($result);
    }

    public function test_filtered_matching_touches_only_selected_visible_documents_and_keeps_other_backlogs_intact(): void
    {
        $selected = $this->document();
        $old = $this->document();
        $private = $this->document(['access_level' => 'private', 'owner_user_id' => auth()->id()]);
        $other = $this->contract->replicate();
        $other->internal_reference = null;
        $other->save();
        $foreign = $this->document(['project_contract_id' => $other->id]);
        $expense = MobileExpense::withoutEvents(fn () => MobileExpense::create([
            'site_id' => $this->contract->site_id, 'company_id' => $this->contract->company_id,
            'project_id' => $this->contract->project_id, 'description' => 'Stainless pipe backlog', 'amount' => 120, 'category' => 'materials',
            'expense_date' => '2026-10-10', 'status' => 'pending', 'payment_type' => 'corporate',
        ]));
        $oldItem = ClaimEvidenceInboxItem::create(['project_contract_id' => $this->contract->id, 'source_type' => 'document',
            'source_id' => $old->id, 'status' => 'review', 'reason' => 'Do not touch', 'line_ids' => [], 'locators' => []]);
        $oldState = $oldItem->fresh()->toJson();
        $privateItem = ClaimEvidenceInboxItem::create(['project_contract_id' => $this->contract->id, 'source_type' => 'document',
            'source_id' => $private->id, 'status' => 'review', 'reason' => 'Revoked source must remain untouched', 'line_ids' => [], 'locators' => []]);
        $privateState = $privateItem->fresh()->toJson();
        $this->mock(OcrEngine::class)->shouldReceive('analyze')->once()->andReturnUsing(function ($images, $prompt) use ($selected): array {
            $input = json_decode(explode('DATA: ', $prompt, 2)[1], true);
            $this->assertCount(1, $input['sources']);
            $item = ClaimEvidenceInboxItem::where('source_type', 'document')->where('source_id', $selected->id)->firstOrFail();
            $this->assertSame($item->id, $input['sources'][0]['id']);

            return ['data' => ['matches' => [['source_id' => $item->id, 'line_id' => $this->line->id,
                'confidence' => .99, 'quote' => 'Stainless pipe 6 inch', 'locator' => 'original']]]];
        });

        $service = app(ClaimEvidenceInboxService::class);
        $result = $service->match($this->contract->id, [$selected->id, $private->id, $foreign->id]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['linked']);
        $this->assertSame($oldState, $oldItem->fresh()->toJson());
        $this->assertSame($privateState, $privateItem->fresh()->toJson());
        $this->assertDatabaseCount('claim_evidence_inbox_items', 3);
        $this->assertDatabaseMissing('claim_evidence_inbox_items', ['source_type' => 'expense', 'source_id' => $expense->id]);
        $this->assertSame(0, $service->match($this->contract->id, [])['linked']);
        $this->assertFalse($service->match($this->contract->id, range(1, 21))['success']);
        $this->assertFalse($service->match($this->contract->id, ['invalid'])['success']);
        $this->assertDatabaseCount('claim_evidence_inbox_items', 3);
        $this->assertDatabaseCount('claim_work_records', 0);
        $this->assertDatabaseCount('pay_applications', 0);
        $this->assertSame('100.0000', $this->line->fresh()->contract_qty);
        $this->assertSame('pending', $expense->fresh()->status);
        $this->assertEmpty($this->contract->fresh()->payload['daily_evidence_automation'] ?? null);
    }

    private function prohibitIntegrations(): void
    {
        foreach ([DocumentExpenseConnector::class, BillingInflowConnector::class, DocumentEquipmentConnector::class, ChatDocumentReplyConnector::class] as $connector) {
            $this->mock($connector)->shouldNotReceive('sync');
        }
        $this->mock(KnowledgeKeeper::class)->shouldNotReceive('harvest');
    }

    private function analysisData(): array
    {
        return ['title' => 'Mixed historical evidence', 'document_type' => 'invoice', 'category' => 'finance', 'document_date' => '2026-10-10', 'confidence' => 99,
            'money' => ['flow' => 'out', 'amount' => 120], 'equipment' => ['involved' => 'purchase'],
            'action_items' => [['title' => 'AI task must not be executed', 'severity' => 'critical']],
            'evidence' => ['kind' => 'purchase', 'printed_facts' => [['field' => 'item', 'value' => 'Stainless pipe 6 inch', 'source_excerpt' => 'Stainless pipe 6 inch', 'location' => 'page 1']],
                'handwritten_notes' => [['text' => '703K 배관팀 / 개인카드', 'location' => 'margin', 'confidence' => 98, 'uncertain' => false]],
                'conflicts' => [], 'process_hints' => ['speculative-process']]];
    }

    private function document(array $extra = [], ?string $bytes = null): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        $bytes ??= 'Stainless pipe 6 inch '.$uuid;
        $path = 'document-intelligence/inbox/'.$uuid.'/evidence.txt';
        Storage::disk('local')->put($path, $bytes);

        return IntelligentDocument::create(array_merge([
            'uuid' => $uuid, 'company_id' => $this->contract->company_id, 'site_id' => $this->contract->site_id,
            'project_id' => $this->contract->project_id, 'project_contract_id' => $this->contract->id,
            'uploaded_by' => auth()->id(), 'disk' => 'local', 'file_path' => $path, 'source' => 'evidence_batch',
            'original_file_name' => 'evidence.txt', 'stored_file_name' => 'evidence.txt', 'mime_type' => 'text/plain', 'extension' => 'txt',
            'file_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'title' => 'Stainless pipe delivery',
            'extracted_text' => $bytes, 'document_date' => '2026-10-10', 'received_at' => now(), 'ai_status' => 'ready',
            'access_level' => 'shared', 'confidentiality' => 'internal', 'status' => 'approved', 'document_type' => 'delivery_ticket',
        ], $extra));
    }
}

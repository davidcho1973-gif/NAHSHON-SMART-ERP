<?php

namespace Tests\Feature;

use App\Models\ClaimEditDraft;
use App\Models\ClaimWorkRecord;
use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\DrawingSheet;
use App\Models\IntelligentDocument;
use App\Models\PayApplication;
use App\Models\PayApplicationAllocation;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\BillingAdminService;
use App\Services\Finance\ClaimEditingService;
use App\Services\Finance\ClaimEvidenceService;
use App\Services\Ocr\OcrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PreparesClaimSubmissions;
use Tests\TestCase;

class ClaimEvidenceTest extends TestCase
{
    use PreparesClaimSubmissions;
    use RefreshDatabase;

    private ProjectContract $contract;

    private IntelligentDocument $document;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 9, 23));
        $company = Company::create(['code' => 'EVIDENCE', 'name' => 'Evidence Company', 'status' => 'active']);
        $counterparty = Company::create(['code' => 'GC', 'name' => 'GC', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'KITCHEN', 'name' => 'Kitchen', 'status' => 'active']);
        $project = Project::create(['company_id' => $company->id, 'site_id' => $site->id, 'project_code' => 'KITCHEN-26', 'name' => 'Kitchen', 'construction_type' => 'equipment_setting']);
        $this->contract = ProjectContract::create(['company_id' => $company->id, 'counterparty_company_id' => $counterparty->id, 'site_id' => $site->id, 'project_id' => $project->id, 'title' => 'Kitchen contract', 'direction' => 'receivable', 'status' => 'active', 'original_amount' => 100000, 'retainage_percent' => 5]);
        $this->actor = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($this->actor);
        $uuid = (string) Str::uuid();
        $this->document = IntelligentDocument::create(['uuid' => $uuid, 'company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id, 'project_contract_id' => $this->contract->id, 'uploaded_by' => $this->actor->id, 'disk' => 'local', 'file_path' => 'test/'.$uuid.'.pdf', 'original_file_name' => 'Contract.pdf', 'stored_file_name' => $uuid.'.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 10, 'sha256' => hash('sha256', $uuid), 'title' => 'Contract / inspection evidence', 'received_at' => now(), 'ai_status' => 'ready', 'access_level' => 'shared']);
        Storage::disk('local')->put($this->document->file_path, $uuid);
    }

    private function svc(): ClaimEvidenceService
    {
        return app(ClaimEvidenceService::class);
    }

    public function test_process_requirements_block_verification_and_new_claims_until_matched(): void
    {
        $line = $this->line();
        $record = $this->record($line);
        $this->assertTrue($this->svc()->saveRequirements(['lineId' => $line->id, 'requirements' => [['type' => 'inspection', 'stage' => 'installed', 'count' => 1]]])['success']);
        $review = $this->svc()->reviewRecord(['id' => $record->id, 'action' => 'verify', 'verifiedQty' => 15, 'reviewNote' => 'Measured']);
        $this->assertFalse($review['success']);
        $this->assertStringContainsString('1건 필요', $review['error']);
        $this->document->update(['document_type' => 'inspection']);
        $this->verify($record);
        $this->assertTrue($this->svc()->saveRequirements(['lineId' => $line->id, 'requirements' => [['type' => 'inspection', 'stage' => '', 'count' => 2]]])['success']);
        $this->assertFalse($this->svc()->draft($this->contract->id, '2026-09-30')['success']);
        $this->assertCount(2, $this->contract->fresh()->payload['claimRequirementHistory']);
    }

    public function test_evidence_search_preserves_scope_and_can_find_older_documents(): void
    {
        $this->document->update(['title' => 'Old 100% evidence']);
        $result = $this->svc()->searchSources($this->contract->id, '100%');
        $this->assertTrue($result['success']);
        $this->assertContains('document:'.$this->document->id, array_column($result['sourceOptions'], 'value'));
        $this->document->update(['access_level' => 'private']);
        $this->assertSame([], $this->svc()->searchSources($this->contract->id, '100%')['sourceOptions']);
    }

    public function test_marked_drawing_is_scoped_ready_evidence_not_verified_work(): void
    {
        $line = $this->line();
        config(['document-intelligence.disk' => 'local']);
        Queue::fake();
        $file = UploadedFile::fake()->image('marked.jpg', 100, 100);
        $editor = app(ClaimEditingService::class);
        $result = $editor->saveDrawingImage(['lineId' => $line->id, 'sheetNo' => 'A-1', 'image' => 'data:image/jpeg;base64,'.base64_encode($file->getContent())]);
        $this->assertTrue($result['success'], $result['error'] ?? '');
        $doc = IntelligentDocument::findOrFail($result['id']);
        $this->assertEquals($this->contract->id, $doc->project_contract_id);
        $this->assertSame('ready', $doc->ai_status);
        $this->assertSame('drawing', $doc->document_type);
        $this->assertSame(0, ClaimWorkRecord::count());
        $this->assertFalse($editor->saveDrawingImage(['lineId' => $line->id, 'image' => 'data:image/jpeg;base64,not-an-image'])['success']);
        Queue::assertNothingPushed();
    }

    public function test_edit_draft_calculation_commits_once_without_verifying_or_allocating(): void
    {
        $line = $this->line();
        $editor = app(ClaimEditingService::class);
        $content = ['workDate' => '2026-09-23', 'location' => 'North wall', 'stage' => 'installed',
            'calculations' => [['length' => 2, 'height' => 3, 'count' => 1, 'deduction' => 1]],
            'evidence' => [['type' => 'document', 'id' => $this->document->id, 'locator' => 'p.1']]];
        $saved = $editor->save(['lineId' => $line->id, 'revision' => 0, 'content' => $content]);
        $this->assertTrue($saved['success'], $saved['error'] ?? '');
        $this->assertSame(0, ClaimWorkRecord::count());
        $this->assertFalse($editor->save(['lineId' => $line->id, 'revision' => 0, 'content' => $content])['success']);
        $committed = $editor->commit(['lineId' => $line->id, 'revision' => $saved['draft']['revision']]);
        $this->assertTrue($committed['success'], $committed['error'] ?? '');
        $again = $editor->commit(['lineId' => $line->id, 'revision' => $saved['draft']['revision']]);
        $this->assertSame($committed['id'], $again['id']);
        $record = ClaimWorkRecord::findOrFail($committed['id']);
        $this->assertSame('pending', $record->status);
        $this->assertEquals(5, $record->reported_qty);
        $this->assertNull($record->verified_qty);
        $this->assertSame(0, PayApplicationAllocation::count());
        $this->assertSame(1, ClaimWorkRecord::count());
    }

    public function test_edit_drafts_are_private_to_the_editor_and_reject_foreign_sources(): void
    {
        $line = $this->line();
        $editor = app(ClaimEditingService::class);
        $this->assertTrue($editor->save(['lineId' => $line->id, 'content' => ['location' => 'Private draft']])['success']);
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $this->assertSame([], $editor->workspace($this->contract->id)['drafts']);
        $this->assertFalse($editor->save(['lineId' => $line->id, 'content' => ['evidence' => [['type' => 'document', 'id' => 999999]]]])['success']);
    }

    public function test_drawing_ai_returns_a_proposal_without_creating_work_or_trusting_source_ids(): void
    {
        $line = $this->line();
        $sheet = DrawingSheet::create(['intelligent_document_id' => $this->document->id, 'site_id' => $this->contract->site_id, 'page_no' => 1, 'sheet_no' => 'A-1', 'text' => 'Wall 2 x 3 feet']);
        $this->mock(OcrEngine::class)->shouldReceive('analyze')->once()->andReturn(['model' => 'test', 'data' => [
            'answer' => '치수 확인 필요', 'caution' => '실제 시공 확인 전', 'sources' => ['document:999999', 'document:'.$this->document->id],
            'calculations' => [['label' => 'Wall', 'length' => 2, 'height' => 3, 'count' => 1, 'deduction' => 1]],
        ]]);
        $result = app(ClaimEditingService::class)->ask(['lineId' => $line->id, 'sheetId' => $sheet->id, 'question' => '면적 근거는?']);
        $this->assertTrue($result['success']);
        $this->assertSame(5.0, $result['proposal']['quantity']);
        $this->assertSame(['document:'.$this->document->id], $result['proposal']['sources']);
        $this->assertSame(0, ClaimWorkRecord::count());
        $this->assertSame(0, ClaimEditDraft::count());
        $this->document->update(['access_level' => 'private']);
        $this->assertFalse(app(ClaimEditingService::class)->ask(['lineId' => $line->id, 'sheetId' => $sheet->id, 'question' => '면적?'])['success']);
    }

    public function test_changed_evidence_does_not_commit_work(): void
    {
        $line = $this->line();
        $editor = app(ClaimEditingService::class);
        $content = ['workDate' => '2026-09-23', 'location' => 'North', 'quantity' => 5, 'stage' => 'installed', 'evidence' => [['type' => 'document', 'id' => $this->document->id, 'locator' => 'p1']]];
        $saved = $editor->save(['lineId' => $line->id, 'content' => $content]);
        $this->document->update(['sha256' => hash('sha256', 'replacement')]);
        $this->assertFalse($editor->commit(['lineId' => $line->id, 'revision' => $saved['draft']['revision']])['success']);
        $this->assertSame(0, ClaimWorkRecord::count());
    }

    public function test_bulk_edit_rolls_back_all_changes_when_one_record_is_verified(): void
    {
        $line = $this->line();
        $first = $this->record($line);
        $second = $this->svc()->saveRecord(['lineId' => $line->id, 'recordKind' => 'actual', 'workDate' => '2026-09-23', 'location' => 'Second wall', 'stage' => 'installed', 'reportedQty' => 3]);
        $this->verify($first);
        $result = app(ClaimEditingService::class)->bulk(['contractId' => $this->contract->id, 'ids' => [$second['id'], $first->id], 'changes' => ['location' => 'Changed']]);
        $this->assertFalse($result['success']);
        $this->assertSame('Second wall', ClaimWorkRecord::findOrFail($second['id'])->location);
    }

    public function test_actual_work_uses_the_site_day_instead_of_the_server_day(): void
    {
        $this->contract->site->update(['timezone' => 'America/New_York']);
        $this->travelTo(Carbon::parse('2026-09-24T02:00:00Z'));
        $line = $this->line();
        $input = ['lineId' => $line->id, 'recordKind' => 'actual', 'location' => 'East', 'stage' => 'installed', 'reportedQty' => 1];
        $this->assertTrue($this->svc()->saveRecord($input + ['workDate' => '2026-09-23'])['success']);
        $this->assertFalse($this->svc()->saveRecord($input + ['workDate' => '2026-09-24'])['success']);
        $this->assertSame('2026-09-23', $this->svc()->getLedger($this->contract->id)['contract']['today']);
    }

    private function line(array $extra = []): ContractBoqLine
    {
        $result = $this->svc()->saveLine(array_merge(['projectContractId' => $this->contract->id, 'lineNo' => '15', 'description' => 'Pipe', 'unit' => 'm', 'contractQty' => 100, 'unitPrice' => 9.99, 'recognitionBasis' => 'quantity', 'status' => 'accepted', 'acceptanceNote' => 'Signed contract quantity and rate checked', 'sourceDocumentId' => $this->document->id, 'sourceLocator' => 'Contract p.5 line 15'], $extra));
        $this->assertTrue($result['success'], $result['error'] ?? '');

        return ContractBoqLine::findOrFail($result['id']);
    }

    private function record(ContractBoqLine $line, array $extra = []): ClaimWorkRecord
    {
        $result = $this->svc()->saveRecord(array_merge(['lineId' => $line->id, 'recordKind' => 'actual', 'workDate' => '2026-09-20', 'location' => 'Kitchen east wall', 'stage' => 'installed', 'reportedQty' => 20, 'evidence' => [['type' => 'document', 'id' => $this->document->id, 'locator' => 'Inspection p.2']]], $extra));
        $this->assertTrue($result['success'], $result['error'] ?? '');

        return ClaimWorkRecord::findOrFail($result['id']);
    }

    private function verify(ClaimWorkRecord $record, float $qty = 15): void
    {
        $result = $this->svc()->reviewRecord(['id' => $record->id, 'action' => 'verify', 'verifiedQty' => $qty, 'reviewNote' => 'Measured and proof checked']);
        $this->assertTrue($result['success'], $result['error'] ?? '');
    }

    public function test_partial_confirmation_reserves_once_and_freezes_real_terms(): void
    {
        $line = $this->line();
        $record = $this->record($line);
        $this->verify($record);
        $ledger = $this->svc()->getLedger($this->contract->id);
        $this->assertEquals(5, $ledger['lines'][0]['unverifiedQty']);
        $first = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertTrue($first['success'], $first['error'] ?? '');
        $this->assertSame(149.85, $first['thisPeriodAmount']);
        $second = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertTrue($second['success'], $second['error'] ?? '');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, PayApplication::count());
        $this->assertSame(1, PayApplicationAllocation::count());
        $packet = $this->svc()->getPacket($first['id']);
        $this->assertSame(9.99, $packet['allocations'][0]['snapshot']['line']['unitPrice']);
        $this->assertSame(15.0, $packet['allocations'][0]['quantity']);
        $this->assertSame($this->document->sha256, $packet['allocations'][0]['snapshot']['evidence'][0]['sha256']);
        $this->assertFalse($this->svc()->saveLine(['id' => $line->id, 'unitPrice' => 100])['success']);
        $this->assertFalse($this->svc()->reviewRecord(['id' => $record->id, 'action' => 'reopen', 'reviewNote' => 'Change'])['success']);
        $this->assertFalse(app(BillingAdminService::class)->saveBilling(['id' => $first['id'], 'periodEnd' => '2026-09-30', 'thisPeriodAmount' => 999])['success']);
    }

    public function test_submitted_work_cannot_be_claimed_again_or_revised(): void
    {
        $record = $this->record($this->line());
        $this->verify($record);
        $draft = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertTrue($draft['success'], $draft['error'] ?? '');
        $submit = $this->submitClaimFixture($draft['id']);
        $this->assertTrue($submit['success'], $submit['error'] ?? '');
        $this->assertFalse($this->svc()->draft($this->contract->id, '2026-10-31')['success']);
        $this->assertFalse(app(BillingAdminService::class)->setBillingStatus(['id' => $draft['id'], 'action' => 'withdraw'])['success']);
        $this->assertTrue($this->svc()->getPacket($draft['id'])['immutable']);
        $this->assertSame(1, PayApplicationAllocation::count());
    }

    public function test_source_claim_and_forecast_are_never_verified_or_converted_to_actual(): void
    {
        $line = $this->line();
        foreach (['source_claim', 'forecast'] as $kind) {
            $record = $this->record($line, ['recordKind' => $kind, 'workDate' => '2026-09-30']);
            $this->assertFalse($this->svc()->reviewRecord(['id' => $record->id, 'action' => 'verify', 'verifiedQty' => 20, 'reviewNote' => 'Review'])['success']);
            $this->assertFalse($this->svc()->saveRecord(['id' => $record->id, 'recordKind' => 'actual'])['success']);
        }
        $this->assertFalse($this->svc()->draft($this->contract->id, '2026-09-30')['success']);
        $this->assertSame(0, PayApplication::count());
    }

    public function test_unaccepted_terms_missing_proof_future_dates_and_excess_quantity_are_blocked(): void
    {
        $line = $this->line(['status' => 'draft']);
        $record = $this->record($line, ['evidence' => []]);
        $this->assertFalse($this->svc()->reviewRecord(['id' => $record->id, 'action' => 'verify', 'verifiedQty' => 15, 'reviewNote' => 'Review'])['success']);
        $this->assertTrue($this->svc()->saveLine(['id' => $line->id, 'status' => 'accepted'])['success']);
        $this->assertFalse($this->svc()->reviewRecord(['id' => $record->id, 'action' => 'verify', 'verifiedQty' => 15, 'reviewNote' => 'Review'])['success']);
        $this->assertFalse($this->svc()->saveRecord(['lineId' => $line->id, 'recordKind' => 'actual', 'workDate' => '2026-09-30', 'location' => 'Kitchen', 'reportedQty' => 20])['success']);
        $this->assertFalse($this->svc()->saveRecord(['lineId' => $line->id, 'recordKind' => 'actual', 'workDate' => '2026-09-20', 'location' => 'Kitchen', 'reportedQty' => 101])['success']);
        $valid = $this->record($line, ['reportedQty' => 80]);
        $this->verify($valid, 80);
        $other = $this->record($line, ['reportedQty' => 30]);
        $this->assertFalse($this->svc()->reviewRecord(['id' => $other->id, 'action' => 'verify', 'verifiedQty' => 30, 'reviewNote' => 'Review'])['success']);
    }

    public function test_milestone_values_partition_contract_and_do_not_double_count_installation(): void
    {
        $line = $this->line(['contractQty' => 1, 'unitPrice' => 1000, 'recognitionBasis' => 'milestone', 'stageWeights' => ['fabrication' => 80, 'installation' => 20]]);
        $fab = $this->record($line, ['stage' => 'fabrication', 'reportedQty' => 1]);
        $install = $this->record($line, ['stage' => 'installation', 'reportedQty' => 1]);
        $this->verify($fab, 1);
        $this->verify($install, 1);
        $draft = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertTrue($draft['success'], $draft['error'] ?? '');
        $this->assertSame(1000.0, $draft['thisPeriodAmount']);
        $this->assertSame([800.0, 200.0], PayApplicationAllocation::orderBy('id')->get()->map(fn ($r) => (float) $r->amount)->all());
        $this->assertNull($this->svc()->validateApplication(PayApplication::find($draft['id'])));
    }

    public function test_split_measurements_do_not_create_rounding_money(): void
    {
        $line = $this->line(['contractQty' => 3, 'unitPrice' => 0.005]);
        for ($i = 0; $i < 3; $i++) {
            $this->verify($this->record($line, ['reportedQty' => 1, 'location' => 'Segment '.$i]), 1);
        }
        $this->assertSame(0.02, $this->svc()->getLedger($this->contract->id)['summary']['availableAmount']);
        $draft = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertTrue($draft['success'], $draft['error'] ?? '');
        $this->assertSame(0.02, $draft['thisPeriodAmount']);
        $this->assertNull($this->svc()->validateApplication(PayApplication::find($draft['id'])));
    }

    public function test_milestone_rounding_is_cumulative_per_contract_line(): void
    {
        $line = $this->line(['contractQty' => 1, 'unitPrice' => 0.02, 'recognitionBasis' => 'milestone', 'stageWeights' => ['fabrication' => 33, 'delivery' => 33, 'installation' => 34]]);
        foreach (['fabrication', 'delivery', 'installation'] as $stage) {
            $this->verify($this->record($line, ['stage' => $stage, 'reportedQty' => 1]), 1);
        }
        $this->assertSame(0.02, $this->svc()->getLedger($this->contract->id)['summary']['availableAmount']);
        $draft = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertTrue($draft['success'], $draft['error'] ?? '');
        $this->assertSame(0.02, $draft['thisPeriodAmount']);
        $this->assertSame([0.01, 0.0, 0.01], PayApplicationAllocation::orderBy('id')->get()->map(fn ($r) => (float) $r->amount)->all());
        $this->assertNull($this->svc()->validateApplication(PayApplication::find($draft['id'])));
        $refresh = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertTrue($refresh['success'], $refresh['error'] ?? '');
        $this->assertSame(0.02, $refresh['thisPeriodAmount']);
        $this->assertSame([0.01, 0.0, 0.01], PayApplicationAllocation::orderBy('id')->get()->map(fn ($r) => (float) $r->amount)->all());
        $this->assertSame(0.0, $this->svc()->getLedger($this->contract->id)['summary']['availableAmount']);
    }

    public function test_later_milestones_keep_submitted_packet_cents_unchanged(): void
    {
        $line = $this->line(['contractQty' => 1, 'unitPrice' => 0.02, 'recognitionBasis' => 'milestone', 'stageWeights' => ['fabrication' => 33, 'delivery' => 33, 'installation' => 34]]);
        $this->verify($this->record($line, ['stage' => 'fabrication', 'reportedQty' => 1]), 1);
        $first = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertTrue($first['success'], $first['error'] ?? '');
        $this->assertSame(0.01, $first['thisPeriodAmount']);
        $submit = $this->submitClaimFixture($first['id']);
        $this->assertTrue($submit['success'], $submit['error'] ?? '');
        $firstSnapshot = $this->svc()->getPacket($first['id']);
        foreach (['delivery', 'installation'] as $stage) {
            $this->verify($this->record($line, ['stage' => $stage, 'reportedQty' => 1]), 1);
        }
        $this->assertSame(0.01, $this->svc()->getLedger($this->contract->id)['summary']['availableAmount']);
        $second = $this->svc()->draft($this->contract->id, '2026-10-31');
        $this->assertTrue($second['success'], $second['error'] ?? '');
        $this->assertSame(0.01, $second['thisPeriodAmount']);
        $this->assertSame(0.02, (float) PayApplicationAllocation::sum('amount'));
        $this->assertNull($this->svc()->validateApplication(PayApplication::find($second['id'])));
        $this->assertSame($firstSnapshot, $this->svc()->getPacket($first['id']));
    }

    public function test_contract_scope_cross_contract_evidence_and_private_documents_are_enforced(): void
    {
        $line = $this->line();
        $other = Site::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $scoped = User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_site_id' => $other->id, 'account_status' => 'active']);
        $this->actingAs($scoped);
        $this->assertFalse($this->svc()->getLedger($this->contract->id)['success']);
        $this->assertFalse($this->svc()->saveLine(['id' => $line->id, 'unitPrice' => 1])['success']);
        $this->actingAs($this->actor);
        $foreign = $this->document->replicate(['uuid', 'sha256']);
        $foreign->uuid = (string) Str::uuid();
        $foreign->sha256 = hash('sha256', $foreign->uuid);
        $foreign->project_contract_id = null;
        $foreign->project_id = null;
        $foreign->site_id = $other->id;
        $foreign->save();
        $payload = ['lineId' => $line->id, 'recordKind' => 'actual', 'workDate' => '2026-09-20', 'location' => 'Kitchen', 'reportedQty' => 10, 'evidence' => [['type' => 'document', 'id' => $foreign->id, 'locator' => 'p.1']]];
        $this->assertFalse($this->svc()->saveRecord($payload)['success']);
        $this->document->update(['access_level' => 'private', 'owner_user_id' => $this->actor->id]);
        $payload['evidence'][0]['id'] = $this->document->id;
        $this->assertFalse($this->svc()->saveRecord($payload)['success']);
        $this->assertSame([], $this->svc()->getLedger($this->contract->id)['sourceDocumentOptions']);
    }

    public function test_historical_manual_claims_require_opening_balance_reconciliation(): void
    {
        PayApplication::create(['project_contract_id' => $this->contract->id, 'company_id' => $this->contract->company_id, 'site_id' => $this->contract->site_id, 'project_id' => $this->contract->project_id, 'status' => 'submitted', 'period_end' => '2026-08-31', 'this_period_amount' => 50, 'cumulative_amount' => 50]);
        $this->verify($this->record($this->line()));
        $result = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('대사', $result['error']);
        $this->assertSame(1, PayApplication::count());
    }

    public function test_invalid_numbers_dates_and_duplicate_source_are_rejected(): void
    {
        $line = $this->line();
        foreach ([INF, NAN, -1, 'text', '1.00001'] as $qty) {
            $this->assertFalse($this->svc()->saveRecord(['lineId' => $line->id, 'workDate' => '2026-09-20', 'location' => 'Kitchen', 'reportedQty' => $qty])['success']);
        }
        $this->assertFalse($this->svc()->saveRecord(['lineId' => $line->id, 'workDate' => '2026-02-30', 'location' => 'Kitchen', 'reportedQty' => 1])['success']);
        $this->record($line, ['sourceRef' => 'intake:123']);
        $this->assertFalse($this->svc()->saveRecord(['lineId' => $line->id, 'workDate' => '2026-09-20', 'location' => 'Kitchen', 'reportedQty' => 1, 'sourceRef' => 'intake:123'])['success']);
        $this->assertTrue(ClaimEvidenceService::sourceIsProtected('document', $this->document->id));
    }

    public function test_duplicate_actual_proof_and_source_deletion_are_blocked(): void
    {
        $line = $this->line();
        $proof = $this->document->replicate(['uuid', 'sha256']);
        $proof->uuid = (string) Str::uuid();
        $proof->sha256 = hash('sha256', $proof->uuid);
        $proof->save();
        $record = $this->record($line, ['evidence' => [['type' => 'document', 'id' => $proof->id, 'locator' => 'p.2']]]);
        // This document is protected through a JSON evidence reference, not a line FK.
        $this->assertTrue(ClaimEvidenceService::sourceIsProtected('document', $proof->id));
        $this->assertFalse(ClaimEvidenceService::sourceIsProtected('document', 999999));
        $duplicate = $this->svc()->saveRecord(['lineId' => $line->id, 'recordKind' => 'actual', 'workDate' => '2026-09-20', 'location' => 'Kitchen east wall', 'stage' => 'installed', 'reportedQty' => 20, 'sourceRef' => 'a-different-uuid', 'evidence' => [['type' => 'document', 'id' => $proof->id, 'locator' => 'p.2']]]);
        $this->assertFalse($duplicate['success']);
        $this->assertSame(1, ClaimWorkRecord::count());
        $proof->update(['access_level' => 'private', 'owner_user_id' => $this->actor->id]);
        $ledger = $this->svc()->getLedger($this->contract->id);
        $this->assertTrue($ledger['records'][0]['evidence'][0]['unavailable']);
        $this->assertArrayNotHasKey('filePath', $ledger['records'][0]['evidence'][0]);
    }

    public function test_retainage_release_cannot_bypass_evidence_valuation(): void
    {
        $this->line();
        $billing = app(BillingAdminService::class);
        foreach ([['thisPeriodAmount' => 50, 'storedMaterialsAmount' => 0], ['thisPeriodAmount' => 0, 'storedMaterialsAmount' => 50]] as $amounts) {
            $result = $billing->saveBilling($amounts + ['projectContractId' => $this->contract->id, 'type' => 'retainage_release', 'periodEnd' => '2026-09-30', 'retainageReleased' => 0]);
            $this->assertFalse($result['success'], 'A retainage label must not permit manually valued work.');
        }
        $this->assertSame(0, PayApplication::count());
        $legacy = PayApplication::create(['project_contract_id' => $this->contract->id, 'company_id' => $this->contract->company_id, 'site_id' => $this->contract->site_id, 'project_id' => $this->contract->project_id, 'status' => 'draft', 'type' => 'retainage_release', 'period_end' => '2026-09-30', 'this_period_amount' => 50, 'cumulative_amount' => 50]);
        $this->assertFalse($billing->setBillingStatus(['id' => $legacy->id, 'action' => 'submit'])['success']);
        $this->assertSame('draft', $legacy->fresh()->status);
        $this->assertFalse($billing->saveBilling(['id' => $legacy->id, 'type' => 'progress', 'periodEnd' => '2026-09-30', 'thisPeriodAmount' => 100])['success']);
    }

    public function test_evidence_total_cannot_exceed_current_contract_value(): void
    {
        $this->contract->update(['current_amount' => 100]);
        $this->verify($this->record($this->line()));
        $result = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('계약액', $result['error']);
        $this->assertSame(0, PayApplication::count());
    }

    public function test_missing_or_changed_physical_proof_cannot_be_confirmed_or_submitted(): void
    {
        $record = $this->record($this->line());
        $original = Storage::disk('local')->get($this->document->file_path);
        Storage::disk('local')->delete($this->document->file_path);
        $review = ['id' => $record->id, 'action' => 'verify', 'verifiedQty' => 15, 'reviewNote' => 'Review'];
        $this->assertFalse($this->svc()->reviewRecord($review)['success']);
        Storage::disk('local')->put($this->document->file_path, 'different bytes');
        $this->assertFalse($this->svc()->reviewRecord($review)['success']);
        Storage::disk('local')->put($this->document->file_path, $original);
        $this->verify($record);
        $draft = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertTrue($draft['success'], $draft['error'] ?? '');
        $packet = $this->svc()->getPacket($draft['id']);
        $this->assertTrue($packet['allocations'][0]['snapshot']['evidence'][0]['registeredHashMatched']);
        Storage::disk('local')->delete($this->document->file_path);
        $this->assertFalse(app(BillingAdminService::class)->setBillingStatus(['id' => $draft['id'], 'action' => 'submit'])['success']);
        $this->assertFalse($this->svc()->draft($this->contract->id, '2026-09-30')['success']);
        Storage::disk('local')->put($this->document->file_path, 'replacement after review');
        $this->document->update(['sha256' => hash('sha256', 'replacement after review')]);
        $replacement = $this->svc()->draft($this->contract->id, '2026-09-30');
        $this->assertFalse($replacement['success']);
        $this->assertStringContainsString('변경', $replacement['error']);
        $this->assertFalse(app(BillingAdminService::class)->setBillingStatus(['id' => $draft['id'], 'action' => 'submit'])['success']);
    }
}

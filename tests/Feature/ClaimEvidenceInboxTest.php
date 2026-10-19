<?php

namespace Tests\Feature;

use App\Models\ClaimEvidenceInboxItem;
use App\Models\ClaimWorkRecord;
use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\IntelligentDocument;
use App\Models\MaterialReceipt;
use App\Models\MobileExpense;
use App\Models\PayApplication;
use App\Models\PayApplicationAllocation;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsPhoto;
use App\Models\WorkSection;
use App\Models\WorkSectionSheet;
use App\Services\Drawings\DrawingMarkService;
use App\Services\Finance\ClaimEvidenceInboxService;
use App\Services\Ocr\OcrEngine;
use App\Support\ReceiptFilePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class ClaimEvidenceInboxTest extends TestCase
{
    use RefreshDatabase;

    private ProjectContract $contract;

    private ContractBoqLine $line;

    private ContractBoqLine $secondLine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
        Storage::fake('local');
        Storage::fake('public');
        config(['document-intelligence.disk' => 'local']);
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $company = Company::create(['code' => 'INBOX', 'name' => 'Evidence company', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'INBOX', 'name' => 'Evidence site', 'timezone' => 'UTC', 'status' => 'active']);
        $project = Project::create(['company_id' => $company->id, 'site_id' => $site->id, 'project_code' => 'EVIDENCE', 'name' => 'Evidence project', 'construction_type' => 'equipment_setting']);
        $this->contract = ProjectContract::create(['company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id,
            'title' => 'Accepted quantity contract', 'direction' => 'receivable', 'status' => 'active', 'original_amount' => 2000,
            'payload' => ['claim_profile' => ['stored_materials' => false]]]);
        $this->line = $this->line('1', 'Stainless pipe installation');
        $this->secondLine = $this->line('2', 'Drywall installation');
    }

    private function service(): ClaimEvidenceInboxService
    {
        return app(ClaimEvidenceInboxService::class);
    }

    public function test_linked_evidence_follows_section_drawings_without_geometry_or_claim_writes(): void
    {
        $section = WorkSection::create(['project_contract_id' => $this->contract->id, 'company_id' => $this->contract->company_id,
            'site_id' => $this->contract->site_id, 'division' => 'Mechanical', 'code' => 'M1', 'name' => 'Piping']);
        $this->line->update(['work_section_id' => $section->id]);
        $pick = WorkSectionSheet::create(['work_section_id' => $section->id, 'sheet_no' => 'P-101']);
        $document = $this->document();
        $this->service()->syncContract($this->contract->id);
        $item = $this->item('document', $document->id);
        $marks = app(DrawingMarkService::class);
        $this->assertSame([], $marks->sheet($this->contract->site_id, 'P-101')['evidenceGroups'][0]['rows'], 'Unclassified evidence must not be assigned to a drawing.');
        $this->assertTrue($this->link($item)['success']);
        $view = $marks->sheet($this->contract->site_id, ' p-101 ');
        $this->assertSame($document->id, $view['evidenceGroups'][0]['rows'][0]['sourceId']);
        $this->assertSame([], $view['marks'], 'A documentary association must not invent coordinates.');
        $this->assertCount(1, $this->service()->getInbox($this->contract->id, ['sectionId' => $section->id])['rows']);
        $this->assertSame([], $this->service()->getInbox($this->contract->id, ['sheetNo' => 'OTHER'])['rows']);
        $pick->update(['sheet_no' => 'P-102']);
        $this->assertSame([], $marks->sheet($this->contract->site_id, 'P-101')['evidenceGroups']);
        $this->assertCount(1, $marks->sheet($this->contract->site_id, 'P-102')['evidenceGroups'][0]['rows']);
        $this->service()->review(['id' => $item->id, 'action' => 'reopen']);
        $this->assertSame([], $marks->sheet($this->contract->site_id, 'P-102')['evidenceGroups'][0]['rows']);
        $this->assertSame('review', $item->fresh()->status);
        foreach (['claim_work_records', 'drawing_marks', 'pay_applications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertCount(2, $item->fresh()->review_history);
    }

    public function test_drawing_projection_rechecks_source_privacy_and_contract_access(): void
    {
        $section = WorkSection::create(['project_contract_id' => $this->contract->id, 'company_id' => $this->contract->company_id,
            'site_id' => $this->contract->site_id, 'division' => 'Mechanical', 'code' => 'M1', 'name' => 'Piping']);
        $this->line->update(['work_section_id' => $section->id]);
        WorkSectionSheet::create(['work_section_id' => $section->id, 'sheet_no' => 'P-101']);
        $document = $this->document();
        $this->service()->syncContract($this->contract->id);
        $this->assertTrue($this->link($this->item('document', $document->id))['success']);
        $document->update(['access_level' => 'private', 'owner_user_id' => User::factory()->create()->id]);
        $view = app(DrawingMarkService::class)->sheet($this->contract->site_id, 'P-101');
        $this->assertSame([], $view['evidenceGroups'][0]['rows']);
        $this->assertStringNotContainsString($document->title, json_encode($view));
        $this->actingAs(User::factory()->create(['access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active']));
        $this->assertFalse($this->service()->getInbox($this->contract->id, ['sheetNo' => 'P-101'])['success']);
    }

    private function line(string $number, string $description): ContractBoqLine
    {
        return ContractBoqLine::create(['project_contract_id' => $this->contract->id, 'line_no' => $number,
            'description' => $description, 'unit' => 'EA', 'contract_qty' => 10, 'unit_price' => 100,
            'recognition_basis' => 'quantity', 'status' => 'accepted', 'accepted_by' => auth()->id(), 'accepted_at' => now()]);
    }

    private function document(array $extra = [], ?string $bytes = null): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        $bytes ??= 'Stainless pipe delivery '.$uuid;
        $path = 'evidence/'.$uuid.'.txt';
        Storage::disk('local')->put($path, $bytes);

        return IntelligentDocument::create(array_merge([
            'uuid' => $uuid, 'company_id' => $this->contract->company_id, 'site_id' => $this->contract->site_id,
            'project_id' => $this->contract->project_id, 'project_contract_id' => $this->contract->id,
            'uploaded_by' => auth()->id(), 'disk' => 'local', 'file_path' => $path,
            'original_file_name' => 'delivery.txt', 'stored_file_name' => $uuid.'.txt', 'mime_type' => 'text/plain', 'extension' => 'txt',
            'file_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'title' => 'Stainless pipe delivery',
            'extracted_text' => $bytes, 'document_date' => '2026-10-10', 'received_at' => now(), 'ai_status' => 'ready',
            'access_level' => 'shared', 'confidentiality' => 'internal', 'status' => 'approved', 'document_type' => 'delivery_ticket',
        ], $extra));
    }

    private function expense(array $extra = []): MobileExpense
    {
        return MobileExpense::withoutEvents(fn () => MobileExpense::create(array_merge([
            'company_id' => $this->contract->company_id, 'site_id' => $this->contract->site_id, 'project_id' => $this->contract->project_id,
            'payment_type' => 'corporate', 'category' => 'materials', 'description' => 'Stainless pipe receipt', 'amount' => 123,
            'expense_date' => '2026-10-10', 'status' => 'pending', 'receipt_file' => ReceiptFilePayload::encode('PRIVATE-ORIGINAL-RECEIPT'),
            'receipt_mime_type' => 'text/plain', 'receipt_original_name' => 'receipt.txt',
            'ocr_data' => ['items' => [['name' => 'Stainless pipe']]],
        ], $extra)));
    }

    private function item(string $type, int $id): ClaimEvidenceInboxItem
    {
        return ClaimEvidenceInboxItem::where('project_contract_id', $this->contract->id)->where('source_type', $type)->where('source_id', $id)->firstOrFail();
    }

    private function link(ClaimEvidenceInboxItem $item, array $lineIds = []): array
    {
        return $this->service()->review(['id' => $item->id, 'action' => 'link', 'lineIds' => $lineIds ?: [$this->line->id]]);
    }

    public function test_daily_scan_accumulates_all_four_source_types_without_financial_writes_or_inline_bytes(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $section = WorkSection::create(['project_contract_id' => $this->contract->id, 'company_id' => $this->contract->company_id,
            'site_id' => $this->contract->site_id, 'division' => 'Mechanical', 'code' => 'M1', 'name' => 'Piping']);
        $this->line->update(['spec' => '25mm', 'group_label' => 'Process supply', 'work_section_id' => $section->id]);
        $document = $this->document();
        $expense = $this->expense();
        Storage::disk('local')->put('delivery.txt', 'Original material delivery');
        $receipt = MaterialReceipt::create(['company_id' => $this->contract->company_id, 'site_id' => $this->contract->site_id,
            'received_on' => '2026-10-10', 'vendor' => 'Supplier', 'status' => 'confirmed', 'photo_disk' => 'local', 'photo_path' => 'delivery.txt']);
        $receipt->lines()->create(['name' => 'Stainless pipe', 'quantity' => 10, 'unit' => 'EA', 'seq' => 1]);
        Storage::disk('local')->put('original.jpg', 'Original site photo');
        $photo = WbsPhoto::create(['site_id' => $this->contract->site_id, 'project_code' => 'EVIDENCE', 'wbs_code' => 'PIPE',
            'photo_date' => '2026-10-10', 'caption' => 'Stainless pipe installation', 'disk' => 'local', 'path' => 'preview.jpg',
            'original_path' => 'original.jpg', 'original_sha256' => hash('sha256', 'Original site photo'), 'mime' => 'image/jpeg']);
        $scan = $this->service()->syncContract($this->contract->id, 4);
        $this->assertTrue($scan['success']);
        $this->assertSame(4, $scan['created']);
        $unchanged = $this->service()->syncContract($this->contract->id, 4);
        $this->assertSame(0, $unchanged['created']);
        $this->assertSame(0, $unchanged['scanned']);
        $inbox = $this->service()->getInbox($this->contract->id);
        $this->assertCount(4, $inbox['rows']);
        $this->assertCount(2, $inbox['lines']);
        $this->assertSame('25mm', $inbox['lines'][0]['spec']);
        $this->assertSame('EA', $inbox['lines'][0]['unit']);
        $this->assertSame('Process supply', $inbox['lines'][0]['group']);
        $this->assertSame(['code' => 'M1', 'name' => 'Piping'], $inbox['lines'][0]['section']);
        $this->assertSame(['linked' => 0, 'review' => 4, 'common' => 0], $inbox['summary']);
        $this->assertStringNotContainsString('PRIVATE-ORIGINAL-RECEIPT', json_encode($inbox));
        foreach ([['document', $document], ['expense', $expense], ['receipt', $receipt], ['photo', $photo]] as [$type, $source]) {
            $this->assertTrue($this->link($this->item($type, $source->id))['success']);
        }
        $this->assertSame(0, ClaimWorkRecord::count());
        $this->assertSame(0, PayApplication::count());
        $this->assertSame('pending', $expense->fresh()->status);
        $this->assertSame('123.00', $expense->fresh()->amount);
        $this->assertSame('10.0000', $this->line->fresh()->contract_qty);
        $meta = $this->service()->resolveSource($this->contract, 'expense', $expense->id, true);
        $this->assertSame('PRIVATE-ORIGINAL-RECEIPT', $this->service()->readSource($meta));
    }

    public function test_review_supports_multiple_lines_common_ignore_and_changed_source_requires_review(): void
    {
        // Production uses a UTC DB session while legacy source timestamps use the app wall clock.
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $document = $this->document();
        $this->service()->syncContract($this->contract->id);
        $item = $this->item('document', $document->id);
        $linked = $this->link($item, [$this->line->id, $this->secondLine->id]);
        $this->assertTrue($linked['success']);
        $this->assertSame([$this->line->id, $this->secondLine->id], $linked['row']['lineIds']);
        $this->assertTrue($this->service()->sourceIsProtected('document', $document->id));
        $filtered = $this->service()->getInbox($this->contract->id, ['lineId' => $this->secondLine->id]);
        $this->assertCount(1, $filtered['rows']);
        $common = $this->service()->review(['id' => $item->id, 'action' => 'common']);
        $this->assertSame('common', $common['row']['status']);
        $this->assertSame([], $common['row']['lineIds']);
        $ignored = $this->service()->review(['id' => $item->id, 'action' => 'ignore']);
        $this->assertSame('ignored', $ignored['row']['status']);
        $this->assertTrue($this->service()->review(['id' => $item->id, 'action' => 'reopen'])['success']);
        $this->assertTrue($this->link($item)['success']);
        $this->travel(1)->minutes();
        $document->update(['summary' => 'Corrected original description']);
        $this->assertTrue($this->service()->syncContract($this->contract->id)['success']);
        $this->assertSame('review', $item->fresh()->status);
        $this->assertSame([], $item->fresh()->line_ids);
        $this->assertGreaterThanOrEqual(4, count($item->fresh()->review_history));
    }

    public function test_review_never_bypasses_source_review_duplicate_or_foreign_contract_line(): void
    {
        $blocked = $this->document(['ai_payload' => ['source_review_reason' => 'Unreviewed email attachment'], 'ai_status' => 'review_required']);
        $duplicate = $this->document(['ai_payload' => ['duplicate_document_id' => $blocked->id]]);
        $valid = $this->document();
        $this->service()->syncContract($this->contract->id);
        $this->assertFalse($this->link($this->item('document', $blocked->id))['success']);
        $this->assertFalse($this->link($this->item('document', $duplicate->id))['success']);
        $other = $this->contract->replicate();
        $other->internal_reference = null;
        $other->save();
        $foreignLine = $this->line->replicate();
        $foreignLine->project_contract_id = $other->id;
        $foreignLine->save();
        $this->assertFalse($this->link($this->item('document', $valid->id), [$foreignLine->id])['success']);
        $this->assertSame(0, ClaimEvidenceInboxItem::where('status', 'linked')->count());
    }

    public function test_private_original_also_revokes_expense_copy_and_archived_checks_remain_scoped(): void
    {
        $document = $this->document();
        $expense = $this->expense(['source_ref' => 'document:'.$document->id, 'ocr_data' => ['document_id' => $document->id]]);
        $this->service()->syncContract($this->contract->id);
        $this->assertTrue($this->link($this->item('expense', $expense->id))['success']);
        $descriptor = $this->service()->resolveSource($this->contract, 'expense', $expense->id, true);
        $document->update(['access_level' => 'private', 'owner_user_id' => auth()->id()]);
        $this->assertCount(0, $this->service()->getInbox($this->contract->id)['rows']);
        try {
            $this->service()->readSource($descriptor);
            $this->fail('A DB receipt copy must not bypass the original mailbox privacy.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->service()->resolveSource($this->contract, 'expense', $expense->id, false, true);
    }

    public function test_archive_does_not_treat_a_canonical_document_hidden_by_job_scope_as_deleted(): void
    {
        $admin = auth()->user();
        $document = $this->document(['document_type' => 'payroll_record']);
        $expense = $this->expense(['source_ref' => 'document:'.$document->id, 'ocr_data' => ['document_id' => $document->id]]);
        $actor = User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'company', 'account_status' => 'active',
            'allowed_company_id' => $this->contract->company_id, 'allowed_site_id' => $this->contract->site_id,
            'job_role' => 'office', 'job_site_ids' => [$this->contract->site_id],
            'job_permissions' => ['contracts' => ['view'], 'finance' => ['view'], 'documents' => ['view']]]);
        $this->actingAs($actor);
        $this->assertFalse(IntelligentDocument::whereKey($document->id)->exists());
        $this->assertTrue(IntelligentDocument::withoutGlobalScopes()->whereKey($document->id)->exists());
        try {
            $this->service()->resolveSource($this->contract, 'expense', $expense->id, false, true);
            $this->fail('An existing source hidden by payroll permissions cannot be treated as a deleted original.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        // A genuinely deleted original may be absent when inspecting an immutable archive's scope.
        $this->actingAs($admin);
        $document->delete();
        $this->actingAs($actor);
        $archived = $this->service()->resolveSource($this->contract, 'expense', $expense->id, false, true);
        $this->assertSame($expense->id, $archived['sourceId']);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->resolveSource($this->contract, 'expense', $expense->id);
    }

    public function test_high_confidence_matching_is_grounded_and_does_not_convert_evidence_into_work(): void
    {
        $estimate = $this->contract->replicate();
        $estimate->forceFill(['internal_reference' => null, 'title' => 'Earlier estimate without accepted BOQ', 'status' => 'draft'])->save();
        $valid = $this->document(['project_contract_id' => null], 'Stainless pipe 25mm delivered');
        $expense = $this->expense();
        $blocked = $this->document(['ai_payload' => ['source_review_reason' => 'Awaiting source check']]);
        $this->assertTrue($this->service()->resolveSource($this->contract, 'document', $valid->id)['autoEligible']);
        $this->assertTrue($this->service()->resolveSource($this->contract, 'expense', $expense->id)['autoEligible']);
        $this->service()->syncContract($this->contract->id);
        $item = $this->item('document', $valid->id);
        $engine = Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->once()->withArgs(function ($images, $prompt, $schema) use ($blocked): bool {
            $this->assertSame([], $images);
            $this->assertStringNotContainsString($blocked->extracted_text, $prompt);
            $this->assertArrayHasKey('properties', $schema);

            return true;
        })->andReturn(['data' => ['matches' => [
            ['source_id' => $item->id, 'line_id' => $this->line->id, 'confidence' => .99, 'quote' => 'Stainless pipe 25mm', 'locator' => 'delivery item 1'],
            ['source_id' => $item->id, 'line_id' => $this->secondLine->id, 'confidence' => .99, 'quote' => 'Invented drywall evidence', 'locator' => 'imaginary'],
        ]], 'model' => 'test']);
        $this->app->instance(OcrEngine::class, $engine);
        $result = $this->service()->match($this->contract->id);
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['linked']);
        $this->assertSame([$this->line->id], $item->fresh()->line_ids);
        $this->assertSame('review', $this->item('document', $blocked->id)->status);
        $this->assertSame(0, ClaimWorkRecord::count());
        $this->assertSame(0, PayApplicationAllocation::count());
        // A second accepted workbook really is ambiguous, even while its contract remains draft.
        $alternative = $this->line->replicate();
        $alternative->project_contract_id = $estimate->id;
        $alternative->save();
        $this->assertFalse($this->service()->resolveSource($this->contract, 'document', $valid->id)['autoEligible']);
        $this->assertFalse($this->service()->resolveSource($this->contract, 'expense', $expense->id)['autoEligible']);
        $valid->update(['project_contract_id' => $this->contract->id]);
        $this->assertTrue($this->service()->resolveSource($this->contract, 'document', $valid->id)['autoEligible']);
        $valid->update(['project_contract_id' => $estimate->id]);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->resolveSource($this->contract, 'document', $valid->id);
    }

    public function test_retry_cooldown_compares_instants_across_application_and_database_timezones(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Seoul');
        $this->travelTo(Carbon::parse('2026-10-12T20:00:00+09:00'));
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        try {
            $document = $this->document();
            $this->service()->syncContract($this->contract->id);
            $item = $this->item('document', $document->id);
            $item->update(['last_matched_at' => now()->subHours(1)]);
            $before = $item->fresh()->last_matched_at->getTimestamp();
            $engine = Mockery::mock(OcrEngine::class);
            $engine->shouldNotReceive('analyze');
            $this->app->instance(OcrEngine::class, $engine);
            $this->assertTrue($this->service()->match($this->contract->id)['success']);
            $this->assertSame($before, $item->fresh()->last_matched_at->getTimestamp());
            $this->travel(6)->hours();
            $retry = Mockery::mock(OcrEngine::class);
            $retry->shouldReceive('analyze')->once()->andReturn(['data' => ['matches' => []], 'model' => 'test']);
            $this->app->instance(OcrEngine::class, $retry);
            $this->assertTrue($this->service()->match($this->contract->id)['success']);
            $this->assertSame(now()->getTimestamp(), $item->fresh()->last_matched_at->getTimestamp());
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_package_includes_earlier_purchases_and_detects_changed_bytes_without_changing_amounts(): void
    {
        $document = $this->document(['document_date' => '2026-09-20']);
        $common = $this->document(['document_date' => '2026-09-21']);
        $future = $this->document(['document_date' => '2026-10-13']);
        $this->service()->syncContract($this->contract->id);
        $this->assertTrue($this->link($this->item('document', $document->id))['success']);
        $this->assertTrue($this->service()->review(['id' => $this->item('document', $common->id)->id, 'action' => 'common'])['success']);
        $this->assertFalse($this->link($this->item('document', $future->id))['success']);
        $application = PayApplication::create(['project_contract_id' => $this->contract->id, 'company_id' => $this->contract->company_id,
            'site_id' => $this->contract->site_id, 'project_id' => $this->contract->project_id, 'type' => 'progress', 'status' => 'draft',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-12', 'this_period_amount' => 100]);
        $packet = $this->service()->materializeForApplication($application);
        $this->assertCount(1, $packet);
        $this->assertSame($document->id, $packet[0]['sourceId']);
        $this->assertArrayNotHasKey('text', $packet[0]);
        $this->assertSame($document->extracted_text, $this->service()->readSource($packet[0]));
        $application->update(['type' => 'retainage_release']);
        $this->assertSame([], $this->service()->materializeForApplication($application));
        $application->update(['type' => 'progress']);
        Storage::disk('local')->put($document->file_path, 'Different bytes under the same path');
        $this->assertSame('100.00', $application->fresh()->this_period_amount);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->materializeForApplication($application);
    }

    public function test_scope_and_module_permissions_are_rechecked_and_incremental_scan_does_not_repeat_first_page(): void
    {
        $one = $this->document();
        $two = $this->document();
        $three = $this->document();
        $this->document(['project_contract_id' => null, 'project_id' => null, 'site_id' => null, 'access_level' => 'private']);
        $this->assertSame(1, $this->service()->syncContract($this->contract->id, 1)['created']);
        $this->assertSame(1, $this->service()->syncContract($this->contract->id, 1)['created']);
        $this->assertSame(1, $this->service()->syncContract($this->contract->id, 1)['created']);
        $this->assertSame(0, $this->service()->syncContract($this->contract->id, 1)['created']);
        $this->assertSame([$one->id, $two->id, $three->id], ClaimEvidenceInboxItem::orderBy('id')->pluck('source_id')->all());
        auth()->user()->update(['account_status' => 'inactive']);
        $this->assertFalse($this->service()->getInbox($this->contract->id)['success']);
        $this->assertFalse($this->service()->syncContract($this->contract->id)['success']);
    }

    public function test_source_can_be_previewed_to_resolve_review_issues_but_cannot_be_linked(): void
    {
        $document = $this->document(['document_date' => null, 'ai_status' => 'review_required',
            'ai_payload' => ['source_review_reason' => 'Attachment must be checked']]);
        $this->service()->syncContract($this->contract->id);
        $this->assertFalse($this->link($this->item('document', $document->id))['success']);
        $preview = $this->service()->readForPreview($this->contract, 'document', $document->id);
        $this->assertSame($document->extracted_text, $preview['bytes']);
        $this->assertSame('text/plain', $preview['mime']);
        $document->update(['confidentiality' => 'restricted']);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->readForPreview($this->contract, 'document', $document->id);
    }

    public function test_ai_failure_remains_retryable_and_same_name_without_specification_is_not_auto_linked(): void
    {
        $this->line->update(['spec' => '25mm']);
        $this->secondLine->update(['description' => $this->line->description, 'spec' => '50mm']);
        $document = $this->document([], 'Stainless pipe delivery without size');
        $this->service()->syncContract($this->contract->id);
        $item = $this->item('document', $document->id);
        $engine = Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->once()->andThrow(new \RuntimeException('Provider unavailable'));
        $this->app->instance(OcrEngine::class, $engine);
        $failed = $this->service()->match($this->contract->id);
        $this->assertFalse($failed['success']);
        $this->assertSame('review', $item->fresh()->status);
        $this->assertNull($item->fresh()->last_matched_at);
        $retry = Mockery::mock(OcrEngine::class);
        $retry->shouldReceive('analyze')->once()->andReturn(['data' => ['matches' => [
            ['source_id' => $item->id, 'line_id' => $this->line->id, 'confidence' => .99, 'quote' => 'Stainless pipe delivery', 'locator' => 'item 1'],
        ]], 'model' => 'test']);
        $this->app->instance(OcrEngine::class, $retry);
        $this->assertSame(0, $this->service()->match($this->contract->id)['linked']);
        $this->assertSame('review', $item->fresh()->status);
    }

    public function test_only_server_verified_allocation_can_use_an_undated_legacy_original(): void
    {
        $document = $this->document(['document_date' => null]);
        $record = ClaimWorkRecord::create(['contract_boq_line_id' => $this->line->id, 'record_kind' => 'actual',
            'work_date' => '2026-10-10', 'location' => 'Zone A', 'stage' => 'installed', 'reported_qty' => 1,
            'verified_qty' => 1, 'status' => 'verified', 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
        $application = PayApplication::create(['project_contract_id' => $this->contract->id, 'company_id' => $this->contract->company_id,
            'site_id' => $this->contract->site_id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-12', 'this_period_amount' => 100]);
        $allocation = PayApplicationAllocation::create(['pay_application_id' => $application->id, 'claim_work_record_id' => $record->id,
            'quantity' => 1, 'amount' => 100, 'snapshot' => ['evidence' => [[
                'type' => 'document', 'id' => $document->id, 'verifiedFileSha256' => $document->sha256,
            ]]]]);
        $descriptor = $this->service()->resolveSource($this->contract, 'document', $document->id);
        $this->assertNull($descriptor['date']);
        $this->assertNotNull($descriptor['blockReason']);
        $descriptor['allocationId'] = $allocation->id;
        $this->assertSame($document->extracted_text, $this->service()->readSource($descriptor));
        $record->update(['status' => 'pending']);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->readSource($descriptor);
    }
}

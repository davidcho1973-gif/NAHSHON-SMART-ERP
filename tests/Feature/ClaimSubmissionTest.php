<?php

namespace Tests\Feature;

use App\Models\ClaimSubmission;
use App\Models\ClaimEvidenceInboxItem;
use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\PayApplicationAllocation;
use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\MaterialReceipt;
use App\Models\MobileExpense;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\PayApplication;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\ProjectContractDocument;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsPhoto;
use App\Services\Admin\BillingAdminService;
use App\Services\Finance\ClaimEvidenceService;
use App\Services\Finance\ClaimEvidenceInboxService;
use App\Services\Finance\ClaimSubmissionService;
use App\Support\ReceiptFilePayload;
use App\Support\JobAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class ClaimSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private ProjectContract $contract;
    private PayApplication $application;
    private IntelligentDocument $document;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document-intelligence.disk' => 'local']);
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $company = Company::create(['code' => 'SUBMISSION', 'name' => 'Submission Co', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'PACKET', 'name' => 'Packet site', 'timezone' => 'UTC', 'status' => 'active']);
        $project = Project::create(['company_id' => $company->id, 'site_id' => $site->id, 'project_code' => 'PACKET-1', 'name' => 'Packet project', 'construction_type' => 'equipment_setting']);
        $this->contract = ProjectContract::create([
            'company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id,
            'title' => 'Short project contract', 'contract_number' => 'C-101', 'direction' => 'receivable',
            'status' => 'active', 'original_amount' => 10000, 'retainage_percent' => 0,
            'payload' => ['claim_profile' => [
                'version' => 1, 'confirmed_at' => now()->toIso8601String(), 'confirmed_by' => auth()->id(),
                'basis_note' => 'Signed contract payment exhibit, page 3.', 'stored_materials' => false,
                'submission_day' => 25, 'preparation_days' => 7, 'template_label' => 'Owner prescribed form',
                'requirements' => [['key' => 'work_proof', 'title' => 'Measured work proof', 'stage' => 'each_claim', 'required' => true]],
            ]],
        ]);
        $result = app(BillingAdminService::class)->saveBilling([
            'projectContractId' => $this->contract->id, 'periodStart' => '2026-10-01',
            'periodEnd' => '2026-10-11', 'thisPeriodAmount' => 100,
        ]);
        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->application = PayApplication::findOrFail($result['id']);
        $this->document = $this->document([], 'Original measured work proof');
    }

    private function service(): ClaimSubmissionService
    {
        return app(ClaimSubmissionService::class);
    }

    private function document(array $extra = [], ?string $contents = null): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        // Separate document fixtures need distinct bytes under the scoped SHA-256 unique constraint.
        $contents ??= 'Measured work proof '.$uuid;
        $path = 'proofs/'.$uuid.'.txt';
        Storage::disk('local')->put($path, $contents);
        return IntelligentDocument::create(array_merge([
            'uuid' => $uuid, 'company_id' => $this->contract->company_id,
            'site_id' => $this->contract->site_id, 'project_id' => $this->contract->project_id,
            'project_contract_id' => $this->contract->id, 'uploaded_by' => auth()->id(),
            'disk' => 'local', 'file_path' => $path, 'original_file_name' => 'proof.txt',
            'stored_file_name' => $uuid.'.txt', 'mime_type' => 'text/plain', 'extension' => 'txt',
            'file_size' => strlen($contents), 'sha256' => hash('sha256', $contents), 'title' => 'Proof',
            'received_at' => now(), 'document_date' => '2026-10-01', 'ai_status' => 'ready', 'access_level' => 'shared', 'status' => 'approved',
        ], $extra));
    }

    private function select(?IntelligentDocument $document = null): array
    {
        return $this->service()->saveEvidence([
            'application_id' => $this->application->id,
            'evidence' => ['work_proof' => [['type' => 'document', 'id' => ($document ?? $this->document)->id]]],
        ]);
    }

    private function prepared(): ClaimSubmission
    {
        $this->assertTrue($this->select()['success']);
        $result = $this->service()->prepare($this->application->id);
        $this->assertTrue($result['success'], $result['error'] ?? '');
        return ClaimSubmission::findOrFail($result['submission']['id']);
    }

    private function transition(ClaimSubmission $submission, string $status, array $extra = []): array
    {
        return $this->service()->transition(array_merge([
            'submission_id' => $submission->id, 'status' => $status,
            'occurred_at' => '2026-10-11', 'reference' => 'Portal reference '.$status,
            'response_document' => ['type' => 'document', 'id' => $this->document->id],
        ], $extra));
    }

    private function evidenceApplication(array $proofs): void
    {
        // Replace only this test's empty manual application with a real reviewed BOQ allocation.
        $this->application->delete();
        $service = app(ClaimEvidenceService::class);
        $line = $service->saveLine([
            'projectContractId' => $this->contract->id, 'lineNo' => '01',
            'description' => 'Kitchen wall framing', 'unit' => 'EA', 'contractQty' => 10, 'unitPrice' => 100,
            'recognitionBasis' => 'quantity', 'status' => 'accepted',
            'acceptanceNote' => 'Signed schedule checked', 'sourceDocumentId' => $this->document->id,
            'sourceLocator' => 'Contract sheet row 12',
        ]);
        $this->assertTrue($line['success'], $line['error'] ?? '');
        $record = $service->saveRecord([
            'lineId' => $line['id'], 'recordKind' => 'actual', 'workDate' => '2026-10-10',
            'location' => 'Kitchen east wall', 'stage' => 'installed', 'reportedQty' => 1, 'evidence' => $proofs,
        ]);
        $this->assertTrue($record['success'], $record['error'] ?? '');
        $review = $service->reviewRecord(['id' => $record['id'], 'action' => 'verify', 'verifiedQty' => 1, 'reviewNote' => 'Physical proof reviewed']);
        $this->assertTrue($review['success'], $review['error'] ?? '');
        $draft = $service->draft($this->contract->id, '2026-10-11');
        $this->assertTrue($draft['success'], $draft['error'] ?? '');
        $this->application = PayApplication::findOrFail($draft['id']);
    }

    public function test_missing_unconfirmed_and_expired_requirements_never_produce_a_ready_packet(): void
    {
        $this->assertFalse($this->service()->prepare($this->application->id)['success']);
        $this->assertTrue($this->select()['success']);
        $payload = $this->contract->payload;
        $payload['claim_profile']['confirmed_at'] = null;
        $this->contract->update(['payload' => $payload]);
        $this->assertFalse($this->service()->prepare($this->application->id)['success']);
        $payload['claim_profile']['confirmed_at'] = now()->toIso8601String();
        $this->contract->update(['payload' => $payload]);
        $this->document->update(['expires_on' => '2026-10-10']);
        $this->assertFalse($this->service()->prepare($this->application->id)['success']);
        $this->assertSame(0, ClaimSubmission::count());
    }

    public function test_private_other_contract_and_foreign_project_sources_are_not_exportable(): void
    {
        $private = $this->document(['access_level' => 'private', 'owner_user_id' => auth()->id()]);
        $this->assertFalse($this->select($private)['success'], 'Even the owner must explicitly share a private document before exporting it.');
        $other = ProjectContract::create([
            'company_id' => $this->contract->company_id, 'site_id' => $this->contract->site_id,
            'title' => 'Other contract', 'direction' => 'receivable', 'status' => 'active',
        ]);
        $this->assertFalse($this->select($this->document(['project_contract_id' => $other->id]))['success']);
        $project = Project::create([
            'company_id' => $this->contract->company_id, 'site_id' => $this->contract->site_id,
            'project_code' => 'FOREIGN', 'name' => 'Foreign project', 'construction_type' => 'equipment_setting',
        ]);
        $this->assertFalse($this->select($this->document(['project_id' => $project->id]))['success']);
        $values = array_column($this->service()->workspace($this->application->id)['sourceOptions'], 'value');
        $this->assertNotContains('document:'.$private->id, $values);
    }

    public function test_confidential_and_restricted_documents_cannot_enter_packets_or_responses(): void
    {
        foreach (['confidential', 'restricted'] as $confidentiality) {
            $document = $this->document(['confidentiality' => $confidentiality]);
            $this->assertFalse($this->select($document)['success']);
            $options = array_column($this->service()->workspace($this->application->id)['sourceOptions'], 'value');
            $this->assertNotContains('document:'.$document->id, $options);
        }
        $submission = $this->prepared();
        $this->assertTrue($this->transition($submission, 'sent')['success']);
        $this->assertFalse($this->transition($submission, 'accepted', [
            'response_document' => ['type' => 'document', 'id' => $document->id],
        ])['success']);
        $this->assertSame('sent', $submission->fresh()->status);
    }

    public function test_an_approved_corporate_file_requires_an_explicit_contract_link(): void
    {
        $shared = $this->document(['site_id' => null, 'project_id' => null]);
        $this->assertTrue($this->select($shared)['success']);
        $shared->update(['project_contract_id' => null]);
        $this->assertFalse($this->select($shared)['success']);
    }

    public function test_prepared_zip_contains_originals_and_summary_but_is_not_sent_or_approved(): void
    {
        $submission = $this->prepared();
        $path = Storage::disk('local')->path($submission->file_path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertStringContainsString('100.00', $zip->getFromName('claim-summary.html'));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Original measured work proof', $zip->getFromName($manifest['documents'][0]['file']));
        $zip->close();
        $this->service()->getDownload($submission->id);
        $this->assertSame('draft', $this->application->fresh()->status);
        $this->assertSame('prepared', $submission->fresh()->status);
        $this->assertNull($submission->fresh()->sent_at);
        $direct = app(BillingAdminService::class)->setBillingStatus(['id' => $this->application->id, 'action' => 'submit']);
        $this->assertFalse($direct['success']);
        $this->assertFalse($this->transition($submission, 'accepted')['success']);
    }

    public function test_changed_source_rules_or_amounts_require_a_new_prepared_revision(): void
    {
        $submission = $this->prepared();
        Storage::disk('local')->put($this->document->file_path, 'Replacement bytes');
        $this->assertFalse($this->transition($submission, 'sent')['success']);
        $this->assertSame('prepared', $submission->fresh()->status);
        Storage::disk('local')->put($this->document->file_path, 'Original measured work proof');
        $payload = $this->contract->payload;
        $payload['claim_profile']['version'] = 2;
        $this->contract->update(['payload' => $payload]);
        $this->assertFalse($this->transition($submission, 'sent')['success']);
        $payload['claim_profile']['version'] = 1;
        $this->contract->update(['payload' => $payload]);
        $this->application->update(['this_period_amount' => 101]);
        $this->assertFalse($this->transition($submission, 'sent')['success']);
        $this->assertSame('draft', $this->application->fresh()->status);
    }

    public function test_allocation_originals_are_bundled_once_with_line_locators_and_item_only_text(): void
    {
        Storage::disk('local')->put('proofs/original-photo.jpg', 'Original site photograph');
        Storage::disk('local')->put('proofs/display-photo.jpg', 'Resized display photograph');
        Storage::disk('local')->put('proofs/delivery.pdf', 'Signed delivery docket');
        $photo = WbsPhoto::create([
            'site_id' => $this->contract->site_id, 'project_code' => $this->contract->project->project_code,
            'wbs_code' => 'WALL', 'photo_date' => '2026-10-10', 'disk' => 'local',
            'path' => 'proofs/display-photo.jpg', 'original_path' => 'proofs/original-photo.jpg',
            'original_name' => 'wall.jpg', 'original_sha256' => hash('sha256', 'Original site photograph'),
            'caption' => 'East wall installation', 'uploaded_by_id' => auth()->id(),
        ]);
        $receipt = MaterialReceipt::create([
            'company_id' => $this->contract->company_id, 'site_id' => $this->contract->site_id,
            'received_on' => '2026-10-09', 'status' => 'confirmed', 'vendor' => 'Stud supplier',
            'photo_disk' => 'local', 'photo_path' => 'proofs/delivery.pdf', 'photo_name' => 'delivery.pdf',
        ]);
        $batch = OpsIntakeBatch::create([
            'site_id' => $this->contract->site_id, 'raw_text' => 'Unrelated confidential conversation in the batch',
            'status' => 'done', 'source' => 'manual',
        ]);
        $item = OpsIntakeItem::create([
            'site_id' => $this->contract->site_id, 'project_code' => $this->contract->project->project_code,
            'ops_intake_batch_id' => $batch->id, 'raw_text' => 'East wall framing inspected on October 10.',
            'summary' => 'East wall complete', 'occurred_on' => '2026-10-10', 'category' => 'progress', 'status' => 'applied',
        ]);
        $this->evidenceApplication([
            ['type' => 'document', 'id' => $this->document->id, 'locator' => 'Inspection row 4'],
            ['type' => 'photo', 'id' => $photo->id, 'locator' => 'East wall'],
            ['type' => 'receipt', 'id' => $receipt->id, 'locator' => 'Delivery item 1'],
            ['type' => 'intake', 'id' => $item->id, 'locator' => 'Daily report item 1'],
        ]);
        $submission = $this->prepared();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($submission->file_path)));
        $manifest = $submission->manifest;
        $this->assertCount(5, $manifest['dailyEvidence']);
        // One contract document + photograph + delivery + item text, plus manifest and summary.
        $this->assertSame(6, $zip->numFiles);
        foreach ($manifest['dailyEvidence'] as $proof) {
            $content = $zip->getFromName($proof['file']);
            $this->assertSame($proof['sha256'], hash('sha256', $content));
            $this->assertStringNotContainsString('Unrelated confidential conversation', $content);
            if ($proof['source']['type'] === 'photo') {
                $this->assertSame('Original site photograph', $content);
            }
        }
        $this->assertStringContainsString('Contract sheet row 12', $zip->getFromName('claim-summary.html'));
        $this->assertStringContainsString('Kitchen wall framing', $zip->getFromName('claim-summary.html'));
        $zip->close();
        $this->assertTrue($this->transition($submission, 'sent')['success']);
        $this->assertSame('100.00', $this->application->fresh()->this_period_amount);
    }

    public function test_allocation_file_changes_and_later_privacy_cannot_bypass_frozen_packet(): void
    {
        $proof = $this->document([], 'Independent signed inspection');
        $this->evidenceApplication([['type' => 'document', 'id' => $proof->id, 'locator' => 'Inspection page 1']]);
        $submission = $this->prepared();
        $originalZip = Storage::disk('local')->get($submission->file_path);
        Storage::disk('local')->put($proof->file_path, 'Replacement inspection');
        $this->assertFalse($this->transition($submission, 'sent')['success']);
        $this->assertFalse($this->service()->prepare($this->application->id)['success']);
        $this->assertSame($originalZip, Storage::disk('local')->get($submission->file_path));
        Storage::disk('local')->put($proof->file_path, 'Independent signed inspection');
        $proof->update(['access_level' => 'private', 'owner_user_id' => auth()->id()]);
        $this->assertFalse($this->transition($submission, 'sent')['success']);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->getDownload($submission->id);
    }

    public function test_daily_receipts_use_current_lines_and_period_without_changing_money(): void
    {
        $this->evidenceApplication([['type' => 'document', 'id' => $this->document->id, 'locator' => 'Work proof']]);
        $this->application->update(['period_start' => '2026-10-01']);
        $line = ContractBoqLine::where('project_contract_id', $this->contract->id)->firstOrFail();
        $expenses = [];
        foreach (['2026-09-05', '2026-10-10', '2026-09-06'] as $index => $date) {
            $expenses[] = MobileExpense::withoutEvents(fn () => MobileExpense::create([
                'company_id' => $this->contract->company_id, 'site_id' => $this->contract->site_id,
                'project_id' => $this->contract->project_id, 'expense_date' => $date,
                'description' => 'Daily receipt '.$index, 'category' => 'materials', 'payment_type' => 'corporate',
                'amount' => 31.25 + $index, 'status' => 'pending',
                'receipt_file' => ReceiptFilePayload::encode('Original daily receipt '.$index),
                'receipt_mime_type' => 'text/plain', 'receipt_original_name' => 'receipt-'.$index.'.txt',
            ]));
        }
        $inbox = app(ClaimEvidenceInboxService::class);
        $this->assertTrue($inbox->syncContract($this->contract->id)['success']);
        foreach ($expenses as $index => $expense) {
            $item = ClaimEvidenceInboxItem::where('project_contract_id', $this->contract->id)
                ->where('source_type', 'expense')->where('source_id', $expense->id)->firstOrFail();
            $result = $inbox->review([
                'id' => $item->id, 'action' => $index === 0 ? 'link' : 'common',
                'lineIds' => [$line->id], 'locators' => [['lineId' => $line->id, 'locator' => 'Stud purchase item 1']],
            ]);
            $this->assertTrue($result['success'], $result['error'] ?? '');
        }
        $before = $this->application->fresh()->only(['this_period_amount', 'stored_materials_amount', 'amount_due', 'status']);
        $submission = $this->prepared();
        $dailyReceipts = collect($submission->manifest['dailyEvidence'])->filter(fn ($row) => $row['source']['type'] === 'expense')->values();
        $this->assertSame([$expenses[0]->id, $expenses[1]->id], $dailyReceipts->pluck('source.id')->all());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($submission->file_path)));
        $this->assertSame('Original daily receipt 0', $zip->getFromName($dailyReceipts[0]['file']));
        $this->assertSame('Original daily receipt 1', $zip->getFromName($dailyReceipts[1]['file']));
        $zip->close();
        $this->assertSame($before, $this->application->fresh()->only(array_keys($before)));
        $this->assertSame(['pending'], MobileExpense::pluck('status')->unique()->all());
        $this->assertSame($submission->id, $this->service()->getDownload($submission->id)->id);

        // Altering a receipt after preparation cannot silently replace its saved packet original.
        $expenses[0]->withoutEvents(fn () => $expenses[0]->update(['receipt_file' => ReceiptFilePayload::encode('Changed receipt')]));
        $this->assertFalse($this->transition($submission, 'sent')['success']);
        $this->assertSame('prepared', $submission->fresh()->status);
    }

    public function test_stored_milestone_allocations_cannot_hide_in_current_work_when_contract_disallows_them(): void
    {
        $submission = $this->prepared();
        $line = ContractBoqLine::create([
            'project_contract_id' => $this->contract->id, 'line_no' => 'STORED',
            'description' => 'Stored equipment', 'unit' => 'EA', 'contract_qty' => 1, 'unit_price' => 100,
            'recognition_basis' => 'milestone', 'stage_weights' => ['stored' => 100], 'status' => 'accepted',
        ]);
        $record = ClaimWorkRecord::create([
            'contract_boq_line_id' => $line->id, 'record_kind' => 'actual', 'work_date' => '2026-10-11',
            'location' => 'Laydown area A', 'stage' => 'stored', 'reported_qty' => 1, 'verified_qty' => 1, 'status' => 'verified',
            'evidence' => [], 'review_history' => [],
        ]);
        PayApplicationAllocation::create([
            'pay_application_id' => $this->application->id, 'claim_work_record_id' => $record->id,
            'quantity' => 1, 'amount' => 100, 'snapshot' => ['record' => ['stage' => 'stored']],
        ]);
        $this->assertSame('0.00', $this->application->fresh()->stored_materials_amount);
        $prepared = $this->service()->prepare($this->application->id);
        $this->assertFalse($prepared['success']);
        $this->assertStringContainsString('반입·보관 기성', $prepared['error']);
        $sent = $this->transition($submission, 'sent');
        $this->assertFalse($sent['success']);
        $this->assertStringContainsString('반입·보관 기성', $sent['error']);
        $this->assertSame('prepared', $submission->fresh()->status);
        $this->assertSame('100.00', $this->application->fresh()->this_period_amount);
        $this->assertSame('100.00', PayApplicationAllocation::firstOrFail()->amount);
    }

    public function test_a_returned_packet_requires_a_reason_and_keeps_its_existing_state_on_failure(): void
    {
        $submission = $this->prepared();
        $this->assertTrue($this->transition($submission, 'sent')['success']);
        $result = $this->transition($submission, 'changes_requested', ['note' => ' ']);
        $this->assertFalse($result['success']);
        $this->assertSame('sent', $submission->fresh()->status);
        $this->assertCount(2, $submission->fresh()->history);
    }

    public function test_failed_financial_validation_rolls_back_sent_state_and_history(): void
    {
        $this->application->update(['this_period_amount' => 0]);
        $submission = $this->prepared();
        $result = $this->transition($submission, 'sent');
        $this->assertFalse($result['success']);
        $this->assertSame('prepared', $submission->fresh()->status);
        $this->assertCount(1, $submission->fresh()->history);
        $this->assertNull($submission->fresh()->sent_at);
        $this->assertSame('draft', $this->application->fresh()->status);
    }

    public function test_receipt_and_acceptance_require_real_response_files_and_financial_approval_is_separate(): void
    {
        $submission = $this->prepared();
        $this->assertTrue($this->transition($submission, 'sent')['success']);
        $this->assertSame('submitted', $this->application->fresh()->status);
        $this->assertFalse($this->transition($submission, 'received', ['response_document' => null])['success']);
        $this->assertTrue($this->transition($submission, 'received')['success']);
        $this->assertFalse(app(BillingAdminService::class)->setBillingStatus(['id' => $this->application->id, 'action' => 'approve'])['success']);
        $this->assertTrue($this->transition($submission, 'accepted')['success']);
        $this->assertSame('submitted', $this->application->fresh()->status);
        // Approval retains the received original even if the mutable source is later replaced.
        Storage::disk('local')->put($this->document->file_path, 'Later document replacement');
        $approval = app(BillingAdminService::class)->setBillingStatus(['id' => $this->application->id, 'action' => 'approve', 'approvedAmount' => 90]);
        $this->assertTrue($approval['success'], $approval['error'] ?? '');
        $this->assertSame('approved', $this->application->fresh()->status);
        $this->assertSame('100.00', $this->application->fresh()->amount_due);
        $this->assertSame('90.00', $this->application->fresh()->approved_amount);
    }

    public function test_recorded_response_can_be_downloaded_after_source_deletion_and_requires_contract_access(): void
    {
        $submission = $this->prepared();
        $this->assertTrue($this->transition($submission, 'sent')['success']);
        $this->assertTrue($this->transition($submission, 'received')['success']);
        $response = $this->service()->getResponseDownload($submission->id, 2);
        $this->assertSame('Original measured work proof', Storage::disk($response['disk'])->get($response['path']));
        $this->document->delete();
        $response = $this->service()->getResponseDownload($submission->id, 2);
        $this->assertSame('Original measured work proof', Storage::disk($response['disk'])->get($response['path']));
        $this->actingAs(User::factory()->create(['access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active']));
        $this->expectException(InvalidArgumentException::class);
        $this->service()->getResponseDownload($submission->id, 2);
    }

    public function test_returned_packet_gets_a_new_revision_without_rewriting_old_bytes_or_claim_amounts(): void
    {
        $first = $this->prepared();
        $originalZip = Storage::disk('local')->get($first->file_path);
        $this->assertTrue($this->transition($first, 'sent')['success']);
        $this->assertTrue($this->transition($first, 'changes_requested', ['note' => 'Replace the signed inspection sheet.'])['success']);
        $replacement = $this->document([], 'Corrected inspection sheet');
        $this->assertTrue($this->select($replacement)['success']);
        $result = $this->service()->prepare($this->application->id);
        $this->assertTrue($result['success'], $result['error'] ?? '');
        $second = ClaimSubmission::findOrFail($result['submission']['id']);
        $this->assertSame(2, $second->revision);
        $this->assertSame('changes_requested', $first->fresh()->status);
        $this->assertSame($originalZip, Storage::disk('local')->get($first->file_path));
        $this->assertSame($first->application_snapshot, $second->application_snapshot);
        $this->assertFalse($this->transition($first, 'accepted')['success']);
        $this->assertTrue($this->transition($second, 'sent')['success']);
        $this->assertSame('100.00', $this->application->fresh()->amount_due);
    }

    public function test_prepare_and_sent_use_the_site_day_near_midnight(): void
    {
        $this->contract->site->update(['timezone' => 'America/New_York']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T02:00:00Z'));
        $submission = $this->prepared();
        $this->assertTrue($this->transition($submission, 'sent', ['occurred_at' => '2026-10-11'])['success']);
    }

    public function test_out_of_scope_actor_cannot_read_workspace_or_download_archives(): void
    {
        $submission = $this->prepared();
        $otherSite = Site::create(['code' => 'OTHER-PACKET', 'name' => 'Other site', 'status' => 'active']);
        $this->actingAs(User::factory()->create([
            'access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_site_id' => $otherSite->id, 'account_status' => 'active',
        ]));
        $this->assertFalse($this->service()->workspace($this->application->id)['success']);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->getDownload($submission->id);
    }

    public function test_read_only_billing_user_cannot_prepare_or_record_external_acceptance(): void
    {
        $submission = $this->prepared();
        $this->actingAs(User::factory()->create(['access_role' => 'payroll', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $this->assertTrue($this->service()->workspace($this->application->id)['success']);
        $this->assertFalse($this->select()['success']);
        $this->assertFalse($this->transition($submission, 'sent')['success']);
        $this->assertFalse($this->service()->prepare($this->application->id)['success']);
    }

    public function test_final_requirements_activate_only_for_final_and_retainage_release_claims(): void
    {
        $payload = $this->contract->payload;
        $payload['claim_profile']['requirements'][] = ['key' => 'handover', 'title' => 'Handover acceptance', 'stage' => 'final', 'required' => true];
        $this->contract->update(['payload' => $payload]);
        $this->assertTrue($this->select()['success']);
        $this->assertTrue($this->service()->prepare($this->application->id)['success']);
        $this->application->update(['type' => 'final']);
        $this->assertFalse($this->service()->prepare($this->application->id)['success']);
        $this->application->update(['type' => 'retainage_release']);
        $this->assertFalse($this->service()->prepare($this->application->id)['success']);
    }

    public function test_once_evidence_is_suggested_for_the_next_claim_but_revalidated_for_expiry(): void
    {
        $payload = $this->contract->payload;
        $payload['claim_profile']['requirements'][0]['stage'] = 'once';
        $this->contract->update(['payload' => $payload]);
        $first = $this->prepared();
        $this->assertTrue($this->transition($first, 'sent')['success']);
        $created = app(BillingAdminService::class)->saveBilling([
            'projectContractId' => $this->contract->id, 'periodEnd' => '2026-10-11', 'thisPeriodAmount' => 50,
        ]);
        $this->assertTrue($created['success']);
        $workspace = $this->service()->workspace($created['id']);
        $this->assertSame($this->document->id, $workspace['draftEvidence']['work_proof'][0]['id']);
        $this->document->update(['expires_on' => '2026-10-10']);
        $this->assertFalse($this->service()->prepare($created['id'])['success']);
        $this->assertSame(1, ClaimSubmission::count());
    }

    public function test_an_archive_does_not_bypass_a_later_document_privacy_restriction(): void
    {
        $submission = $this->prepared();
        $this->document->update(['confidentiality' => 'restricted']);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->getDownload($submission->id);
    }

    public function test_retained_archive_survives_source_expiry_and_deletion(): void
    {
        $submission = $this->prepared();
        $this->document->update(['expires_on' => '2026-10-10']);
        $this->assertSame($submission->id, $this->service()->getDownload($submission->id)->id);
        $this->document->delete();
        $this->assertSame($submission->id, $this->service()->getDownload($submission->id)->id);
    }

    public function test_retained_files_do_not_treat_scope_hidden_sources_as_deleted(): void
    {
        $administrator = auth()->user();
        $daily = $this->document([], 'Daily work original');
        $response = $this->document([], 'Owner response original');
        $this->evidenceApplication([['type' => 'document', 'id' => $daily->id, 'locator' => 'Work proof']]);
        $submission = $this->prepared();
        $this->assertTrue($this->transition($submission, 'sent')['success']);
        $this->assertTrue($this->transition($submission, 'received', [
            'response_document' => ['type' => 'document', 'id' => $response->id],
        ])['success']);
        $otherSite = Site::create(['company_id' => $this->contract->company_id,
            'code' => 'MOVED-PROOF', 'name' => 'Outside manager scope', 'status' => 'active']);
        $manager = User::factory()->create(['account_status' => 'active']);
        $manager->forceFill(JobAccess::grant([
            'jobRole' => 'office', 'jobDuties' => ['accounting'], 'companyId' => $this->contract->company_id,
            'scope' => 'site', 'siteIds' => [$this->contract->site_id],
        ]))->save();
        $this->actingAsPurchaseUser($manager);
        $this->assertSame($submission->id, $this->service()->getDownload($submission->id)->id);
        $this->assertNotEmpty($this->service()->getResponseDownload($submission->id, 2));

        foreach ([$this->document, $daily, $response] as $source) {
            $this->actingAs($administrator);
            $source->update(['site_id' => $otherSite->id]);
            $this->actingAsPurchaseUser($manager);
            $this->assertFalse(IntelligentDocument::whereKey($source->id)->exists());
            $this->assertTrue(IntelligentDocument::withoutGlobalScopes()->whereKey($source->id)->exists());
            try {
                $source->id === $response->id
                    ? $this->service()->getResponseDownload($submission->id, 2)
                    : $this->service()->getDownload($submission->id);
                $this->fail('A source hidden by current scope must not be treated as deleted.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
            $this->actingAs($administrator);
            $source->update(['site_id' => $this->contract->site_id]);
        }
        $this->assertSame('100.00', $this->application->fresh()->amount_due);
    }

    public function test_corrupt_archive_is_never_offered_as_a_successful_download(): void
    {
        $submission = $this->prepared();
        Storage::disk('local')->put($submission->file_path, 'corrupt archive');
        $this->expectException(InvalidArgumentException::class);
        $this->service()->getDownload($submission->id);
    }

    public function test_legacy_submitted_claim_can_record_past_dispatch_and_response_without_recalculating_money(): void
    {
        $this->application->update([
            'status' => 'submitted', 'submitted_on' => '2026-09-20',
            'previous_billed_amount' => 250, 'cumulative_amount' => 350,
            'retainage_held' => 0, 'earned_less_retainage' => 350,
            'previous_certificates' => 230, 'amount_due' => 120,
        ]);
        $this->document->update(['expires_on' => '2026-09-30', 'status' => 'expired']);
        $workspace = $this->service()->workspace($this->application->id);
        $this->assertSame('2026-09-20', $workspace['application']['historicalSubmittedOn']);
        $submission = $this->prepared();
        $this->assertSame('2026-09-20', $submission->manifest['documentsValidatedOn']);
        $this->assertSame('120.00', $submission->application_snapshot['due']);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($submission->file_path)));
        $this->assertStringContainsString('Reconstructed record', $zip->getFromName('claim-summary.html'));
        $zip->close();
        $this->assertFalse($this->transition($submission, 'sent', ['occurred_at' => '2026-09-19'])['success']);
        $this->assertTrue($this->transition($submission, 'sent', ['occurred_at' => '2026-09-20'])['success']);
        $this->assertFalse(app(BillingAdminService::class)->setBillingStatus(['id' => $this->application->id, 'action' => 'withdraw'])['success']);
        $this->assertTrue($this->transition($submission, 'accepted', ['occurred_at' => '2026-09-23'])['success']);
        $approved = app(BillingAdminService::class)->setBillingStatus([
            'id' => $this->application->id, 'action' => 'approve', 'approvedAmount' => 120, 'approvedOn' => '2026-09-23',
        ]);
        $this->assertTrue($approved['success'], $approved['error'] ?? '');
        $this->assertSame('2026-09-20', $this->application->fresh()->submitted_on->toDateString());
        $this->assertSame('250.00', $this->application->fresh()->previous_billed_amount);
        $this->assertSame('120.00', $this->application->fresh()->amount_due);
        $this->assertSame('2026-09-23', $submission->fresh()->response_at->toDateString());
    }

    public function test_resubmission_after_historical_reconstruction_requires_documents_valid_today(): void
    {
        $this->application->update(['status' => 'submitted', 'submitted_on' => '2026-09-20']);
        $this->document->update(['expires_on' => '2026-09-30']);
        $first = $this->prepared();
        $this->assertTrue($this->transition($first, 'sent', ['occurred_at' => '2026-09-20'])['success']);
        $this->assertTrue($this->transition($first, 'changes_requested', [
            'occurred_at' => '2026-09-23', 'note' => 'A current replacement is required.',
        ])['success']);
        $this->assertNull($this->service()->workspace($this->application->id)['application']['historicalSubmittedOn']);
        $this->assertFalse($this->service()->prepare($this->application->id)['success']);
        $replacement = $this->document([], 'Updated prescribed form');
        $this->assertTrue($this->select($replacement)['success']);
        $prepared = $this->service()->prepare($this->application->id);
        $this->assertTrue($prepared['success'], $prepared['error'] ?? '');
        $second = ClaimSubmission::findOrFail($prepared['submission']['id']);
        $this->assertNull($second->manifest['historicalSubmittedOn']);
        $this->assertSame('2026-10-11', $second->manifest['documentsValidatedOn']);
        $this->assertFalse($this->transition($second, 'sent', ['occurred_at' => '2026-09-20'])['success']);
        $this->assertSame('2026-09-20', $this->application->fresh()->submitted_on->toDateString());
    }

    public function test_document_expiry_uses_calendar_days_in_the_site_timezone(): void
    {
        $this->contract->site->update(['timezone' => 'America/New_York']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T02:00:00Z'));
        $this->document->update(['expires_on' => '2026-10-11']);
        $this->assertTrue($this->select()['success']);
        $this->assertTrue($this->service()->prepare($this->application->id)['success']);
    }

    public function test_legacy_completed_records_remain_readable_without_creating_submission_facts(): void
    {
        $this->application->update(['status' => 'paid', 'approved_amount' => 100, 'paid_at' => now()]);
        $workspace = $this->service()->workspace($this->application->id);
        $this->assertTrue($workspace['success']);
        $this->assertSame([], $workspace['submissions']);
        $this->assertFalse($this->service()->prepare($this->application->id)['success']);
        $this->assertSame('paid', $this->application->fresh()->status);
    }
}

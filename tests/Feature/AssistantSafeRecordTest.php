<?php

namespace Tests\Feature;

use App\Models\AssistantProposal;
use App\Models\Company;
use App\Models\DailyClosingReport;
use App\Models\Employee;
use App\Models\IntegratedDocument;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use App\Services\Assistant\AssistantProposalService;
use App\Services\Finance\DocumentExpenseConnector;
use App\Services\Finance\ExpenseRegistrationService;
use App\Support\ReceiptFilePayload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class AssistantSafeRecordTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private User $actor;

    private AssistantProposalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_assistant.mutations_enabled' => true, 'filesystems.documents_disk' => 'public']);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->company = Company::create(['code' => 'SAFE-CO', 'name' => 'Safe Company', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'SAFE-SITE', 'name' => 'Safe Site', 'status' => 'active']);
        $this->actor = User::factory()->create(['account_status' => 'active', 'access_role' => 'admin',
            'access_scope' => 'all_sites', 'allowed_company_id' => $this->company->id, 'allowed_site_id' => $this->site->id]);
        $this->actor->companies()->attach($this->company);
        $this->service = app(AssistantProposalService::class);
    }

    private function reject(callable $callback, int $status): void
    {
        try {
            $callback();
            $this->fail('Expected a rejected operation.');
        } catch (ValidationException $e) {
            $this->assertSame($status, $e->status);
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());
        } catch (ModelNotFoundException $e) {
            $this->assertSame(404, $status);
        }
    }

    private function confirm(array $preview): array
    {
        return $this->service->confirm($this->actor, $preview['id'], $preview['preview_token'], $preview['version'], true);
    }

    private function day(array $payload = []): array
    {
        return $this->service->create($this->actor, AssistantProposalService::CREATE_DAILY_REPORT, $this->site->id,
            $payload + ['report_date' => '2026-10-06', 'work_title' => 'Level three coordination',
                'work_today' => 'Reviewed revised duct layout', 'work_tomorrow' => 'Continue site coordination']);
    }

    private function expense(array $payload = []): array
    {
        return $this->service->create($this->actor, AssistantProposalService::CREATE_EXPENSE, $this->site->id,
            $payload + ['description' => 'Office receipt pending review', 'amount' => '125.50', 'currency' => 'USD',
                'expense_date' => '2026-10-06', 'accounting_account' => '6601 Office Supplies', 'payment_type' => 'corporate']);
    }

    private function document(array $attributes = []): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        $bytes = "%PDF-1.4\nSynthetic receipt ".$uuid;
        $path = 'documents/'.$uuid.'.pdf';
        Storage::disk('local')->put($path, $bytes);

        return IntelligentDocument::create($attributes + [
            'uuid' => $uuid, 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'uploaded_by' => $this->actor->id, 'owner_user_id' => $this->actor->id, 'access_level' => 'scope',
            'confidentiality' => 'internal', 'disk' => 'local', 'file_path' => $path,
            'original_file_name' => 'site-layout.pdf', 'stored_file_name' => $uuid.'.pdf',
            'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'title' => 'Site layout', 'category' => 'general', 'document_type' => 'drawing', 'ai_status' => 'ready',
            'folder_structure' => ['SAFE-CO', 'SAFE-SITE', 'general', 'drawing', '2026'],
            'virtual_path' => 'SAFE-CO / SAFE-SITE / general / drawing / 2026',
        ]);
    }

    private function receipt(array $attributes = []): IntelligentDocument
    {
        return $this->document($attributes + ['title' => 'Office receipt', 'document_type' => 'receipt', 'category' => 'finance',
            'original_file_name' => 'receipt.pdf', 'ai_payload' => ['money' => ['currency' => 'USD']]]);
    }

    private function category(IntelligentDocument $document, array $payload = []): array
    {
        return $this->service->create($this->actor, AssistantProposalService::UPDATE_DOCUMENT_CATEGORY,
            $this->site->id, $payload + ['category' => 'drawing_spec'], $document->id);
    }

    private function noOutboundEffects(): void
    {
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_daily_report_preview_creates_nothing_and_confirmation_creates_one_unsubmitted_canonical_draft(): void
    {
        $preview = $this->day();
        $this->assertDatabaseCount('daily_closing_reports', 0);
        $this->assertNull($preview['before']);
        $this->assertSame('site_daily_report', $preview['visibility']);
        $result = $this->confirm($preview);
        $this->assertSame($result, $this->confirm($preview));
        $this->assertSame($preview['after'], $result['after']);
        $this->assertDatabaseCount('daily_closing_reports', 1);
        $report = DailyClosingReport::findOrFail($result['record_id']);
        $this->assertSame('draft', $report->field_status);
        $this->assertSame('open', $report->status);
        $this->assertNull($report->field_submitted_at);
        $this->assertNull($report->closed_at);
        $this->assertNull($report->narrative);
        $this->assertDatabaseCount('report_dispatches', 0);
        $this->noOutboundEffects();
    }

    public function test_daily_report_fills_only_empty_field_text_on_the_existing_plan_row(): void
    {
        $report = DailyClosingReport::create(['site_id' => $this->site->id, 'report_date' => '2026-10-06', 'status' => 'open',
            'plan' => ['workScope' => 'Existing plan', 'custom' => ['preserved' => true]], 'plan_status' => 'submitted',
            'plan_submitted_at' => now(), 'weather' => 'Sunny', 'tbm_completed' => true, 'safety_checks' => ['existing' => true]]);
        $original = $report->refresh()->getRawOriginal();
        $preview = $this->day();
        $this->assertSame($report->id, $preview['record_id']);
        $result = $this->confirm($preview);
        $this->assertSame($report->id, $result['record_id']);
        $changed = array_flip(['work_title', 'work_today', 'work_tomorrow', 'field_status', 'updated_at']);
        $this->assertSame(array_diff_key($original, $changed), array_diff_key($report->fresh()->getRawOriginal(), $changed));
        $this->assertDatabaseCount('daily_closing_reports', 1);
    }

    public function test_daily_report_rejects_existing_field_work_submission_closing_or_unreviewed_numeric_claims(): void
    {
        foreach ([['work_today' => 'Human report'], ['field_status' => 'submitted'], ['field_submitted_at' => now()],
            ['status' => 'writing'], ['status' => 'done'], ['closed_at' => now()], ['progress_rate' => 1], ['trades' => [['name' => 'MEP']]]] as $attributes) {
            $record = DailyClosingReport::create($attributes + ['site_id' => $this->site->id, 'report_date' => '2026-10-06', 'status' => 'open']);
            $this->reject(fn () => $this->day(), 422);
            $record->delete();
        }
        foreach (['field_status', 'status', 'progress_rate', 'trades', 'tbm_completed', 'safety_checks', 'plan'] as $field) {
            $this->reject(fn () => $this->day([$field => 'unreviewed']), 422);
        }
        $this->reject(fn () => $this->day(['work_today' => 'Pay invoice 100 USD']), 422);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_daily_report_created_or_edited_since_preview_is_never_overwritten(): void
    {
        $preview = $this->day();
        $report = DailyClosingReport::create(['site_id' => $this->site->id, 'report_date' => '2026-10-06', 'status' => 'open', 'work_today' => 'Human report']);
        $this->reject(fn () => $this->confirm($preview), 409);
        $this->assertSame('Human report', $report->fresh()->work_today);
        $this->assertSame('stale', AssistantProposal::findOrFail($preview['id'])->status);
        $report->update(['work_today' => null]);
        $next = $this->day();
        DB::table('daily_closing_reports')->where('id', $report->id)->update(['weather' => 'Cloudy']);
        $this->reject(fn () => $this->confirm($next), 409);
        $this->assertNull($report->fresh()->work_today);
    }

    public function test_manual_expense_is_exact_usd_pending_review_only_and_idempotent(): void
    {
        $preview = $this->expense();
        $this->assertSame('finance_pending_review', $preview['visibility']);
        $this->assertSame('USD', $preview['after']['currency']);
        $this->assertSame('125.50', $preview['after']['amount']);
        $this->assertSame('pending', $preview['after']['status']);
        $this->assertDatabaseCount('mobile_expenses', 0);
        $result = $this->confirm($preview);
        $this->assertSame($result, $this->confirm($preview));
        $expense = MobileExpense::findOrFail($result['record_id']);
        $this->assertSame('pending', $expense->status);
        $this->assertSame('assistant:'.$preview['id'], $expense->source_ref);
        $this->assertNull($expense->paid_at);
        $this->assertNull($expense->reviewed_at);
        $this->assertNull($expense->payroll_run_id);
        $this->assertNull($expense->employee_id);
        $this->assertDatabaseCount('mobile_expenses', 1);
        $this->assertDatabaseCount('integrated_documents', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->noOutboundEffects();
    }

    public function test_expense_requires_financial_permission_exact_currency_cents_and_allowlisted_fields(): void
    {
        foreach (['site_manager', 'worker', 'foreman', 'viewer', 'vendor_admin', 'payroll'] as $role) {
            $this->actor->update(['access_role' => $role]);
            $this->reject(fn () => $this->expense(), 403);
        }
        $this->actor->update(['access_role' => 'admin']);
        foreach (['EUR', 'KRW', null, ''] as $currency) {
            $this->reject(fn () => $this->expense(['currency' => $currency]), 422);
        }
        foreach (['1.001', '-1', '0', '1e3', '1,000', '1000000000000', 'NaN'] as $amount) {
            $this->reject(fn () => $this->expense(['amount' => $amount]), 422);
        }
        foreach (['status', 'paid_at', 'payment_reference', 'employee_id', 'vendor_id', 'project_id', 'receipt_path', 'receipt_file', 'ocr_data', 'source_ref'] as $field) {
            $this->reject(fn () => $this->expense([$field => 'untrusted']), 422);
        }
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_personal_expense_is_only_for_the_current_active_same_site_employee_and_rechecks_employment(): void
    {
        $this->reject(fn () => $this->expense(['payment_type' => 'personal']), 403);
        $employee = Employee::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'first_name' => 'Fixture', 'last_name' => 'Employee', 'employment_status' => 'active']);
        $this->actor->update(['employee_id' => $employee->id]);
        $preview = $this->expense(['payment_type' => 'personal']);
        $this->assertSame($employee->id, $preview['after']['employee_id']);
        DB::table('employees')->where('id', $employee->id)->update(['employment_status' => 'inactive']);
        $this->reject(fn () => $this->confirm($preview), 403);
        $this->assertDatabaseCount('mobile_expenses', 0);
        DB::table('employees')->where('id', $employee->id)->update(['employment_status' => 'active']);
        $result = $this->confirm($preview);
        $this->assertSame($employee->id, MobileExpense::findOrFail($result['record_id'])->employee_id);
    }

    public function test_shared_owned_receipt_proof_is_retained_without_new_files_ocr_dispatch_or_duplicate_filing(): void
    {
        $source = $this->receipt();
        $bytes = Storage::disk('local')->get($source->file_path);
        $files = Storage::disk('local')->allFiles();
        $filed = IntegratedDocument::count();
        $publicFiles = Storage::disk('public')->allFiles();
        $preview = $this->expense(['source_document_id' => $source->id]);
        $this->assertSame($source->sha256, $preview['before']['source_receipt']['sha256']);
        $this->assertSame($source->id, $preview['after']['receipt']['document_id']);
        $this->assertDatabaseCount('mobile_expenses', 0);
        $result = $this->confirm($preview);
        $this->assertSame($result, $this->confirm($preview));
        $expense = MobileExpense::findOrFail($result['record_id']);
        $this->assertSame($bytes, ReceiptFilePayload::decode($expense->receipt_file));
        $this->assertSame('document:'.$source->id, $expense->source_ref);
        $this->assertSame($preview['after'], $result['after']);
        $this->assertTrue(app(ExpenseRegistrationService::class)->hasCanonicalReceipt($expense->fresh()));
        $this->assertDatabaseCount('intelligent_documents', 1);
        $this->assertDatabaseCount('integrated_documents', $filed);
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame($publicFiles, Storage::disk('public')->allFiles());
        $this->noOutboundEffects();
    }

    public function test_receipt_registration_refuses_private_nonowned_other_scope_unready_and_non_receipt_sources(): void
    {
        $other = User::factory()->create();
        $otherCompany = Company::create(['code' => 'OTHER-SAFE', 'name' => 'Other', 'status' => 'active']);
        $otherSite = Site::create(['company_id' => $otherCompany->id, 'code' => 'OTHER-SAFE', 'name' => 'Other', 'status' => 'active']);
        foreach ([['access_level' => 'private'], ['confidentiality' => 'confidential'], ['uploaded_by' => $other->id],
            ['owner_user_id' => $other->id], ['site_id' => $otherSite->id], ['company_id' => $otherCompany->id],
            ['ai_status' => 'analyzing'], ['document_type' => 'invoice'], ['ai_payload' => ['duplicate_document_id' => 123]]] as $attributes) {
            $source = $this->receipt($attributes);
            $this->reject(fn () => $this->expense(['source_document_id' => $source->id]), 404);
        }
        $this->assertDatabaseCount('assistant_proposals', 0);
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public function test_receipt_already_registered_before_or_after_preview_cannot_duplicate(): void
    {
        $source = $this->receipt();
        $first = $this->expense(['source_document_id' => $source->id]);
        $second = $this->expense(['source_document_id' => $source->id]);
        $this->confirm($first);
        $this->reject(fn () => $this->confirm($second), 409);
        $this->reject(fn () => $this->expense(['source_document_id' => $source->id]), 422);
        $this->assertDatabaseCount('mobile_expenses', 1);
        $this->assertSame('stale', AssistantProposal::findOrFail($second['id'])->status);
    }

    public function test_receipt_changed_row_or_proof_bytes_invalidates_approval(): void
    {
        $source = $this->receipt();
        $first = $this->expense(['source_document_id' => $source->id]);
        DB::table('intelligent_documents')->where('id', $source->id)->update(['title' => 'Human corrected receipt']);
        $this->reject(fn () => $this->confirm($first), 409);
        $this->assertSame('stale', AssistantProposal::findOrFail($first['id'])->status);
        $second = $this->expense(['source_document_id' => $source->id]);
        Storage::disk('local')->put($source->file_path, 'Different bytes');
        $this->reject(fn () => $this->confirm($second), 409);
        $this->assertSame('stale', AssistantProposal::findOrFail($second['id'])->status);
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public function test_receipt_permission_revoked_after_preview_blocks_preview_and_confirm(): void
    {
        $source = $this->receipt();
        $preview = $this->expense(['source_document_id' => $source->id]);
        $source->update(['access_level' => 'private']);
        $this->reject(fn () => $this->service->preview($this->actor, $preview['id']), 404);
        $this->reject(fn () => $this->confirm($preview), 404);
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public function test_receipt_source_size_type_currency_and_hash_are_checked_before_proposal(): void
    {
        foreach ([['file_size' => 11 * 1024 * 1024], ['file_size' => 0], ['extension' => 'html'],
            ['ai_payload' => ['money' => ['currency' => 'EUR']]]] as $attributes) {
            $source = $this->receipt($attributes);
            $this->reject(fn () => $this->expense(['source_document_id' => $source->id]), 422);
        }
        $bad = $this->receipt(['sha256' => str_repeat('a', 64)]);
        $this->reject(fn () => $this->expense(['source_document_id' => $bad->id]), 409);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_existing_receipt_filing_is_unchanged_and_a_spoofed_source_ref_cannot_skip_it(): void
    {
        $source = $this->receipt();
        $filed = IntegratedDocument::count();
        $publicFiles = count(Storage::disk('public')->allFiles());
        $expense = app(ExpenseRegistrationService::class)->registerPending([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'description' => 'Original receipt intake', 'amount' => '12.00', 'expense_date' => '2026-10-06',
            'payment_type' => 'corporate', 'category' => '6601 Office Supplies', 'accounting_account' => '6601 Office Supplies',
            'receipt_file' => ReceiptFilePayload::encode('Different receipt'), 'receipt_original_name' => 'receipt.pdf',
            'receipt_mime_type' => 'application/pdf', 'source_ref' => 'document:'.$source->id,
            'ocr_data' => ['document_id' => $source->id, 'receipt_proof_sha256' => $source->sha256],
        ]);
        $this->assertFalse(app(ExpenseRegistrationService::class)->hasCanonicalReceipt($expense));
        $this->assertDatabaseCount('integrated_documents', $filed + 1);
        $this->assertSame($expense->id, IntegratedDocument::whereJsonContains('fields->mobile_expense_id', $expense->id)->firstOrFail()->fields['mobile_expense_id']);
        $this->assertCount($publicFiles + 1, Storage::disk('public')->allFiles());
    }

    public function test_document_category_updates_reviewed_filing_only_and_preserves_original_privacy_and_links(): void
    {
        $document = $this->document(['access_level' => 'private', 'ai_payload' => ['folder_parts' => ['general', 'drawing', '2026'], 'custom' => 'retain']]);
        $original = $document->refresh()->getRawOriginal();
        $files = Storage::disk('local')->allFiles();
        $preview = $this->category($document);
        $this->assertSame('general', $document->fresh()->category);
        $this->assertSame('private', $preview['after']['access_level']);
        $this->assertSame('SAFE-CO / SAFE-SITE / drawing_spec / drawing / 2026', $preview['after']['virtual_path']);
        $result = $this->confirm($preview);
        $this->assertSame($result, $this->confirm($preview));
        $this->assertSame($preview['after'], $result['after']);
        $changed = array_flip(['category', 'folder_structure', 'virtual_path', 'reviewed_by', 'reviewed_at', 'updated_at']);
        $this->assertSame(array_diff_key($original, $changed), array_diff_key($document->fresh()->getRawOriginal(), $changed));
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('mobile_expenses', 0);
        $this->assertDatabaseCount('document_action_items', 0);
        $this->assertDatabaseCount('knowledge_facts', 0);
        $this->noOutboundEffects();
    }

    public function test_category_rejects_changes_to_privacy_type_source_scope_and_sensitive_classification(): void
    {
        $document = $this->document();
        foreach (['document_type', 'access_level', 'confidentiality', 'owner_user_id', 'site_id', 'company_id', 'project_id', 'file_path', 'ai_status', 'folder_structure'] as $field) {
            $this->reject(fn () => $this->category($document, [$field => 'untrusted']), 422);
        }
        foreach (['finance', 'hr', 'legal', 'contract', 'made_up'] as $category) {
            $this->reject(fn () => $this->category($document, ['category' => $category]), 422);
        }
        $this->reject(fn () => $this->category($document, ['category' => 'general']), 422);
        foreach ([['category' => 'finance'], ['document_type' => 'receipt'], ['summary' => 'Price: $300'],
            ['folder_structure' => ['Custom', 'Five', 'Segment', 'Path', 'Here'], 'virtual_path' => 'Custom / Five / Segment / Path / Here']] as $attributes) {
            $sensitive = $this->document($attributes);
            $this->reject(fn () => $this->category($sensitive), 422);
        }
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_category_cannot_expose_another_owners_private_document_even_for_admin(): void
    {
        $other = User::factory()->create();
        $document = $this->document(['owner_user_id' => $other->id, 'access_level' => 'private']);
        $this->reject(fn () => $this->category($document), 404);
        $document->update(['access_level' => 'scope']);
        $preview = $this->category($document);
        $document->update(['access_level' => 'private']);
        $this->reject(fn () => $this->service->preview($this->actor, $preview['id']), 404);
        $this->reject(fn () => $this->confirm($preview), 404);
        $this->assertSame('general', $document->fresh()->category);
    }

    public function test_category_stale_source_is_not_overwritten_and_ordinary_site_manager_can_correct_technical_categories(): void
    {
        $this->actor->update(['access_role' => 'site_manager', 'access_scope' => 'site']);
        $document = $this->document();
        $preview = $this->category($document);
        DB::table('intelligent_documents')->where('id', $document->id)->update(['summary' => 'Human updated summary']);
        $this->reject(fn () => $this->confirm($preview), 409);
        $this->assertSame('stale', AssistantProposal::findOrFail($preview['id'])->status);
        $next = $this->category($document->fresh());
        $this->confirm($next);
        $this->assertSame('drawing_spec', $document->fresh()->category);
    }

    public function test_source_reanalysis_never_changes_or_deletes_explicitly_registered_pending_expense(): void
    {
        $source = $this->receipt();
        $result = $this->confirm($this->expense(['source_document_id' => $source->id]));
        $expense = MobileExpense::findOrFail($result['record_id']);
        $original = $expense->getRawOriginal();
        unset($original['receipt_file']);
        foreach ([['flow' => 'out', 'amount' => 999, 'currency' => 'USD', 'category_hint' => 'fuel'], ['flow' => 'none']] as $money) {
            $source->update(['ai_payload' => ['money' => $money]]);
            app(DocumentExpenseConnector::class)->sync($source->fresh());
            $current = $expense->fresh()->getRawOriginal();
            unset($current['receipt_file']);
            $this->assertSame($original, $current);
        }
        $this->assertDatabaseCount('mobile_expenses', 1);
    }

    public function test_expense_amount_round_trips_at_postgresql_precision_limit(): void
    {
        foreach (['0.01', '125', '999999999999.99'] as $amount) {
            $preview = $this->expense(['amount' => $amount]);
            $result = $this->confirm($preview);
            $this->assertSame($preview['after']['amount'], MobileExpense::findOrFail($result['record_id'])->amount);
        }
        $this->reject(fn () => $this->expense(['amount' => '1000000000000.00']), 422);
    }

    public function test_manual_duplicate_detection_is_scoped_and_rechecked_at_confirmation(): void
    {
        $first = $this->expense();
        $second = $this->expense();
        $this->confirm($first);
        $this->reject(fn () => $this->confirm($second), 409);
        $this->reject(fn () => $this->expense(), 422);
        $this->assertSame('stale', AssistantProposal::findOrFail($second['id'])->status);
        $otherCompany = Company::create(['code' => 'DUP-OTHER', 'name' => 'Other', 'status' => 'active']);
        $otherSite = Site::create(['company_id' => $otherCompany->id, 'code' => 'DUP-OTHER', 'name' => 'Other', 'status' => 'active']);
        $expense = MobileExpense::firstOrFail();
        $expense->update(['company_id' => $otherCompany->id, 'site_id' => $otherSite->id]);
        $new = $this->expense();
        $this->confirm($new);
        $this->assertDatabaseCount('mobile_expenses', 2);
    }

    public function test_receipt_active_content_or_mismatched_safe_mime_is_never_copied(): void
    {
        $source = $this->receipt(['mime_type' => 'text/html']);
        $this->reject(fn () => $this->expense(['source_document_id' => $source->id]), 422);
        $source = $this->receipt();
        $html = '<html><script>window.fixture = true;</script></html>';
        Storage::disk('local')->put($source->file_path, $html);
        $source->update(['file_size' => strlen($html), 'sha256' => hash('sha256', $html)]);
        $this->reject(fn () => $this->expense(['source_document_id' => $source->id]), 422);
        $source = $this->receipt(['original_file_name' => "bad\r\nHeader.pdf"]);
        $this->reject(fn () => $this->expense(['source_document_id' => $source->id]), 422);
        $this->assertDatabaseCount('assistant_proposals', 0);
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public function test_new_operations_remain_disabled_without_the_mutation_gate(): void
    {
        $document = $this->document();
        $previews = [$this->day(), $this->expense(), $this->category($document)];
        config(['ai_assistant.mutations_enabled' => false]);
        foreach ([fn () => $this->day(), fn () => $this->expense(), fn () => $this->category($document)] as $action) {
            $this->reject($action, 403);
        }
        foreach ($previews as $preview) {
            $this->reject(fn () => $this->confirm($preview), 403);
        }
        $this->assertDatabaseCount('mobile_expenses', 0);
        $this->assertDatabaseCount('daily_closing_reports', 0);
        $this->assertSame('general', $document->fresh()->category);
    }
}

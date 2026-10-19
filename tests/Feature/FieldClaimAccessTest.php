<?php

namespace Tests\Feature;

use App\Models\ClaimWorkRecord;
use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSection;
use App\Services\Auth\EmailPasswordAuthService;
use App\Support\JobAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FieldClaimAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $admin;

    private ContractBoqLine $line;

    private ContractBoqLine $other;

    private WorkSection $section;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['document-intelligence.disk' => 'local']);
        $company = Company::create(['code' => 'FIELD', 'name' => 'Field', 'status' => 'active']);
        $site = Site::create(['code' => 'FIELD', 'name' => 'Field', 'company_id' => $company->id, 'status' => 'active']);
        Team::create(['code' => 'FIELD', 'name' => 'Pipe', 'company_id' => $company->id, 'site_id' => $site->id, 'trade_type' => '배관']);
        $contract = ProjectContract::create(['company_id' => $company->id, 'site_id' => $site->id, 'title' => 'Secret contract', 'direction' => 'receivable', 'status' => 'active', 'original_amount' => 987654]);
        $this->section = WorkSection::create(['company_id' => $company->id, 'site_id' => $site->id, 'project_contract_id' => $contract->id, 'division' => 'MEP', 'code' => 'M1', 'name' => 'Pipe']);
        $this->section->forceFill(['claim_trade' => '배관'])->save();
        $other = WorkSection::create(['company_id' => $company->id, 'site_id' => $site->id, 'project_contract_id' => $contract->id, 'division' => 'MEP', 'code' => 'E1', 'name' => 'Other team']);
        $other->forceFill(['claim_trade' => '전기'])->save();
        $values = ['project_contract_id' => $contract->id, 'description' => 'Pipe', 'unit' => 'LF', 'contract_qty' => 100, 'unit_price' => 4321.98, 'recognition_basis' => 'quantity', 'status' => 'accepted'];
        $this->line = ContractBoqLine::create($values + ['work_section_id' => $this->section->id, 'line_no' => '1']);
        $this->other = ContractBoqLine::create($values + ['work_section_id' => $other->id, 'line_no' => '2']);
        $this->admin = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $this->manager = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active', 'access_scope' => 'trade',
            'job_role' => 'claim_recorder', 'job_trade' => '배관', 'allowed_company_id' => $company->id, 'allowed_site_id' => $site->id,
            'job_site_ids' => [$site->id], 'job_permissions' => JobAccess::defaults('claim_recorder')]);
        $this->actingAsPurchaseUser($this->manager);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['workDate' => now()->subDay()->toDateString(), 'location' => 'Grid A', 'stage' => 'installed', 'reportedQty' => 5, 'notes' => 'Installed', 'evidence' => []];
    }

    private function saveUrl(?ContractBoqLine $line = null): string
    {
        return '/field-claims/lines/'.($line ?? $this->line)->id.'/records';
    }

    public function test_workspace_only_returns_assigned_quantities_and_no_financial_payload(): void
    {
        $this->get('/')->assertRedirect('/field-claims');
        $this->get('/field-claims')->assertOk()->assertSee('담당 공정 작업');
        $response = $this->getJson('/field-claims/data')->assertOk()->assertJsonCount(1, 'sections')->assertJsonPath('sections.0.lines.0.id', $this->line->id);
        foreach (['987654', '4321.98', 'unitPrice', 'contractAmount', 'stageWeights', 'Secret contract', 'Other team', 'filePath'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->actingAsPurchaseUser($this->admin);
        $this->section->forceFill(['claim_trade' => null])->save();
        $this->actingAsPurchaseUser($this->manager);
        $this->getJson('/field-claims/data')->assertOk()->assertJsonCount(0, 'sections');
        $this->postJson($this->saveUrl(), $this->payload())->assertNotFound();
    }

    public function test_reports_use_existing_pending_ledger_and_ignore_forged_approval_fields(): void
    {
        $id = $this->postJson($this->saveUrl(), $this->payload(['status' => 'verified', 'verifiedQty' => 100, 'recordKind' => 'source_claim', 'reported_by' => $this->admin->id]))
            ->assertOk()->assertJsonPath('status', 'pending')->json('id');
        $this->assertDatabaseHas('claim_work_records', ['id' => $id, 'record_kind' => 'actual', 'status' => 'pending', 'verified_qty' => null, 'reported_by' => $this->manager->id]);
        $this->postJson($this->saveUrl(), $this->payload(['id' => $id, 'reportedQty' => 6]))->assertOk();
        $this->postJson($this->saveUrl(), $this->payload(['reportedQty' => 101]))->assertUnprocessable();
        $this->postJson($this->saveUrl(), $this->payload(['workDate' => now()->addDays(2)->toDateString()]))->assertUnprocessable();
        $this->postJson($this->saveUrl($this->other), $this->payload())->assertNotFound();
        $this->actingAsPurchaseUser($this->admin);
        ClaimWorkRecord::findOrFail($id)->forceFill(['status' => 'verified', 'verified_qty' => 6])->save();
        $this->actingAsPurchaseUser($this->manager);
        $this->postJson($this->saveUrl(), $this->payload(['id' => $id]))->assertForbidden();
    }

    public function test_only_own_uploaded_evidence_can_be_attached_and_originals_stay_private(): void
    {
        $file = UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nfield proof");
        $upload = $this->post('/field-claims/lines/'.$this->line->id.'/attachments', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $attachment = $upload->json('attachment.id');
        $this->assertStringNotContainsString('filePath', $upload->getContent());
        $this->postJson($this->saveUrl(), $this->payload(['evidence' => [['attachmentId' => $attachment, 'locator' => 'p1']]]))->assertOk();
        $row = DB::table('field_claim_attachments')->where('id', $attachment)->first();
        $this->getJson(route('document-intelligence.preview', $row->intelligent_document_id, false))->assertForbidden();
        $this->postJson($this->saveUrl(), $this->payload(['location' => 'Grid B', 'evidence' => [['attachmentId' => $attachment + 999, 'locator' => 'p1']]]))->assertForbidden();
        $peer = $this->manager->replicate();
        $peer->email = 'peer@example.test';
        $peer->save();
        $this->actingAsPurchaseUser($peer);
        $this->getJson('/field-claims/data')->assertOk()->assertJsonCount(0, 'sections.0.lines.0.records')->assertJsonCount(0, 'sections.0.lines.0.attachments');
        $this->postJson($this->saveUrl(), $this->payload(['evidence' => [['attachmentId' => $attachment, 'locator' => 'p1']]]))->assertForbidden();
    }

    public function test_financial_legacy_exports_approval_and_assignment_are_denied_even_with_custom_grants(): void
    {
        $this->manager->forceFill(['job_permissions' => ['progress' => ['view', 'edit'], 'contracts' => ['view', 'edit', 'approve', 'export'], 'documents' => ['view', 'export'], 'system' => ['view', 'edit']]])->save();
        foreach (['api_getClaimEditor', 'api_getSectionDrawings', 'api_getSectionLines', 'api_reviewClaimRecord', 'api_setJobAccess'] as $method) {
            $this->postJson('/smart-company-api/'.$method, ['args' => [$this->line->project_contract_id]])->assertForbidden();
        }
        $this->getJson('/claim-editor/lines/'.$this->line->id.'/export/docx')->assertForbidden();
        $this->postJson('/field-claims/sections/'.$this->section->id.'/assignment', ['trade' => '전기'])->assertForbidden();
        $this->assertFalse(JobAccess::can($this->manager, 'contracts'));
    }

    public function test_only_strong_superadmin_can_assign_existing_site_trade(): void
    {
        $this->actingAsPurchaseUser($this->admin);
        $url = '/field-claims/sections/'.$this->section->id.'/assignment';
        $this->postJson($url, ['trade' => '없는 공종'])->assertUnprocessable();
        $this->postJson($url, ['trade' => null])->assertOk();
        $this->postJson($url, ['trade' => '배관'])->assertOk();
        $this->assertDatabaseHas('auth_events', ['event' => 'field_claim_assignment_changed', 'actor_id' => $this->admin->id]);
    }

    public function test_receipt_reupload_is_idempotent_and_cannot_reveal_existing_financial_metadata(): void
    {
        $content = "%PDF-1.4\ninvoice same bytes";
        $url = '/field-claims/lines/'.$this->line->id.'/attachments';
        $a = $this->post($url, ['file' => UploadedFile::fake()->createWithContent('invoice.pdf', $content)], ['Accept' => 'application/json'])->assertOk()->json('attachment.id');
        $row = DB::table('field_claim_attachments')->where('id', $a)->first();
        DB::table('intelligent_documents')->where('id', $row->intelligent_document_id)->update(['document_type' => 'invoice', 'title' => 'Secret price 987654']);
        $response = $this->post($url, ['file' => UploadedFile::fake()->createWithContent('invoice.pdf', $content)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('attachment.id', $a);
        $this->assertStringNotContainsString('987654', $response->getContent());
        $this->assertSame(1, DB::table('field_claim_attachments')->count());
        $this->postJson($this->saveUrl(), $this->payload(['evidence' => [['attachmentId' => $a, 'locator' => 'p1']]]))->assertOk();
        DB::table('intelligent_documents')->where('id', $row->intelligent_document_id)->update(['sha256' => str_repeat('0', 64)]);
        $this->postJson($this->saveUrl(), $this->payload(['location' => 'Grid B', 'evidence' => [['attachmentId' => $a, 'locator' => 'p1']]]))->assertConflict();
    }

    public function test_company_site_and_login_revocation_apply_to_every_write(): void
    {
        $this->assertSame('/field-claims', $this->manager->landingPath());
        $this->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => 0]);
        $this->postJson($this->saveUrl(), $this->payload())->assertForbidden();
        $this->actingAsPurchaseUser($this->manager);
        $this->manager->forceFill(['job_site_ids' => [999999], 'allowed_site_id' => null])->save();
        $this->getJson('/field-claims/data')->assertOk()->assertJsonCount(0, 'sections');
        $this->postJson($this->saveUrl(), $this->payload())->assertNotFound();
        $this->manager->forceFill(['job_site_ids' => [$this->section->site_id], 'allowed_company_id' => null])->save();
        $this->getJson('/field-claims/data')->assertOk()->assertJsonCount(0, 'sections');
        $this->postJson($this->saveUrl(), $this->payload())->assertNotFound();
    }

    public function test_quantity_profile_grants_cannot_add_money_or_approval_and_preserve_normal_trade_role(): void
    {
        $this->actingAsPurchaseUser($this->admin);
        $grant = JobAccess::grant(['jobRole' => 'claim_recorder', 'companyId' => $this->section->company_id,
            'siteIds' => [$this->section->site_id], 'scope' => 'trade', 'jobTrade' => '배관',
            'jobPermissions' => ['progress' => ['view', 'edit', 'approve'], 'contracts' => ['view', 'edit']]]);
        $this->assertSame(['progress' => ['view', 'edit']], $grant['job_permissions']);
        $this->assertSame('trade', $grant['access_scope']);
        $normal = $this->manager->replicate();
        $normal->forceFill(['job_role' => 'trade_manager', 'job_permissions' => JobAccess::defaults('trade_manager')]);
        $this->assertTrue(JobAccess::can($normal, 'attendance', 'approve'));
        $this->assertFalse(JobAccess::can($this->manager, 'contracts'));
    }
}

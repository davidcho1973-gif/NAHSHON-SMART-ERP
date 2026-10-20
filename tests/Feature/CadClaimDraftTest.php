<?php

namespace Tests\Feature;

use App\Models\CadDrawing;
use App\Models\ClaimEditDraft;
use App\Models\ClaimWorkRecord;
use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\PayApplication;
use App\Models\PayApplicationAllocation;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Drawings\CadDrawingService;
use App\Services\Finance\ClaimDailyEvidencePacketService;
use App\Services\Finance\ClaimEditingService;
use App\Services\Finance\ClaimEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CadClaimDraftTest extends TestCase
{
    use RefreshDatabase;

    private CadDrawing $drawing;

    private ContractBoqLine $line;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['cad.disk' => 'local', 'cad.converter_binary' => null]);
        $company = Company::create(['code' => 'CAD-CLAIM', 'name' => 'CAD Claim', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'CAD-CLAIM', 'name' => 'CAD Claim', 'status' => 'active']);
        $contract = ProjectContract::create(['company_id' => $company->id, 'site_id' => $site->id, 'title' => 'CAD Claim', 'direction' => 'receivable', 'status' => 'active', 'original_amount' => 1000]);
        $this->line = ContractBoqLine::create(['project_contract_id' => $contract->id, 'line_no' => '1', 'description' => 'Pipe', 'unit' => 'm', 'contract_qty' => 100, 'unit_price' => 10, 'status' => 'accepted']);
        $this->actor = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($this->actor);
        $response = $this->postJson('/admin-api/cad', ['site_id' => $site->id, 'title' => 'Pipe Plan', 'file' => new UploadedFile(base_path('tests/Fixtures/cad-line.dxf'), 'pipe.dxf', 'application/dxf', null, true)])->assertCreated();
        $this->drawing = CadDrawing::findOrFail($response->json('id'));
    }

    private function attach(int $draftRevision = 0, int $drawingRevision = 1)
    {
        return $this->postJson('/admin-api/cad/'.$this->drawing->id.'/claim-draft', ['lineId' => $this->line->id, 'drawingRevision' => $drawingRevision, 'draftRevision' => $draftRevision]);
    }

    public function test_link_preserves_draft_quantity_and_is_idempotent_without_work_or_allocation_mutation(): void
    {
        $saved = app(ClaimEditingService::class)->save(['lineId' => $this->line->id, 'revision' => 0,
            'content' => ['quantity' => 7, 'notes' => 'Do not change', 'location' => 'Room 1', 'calculations' => []]]);
        $this->assertTrue($saved['success']);
        $response = $this->attach(1)->assertOk()->assertJsonPath('draft.revision', 2)->assertJsonPath('draft.content.quantity', 7)
            ->assertJsonPath('draft.content.notes', 'Do not change')->assertJsonPath('draft.content.location', 'Room 1');
        $proof = $response->json('evidence');
        $this->assertSame($this->drawing->revisions()->first()->sha256, $proof['sha256']);
        $this->assertSame(1, $proof['drawingRevision']);
        $this->assertStringContainsString('drawing_id='.$this->drawing->id.'&revision=1', $proof['url']);
        $this->attach(1)->assertConflict();
        $this->attach(2)->assertOk()->assertJsonPath('alreadyAttached', true)->assertJsonPath('draft.revision', 2);
        $this->assertCount(1, ClaimEditDraft::first()->content['evidence']);
        $this->assertDatabaseCount('claim_work_records', 0);
        $this->assertDatabaseCount('pay_application_allocations', 0);
        $this->getJson('/admin-api/cad/claim-context?lineId='.$this->line->id)->assertOk()->assertJsonPath('draftRevision', 2);
    }

    public function test_pinned_revision_survives_new_head_and_normal_save_and_commit(): void
    {
        $attached = $this->attach()->assertOk();
        $proof = $attached->json('draft.content.evidence.0');
        $old = app(CadDrawingService::class)->read($this->drawing)['dxf'];
        $new = str_replace("11\n100", "11\n200", $old);
        $this->putJson('/admin-api/cad/'.$this->drawing->id, ['revision' => 1, 'dxf' => $new])->assertOk();
        $draft = $attached->json('draft.content');
        $draft['quantity'] = 3;
        $draft['location'] = 'Room 2';
        $saved = app(ClaimEditingService::class)->save(['lineId' => $this->line->id, 'revision' => 1, 'content' => $draft]);
        $this->assertTrue($saved['success']);
        $this->assertSame(1, $saved['draft']['content']['evidence'][0]['drawingRevision']);
        $record = app(ClaimEditingService::class)->commit(['lineId' => $this->line->id, 'revision' => 2]);
        $this->assertTrue($record['success'], $record['error'] ?? '');
        $stored = ClaimWorkRecord::findOrFail($record['id']);
        $this->assertSame($proof['sha256'], $stored->evidence[0]['sha256']);
        $this->assertSame(1, $stored->evidence[0]['drawingRevision']);
        $this->assertSame('pending', $stored->status);
        $this->assertNull($stored->verified_qty);
        $this->get('/admin-api/cad/'.$this->drawing->id.'/download?format=dxf&revision=1')->assertContent($old);
    }

    public function test_wrong_site_committed_draft_and_read_only_access_cannot_attach(): void
    {
        $otherSite = Site::create(['company_id' => $this->drawing->company_id, 'code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $this->line->contract->update(['site_id' => $otherSite->id]);
        $this->attach()->assertNotFound();
        $this->line->contract->update(['site_id' => $this->drawing->site_id]);
        $this->attach()->assertOk();
        $record = ClaimWorkRecord::create(['contract_boq_line_id' => $this->line->id, 'record_kind' => 'actual', 'work_date' => now()->toDateString(), 'location' => 'Verified', 'stage' => 'installed', 'reported_qty' => 5, 'verified_qty' => 5, 'status' => 'verified']);
        ClaimEditDraft::first()->update(['claim_work_record_id' => $record->id]);
        $this->attach(1)->assertForbidden();
        $this->assertSame('5.0000', $record->fresh()->verified_qty);
        $viewer = User::factory()->create(['access_role' => 'payroll', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($viewer);
        $this->attach(1)->assertForbidden();
    }

    public function test_other_users_draft_is_never_modified_and_revision_hash_is_validated(): void
    {
        $this->attach()->assertOk();
        $first = ClaimEditDraft::first();
        $other = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($other);
        $this->attach(1)->assertConflict();
        $this->attach()->assertOk();
        $this->assertSame(1, $first->fresh()->revision);
        $this->assertDatabaseCount('claim_edit_drafts', 2);
        $revision = $this->drawing->revisions()->first();
        Storage::disk('local')->put($revision->dxf_path, 'tampered');
        $this->attach(1)->assertConflict();
    }

    public function test_linked_revision_and_parent_cannot_be_deleted_or_rewritten(): void
    {
        $this->attach()->assertOk();
        $revision = $this->drawing->revisions()->first();
        foreach ([fn () => $revision->update(['sha256' => str_repeat('0', 64)]), fn () => $revision->delete(), fn () => $this->drawing->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Immutable CAD proof changed.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('cad_drawing_revisions', 1);
    }

    public function test_packet_and_archive_export_exact_old_revision_and_reject_corrupt_bytes(): void
    {
        $proof = $this->attach()->assertOk()->json('evidence');
        $revision = $this->drawing->revisions()->first();
        $old = Storage::disk('local')->get($revision->dxf_path);
        $this->putJson('/admin-api/cad/'.$this->drawing->id, ['revision' => 1, 'dxf' => str_replace("11\n100", "11\n220", $old)])->assertOk();
        $record = ClaimWorkRecord::create(['contract_boq_line_id' => $this->line->id, 'record_kind' => 'actual', 'work_date' => now()->toDateString(), 'location' => 'Room', 'stage' => 'installed', 'reported_qty' => 3, 'verified_qty' => 3, 'status' => 'verified', 'evidence' => [$proof]]);
        $application = PayApplication::create(['project_contract_id' => $this->line->project_contract_id, 'company_id' => $this->drawing->company_id,
            'site_id' => $this->drawing->site_id, 'status' => 'draft', 'type' => 'progress', 'period_end' => now()->toDateString(), 'this_period_amount' => 30, 'cumulative_amount' => 30]);
        PayApplicationAllocation::create(['pay_application_id' => $application->id, 'claim_work_record_id' => $record->id, 'quantity' => 3, 'amount' => 30,
            'snapshot' => ['line' => ['lineNo' => '1', 'description' => 'Pipe'], 'evidence' => [$proof + ['verifiedFileSha256' => $proof['sha256']]]]]);
        $packets = app(ClaimDailyEvidencePacketService::class);
        $sources = $packets->sources($application, $this->line->contract);
        $cad = collect($sources)->firstWhere('sourceType', 'cad_revision');
        $this->assertNotNull($cad);
        $this->assertSame($revision->id, $cad['sourceId']);
        $this->assertSame($old, $packets->read($cad));
        $manifest = $packets->manifest($cad, ['name' => 'old.dxf', 'sha256' => $proof['sha256'], 'bytes' => strlen($old)]);
        $packets->assertArchiveAccess($this->line->contract, [$manifest]);
        $this->get('/billing-export/contracts/'.$this->line->project_contract_id.'/daily-evidence/cad_revision/'.$revision->id)->assertOk()->assertContent($old);
        Storage::disk('local')->put($revision->dxf_path, $old.'tampered');
        try {
            $packets->read($cad);
            $this->fail('Corrupt CAD snapshot was exported.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->assertSame('3.0000', $record->fresh()->verified_qty);
        Storage::disk('local')->put($revision->dxf_path, $old);
        $otherSite = Site::create(['company_id' => $this->drawing->company_id, 'code' => 'ARCHIVE-OTHER', 'name' => 'Other', 'status' => 'active']);
        $this->actor->forceFill(['access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_company_id' => $this->drawing->company_id, 'allowed_site_id' => $otherSite->id])->save();
        try {
            $packets->assertArchiveAccess($this->line->contract, [$manifest]);
            $this->fail('Revoked site access still exposed the archived CAD source.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_managed_user_needs_both_contract_edit_and_cad_document_export_rights(): void
    {
        $managed = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'site', 'account_status' => 'active',
            'allowed_company_id' => $this->drawing->company_id, 'allowed_site_id' => $this->drawing->site_id,
            'job_role' => 'engineering', 'job_site_ids' => [$this->drawing->site_id],
            'job_permissions' => ['progress' => ['view', 'edit'], 'contracts' => ['view', 'edit']]]);
        $this->actingAs($managed)->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $managed->id]);
        $this->attach()->assertForbidden();
        $managed->forceFill(['job_permissions' => ['progress' => ['view', 'edit'], 'contracts' => ['view'], 'documents' => ['view', 'export']]])->save();
        $this->attach()->assertForbidden();
        $this->assertDatabaseCount('claim_edit_drafts', 0);
        $managed->forceFill(['job_permissions' => ['progress' => ['view', 'edit'], 'contracts' => ['view', 'edit'], 'documents' => ['view', 'export']]])->save();
        $this->getJson('/admin-api/cad/claim-context?lineId='.$this->line->id)->assertOk()->assertJsonPath('canManage', true);
        $this->attach()->assertOk()->assertJsonPath('draft.revision', 1);
        $this->assertDatabaseCount('claim_edit_drafts', 1);
        $this->assertDatabaseCount('claim_work_records', 0);
    }

    public function test_cad_satisfies_one_drawing_requirement_but_revisions_do_not_count_as_two_drawings(): void
    {
        $first = $this->attach()->assertOk()->json('evidence');
        $old = app(CadDrawingService::class)->read($this->drawing)['dxf'];
        $this->putJson('/admin-api/cad/'.$this->drawing->id, ['revision' => 1, 'dxf' => str_replace("11\n100", "11\n230", $old)])->assertOk();
        $second = $this->attach(1, 2)->assertOk()->json('evidence');
        $record = ClaimWorkRecord::create(['contract_boq_line_id' => $this->line->id, 'record_kind' => 'actual', 'work_date' => now()->toDateString(),
            'location' => 'Room', 'stage' => 'installed', 'reported_qty' => 3, 'status' => 'pending', 'evidence' => [$first, $second]]);
        $ledger = app(ClaimEvidenceService::class);
        $this->assertTrue($ledger->saveRequirements(['lineId' => $this->line->id, 'requirements' => [['type' => 'drawing', 'stage' => 'installed', 'count' => 1]]])['success']);
        $reviewed = $ledger->reviewRecord(['id' => $record->id, 'action' => 'verify', 'verifiedQty' => 3, 'reviewNote' => 'Measured separately from CAD']);
        $this->assertTrue($reviewed['success'], $reviewed['error'] ?? '');
        $this->assertTrue($ledger->saveRequirements(['lineId' => $this->line->id, 'requirements' => [['type' => 'drawing', 'stage' => 'installed', 'count' => 2]]])['success']);
        $issues = $ledger->getLedger($this->line->project_contract_id)['issues'];
        $this->assertStringContainsString('도면 1건 필요', implode(' ', array_column($issues, 'message')));
    }
}

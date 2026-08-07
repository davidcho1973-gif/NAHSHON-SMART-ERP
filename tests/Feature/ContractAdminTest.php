<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProjectContract;
use App\Models\ProjectContractDocument;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\ContractAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 원청 계약 · 서류 — Filament ProjectContractResource 를 SPA 로 옮긴 화면의 뒷단.
 *
 * 계약은 "지금 유효한가" 와 "다음에 뭘 해야 하나" 가 전부다. 종료일이 지났는데 아무도
 * 모르거나 갱신 통보 기한을 놓치는 것이 실제 손해라, 경고 계산을 중심으로 검증한다.
 * 서류는 버전이 생명이라 대체 처리를 함께 본다.
 */
class ContractAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $us;

    private Company $them;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->us = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $this->them = Company::create(['code' => 'LG', 'name' => 'LG', 'status' => 'active']);
        $this->site = Site::create(['code' => 'LG_ESS_PH', 'name' => 'LG PHOENIX', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        Storage::fake('local');
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ], $extra));
    }

    private function svc(): ContractAdminService
    {
        return app(ContractAdminService::class);
    }

    private function contract(array $extra = []): ProjectContract
    {
        return ProjectContract::create(array_merge([
            'company_id' => $this->us->id, 'counterparty_company_id' => $this->them->id,
            'site_id' => $this->site->id, 'title' => 'LG ESS 전기공사',
            'direction' => 'receivable', 'status' => 'active',
        ], $extra));
    }

    private function base(array $over = []): array
    {
        return array_merge([
            'title' => '신규 계약', 'companyId' => (string) $this->us->id,
            'direction' => 'receivable', 'status' => 'draft',
        ], $over);
    }

    // ── 접근 ────────────────────────────────────────────────────────────

    public function test_a_worker_cannot_read_contracts(): void
    {
        $this->actingAs($this->user('worker', ['access_scope' => 'self']));

        $this->assertFalse($this->svc()->list()['success']);
    }

    public function test_payroll_reads_but_cannot_manage(): void
    {
        $this->contract();
        $this->actingAs($this->user('payroll'));

        $res = $this->svc()->list();
        $this->assertTrue($res['success']);
        $this->assertFalse($res['canManage'], '급여 담당은 금액을 봐야 하지만 계약을 바꾸는 사람은 아니다');
    }

    public function test_a_site_manager_only_sees_their_own_site(): void
    {
        $mine = $this->contract();
        $other = Site::create(['code' => 'OTHER', 'name' => 'Other', 'timezone' => 'UTC', 'status' => 'active']);
        $theirs = $this->contract(['title' => '남의 현장', 'site_id' => $other->id]);

        $this->actingAs($this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $this->site->id]));

        $ids = array_column($this->svc()->list()['rows'], 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    // ── 경고 계산 ────────────────────────────────────────────────────────

    public function test_an_expired_contract_is_flagged(): void
    {
        $this->contract(['title' => '끝난 계약', 'ends_on' => Carbon::now()->subDay()->toDateString()]);
        $this->actingAs($this->user('admin'));

        $row = collect($this->svc()->list()['rows'])->firstWhere('title', '끝난 계약');
        $this->assertSame('계약 종료', $row['alerts'][0]['label']);
        $this->assertSame('expired', $row['alerts'][0]['state']);
    }

    public function test_a_contract_ending_soon_is_flagged(): void
    {
        $this->contract(['title' => '곧 종료', 'ends_on' => Carbon::now()->addDays(30)->toDateString()]);
        $this->actingAs($this->user('admin'));

        $row = collect($this->svc()->list()['rows'])->firstWhere('title', '곧 종료');
        $this->assertSame('soon', collect($row['alerts'])->firstWhere('label', '종료 임박')['state']);
    }

    public function test_a_missed_renewal_notice_deadline_is_flagged(): void
    {
        // 90일 뒤 종료 + 통보 기한 120일 → 기한은 이미 지났다.
        $this->contract([
            'title' => '갱신 놓침',
            'ends_on' => Carbon::now()->addDays(90)->toDateString(),
            'renewal_notice_days' => 120,
        ]);
        $this->actingAs($this->user('admin'));

        $row = collect($this->svc()->list()['rows'])->firstWhere('title', '갱신 놓침');
        $this->assertNotNull(collect($row['alerts'])->firstWhere('label', '갱신 통보 기한'),
            '통보 기한을 놓치면 자동 갱신되어 손해가 난다');
    }

    public function test_a_healthy_contract_has_no_alerts(): void
    {
        $this->contract(['title' => '정상', 'ends_on' => Carbon::now()->addYear()->toDateString(), 'renewal_notice_days' => 30]);
        $this->actingAs($this->user('admin'));

        $row = collect($this->svc()->list()['rows'])->firstWhere('title', '정상');
        $this->assertSame([], $row['alerts']);
    }

    // ── 입력 검증 ────────────────────────────────────────────────────────

    public function test_the_end_date_cannot_precede_the_start(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->save($this->base(['startsOn' => '2026-08-01', 'endsOn' => '2026-07-01']));

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('endsOn', $res['errors']);
    }

    public function test_you_cannot_contract_with_your_own_company(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->save($this->base(['counterpartyId' => (string) $this->us->id]));

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('counterpartyId', $res['errors']);
    }

    public function test_the_current_amount_is_computed_when_left_blank(): void
    {
        // 손으로 더하다 틀리는 자리다.
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->save($this->base(['originalAmount' => '100000', 'approvedChangeAmount' => '25000']));

        $this->assertSame(125000.0, (float) ProjectContract::find($res['id'])->current_amount);
    }

    public function test_an_explicit_current_amount_wins(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->save($this->base([
            'originalAmount' => '100000', 'approvedChangeAmount' => '25000', 'currentAmount' => '130000',
        ]));

        $this->assertSame(130000.0, (float) ProjectContract::find($res['id'])->current_amount);
    }

    public function test_a_retainage_outside_zero_to_hundred_is_refused(): void
    {
        $this->actingAs($this->user('admin'));

        $this->assertArrayHasKey('retainagePercent', $this->svc()->save($this->base(['retainagePercent' => '150']))['errors']);
        $this->assertArrayHasKey('retainagePercent', $this->svc()->save($this->base(['retainagePercent' => '-1']))['errors']);
    }

    // ── 서류 버전 ────────────────────────────────────────────────────────

    public function test_uploading_a_document_creates_version_one(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->uploadDocument($c->id, UploadedFile::fake()->create('coi.pdf', 40, 'application/pdf'), [
            'documentType' => 'certificate_of_insurance', 'status' => 'approved',
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['version']);
        $doc = ProjectContractDocument::find($res['id']);
        $this->assertTrue($doc->is_current);
        $this->assertSame('coi.pdf', $doc->original_file_name);
        Storage::disk($doc->disk)->assertExists($doc->file_path);
    }

    public function test_a_newer_document_supersedes_the_previous_one_of_the_same_type(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $first = $this->svc()->uploadDocument($c->id, UploadedFile::fake()->create('coi-2025.pdf', 10, 'application/pdf'),
            ['documentType' => 'certificate_of_insurance']);
        $second = $this->svc()->uploadDocument($c->id, UploadedFile::fake()->create('coi-2026.pdf', 10, 'application/pdf'),
            ['documentType' => 'certificate_of_insurance']);

        $this->assertSame(2, $second['version']);
        $this->assertTrue($second['replaced']);
        $this->assertFalse((bool) ProjectContractDocument::find($first['id'])->is_current);
        $this->assertSame('superseded', ProjectContractDocument::find($first['id'])->status);
        $this->assertTrue((bool) ProjectContractDocument::find($second['id'])->is_current,
            '어느 것이 최신인지 모르는 서류철이 가장 위험하다');
    }

    public function test_a_different_document_type_does_not_supersede(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $coi = $this->svc()->uploadDocument($c->id, UploadedFile::fake()->create('coi.pdf', 10, 'application/pdf'),
            ['documentType' => 'certificate_of_insurance']);
        $this->svc()->uploadDocument($c->id, UploadedFile::fake()->create('w9.pdf', 10, 'application/pdf'),
            ['documentType' => 'w9']);

        $this->assertTrue((bool) ProjectContractDocument::find($coi['id'])->is_current, '종류가 다르면 서로 대체하지 않는다');
    }

    public function test_deleting_the_current_document_restores_the_previous_one(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $v1 = $this->svc()->uploadDocument($c->id, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ['documentType' => 'w9']);
        $v2 = $this->svc()->uploadDocument($c->id, UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
            ['documentType' => 'w9']);

        $this->assertTrue($this->svc()->deleteDocument($v2['id'])['success']);
        $this->assertTrue((bool) ProjectContractDocument::find($v1['id'])->is_current,
            '최신본을 지우면 앞 버전이 다시 현재본이 돼야 한다');
    }

    public function test_an_oversized_file_is_refused(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->uploadDocument($c->id,
            UploadedFile::fake()->create('huge.pdf', ContractAdminService::MAX_KB + 1024, 'application/pdf'), []);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('file', $res['errors']);
    }

    public function test_a_site_manager_may_upload_but_not_delete_documents(): void
    {
        $c = $this->contract();
        $manager = $this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $this->site->id]);
        $this->actingAs($manager);

        $res = $this->svc()->uploadDocument($c->id, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            ['documentType' => 'w9']);
        $this->assertTrue($res['success']);
        $this->assertFalse($this->svc()->deleteDocument($res['id'])['success']);
    }

    // ── 삭제 보호 ────────────────────────────────────────────────────────

    public function test_a_contract_with_documents_cannot_be_deleted(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));
        $this->svc()->uploadDocument($c->id, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), ['documentType' => 'w9']);

        $res = $this->svc()->delete($c->id);

        $this->assertFalse($res['success'], '서명본과 보험증서가 함께 사라진다');
        $this->assertStringContainsString('1건', $res['error']);
    }

    public function test_an_empty_contract_can_be_deleted_by_an_admin(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $this->assertTrue($this->svc()->delete($c->id)['success']);
        $this->assertNull(ProjectContract::find($c->id));
    }

    public function test_the_documents_list_marks_expired_certificates(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));
        $this->svc()->uploadDocument($c->id, UploadedFile::fake()->create('coi.pdf', 10, 'application/pdf'), [
            'documentType' => 'certificate_of_insurance',
            'expiresOn' => Carbon::now()->subDay()->toDateString(),
        ]);

        $row = $this->svc()->documents($c->id)['rows'][0];
        $this->assertTrue($row['expired'], '끊긴 보험으로 작업하면 사고 때 보상이 안 된다');
    }

    // ── API ─────────────────────────────────────────────────────────────

    public function test_the_upload_route_requires_manage_permission(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('worker', ['access_scope' => 'self']));

        $this->postJson(route('admin.contract-document.upload', ['contract' => $c->id]), [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertOk()->assertJsonPath('success', false);

        $this->assertSame(0, ProjectContractDocument::count());
    }

    public function test_the_api_exposes_the_screen_and_blocks_read_only_clients(): void
    {
        $this->actingAs($this->user('admin'));
        $this->postJson('/smart-company-api/api_getContracts', ['args' => [[]], 'siteId' => 'ALL'])
            ->assertOk()->assertJsonPath('success', true);

        $this->actingAs($this->user('client'));
        $this->postJson('/smart-company-api/api_saveContract', ['args' => [['title' => 'x']], 'siteId' => 'ALL'])
            ->assertStatus(403);
    }
}

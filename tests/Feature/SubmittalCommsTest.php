<?php

namespace Tests\Feature;

use App\Mail\SubmittalMail;
use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\SubmittalEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 제출물 소통 회로 — 업체(자료 제공) → 우리 → 원청(최종 수신).
 *
 * 지키는 것: 단계가 상태를 움직인다(요청→작성중, 전달→제출+제출일, 승인본→승인+승인일),
 * 모든 소통은 events 에 남는다, 메일 서버가 없으면 mailto 로 정직하게 돌려준다,
 * 같은 공종 일괄 적용은 빈 값을 퍼뜨리지 않는다.
 */
class SubmittalCommsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document-intelligence.disk' => 'local']);
        Storage::fake('local');
    }

    private function readyMail(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example-mail.test',
            'mail.from.address' => 'erp@example-mep.test',
        ]);
        Mail::fake();
    }

    public function test_contacts_apply_to_whole_csi_without_spreading_blanks(): void
    {
        [$admin, $row] = $this->fixture();
        $sibling = $this->row($row, ['seq' => 6, 'vendor_phone' => '480-555-0100']);
        $otherCsi = $this->row($row, ['seq' => 9, 'csi' => '09 9100']);

        $data = $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['contacts', $row->id, [
                'data' => ['vendorName' => 'Hager Co', 'vendorEmail' => 'sales@hager-example.test', 'vendorPhone' => ''],
                'applyToCsi' => true,
            ]],
        ])->assertOk()->json();

        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['applied']);
        $this->assertSame('sales@hager-example.test', $sibling->fresh()->vendor_email);
        // 빈 전화번호는 퍼뜨리지 않는다 — 이미 있던 번호가 살아 있어야 한다.
        $this->assertSame('480-555-0100', $sibling->fresh()->vendor_phone);
        // 다른 공종에는 손대지 않는다.
        $this->assertNull($otherCsi->fresh()->vendor_email);
    }

    public function test_request_email_is_sent_logged_and_moves_status(): void
    {
        $this->readyMail();
        [$admin, $row] = $this->fixture(['vendor_name' => 'Hager Co', 'vendor_email' => 'sales@hager-example.test']);

        $data = $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['request', $row->id, []],
        ])->assertOk()->json();

        $this->assertTrue($data['success']);
        $this->assertTrue($data['sent']);
        Mail::assertSent(SubmittalMail::class, fn (SubmittalMail $m): bool => str_contains($m->subjectLine, '[자료 요청]')
            && $m->hasTo('sales@hager-example.test'));

        $event = SubmittalEvent::sole();
        $this->assertSame('request_sent', $event->kind);
        $this->assertSame('email', $event->channel);
        $this->assertSame('작성중', $row->fresh()->status);
    }

    public function test_without_mailer_request_returns_mailto_and_logs_honestly(): void
    {
        config(['mail.default' => 'log']);
        [$admin, $row] = $this->fixture(['vendor_email' => 'sales@hager-example.test']);

        $data = $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['request', $row->id, []],
        ])->assertOk()->json();

        $this->assertTrue($data['success']);
        $this->assertFalse($data['sent']);
        $this->assertStringStartsWith('mailto:sales%40hager-example.test', $data['mailto']);
        $this->assertStringContainsString('subject=', $data['mailto']);
        $this->assertSame('mailto', SubmittalEvent::sole()->channel);
    }

    public function test_request_without_vendor_email_asks_for_it_first(): void
    {
        [$admin, $row] = $this->fixture();

        $data = $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['request', $row->id, []],
        ])->assertOk()->json();

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('이메일', $data['error']);
        $this->assertSame(0, SubmittalEvent::count());
    }

    public function test_linking_received_documents_logs_and_wakes_status(): void
    {
        [$admin, $row] = $this->fixture();
        $doc = $this->doc($admin, $row, 'hager-product-data.pdf');

        $data = $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['link', $row->id, ['documentIds' => [$doc->id], 'kind' => 'received']],
        ])->assertOk()->json();

        $this->assertSame(1, $data['linked']);
        $this->assertSame('작성중', $row->fresh()->status);
        $this->assertSame('materials_linked', SubmittalEvent::sole()->kind);
        $this->assertTrue($row->fresh()->documents->contains('id', $doc->id));
    }

    public function test_transmit_attaches_materials_sets_submitted_and_logs(): void
    {
        $this->readyMail();
        [$admin, $row] = $this->fixture([
            'vendor_email' => 'sales@hager-example.test',
            'recipient_name' => 'GC PM', 'recipient_email' => 'pm@gc-example.test',
        ]);
        $doc = $this->doc($admin, $row, 'hager-product-data.pdf', "%PDF-1.7\nspec");
        $row->documents()->attach($doc->id, ['kind' => 'received']);

        $data = $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['transmit', $row->id, []],
        ])->assertOk()->json();

        $this->assertTrue($data['success']);
        Mail::assertSent(SubmittalMail::class, fn (SubmittalMail $m): bool => $m->hasTo('pm@gc-example.test')
            && count($m->files) === 1
            && $m->files[0]['name'] === 'hager-product-data.pdf');

        $fresh = $row->fresh();
        $this->assertSame('제출', $fresh->status);
        $this->assertNotNull($fresh->submitted_on);
        $this->assertSame('transmitted', SubmittalEvent::sole()->kind);
    }

    public function test_transmit_without_materials_refuses(): void
    {
        $this->readyMail();
        [$admin, $row] = $this->fixture(['recipient_email' => 'pm@gc-example.test']);

        $data = $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['transmit', $row->id, []],
        ])->assertOk()->json();

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('자료가 없습니다', $data['error']);
        Mail::assertNothingSent();
        $this->assertSame('미착수', $row->fresh()->status);
    }

    public function test_linking_approval_document_closes_the_loop(): void
    {
        [$admin, $row] = $this->fixture();
        $doc = $this->doc($admin, $row, 'approved-stamped.pdf');

        $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['link', $row->id, ['documentIds' => [$doc->id], 'kind' => 'approval']],
        ])->assertOk();

        $fresh = $row->fresh();
        $this->assertSame('승인', $fresh->status);
        $this->assertNotNull($fresh->approved_on);
        $this->assertSame('approval_linked', SubmittalEvent::sole()->kind);
        $this->assertSame('approval', $fresh->documents->first()->pivot->kind);
    }

    public function test_overview_carries_contacts_events_and_timeline_order(): void
    {
        config(['mail.default' => 'log']);
        [$admin, $row] = $this->fixture(['vendor_email' => 'sales@hager-example.test']);
        $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', ['args' => ['request', $row->id, []]])->assertOk();
        $doc = $this->doc($admin, $row, 'hager-product-data.pdf');
        $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['link', $row->id, ['documentIds' => [$doc->id], 'kind' => 'received']],
        ])->assertOk();

        $data = $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['overview', $row->id, []],
        ])->assertOk()->json();

        $this->assertFalse($data['mailReady']);
        $this->assertSame('sales@hager-example.test', $data['contacts']['vendorEmail']);
        $this->assertSame(['request_sent', 'materials_linked'], array_column($data['events'], 'kind'));
        $this->assertCount(1, $data['documents']);
        // 이미 연결한 문서는 연결 후보에 다시 나오지 않는다.
        $this->assertSame([], array_filter($data['linkable'], fn (array $d): bool => $d['id'] === $doc->id));
    }

    public function test_comms_requires_manage_role(): void
    {
        [, $row] = $this->fixture();
        $viewer = User::query()->create([
            'name' => 'Viewer', 'email' => 'v-'.uniqid().'@example.com', 'password' => bcrypt('password'),
            'access_role' => 'payroll', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $data = $this->actingAs($viewer)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['overview', $row->id, []],
        ])->assertOk()->json();

        $this->assertFalse($data['success']);
    }

    /** @param array<string, mixed> $overrides
     * @return array{0: User, 1: Submittal} */
    private function fixture(array $overrides = []): array
    {
        $company = Company::query()->create(['code' => 'XYZ', 'name' => 'XYZ MEP', 'status' => 'active']);
        $site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'LGES-AZ', 'name' => 'LGES Arizona',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'project_code' => 'LGES-AZ-2026-001', 'name' => 'Arizona Module',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);
        $row = Submittal::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id,
            'seq' => 5, 'csi' => '08 7100.1', 'section' => '도어하드웨어', 'category' => 'Action 제출물',
            'title' => '제품자료(Product Data) — 각 하드웨어 세트별 제출', 'status' => '미착수', 'gate' => false,
            ...$overrides,
        ]);
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@example.com', 'password' => bcrypt('password'),
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        return [$admin, $row];
    }

    /** @param array<string, mixed> $overrides */
    private function row(Submittal $like, array $overrides): Submittal
    {
        return Submittal::query()->create([
            'company_id' => $like->company_id, 'site_id' => $like->site_id, 'project_id' => $like->project_id,
            'csi' => $like->csi, 'section' => $like->section, 'category' => $like->category,
            'title' => '다른 줄', 'status' => '미착수', 'seq' => 99,
            ...$overrides,
        ]);
    }

    private function doc(User $user, Submittal $row, string $fileName, string $contents = '%PDF-1.7 x'): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        $path = 'document-intelligence/inbox/'.$uuid.'/'.$fileName;
        Storage::disk('local')->put($path, $contents);

        return IntelligentDocument::query()->create([
            'uuid' => $uuid, 'company_id' => $row->company_id, 'site_id' => $row->site_id,
            'project_id' => $row->project_id, 'uploaded_by' => $user->id,
            'disk' => 'local', 'file_path' => $path,
            'original_file_name' => $fileName, 'stored_file_name' => $fileName,
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => strlen($contents),
            'sha256' => hash('sha256', $contents), 'title' => pathinfo($fileName, PATHINFO_FILENAME),
            'received_at' => now(), 'ai_status' => 'ready',
        ]);
    }
}

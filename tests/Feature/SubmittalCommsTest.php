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
use App\Services\Ocr\OcrEngine;
use App\Services\Takeoff\SubmittalRequestService;
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
        $this->assertSame('request_draft', SubmittalEvent::sole()->kind);
        $this->assertSame('미착수', $row->fresh()->status);
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
            'args' => ['link', $row->id, ['documentIds' => [$doc->id], 'kind' => 'approval', 'disposition' => '승인', 'decisionOn' => now()->toDateString()]],
        ])->assertOk();

        $fresh = $row->fresh();
        $this->assertSame('승인', $fresh->status);
        $this->assertNotNull($fresh->approved_on);
        $this->assertSame('response_recorded', SubmittalEvent::sole()->kind);
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
        $this->assertSame(['request_draft', 'materials_linked'], array_column($data['events'], 'kind'));
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

    public function test_request_letter_is_bound_to_its_submittal_when_projects_reuse_csi_and_sequence(): void
    {
        $this->readyMail();
        [$admin, $row] = $this->fixture(['vendor_email' => 'vendor@example.test']);
        $this->actingAs($admin);
        $otherProject = $row->project->replicate();
        $otherProject->project_code = 'OTHER-JOB';
        $otherProject->name = 'Other job';
        $otherProject->save();
        $other = $this->row($row, ['project_id' => $otherProject->id, 'seq' => $row->seq]);

        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->twice()->andReturn(
            ['data' => ['required_items' => ['OWN-REQUIREMENT'], 'opening' => 'Own request', 'due_note' => '']],
            ['data' => ['required_items' => ['OTHER-REQUIREMENT'], 'opening' => 'Other request', 'due_note' => '']],
        );
        $engine->shouldReceive('name')->andReturn('fake');
        $this->app->instance(OcrEngine::class, $engine);
        $service = app(SubmittalRequestService::class);
        $own = $service->build($row);
        $foreign = $service->build($other);
        $this->assertTrue($own['success']);
        $this->assertTrue($foreign['success']);
        $this->assertSame($own['documentId'], $row->documents()->wherePivot('kind', 'request')->sole()->id);

        $this->postJson('/smart-company-api/api_submittalComms', ['args' => ['request', $row->id, []]])
            ->assertOk()->assertJsonPath('success', true);
        Mail::assertSent(SubmittalMail::class, fn (SubmittalMail $mail): bool => str_contains($mail->bodyHtml, 'OWN-REQUIREMENT')
            && ! str_contains($mail->bodyHtml, 'OTHER-REQUIREMENT'));
    }

    public function test_linking_another_projects_document_rejects_the_entire_selection(): void
    {
        [$admin, $row] = $this->fixture();
        $own = $this->doc($admin, $row, 'own.pdf');
        $otherProject = $row->project->replicate();
        $otherProject->project_code = 'OTHER-JOB';
        $otherProject->save();
        $other = $this->row($row, ['project_id' => $otherProject->id]);
        $foreign = $this->doc($admin, $other, 'foreign.pdf');

        $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', [
            'args' => ['link', $row->id, ['documentIds' => [$own->id, $foreign->id], 'kind' => 'received']],
        ])->assertOk()->assertJsonPath('success', false);
        $this->assertCount(0, $row->fresh()->documents);
        $this->assertSame(0, SubmittalEvent::count());
    }

    public function test_response_requires_actual_disposition_and_original_and_preserves_the_received_link(): void
    {
        [$admin, $row] = $this->fixture();
        $this->actingAs($admin);
        $doc = $this->doc($admin, $row, 'gc-response.pdf');
        $row->documents()->attach($doc->id, ['kind' => 'received']);
        $args = ['documentIds' => [$doc->id], 'kind' => 'approval'];
        $this->postJson('/smart-company-api/api_submittalComms', ['args' => ['link', $row->id, $args]])
            ->assertOk()->assertJsonPath('success', false);
        $args += ['disposition' => '조건부승인', 'decisionOn' => now()->toDateString(), 'decisionNote' => str_repeat('가', 201)];
        $this->postJson('/smart-company-api/api_submittalComms', ['args' => ['link', $row->id, $args]])
            ->assertOk()->assertJsonPath('success', false);
        $args['decisionNote'] = str_repeat('가', 200);
        Storage::disk('local')->delete($doc->file_path);
        $this->postJson('/smart-company-api/api_submittalComms', ['args' => ['link', $row->id, $args]])
            ->assertOk()->assertJsonPath('success', false);
        $this->assertSame('미착수', $row->fresh()->status);
        Storage::disk('local')->put($doc->file_path, '%PDF-1.7 x');

        $this->postJson('/smart-company-api/api_submittalComms', ['args' => ['link', $row->id, $args]])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertSame('조건부승인', $row->fresh()->status);
        $this->assertSame('received', $row->documents()->sole()->pivot->kind);
        $this->assertSame('조건부승인 · '.now()->toDateString().' · '.str_repeat('가', 200), SubmittalEvent::sole()->subject);

        $rejected = $this->doc($admin, $row, 'gc-rejection.pdf');
        $args['documentIds'] = [$rejected->id];
        $args['disposition'] = '반려';
        $args['decisionNote'] = 'Wrong revision';
        $this->postJson('/smart-company-api/api_submittalComms', ['args' => ['link', $row->id, $args]])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertSame('반려', $row->fresh()->status);
        $this->assertNull($row->fresh()->approved_on);
        $this->assertSame(2, $row->events()->count(), 'Earlier response evidence is retained.');
    }

    public function test_partial_attachment_packets_never_send_or_become_submitted(): void
    {
        $this->readyMail();
        [$admin, $base] = $this->fixture();
        $this->actingAs($admin);
        foreach (['missing', 'oversize', 'changed'] as $case) {
            $row = $this->row($base, ['seq' => 20 + Submittal::count(), 'recipient_email' => 'gc@example.test']);
            $good = $this->doc($admin, $row, $case.'-good.pdf', 'good');
            $bad = $this->doc($admin, $row, $case.'-bad.pdf', 'bad-content');
            $row->documents()->attach([$good->id => ['kind' => 'received'], $bad->id => ['kind' => 'received']]);
            config(['mail.max_attachment_bytes' => $case === 'oversize' ? 5 : 1000]);
            if ($case === 'missing') {
                Storage::disk('local')->delete($bad->file_path);
            } elseif ($case === 'changed') {
                Storage::disk('local')->put($bad->file_path, 'different');
            }
            $this->postJson('/smart-company-api/api_submittalComms', ['args' => ['transmit', $row->id, []]])
                ->assertOk()->assertJsonPath('success', false)->assertJsonPath('sent', false);
            $this->assertSame('미착수', $row->fresh()->status);
            $this->assertNull($row->fresh()->submitted_on);
        }
        Mail::assertNothingSent();
        $this->assertSame(0, SubmittalEvent::where('kind', 'transmitted')->count());
    }

    public function test_mailto_transmission_is_only_a_draft(): void
    {
        config(['mail.default' => 'log']);
        [$admin, $row] = $this->fixture(['recipient_email' => 'gc@example.test', 'status' => '작성중']);
        $doc = $this->doc($admin, $row, 'package.pdf');
        $row->documents()->attach($doc->id, ['kind' => 'received']);

        $this->actingAs($admin)->postJson('/smart-company-api/api_submittalComms', ['args' => ['transmit', $row->id, []]])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('sent', false);
        $this->assertSame('작성중', $row->fresh()->status);
        $this->assertNull($row->fresh()->submitted_on);
        $this->assertSame('transmit_draft', SubmittalEvent::sole()->kind);
    }

    public function test_status_edit_cannot_invent_approval_or_erase_other_fields(): void
    {
        [$admin, $row] = $this->fixture(['assignee' => 'Site engineer', 'planned_on' => '2026-10-01', 'notes' => 'Keep evidence']);
        $this->actingAs($admin)->postJson('/smart-company-api/api_saveSubmittal', [
            'args' => [['id' => $row->id, 'status' => '작성중']],
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertSame('Site engineer', $row->fresh()->assignee);
        $this->assertSame('Keep evidence', $row->fresh()->notes);
        $this->assertSame('2026-10-01', $row->fresh()->planned_on->toDateString());
        foreach (['승인', '조건부승인', '반려'] as $status) {
            $this->postJson('/smart-company-api/api_saveSubmittal', [
                'args' => [['id' => $row->id, 'status' => $status, 'approvedOn' => now()->toDateString()]],
            ])->assertOk()->assertJsonPath('success', false);
        }
        $this->assertSame('작성중', $row->fresh()->status);
        $this->assertNull($row->fresh()->approved_on);
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

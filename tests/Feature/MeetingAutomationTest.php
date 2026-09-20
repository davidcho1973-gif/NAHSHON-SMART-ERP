<?php

namespace Tests\Feature;

use App\Jobs\ProcessOpsMeeting;
use App\Models\Company;
use App\Models\OpsActionItem;
use App\Models\OpsIntakeItem;
use App\Models\OpsMeeting;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Ops\DailyClosingService;
use App\Services\Ops\MeetingAnalyzer;
use App\Services\Ops\MeetingContext;
use App\Services\Ops\MeetingTranscriber;
use App\Services\Ops\MeetingWorkflow;
use App\Services\Ops\OpsDigestService;
use App\Services\Ops\OpsIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MeetingAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $admin;

    private WbsItem $wbs;

    private ProcurementItem $po;

    private string $quote = '방문 언제 입고되는지 확인해 주세요';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 9, 20)->setTime(12, 0));
        Storage::fake('meeting-test');
        config(['meetings.disk' => 'meeting-test', 'meetings.auto_internal_tasks' => true]);
        $co = Company::create(['code' => 'MTEST', 'name' => 'Meeting test', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $co->id, 'name' => 'Meeting site', 'code' => 'M-SITE', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $this->admin = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $this->actingAs($this->admin);
        Project::create(['project_code' => 'M-PROJ', 'name' => 'Meeting project', 'construction_type' => 'equipment_setting']);
        $this->wbs = WbsItem::create(['site_id' => $this->site->id, 'project_code' => 'M-PROJ', 'level' => 'subtask', 'wbs_code' => 'M-DOOR', 'activity_id' => 'A10', 'node_no' => '1.1', 'name' => 'Office door installation', 'progress' => 0, 'planned_start' => '2026-09-21', 'planned_end' => '2026-09-25']);
        $this->po = ProcurementItem::create(['site_id' => $this->site->id, 'project_code' => 'M-PROJ', 'wbs_code' => 'M-DOOR', 'wbs_item_id' => $this->wbs->id, 'po_no' => 'M-PO-1', 'status' => '발주완료', 'vendor' => 'Example vendor']);
    }

    private function meeting(array $overrides = []): OpsMeeting
    {
        return OpsMeeting::create($overrides + ['site_id' => $this->site->id, 'created_by_id' => $this->admin->id, 'title' => 'Daily meeting', 'meeting_on' => '2026-09-19', 'upload_token' => (string) Str::uuid(), 'audio_hash' => hash('sha256', 'audio'), 'audio_bytes' => 5, 'audio_path' => 'test.audio', 'disk' => 'meeting-test', 'audio_mime' => 'audio/wav', 'part_count' => 1, 'parts' => [], 'status' => 'analyzing', 'transcripts' => ['gemini' => ['status' => 'done', 'text' => $this->quote], 'scribe' => ['status' => 'done', 'text' => $this->quote, 'segments' => [['text' => $this->quote, 'start' => 12.0, 'end' => 16.0, 'speaker' => 'speaker_0']]]]]);
    }

    private function item(array $overrides = []): array
    {
        return $overrides + ['kind' => 'lookup', 'title' => '방문 납기 확인', 'target_ref' => 'P:'.$this->po->id, 'candidate_refs' => ['P:'.$this->po->id], 'quote_gemini' => $this->quote, 'quote_scribe' => $this->quote, 'uncertain' => false, 'question' => '', 'condition' => '', 'requires_approval' => false, 'assignee' => '구매 담당', 'due_on' => '2026-09-21', 'blocker' => false, 'changes' => [], 'company' => '', 'headcount' => 0];
    }

    private function persist(OpsMeeting $m, array $items): OpsIntakeItem
    {
        app(MeetingWorkflow::class)->persist($m, ['summary' => '회의 결과', 'items' => $items], app(MeetingContext::class)->get($m->site_id));

        return $m->refresh()->batch->items->first();
    }

    public function test_delivery_question_reads_existing_po_creates_one_follow_up_and_never_changes_purchase_or_schedule(): void
    {
        $po = $this->po->fresh()->getAttributes();
        $wbs = $this->wbs->fresh()->getAttributes();
        $m = $this->meeting();
        $i = $this->persist($m, [$this->item()]);
        $this->assertSame('applied', $i->status);
        $this->assertNull($i->meeting_meta['result']['lookup']['eta']);
        $this->assertSame(12, (int) $i->meeting_meta['evidence']['start']);
        $this->assertSame($po, $this->po->fresh()->getAttributes());
        $this->assertSame($wbs, $this->wbs->fresh()->getAttributes());
        $this->persist($m, [$this->item()]);
        $this->persist($this->meeting(), [$this->item(['title' => '방문 도착일 재확인', 'due_on' => '2026-09-22'])]);
        $this->assertSame(1, OpsActionItem::count());
        $this->assertSame(2, OpsIntakeItem::count());
    }

    public function test_known_eta_returns_a_recorded_date_with_schedule_impact_without_creating_a_task(): void
    {
        $this->po->update(['eta' => '2026-09-27']);
        $i = $this->persist($this->meeting(), [$this->item()]);
        $this->assertSame('2026-09-27', $i->meeting_meta['result']['lookup']['eta']);
        $this->assertStringContainsString('늦습니다', $i->meeting_meta['result']['impact']);
        $this->assertSame(0, OpsActionItem::count());
    }

    public function test_missing_evidence_ambiguous_or_unknown_candidates_and_conditions_are_held(): void
    {
        $raw = [$this->item(['quote_gemini' => '없는 발언입니다']), $this->item(['candidate_refs' => ['P:'.$this->po->id, 'W:'.$this->wbs->id]]), $this->item(['candidate_refs' => ['P:'.$this->po->id, 'P:999999']]), $this->item(['condition' => '승인되면 확인'])];
        $m = $this->meeting();
        $this->persist($m, $raw);
        $this->assertSame(4, $m->batch->items()->where('status', 'needs_input')->count());
        $this->assertSame(0, OpsActionItem::count());
    }

    public function test_meeting_raw_content_and_mutations_cannot_bypass_meeting_review_through_legacy_intake(): void
    {
        $m = $this->meeting();
        $i = $this->persist($m, [$this->item(['uncertain' => true])]);
        $s = app(OpsIntakeService::class);
        $this->assertSame(0, $s->pending($this->site->id)['count']);
        $this->assertSame(0, $s->batches($this->site->id)['count']);
        $this->assertFalse($s->job($m->ops_intake_batch_id)['success']);
        $this->assertFalse($s->batch($m->ops_intake_batch_id)['success']);
        $this->assertFalse($s->apply($i->id, ['eta' => '2026-09-22'], $this->admin->id)['success']);
        $this->assertFalse($s->deleteBatch($m->ops_intake_batch_id)['success']);
        $this->assertSame(0, app(OpsDigestService::class)->summary($this->site->id)['parsed']);
    }

    public function test_progress_is_reviewed_and_stale_state_cannot_be_overwritten(): void
    {
        $i = $this->persist($this->meeting(), [$this->item(['kind' => 'progress', 'target_ref' => 'W:'.$this->wbs->id, 'candidate_refs' => ['W:'.$this->wbs->id], 'changes' => ['progress' => 60]])]);
        $this->assertSame('pending', $i->status);
        $this->wbs->update(['progress' => 30]);
        $r = app(MeetingWorkflow::class)->apply($i->id, $this->admin, true);
        $this->assertFalse($r['success']);
        $this->assertSame(30, $this->wbs->fresh()->progress);
    }

    public function test_reviewed_progress_uses_existing_service_and_can_undo_while_unchanged(): void
    {
        $i = $this->persist($this->meeting(), [$this->item(['kind' => 'progress', 'target_ref' => 'W:'.$this->wbs->id, 'candidate_refs' => ['W:'.$this->wbs->id], 'changes' => ['progress' => 60]])]);
        $r = app(MeetingWorkflow::class)->apply($i->id, $this->admin, true);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame(60, $this->wbs->fresh()->progress);
        $undo = app(MeetingWorkflow::class)->undo($i->id, $this->admin, '잘못 보고함');
        $this->assertTrue($undo['success'], json_encode($undo));
        $this->assertSame(0, $this->wbs->fresh()->progress);
    }

    public function test_hold_point_approval_and_expense_are_not_bypassed(): void
    {
        $this->wbs->update(['hold_point' => true, 'hold_released' => false]);
        $i = $this->persist($this->meeting(), [$this->item(['kind' => 'progress', 'target_ref' => 'W:'.$this->wbs->id, 'candidate_refs' => ['W:'.$this->wbs->id], 'changes' => ['progress' => 100]])]);
        $this->assertFalse(app(MeetingWorkflow::class)->apply($i->id, $this->admin, true)['success']);
        $proc = $this->persist($this->meeting(), [$this->item(['kind' => 'procurement', 'changes' => ['status' => '입고완료']])]);
        $this->assertFalse(app(MeetingWorkflow::class)->apply($proc->id, $this->admin, true)['success']);
        $this->assertNull($this->po->fresh()->ordered_on, 'Do not turn the analysis date into an actual purchase date.');
        $i = $this->persist($this->meeting(), [$this->item(['kind' => 'expense', 'requires_approval' => true])]);
        $this->assertFalse(app(MeetingWorkflow::class)->apply($i->id, $this->admin, true)['success']);
    }

    public function test_late_upload_counts_on_meeting_day_not_upload_day_and_does_not_close_or_email(): void
    {
        $this->persist($this->meeting(), [$this->item()]);
        $s = app(DailyClosingService::class);
        $this->assertSame(1, $s->metrics($this->site->id, '2026-09-19')['ops']['batches']);
        $this->assertSame(0, $s->metrics($this->site->id, '2026-09-20')['ops']['batches']);
        $this->assertDatabaseCount('daily_closing_reports', 0);
    }

    public function test_worker_and_wrong_site_manager_cannot_read_audio_context_or_modify_items(): void
    {
        $m = $this->meeting();
        $i = $this->persist($m, [$this->item()]);
        foreach (['worker', 'site_manager'] as $role) {
            $u = User::factory()->create(['access_role' => $role, 'account_status' => 'active', 'access_scope' => 'site', 'allowed_site_id' => null]);
            $this->actingAs($u);
            foreach (['', '/audio', '/targets'] as $suffix) {
                $this->getJson('/ops-api/meetings/'.$m->id.$suffix)->assertForbidden();
            }
            $this->postJson('/ops-api/meetings/'.$m->id.'/items/'.$i->id.'/undo', ['reason' => 'no'])->assertForbidden();
        }
    }

    private function wav(): string
    {
        $data = str_repeat("\0", 3200);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($data)).$data;
    }

    public function test_resumable_upload_checks_bytes_stores_private_original_and_queues_once(): void
    {
        Bus::fake([ProcessOpsMeeting::class]);
        $bytes = $this->wav();
        $input = ['site_id' => $this->site->id, 'title' => 'Uploaded', 'meeting_on' => '2026-09-19', 'upload_token' => (string) Str::uuid(), 'audio_hash' => hash('sha256', $bytes), 'audio_bytes' => strlen($bytes), 'audio_mime' => 'audio/wav'];
        $id = $this->postJson('/ops-api/meetings', $input)->assertOk()->json('meeting.id');
        $this->postJson('/ops-api/meetings/'.$id.'/finish')->assertUnprocessable();
        $url = '/ops-api/meetings/'.$id.'/parts/0';
        $this->post($url, ['chunk' => UploadedFile::fake()->createWithContent('part.bin', $bytes)])->assertOk();
        $this->post($url, ['chunk' => UploadedFile::fake()->createWithContent('part.bin', $bytes)])->assertOk();
        $this->postJson('/ops-api/meetings', $input)->assertOk()->assertJsonPath('meeting.id', $id);
        $this->postJson('/ops-api/meetings/'.$id.'/finish')->assertOk()->assertJsonPath('meeting.status', 'queued');
        $this->postJson('/ops-api/meetings/'.$id.'/finish')->assertOk();
        Bus::assertDispatchedTimes(ProcessOpsMeeting::class, 1);
        Bus::assertDispatched(ProcessOpsMeeting::class, fn ($j) => $j->connection === 'meeting-analysis' && $j->queue === 'meetings');
        $m = OpsMeeting::findOrFail($id);
        $this->assertSame($bytes, Storage::disk('meeting-test')->get($m->audio_path));
        $this->get('/ops-api/meetings/'.$id.'/audio', ['Range' => 'bytes=10-19'])->assertStatus(206)->assertHeader('Content-Length', '10')->assertStreamedContent(substr($bytes, 10, 10));
        $this->get('/ops-api/meetings/'.$id.'/audio', ['Range' => 'bytes=999999-'])->assertStatus(416);
    }

    public function test_transcription_contracts_are_independent_and_completed_paid_results_are_reused(): void
    {
        config(['services.gemini.api_key' => 'test', 'meetings.elevenlabs_key' => 'test']);
        $m = $this->meeting(['transcripts' => []]);
        Storage::disk('meeting-test')->put($m->audio_path, 'audio');
        Http::fake([
            '*/upload/v1beta/files' => Http::response([], 200, ['x-goog-upload-url' => 'https://generativelanguage.googleapis.com/upload/test']),
            '*/upload/test' => Http::response(['file' => ['name' => 'files/test', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/test', 'state' => 'ACTIVE']]),
            '*/v1beta/interactions' => Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => $this->quote]]]]]),
            '*elevenlabs.io/*' => Http::response(['text' => $this->quote, 'words' => [['type' => 'word', 'text' => $this->quote, 'start' => 12, 'end' => 16, 'speaker_id' => 'speaker_0']]]),
            '*/v1beta/files/test' => Http::response([]),
        ]);
        $service = app(MeetingTranscriber::class);
        $r = $service->transcribe($m, ['방문']);
        $this->assertSame('done', $r['gemini']['status'], json_encode($r));
        $this->assertSame('done', $r['scribe']['status'], json_encode($r));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/interactions') && $r['generation_config']['transcription_config']['custom_vocabulary'] === ['방문'] && ! isset($r['generation_config']['transcription_config']['diarization']));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'elevenlabs.io') && str_contains($r->body(), 'name="diarize"') && str_contains($r->body(), 'name="keyterms"'));
        $count = Http::recorded()->count();
        $this->assertEquals($r, $service->transcribe($m->fresh(), ['방문']));
        $this->assertSame($count, Http::recorded()->count());
    }

    public function test_failed_provider_retries_without_resending_successful_transcript(): void
    {
        config(['services.gemini.api_key' => 'test', 'meetings.elevenlabs_key' => 'test']);
        $m = $this->meeting(['transcripts' => ['gemini' => ['status' => 'done', 'text' => $this->quote]]]);
        Storage::disk('meeting-test')->put($m->audio_path, 'audio');
        Http::fake(['*elevenlabs.io/*' => Http::response([], 401)]);
        $r = app(MeetingTranscriber::class)->transcribe($m, []);
        $this->assertSame('done', $r['gemini']['status']);
        $this->assertSame('failed', $r['scribe']['status']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'googleapis.com'));
    }

    public function test_partial_analysis_response_never_commits_items(): void
    {
        config(['services.gemini.api_key' => 'test']);
        Http::fake(['*generateContent' => Http::response(['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{"summary":"partial","items":[]}']]]]]])]);
        try {
            app(MeetingAnalyzer::class)->analyze($this->meeting(), app(MeetingContext::class)->get($this->site->id));
            $this->fail('Partial output accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('완결', $e->getMessage());
        }
        $this->assertSame(0, OpsIntakeItem::count());
    }

    public function test_undo_does_not_delete_a_follow_up_modified_by_another_person(): void
    {
        $i = $this->persist($this->meeting(), [$this->item()]);
        $a = OpsActionItem::firstOrFail();
        $a->update(['assignee' => '새 담당자']);
        $this->assertFalse(app(MeetingWorkflow::class)->undo($i->id, $this->admin, '취소')['success']);
        $this->assertDatabaseHas('ops_action_items', ['id' => $a->id, 'assignee' => '새 담당자']);
    }

    public function test_analyzed_meeting_runs_as_a_durable_job_and_alerts_only_its_creator(): void
    {
        $m = $this->meeting(['status' => 'queued']);
        $analyzer = $this->mock(MeetingAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn(['summary' => '확인 요청', 'items' => [$this->item()]]);
        $job = new ProcessOpsMeeting($m->id);
        $job->handle(app(MeetingTranscriber::class), $analyzer, app(MeetingContext::class), app(MeetingWorkflow::class));
        $this->assertSame('completed', $m->fresh()->status);
        $this->assertDatabaseHas('unified_alerts', ['user_id' => $this->admin->id, 'fingerprint' => 'meeting:'.$m->id, 'source_module' => 'OPS']);
        $job->handle(app(MeetingTranscriber::class), $analyzer, app(MeetingContext::class), app(MeetingWorkflow::class));
        $this->assertSame(1, OpsActionItem::count());
    }

    public function test_review_edit_accepts_targetless_internal_task_and_checks_creator_scope_again(): void
    {
        $m = $this->meeting();
        $i = $this->persist($m, [$this->item(['kind' => 'request', 'target_ref' => '', 'candidate_refs' => [], 'assignee' => ''])]);
        $this->patchJson('/ops-api/meetings/'.$m->id.'/items/'.$i->id, ['title' => '문 납기 확인 요청', 'target_ref' => '', 'assignee' => '자재 담당', 'changes' => [], 'due_on' => null])->assertOk();
        $this->postJson('/ops-api/meetings/'.$m->id.'/items/'.$i->id.'/apply', ['confirmed' => true])->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, OpsActionItem::count());
        $m2 = $this->meeting(['status' => 'queued']);
        $this->admin->update(['account_status' => 'suspended']);
        try {
            (new ProcessOpsMeeting($m2->id))->handle(app(MeetingTranscriber::class), app(MeetingAnalyzer::class), app(MeetingContext::class), app(MeetingWorkflow::class));
            $this->fail('Suspended creator allowed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        Http::assertNothingSent();
        $this->assertSame('failed', $m2->fresh()->status);
    }
}

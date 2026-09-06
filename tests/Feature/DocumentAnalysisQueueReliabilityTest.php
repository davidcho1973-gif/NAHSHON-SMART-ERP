<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\IntelligentDocument;
use App\Services\Alerts\UnifiedAlertService;
use App\Services\Documents\DocumentIntelligenceService;
use App\Services\Documents\StuckAnalysisReaper;
use App\Services\Ocr\OcrEngine;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DocumentAnalysisQueueReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $status = 'queued'): IntelligentDocument
    {
        return IntelligentDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'source' => 'dropzone', 'disk' => 'local',
            'file_path' => 'document-intelligence/inbox/test/doc.pdf',
            'original_file_name' => 'doc.pdf', 'stored_file_name' => 'doc.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 100,
            'sha256' => hash('sha256', (string) Str::uuid()),
            'title' => 'Original title', 'received_at' => now(), 'ai_status' => $status,
        ]);
    }

    public function test_legacy_backlog_does_not_reprocess_finished_failed_or_active_documents(): void
    {
        $service = Mockery::mock(DocumentIntelligenceService::class);
        $service->shouldNotReceive('process');
        $alerts = Mockery::mock(UnifiedAlertService::class);
        $alerts->shouldNotReceive('emit');

        foreach (['ready', 'review_required', 'failed', 'analyzing'] as $status) {
            $document = $this->document($status);
            $before = $document->fresh()->getAttributes();
            $legacy = new AnalyzeIntelligentDocumentJob($document->id);
            $legacy->analysisToken = null;
            $legacy->connection = null;
            $legacy->queue = null;
            // SerializesModels omits default-valued properties, matching old queued payloads.
            unserialize(serialize($legacy))->handle($service, $alerts);

            $this->assertSame($before, $document->fresh()->getAttributes());
        }
    }

    public function test_duplicate_job_cannot_claim_an_analysis_already_in_progress(): void
    {
        $document = $this->document();
        $first = new AnalyzeIntelligentDocumentJob($document->id);
        $second = new AnalyzeIntelligentDocumentJob($document->id);
        $alerts = Mockery::mock(UnifiedAlertService::class);
        $alerts->shouldNotReceive('emit');
        $service = Mockery::mock(DocumentIntelligenceService::class);
        $service->shouldReceive('process')->once()->andReturnUsing(function ($claimed) use ($first, $second, $service, $alerts) {
            $this->assertSame('analyzing', $claimed->fresh()->ai_status);
            $this->assertSame($first->analysisToken, $claimed->fresh()->ai_payload['analysis_run_token']);
            $second->handle($service, $alerts);
            $claimed->update(['ai_status' => 'ready']);

            return $claimed;
        });

        $first->handle($service, $alerts);

        $this->assertSame('ready', $document->fresh()->ai_status);
    }

    public function test_rolled_back_dirty_save_records_safe_failure_even_when_alert_delivery_fails(): void
    {
        $existing = $this->document('ready');
        $document = $this->document();
        $originalHash = $document->sha256;
        $service = Mockery::mock(DocumentIntelligenceService::class);
        $service->shouldReceive('process')->once()->andReturnUsing(function ($claimed) use ($existing) {
            return DB::transaction(function () use ($claimed, $existing) {
                $claimed->fill(['sha256' => $existing->sha256, 'title' => 'Dirty AI title']);
                $claimed->save(); // Real PostgreSQL unique violation, followed by a savepoint rollback.

                return $claimed;
            });
        });
        $alerts = Mockery::mock(UnifiedAlertService::class);
        $alerts->shouldReceive('emit')->once()->withArgs(function ($fingerprint, $data) {
            $this->assertStringContainsString('[DUPLICATE_SCOPE]', $data['content']);
            $this->assertStringNotContainsString('SQLSTATE', $data['content']);

            return true;
        })->andThrow(new \RuntimeException('Alert storage unavailable'));

        (new AnalyzeIntelligentDocumentJob($document->id))->handle($service, $alerts);

        $document->refresh();
        $this->assertSame('failed', $document->ai_status);
        $this->assertSame($originalHash, $document->sha256);
        $this->assertSame('Original title', $document->title);
        $this->assertStringContainsString('[DUPLICATE_SCOPE]', $document->ai_error);
        $this->assertStringNotContainsString('SQLSTATE', $document->ai_error);
        $this->assertSame('ready', $existing->fresh()->ai_status);
    }

    public function test_deserialized_failure_hook_only_updates_the_run_it_owns(): void
    {
        $document = $this->document();
        $job = new AnalyzeIntelligentDocumentJob($document->id);
        $serialized = serialize($job);
        $service = Mockery::mock(DocumentIntelligenceService::class);
        $service->shouldReceive('process')->once()->andReturnUsing(fn ($claimed) => $claimed);
        $alerts = Mockery::mock(UnifiedAlertService::class);
        $job->handle($service, $alerts);

        unserialize($serialized)->failed(new \RuntimeException('Provider timed out: private response'));
        $this->assertSame('failed', $document->fresh()->ai_status);
        $this->assertStringStartsWith('[ANALYSIS_TIMEOUT]', $document->fresh()->ai_error);

        $document->refresh()->update([
            'ai_status' => 'analyzing', 'ai_error' => null,
            'ai_payload' => ['analysis_run_token' => (string) Str::uuid()],
        ]);
        unserialize($serialized)->failed(new \RuntimeException('Old worker failure'));
        $this->assertSame('analyzing', $document->fresh()->ai_status);
        $this->assertNull($document->fresh()->ai_error);

        $document->refresh()->update(['ai_status' => 'ready']);
        unserialize($serialized)->failed(new \RuntimeException('Old worker failure'));
        $this->assertSame('ready', $document->fresh()->ai_status);
    }

    public function test_legacy_job_uses_queue_uuid_for_failure_ownership(): void
    {
        $document = $this->document();
        $legacy = new AnalyzeIntelligentDocumentJob($document->id);
        $legacy->analysisToken = null;
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('uuid')->andReturn('legacy-queue-uuid');
        $legacy->setJob($queueJob);
        $service = Mockery::mock(DocumentIntelligenceService::class);
        $service->shouldReceive('process')->once()->andReturnUsing(fn ($claimed) => $claimed);
        $legacy->handle($service, Mockery::mock(UnifiedAlertService::class));

        $this->assertSame('legacy-queue-uuid', $document->fresh()->ai_payload['analysis_run_token']);
        $legacy->failed(new \RuntimeException('Provider timed out'));
        $this->assertSame('failed', $document->fresh()->ai_status);
    }

    public function test_reaper_waits_beyond_worker_timeout_and_requeues_only_once_on_durable_queue(): void
    {
        Queue::fake();
        $recent = $this->document('analyzing');
        $recent->forceFill(['updated_at' => now()->subMinutes(9)])->saveQuietly();
        $stuck = $this->document('analyzing');
        $stuck->forceFill([
            'ai_payload' => ['analysis_run_token' => 'expired-run', 'key_facts' => ['Preserve data']],
            'updated_at' => now()->subMinutes(20),
        ])->saveQuietly();

        $reaper = app(StuckAnalysisReaper::class);
        $this->assertSame(['total' => 1, 'requeued' => 1, 'failed' => 0], $reaper->reap(3));
        $this->assertSame(['total' => 0, 'requeued' => 0, 'failed' => 0], $reaper->reap(3));
        $this->assertSame('analyzing', $recent->fresh()->ai_status);
        $this->assertSame(1, $stuck->fresh()->ai_payload['stuck_requeues']);
        $this->assertSame(['Preserve data'], $stuck->fresh()->ai_payload['key_facts']);
        $this->assertArrayNotHasKey('analysis_run_token', $stuck->fresh()->ai_payload);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 1);
        Queue::assertPushedOn('documents', AnalyzeIntelligentDocumentJob::class, fn ($job) => $job->documentId === $stuck->id && $job->connection === 'document-analysis');
        $this->assertGreaterThanOrEqual(900, config('queue.connections.document-analysis.retry_after'));
        $this->assertTrue(config('queue.connections.document-analysis.after_commit'));
    }

    public function test_reaper_keeps_old_queued_documents_with_durable_jobs_in_either_queue(): void
    {
        Queue::fake();
        foreach (['documents', 'default'] as $queue) {
            $document = $this->document();
            $document->forceFill([
                'updated_at' => now()->subMinutes(45),
                'ai_payload' => ['stuck_requeues' => 1],
            ])->saveQuietly();
            $this->persistJob($document, $queue);
        }
        $before = IntelligentDocument::query()->orderBy('id')->get()->toArray();

        $this->assertSame(['total' => 0, 'requeued' => 0, 'failed' => 0], app(StuckAnalysisReaper::class)->reap());
        $this->assertSame($before, IntelligentDocument::query()->orderBy('id')->get()->toArray());
        Queue::assertNothingPushed();
    }

    public function test_reaper_replaces_missing_queued_job_without_confusing_other_document_jobs(): void
    {
        Queue::fake();
        $other = $this->document();
        $this->persistJob($other, 'documents');
        $missing = $this->document();
        $missing->forceFill(['updated_at' => now()->subMinutes(45)])->saveQuietly();

        $this->assertSame(['total' => 1, 'requeued' => 1, 'failed' => 0], app(StuckAnalysisReaper::class)->reap());
        $this->assertSame(1, $missing->fresh()->ai_payload['stuck_requeues']);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 1);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, fn ($job) => $job->documentId === $missing->id);
    }

    public function test_diagnose_reads_document_connection_even_when_default_queue_is_sync(): void
    {
        config(['queue.default' => 'sync', 'services.ai_ocr.engine' => 'gemini', 'services.gemini.api_key' => 'test-key']);
        $this->mock(OcrEngine::class)->shouldReceive('maxAttachmentBytes')->once()->andReturn(50 * 1048576);
        $this->persistJob($this->document(), 'documents');
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => json_encode(['displayName' => 'OtherJob']),
            'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);

        $this->artisan('docs:diagnose')
            ->expectsOutputToContain('문서 전용 연결  document-analysis')
            ->expectsOutputToContain('문서 일감  1건')
            ->expectsOutputToContain('queue:work document-analysis --queue=documents,default')
            ->assertSuccessful();
    }

    public function test_reanalysis_request_resets_retry_state_once_and_preserves_analysis_content(): void
    {
        Queue::fake();
        $document = $this->document('failed');
        $document->update([
            'ai_error' => 'Earlier failure',
            'ai_payload' => ['analysis_run_token' => 'earlier-run', 'stuck_requeues' => 1, 'key_facts' => ['Keep this']],
        ]);

        $this->assertTrue(AnalyzeIntelligentDocumentJob::requestReanalysis($document->id));
        $this->assertFalse(AnalyzeIntelligentDocumentJob::requestReanalysis($document->id));
        $document->refresh();
        $this->assertSame('queued', $document->ai_status);
        $this->assertNull($document->ai_error);
        $this->assertSame(['key_facts' => ['Keep this']], $document->ai_payload);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 1);
        Queue::assertPushedOn('documents', AnalyzeIntelligentDocumentJob::class, fn ($job) => $job->documentId === $document->id);
    }

    public function test_reanalysis_request_cannot_reset_active_or_duplicate_documents(): void
    {
        Queue::fake();
        foreach (['queued', 'analyzing', 'review_required'] as $status) {
            $document = $this->document($status);
            $document->update(['ai_payload' => [
                'analysis_run_token' => 'current-run', 'stuck_requeues' => 1,
                ...($status === 'review_required' ? ['duplicate_document_id' => 123] : []),
            ]]);
            $before = $document->fresh()->getAttributes();

            $this->assertFalse(AnalyzeIntelligentDocumentJob::requestReanalysis($document->id));
            $this->assertSame($before, $document->fresh()->getAttributes());
        }

        $this->assertFalse(AnalyzeIntelligentDocumentJob::requestReanalysis(999999));
        Queue::assertNothingPushed();
    }

    private function persistJob(IntelligentDocument $document, string $queue): void
    {
        DB::table(config('queue.connections.document-analysis.table', 'jobs'))->insert([
            'queue' => $queue,
            'payload' => json_encode([
                'displayName' => AnalyzeIntelligentDocumentJob::class,
                'data' => ['command' => serialize(new AnalyzeIntelligentDocumentJob($document->id))],
            ]),
            'attempts' => 0, 'reserved_at' => null,
            'available_at' => time(), 'created_at' => time() - 2700,
        ]);
    }
}

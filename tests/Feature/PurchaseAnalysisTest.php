<?php

namespace Tests\Feature;

use App\Jobs\RunPurchaseAnalysis;
use App\Models\AiJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Ocr\OcrEngine;
use App\Services\Procurement\PurchaseDraftAnalyzer;
use App\Services\Takeoff\AiJobQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function requester(Site $site): User
    {
        $user = User::factory()->create(['access_role' => 'site_manager', 'account_status' => 'active', 'access_scope' => 'site', 'allowed_site_id' => $site->id]);
        $user->forceFill(['purchase_request_enabled' => true])->save();

        return $user;
    }

    public function test_analysis_is_durable_idempotent_private_and_rechecks_revoked_access(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['filesystems.wbs_photos_disk' => 'local']);
        $site = Site::create(['code' => 'PUR-A', 'name' => 'Test site', 'status' => 'active']);
        $user = $this->requester($site);
        $this->actingAsPurchaseUser($user);
        $payload = ['site_id' => $site->id, 'mode' => 'request', 'text' => '3/4 inch coupling 10 EA', 'request_key' => (string) Str::uuid()];
        $r = $this->postJson('/purchase-requests/analyze', $payload)->assertStatus(202);
        $id = $r->json('job_id');
        $this->postJson('/purchase-requests/analyze', $payload)->assertJsonPath('job_id', $id);
        Queue::assertPushed(RunPurchaseAnalysis::class, 1);
        Queue::assertPushed(RunPurchaseAnalysis::class, fn ($job) => $job->queue === 'purchases' && $job->connection === 'document-analysis');
        $this->postJson('/purchase-requests/analyze', array_merge($payload, ['text' => 'different']))->assertConflict();
        $this->getJson('/purchase-requests/analysis/'.$id)->assertOk()->assertJsonMissingPath('params');
        $this->actingAsPurchaseUser(User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']));
        $this->getJson('/purchase-requests/analysis/'.$id)->assertNotFound();
        $this->assertFalse(AiJobQueue::status($id)['success']);
        $user->forceFill(['purchase_request_enabled' => false])->save();
        $analyzer = $this->mock(PurchaseDraftAnalyzer::class);
        $analyzer->shouldNotReceive('analyze');
        (new RunPurchaseAnalysis($id))->handle($analyzer);
        $this->assertSame('failed', AiJob::find($id)->status);
        $this->assertDatabaseCount('purchase_requests', 0);
    }

    public function test_requester_cannot_analyze_order_and_foreign_site_or_upload_executable(): void
    {
        Queue::fake();
        $site = Site::create(['code' => 'PUR-B', 'name' => 'A', 'status' => 'active']);
        $other = Site::create(['code' => 'PUR-C', 'name' => 'B', 'status' => 'active']);
        $this->actingAsPurchaseUser($this->requester($site));
        $payload = ['site_id' => $site->id, 'mode' => 'order', 'text' => 'PO', 'request_key' => (string) Str::uuid()];
        $this->postJson('/purchase-requests/analyze', $payload)->assertForbidden();
        $this->postJson('/purchase-requests/analyze', array_merge($payload, ['mode' => 'request', 'site_id' => $other->id]))->assertForbidden();
        $this->postJson('/purchase-requests/analyze', array_merge($payload, ['mode' => 'request', 'file' => UploadedFile::fake()->create('x.php', 1, 'application/x-httpd-php')]))->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_extraction_never_invents_missing_quantity_or_creates_orders(): void
    {
        $this->mock(OcrEngine::class, function ($mock) {
            $mock->shouldReceive('analyze')->once()->andReturn(['data' => ['lines' => [['name' => 'Pipe fitting', 'quantity' => null, 'product_url' => 'javascript:alert(1)']], 'questions' => ['연결 규격은?'], 'need_by' => '2026-02-31'], 'model' => 'fake']);
            $mock->shouldReceive('name')->andReturn('fake');
        });
        $job = new AiJob(['params' => ['mode' => 'request', 'text' => '배관 피팅 필요']]);
        $result = app(PurchaseDraftAnalyzer::class)->analyze($job);
        $this->assertNull($result['lines'][0]['quantity']);
        $this->assertSame('', $result['lines'][0]['product_url']);
        $this->assertNull($result['need_by']);
        $this->assertSame(['연결 규격은?'], $result['questions']);
        $this->assertDatabaseCount('purchase_requests', 0);
    }

    public function test_worker_persists_draft_result_and_stalled_jobs_stop_showing_running(): void
    {
        $site = Site::create(['code' => 'PUR-D', 'name' => 'D', 'status' => 'active']);
        $user = $this->requester($site);
        $this->actingAsPurchaseUser($user);
        $job = AiJob::create(['user_id' => $user->id, 'kind' => 'purchase_draft', 'subject_type' => 'purchase_draft', 'subject_id' => $site->id,
            'status' => 'queued', 'label' => 'Test', 'params' => ['site_id' => $site->id, 'mode' => 'request', 'text' => 'fitting']]);
        $analyzer = $this->mock(PurchaseDraftAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn(['success' => true, 'lines' => [['name' => 'Fitting', 'quantity' => null]], 'draft' => true]);
        (new RunPurchaseAnalysis($job->id))->handle($analyzer);
        $this->getJson('/purchase-requests/analysis/'.$job->id)->assertJsonPath('status', 'done')->assertJsonPath('result.lines.0.name', 'Fitting');
        (new RunPurchaseAnalysis($job->id))->handle($analyzer);
        $job->refresh()->forceFill(['status' => 'running', 'started_at' => now()->subMinutes(16)])->save();
        $this->getJson('/purchase-requests/analysis/'.$job->id)->assertJsonPath('status', 'failed');
        $this->assertDatabaseCount('purchase_requests', 0);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\IntelligentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "AI 분석 중"에 갇힌 문서를 되살린다.
 *
 * 분석은 시작할 때 상태를 'analyzing' 으로 바꾼다. 작업 프로세스가 메모리 초과나
 * 배포 재시작으로 죽으면 그 상태를 되돌릴 사람이 없어 문서는 영원히 도는 것처럼
 * 보인다 — 실제로는 아무도 일하지 않는데도. 사용자가 할 수 있는 일은 하나씩 열어
 * "재분석"을 누르는 것뿐이었다.
 */
class StuckDocumentAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $status, int $minutesAgo, array $payload = []): IntelligentDocument
    {
        $doc = IntelligentDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'source' => 'dropzone', 'disk' => 'local',
            'file_path' => 'document-intelligence/inbox/x/doc.pdf',
            'original_file_name' => 'doc.pdf', 'stored_file_name' => 'doc.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 100,
            'sha256' => hash('sha256', uniqid('', true)),
            'title' => 'doc', 'received_at' => now(),
            'ai_status' => $status, 'ai_payload' => $payload ?: null,
        ]);
        // updated_at 이 "언제부터 멈췄나"의 기준이다.
        $doc->forceFill(['updated_at' => now()->subMinutes($minutesAgo)])->saveQuietly();

        return $doc->fresh();
    }

    public function test_오래_멈춘_문서는_한_번_자동으로_다시_분석된다(): void
    {
        Queue::fake();
        $doc = $this->document('analyzing', 60);

        $this->artisan('docs:reap-stuck', ['--minutes' => 15])->assertSuccessful();

        $doc->refresh();
        $this->assertSame('queued', $doc->ai_status);
        $this->assertSame(1, $doc->ai_payload['stuck_requeues']);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class);
    }

    public function test_다시_시도해도_멈추면_실패로_표시해_사용자가_알게_한다(): void
    {
        Queue::fake();
        $doc = $this->document('analyzing', 60, ['stuck_requeues' => 1]);

        $this->artisan('docs:reap-stuck', ['--minutes' => 15])->assertSuccessful();

        $doc->refresh();
        $this->assertSame('failed', $doc->ai_status, '도는 척하는 것보다 실패가 낫다');
        $this->assertStringContainsString('응답이 없어 중단', (string) $doc->ai_error);
        $this->assertStringContainsString('재분석', (string) $doc->ai_error, '다음에 뭘 하면 되는지 알려 준다');
        Queue::assertNothingPushed();
    }

    public function test_방금_시작한_분석은_건드리지_않는다(): void
    {
        Queue::fake();
        $doc = $this->document('analyzing', 2);

        $this->artisan('docs:reap-stuck', ['--minutes' => 15])->assertSuccessful();

        $this->assertSame('analyzing', $doc->fresh()->ai_status, '정상 진행 중인 분석을 끊으면 안 된다');
        Queue::assertNothingPushed();
    }

    public function test_끝난_문서는_대상이_아니다(): void
    {
        Queue::fake();
        $done = $this->document('ready', 600);
        $failed = $this->document('failed', 600);

        $this->artisan('docs:reap-stuck', ['--minutes' => 15])->assertSuccessful();

        $this->assertSame('ready', $done->fresh()->ai_status);
        $this->assertSame('failed', $failed->fresh()->ai_status);
        Queue::assertNothingPushed();
    }

    public function test_큐에서_잊힌_문서도_되살린다(): void
    {
        // 워커가 잠시 죽어 있는 동안 접수된 문서는 'queued' 로 남는다.
        Queue::fake();
        $doc = $this->document('queued', 60);

        $this->artisan('docs:reap-stuck', ['--minutes' => 15])->assertSuccessful();

        $this->assertSame('queued', $doc->fresh()->ai_status);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class);
    }

    public function test_화면_버튼으로도_한_번에_되살릴_수_있다(): void
    {
        // 스케줄러가 꺼진 환경도 있고, 사용자는 지금 당장 풀고 싶다.
        Queue::fake();
        $this->document('analyzing', 60);
        $this->document('analyzing', 60);
        $this->actingAs(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        $res = $this->postJson('/document-hub/api/reanalyze-stuck', ['minutes' => 15])->assertOk();

        $this->assertSame(2, $res->json('requeued'));
        $this->assertSame(0, IntelligentDocument::where('ai_status', 'analyzing')->count());
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 2);
    }

    public function test_권한_없는_사용자는_되살리기를_못_한다(): void
    {
        Queue::fake();
        $this->document('analyzing', 60);
        $this->actingAs(User::factory()->create([
            'access_role' => 'worker', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        $this->postJson('/document-hub/api/reanalyze-stuck')->assertForbidden();
        Queue::assertNothingPushed();
    }
}

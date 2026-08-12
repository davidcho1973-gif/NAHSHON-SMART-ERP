<?php

namespace Tests\Feature;

use App\Http\Controllers\OpsPhotoController;
use App\Jobs\AnalyzeOpsIntakeJob;
use App\Models\OpsIntakeBatch;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Ops\OpsIntakeAnalyzer;
use App\Services\Ops\OpsIntakeService;
use App\Support\ImageDownscale;
use App\Support\ImageParts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 상황실 사진 판독 504 해소 — 업로드는 한 장씩(크기 제한 없음), 판독은 응답 후 백그라운드.
 */
class OpsPhotoAsyncTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.documents_disk' => 'ops-test']);
        Storage::fake('ops-test');

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
    }

    /** 실제 폰 사진처럼 잘 안 눌리는(노이즈 많은) 큰 JPEG 을 만든다. */
    private function bigPhoto(int $w = 2400, int $h = 1800): string
    {
        $im = imagecreatetruecolor($w, $h);
        mt_srand(11);
        for ($y = 0; $y < $h; $y += 3) {
            for ($x = 0; $x < $w; $x += 3) {
                imagefilledrectangle($im, $x, $y, $x + 2, $y + 2, imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
        ob_start();
        imagejpeg($im, null, 92);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    public function test_photo_upload_accepts_a_large_original_and_returns_a_token(): void
    {
        $user = $this->user();
        $bytes = $this->bigPhoto();
        $this->assertGreaterThan(1024 * 1024, strlen($bytes), '테스트 사진이 1MB 는 넘어야 의미가 있다');

        $res = $this->actingAs($user)->post('/ops-api/photo', [
            'photo' => UploadedFile::fake()->createWithContent('site.jpg', $bytes),
        ]);

        $res->assertStatus(200)->assertJson(['success' => true]);
        $token = $res->json('token');

        // 원본이 줄지 않고 그대로 보관된다 — 줄이는 건 AI 에 넘기기 직전에만 한다.
        Storage::disk('ops-test')->assertExists(OpsPhotoController::pathFor($user->id, $token));
        $this->assertSame(strlen($bytes), strlen(Storage::disk('ops-test')->get(OpsPhotoController::pathFor($user->id, $token))));
    }

    public function test_non_image_upload_is_rejected(): void
    {
        $this->actingAs($this->user())->post('/ops-api/photo', [
            'photo' => UploadedFile::fake()->createWithContent('note.txt', 'hello'),
        ])->assertStatus(422);
    }

    public function test_a_token_from_another_user_is_not_resolvable(): void
    {
        $mine = $this->user();
        $theirs = $this->user();

        $token = $this->actingAs($theirs)->post('/ops-api/photo', [
            'photo' => UploadedFile::fake()->createWithContent('x.jpg', $this->bigPhoto(400, 300)),
        ])->json('token');

        $this->assertSame([], OpsPhotoController::resolve([$token], $mine->id));
        $this->assertCount(1, OpsPhotoController::resolve([$token], $theirs->id));
    }

    public function test_garbage_tokens_are_dropped(): void
    {
        $this->assertSame([], OpsPhotoController::resolve(['../../etc/passwd', '', 'not-a-uuid', 123], 1));
    }

    public function test_ingest_returns_immediately_and_defers_the_ai_call(): void
    {
        Bus::fake();
        $user = $this->user();

        $token = $this->actingAs($user)->post('/ops-api/photo', [
            'photo' => UploadedFile::fake()->createWithContent('x.jpg', $this->bigPhoto(800, 600)),
        ])->json('token');

        $res = $this->actingAs($user)->postJson('/smart-company-api/api_opsIngest', [
            'args' => ['천장 배관 12개 완료', [$token]],
            'siteId' => 'ALL',
        ]);

        $res->assertStatus(200)->assertJson(['success' => true, 'status' => 'analyzing', 'imageCount' => 1]);

        // 요청 안에서 AI 를 부르지 않는다 — 이것이 504 를 없앤 핵심이다.
        Bus::assertDispatchedAfterResponse(AnalyzeOpsIntakeJob::class);

        $batch = OpsIntakeBatch::find($res->json('batchId'));
        $this->assertSame('analyzing', $batch->status);
        $this->assertSame('천장 배관 12개 완료', $batch->raw_text);
        $this->assertCount(1, $batch->photo_paths);
    }

    public function test_polling_reports_analyzing_then_done_with_items(): void
    {
        Bus::fake();
        $user = $this->user();
        WbsItem::create(['project_code' => 'P1', 'level' => 'subtask', 'wbs_code' => 'P1-A1', 'name' => '천장 배관', 'status' => '진행중', 'site_id' => $this->site->id]);

        $batchId = $this->actingAs($user)->postJson('/smart-company-api/api_opsIngest', [
            'args' => ['천장 배관 12개 완료', []], 'siteId' => 'ALL',
        ])->json('batchId');

        $this->actingAs($user)->postJson('/smart-company-api/api_getOpsJob', ['args' => [$batchId], 'siteId' => 'ALL'])
            ->assertStatus(200)->assertJson(['status' => 'analyzing']);

        // 백그라운드 판독을 직접 돌린다(afterResponse 는 테스트에서 실행되지 않는다).
        $this->swap(OpsIntakeAnalyzer::class, new class extends OpsIntakeAnalyzer
        {
            public function __construct() {}

            public function read(string $text, array $activities, array $purchases, string $today, array $images = [], string $learned = '', array $photoKinds = []): array
            {
                return [[
                    'category' => 'progress', 'summary' => '천장 배관 60%', 'raw_text' => $text,
                    'confidence' => 90, 'target_type' => 'wbs', 'target_code' => 'P1-A1',
                    'proposed' => ['progress' => 60],
                ]];
            }
        });
        app(OpsIntakeService::class)->analyze($batchId);

        $res = $this->actingAs($user)->postJson('/smart-company-api/api_getOpsJob', ['args' => [$batchId], 'siteId' => 'ALL']);
        $res->assertStatus(200)->assertJson(['status' => 'done', 'parsed' => 1, 'actionable' => 1]);
        $this->assertSame('P1-A1', $res->json('items.0.targetCode'));
    }

    public function test_a_failed_analysis_is_reported_not_left_hanging(): void
    {
        $batch = OpsIntakeBatch::create([
            'site_id' => $this->site->id, 'source' => 'paste',
            'raw_text' => '내용', 'status' => 'analyzing',
        ]);

        $this->swap(OpsIntakeAnalyzer::class, new class extends OpsIntakeAnalyzer
        {
            public function __construct() {}

            public function read(string $text, array $activities, array $purchases, string $today, array $images = [], string $learned = '', array $photoKinds = []): array
            {
                throw new \RuntimeException('Gemini 응답 없음');
            }
        });

        app(OpsIntakeService::class)->analyze($batch->id);

        $batch->refresh();
        $this->assertSame('failed', $batch->status);
        $this->assertStringContainsString('Gemini', (string) $batch->error);

        $this->actingAs($this->user())->postJson('/smart-company-api/api_getOpsJob', ['args' => [$batch->id], 'siteId' => 'ALL'])
            ->assertJson(['status' => 'failed']);
    }

    public function test_photos_are_downscaled_before_the_ai_call_and_cleaned_up_after(): void
    {
        $user = $this->user();
        $token = $this->actingAs($user)->post('/ops-api/photo', [
            'photo' => UploadedFile::fake()->createWithContent('big.jpg', $this->bigPhoto()),
        ])->json('token');
        $path = OpsPhotoController::pathFor($user->id, $token);

        $batchId = app(OpsIntakeService::class)
            ->queue('사진 판독', $this->site, $user->id, [$path])['batchId'];

        $seen = null;
        $this->swap(OpsIntakeAnalyzer::class, new class($seen) extends OpsIntakeAnalyzer
        {
            public function __construct(public &$seen) {}

            public function read(string $text, array $activities, array $purchases, string $today, array $images = [], string $learned = '', array $photoKinds = []): array
            {
                $this->seen = $images;

                return [];
            }
        });
        app(OpsIntakeService::class)->analyze($batchId);

        $this->assertCount(1, $seen);
        $decoded = base64_decode($seen[0]['data']);
        [$w, $h] = getimagesizefromstring($decoded);
        $this->assertLessThanOrEqual(ImageDownscale::MAX_EDGE, max($w, $h), '비전 API 로 원본 크기가 그대로 나가면 안 된다');
        $this->assertSame('image/jpeg', $seen[0]['mime_type']);

        // 판독이 끝나면 원본 사진은 정리된다.
        Storage::disk('ops-test')->assertMissing($path);
        $this->assertNull(OpsIntakeBatch::find($batchId)->photo_paths);
    }

    public function test_ops_may_send_more_photos_than_the_synchronous_paths(): void
    {
        $this->assertGreaterThan(6, ImageParts::MAX_IMAGES);

        $many = array_fill(0, 30, ['data' => base64_encode('x'), 'mime_type' => 'image/jpeg']);

        // 상황실(비동기)은 상한이 높고,
        $this->assertCount(ImageParts::MAX_IMAGES, ImageParts::sanitize($many, ImageParts::MAX_IMAGES));
        // 아직 요청 안에서 동기로 도는 경로(작업마감 사진 등)는 6장 그대로 — 늘리면 다시 타임아웃.
        $this->assertCount(6, ImageParts::sanitize($many));
    }

    public function test_ops_queue_caps_photo_count(): void
    {
        $paths = array_map(fn (int $i): string => 'ops-intake/1/'.$i.'.bin', range(1, 30));

        // 사람 id 를 숫자로 박지 않는다. 예전에는 마이그레이션이 1번 계정을 만들어
        // 둬서 우연히 통했는데, 그 계정은 이제 원본에 없다.
        $actor = User::factory()->create();

        $res = app(OpsIntakeService::class)->queue('많은 사진', $this->site, $actor->id, $paths);

        $this->assertSame(ImageParts::MAX_IMAGES, $res['imageCount']);
    }

    public function test_queue_rejects_an_empty_submission(): void
    {
        $res = app(OpsIntakeService::class)->queue('   ', $this->site, 1, []);

        $this->assertFalse($res['success']);
    }
}

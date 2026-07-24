<?php

namespace Tests\Feature;

use App\Models\IntegratedDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 문서통합관리 업로드 용량 정책.
 *  - 상한(기본 256MB)까지 허용, 초과 시 한글 메시지로 거부.
 *  - 분석 상한(기본 50MB) 초과 대용량은 AI 없이 "보관 등록"(무한 '분석중' 방지).
 */
class IntegratedDocumentUploadSizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['filesystems.documents_disk' => 'public']);
    }

    private function admin(): User
    {
        return User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
    }

    public function test_file_above_max_is_rejected_with_korean_message(): void
    {
        config(['filesystems.documents_max_kb' => 262144]); // 256MB
        $file = UploadedFile::fake()->create('huge.pdf', 300000); // ~293MB

        $res = $this->actingAs($this->admin())->postJson(route('docs.upload'), ['file' => $file]);

        $res->assertStatus(422);
        $this->assertStringContainsString('최대 256MB', $res->json('message') ?? json_encode($res->json()));
        $this->assertSame(0, IntegratedDocument::count());
    }

    public function test_large_analyzable_file_is_stored_without_ai(): void
    {
        config(['filesystems.documents_max_kb' => 262144, 'filesystems.documents_analyze_max_kb' => 51200]); // 분석 상한 50MB
        $file = UploadedFile::fake()->create('PHASE 10 CITY SUBMITTAL.pdf', 60000); // ~58MB > 50MB

        $res = $this->actingAs($this->admin())->postJson(route('docs.upload'), ['file' => $file]);

        // AI 분석(analyzing)이 아니라 보관 등록(needs_review)으로 즉시 처리.
        $res->assertStatus(201)->assertJsonPath('status', 'needs_review');
        $doc = IntegratedDocument::firstOrFail();
        $this->assertNotSame('analyzing', $doc->status);
        Storage::disk('public')->assertExists($doc->path);
    }

    public function test_raising_the_limit_lets_a_previously_rejected_file_through(): void
    {
        // 예전 32MB 상한을 넘던 40MB 파일이 이제는 통과해야 한다(분석 상한을 낮춰 AI 없이 보관).
        config(['filesystems.documents_max_kb' => 262144, 'filesystems.documents_analyze_max_kb' => 10240]);
        $file = UploadedFile::fake()->create('submittal.pdf', 40000); // ~39MB (구 32MB 초과)

        $res = $this->actingAs($this->admin())->postJson(route('docs.upload'), ['file' => $file]);

        $res->assertStatus(201)->assertJsonPath('status', 'needs_review');
        $this->assertSame(1, IntegratedDocument::count());
    }
}

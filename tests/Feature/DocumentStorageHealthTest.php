<?php

namespace Tests\Feature;

use App\Models\IntegratedDocument;
use App\Services\IntegratedDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 문서 보관 디스크 점검 — 임시 로컬에 쌓이면 '위험'으로 드러나야 한다.
 * (Laravel Cloud 로컬 디스크는 배포마다 초기화되어 원본이 사라진다.)
 */
class DocumentStorageHealthTest extends TestCase
{
    use RefreshDatabase;

    private function service(): IntegratedDocumentService
    {
        return app(IntegratedDocumentService::class);
    }

    public function test_local_disk_is_reported_as_critical(): void
    {
        config(['filesystems.documents_disk' => 'public']); // driver=local

        $h = $this->service()->storageHealth();

        $this->assertSame('critical', $h['level']);
        $this->assertFalse($h['persistent']);
        $this->assertStringContainsString('사라집니다', $h['message']);
    }

    public function test_object_storage_is_reported_as_ok(): void
    {
        config([
            'filesystems.documents_disk' => 'docs_test_s3',
            'filesystems.disks.docs_test_s3' => ['driver' => 's3'],
        ]);
        Storage::fake('docs_test_s3');

        $h = $this->service()->storageHealth();

        $this->assertSame('ok', $h['level']);
        $this->assertTrue($h['persistent']);
    }

    public function test_missing_originals_are_detected_on_persistent_disk(): void
    {
        config([
            'filesystems.documents_disk' => 'docs_test_s3',
            'filesystems.disks.docs_test_s3' => ['driver' => 's3'],
        ]);
        Storage::fake('docs_test_s3');

        // DB 에는 있는데 실제 파일이 없는 문서(이미 유실된 상태).
        IntegratedDocument::create([
            'folder_code' => '03', 'title' => '사라진 문서', 'status' => 'confirmed',
            'disk' => 'docs_test_s3', 'path' => 'integrated-documents/gone.pdf',
            'type_confidence' => 90, 'folder_confidence' => 90,
        ]);

        $h = $this->service()->storageHealth();

        $this->assertSame('warn', $h['level']);
        $this->assertSame(1, $h['sampleMissing']);
    }

    public function test_storage_check_command_fails_loudly_on_local_disk(): void
    {
        config(['filesystems.documents_disk' => 'public']);

        $this->artisan('docs:storage-check')->assertExitCode(1);
    }
}

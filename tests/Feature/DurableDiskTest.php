<?php

namespace Tests\Feature;

use App\Support\DurableDisk;
use Tests\TestCase;

/**
 * 영구 보관 업로드가 어느 디스크로 가는가.
 *
 * Laravel Cloud 로컬 디스크는 배포마다 초기화된다. 그런데 .env 에 남아 있던
 * DOCUMENT_STORAGE_DISK=local (대개 .env.example 에서 복사돼 온 기본값이지
 * 의도한 선택이 아니다) 때문에 버킷을 붙여 놓고도 계속 local 에 저장됐고,
 * 문서 원본이 배포마다 조용히 사라졌다 — 화면엔 멀쩡히 보이는데 다운로드는 404.
 */
class DurableDiskTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('AWS_BUCKET');
        unset($_ENV['AWS_BUCKET'], $_SERVER['AWS_BUCKET']);
        parent::tearDown();
    }

    private function withBucket(?string $bucket): void
    {
        if ($bucket === null) {
            putenv('AWS_BUCKET');
            unset($_ENV['AWS_BUCKET'], $_SERVER['AWS_BUCKET']);

            return;
        }
        putenv("AWS_BUCKET={$bucket}");
        $_ENV['AWS_BUCKET'] = $bucket;
        $_SERVER['AWS_BUCKET'] = $bucket;
    }

    public function test_버킷이_없으면_명시값이나_폴백을_그대로_쓴다(): void
    {
        $this->withBucket(null);

        $this->assertSame('local', DurableDisk::resolve(null, 'local'));
        $this->assertSame('public', DurableDisk::resolve(null, 'public'));
        $this->assertSame('local', DurableDisk::resolve('local', 'public'));
    }

    public function test_버킷이_있으면_남아있던_local_설정을_무시하고_s3_로_간다(): void
    {
        // 이것이 실제로 문서 원본을 잃게 만든 조합이다.
        $this->withBucket('smart_erp_files');

        $this->assertSame('s3', DurableDisk::resolve('local', 'local'));
        $this->assertSame('s3', DurableDisk::resolve(null, 'public'));
        $this->assertSame('s3', DurableDisk::resolve('', 'local'));
    }

    public function test_버킷이_있어도_의도적으로_고른_다른_디스크는_존중한다(): void
    {
        // 'local' 만 "고르지 않았다"로 본다 — public·custom 은 사람이 정한 값이다.
        $this->withBucket('smart_erp_files');

        $this->assertSame('public', DurableDisk::resolve('public', 'local'));
        $this->assertSame('sftp_archive', DurableDisk::resolve('sftp_archive', 'local'));
    }
}

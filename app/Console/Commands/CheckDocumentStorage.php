<?php

namespace App\Console\Commands;

use App\Services\IntegratedDocumentService;
use Illuminate\Console\Command;

/**
 * 문서 보관 디스크 점검 — 임시 로컬 디스크에 쌓이고 있으면 경고한다.
 * (Laravel Cloud 로컬 디스크는 배포마다 초기화되어 원본이 사라진다.)
 */
class CheckDocumentStorage extends Command
{
    protected $signature = 'docs:storage-check';

    protected $description = '문서통합관리 보관 디스크가 영구 저장소인지 점검';

    public function handle(IntegratedDocumentService $service): int
    {
        $h = $service->storageHealth();

        $this->line(sprintf('디스크: %s (driver=%s) · 문서 %d건', $h['disk'], $h['driver'], $h['documents']));
        $this->line(sprintf('표본 점검: %d건 중 %d건 원본 없음', $h['sampleChecked'], $h['sampleMissing']));

        if ($h['level'] === 'critical') {
            $this->error('[위험] ' . $h['message']);
            $this->line('  해결: 오브젝트 스토리지 연결 후 환경변수 DOCUMENT_DISK=s3 (또는 AWS_BUCKET) 설정.');

            return self::FAILURE;
        }
        if ($h['level'] === 'warn') {
            $this->warn('[주의] ' . $h['message']);

            return self::SUCCESS;
        }

        $this->info('[정상] ' . $h['message']);

        return self::SUCCESS;
    }
}

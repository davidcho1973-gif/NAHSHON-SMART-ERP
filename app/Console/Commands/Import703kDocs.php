<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Support\DurableDisk;
use App\Support\Org;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 703K 문서 원본(도면 분할본·대장 엑셀·RFI)을 오브젝트 스토리지로 직접 넣는 보조 커맨드.
 *
 * 웹 업로드를 못 쓰는 대용량 일괄 반입용 2단계:
 *   1) --presign : database/data/703k/docs_manifest.json 의 각 파일에 대해
 *      15분짜리 서명 PUT URL 을 출력한다. 로컬에서 curl -T 로 그 URL 에 올린다.
 *      (키: document-intelligence/manual-703k/{파일명})
 *   2) --register : 스테이징 키를 정식 inbox/{uuid}/ 경로로 옮기고, sha256 을
 *      매니페스트와 대조 검증한 뒤 intelligent_documents 행을 만든다.
 *      sha256 이 이미 있으면 건너뛴다(멱등). AI 분석은 큐 상태로 두고
 *      docs:reap-stuck / 스케줄러가 회수하게 맡긴다.
 */
class Import703kDocs extends Command
{
    protected $signature = 'erp:import-703k-docs
        {--presign : 매니페스트 파일별 서명 업로드 URL 출력}
        {--register : 업로드된 파일 검증 후 문서 행 생성}
        {--from=0 : presign 시작 인덱스}
        {--to=999 : presign 끝 인덱스(포함)}';

    protected $description = '703K 문서 일괄 반입 — S3 서명 URL 발급(--presign)과 등록(--register)';

    private const PREFIX = 'document-intelligence/manual-703k/';

    public function handle(): int
    {
        $manifestPath = database_path('data/703k/docs_manifest.json');
        if (! is_file($manifestPath)) {
            $this->error('매니페스트가 없습니다: '.$manifestPath);

            return self::FAILURE;
        }
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $disk = DurableDisk::resolve(env('DOCUMENT_STORAGE_DISK'), env('FILESYSTEM_DISK', 'local'));
        $storage = Storage::disk($disk);

        if ($this->option('presign')) {
            $from = (int) $this->option('from');
            $to = (int) $this->option('to');
            $this->line("DISK={$disk} COUNT=".count($manifest));
            foreach ($manifest as $i => $m) {
                if ($i < $from || $i > $to) {
                    continue;
                }
                ['url' => $url] = $storage->temporaryUploadUrl(self::PREFIX.$m['name'], now()->addMinutes(30));
                $this->line("PS|{$i}|{$m['name']}|{$url}");
            }

            return self::SUCCESS;
        }

        if (! $this->option('register')) {
            $this->error('--presign 또는 --register 를 지정하세요.');

            return self::FAILURE;
        }

        $own = Company::query()->where('code', Org::code())->first();
        $site = Site::query()->where('code', '703K')->first();
        $project = Project::query()->where('project_code', '703K-KITCHEN')->first();
        if (! $own || ! $site || ! $project) {
            $this->error('선행 데이터가 없습니다 — 먼저 erp:import-703k 를 실행하세요.');

            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;
        $missing = [];
        $badHash = [];
        foreach ($manifest as $m) {
            $stageKey = self::PREFIX.$m['name'];
            if (! $storage->exists($stageKey)) {
                $missing[] = $m['name'];

                continue;
            }

            $stream = $storage->readStream($stageKey);
            $ctx = hash_init('sha256');
            while (! feof($stream)) {
                hash_update($ctx, (string) fread($stream, 1024 * 1024));
            }
            fclose($stream);
            $sha = hash_final($ctx);
            if ($sha !== $m['sha256']) {
                $badHash[] = $m['name'];

                continue;
            }

            $exists = IntelligentDocument::query()
                ->where('sha256', $sha)
                ->where('company_id', $own->id)
                ->exists();
            if ($exists) {
                $skipped++;
                $storage->delete($stageKey);

                continue;
            }

            $uuid = (string) Str::uuid();
            $safeName = $m['name']; // 원문 이름 유지 — uuid 디렉터리라 충돌 없음
            $finalKey = "document-intelligence/inbox/{$uuid}/{$safeName}";
            $storage->move($stageKey, $finalKey);

            IntelligentDocument::query()->create([
                'uuid' => $uuid,
                'company_id' => $own->id,
                'site_id' => $site->id,
                'project_id' => $project->id,
                'file_path' => $finalKey,
                'original_file_name' => $m['name'],
                'stored_file_name' => $safeName,
                'mime_type' => $m['mime'],
                'file_size' => $m['size'],
                'sha256' => $sha,
                'ai_status' => 'queued',
                'received_at' => now(),
            ]);
            $created++;
        }

        $this->info("등록 {$created} · 중복 건너뜀 {$skipped} · 미업로드 ".count($missing).' · 해시불일치 '.count($badHash));
        foreach ($missing as $n) {
            $this->line('  미업로드: '.$n);
        }
        foreach ($badHash as $n) {
            $this->line('  해시불일치: '.$n);
        }

        return count($badHash) ? self::FAILURE : self::SUCCESS;
    }
}

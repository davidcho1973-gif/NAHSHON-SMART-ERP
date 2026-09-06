<?php

namespace App\Services\Documents;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\IntelligentDocument;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 파일 하나를 문서함으로 받아들이는 규칙 — 접수 창구가 여럿이어도 규칙은 하나다.
 *
 * 문서함 업로드 화면이 유일한 창구일 때는 그 규칙이 컨트롤러 안에 있어도 됐다.
 * 이제 <b>채팅방에 올린 파일</b>도 같은 문서함으로 들어온다. 규칙을 복사하면
 * 언젠가 한쪽만 고쳐져서 "문서함으로 올리면 중복이 걸러지는데 채팅으로 올리면
 * 두 번 등록되는" 상태가 된다 — 그래서 여기 모은다.
 *
 * 이 창구가 지키는 것:
 *  - 허용 형식만 받는다.
 *  - 같은 파일(sha256)이 같은 범위(회사·현장·프로젝트)에 이미 있으면 새로 만들지 않는다.
 *  - 단, 원본이 유실된 기록은 "중복" 으로 막지 않는다 — 다시 올릴 유일한 길이기 때문이다.
 *  - 저장소가 말썽이면 로컬로라도 받아 둔다(경고를 남긴다). 접수 자체가 죽는 것보다 낫다.
 *  - 접수되면 분석을 큐에 넣는다. 그 뒤는 DocumentIntelligenceService 한 곳에서 이어진다.
 */
class DocumentIntake
{
    /**
     * @param  array{company_id?: int|null, site_id?: int|null, project_id?: int|null}  $scope
     * @param  array{uploaded_by?: int|null, source?: string}  $meta
     * @return array{status: string, document: IntelligentDocument|null, reason: string|null, requeued: bool}
     */
    public function ingest(UploadedFile $file, array $scope = [], array $meta = []): array
    {
        $originalName = $file->getClientOriginalName();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, (array) config('document-intelligence.allowed_extensions', []), true)) {
            return $this->result('failed', null, '지원하지 않는 파일 형식입니다.');
        }

        $scopes = app(DocumentScope::class);
        $scope = $scopes->normalize($scope);

        // 디스크는 미리 열지 않는다 — 설정이 잘못돼 있으면 Storage::disk() 자체가
        // 예외를 던져 접수 전체가 죽고, 화면에는 이유 없는 실패만 남는다.
        $diskName = (string) config('document-intelligence.disk', 'local');
        $safeName = $this->safeFileName($originalName);
        $sha256 = hash_file('sha256', $file->getRealPath());

        $duplicate = $scopes->findDuplicate($sha256, $scope);

        if ($duplicate) {
            return $this->handleDuplicate($duplicate, $file, $safeName, $diskName);
        }

        $uuid = (string) Str::uuid();
        [$path, $usedDisk, $error] = $this->store($file, $uuid, $safeName, $diskName);

        if ($path === null) {
            return $this->result('failed', null, $error ?: '파일 저장에 실패했습니다.');
        }

        try {
            $document = DB::transaction(fn () => IntelligentDocument::query()->create([
                'uuid' => $uuid,
                ...$scope,
                'uploaded_by' => $meta['uploaded_by'] ?? null,
                'source' => $meta['source'] ?? 'dropzone',
                'disk' => $usedDisk,
                'file_path' => $path,
                'original_file_name' => $originalName,
                'stored_file_name' => $safeName,
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                'extension' => $extension,
                'file_size' => $file->getSize() ?: 0,
                'sha256' => $sha256,
                'title' => pathinfo($originalName, PATHINFO_FILENAME),
                'received_at' => now(),
                'ai_status' => 'queued',
            ]));
        } catch (UniqueConstraintViolationException $e) {
            $duplicate = $scopes->findDuplicate($sha256, $scope);
            if (! $duplicate) {
                throw $e;
            }
            // Only this request's unreferenced temporary copy is removed. The winning
            // record and all of its original/linked files remain untouched.
            Storage::disk($usedDisk)->delete($path);

            return $this->handleDuplicate($duplicate, $file, $safeName, $diskName);
        }

        AnalyzeIntelligentDocumentJob::dispatch($document->id)->afterCommit();

        return $this->result('created', $document);
    }

    // ── 중복·유실 처리 ─────────────────────────────────────────────────

    /**
     * @return array{status: string, document: IntelligentDocument|null, reason: string|null, requeued: bool}
     */
    private function handleDuplicate(IntelligentDocument $duplicate, UploadedFile $file, string $safeName, string $diskName): array
    {
        return DB::transaction(function () use ($duplicate, $file, $safeName, $diskName): array {
            $duplicate = IntelligentDocument::query()->lockForUpdate()->findOrFail($duplicate->id);

            return $this->restoreOrReuse($duplicate, $file, $safeName, $diskName);
        });
    }

    private function restoreOrReuse(IntelligentDocument $duplicate, UploadedFile $file, string $safeName, string $diskName): array
    {
        // 원본이 유실된 기록(배포로 로컬 디스크가 초기화된 경우)은 "중복" 으로 거부하면
        // 안 된다 — 같은 파일을 다시 올릴 유일한 길을 막아 버린다.
        $lost = blank($duplicate->file_path)
            || ! $this->fileStillThere($duplicate->disk ?: $diskName, (string) $duplicate->file_path);

        if ($lost) {
            $uuid = $duplicate->uuid ?: (string) Str::uuid();
            [$path, $usedDisk, $error] = $this->store($file, $uuid, $safeName, $diskName);

            if ($path === null) {
                // 저장 실패를 "중복" 으로 뭉뚱그리면 사용자는 원인을 영영 알 수 없다.
                return $this->result('failed', null, $error ?: '파일 저장에 실패했습니다.');
            }

            $duplicate->update([
                'disk' => $usedDisk,
                'file_path' => $path,
                'stored_file_name' => $safeName,
                'ai_status' => 'queued',
                'ai_error' => null,
            ]);
            AnalyzeIntelligentDocumentJob::dispatch($duplicate->id)->afterCommit();

            return $this->result('restored', $duplicate->fresh());
        }

        // 원본은 멀쩡한 진짜 중복. 다만 예전 분석이 실패로 남아 있으면 이 기회에
        // 다시 태운다 — 사용자가 같은 파일을 또 올리는 이유는 대개 그것이다.
        // 'queued' 는 여기서 다시 태우지 않는다 — 큐에 있는 상태는 10분 리퍼
        // (StuckAnalysisReaper)의 소관이라, 여기서도 태우면 같은 문서가 두 번 분석된다.
        $requeued = false;
        if (in_array((string) $duplicate->ai_status, ['failed'], true)) {
            $duplicate->update(['ai_status' => 'queued', 'ai_error' => null]);
            AnalyzeIntelligentDocumentJob::dispatch($duplicate->id)->afterCommit();
            $requeued = true;
        }

        return $this->result(
            'duplicate',
            $duplicate,
            $requeued ? '이미 등록된 문서입니다 — 분석을 다시 시작했습니다.' : '이미 등록된 문서입니다.',
            $requeued,
        );
    }

    // ── 저장 ───────────────────────────────────────────────────────────

    /** @return array{0: string|null, 1: string, 2: string|null} */
    private function store(UploadedFile $file, string $uuid, string $safeName, string $diskName): array
    {
        $dir = "document-intelligence/inbox/{$uuid}";

        try {
            $path = Storage::disk($diskName)->putFileAs($dir, $file, $safeName);
            if ($path) {
                return [$path, $diskName, null];
            }
            $reason = "저장소('{$diskName}')가 파일을 받지 못했습니다.";
        } catch (\Throwable $e) {
            report($e);
            $reason = "저장소('{$diskName}') 오류: ".$e->getMessage();
        }

        Log::warning('문서 업로드 저장 실패 — 로컬로 대체합니다: '.$reason);

        if ($diskName !== 'local') {
            try {
                $path = Storage::disk('local')->putFileAs($dir, $file, $safeName);
                if ($path) {
                    // 올라가긴 했지만 저장소 설정이 잘못돼 있다 — 다음 배포에 사라질 수 있다.
                    return [$path, 'local', null];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [null, $diskName, $reason];
    }

    /** 디스크를 열 수 없으면 "파일 없음" 으로 본다 — 판정 하나 때문에 접수가 죽으면 안 된다. */
    private function fileStillThere(string $diskName, string $path): bool
    {
        try {
            return $path !== '' && Storage::disk($diskName)->exists($path);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public function safeFileName(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'document';

        return Str::limit($base, 120, '').($extension ? '.'.$extension : '');
    }

    /**
     * @return array{status: string, document: IntelligentDocument|null, reason: string|null, requeued: bool}
     */
    private function result(string $status, ?IntelligentDocument $document, ?string $reason = null, bool $requeued = false): array
    {
        return ['status' => $status, 'document' => $document, 'reason' => $reason, 'requeued' => $requeued];
    }
}

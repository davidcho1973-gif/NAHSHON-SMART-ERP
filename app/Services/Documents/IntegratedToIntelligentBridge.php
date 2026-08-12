<?php

namespace App\Services\Documents;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\IntegratedDocument;
use App\Models\IntelligentDocument;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 문서관리(a) → AI 문서함(b) 자동 인덱싱 다리.
 *
 * 두 문서함은 같은 문제를 푸는 평행 시스템이었고 서로를 몰랐다. 문서관리에는
 * 조달·계약·영수증·상황실 4개 모듈이 자동 편철되는데, AI 문서함의 기한·위험
 * 액션 큐는 거기 없는 문서를 못 본다 — 그래서 같은 발주서를 AI 문서함에
 * 한 번 더 올려야 액션이 잡혔다.
 *
 * 이 다리는 문서관리에 문서가 들어오는 순간(직접 업로드든 자동 편철이든)
 * AI 문서함에도 자동 등록한다. 한 번 올리면 두 화면 모두에서 보인다.
 *
 * 방향은 (a)→(b) 한쪽뿐이다 — 양방향이면 서로를 다시 등록하는 고리가 생기고,
 * 같은 파일을 두 AI 가 두 번 분석하는 낭비가 된다. AI 문서함에 직접 올린
 * 문서는 그쪽에서만 산다(기존과 동일).
 */
class IntegratedToIntelligentBridge
{
    public function index(IntegratedDocument $doc): ?IntelligentDocument
    {
        if (blank($doc->path)) {
            return null; // 파일 없는 메타 전용 등록(외부 링크 등)은 인덱싱할 게 없다.
        }

        $extension = strtolower(pathinfo((string) $doc->original_name, PATHINFO_EXTENSION)
            ?: pathinfo((string) $doc->path, PATHINFO_EXTENSION));
        if (! in_array($extension, (array) config('document-intelligence.allowed_extensions', []), true)) {
            return null; // dwg 등 AI 문서함이 다루지 않는 형식.
        }

        try {
            $sourceDisk = Storage::disk($doc->disk ?: IntegratedDocument::storageDisk());
            if (! $sourceDisk->exists($doc->path)) {
                return null;
            }
            $bytes = $sourceDisk->get($doc->path);
        } catch (\Throwable $e) {
            Log::warning('문서 인덱싱 실패(원본 읽기): '.$e->getMessage());

            return null;
        }

        $sha256 = hash('sha256', (string) $bytes);

        // 같은 내용이 이미 인덱스에 있으면 다시 만들지 않는다 — 어느 경로로 왔든.
        $existing = IntelligentDocument::query()->where('sha256', $sha256)->first();
        if ($existing) {
            return $existing;
        }

        $projectId = $doc->project_code
            ? Project::query()->where('project_code', $doc->project_code)->value('id')
            : null;

        $diskName = (string) config('document-intelligence.disk', 'local');
        $uuid = (string) Str::uuid();
        $safeName = Str::slug(pathinfo((string) $doc->original_name, PATHINFO_FILENAME), '_') ?: 'document';
        $safeName .= '.'.$extension;
        $path = "document-intelligence/inbox/{$uuid}/{$safeName}";

        try {
            Storage::disk($diskName)->put($path, $bytes);
        } catch (\Throwable $e) {
            Log::warning('문서 인덱싱 실패(사본 저장): '.$e->getMessage());

            return null;
        }

        $document = IntelligentDocument::query()->create([
            'uuid' => $uuid,
            'company_id' => $doc->company_id,
            'site_id' => $doc->site_id,
            'project_id' => $projectId,
            'source' => 'integrated',
            'external_id' => (string) $doc->id,
            'disk' => $diskName,
            'file_path' => $path,
            'original_file_name' => $doc->original_name ?: $safeName,
            'stored_file_name' => $safeName,
            'mime_type' => $doc->mime_type ?: 'application/octet-stream',
            'extension' => $extension,
            'file_size' => strlen((string) $bytes),
            'sha256' => $sha256,
            'title' => $doc->title ?: pathinfo((string) $doc->original_name, PATHINFO_FILENAME),
            'received_at' => now(),
            'ai_status' => 'queued',
        ]);

        AnalyzeIntelligentDocumentJob::dispatch($document->id);

        return $document;
    }
}

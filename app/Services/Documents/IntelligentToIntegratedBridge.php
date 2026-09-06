<?php

namespace App\Services\Documents;

use App\Models\IntegratedDocument;
use App\Models\IntelligentDocument;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AI 문서함(b) → 문서관리(a) 되돌리는 다리.
 *
 * 반대 방향 다리는 이미 있었다. 문서관리에 들어온 것은 AI 문서함에도 자동으로
 * 등록된다. 그런데 <b>반대는 아니었다</b> — AI 문서함에 직접 올린 문서는 문서관리에
 * 없었다.
 *
 * 그래서 쓰는 사람은 파일을 올릴 때마다 "어디에 올려야 하지" 를 판단해야 했다.
 * 틀리면 한쪽 화면에서 그 문서가 아예 안 보이는데, 없는 것인지 다른 데 있는 것인지
 * 구별할 방법이 없다. 혼자 관리하는 사람에게 그 판단은 하루에도 여러 번이다.
 *
 * 이 다리로 <b>어디에 올리든 두 곳에 다 있다</b>. 판단이 사라진다.
 *
 * 고리가 생기지 않는 이유 — 문서관리에서 넘어온 문서(source=integrated)는 되돌리지
 * 않는다. 그리고 반대편 다리는 같은 내용(sha256)이 이미 있으면 새로 만들지 않으므로,
 * 설령 한 바퀴를 돌아도 거기서 멈춘다.
 */
class IntelligentToIntegratedBridge
{
    /** 문서관리에서 넘어온 것. 되돌리면 왔던 자리로 다시 가는 것이라 건너뛴다. */
    public const FROM_INTEGRATED = 'integrated';

    public function file(IntelligentDocument $doc): ?IntegratedDocument
    {
        if ($doc->source === self::FROM_INTEGRATED) {
            return null;
        }

        // 이미 되돌려 둔 것이 있으면 다시 만들지 않는다. 분석이 다시 돌거나
        // 사람이 같은 파일을 또 올려도 목록에 같은 줄이 둘로 늘지 않는다.
        $already = IntegratedDocument::query()
            ->where('source_document_id', $doc->id)
            ->first();
        if ($already) {
            return $already;
        }

        if (blank($doc->file_path)) {
            return null;
        }

        try {
            $from = Storage::disk($doc->disk ?: (string) config('document-intelligence.disk', 'local'));
            if (! $from->exists($doc->file_path)) {
                return null;
            }
            $bytes = $from->get($doc->file_path);
        } catch (\Throwable $e) {
            Log::warning('문서 되돌리기 실패(원본 읽기): '.$e->getMessage());

            return null;
        }

        // 사본을 따로 둔다. 같은 파일을 가리키게 하면 한쪽에서 지웠을 때 다른 쪽이
        // "목록에는 있는데 열리지 않는" 상태가 되는데, 그게 파일이 없는 것보다 나쁘다.
        $extension = $doc->extension ?: pathinfo((string) $doc->original_file_name, PATHINFO_EXTENSION);
        $safeName = (Str::slug(pathinfo((string) $doc->original_file_name, PATHINFO_FILENAME), '_') ?: 'document')
            .($extension ? '.'.$extension : '');
        $diskName = IntegratedDocument::storageDisk();
        $path = 'integrated-documents/'.now()->format('Y/m').'/'.Str::uuid().'-'.$safeName;

        try {
            Storage::disk($diskName)->put($path, $bytes);
        } catch (\Throwable $e) {
            Log::warning('문서 되돌리기 실패(사본 저장): '.$e->getMessage());

            return null;
        }

        return IntegratedDocument::query()->create([
            'company_id' => $doc->company_id,
            'site_id' => $doc->site_id,
            'project_code' => $doc->project_id
                ? Project::query()->whereKey($doc->project_id)->value('project_code')
                : null,
            'source_document_id' => $doc->id,
            'disk' => $diskName,
            'path' => $path,
            'original_name' => $doc->original_file_name ?: $safeName,
            'mime_type' => $doc->mime_type ?: 'application/octet-stream',
            'size' => strlen((string) $bytes),
            'title' => $doc->title ?: pathinfo((string) $doc->original_file_name, PATHINFO_FILENAME),
            'uploaded_by_id' => $doc->uploaded_by,

            // 여기서 AI 를 다시 돌리지 않는다. 같은 파일을 두 번 읽어 두 벌의 답을
            // 만들면, 둘이 어긋났을 때 어느 쪽이 맞는지 가릴 방법이 없다.
            // 분석은 AI 문서함이 하고, 끝나면 그 결과가 이 줄로 내려온다.
            'status' => 'needs_review',
        ]);
    }

    /**
     * AI 문서함의 분석이 끝나면 그 결과를 문서관리 쪽 줄에도 옮겨 적는다.
     *
     * 옮겨 적지 않으면 문서관리 목록에 제목도 종류도 없는 줄이 남는다. 파일은 거기
     * 있는데 무엇인지 알 수 없어서, 결국 사람이 열어 보고 손으로 채우게 된다.
     */
    public function syncAnalysis(IntelligentDocument $doc): ?IntegratedDocument
    {
        // A preserved duplicate or unresolved scope is not an accepted analysis.
        // Publishing it would overwrite the already filed document during repair.
        if (($doc->ai_payload['duplicate_document_id'] ?? null)
            || ($doc->ai_payload['scope_review_reason'] ?? null)) {
            return null;
        }

        $mirror = IntegratedDocument::query()->where('source_document_id', $doc->id)->first();
        if (! $mirror) {
            return null;
        }

        $mirror->forceFill(array_filter([
            'title' => $doc->title,
            'document_type' => $doc->document_type,
            'document_number' => $doc->document_number,
            'issuer' => $doc->sender,
            'issued_on' => $doc->document_date,
            'effective_on' => $doc->effective_on,
            'expires_on' => $doc->expires_on,
            'body_text' => $doc->extracted_text,
            'engine' => $doc->ai_engine,
            'model' => $doc->ai_model,
            'analyzed_at' => $doc->analyzed_at,
        ], fn ($v) => $v !== null && $v !== ''))->save();

        return $mirror->fresh();
    }
}

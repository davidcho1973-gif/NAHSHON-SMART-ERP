<?php

namespace App\Console\Commands;

use App\Models\IntelligentDocument;
use App\Services\Documents\DocumentAnalysisFailure;
use App\Services\Documents\DocumentIntelligenceAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PreflightDocuments extends Command
{
    protected $signature = 'docs:preflight {--document=* : Exact document IDs} {--after-id=0} {--limit=200}';

    protected $description = 'Read-only source integrity and extraction audit; no AI calls or record changes';

    public function handle(DocumentIntelligenceAnalyzer $analyzer): int
    {
        $query = IntelligentDocument::query()->where('id', '>', (int) $this->option('after-id'));
        if ($ids = $this->option('document')) {
            $query->whereIn('id', $ids);
        }
        $total = (clone $query)->count();
        $checked = $failed = $warnings = $last = 0;
        foreach ($query->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->cursor() as $document) {
            $row = ['id' => $document->id, 'file' => $document->original_file_name, 'analysis_status' => $document->ai_status];
            $checked++;
            $last = $document->id;
            try {
                $disk = Storage::disk($document->disk ?: config('document-intelligence.disk'));
                if (! $disk->exists($document->file_path)) {
                    throw new \RuntimeException('업로드된 원본 파일을 찾을 수 없습니다.');
                }
                $bytes = $disk->get($document->file_path);
                if (! is_string($bytes)) {
                    throw new \RuntimeException('Source read failed');
                }
                if (($document->sha256 && ! hash_equals($document->sha256, hash('sha256', $bytes)))
                    || ((int) $document->file_size > 0 && strlen($bytes) !== (int) $document->file_size)) {
                    throw new \RuntimeException('[SOURCE_MISMATCH]');
                }
                $prepared = $analyzer->prepare($document, $bytes);
                $row += [
                    'ok' => true, 'bytes' => strlen($bytes),
                    'text_chars' => mb_strlen($prepared['extracted_text'] ?? '', 'UTF-8'),
                    'native_attachment' => $prepared['parts'] !== [],
                    'text_truncated' => $prepared['text_truncated'],
                ];
                if ($prepared['text_truncated']) {
                    $warnings++;
                }
                unset($prepared, $bytes);
            } catch (\Throwable $e) {
                $failed++;
                $row += ['ok' => false, 'error' => DocumentAnalysisFailure::preflightMessage($e)
                    ?: (str_starts_with($e->getMessage(), '업로드된 원본') ? '[SOURCE_MISSING]'
                        : (str_starts_with($e->getMessage(), '이 파일은 서버에서') ? '[UNREADABLE_SOURCE]' : '[PREFLIGHT_FAILED] 서버 로그 확인 필요'))];
                // No source text, credentials, provider response or SQL in audit output.
            }
            $this->line(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        $this->line(json_encode(['summary' => ['total' => $total, 'checked' => $checked, 'failed' => $failed,
            'truncated' => $warnings, 'remaining' => max(0, $total - $checked), 'last_id' => $last,
            'ai_calls' => 0, 'records_changed' => 0]]));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

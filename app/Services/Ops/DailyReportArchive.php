<?php

namespace App\Services\Ops;

use App\Models\DailyClosingReport;
use App\Models\IntegratedDocument;
use App\Models\IntelligentDocument;
use App\Models\ReportRecipient;
use App\Services\Documents\IntelligentToIntegratedBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Filing belongs to report completion, not email delivery. Both use this writer. */
class DailyReportArchive
{
    public function save(DailyClosingReport $report, string $kind, array $composed, array $recipientNames = []): IntelligentDocument
    {
        return DB::transaction(function () use ($report, $kind, $composed, $recipientNames) {
            DailyClosingReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            $date = $report->report_date->toDateString();
            $label = $kind === ReportRecipient::PLAN ? '작업계획서' : '작업보고서';
            $siteCode = $report->site?->code ?: 'ALL';

            // 같은 날 같은 종류를 두 번 보내면 문서가 두 벌 쌓인다 — 덮어쓴다.
            $number = sprintf('%s-%s-%s', $kind === ReportRecipient::PLAN ? 'DWP' : 'DCR',
                $siteCode, str_replace('-', '', $date));

            $existing = IntelligentDocument::query()->where('document_number', $number)->first();

            $uuid = $existing?->uuid ?: (string) Str::uuid();
            $name = "{$date}_{$label}_{$siteCode}.html";
            $diskName = (string) config('document-intelligence.disk');
            $path = "document-intelligence/inbox/{$uuid}/{$name}";
            if (! Storage::disk($diskName)->put($path, $composed['html'])) {
                throw new \RuntimeException('보고서 파일 저장 실패');
            }

            $attributes = [
                'uuid' => $uuid,
                'disk' => $diskName,
                'site_id' => $report->site_id,
                'uploaded_by' => $report->closed_by_id,
                'source' => 'daily_report',
                'extracted_text' => $composed['text'],
                'file_path' => $path,
                'original_file_name' => $name,
                'stored_file_name' => $name,
                'mime_type' => 'text/html',
                'extension' => 'html',
                'file_size' => strlen($composed['html']),
                'sha256' => hash('sha256', $composed['html']),
                'title' => sprintf('%s 일일 %s (%s)', $date, $label, $report->site?->name ?: '전 현장'),
                // 문서함이 이미 아는 분류를 쓴다 — 새 이름을 지어내면 서랍 밖에 떨어져
                // 목록·검색에 안 잡힌다. 일보는 '공정·일정' 서랍의 '일보' 종류다.
                'category' => 'schedule',
                'document_type' => 'daily_report',
                'direction' => 'outgoing',
                'document_number' => $number,
                'recipients' => $recipientNames,
                'summary' => Str::limit(strip_tags($composed['text']), 400),
                // 우리가 만든 문서라 AI 분류를 다시 돌릴 이유가 없다.
                'ai_status' => 'ready',
                'ai_confidence' => 100,
                'received_at' => now(),
                'analyzed_at' => now(),
                'document_date' => $date,
            ];

            $document = $existing ?: new IntelligentDocument;
            $document->fill($attributes)->save();
            $mirror = app(IntelligentToIntegratedBridge::class)->file($document);
            if (! $mirror) {
                throw new \RuntimeException('문서함 보고서 등록 실패');
            }
            // The bridge copies new files only; re-closing must refresh the copy too.
            if (! Storage::disk($mirror->disk)->put($mirror->path, $composed['html'])) {
                throw new \RuntimeException('문서함 보고서 갱신 실패');
            }
            $mirror->update([
                'folder_code' => IntegratedDocument::FOLDER_DAILY_REPORT,
                'folder_locked' => true,
                'document_type' => 'daily_report',
                'document_number' => $number,
                'title' => $attributes['title'],
                'issued_on' => $date,
                'body_text' => $composed['text'],
                'summary' => [Str::limit(strip_tags($composed['text']), 400)],
                'size' => strlen($composed['html']),
                'status' => 'confirmed',
                'type_confidence' => 100,
                'folder_confidence' => 100,
            ]);

            return $document;
        });
    }
}

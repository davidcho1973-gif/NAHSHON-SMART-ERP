<?php

namespace App\Services;

use App\Models\IntegratedDocument;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 문서통합관리 도메인 서비스.
 *
 * 공통 AI 분석기(GeminiDocumentAnalyzer)로 문서를 읽어 종류를 판별하고, 9개 폴더로 자동 분류하며,
 * 중복 문서를 감지해 저장한다. 대시보드/폴더/검색/상세 등 SPA 가 필요한 조회도 여기서 담당한다.
 */
class IntegratedDocumentService
{
    public function __construct(private readonly GeminiDocumentAnalyzer $analyzer)
    {
    }

    /**
     * 업로드된 문서를 AI 로 분석·분류·중복감지하고 저장(상태를 needs_review/confirmed 로 전환).
     * 백그라운드 잡에서 호출된다(요청 응답 후) — 실패는 status=failed 로 기록한다.
     */
    public function analyzeAndClassify(IntegratedDocument $doc): IntegratedDocument
    {
        try {
            $disk = $doc->disk ?: 'public';
            if (blank($doc->path) || ! Storage::disk($disk)->exists($doc->path)) {
                $doc->update(['status' => 'failed', 'error' => '업로드된 파일을 찾을 수 없습니다.']);

                return $doc;
            }

            $data = $this->analyzer->analyze(Storage::disk($disk)->path($doc->path), $doc->mime_type ?: null);
            $folder = IntegratedDocument::classifyFolder($data);
            $duplicate = $this->findDuplicate($doc, $data, $folder['code']);

            $doc->update([
                'document_type' => $data['document_type'],
                'type_confidence' => $this->typeConfidence($data),
                'folder_code' => $folder['code'],
                'folder_confidence' => $folder['confidence'],
                'title' => $doc->title ?: ($data['title'] ?: $doc->original_name ?: '무제 문서'),
                'document_number' => $data['document_number'],
                'issuer' => $data['issuer'],
                'counterparty' => $data['counterparty'],
                'issued_on' => $data['issued_on'],
                'effective_on' => $data['effective_on'],
                'expires_on' => $data['expires_on'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'summary' => $this->summaryLines($data['summary'] ?? null),
                'fields' => $data['fields'],
                'tags' => $this->deriveTags($data, $folder['code']),
                'duplicate_of_id' => $duplicate?->id,
                'duplicate_note' => $duplicate
                    ? sprintf('유사 문서 "%s"(#%d) 와 중복 가능성 — 검토 권장', $duplicate->title, $duplicate->id)
                    : null,
                'engine' => $data['engine'] ?? null,
                'model' => $data['model'] ?? null,
                'status' => 'needs_review',
                'error' => null,
                'analyzed_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            $doc->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }

        return $doc->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?int $siteId): array
    {
        $base = IntegratedDocument::query()->when($siteId, fn ($q) => $q->where('site_id', $siteId));

        $total = (clone $base)->count();
        $weekAgo = Carbon::now()->subDays(7);
        $thisWeek = (clone $base)->where('created_at', '>=', $weekAgo)->count();
        $pending = (clone $base)->where('status', 'needs_review')->count();
        $duplicates = (clone $base)->whereNotNull('duplicate_of_id')->count();
        $expiringSoon = (clone $base)
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [Carbon::today(), Carbon::today()->addDays(30)])
            ->count();

        // 검토 대기 — 신뢰도 낮은 순.
        $reviewQueue = (clone $base)
            ->where('status', 'needs_review')
            ->orderByRaw('LEAST(type_confidence, folder_confidence) asc')
            ->limit(6)->get()
            ->map(fn (IntegratedDocument $d) => $this->summaryRow($d))->all();

        // 폴더 분포.
        $counts = (clone $base)->selectRaw('folder_code, count(*) as c')->groupBy('folder_code')->pluck('c', 'folder_code');
        $dist = [];
        foreach (IntegratedDocument::FOLDERS as $code => $meta) {
            $c = (int) ($counts[$code] ?? 0);
            $dist[] = [
                'code' => $code,
                'name' => $meta['name'],
                'color' => $meta['color'],
                'count' => $c,
                'pct' => $total > 0 ? round($c / $total * 100) : 0,
            ];
        }

        return [
            'stats' => [
                ['key' => 'total', 'label' => '전체 문서', 'value' => $total, 'sub' => 'AI 분류·저장됨'],
                ['key' => 'week', 'label' => '이번 주 업로드', 'value' => $thisWeek, 'sub' => '최근 7일'],
                ['key' => 'pending', 'label' => '검토 대기', 'value' => $pending, 'sub' => '확정 필요'],
                ['key' => 'expiring', 'label' => '만료 임박', 'value' => $expiringSoon, 'sub' => '30일 이내'],
            ],
            'duplicates' => $duplicates,
            'reviewQueue' => $reviewQueue,
            'dist' => $dist,
            'insight' => $this->insight($base, $duplicates, $expiringSoon),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function folders(?int $siteId): array
    {
        $counts = IntegratedDocument::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw('folder_code, count(*) as c')->groupBy('folder_code')->pluck('c', 'folder_code');

        $out = [];
        foreach (IntegratedDocument::FOLDERS as $code => $meta) {
            $out[] = [
                'code' => $code,
                'name' => $meta['name'],
                'color' => $meta['color'],
                'count' => (int) ($counts[$code] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function browse(?int $siteId, string $folderCode): array
    {
        $docs = IntegratedDocument::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('folder_code', $folderCode)
            ->latest()->limit(200)->get()
            ->map(fn (IntegratedDocument $d) => $this->summaryRow($d))->all();

        return [
            'folder' => [
                'code' => $folderCode,
                'name' => IntegratedDocument::FOLDERS[$folderCode]['name'] ?? '미분류',
            ],
            'docs' => $docs,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(int $id): ?array
    {
        $d = IntegratedDocument::query()->with(['site', 'uploadedBy', 'duplicateOf'])->find($id);
        if (! $d) {
            return null;
        }

        $numbers = [];
        if ($d->amount !== null) {
            $numbers[] = ['label' => '금액', 'v' => trim(($d->currency ? $d->currency . ' ' : '') . number_format((float) $d->amount, 2))];
        }
        foreach ((array) $d->fields as $k => $v) {
            if (preg_match('/금액|한도|수량|단가|합계|amount|limit|qty|total|Ω|㎡|m2/iu', (string) $k) && count($numbers) < 4) {
                $numbers[] = ['label' => $k, 'v' => (string) $v];
            }
        }

        $fieldsList = [];
        foreach ((array) $d->fields as $k => $v) {
            $fieldsList[] = ['k' => (string) $k, 'v' => (string) $v];
        }

        return [
            'id' => $d->id,
            'title' => $d->title,
            'type' => $this->typeBadge($d->document_type),
            'docType' => IntegratedDocument::typeLabel($d->document_type),
            'typeConf' => $d->type_confidence,
            'folderCode' => $d->folder_code,
            'folderName' => $d->folderName(),
            'folderConf' => $d->folder_confidence,
            'docno' => $d->document_number ?: '—',
            'by' => $d->uploaded_by_label ?: (optional($d->uploadedBy)->name ?: '—'),
            'size' => $this->humanSize($d->size),
            'site' => optional($d->site)->name ?: ($d->project_code ?: '—'),
            'sub' => $d->folderName(),
            'summary' => (array) $d->summary,
            'fields' => $fieldsList,
            'numbers' => $numbers,
            'tags' => (array) $d->tags,
            'status' => $d->status,
            'issuer' => $d->issuer,
            'counterparty' => $d->counterparty,
            'issued_on' => optional($d->issued_on)?->toDateString(),
            'expires_on' => optional($d->expires_on)?->toDateString(),
            'fileUrl' => $d->fileUrl(),
            'dup' => $d->duplicate_of_id ? ['text' => $d->duplicate_note] : null,
            'folders' => $this->folders($d->site_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function search(?int $siteId, string $query): array
    {
        $q = trim($query);
        $builder = IntegratedDocument::query()->when($siteId, fn ($b) => $b->where('site_id', $siteId));

        if ($q !== '') {
            $like = '%' . $q . '%';
            $builder->where(function ($b) use ($like): void {
                $b->where('title', 'ilike', $like)
                    ->orWhere('document_number', 'ilike', $like)
                    ->orWhere('issuer', 'ilike', $like)
                    ->orWhere('counterparty', 'ilike', $like)
                    ->orWhere('document_type', 'ilike', $like)
                    ->orWhere('summary', 'ilike', $like)
                    ->orWhere('fields', 'ilike', $like)
                    ->orWhere('tags', 'ilike', $like);
            });
        }

        $hits = $builder->latest()->limit(50)->get()->map(function (IntegratedDocument $d): array {
            $row = $this->summaryRow($d);
            $row['from'] = $d->issuer ?: ($d->counterparty ?: '—');
            $row['docType'] = IntegratedDocument::typeLabel($d->document_type);

            return $row;
        })->all();

        return ['count' => count($hits), 'hits' => $hits];
    }

    /**
     * 분류를 확정(status=confirmed)하고, 필요하면 폴더를 사람이 바꿔 저장한다.
     *
     * @return array<string, mixed>
     */
    public function confirm(int $id, ?string $folderCode = null): array
    {
        $d = IntegratedDocument::find($id);
        if (! $d) {
            return ['success' => false, 'error' => '문서를 찾을 수 없습니다.'];
        }

        if ($folderCode !== null && isset(IntegratedDocument::FOLDERS[$folderCode]) && $folderCode !== $d->folder_code) {
            $d->folder_code = $folderCode;
            $d->folder_confidence = 100; // 사람이 확정.
        }
        $d->status = 'confirmed';
        $d->save();

        return ['success' => true, 'id' => $d->id, 'folder_code' => $d->folder_code, 'status' => $d->status];
    }

    public function deleteDocument(int $id): array
    {
        $d = IntegratedDocument::find($id);
        if (! $d) {
            return ['success' => false, 'error' => '문서를 찾을 수 없습니다.'];
        }
        $d->delete();

        return ['success' => true];
    }

    // ───────────────────────── 내부 헬퍼 ─────────────────────────

    private function findDuplicate(IntegratedDocument $doc, array $data, string $folderCode): ?IntegratedDocument
    {
        $q = IntegratedDocument::query()
            ->where('id', '!=', $doc->id)
            ->where('folder_code', $folderCode)
            ->when($doc->site_id, fn ($b) => $b->where('site_id', $doc->site_id));

        // 문서번호가 있으면 그게 가장 강한 신호.
        if (filled($data['document_number'] ?? null)) {
            $hit = (clone $q)->where('document_number', $data['document_number'])->first();
            if ($hit) {
                return $hit;
            }
        }

        // 같은 금액 + 발행처가 겹치면 중복 후보(영수증 중복 감지).
        if (($data['amount'] ?? null) !== null && filled($data['issuer'] ?? null)) {
            $hit = (clone $q)->where('amount', $data['amount'])->where('issuer', $data['issuer'])->first();
            if ($hit) {
                return $hit;
            }
        }

        return null;
    }

    private function typeConfidence(array $data): int
    {
        $type = (string) ($data['document_type'] ?? '');

        return ($type === '' || $type === 'other') ? 60 : 92;
    }

    /**
     * @return array<int, string>
     */
    private function summaryLines(mixed $summary): array
    {
        if (is_array($summary)) {
            return array_values(array_filter(array_map('strval', $summary), fn ($s) => trim($s) !== ''));
        }
        $summary = trim((string) $summary);
        if ($summary === '') {
            return [];
        }

        // 한 문단이면 문장 단위로 쪼개 불릿으로.
        $parts = preg_split('/(?<=[.。!?])\s+/u', $summary) ?: [$summary];

        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function deriveTags(array $data, string $folderCode): array
    {
        $tags = [];
        $tags[] = IntegratedDocument::FOLDERS[$folderCode]['name'] ?? '문서';
        if (filled($data['document_type'] ?? null)) {
            $tags[] = IntegratedDocument::typeLabel($data['document_type']);
        }
        if (filled($data['issuer'] ?? null)) {
            $tags[] = (string) $data['issuer'];
        }
        if (($data['expires_on'] ?? null) !== null) {
            $tags[] = '만료 ' . $data['expires_on'];
        }
        foreach (array_keys((array) ($data['fields'] ?? [])) as $k) {
            if (count($tags) >= 6) {
                break;
            }
            $tags[] = (string) $k;
        }

        return array_values(array_unique(array_filter($tags, fn ($t) => trim((string) $t) !== '')));
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryRow(IntegratedDocument $d): array
    {
        $conf = min($d->type_confidence, $d->folder_confidence);

        return [
            'id' => $d->id,
            'type' => $this->typeBadge($d->document_type),
            'tc' => $this->typeBadgeColor($d->document_type)['fg'],
            'tb' => $this->typeBadgeColor($d->document_type)['bg'],
            'title' => $d->title,
            'sub' => IntegratedDocument::typeLabel($d->document_type),
            'meta' => trim(($d->issuer ?: '') . ' · ' . (optional($d->created_at)?->format('Y-m-d') ?: ''), ' ·'),
            'by' => $d->uploaded_by_label ?: (optional($d->uploadedBy)->name ?: '—'),
            'from' => $d->issuer ?: ($d->counterparty ?: '—'),
            'date' => optional($d->created_at)?->format('Y-m-d') ?: '',
            'suggest' => $d->folderName(),
            'folderCode' => $d->folder_code,
            'conf' => $conf . '%',
            'confColor' => $conf >= 85 ? '#16a34a' : ($conf >= 70 ? '#ea580c' : '#dc2626'),
            'status' => $d->status,
            'dup' => (bool) $d->duplicate_of_id,
            'tags' => array_slice((array) $d->tags, 0, 3),
        ];
    }

    private function typeBadge(?string $type): string
    {
        return match ($type) {
            'certificate_of_insurance', 'bond', 'pay_application', 'w9', 'executed_contract',
            'amendment', 'change_order', 'scope_of_work', 'notice_to_proceed', 'correspondence',
            'lien_waiver', 'license', 'certificate', 'test_report', 'inspection', 'safety_plan', 'schedule' => 'PDF',
            'site_photo' => 'IMG',
            'receipt' => 'SCAN',
            default => 'DOC',
        };
    }

    /**
     * @return array{fg: string, bg: string}
     */
    private function typeBadgeColor(?string $type): array
    {
        return match ($this->typeBadge($type)) {
            'IMG' => ['fg' => '#0e7490', 'bg' => '#ecfeff'],
            'SCAN' => ['fg' => '#ea580c', 'bg' => '#fff7ed'],
            'DOC' => ['fg' => '#2563eb', 'bg' => '#eff6ff'],
            default => ['fg' => '#dc2626', 'bg' => '#fef2f2'],
        };
    }

    private function insight($base, int $duplicates, int $expiringSoon): string
    {
        $parts = [];
        $expiring = (clone $base)->whereNotNull('expires_on')
            ->whereBetween('expires_on', [Carbon::today(), Carbon::today()->addDays(30)])
            ->orderBy('expires_on')->first();
        if ($expiring) {
            $parts[] = sprintf('"%s" 만료 %s 예정', $expiring->title, optional($expiring->expires_on)?->toDateString());
        }
        if ($duplicates > 0) {
            $parts[] = "중복 의심 문서 {$duplicates}건 감지";
        }
        if ($parts === []) {
            return '현재 검토가 필요한 만료·중복 문서가 없습니다. 문서함이 최신 상태입니다.';
        }

        return implode(' · ', $parts);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = max(0, min($i, count($units) - 1));

        return round($bytes / (1024 ** $i), $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }

    public function resolveSiteId(mixed $site): ?int
    {
        $site = is_string($site) ? trim($site) : $site;
        if ($site === null || $site === '' || in_array(strtoupper((string) $site), ['ALL', 'GLOBAL'], true)) {
            return null;
        }
        if (is_numeric($site)) {
            return (int) $site;
        }

        return Site::query()->where('code', (string) $site)->orWhere('name', (string) $site)->value('id');
    }
}

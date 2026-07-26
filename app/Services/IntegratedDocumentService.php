<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DocumentFolder;
use App\Models\Employee;
use App\Models\IntegratedDocument;
use App\Models\MobileExpense;
use App\Models\ProcurementItem;
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

            // 어떤 디스크(로컬/오브젝트 스토리지)든 임시 로컬 파일로 받아 분석기에 넘긴다.
            // 원본 확장자를 유지해 OfficeText(docx/xlsx/pptx) 판별이 마임 외에도 동작하게 한다.
            $ext = pathinfo($doc->original_name ?: $doc->path, PATHINFO_EXTENSION);
            $tmp = tempnam(sys_get_temp_dir(), 'idoc');
            if ($ext !== '' && @rename($tmp, $tmp . '.' . $ext)) {
                $tmp .= '.' . $ext;
            }
            file_put_contents($tmp, Storage::disk($disk)->get($doc->path));

            try {
                $data = $this->analyzer->analyze($tmp, $doc->mime_type ?: null);
            } finally {
                @unlink($tmp);
            }
            $folder = IntegratedDocument::classifyFolder($data);
            // 업로드할 때 사람이 폴더를 직접 골랐으면 AI 추측으로 덮어쓰지 않는다.
            if ($doc->folder_locked && filled($doc->folder_code)) {
                $folder = ['code' => $doc->folder_code, 'confidence' => 100];
            }
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

            // 분석 결과(PO 번호·발행처·이름)를 근거로 업무 대상에 자동 연결.
            $this->autoLink($doc->fresh());
        } catch (Throwable $e) {
            report($e);
            $doc->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }

        return $doc->fresh();
    }

    /**
     * AI 로 본문을 읽을 수 없는 형식(CAD 도면 .dwg/.dxf, 구형 .ppt/.doc/.xls, HWP 등)을
     * 분석 없이 "보관 등록"한다 — 파일은 저장되고 폴더·태그로 검색되며, 폴더는 사람이 확인·조정.
     */
    public function registerWithoutAi(IntegratedDocument $doc, string $ext): IntegratedDocument
    {
        $ext = strtolower($ext);
        $type = in_array($ext, ['dwg', 'dxf'], true)
            ? 'drawing'
            : (in_array($ext, ['ppt', 'pptx'], true) ? 'presentation' : 'other');

        // 도면은 시공·공정(03)으로 편향, 그 외는 파일명 키워드로 추정.
        $folder = in_array($ext, ['dwg', 'dxf'], true)
            ? ['code' => '03', 'confidence' => 70]
            : IntegratedDocument::classifyFolder([
                'document_type' => 'other',
                'title' => $doc->title ?: $doc->original_name,
                'summary' => [], 'fields' => [],
            ]);
        // 사람이 고른 폴더가 우선.
        if ($doc->folder_locked && filled($doc->folder_code)) {
            $folder = ['code' => $doc->folder_code, 'confidence' => 100];
        }

        $doc->update([
            'document_type' => $type,
            'type_confidence' => $folder['confidence'],   // AI 미분석 — 폴더 신뢰도로 표기.
            'folder_code' => $folder['code'],
            'folder_confidence' => $folder['confidence'],
            'title' => $doc->title ?: ($doc->original_name ?: '무제 문서'),
            'summary' => ['AI 미분석 형식(' . strtoupper($ext) . ') — 파일로 보관되었습니다. 폴더가 맞는지 확인·조정 후 확정하세요.'],
            'tags' => array_values(array_unique([
                IntegratedDocument::folderMap()[$folder['code']]['name'] ?? '문서',
                IntegratedDocument::typeLabel($type),
                strtoupper($ext),
            ])),
            'status' => 'needs_review',
            'engine' => null,
            'model' => null,
            'error' => null,
            'analyzed_at' => now(),
        ]);

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
        foreach (IntegratedDocument::folderMap() as $code => $meta) {
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
        foreach (IntegratedDocument::folderMap() as $code => $meta) {
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
                'name' => IntegratedDocument::folderMap()[$folderCode]['name'] ?? '미분류',
            ],
            'docs' => $docs,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(int $id): ?array
    {
        $d = IntegratedDocument::query()
            ->with(['site', 'uploadedBy', 'duplicateOf', 'procurementItem', 'employee', 'company'])->find($id);
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
            'links' => $this->linkSummary($d),
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
            'fileUrl' => $d->fileExists() ? $d->fileUrl() : null,
            'fileMissing' => ! $d->fileExists(),
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

        if ($folderCode !== null && isset(IntegratedDocument::folderMap()[$folderCode]) && $folderCode !== $d->folder_code) {
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

    // ───────────────────────── 문서 ↔ 업무 연결 ─────────────────────────

    /**
     * AI 분석 결과를 근거로 문서를 업무 대상(발주·협력사·작업자·공정)에 자동 연결한다.
     *
     * 사람이 이미 연결해 둔 문서(link_locked)는 건드리지 않는다.
     */
    public function autoLink(IntegratedDocument $doc): IntegratedDocument
    {
        if ($doc->link_locked) {
            return $doc;
        }

        $links = [];

        // 1) 문서번호/제목의 PO 번호 → 조달 항목.
        $poNo = trim((string) $doc->document_number);
        $haystack = trim($poNo . ' ' . (string) $doc->title);
        if ($haystack !== '') {
            $po = ProcurementItem::query()
                ->whereNotNull('po_no')->where('po_no', '!=', '')
                ->get()
                ->first(fn (ProcurementItem $p) => $poNo !== ''
                    ? strcasecmp(trim((string) $p->po_no), $poNo) === 0
                    : str_contains(mb_strtolower($haystack), mb_strtolower(trim((string) $p->po_no))));
            if ($po) {
                $links['procurement_item_id'] = $po->id;
                $links['wbs_code'] = $links['wbs_code'] ?? $po->wbs_code;
            }
        }

        // 2) 발행처/상대처 이름 → 협력사(회사).
        foreach ([$doc->issuer, $doc->counterparty] as $name) {
            $name = trim((string) $name);
            if ($name === '' || isset($links['company_id'])) {
                continue;
            }
            $company = Company::query()
                ->where('name', 'ilike', $name)
                ->orWhere('name', 'ilike', '%' . $name . '%')
                ->orWhere('code', 'ilike', $name)
                ->first();
            if ($company) {
                $links['company_id'] = $company->id;
            }
        }

        // 3) 개인 서류(비자·취업허가·자격증)는 제목에서 직원 이름 매칭.
        if (in_array((string) $doc->document_type, ['visa', 'work_authorization', 'certificate', 'license'], true)) {
            $title = mb_strtolower((string) $doc->title);
            if ($title !== '') {
                $employee = Employee::query()
                    ->where('employment_status', 'active')
                    ->when($doc->site_id, fn ($q) => $q->where('site_id', $doc->site_id))
                    ->get()
                    ->first(function (Employee $e) use ($title): bool {
                        $name = trim((string) ($e->name ?: ($e->first_name . ' ' . $e->last_name)));

                        return $name !== '' && mb_strlen($name) >= 2 && str_contains($title, mb_strtolower($name));
                    });
                if ($employee) {
                    $links['employee_id'] = $employee->id;
                    $links['company_id'] = $links['company_id'] ?? $employee->company_id;
                }
            }
        }

        if ($links !== []) {
            $doc->update($links);
        }

        return $doc;
    }

    /**
     * 사람이 문서 연결을 직접 지정/해제한다(이후 자동 추정이 덮어쓰지 않도록 잠근다).
     *
     * @param  array<string, mixed>  $links
     * @return array<string, mixed>
     */
    public function linkDocument(int $id, array $links): array
    {
        $doc = IntegratedDocument::find($id);
        if (! $doc) {
            return ['success' => false, 'error' => '문서를 찾을 수 없습니다.'];
        }

        $payload = ['link_locked' => true];
        foreach (['procurement_item_id', 'employee_id', 'company_id'] as $key) {
            if (array_key_exists($key, $links)) {
                $payload[$key] = $links[$key] !== '' && $links[$key] !== null ? (int) $links[$key] : null;
            }
        }
        if (array_key_exists('wbs_code', $links)) {
            $payload['wbs_code'] = $links['wbs_code'] !== '' ? (string) $links['wbs_code'] : null;
        }
        $doc->update($payload);

        return ['success' => true, 'links' => $this->linkSummary($doc->fresh())];
    }

    /**
     * 특정 업무 대상에 붙은 문서 목록. 예: 이 발주 건 서류 일체.
     *
     * @return array<string, mixed>
     */
    public function forEntity(string $type, int|string $id): array
    {
        $map = [
            'procurement' => ['column' => 'procurement_item_id', 'label' => '발주', 'cast' => 'int'],
            'employee' => ['column' => 'employee_id', 'label' => '작업자', 'cast' => 'int'],
            'company' => ['column' => 'company_id', 'label' => '협력사', 'cast' => 'int'],
            'wbs' => ['column' => 'wbs_code', 'label' => '공정', 'cast' => 'string'],
        ];
        if (! isset($map[$type])) {
            return ['success' => false, 'error' => '알 수 없는 연결 대상입니다.'];
        }
        $spec = $map[$type];
        $label = $spec['label'];

        $docs = IntegratedDocument::query()
            ->where($spec['column'], $spec['cast'] === 'int' ? (int) $id : (string) $id)
            ->latest()->limit(100)->get()->map(fn (IntegratedDocument $d) => $this->summaryRow($d))->all();

        return ['success' => true, 'type' => $type, 'label' => $label, 'count' => count($docs), 'docs' => $docs];
    }

    /**
     * 문서에 붙은 연결을 사람이 읽는 형태로.
     *
     * @return array<int, array<string, mixed>>
     */
    public function linkSummary(IntegratedDocument $doc): array
    {
        $out = [];
        if ($doc->procurement_item_id && $po = $doc->procurementItem) {
            $out[] = ['type' => 'procurement', 'id' => $po->id, 'label' => '발주', 'name' => trim(($po->po_no ?: 'PO') . ' · ' . ($po->vendor ?: ''))];
        }
        if ($doc->company_id && $c = $doc->company) {
            $out[] = ['type' => 'company', 'id' => $c->id, 'label' => '협력사', 'name' => $c->name];
        }
        if ($doc->employee_id && $e = $doc->employee) {
            $out[] = ['type' => 'employee', 'id' => $e->id, 'label' => '작업자', 'name' => $e->name ?: trim($e->first_name . ' ' . $e->last_name)];
        }
        if (filled($doc->wbs_code)) {
            $out[] = ['type' => 'wbs', 'id' => $doc->wbs_code, 'label' => '공정', 'name' => $doc->wbs_code];
        }

        return $out;
    }

    // ───────────────────────── 스토리지 상태 ─────────────────────────

    /**
     * 문서 보관 디스크가 영구 저장소인지 점검한다.
     *
     * Laravel Cloud 의 로컬 디스크는 배포마다 초기화되므로(임시), 로컬에 보관 중이면
     * 이미 올린 문서가 다음 배포에서 사라진다. 화면·CLI 에서 이 위험을 드러낸다.
     *
     * @return array<string, mixed>
     */
    public function storageHealth(): array
    {
        $disk = IntegratedDocument::storageDisk();
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'local');
        $persistent = $driver !== 'local';
        $docCount = IntegratedDocument::query()->count();

        // 실제로 파일이 남아 있는지 표본 점검(유실이 이미 일어났는지 확인).
        $missing = 0;
        $sample = IntegratedDocument::query()->whereNotNull('path')->latest()->limit(20)->get();
        foreach ($sample as $doc) {
            try {
                if (! Storage::disk($doc->disk ?: $disk)->exists($doc->path)) {
                    $missing++;
                }
            } catch (Throwable) {
                $missing++;
            }
        }

        return [
            'success' => true,
            'disk' => $disk,
            'driver' => $driver,
            'persistent' => $persistent,
            'documents' => $docCount,
            'sampleChecked' => $sample->count(),
            'sampleMissing' => $missing,
            'level' => $persistent ? ($missing > 0 ? 'warn' : 'ok') : 'critical',
            'message' => $persistent
                ? ($missing > 0
                    ? sprintf('영구 저장소(%s)를 쓰고 있으나 최근 문서 %d건의 원본 파일을 찾을 수 없습니다.', $disk, $missing)
                    : sprintf('영구 저장소(%s)에 안전하게 보관 중입니다.', $disk))
                : '문서가 임시 로컬 디스크에 저장되고 있습니다. 배포·재시작 시 원본 파일이 사라집니다. 오브젝트 스토리지(S3) 연결이 필요합니다.',
        ];
    }

    // ───────────────────────── 사용자 폴더 ─────────────────────────

    /**
     * 폴더를 직접 만든다(기본 9개 폴더 뒤에 코드 10, 11… 순서로 붙는다).
     *
     * @return array<string, mixed>
     */
    public function createFolder(string $name, ?string $color = null, ?int $userId = null): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => '폴더 이름을 입력하세요.'];
        }
        if (mb_strlen($name) > 40) {
            return ['success' => false, 'error' => '폴더 이름은 40자 이내로 입력하세요.'];
        }
        foreach (IntegratedDocument::folderMap() as $meta) {
            if (mb_strtolower(trim($meta['name'])) === mb_strtolower($name)) {
                return ['success' => false, 'error' => '같은 이름의 폴더가 이미 있습니다.'];
            }
        }

        $color = (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) ? $color : '#64748b';
        $folder = DocumentFolder::create([
            'code' => DocumentFolder::nextCode(),
            'name' => $name,
            'color' => $color,
            'sort_order' => DocumentFolder::query()->max('sort_order') + 1,
            'created_by_id' => $userId,
        ]);
        IntegratedDocument::forgetFolderMap();

        return ['success' => true, 'folder' => ['code' => $folder->code, 'name' => $folder->name, 'color' => $folder->color, 'count' => 0]];
    }

    /**
     * 사용자 폴더 삭제. 기본 폴더(01~09)는 지울 수 없고, 문서가 남아 있으면 막는다.
     *
     * @return array<string, mixed>
     */
    public function deleteFolder(string $code): array
    {
        if (isset(IntegratedDocument::FOLDERS[$code])) {
            return ['success' => false, 'error' => '기본 폴더는 삭제할 수 없습니다.'];
        }
        $folder = DocumentFolder::query()->where('code', $code)->first();
        if (! $folder) {
            return ['success' => false, 'error' => '폴더를 찾을 수 없습니다.'];
        }
        $count = IntegratedDocument::query()->where('folder_code', $code)->count();
        if ($count > 0) {
            return ['success' => false, 'error' => sprintf('이 폴더에 문서 %d건이 있습니다. 먼저 옮기거나 삭제하세요.', $count)];
        }
        $folder->delete();
        IntegratedDocument::forgetFolderMap();

        return ['success' => true];
    }

    // ───────────────────────── 영수증 자동 편철 ─────────────────────────

    /**
     * 재무관리에서 등록된 영수증을 문서함(자재·구매 폴더)에 자동으로 편철한다.
     *
     * 원본 파일은 문서함 전용으로 복사한다 — 문서를 지워도 지출결의 영수증이 사라지지 않게.
     */
    public function fileReceipt(MobileExpense $expense): ?IntegratedDocument
    {
        // 이미 편철된 영수증이면 중복 생성하지 않는다.
        $already = IntegratedDocument::query()
            ->where('document_type', 'receipt')
            ->whereJsonContains('fields->mobile_expense_id', $expense->id)
            ->first();
        if ($already) {
            return $already;
        }

        $source = $this->receiptSourcePath($expense);
        if ($source === null) {
            return null;
        }

        $disk = IntegratedDocument::storageDisk();
        $ext = pathinfo($source['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $path = 'integrated-documents/receipt-' . $expense->id . '-' . substr(md5($source['name'] . $expense->id), 0, 8) . '.' . $ext;
        Storage::disk($disk)->put($path, $source['bytes']);

        $vendor = trim((string) (is_array($expense->ocr_data) ? ($expense->ocr_data['vendor_name'] ?? '') : ''));
        $title = '영수증 · ' . ($vendor !== '' ? $vendor : ($expense->description ?: '지출'));
        $amount = (float) $expense->amount;

        $doc = IntegratedDocument::create([
            'site_id' => $expense->site_id,
            'folder_code' => IntegratedDocument::FOLDER_MATERIAL_PURCHASE,
            'folder_confidence' => 100,
            'folder_locked' => true,           // 재무에서 온 영수증은 자재·구매 폴더에 고정.
            'document_type' => 'receipt',
            'type_confidence' => 100,
            'title' => mb_substr($title, 0, 255),
            'issuer' => $vendor !== '' ? $vendor : null,
            'issued_on' => $expense->expense_date,
            'amount' => $amount ?: null,
            'currency' => 'USD',
            'summary' => array_values(array_filter([
                $expense->description ? mb_substr((string) $expense->description, 0, 300) : null,
                $amount ? ('금액 $' . number_format($amount, 2)) : null,
                '재무관리 영수증 등록 시 자동 편철됨',
            ])),
            'fields' => ['mobile_expense_id' => $expense->id, 'accounting_account' => $expense->accounting_account],
            'tags' => array_values(array_unique(array_filter([
                IntegratedDocument::folderMap()[IntegratedDocument::FOLDER_MATERIAL_PURCHASE]['name'] ?? '자재·구매',
                '영수증',
                $vendor !== '' ? $vendor : null,
            ]))),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $source['name'],
            'mime_type' => $source['mime'],
            'size' => strlen($source['bytes']),
            'status' => 'needs_review',
            'uploaded_by_id' => $expense->employee?->user?->id,
            'uploaded_by_label' => $expense->employee?->name,
            'analyzed_at' => now(),
        ]);

        return $doc;
    }

    /**
     * 영수증 원본 바이트를 찾아온다(DB 저장본 우선, 없으면 public 디스크 경로).
     *
     * @return array{bytes: string, name: string, mime: string}|null
     */
    private function receiptSourcePath(MobileExpense $expense): ?array
    {
        $name = $expense->receipt_original_name ?: ('receipt-' . $expense->id . '.jpg');
        $mime = $expense->receipt_mime_type ?: 'image/jpeg';

        if (filled($expense->receipt_file)) {
            try {
                $bytes = \App\Support\ReceiptFilePayload::decode($expense->receipt_file);
                if (is_string($bytes) && $bytes !== '') {
                    return ['bytes' => $bytes, 'name' => $name, 'mime' => $mime];
                }
            } catch (Throwable) {
                // 디코드 실패 시 파일 경로로 폴백.
            }
        }

        $path = ltrim((string) $expense->receipt_path, '/');
        $path = str_starts_with($path, 'storage/') ? substr($path, strlen('storage/')) : $path;
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            return ['bytes' => Storage::disk('public')->get($path), 'name' => $name, 'mime' => $mime];
        }

        return null;
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
        $tags[] = IntegratedDocument::folderMap()[$folderCode]['name'] ?? '문서';
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
            'drawing' => 'CAD',
            'presentation' => 'PPT',
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
            'CAD' => ['fg' => '#7c3aed', 'bg' => '#f5f3ff'],
            'PPT' => ['fg' => '#c2410c', 'bg' => '#fff7ed'],
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

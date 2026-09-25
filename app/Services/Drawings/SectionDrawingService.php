<?php

namespace App\Services\Drawings;

use App\Models\DrawingSheet;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkSection;
use App\Models\WorkSectionSheet;
use App\Support\AccessPolicy;
use App\Support\AiInformationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 공정별 도면 — 계약서의 공정마다 «이 일을 그 위에 그리고 적을 도면» 을 고른다.
 *
 * ── 무엇을 어디에 두는가 ───────────────────────────────────────────────
 *  - 도면 파일: 문서함(IntelligentDocument). 여기서 또 받지 않는다.
 *  - 쪽마다의 도면 번호·글자: drawing_sheets. 사진으로 된 쪽은 AI 가 읽어 붙인다.
 *  - 공정 목록: work_sections — 원청 계약 기성표의 섹션.
 *  - 선택: work_section_sheets — 도면 <b>번호</b>로 잇는다. 개정판이 와도 선택이 산다.
 */
class SectionDrawingService
{
    /** 도면 파일로 보는 문서 — 문서함이 도면으로 분류했거나, 이미 쪽을 읽기 시작한 PDF. */
    public const DRAWING_CATEGORY = 'drawing_spec';

    public const DRAWING_TYPE = 'drawing';

    /** 이 시간보다 오래 «읽는 중» 이면 멈춘 것으로 본다 — 화면이 다시 읽기를 권한다. */
    public const STALE_MINUTES = 10;

    /**
     * @return array<string, mixed>
     */
    public function board(string $siteId = 'ALL'): array
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $this->canView($user)) {
            return ['success' => false, 'error' => '도면을 볼 권한이 없습니다.'];
        }

        $sites = $this->sites($user);
        $site = $this->site($user, $siteId, $sites);
        if ($site === null) {
            return ['success' => true, 'noSite' => true, 'sites' => $this->siteOptions($sites), 'canManage' => $this->canManage($user)];
        }

        $sheets = DrawingSheet::query()
            ->where('site_id', $site->id)
            ->with('document:id,title,original_file_name,created_at')
            ->orderBy('intelligent_document_id')->orderBy('page_no')
            ->get();

        // 같은 번호가 여러 파일에 있으면(개정판) 가장 늦게 올라온 파일의 것을 쓴다.
        $byNo = $sheets->filter(fn (DrawingSheet $s): bool => $s->sheet_no !== null)
            ->sortByDesc(fn (DrawingSheet $s) => [$s->document?->created_at?->timestamp ?? 0, $s->id])
            ->groupBy(fn (DrawingSheet $s): string => (string) DrawingSheet::normalizeNo($s->sheet_no))
            ->map(fn (Collection $g): DrawingSheet => $g->first());

        $sections = WorkSection::query()->where('site_id', $site->id)
            ->with('sheets')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (WorkSection $sec): array => [
                'id' => $sec->id,
                'division' => $sec->division,
                'code' => $sec->code,
                'name' => $sec->name,
                'contractAmount' => $sec->contract_amount,
                'sheets' => $sec->sheets->map(function (WorkSectionSheet $pick) use ($byNo): array {
                    $sheet = $byNo->get((string) DrawingSheet::normalizeNo($pick->sheet_no));

                    return ['sheetNo' => $pick->sheet_no, 'found' => $sheet !== null, 'sheet' => $sheet ? $this->sheetRow($sheet) : null];
                })->all(),
            ])->all();

        return [
            'success' => true,
            'noSite' => false,
            'siteId' => $site->id,
            'site' => trim($site->code.' — '.$site->name, ' —'),
            'sites' => $this->siteOptions($sites),
            'sections' => $sections,
            'documents' => $this->documents($user, $site, $sheets),
            'otherPdfs' => $this->otherPdfs($user, $site, $sheets),
            'catalog' => $sheets->map(fn (DrawingSheet $s): array => $this->sheetRow($s))->values()->all(),
            'canManage' => $this->canManage($user),
        ];
    }

    /**
     * 한 공정이 쓰는 도면을 통째로 바꾼다 — 화면의 체크 목록이 곧 선택이다.
     *
     * @param  array<int, mixed>  $sheetNos
     * @return array<string, mixed>
     */
    public function setSheets(int $sectionId, array $sheetNos): array
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $this->canManage($user)) {
            return ['success' => false, 'error' => '도면을 고를 권한이 없습니다.'];
        }

        $section = WorkSection::query()->find($sectionId);
        if ($section === null || ! $this->canUseSiteId($user, $section->site_id)) {
            return ['success' => false, 'error' => '공정을 찾을 수 없습니다.'];
        }

        $wanted = collect($sheetNos)->map(fn ($n) => DrawingSheet::normalizeNo(is_scalar($n) ? (string) $n : null))
            ->filter()->unique()->values();

        DB::transaction(function () use ($section, $wanted, $user): void {
            WorkSectionSheet::query()->where('work_section_id', $section->id)
                ->whereNotIn(DB::raw('upper(sheet_no)'), $wanted->all() ?: ['__none__'])->delete();
            $have = WorkSectionSheet::query()->where('work_section_id', $section->id)
                ->pluck('sheet_no')->map(fn ($n) => DrawingSheet::normalizeNo($n))->all();
            foreach ($wanted as $i => $no) {
                if (in_array($no, $have, true)) {
                    WorkSectionSheet::query()->where('work_section_id', $section->id)
                        ->whereRaw('upper(sheet_no) = ?', [$no])->update(['sort_order' => $i + 1]);

                    continue;
                }
                WorkSectionSheet::query()->create([
                    'work_section_id' => $section->id, 'sheet_no' => $no, 'sort_order' => $i + 1, 'created_by_id' => $user->id,
                ]);
            }
        });

        return ['success' => true, 'id' => $section->id, 'count' => $wanted->count()];
    }

    /**
     * 공정 하나를 적거나 고친다 — 계약서가 올라오기 전의 다른 현장, 또는 RFI 로 늘어난 공정.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveSection(array $input, string $siteId = 'ALL'): array
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $this->canManage($user)) {
            return ['success' => false, 'error' => '공정을 적을 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $section = $id > 0 ? WorkSection::query()->find($id) : new WorkSection;
        if ($section === null || ($section->exists && ! $this->canUseSiteId($user, $section->site_id))) {
            return ['success' => false, 'error' => '공정을 찾을 수 없습니다.'];
        }

        $site = $section->exists ? Site::query()->find($section->site_id) : $this->site($user, (string) ($input['siteId'] ?? $siteId), $this->sites($user));
        if ($site === null) {
            return ['success' => false, 'error' => '현장을 먼저 고르세요.'];
        }

        $name = trim((string) ($input['name'] ?? ''));
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $errors = [];
        if ($name === '') {
            $errors['name'] = '공정 이름을 적으세요.';
        }
        if ($code === '') {
            $errors['code'] = '부호를 적으세요. 예) A3, M1-2, E01';
        } elseif (WorkSection::query()->where('site_id', $site->id)->where('code', $code)->whereKeyNot($section->id ?? 0)->exists()) {
            $errors['code'] = '같은 부호가 이미 있습니다.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $section->fill([
            'site_id' => $site->id,
            'company_id' => $site->company_id,
            'division' => mb_substr(trim((string) ($input['division'] ?? '')) ?: '기타', 0, 60),
            'code' => mb_substr($code, 0, 20),
            'name' => mb_substr($name, 0, 160),
            'contract_amount' => is_numeric($input['contractAmount'] ?? null) ? (float) $input['contractAmount'] : $section->contract_amount,
            'sort_order' => $section->exists ? $section->sort_order
                : (int) WorkSection::query()->where('site_id', $site->id)->max('sort_order') + 1,
        ])->save();

        return ['success' => true, 'id' => $section->id];
    }

    /**
     * AI 가 잘못 읽은 도면 번호·제목을 사람이 고친다. 고친 장은 다시 읽어도 덮지 않는다.
     *
     * @return array<string, mixed>
     */
    public function updateSheet(int $sheetId, ?string $sheetNo, ?string $title): array
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $this->canManage($user)) {
            return ['success' => false, 'error' => '도면 정보를 고칠 권한이 없습니다.'];
        }

        $sheet = DrawingSheet::query()->find($sheetId);
        if ($sheet === null || ! $this->canUseSiteId($user, $sheet->site_id)) {
            return ['success' => false, 'error' => '도면을 찾을 수 없습니다.'];
        }

        $sheet->forceFill([
            'sheet_no' => DrawingSheet::normalizeNo($sheetNo),
            'title' => trim((string) $title) !== '' ? mb_substr(trim((string) $title), 0, 255) : $sheet->title,
            'manual' => true,
        ])->save();

        return ['success' => true, 'sheet' => $this->sheetRow($sheet)];
    }

    // ── 문서·권한 ─────────────────────────────────────────────────────

    /** 사람이 볼 수 있는 문서함 문서 중 이 현장의 것. */
    public function documentQuery(User $user, Site $site): Builder
    {
        return IntelligentDocument::query()->visibleTo($user)->where('site_id', $site->id)
            ->where(fn (Builder $q) => $q->whereRaw('lower(extension) = ?', ['pdf'])->orWhere('mime_type', 'application/pdf'));
    }

    public function findDocument(User $user, int $documentId): ?IntelligentDocument
    {
        $doc = IntelligentDocument::query()->visibleTo($user)->whereKey($documentId)->first();
        if ($doc === null || $doc->site_id === null) {
            return null;
        }
        $site = Site::query()->find($doc->site_id);

        return $site && AiInformationAccess::canUseSite($user, $site) ? $doc : null;
    }

    public function canView(?User $user): bool
    {
        return $user !== null && $user->account_status === 'active'
            && in_array($user->access_role, ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'payroll'], true);
    }

    public function canManage(?User $user): bool
    {
        return AccessPolicy::canManageSite($user);
    }

    /** @return array<string, mixed> */
    public function sheetRow(DrawingSheet $s): array
    {
        $stale = $s->status === DrawingSheet::STATUS_READING
            && $s->updated_at !== null && $s->updated_at->lt(now()->subMinutes(self::STALE_MINUTES));

        return [
            'id' => $s->id,
            'documentId' => $s->intelligent_document_id,
            'file' => $s->document?->title ?: $s->document?->original_file_name,
            'pageNo' => $s->page_no,
            'sheetNo' => $s->sheet_no,
            'title' => $s->title,
            'discipline' => $s->discipline,
            'manual' => $s->manual,
            'textSource' => $s->text_source,
            'textLength' => mb_strlen((string) $s->text),
            'status' => $stale ? DrawingSheet::STATUS_FAILED : $s->status,
            'error' => $stale ? '읽다가 멈췄습니다. 다시 읽기를 누르세요.' : $s->error,
            'thumbUrl' => $s->thumb_path ? route('drawing-sheets.thumb', ['sheet' => $s->id]).'?v='.($s->updated_at?->timestamp ?? 0) : null,
            'widthPt' => $s->width_pt,
            'heightPt' => $s->height_pt,
        ];
    }

    /**
     * @param  Collection<int, DrawingSheet>  $sheets
     * @return array<int, array<string, mixed>>
     */
    private function documents(User $user, Site $site, Collection $sheets): array
    {
        $read = $sheets->groupBy('intelligent_document_id');

        return $this->documentQuery($user, $site)
            ->where(function (Builder $q) use ($read): void {
                $q->where('category', self::DRAWING_CATEGORY)->orWhere('document_type', self::DRAWING_TYPE)
                    ->orWhereIn('id', $read->keys()->all() ?: [0]);
            })
            ->orderBy('title')->get(['id', 'title', 'original_file_name', 'file_size', 'created_at'])
            ->map(function (IntelligentDocument $d) use ($read): array {
                $rows = $read->get($d->id, collect())->map(fn (DrawingSheet $s): array => $this->sheetRow($s));

                return [
                    'id' => $d->id,
                    'title' => $d->title ?: $d->original_file_name,
                    'fileSize' => $d->file_size,
                    'pdfUrl' => route('document-intelligence.preview', ['document' => $d->id]),
                    'pages' => $rows->count(),
                    'done' => $rows->where('status', DrawingSheet::STATUS_DONE)->count(),
                    'failed' => $rows->where('status', DrawingSheet::STATUS_FAILED)->count(),
                    'ocr' => $rows->where('textSource', 'ocr')->count(),
                    'sheets' => $rows->values()->all(),
                ];
            })->all();
    }

    /**
     * 도면으로 분류되지 않은 PDF — AI 가 도면을 다른 종류로 잘못 분류했을 때 사람이 골라 쓰게.
     *
     * @param  Collection<int, DrawingSheet>  $sheets
     * @return array<int, array<string, mixed>>
     */
    private function otherPdfs(User $user, Site $site, Collection $sheets): array
    {
        return $this->documentQuery($user, $site)
            ->where(fn (Builder $q) => $q->whereNull('category')->orWhere('category', '!=', self::DRAWING_CATEGORY))
            ->where(fn (Builder $q) => $q->whereNull('document_type')->orWhere('document_type', '!=', self::DRAWING_TYPE))
            ->whereNotIn('id', $sheets->pluck('intelligent_document_id')->unique()->all() ?: [0])
            ->orderByDesc('created_at')->limit(50)
            ->get(['id', 'title', 'original_file_name'])
            ->map(fn (IntelligentDocument $d): array => ['id' => $d->id, 'title' => $d->title ?: $d->original_file_name,
                'pdfUrl' => route('document-intelligence.preview', ['document' => $d->id])])
            ->all();
    }

    /** @return Collection<int, Site> */
    private function sites(User $user): Collection
    {
        return Site::query()->where('status', 'active')->orderBy('name')->get()
            ->filter(fn (Site $s): bool => AiInformationAccess::canUseSite($user, $s))->values();
    }

    /** @param Collection<int, Site> $sites */
    private function site(User $user, string $siteId, Collection $sites): ?Site
    {
        $siteId = trim($siteId);
        $wanted = null;
        if ($siteId !== '' && ! in_array(strtoupper($siteId), ['ALL', 'GLOBAL'], true)) {
            $wanted = $sites->first(fn (Site $s): bool => is_numeric($siteId) ? $s->id === (int) $siteId : strtoupper($s->code) === strtoupper($siteId));
        }
        $wanted ??= ($own = AiInformationAccess::siteId($user)) ? $sites->firstWhere('id', $own) : null;

        return $wanted ?? ($sites->count() === 1 ? $sites->first() : null);
    }

    private function canUseSiteId(User $user, ?int $siteId): bool
    {
        $site = $siteId ? Site::query()->find($siteId) : null;

        return $site !== null && AiInformationAccess::canUseSite($user, $site);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<int, array{value: int, label: string}>
     */
    private function siteOptions(Collection $sites): array
    {
        return $sites->map(fn (Site $s): array => ['value' => $s->id, 'label' => trim($s->code.' — '.$s->name, ' —')])->values()->all();
    }
}

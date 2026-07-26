<?php

namespace App\Services\Ops;

use App\Models\OpsIntakeItem;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\WbsItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 현장 상황실 — 올라온 글을 판독해 "반영 제안"으로 바꾼다.
 *
 * 1단계(현재): 판독 + 제안 생성/조회/무시.
 * 2단계(예정): 이 제안을 실제 공정표·조달에 적용.
 */
class OpsIntakeService
{
    /** 이 값 미만이면 사람에게 되물어야 하는 제안으로 본다. */
    public const LOW_CONFIDENCE = 60;

    private const CATEGORIES = ['progress', 'plan', 'procurement', 'labor', 'expense', 'issue', 'noise'];

    public function __construct(private readonly OpsIntakeAnalyzer $analyzer)
    {
    }

    /**
     * 자유 형식 텍스트(카톡 붙여넣기 포함)를 판독해 제안을 저장한다.
     *
     * @param  array<int, array{data: string, mime_type: string}>  $images
     * @return array<string, mixed>
     */
    public function ingest(string $text, ?Site $site, ?int $userId = null, array $images = [], string $source = 'paste', ?int $messageId = null): array
    {
        $text = trim($text);
        if ($text === '' && $images === []) {
            return ['success' => false, 'error' => '판독할 내용이 없습니다.'];
        }

        $activities = $this->activityContext($site);
        $purchases = $this->purchaseContext($site);
        $today = Carbon::today()->toDateString();

        try {
            $raw = $this->analyzer->read($text, $activities->all(), $purchases->all(), $today, $images);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'AI 판독에 실패했습니다: ' . $e->getMessage()];
        }

        $validCodes = $activities->pluck('code')->filter()->all();
        $validPos = $purchases->pluck('po')->filter()->all();

        $saved = [];
        foreach ($raw as $r) {
            $item = $this->persist($r, $site, $userId, $source, $messageId, $validCodes, $validPos, $text);
            if ($item !== null) {
                $saved[] = $item;
            }
        }

        $items = collect($saved);

        return [
            'success' => true,
            'parsed' => $items->count(),
            'actionable' => $items->where('category', '!=', 'noise')->count(),
            'noise' => $items->where('category', 'noise')->count(),
            'needsInput' => $items->where('status', 'needs_input')->count(),
            'items' => $items->map(fn (OpsIntakeItem $i) => $this->row($i))->all(),
        ];
    }

    /**
     * 확인 대기 중인 제안 목록(잡담 제외).
     *
     * @return array<string, mixed>
     */
    public function pending(?int $siteId = null, int $limit = 100): array
    {
        $rows = OpsIntakeItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereIn('status', ['pending', 'needs_input'])
            ->where('category', '!=', 'noise')
            ->latest()->limit($limit)->get();

        return [
            'success' => true,
            'count' => $rows->count(),
            'items' => $rows->map(fn (OpsIntakeItem $i) => $this->row($i))->all(),
        ];
    }

    /**
     * 제안을 무시 처리한다(잘못 읽었거나 반영할 필요 없음).
     *
     * @return array<string, mixed>
     */
    public function dismiss(int $id, ?string $note = null): array
    {
        $item = OpsIntakeItem::find($id);
        if (! $item) {
            return ['success' => false, 'error' => '항목을 찾을 수 없습니다.'];
        }
        $item->update(['status' => 'dismissed', 'result_note' => $note]);

        return ['success' => true];
    }

    // ───────────────────────── 내부 ─────────────────────────

    /**
     * AI 결과 1건을 검증해 저장한다. 지어낸 대상 코드는 버리고 되물음으로 돌린다.
     *
     * @param  array<string, mixed>  $r
     * @param  array<int, string>  $validCodes
     * @param  array<int, string>  $validPos
     */
    private function persist(array $r, ?Site $site, ?int $userId, string $source, ?int $messageId, array $validCodes, array $validPos, string $fallbackText): ?OpsIntakeItem
    {
        $category = (string) ($r['category'] ?? 'noise');
        if (! in_array($category, self::CATEGORIES, true)) {
            $category = 'noise';
        }

        $targetType = (string) ($r['target_type'] ?? '');
        $targetCode = trim((string) ($r['target_code'] ?? ''));

        // AI 가 목록에 없는 코드를 지어냈으면 버린다(잘못된 대상에 반영되는 사고 방지).
        $hallucinated = false;
        if ($targetCode !== '') {
            $known = $targetType === 'procurement'
                ? in_array($targetCode, $validPos, true)
                : in_array($targetCode, $validCodes, true);
            if (! $known) {
                $hallucinated = true;
                $targetCode = '';
                $targetType = '';
            }
        }

        $confidence = (int) ($r['confidence'] ?? 0);
        $confidence = max(0, min(100, $confidence));

        $proposed = is_array($r['proposed'] ?? null) ? $r['proposed'] : [];
        $question = trim((string) ($r['question'] ?? ''));
        if ($hallucinated && $question === '') {
            $question = '어느 작업/발주 건인지 확인해 주세요. (AI 가 대상을 특정하지 못했습니다)';
        }

        // 잡담은 기록만 하고 목록에서 빠진다. 그 외는 확신도·대상 유무로 상태를 정한다.
        $status = 'pending';
        if ($category === 'noise') {
            $status = 'ignored';
        } elseif ($question !== '' || $confidence < self::LOW_CONFIDENCE || ($targetCode === '' && $proposed !== [])) {
            $status = 'needs_input';
        }

        $occurred = trim((string) ($r['occurred_on'] ?? ''));

        return OpsIntakeItem::create([
            'site_id' => $site?->id,
            'source' => $source,
            'communication_message_id' => $messageId,
            'created_by_id' => $userId,
            'raw_text' => mb_substr(trim((string) ($r['raw_text'] ?? '')) ?: $fallbackText, 0, 4000),
            'speaker' => mb_substr(trim((string) ($r['speaker'] ?? '')), 0, 80) ?: null,
            'occurred_on' => $this->safeDate($occurred),
            'category' => $category,
            'confidence' => $confidence,
            'summary' => mb_substr(trim((string) ($r['summary'] ?? '')), 0, 300) ?: null,
            'target_type' => $targetType ?: null,
            'target_code' => $targetCode ?: null,
            'target_name' => mb_substr(trim((string) ($r['target_name'] ?? '')), 0, 200) ?: null,
            'proposed' => $proposed ?: null,
            'question' => $question ?: null,
            'status' => $status,
        ]);
    }

    private function safeDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * AI 에게 넘길 공정 후보 — 이름으로 대조해 코드를 찾게 한다.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function activityContext(?Site $site): Collection
    {
        return WbsItem::query()
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->when($site, fn ($q) => $q->where('site_id', $site->id))
            ->where('status', '!=', WbsItem::STATUS_DONE)
            ->orderByRaw('planned_start is null, planned_start')
            ->limit(200)->get()
            ->map(fn (WbsItem $w) => [
                'code' => (string) $w->wbs_code,
                'name' => (string) $w->name,
                'status' => (string) $w->status,
                'start' => $w->planned_start?->toDateString() ?? '',
                'end' => $w->planned_end?->toDateString() ?? '',
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseContext(?Site $site): Collection
    {
        return ProcurementItem::query()
            ->when($site, fn ($q) => $q->where('site_id', $site->id))
            ->whereNotNull('po_no')->where('po_no', '!=', '')
            ->latest()->limit(100)->get()
            ->map(fn (ProcurementItem $p) => [
                'po' => (string) $p->po_no,
                'vendor' => (string) $p->vendor,
                'eta' => $p->eta?->toDateString() ?? '',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(OpsIntakeItem $i): array
    {
        return [
            'id' => $i->id,
            'category' => $i->category,
            'categoryLabel' => $i->categoryLabel(),
            'confidence' => $i->confidence,
            'summary' => $i->summary,
            'raw' => $i->raw_text,
            'speaker' => $i->speaker,
            'occurredOn' => $i->occurred_on?->toDateString(),
            'targetType' => $i->target_type,
            'targetCode' => $i->target_code,
            'targetName' => $i->target_name,
            'proposed' => $i->proposed ?: [],
            'question' => $i->question,
            'status' => $i->status,
        ];
    }
}

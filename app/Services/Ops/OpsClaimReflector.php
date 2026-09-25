<?php

namespace App\Services\Ops;

use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\User;
use App\Services\Admin\BillingAdminService;
use App\Services\Finance\ClaimEvidenceService;
use App\Services\Ocr\OcrEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 상황실 글·사진 → 설치 기성 기록. 사장 지시(2026-09-25): «상황실 글·사진으로 설치 기록도 자동으로».
 *
 * ── 흐름 ──────────────────────────────────────────────────────────────
 * 상황실은 이미 글을 읽어 «제안(OpsIntakeItem)» 을 만들고, 사람이 «반영» 하거나 자동으로 반영한다.
 * 설치 기록도 같은 길이다 — 다른 길을 만들지 않는다.
 *  1. 글(과 사진 판독 요약)이 계약서의 어느 줄을 몇 만큼 설치했다고 말하는지 AI 가 읽는다.
 *     수량이 글에 있거나 «그 줄 전부 끝» 처럼 분명할 때만. 확신 0.7 미만은 버린다.
 *  2. 줄마다 «설치 기성» 제안을 만든다(category = installation).
 *  3. 글쓴이가 기성 기록 권한이 있으면 바로 반영한다 — 확인 대기 기록이 되고, 빠진 반입도 함께
 *     적힌다(ClaimEvidenceService::recordInstallation 한 곳의 규칙). 반장 글은 제안으로 남아
 *     상황실에서 관리자가 «반영» 을 누른다.
 * 어느 쪽이든 기록은 «확인 대기» 다 — 담당자가 사진을 보고 확인해야 기성이 된다.
 */
class OpsClaimReflector
{
    public const CATEGORY = 'installation';

    private const MIN_CONFIDENCE = 0.7;

    public function __construct(
        private readonly OcrEngine $ai,
        private readonly BillingAdminService $billing,
    ) {}

    /**
     * @return array{proposed: int, applied: int}
     */
    public function reflect(OpsIntakeBatch $batch): array
    {
        if (! $batch->site_id || OpsIntakeItem::where('ops_intake_batch_id', $batch->id)->where('category', self::CATEGORY)->exists()) {
            return ['proposed' => 0, 'applied' => 0];   // 현장 없는 글이거나 이미 읽었다
        }
        $text = $this->text($batch);
        $lines = $this->lines($batch->site_id);
        if ($text === '' || $lines->isEmpty()) {
            return ['proposed' => 0, 'applied' => 0];
        }
        try {
            $result = $this->ai->analyze([], $this->prompt($text, $lines, $batch), $this->schema());
        } catch (\Throwable $e) {
            report($e);
            Log::warning('상황실 → 설치 기성 판독 실패(batch '.$batch->id.'): '.$e->getMessage());

            return ['proposed' => 0, 'applied' => 0];
        }

        $items = [];
        foreach ((array) data_get($result, 'data.installations', []) as $row) {
            $line = $lines->get((int) ($row['contract_line_id'] ?? 0));
            $qty = round((float) ($row['quantity'] ?? 0), 4);
            if (! $line || $qty <= 0 || (float) ($row['confidence'] ?? 0) < self::MIN_CONFIDENCE) {
                continue;
            }
            $left = round((float) $line->contract_qty - $this->installed($line), 4);
            if ($left <= 0) {
                continue;   // 이미 계약 수량만큼 설치 기록이 있다
            }
            $capped = min($qty, $left);
            $where = mb_substr(trim((string) ($row['location'] ?? '')) ?: '상황실 보고', 0, 200);
            $quote = mb_substr(trim((string) ($row['quote'] ?? '')), 0, 300);
            $items[] = OpsIntakeItem::create([
                'site_id' => $batch->site_id, 'ops_intake_batch_id' => $batch->id, 'source' => $batch->source,
                'communication_message_id' => $batch->communication_message_id, 'created_by_id' => $batch->created_by_id,
                'raw_text' => $batch->raw_text, 'occurred_on' => $batch->created_at?->toDateString(),
                'category' => self::CATEGORY, 'confidence' => (int) round((float) $row['confidence'] * 100),
                'summary' => '#'.$line->line_no.' '.$line->description.' 설치 '.$capped.' '.$line->unit.' · '.$where,
                'target_type' => 'boq_line', 'target_code' => $line->line_no, 'target_name' => mb_substr($line->description, 0, 255),
                'proposed' => ['lineId' => $line->id, 'qty' => $capped, 'location' => $where, 'quote' => $quote,
                    'note' => $capped < $qty ? '계약 수량까지만(넘는 '.round($qty - $capped, 4).' 은 RFI 필요)' : null],
                'status' => 'pending',
            ]);
        }

        // 글쓴이가 기성 기록 권한이 있으면 바로 반영 — 반장 글은 상황실에서 관리자가 반영한다.
        $applied = 0;
        $author = $batch->created_by_id ? User::find($batch->created_by_id) : null;
        if ($items !== [] && $author && $this->billing->canManage($author)) {
            $previous = auth()->user();
            auth()->setUser($author);
            try {
                foreach ($items as $item) {
                    $res = app(OpsIntakeService::class)->apply($item->id, null, $author->id, OpsIntakeItem::VIA_AUTO);
                    $applied += ($res['success'] ?? false) ? 1 : 0;
                }
            } finally {
                $previous ? auth()->setUser($previous) : auth()->forgetUser();
            }
        }

        return ['proposed' => count($items), 'applied' => $applied];
    }

    /**
     * 상황실 «반영» — 설치 기록(확인 대기)과 빠진 반입. 근거는 이 상황실 글.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function apply(OpsIntakeItem $item, array $patch, ?int $userId, string $via): array
    {
        if (! $this->billing->canManage()) {
            return ['success' => false, 'error' => '설치 기성을 기록할 권한이 없습니다. 기성 담당자가 반영하세요.'];
        }
        $line = ContractBoqLine::find((int) ($patch['lineId'] ?? 0));
        $qty = round((float) ($patch['qty'] ?? 0), 4);
        if (! $line || $qty <= 0) {
            return ['success' => false, 'error' => '어느 계약 줄을 얼마나 설치했는지가 없습니다.'];
        }
        $res = app(ClaimEvidenceService::class)->recordInstallation([
            'lineId' => $line->id, 'reportedQty' => $qty,
            'workDate' => ($item->occurred_on ?? $item->created_at)?->toDateString(),
            'location' => (string) ($patch['location'] ?? '상황실 보고'),
            'evidence' => [['type' => 'intake', 'id' => $item->id, 'locator' => '상황실 글'.(filled($patch['quote'] ?? null) ? ' · «'.mb_substr((string) $patch['quote'], 0, 120).'»' : '')]],
            'sourceRef' => 'ops-item:'.$item->id,
            'notes' => '상황실 '.($via === OpsIntakeItem::VIA_AUTO ? '자동' : '반영').' · '.$item->summary.(filled($patch['note'] ?? null) ? ' · '.$patch['note'] : '').' · 확인 필요',
        ]);
        if (! ($res['success'] ?? false)) {
            return ['success' => false, 'error' => $res['error'] ?? '설치 기록을 저장하지 못했습니다.'];
        }
        $item->update([
            'status' => 'applied', 'applied_at' => now(), 'applied_by_id' => $userId, 'applied_via' => $via,
            'previous' => ['recordIds' => array_values(array_filter([$res['id'], $res['storedId']]))],
            'result_note' => '#'.$line->line_no.' 설치 '.$qty.' '.$line->unit.' 기록(확인 대기)'.($res['storedQty'] > 0 ? ' · 반입 '.$res['storedQty'].' 함께' : ''),
        ]);

        return ['success' => true, 'recordId' => $res['id'], 'storedId' => $res['storedId']];
    }

    /**
     * 되돌리기 — 확인 대기 기록은 반려로. 이미 확인된 것은 되돌리지 않는다(기성에 들어갔다).
     *
     * @return array<string, mixed>
     */
    public function revert(OpsIntakeItem $item): array
    {
        $ids = (array) data_get($item->previous, 'recordIds', []);
        $records = ClaimWorkRecord::whereIn('id', $ids ?: [0])->get();
        if ($records->contains('status', 'verified')) {
            return ['success' => false, 'error' => '이 글로 만든 설치 기록이 이미 확인돼 기성에 들어갔습니다. 기성 근거 대장에서 먼저 검토를 되돌리세요.'];
        }
        foreach ($records->where('status', 'pending') as $r) {
            $res = app(ClaimEvidenceService::class)->reviewRecord(['id' => $r->id, 'action' => 'reject', 'reviewNote' => '상황실 반영 취소']);
            if (! ($res['success'] ?? false)) {
                return ['success' => false, 'error' => $res['error'] ?? '되돌리지 못했습니다.'];
            }
        }

        return ['success' => true];
    }

    // ── 읽기 ─────────────────────────────────────────────────────────

    /** 글 원문 + 사진 판독 요약(사진만 올린 글도 무엇을 했는지 알 수 있게). */
    private function text(OpsIntakeBatch $batch): string
    {
        $summaries = OpsIntakeItem::where('ops_intake_batch_id', $batch->id)->where('category', '!=', 'noise')
            ->where('category', '!=', self::CATEGORY)->pluck('summary')->filter()->implode("\n");

        return trim(trim((string) $batch->raw_text)."\n".$summaries);
    }

    /** @return Collection<int, ContractBoqLine> */
    private function lines(int $siteId): Collection
    {
        return ContractBoqLine::query()->where('status', 'accepted')->where('contract_qty', '>', 0)
            ->whereHas('contract', fn ($q) => $q->where('site_id', $siteId)->where('direction', 'receivable'))
            ->with('section:id,code')->orderBy('id')->get()->keyBy('id');
    }

    private function installed(ContractBoqLine $line): float
    {
        return (float) ClaimWorkRecord::where('contract_boq_line_id', $line->id)->where('record_kind', 'actual')
            ->whereIn('stage', ['installation', 'installed'])->whereIn('status', ['pending', 'verified'])
            ->selectRaw('coalesce(sum(coalesce(verified_qty, reported_qty)), 0) as q')->value('q');
    }

    /** @param Collection<int, ContractBoqLine> $lines */
    private function prompt(string $text, Collection $lines, OpsIntakeBatch $batch): string
    {
        $list = $lines->map(fn (ContractBoqLine $l) => $l->id.' | '.($l->section?->code ?? '').' | #'.$l->line_no.' | '
            .($l->group_label ? $l->group_label.' › ' : '').$l->description.($l->spec ? ' | '.mb_substr(preg_replace('/\s+/', ' ', $l->spec), 0, 70) : '')
            .' | '.(float) $l->contract_qty.' '.$l->unit.' | installed so far '.$this->installed($l))->implode("\n");

        return <<<TXT
A construction site posted this report (Korean or English; may include photo descriptions):
"""
{$text}
"""

CONTRACT LINES (id | section | item | description | spec | contract qty unit | installed so far):
{$list}

List the contract lines this report says were INSTALLED / COMPLETED (not planned, not delivered, not "will do").
quantity must be in the contract unit and come from the report: an explicit number (convert units if needed, e.g. feet for LF), or the whole remaining quantity only when the report clearly says that line is entirely finished.
If the report gives no quantity for a line, do not list it. Sizes and types must agree with the spec.
location: where, from the report (room, wall, grid). quote: the exact words from the report that say it.
confidence 0..1, strict: below 0.7 when unsure which line or how much.
TXT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return ['type' => 'object', 'properties' => ['installations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'contract_line_id' => ['type' => 'integer'], 'quantity' => ['type' => 'number'], 'location' => ['type' => 'string'],
            'quote' => ['type' => 'string'], 'confidence' => ['type' => 'number'],
        ], 'required' => ['contract_line_id', 'quantity', 'confidence']]]], 'required' => ['installations']];
    }
}

<?php

namespace App\Services\Finance;

use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\MaterialClaimLink;
use App\Models\MaterialReceipt;
use App\Models\MaterialReceiptLine;
use App\Services\Admin\BillingAdminService;
use App\Services\Ocr\OcrEngine;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * 송장 → 반입 기록. 사장 지시(2026-09-25): «송장 사진 올리면 반입 기록 자동으로 되게 해줘».
 *
 * ── 흐름 ──────────────────────────────────────────────────────────────
 * 송장은 자재 입고에서 이미 사진으로 읽힌다(품목·수량·단가). 사람이 그 입고를 «확정» 하는 순간
 * 이 연결이 돈다 — 확정 전의 AI 판독값으로 기성 근거를 만들지 않는다.
 *  1. 송장 줄마다 계약서의 어느 줄 자재인지 찾는다. 사람이 한 번 연결한 품목은 기억(material_claim_links)
 *     에서 바로, 처음 보는 품목은 AI 가 계약 줄 목록을 보고 추정한다(단위 환산 포함: 10FT 1개 = 10 LF).
 *  2. 찾은 줄마다 «반입(stored)» 현장 기록을 확인 대기로 만든다. 근거는 그 송장 사진.
 *     확인 대기라 청구에 들어가지 않는다 — 담당자가 확인해야 기성이 된다(기성 근거 대장 규칙 그대로).
 *  3. 못 찾은 줄은 입고 화면에서 사람이 한 번 고른다. 그 연결을 기억한다.
 * 확정을 풀면 이 송장으로 만든 확인 대기 기록은 반려로 돌린다. 이미 확인된 것이 있으면 확정을 못 푼다.
 *
 * 반입 단계가 있는 줄(자재 단가와 노무 단가가 둘 다 있는 줄)만 대상이다 — 자재 단가가 없는 줄은
 * 반입으로 받을 돈이 없다.
 */
class ReceiptClaimConnector
{
    public const PREFIX = 'receipt:';

    /** AI 추정을 믿는 하한. 그 아래는 사람이 고르게 둔다. */
    private const MIN_CONFIDENCE = 0.6;

    public function __construct(
        private readonly BillingAdminService $billing,
        private readonly ClaimEvidenceService $ledger,
        private readonly OcrEngine $ai,
    ) {}

    /**
     * 입고 확정/확정 해제에 맞춰 반입 기록을 만들거나 거둔다.
     *
     * @return array{created: int, unmatched: int, warning: ?string}
     */
    public function sync(MaterialReceipt $receipt): array
    {
        if (! $receipt->isConfirmed()) {
            $this->withdraw($receipt);
            $analysis = $receipt->analysis ?? [];
            unset($analysis['claimMatchedAt']);
            $receipt->forceFill(['analysis' => $analysis])->saveQuietly();

            return ['created' => 0, 'unmatched' => 0, 'warning' => null];
        }
        if (! $this->billing->canManage()) {
            // 현장앱에서 반장이 확정한 송장 — 기성 기록은 기성 담당자의 몫이라, 담당자가 입고 화면을 열 때 잇는다.
            return ['created' => 0, 'unmatched' => 0, 'warning' => null, 'deferred' => true];
        }
        $this->markMatched($receipt);
        $candidates = $this->candidates($receipt);
        if ($candidates->isEmpty()) {
            return ['created' => 0, 'unmatched' => 0, 'warning' => null];   // 이 현장에 반입으로 받는 계약 줄이 없다
        }
        $lines = $receipt->lines()->get()->filter(fn (MaterialReceiptLine $l) => ! $this->recordFor($receipt, $l));
        $matches = $this->match($receipt, $lines, $candidates);
        $created = 0;
        $unmatched = 0;
        foreach ($lines as $l) {
            $m = $matches[$l->id] ?? null;
            if ($m === null || ! $this->create($receipt, $l, $candidates->get($m['lineId']), $m['factor'], $m['source'])) {
                $unmatched++;

                continue;
            }
            $created++;
        }

        return ['created' => $created, 'unmatched' => $unmatched, 'warning' => null];
    }

    /**
     * 입고 화면 — 송장 줄마다 만들어진 반입 기록, 또는 고를 계약 줄.
     *
     * @return array<string, mixed>
     */
    public function view(int $receiptId): array
    {
        return $this->respond(function () use ($receiptId): array {
            $receipt = $this->receipt($receiptId);
            // 기성 권한 없는 사람이 확정한 송장은 아직 한 번도 잇지 않았다 — 담당자가 여는 지금 잇는다.
            if ($receipt->isConfirmed() && $this->billing->canManage() && ! data_get($receipt->analysis, 'claimMatchedAt')) {
                $this->sync($receipt);
            }
            $candidates = $this->billing->canView() ? $this->candidates($receipt) : collect();
            $rows = $receipt->lines()->get()->map(function (MaterialReceiptLine $l) use ($receipt): array {
                $record = $this->recordFor($receipt, $l);

                return ['receiptLineId' => $l->id, 'name' => $l->name, 'qty' => (float) $l->quantity, 'unit' => $l->unit,
                    'record' => $record ? ['id' => $record->id, 'status' => $record->status, 'qty' => (float) $record->reported_qty,
                        'lineNo' => $record->line?->line_no, 'description' => $record->line?->description, 'unit' => $record->line?->unit, 'notes' => $record->notes] : null];
            })->all();

            return ['success' => true, 'confirmed' => $receipt->isConfirmed(), 'hasPhoto' => filled($receipt->photo_path),
                'canManage' => $this->billing->canManage(), 'rows' => $rows,
                'options' => $candidates->map(fn (ContractBoqLine $c): array => ['id' => $c->id, 'lineNo' => $c->line_no,
                    'label' => '#'.$c->line_no.' '.$c->description.($c->spec ? ' · '.mb_substr($c->spec, 0, 40) : ''), 'unit' => $c->unit])->values()->all()];
        });
    }

    /**
     * 사람이 송장 줄을 계약 줄에 잇는다 — 반입 기록을 만들고, 이 품목의 연결을 기억한다.
     *
     * @param  array<string, mixed>  $input  receiptLineId, contractLineId, factor
     * @return array<string, mixed>
     */
    public function link(array $input): array
    {
        return $this->respond(function () use ($input): array {
            $line = MaterialReceiptLine::find((int) ($input['receiptLineId'] ?? 0));
            if (! $line) {
                throw new InvalidArgumentException('송장 줄을 찾을 수 없습니다.');
            }
            $receipt = $this->receipt($line->material_receipt_id);
            if (! $receipt->isConfirmed()) {
                throw new InvalidArgumentException('입고를 먼저 확정하세요. 확정된 송장만 반입 근거가 됩니다.');
            }
            if (! $this->billing->canManage()) {
                throw new InvalidArgumentException('반입 기록을 만들 권한이 없습니다.');
            }
            if ($this->recordFor($receipt, $line)) {
                throw new InvalidArgumentException('이 송장 줄은 이미 반입 기록이 있습니다.');
            }
            $target = $this->candidates($receipt)->get((int) ($input['contractLineId'] ?? 0));
            if (! $target) {
                throw new InvalidArgumentException('이 현장에서 반입으로 받는 계약 줄을 고르세요.');
            }
            $factor = (float) ($input['factor'] ?? 1);
            if (! ($factor > 0 && $factor < 100000)) {
                throw new InvalidArgumentException('환산 값(송장 단위 1 = 계약 단위 몇)을 적으세요.');
            }
            if (! $this->create($receipt, $line, $target, $factor, '사람이 연결')) {
                throw new InvalidArgumentException($this->lastError ?? '반입 기록을 만들지 못했습니다.');
            }
            MaterialClaimLink::updateOrCreate(
                ['site_id' => $receipt->site_id, 'name_key' => MaterialClaimLink::keyOf($line->name)],
                ['contract_boq_line_id' => $target->id, 'factor' => $factor, 'receipt_unit' => $line->unit, 'created_by' => auth()->id()],
            );

            return ['success' => true, 'message' => '반입 기록을 만들고, 다음 송장부터 이 품목을 #'.$target->line_no.' 로 바로 연결합니다.'];
        });
    }

    // ── 찾기 ─────────────────────────────────────────────────────────

    /**
     * 이 현장에서 반입으로 받는 계약 줄 — 확정 줄 중 반입(stored) 단계가 있는 것.
     *
     * @return Collection<int, ContractBoqLine>
     */
    private function candidates(MaterialReceipt $receipt): Collection
    {
        return ContractBoqLine::query()->where('status', 'accepted')->where('recognition_basis', 'milestone')
            ->whereHas('contract', fn ($q) => $q->where('site_id', $receipt->site_id)->where('direction', 'receivable'))
            ->orderBy('id')->get()
            ->filter(fn (ContractBoqLine $l) => isset(($l->stage_weights ?? [])['stored']) && $this->billing->findAccessibleContract($l->project_contract_id) !== null)
            ->keyBy('id');
    }

    /**
     * 기억한 연결 먼저, 나머지는 AI 한 번에.
     *
     * @param  Collection<int, MaterialReceiptLine>  $lines
     * @param  Collection<int, ContractBoqLine>  $candidates
     * @return array<int, array{lineId: int, factor: float, source: string}>
     */
    private function match(MaterialReceipt $receipt, Collection $lines, Collection $candidates): array
    {
        $out = [];
        $left = collect();
        foreach ($lines as $l) {
            $link = MaterialClaimLink::where('site_id', $receipt->site_id)->where('name_key', MaterialClaimLink::keyOf($l->name))->first();
            if ($link && $candidates->has($link->contract_boq_line_id)) {
                $out[$l->id] = ['lineId' => $link->contract_boq_line_id, 'factor' => (float) $link->factor, 'source' => '기억한 연결'];
                $link->increment('times_used');
            } else {
                $left->push($l);
            }
        }
        if ($left->isEmpty()) {
            return $out;
        }
        try {
            $result = $this->ai->analyze([], $this->prompt($left, $candidates), $this->schema());
        } catch (\Throwable $e) {
            report($e);

            return $out;   // AI 가 안 되면 사람이 고른다 — 입고 확정은 막지 않는다
        }
        foreach ((array) data_get($result, 'data.matches', []) as $m) {
            $rid = (int) ltrim((string) ($m['receipt_line'] ?? ''), 'r');
            $cid = (int) ($m['contract_line_id'] ?? 0);
            $factor = (float) ($m['factor'] ?? 0);
            if ($left->contains('id', $rid) && $candidates->has($cid) && $factor > 0 && (float) ($m['confidence'] ?? 0) >= self::MIN_CONFIDENCE) {
                $out[$rid] = ['lineId' => $cid, 'factor' => $factor, 'source' => 'AI 추정 — '.mb_substr(trim((string) ($m['reason'] ?? '')), 0, 120)];
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, MaterialReceiptLine>  $lines
     * @param  Collection<int, ContractBoqLine>  $candidates
     */
    private function prompt(Collection $lines, Collection $candidates): string
    {
        $contract = $candidates->map(fn (ContractBoqLine $c) => $c->id.' | #'.$c->line_no.' | '.$c->description
            .($c->spec ? ' | '.mb_substr(preg_replace('/\s+/', ' ', $c->spec), 0, 90) : '').' | unit '.$c->unit.' | material price '.(float) $c->material_price)->implode("\n");
        $receipt = $lines->map(fn (MaterialReceiptLine $l) => 'r'.$l->id.' | '.$l->name.' | '.(float) $l->quantity.' '.($l->unit ?: '').($l->unit_price !== null ? ' | unit price '.(float) $l->unit_price : ''))->implode("\n");

        return <<<TXT
You match lines of a construction material delivery invoice to the contract line (schedule of values) the material is installed under.

CONTRACT LINES (id | item | description | spec | unit | material unit price):
{$contract}

INVOICE LINES (receipt line | name | quantity unit | unit price):
{$receipt}

For each invoice line return the single contract line id whose MATERIAL this is, or 0 if none fits. Sizes, types and specs must agree (a 1/2" EMT invoice line goes to the EMT line whose spec is 1/2", not 3/4").
factor = how many contract units one invoice unit equals (10FT stick of conduit billed per LF → 10; box of 100 connectors billed per EA → 100; same unit → 1). Use the unit prices as a sanity check: invoice unit price ÷ factor should be near the contract material unit price.
confidence 0..1. Be strict: below 0.6 if unsure. reason: one short sentence.
TXT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return ['type' => 'object', 'properties' => ['matches' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'receipt_line' => ['type' => 'string'], 'contract_line_id' => ['type' => 'integer'],
            'factor' => ['type' => 'number'], 'confidence' => ['type' => 'number'], 'reason' => ['type' => 'string'],
        ], 'required' => ['receipt_line', 'contract_line_id', 'factor', 'confidence']]]], 'required' => ['matches']];
    }

    // ── 만들기·거두기 ─────────────────────────────────────────────────

    private ?string $lastError = null;

    /** 반입 기록 한 건(확인 대기). 계약 수량을 넘는 몫은 넣지 않는다 — 넘는 일은 RFI 로. */
    private function create(MaterialReceipt $receipt, MaterialReceiptLine $l, ?ContractBoqLine $target, float $factor, string $source): bool
    {
        $this->lastError = null;
        if (! $target || ! $receipt->received_on) {
            return false;
        }
        $already = (float) ClaimWorkRecord::where('contract_boq_line_id', $target->id)->where('stage', 'stored')
            ->whereIn('status', ['pending', 'verified'])->where('record_kind', 'actual')
            ->selectRaw('coalesce(sum(coalesce(verified_qty, reported_qty)), 0) as q')->value('q');
        $want = round((float) $l->quantity * $factor, 4);
        $qty = round(min($want, (float) $target->contract_qty - $already), 4);
        if ($qty <= 0) {
            $this->lastError = '#'.$target->line_no.' 은 반입 수량이 이미 계약 수량만큼 찼습니다. 넘는 자재는 RFI 로 계약을 늘려야 받을 수 있습니다.';

            return false;
        }
        $r = $this->ledger->saveRecord([
            'lineId' => $target->id, 'recordKind' => 'actual', 'stage' => 'stored',
            'workDate' => $receipt->received_on->toDateString(),
            'location' => mb_substr('송장 반입'.($receipt->vendor ? ' · '.$receipt->vendor : ''), 0, 255),
            'reportedQty' => $qty,
            // 몇 번째 확정인가 — 확정을 풀었다 다시 하면 거둔 기록과 다른 새 기록이다.
            'sourceRef' => self::PREFIX.$receipt->id.':'.$l->id.':'.(ClaimWorkRecord::where('source_ref', 'like', self::PREFIX.$receipt->id.':'.$l->id.':%')->count() + 1),
            'evidence' => filled($receipt->photo_path) ? [['type' => 'receipt', 'id' => $receipt->id, 'locator' => '송장 사진 · '.$l->name]] : [],
            'notes' => '송장 '.trim(($receipt->vendor ?: '').' '.($receipt->po_no ? 'PO '.$receipt->po_no : '')).' · '.$l->name.' '.(float) $l->quantity.' '.($l->unit ?: '')
                .' → '.$qty.' '.$target->unit.($factor != 1.0 ? ' (×'.$factor.')' : '').($qty < $want ? ' · 계약 수량까지만 넣음(넘는 '.round($want - $qty, 4).' 은 RFI 필요)' : '')
                .' · '.$source.' · 확인 필요',
        ]);
        if (! ($r['success'] ?? false)) {
            $this->lastError = $r['error'] ?? null;

            return false;
        }

        return true;
    }

    /** 이 송장을 한 번 이었다는 표시 — 화면을 열 때마다 AI 에게 다시 묻지 않게. */
    private function markMatched(MaterialReceipt $receipt): void
    {
        $analysis = $receipt->analysis ?? [];
        $analysis['claimMatchedAt'] = now()->toIso8601String();
        $receipt->forceFill(['analysis' => $analysis])->saveQuietly();
    }

    /** 확정을 풀면 — 이 송장으로 만든 확인 대기 기록을 반려로. 확인된 것이 있으면 풀지 못하게 막는 쪽은 confirm() 이다. */
    private function withdraw(MaterialReceipt $receipt): void
    {
        foreach ($this->records($receipt)->where('status', 'pending') as $record) {
            $this->ledger->reviewRecord(['id' => $record->id, 'action' => 'reject', 'reviewNote' => '송장 입고 확정 해제 — 이 송장으로 만든 반입 기록을 거둠']);
        }
    }

    /** 확정을 풀 수 없는 이유 — 이 송장으로 만든 반입 기록이 이미 확인(기성)됐다. */
    public function blocksUnconfirm(MaterialReceipt $receipt): ?string
    {
        $n = $this->records($receipt)->where('status', 'verified')->count();

        return $n ? '이 송장으로 만든 반입 기록 '.$n.'건이 이미 확인돼 기성에 들어갔습니다. 확정을 풀려면 기성 근거 대장에서 먼저 검토를 되돌리세요.' : null;
    }

    /** @return Collection<int, ClaimWorkRecord> */
    private function records(MaterialReceipt $receipt): Collection
    {
        return ClaimWorkRecord::where('source_ref', 'like', self::PREFIX.$receipt->id.':%')->with('line')->get();
    }

    private function recordFor(MaterialReceipt $receipt, MaterialReceiptLine $l): ?ClaimWorkRecord
    {
        return ClaimWorkRecord::where('source_ref', 'like', self::PREFIX.$receipt->id.':'.$l->id.':%')
            ->where('status', '!=', 'rejected')->with('line')->latest('id')->first();
    }

    private function receipt(int $id): MaterialReceipt
    {
        $receipt = MaterialReceipt::query()->visibleTo(auth()->user())->find($id);
        if (! $receipt) {
            throw new InvalidArgumentException('입고 기록을 찾을 수 없습니다.');
        }

        return $receipt;
    }

    private function respond(callable $work): array
    {
        try {
            return $work();
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

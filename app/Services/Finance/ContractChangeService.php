<?php

namespace App\Services\Finance;

use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\ContractChange;
use App\Models\ContractChangeLine;
use App\Models\IntelligentDocument;
use App\Models\ProjectContract;
use App\Models\WorkSection;
use App\Services\Admin\BillingAdminService;
use App\Support\AiInformationAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * RFI — 계약을 바꾸는 유일한 길. 사장 지시(2026-09-25): «계약서가 기본이 되어 공사를 하고,
 * 추가되면 RFI 제출해서 승인받고 뺄건 빼고.»
 *
 * ── 규칙 ─────────────────────────────────────────────────────────────
 *  - 추가 RFI 는 새 계약 줄을 만든다. 승인 전에는 «검토 중» 이라 현장 기록·사진은 모을 수 있어도
 *    청구에는 들어가지 않는다(기성 근거 대장이 확정 전 줄의 확인을 막는다). 승인하면 확정 줄이 된다.
 *  - 감액 RFI 는 기존 줄의 계약 수량을 줄인다. 승인 전에는 아무것도 바뀌지 않는다.
 *    이미 확인된 수량보다 적게 줄일 수는 없다 — 한 일을 계약에서 지우면 받을 돈이 사라진다.
 *  - 승인에는 원청 승인 문서가 있어야 한다. 돈이 바뀌는 근거이기 때문이다.
 *  - 승인된 금액은 계약의 «승인된 변경 금액» 에 더해지고, 현재 계약 금액이 그만큼 바뀐다.
 *  - 줄의 기성 인정 방식(반입·설치 분리)은 계약서 줄과 같은 규칙(ContractSheetImportService::recognitionFor)이다.
 */
class ContractChangeService
{
    public function __construct(
        private readonly BillingAdminService $billing,
        private readonly ClaimEvidenceService $evidence,
    ) {}

    /**
     * RFI 제출 — 추가(items: 작업명·규격·단위·수량·단가) 또는 감액(items: lineId·줄일 수량).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function submit(array $input): array
    {
        return $this->respond(function () use ($input): array {
            $section = $this->section((int) ($input['sectionId'] ?? 0));
            $contract = $this->contract($section);
            $kind = (string) ($input['kind'] ?? 'add');
            if (! in_array($kind, ContractChange::KINDS, true)) {
                throw new InvalidArgumentException('RFI 종류(추가·감액)를 고르세요.');
            }
            $rfiNo = strtoupper(trim((string) ($input['rfiNo'] ?? '')));
            if (! preg_match('/^[A-Z0-9][A-Z0-9._-]{0,29}$/D', $rfiNo)) {
                throw new InvalidArgumentException('RFI 번호는 영문·숫자·-·. 으로 30자 이내로 적으세요. 예) 003');
            }
            $title = trim((string) ($input['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 255) {
                throw new InvalidArgumentException('RFI 제목을 적으세요.');
            }
            $items = is_array($input['items'] ?? null) ? array_values($input['items']) : [];
            if ($items === [] || count($items) > 100) {
                throw new InvalidArgumentException('RFI 에 줄을 한 개 이상 적으세요.');
            }
            $requestDoc = ! empty($input['requestDocumentId']) ? $this->document((int) $input['requestDocumentId'], $contract) : null;

            return DB::transaction(function () use ($input, $section, $contract, $kind, $rfiNo, $title, $items, $requestDoc): array {
                ProjectContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
                if (ContractChange::where('project_contract_id', $contract->id)->where('rfi_no', $rfiNo)->where('kind', $kind)->exists()) {
                    throw new InvalidArgumentException('RFI '.$rfiNo.' ('.($kind === 'add' ? '추가' : '감액').')가 이미 있습니다.');
                }
                $change = ContractChange::create([
                    'project_contract_id' => $contract->id, 'work_section_id' => $section->id, 'rfi_no' => $rfiNo, 'kind' => $kind,
                    'title' => $title, 'status' => 'submitted', 'submitted_on' => $this->date($input['submittedOn'] ?? null),
                    'request_document_id' => $requestDoc?->id, 'note' => $this->optional($input['note'] ?? null),
                    'created_by' => auth()->id(),
                ]);
                $total = $kind === 'add'
                    ? $this->addLines($change, $contract, $section, $items, $requestDoc)
                    : $this->deductLines($change, $contract, $items);
                $change->update(['amount' => $total]);

                return ['success' => true, 'id' => $change->id, 'amount' => $total,
                    'message' => 'RFI '.$rfiNo.' 을 제출 중으로 적었습니다. 원청이 승인하면 승인을 눌러 계약에 반영하세요.'];
            });
        });
    }

    /**
     * 원청의 답 — approve(승인 문서 필수) · reject · withdraw(제출 중 RFI 를 거둬들임).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function decide(array $input): array
    {
        return $this->respond(function () use ($input): array {
            $change = ContractChange::with('items.line')->find((int) ($input['id'] ?? 0));
            if (! $change) {
                throw new InvalidArgumentException('RFI 를 찾을 수 없습니다.');
            }
            $contract = $this->accessibleContract($change->project_contract_id);
            $action = (string) ($input['action'] ?? '');
            if (! in_array($action, ['approve', 'reject', 'withdraw'], true)) {
                throw new InvalidArgumentException('승인·반려·취소 중 하나를 고르세요.');
            }
            if ($change->status !== 'submitted') {
                throw new InvalidArgumentException('이미 결정된 RFI 입니다.');
            }
            $approval = $action === 'approve' ? $this->document((int) ($input['approvalDocumentId'] ?? 0), $contract, true) : null;
            $decidedOn = $this->date($input['decidedOn'] ?? null) ?? Carbon::today($contract->site?->timezone ?: config('app.timezone'))->toDateString();

            return DB::transaction(function () use ($change, $contract, $action, $approval, $decidedOn, $input): array {
                $contract = ProjectContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
                $change->refresh()->load('items.line');
                if ($action === 'approve') {
                    $change->kind === 'add' ? $this->approveAdd($change, $approval, $decidedOn) : $this->approveDeduct($change);
                    $approved = round((float) $contract->approved_change_amount + (float) $change->amount, 2);
                    $contract->forceFill([
                        'approved_change_amount' => $approved,
                        'current_amount' => round((float) ($contract->original_amount ?? $contract->current_amount) + $approved, 2),
                    ])->save();
                } else {
                    $this->dropUnusedLines($change);
                }
                if ($action === 'withdraw' && ! $change->items()->exists()) {
                    $change->delete();

                    return ['success' => true, 'message' => 'RFI 를 거둬들였습니다.'];
                }
                $change->update([
                    'status' => $action === 'approve' ? 'approved' : 'rejected', 'decided_on' => $decidedOn,
                    'approval_document_id' => $approval?->id, 'decision_note' => $this->optional($input['note'] ?? null),
                    'decided_by' => auth()->id(),
                ]);

                return ['success' => true, 'message' => $action === 'approve'
                    ? 'RFI '.$change->rfi_no.' 승인 — 계약 금액이 '.number_format((float) $change->amount, 2).' 바뀌었습니다.'
                    : 'RFI '.$change->rfi_no.' 을 반려로 적었습니다.'];
            });
        });
    }

    /**
     * 공정별 RFI — 공정별 도면 화면이 공정 카드에 보여 준다.
     *
     * @param  Collection<int, int>  $sectionIds
     * @return array<int, array<int, array<string, mixed>>> sectionId => RFI 목록
     */
    public function forSections(Collection $sectionIds): array
    {
        if (! $this->billing->canView() || $sectionIds->isEmpty()) {
            return [];
        }
        $allowed = [];

        return ContractChange::query()->whereIn('work_section_id', $sectionIds->all())->with('items.line')->orderBy('id')->get()
            ->filter(function (ContractChange $c) use (&$allowed): bool {
                return $allowed[$c->project_contract_id] ??= $this->billing->findAccessibleContract($c->project_contract_id) !== null;
            })
            ->groupBy('work_section_id')
            ->map(fn (Collection $g) => $g->map(fn (ContractChange $c): array => $this->row($c))->values()->all())
            ->all();
    }

    /**
     * 줄마다 붙은 RFI — 추가로 생긴 줄인지, 감액이 걸려 있는지.
     *
     * @param  array<int, int>  $lineIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function forLines(array $lineIds): array
    {
        return ContractChangeLine::query()->whereIn('contract_boq_line_id', $lineIds ?: [0])->with('change')->orderBy('id')->get()
            ->groupBy('contract_boq_line_id')
            ->map(fn (Collection $g) => $g->map(fn (ContractChangeLine $i): array => [
                'rfiNo' => $i->change->rfi_no, 'kind' => $i->change->kind, 'status' => $i->change->status,
                'qtyDelta' => (float) $i->qty_delta, 'qtyBefore' => $i->qty_before !== null ? (float) $i->qty_before : null,
            ])->values()->all())
            ->all();
    }

    // ── 추가 ─────────────────────────────────────────────────────────

    /** @param array<int, mixed> $items */
    private function addLines(ContractChange $change, ProjectContract $contract, WorkSection $section, array $items, ?IntelligentDocument $requestDoc): float
    {
        $total = 0.0;
        foreach ($items as $i => $item) {
            $item = is_array($item) ? $item : [];
            $parts = [];
            foreach (['materialPrice', 'laborPrice', 'expensePrice'] as $k) {
                $v = $item[$k] ?? null;
                if ($v !== null && $v !== '' && (! is_numeric($v) || (float) $v < 0)) {
                    throw new InvalidArgumentException(($i + 1).'번째 줄의 단가를 숫자로 적으세요.');
                }
                $parts[$k] = $v === null || $v === '' ? null : round((float) $v, 4);
            }
            $price = round(array_sum(array_map(fn ($v) => (float) $v, $parts)), 4);
            $qty = $item['qty'] ?? null;
            if (! is_numeric($qty) || (float) $qty <= 0) {
                throw new InvalidArgumentException(($i + 1).'번째 줄의 수량을 적으세요.');
            }
            $lineNo = 'RFI-'.$change->rfi_no.'-'.($i + 1);
            $saved = $this->evidence->saveLine([
                'projectContractId' => $contract->id, 'lineNo' => $lineNo, 'workSectionId' => $section->id,
                'groupLabel' => mb_substr('RFI '.$change->rfi_no.' · '.$change->title, 0, 255),
                'description' => trim((string) ($item['description'] ?? '')), 'spec' => trim((string) ($item['spec'] ?? '')) ?: null,
                'unit' => trim((string) ($item['unit'] ?? '')), 'contractQty' => round((float) $qty, 4), 'unitPrice' => $price,
                'materialPrice' => $parts['materialPrice'], 'laborPrice' => $parts['laborPrice'], 'expensePrice' => $parts['expensePrice'],
                'status' => 'draft', 'sourceDocumentId' => $requestDoc?->id, 'sourceLocator' => $requestDoc ? 'RFI '.$change->rfi_no : null,
                'sourceRef' => 'rfi:'.$change->id.':'.($i + 1),
            ] + ContractSheetImportService::recognitionFor($parts['materialPrice'], $parts['laborPrice'], $parts['expensePrice'], $price, 'RFI '.$change->rfi_no));
            if (! ($saved['success'] ?? false)) {
                throw new InvalidArgumentException(($i + 1).'번째 줄: '.($saved['error'] ?? '저장하지 못했습니다.'));
            }
            $amount = round((float) $qty * $price, 2);
            ContractChangeLine::create(['contract_change_id' => $change->id, 'contract_boq_line_id' => $saved['id'], 'qty_delta' => round((float) $qty, 4), 'amount' => $amount]);
            $total += $amount;
        }

        return round($total, 2);
    }

    private function approveAdd(ContractChange $change, IntelligentDocument $approval, string $decidedOn): void
    {
        foreach ($change->items as $item) {
            $line = $item->line;
            $rule = ContractSheetImportService::recognitionFor($line->material_price, $line->labor_price, $line->expense_price, (float) $line->unit_price, 'RFI '.$change->rfi_no.' 원청 승인 '.$decidedOn);
            $saved = $this->evidence->saveLine([
                'id' => $line->id, 'status' => 'accepted', 'sourceDocumentId' => $approval->id,
                'sourceLocator' => 'RFI '.$change->rfi_no.' 승인', 'acceptanceNote' => $rule['acceptanceNote'],
            ]);
            if (! ($saved['success'] ?? false)) {
                throw new InvalidArgumentException('#'.$line->line_no.': '.($saved['error'] ?? '확정하지 못했습니다.'));
            }
        }
    }

    // ── 감액 ─────────────────────────────────────────────────────────

    /** @param array<int, mixed> $items */
    private function deductLines(ContractChange $change, ProjectContract $contract, array $items): float
    {
        $total = 0.0;
        foreach ($items as $i => $item) {
            $line = ContractBoqLine::find((int) (is_array($item) ? ($item['lineId'] ?? 0) : 0));
            if (! $line || $line->project_contract_id !== $contract->id || $line->status !== 'accepted') {
                throw new InvalidArgumentException(($i + 1).'번째 줄: 이 계약의 확정 줄을 고르세요.');
            }
            $qty = $item['qty'] ?? null;
            if (! is_numeric($qty) || (float) $qty <= 0 || (float) $qty > (float) $line->contract_qty + 0.00001) {
                throw new InvalidArgumentException('#'.$line->line_no.': 줄일 수량은 0 보다 크고 계약 수량('.(float) $line->contract_qty.') 이하여야 합니다.');
            }
            $amount = -round((float) $qty * (float) $line->unit_price, 2);
            ContractChangeLine::create(['contract_change_id' => $change->id, 'contract_boq_line_id' => $line->id, 'qty_delta' => -round((float) $qty, 4), 'amount' => $amount]);
            $total += $amount;
        }

        return round($total, 2);
    }

    /**
     * 감액 승인 — 기존 줄의 계약 수량을 줄인다. 대장의 일반 수정은 확인 실적이 있는 줄을 잠그지만,
     * 원청이 승인한 RFI 는 그 잠금이 기다리던 «변경계약» 이다. 대신 확인된 수량 밑으로는 못 줄인다.
     */
    private function approveDeduct(ContractChange $change): void
    {
        foreach ($change->items as $item) {
            $line = ContractBoqLine::whereKey($item->contract_boq_line_id)->lockForUpdate()->firstOrFail();
            $after = round((float) $line->contract_qty + (float) $item->qty_delta, 4);
            $verified = (float) (ClaimWorkRecord::where('contract_boq_line_id', $line->id)->where('status', 'verified')
                ->selectRaw('stage, sum(verified_qty) as q')->groupBy('stage')->get()->max('q') ?? 0);
            if ($after < -0.00001 || $after + 0.00001 < $verified) {
                throw new InvalidArgumentException('#'.$line->line_no.': 이미 확인된 수량('.$verified.')보다 적게 줄일 수 없습니다. 한 일은 계약에서 빼지 않습니다.');
            }
            $item->update(['qty_before' => $line->contract_qty]);
            $line->forceFill(['contract_qty' => max(0, $after)])->save();
        }
    }

    // ── 반려·취소 ────────────────────────────────────────────────────

    /** 추가 RFI 의 줄 중 현장 기록이 없는 것은 지운다. 기록이 붙은 줄은 근거라서 «반려된 RFI» 로 남긴다. */
    private function dropUnusedLines(ContractChange $change): void
    {
        if ($change->kind !== 'add') {
            ContractChangeLine::where('contract_change_id', $change->id)->delete();

            return;
        }
        foreach ($change->items as $item) {
            if (! ClaimWorkRecord::where('contract_boq_line_id', $item->contract_boq_line_id)->exists()) {
                $item->delete();
                ContractBoqLine::whereKey($item->contract_boq_line_id)->delete();
            }
        }
    }

    // ── 공통 ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function row(ContractChange $c): array
    {
        return [
            'id' => $c->id, 'rfiNo' => $c->rfi_no, 'kind' => $c->kind, 'title' => $c->title, 'status' => $c->status,
            'amount' => (float) $c->amount, 'submittedOn' => $c->submitted_on?->toDateString(), 'decidedOn' => $c->decided_on?->toDateString(),
            'note' => $c->note, 'decisionNote' => $c->decision_note,
            'requestDocumentUrl' => $c->request_document_id ? route('document-intelligence.preview', ['document' => $c->request_document_id]) : null,
            'approvalDocumentUrl' => $c->approval_document_id ? route('document-intelligence.preview', ['document' => $c->approval_document_id]) : null,
            'items' => $c->items->map(fn (ContractChangeLine $i): array => [
                'lineId' => $i->contract_boq_line_id, 'lineNo' => $i->line?->line_no, 'description' => $i->line?->description,
                'unit' => $i->line?->unit, 'qtyDelta' => (float) $i->qty_delta, 'qtyBefore' => $i->qty_before !== null ? (float) $i->qty_before : null,
                'amount' => (float) $i->amount,
            ])->all(),
        ];
    }

    private function section(int $id): WorkSection
    {
        $section = WorkSection::with('site')->find($id);
        if (! $section || ! $section->site || ! AiInformationAccess::canUseSite(auth()->user(), $section->site)) {
            throw new InvalidArgumentException('공정을 찾을 수 없습니다.');
        }

        return $section;
    }

    private function contract(WorkSection $section): ProjectContract
    {
        if (! $section->project_contract_id) {
            throw new InvalidArgumentException('이 공정에 연결된 원청 계약이 없습니다. 계약서를 먼저 올리세요.');
        }

        return $this->accessibleContract($section->project_contract_id);
    }

    private function accessibleContract(int $id): ProjectContract
    {
        if (! $this->billing->canManage()) {
            throw new InvalidArgumentException('RFI 를 다룰 권한이 없습니다.');
        }
        $contract = $this->billing->findAccessibleContract($id);
        if (! $contract || $contract->direction !== 'receivable') {
            throw new InvalidArgumentException('접근 가능한 수주 계약을 찾을 수 없습니다.');
        }

        return $contract;
    }

    /** RFI 문서·승인 문서 — 같은 현장의 공유 문서만. */
    private function document(int $id, ProjectContract $contract, bool $required = false): IntelligentDocument
    {
        $doc = $id ? IntelligentDocument::query()->visibleTo(auth()->user())->find($id) : null;
        if (! $doc) {
            throw new InvalidArgumentException($required ? '원청 승인 문서(메일·공문·서명본)를 붙여야 승인할 수 있습니다.' : 'RFI 문서를 찾을 수 없습니다.');
        }
        if ($doc->access_level === 'private' || (int) $doc->site_id !== (int) $contract->site_id) {
            throw new InvalidArgumentException('같은 현장의 공유 문서만 RFI 근거로 쓸 수 있습니다.');
        }

        return $doc;
    }

    private function date(mixed $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            throw new InvalidArgumentException('날짜는 YYYY-MM-DD 로 적으세요.');
        }

        return $v;
    }

    private function optional(mixed $value): ?string
    {
        $v = trim((string) $value);

        return $v === '' ? null : mb_substr($v, 0, 5000);
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

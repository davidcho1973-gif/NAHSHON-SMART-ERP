<?php

namespace App\Services\Finance;

use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\IntelligentDocument;
use App\Models\OpsIntakeItem;
use App\Models\PayApplication;
use App\Models\PayApplicationAllocation;
use App\Models\ProjectContract;
use App\Models\WbsPhoto;
use App\Models\WorkSection;
use App\Services\Admin\BillingAdminService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Contract terms, measured work and claim reservations have different meanings.
 * Keeping their provenance separate prevents mutable estimates and forecasts from
 * becoming payable work merely because they carry a progress percentage.
 */
class ClaimEvidenceService
{
    public const SOURCE_PREFIX = 'claim-evidence:';

    public function __construct(private readonly BillingAdminService $billing) {}

    public function getLedger(int $contractId): array
    {
        return $this->respond(function () use ($contractId): array {
            $contract = $this->contract($contractId, false);
            $lines = ContractBoqLine::where('project_contract_id', $contract->id)->with('records.allocations')->orderBy('id')->get();
            $rows = [];
            $records = [];
            $issues = [];
            $summary = ['lineCount' => $lines->count(), 'recordCount' => 0, 'sourceClaimAmount' => 0.0, 'verifiedAmount' => 0.0, 'availableAmount' => 0.0, 'unverifiedCount' => 0];
            foreach ($lines as $line) {
                $row = $this->lineRow($line) + ['reportedQty' => 0.0, 'verifiedQty' => 0.0, 'unallocatedQty' => 0.0, 'sourceClaimQty' => 0.0, 'forecastQty' => 0.0, 'unverifiedQty' => 0.0, 'availableAmount' => 0.0];
                $groups = [];
                if ($line->status !== 'accepted') {
                    $issues[] = ['lineId' => $line->id, 'message' => '계약 수량·단가·인정 조건을 확인해야 합니다.'];
                }
                foreach ($line->records as $record) {
                    $r = $this->recordRow($record);
                    $records[] = $r;
                    $summary['recordCount']++;
                    if ($record->record_kind === 'source_claim') {
                        $row['sourceClaimQty'] += (float) $record->reported_qty;
                        // A source claim's quantity is an equivalent source quantity, not a verified stage valuation.
                        $summary['sourceClaimAmount'] += round((float) $record->reported_qty * (float) $line->unit_price, 2);
                    } elseif ($record->record_kind === 'forecast') {
                        $row['forecastQty'] += (float) $record->reported_qty;
                    } else {
                        $row['reportedQty'] += (float) $record->reported_qty;
                        if ($record->status === 'verified') {
                            $row['verifiedQty'] += (float) $record->verified_qty;
                            $row['unverifiedQty'] += max(0, (float) $record->reported_qty - (float) $record->verified_qty);
                            $row['unallocatedQty'] += $r['availableQty'];
                            $groups[$record->stage] ??= ['qty' => 0.0, 'allocatedAmount' => 0.0];
                            $groups[$record->stage]['qty'] += (float) $record->verified_qty;
                            $groups[$record->stage]['allocatedAmount'] += (float) $record->allocations->sum('amount');
                        } elseif ($record->status === 'pending') {
                            $row['unverifiedQty'] += (float) $record->reported_qty;
                        }
                    }
                    if ($record->status === 'pending') {
                        $summary['unverifiedCount']++;
                        $issues[] = ['lineId' => $line->id, 'recordId' => $record->id, 'message' => $record->record_kind === 'actual' ? '실제 수량과 증빙 검토가 필요합니다.' : '원문 청구·예상 기록은 확인된 실적으로 계산하지 않습니다.'];
                    }
                    if (! $record->evidence) {
                        $issues[] = ['lineId' => $line->id, 'recordId' => $record->id, 'message' => '연결된 ERP 근거 자료가 없습니다.'];
                    }
                }
                $weightedQty = 0.0;
                $allocatedAmount = 0.0;
                foreach ($groups as $stage => $group) {
                    $weightedQty += $this->weightedQuantity($line, $stage, $group['qty']);
                    $allocatedAmount += $group['allocatedAmount'];
                }
                $earned = $this->lineValue($line, $weightedQty);
                $row['availableAmount'] = max(0, round($earned - $allocatedAmount, 2));
                $summary['verifiedAmount'] += $earned;
                $row['availableAmount'] = round($row['availableAmount'], 2);
                $summary['availableAmount'] += $row['availableAmount'];
                $rows[] = $row;
            }
            foreach (['sourceClaimAmount', 'verifiedAmount', 'availableAmount'] as $key) {
                $summary[$key] = round($summary[$key], 2);
            }
            $options = $this->sourceOptions($contract);

            return ['success' => true, 'contract' => $this->contractRow($contract), 'canManage' => $this->billing->canManage(), 'lines' => $rows, 'records' => $records, 'summary' => $summary, 'issues' => $issues, 'sourceImports' => array_values(data_get($contract->payload, 'claimSourceImports', []))] + $options;
        });
    }

    public function saveLine(array $input): array
    {
        return $this->respond(function () use ($input): array {
            $existing = isset($input['id']) ? ContractBoqLine::find((int) $input['id']) : null;
            if (isset($input['id']) && ! $existing) {
                throw new InvalidArgumentException('계약 항목을 찾을 수 없습니다.');
            }
            $contract = $this->contract((int) ($existing?->project_contract_id ?? $input['projectContractId'] ?? 0));

            return DB::transaction(function () use ($input, $existing, $contract): array {
                $this->lock($contract);
                $existing?->refresh();
                if ($existing && $existing->records()->where('status', 'verified')->exists()) {
                    throw new InvalidArgumentException('검토 완료된 실적이 있는 계약 조건은 수정할 수 없습니다. 변경계약 항목으로 기록하세요.');
                }
                $lineNo = $this->text($input['lineNo'] ?? $existing?->line_no, 'BOQ 번호', 80);
                if (ContractBoqLine::where('project_contract_id', $contract->id)->where('line_no', $lineNo)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
                    throw new InvalidArgumentException('계약 안에 같은 BOQ 번호가 있습니다.');
                }
                $qty = $this->number($input['contractQty'] ?? $existing?->contract_qty, '계약 수량', true);
                $price = $this->number($input['unitPrice'] ?? $existing?->unit_price, '계약 단가');
                if ($qty * $price > 999999999999.99) {
                    throw new InvalidArgumentException('계약 항목 금액이 지원 범위를 초과합니다.');
                }
                $basis = (string) ($input['recognitionBasis'] ?? $existing?->recognition_basis ?? 'quantity');
                $status = (string) ($input['status'] ?? $existing?->status ?? 'draft');
                if (! in_array($basis, ['quantity', 'milestone'], true) || ! in_array($status, ['draft', 'accepted'], true)) {
                    throw new InvalidArgumentException('계약 인정 기준 또는 상태가 올바르지 않습니다.');
                }
                $weights = $input['stageWeights'] ?? $existing?->stage_weights ?? [];
                if (! is_array($weights) || count($weights) > 20) {
                    throw new InvalidArgumentException('단계별 인정 비율을 확인하세요.');
                }
                $normalized = [];
                foreach ($weights as $key => $weight) {
                    if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/D', (string) $key)) {
                        throw new InvalidArgumentException('단계 이름은 영문 소문자·숫자·밑줄을 사용하세요.');
                    }
                    $normalized[$key] = $this->number($weight, '단계 비율', true);
                    if ($normalized[$key] > 100) {
                        throw new InvalidArgumentException('단계 비율은 100% 이하이어야 합니다.');
                    }
                }
                if ($basis === 'quantity') {
                    $normalized = ['installed' => 100.0];
                } elseif ($status === 'accepted' && abs(array_sum($normalized) - 100) > 0.00001) {
                    throw new InvalidArgumentException('계약을 확정하려면 단계별 인정 비율의 합계가 100%여야 합니다.');
                }
                $acceptance = trim((string) ($input['acceptanceNote'] ?? $existing?->acceptance_note ?? ''));
                $documentId = (int) ($input['sourceDocumentId'] ?? $existing?->source_document_id ?? 0);
                $locator = trim((string) ($input['sourceLocator'] ?? $existing?->source_locator ?? ''));
                if ($status === 'accepted' && ($acceptance === '' || ! $documentId || $locator === '')) {
                    throw new InvalidArgumentException('계약 확정에는 지급 조건 확인 내용과 원본 계약 문서·위치가 필요합니다.');
                }
                if ($documentId) {
                    $this->resolveEvidence($contract, [['type' => 'document', 'id' => $documentId, 'locator' => $locator ?: '문서 전체']]);
                }
                $split = $this->priceSplit($input, $existing, $price);
                $sectionId = array_key_exists('workSectionId', $input) ? (int) $input['workSectionId'] : (int) $existing?->work_section_id;
                if ($sectionId && ! WorkSection::whereKey($sectionId)->where('site_id', $contract->site_id ?: -1)->exists()) {
                    throw new InvalidArgumentException('계약 현장의 공정만 연결할 수 있습니다.');
                }
                $line = $existing ?? new ContractBoqLine;
                $line->fill($split + [
                    'project_contract_id' => $contract->id, 'line_no' => $lineNo,
                    'work_section_id' => $sectionId ?: null,
                    'spec' => array_key_exists('spec', $input) ? $this->optionalText($input['spec'], 10000) : $existing?->spec,
                    'group_label' => array_key_exists('groupLabel', $input) ? $this->optionalText($input['groupLabel'], 255) : $existing?->group_label,
                    'description' => $this->text($input['description'] ?? $existing?->description, '항목명', 10000),
                    'unit' => $this->text($input['unit'] ?? $existing?->unit, '단위', 20),
                    'contract_qty' => $qty, 'unit_price' => $price, 'recognition_basis' => $basis,
                    'stage_weights' => $normalized, 'status' => $status, 'acceptance_note' => $acceptance ?: null,
                    'accepted_by' => $status === 'accepted' ? auth()->id() : null,
                    'accepted_at' => $status === 'accepted' ? now() : null,
                    'source_document_id' => $documentId ?: null, 'source_locator' => $locator ?: null,
                    'source_ref' => $this->optionalText($input['sourceRef'] ?? $existing?->source_ref, 160),
                ])->save();

                return ['success' => true, 'id' => $line->id, 'line' => $this->lineRow($line)];
            });
        });
    }

    public function saveRecord(array $input): array
    {
        return $this->respond(function () use ($input): array {
            $record = isset($input['id']) ? ClaimWorkRecord::find((int) $input['id']) : null;
            if (isset($input['id']) && ! $record) {
                throw new InvalidArgumentException('실적 기록을 찾을 수 없습니다.');
            }
            $line = ContractBoqLine::find((int) ($record?->contract_boq_line_id ?? $input['lineId'] ?? 0));
            if (! $line) {
                throw new InvalidArgumentException('계약 항목을 찾을 수 없습니다.');
            }
            $contract = $this->contract($line->project_contract_id);

            return DB::transaction(function () use ($input, $record, $line, $contract): array {
                $this->lock($contract);
                $record?->refresh();
                if ($record && ($record->status !== 'pending' || $record->allocations()->exists())) {
                    throw new InvalidArgumentException('검토·청구된 기록은 수정할 수 없습니다. 미청구 기록은 검토 취소 후 수정하세요.');
                }
                $kind = (string) ($input['recordKind'] ?? $record?->record_kind ?? 'actual');
                if (! in_array($kind, ['source_claim', 'actual', 'forecast'], true)) {
                    throw new InvalidArgumentException('기록 종류를 확인하세요.');
                }
                if ($record && $kind !== $record->record_kind) {
                    throw new InvalidArgumentException('원문·예상·실제 기록의 종류는 바꿀 수 없습니다. 실제 작업은 별도 기록으로 남기세요.');
                }
                $date = $this->date($input['workDate'] ?? $record?->work_date?->toDateString());
                if ($kind === 'actual' && $date > $this->siteToday($contract)) {
                    throw new InvalidArgumentException('미래 작업은 실제 실적이 아닌 예상으로 기록하세요.');
                }
                $qty = $this->number($input['reportedQty'] ?? $record?->reported_qty, '보고 수량', true);
                if ($qty > (float) $line->contract_qty + 0.00001) {
                    throw new InvalidArgumentException('보고 수량이 계약 수량을 초과합니다. 변경계약을 먼저 확인하세요.');
                }
                $stage = $this->text($input['stage'] ?? $record?->stage ?? 'installed', '작업 단계', 60);
                if ($kind === 'actual') {
                    $this->stageWeight($line, $stage, false);
                }
                $sourceRef = $this->optionalText($input['sourceRef'] ?? $record?->source_ref, 160);
                if ($sourceRef && ClaimWorkRecord::where('contract_boq_line_id', $line->id)->where('source_ref', $sourceRef)->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                    throw new InvalidArgumentException('이미 접수된 원본 기록입니다. 기존 기록을 사용하세요.');
                }
                $evidence = $this->resolveEvidence($contract, $input['evidence'] ?? $record?->evidence ?? []);
                $location = $this->text($input['location'] ?? $record?->location, '작업 위치', 255);
                if ($kind === 'actual') {
                    $fingerprint = fn (array $proof): string => collect($proof)->map(fn ($p) => $p['type'].':'.$p['id'].':'.$p['locator'])->sort()->implode('|');
                    $duplicates = ClaimWorkRecord::where('contract_boq_line_id', $line->id)->where('record_kind', 'actual')->where('status', '!=', 'rejected')->whereDate('work_date', $date)->where('location', $location)->where('stage', $stage)->where('reported_qty', $qty)->when($record, fn ($q) => $q->whereKeyNot($record->id))->get();
                    if ($duplicates->contains(fn ($other) => $fingerprint($other->evidence ?? []) === $fingerprint($evidence))) {
                        throw new InvalidArgumentException('같은 날짜·위치·단계·수량·근거의 실제 기록이 이미 있습니다. 기존 기록을 확인하거나 작업 구간을 구분하세요.');
                    }
                }
                $entry = $record ?? new ClaimWorkRecord;
                $entry->fill([
                    'contract_boq_line_id' => $line->id, 'record_kind' => $kind, 'work_date' => $date,
                    'location' => $location,
                    'stage' => $stage, 'reported_qty' => $qty, 'verified_qty' => null, 'status' => 'pending',
                    'evidence' => $evidence, 'notes' => $this->optionalText($input['notes'] ?? $record?->notes, 10000),
                    'reported_by' => $record?->reported_by ?? auth()->id(), 'source_ref' => $sourceRef,
                ])->save();

                return ['success' => true, 'id' => $entry->id, 'record' => $this->recordRow($entry)];
            });
        });
    }

    public function reviewRecord(array $input): array
    {
        return $this->respond(function () use ($input): array {
            $record = ClaimWorkRecord::with('line')->find((int) ($input['id'] ?? 0));
            if (! $record) {
                throw new InvalidArgumentException('실적 기록을 찾을 수 없습니다.');
            }
            $contract = $this->contract($record->line->project_contract_id);

            return DB::transaction(function () use ($input, $record, $contract): array {
                $this->lock($contract);
                $record->refresh()->load('line');
                if ($record->allocations()->exists()) {
                    throw new InvalidArgumentException('청구에 배정된 근거는 변경할 수 없습니다.');
                }
                $action = (string) ($input['action'] ?? '');
                $note = $this->text($input['reviewNote'] ?? '', '검토 내용', 10000);
                if (! in_array($action, ['verify', 'reject', 'reopen'], true)) {
                    throw new InvalidArgumentException('검토 동작을 확인하세요.');
                }
                if ($action !== 'reopen' && $record->status !== 'pending') {
                    throw new InvalidArgumentException('이미 검토된 기록입니다. 먼저 검토 취소하세요.');
                }
                $verified = null;
                if ($action === 'verify') {
                    if ($record->record_kind !== 'actual' || $record->work_date->toDateString() > $this->siteToday($contract)) {
                        throw new InvalidArgumentException('원문 청구·예상 기록은 실제 실적으로 승인할 수 없습니다. 별도의 실제 작업 기록을 연결하세요.');
                    }
                    if ($record->line->status !== 'accepted') {
                        throw new InvalidArgumentException('계약 수량·단가·인정 조건을 먼저 확정하세요.');
                    }
                    $this->stageWeight($record->line, $record->stage);
                    $verified = $this->number($input['verifiedQty'] ?? null, '확인 수량', true);
                    if ($verified > (float) $record->reported_qty + 0.00001) {
                        throw new InvalidArgumentException('확인 수량은 보고 수량을 초과할 수 없습니다.');
                    }
                    $others = (float) ClaimWorkRecord::where('contract_boq_line_id', $record->contract_boq_line_id)->where('stage', $record->stage)->where('status', 'verified')->whereKeyNot($record->id)->sum('verified_qty');
                    if ($others + $verified > (float) $record->line->contract_qty + 0.00001) {
                        throw new InvalidArgumentException('같은 단계의 누적 확인 수량이 계약 수량을 초과합니다.');
                    }
                    if (! $record->evidence) {
                        throw new InvalidArgumentException('검토 확인에는 연결된 ERP 원문·현장 기록·사진 근거가 필요합니다.');
                    }
                    $record->evidence = $this->resolveEvidence($contract, $record->evidence, true);
                }
                $history = $record->review_history ?? [];
                $history[] = ['action' => $action, 'from' => $record->status, 'previousVerifiedQty' => $record->verified_qty, 'verifiedQty' => $verified, 'note' => $note, 'by' => auth()->id(), 'at' => now()->toIso8601String()];
                $record->fill(['status' => match ($action) {
                    'verify' => 'verified', 'reject' => 'rejected', default => 'pending'
                }, 'verified_qty' => $verified, 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_note' => $note, 'review_history' => $history])->save();

                return ['success' => true, 'id' => $record->id, 'record' => $this->recordRow($record)];
            });
        });
    }

    public function draft(int $contractId, ?string $periodEnd = null): array
    {
        return $this->respond(function () use ($contractId, $periodEnd): array {
            $contract = $this->contract($contractId);
            $end = $this->date($periodEnd ?? $this->siteToday($contract));

            return DB::transaction(function () use ($contract, $end): array {
                $this->lock($contract);
                $applications = PayApplication::where('project_contract_id', $contract->id)->orderBy('application_no')->get();
                if ($applications->contains(fn ($a) => ! str_starts_with((string) $a->source_ref, self::SOURCE_PREFIX) && ((float) $a->cumulative_amount > 0 || (float) $a->this_period_amount > 0 || (float) $a->stored_materials_amount > 0))) {
                    throw new InvalidArgumentException('기존 수동·공정률 청구가 있습니다. BOQ별 기청구 수량을 대사하기 전에는 자동 초안을 만들 수 없습니다.');
                }
                $existing = $applications->firstWhere('status', 'draft');
                if ($existing && (! str_starts_with((string) $existing->source_ref, self::SOURCE_PREFIX) || data_get($existing->payload, 'evidenceSubmittedAt'))) {
                    throw new InvalidArgumentException('기존 작성 중 회차를 먼저 처리하세요. 이 작업은 기존 수동·제출 이력 회차를 변경하지 않습니다.');
                }
                $previous = $applications->where('status', '!=', 'draft')->last();
                if ($previous?->period_end && $end <= $previous->period_end->toDateString()) {
                    throw new InvalidArgumentException('새 청구 기간은 직전 청구 기간보다 뒤여야 합니다.');
                }
                $records = ClaimWorkRecord::with(['line', 'allocations'])->whereHas('line', fn ($q) => $q->where('project_contract_id', $contract->id)->where('status', 'accepted'))->where('record_kind', 'actual')->where('status', 'verified')->whereDate('work_date', '<=', min($end, $this->siteToday($contract)))->orderBy('id')->get();
                $allocations = [];
                $total = 0.0;
                $valued = [];
                foreach ($records as $record) {
                    $reserved = (float) $record->allocations->where('pay_application_id', '!=', $existing?->id ?? 0)->sum('quantity');
                    $qty = round((float) $record->verified_qty - $reserved, 4);
                    if ($qty <= 0) {
                        continue;
                    }
                    $evidence = $this->resolveEvidence($contract, $record->evidence ?? [], true);
                    $this->ensureUnchanged($record->evidence ?? [], $evidence);
                    if (! $evidence) {
                        throw new InvalidArgumentException('확인된 실적의 근거가 없어 초안을 만들 수 없습니다.');
                    }
                    // Round once for the whole contract line, across all milestones.
                    // Rounding three stage shares of a two-cent line separately would
                    // create a third cent. Previously reserved cents remain unchanged;
                    // this record receives only the new cumulative-value difference.
                    $key = $record->contract_boq_line_id;
                    if (! isset($valued[$key])) {
                        $prior = PayApplicationAllocation::with('record')->whereHas('record', fn ($q) => $q->where('contract_boq_line_id', $record->contract_boq_line_id))->when($existing, fn ($q) => $q->where('pay_application_id', '!=', $existing->id))->get();
                        $valued[$key] = ['weightedQty' => (float) $prior->sum(fn ($a) => $this->weightedQuantity($record->line, $a->record->stage, (float) $a->quantity)), 'amount' => (float) $prior->sum('amount')];
                    }
                    $weightedQty = $valued[$key]['weightedQty'] + $this->weightedQuantity($record->line, $record->stage, $qty);
                    $target = $this->lineValue($record->line, $weightedQty);
                    $value = round($target - $valued[$key]['amount'], 2);
                    if ($value < 0 || $weightedQty > (float) $record->line->contract_qty + 0.00001) {
                        throw new InvalidArgumentException('BOQ 항목의 기존 청구 배정과 계약 인정값이 일치하지 않습니다. 항목별 누계를 대사하세요.');
                    }
                    $valued[$key] = ['weightedQty' => $weightedQty, 'amount' => $target];
                    $contractEvidence = $this->resolveEvidence($contract, [['type' => 'document', 'id' => $record->line->source_document_id, 'locator' => $record->line->source_locator]], true);
                    $snapshot = ['line' => $this->lineRow($record->line), 'record' => $this->recordRow($record), 'evidence' => $evidence, 'contractEvidence' => $contractEvidence, 'reviewedBy' => $record->reviewed_by, 'reviewedAt' => $record->reviewed_at?->toIso8601String(), 'reviewNote' => $record->review_note, 'capturedAt' => now()->toIso8601String()];
                    $allocations[] = ['claim_work_record_id' => $record->id, 'quantity' => $qty, 'amount' => $value, 'snapshot' => $snapshot];
                    $total += $value;
                }
                $total = round($total, 2);
                if ($total <= 0) {
                    throw new InvalidArgumentException('청구할 확인 완료 미배정 실적이 없습니다.');
                }
                if ($contract->current_amount !== null && (int) round(((float) ($previous?->cumulative_amount ?? 0) + $total) * 100) > (int) round((float) $contract->current_amount * 100)) {
                    throw new InvalidArgumentException('확인 실적을 포함한 누계가 계약액을 초과합니다. 승인된 변경계약과 BOQ 조건을 먼저 정리하세요.');
                }
                $start = $previous?->period_end ? $previous->period_end->copy()->addDay()->toDateString() : Carbon::parse($end)->startOfMonth()->toDateString();
                $result = $this->billing->saveBilling(['id' => $existing?->id, 'projectContractId' => $contract->id, 'periodStart' => $start, 'periodEnd' => $end, 'type' => 'progress', 'thisPeriodAmount' => $total, 'storedMaterialsAmount' => 0, 'retainagePercent' => $contract->retainage_percent ?? 0, 'retainageReleased' => 0, 'notes' => '[근거 기반 초안] 확정 계약 조건 × 확인 수량. 제출 전 근거 묶음을 검토하세요.'], true);
                if (! ($result['success'] ?? false)) {
                    throw new InvalidArgumentException($result['error'] ?? implode(' ', $result['errors'] ?? ['청구 초안을 저장하지 못했습니다.']));
                }
                $app = PayApplication::findOrFail($result['id']);
                $app->update(['source_ref' => self::SOURCE_PREFIX.$contract->id.':'.$end, 'payload' => array_merge($app->payload ?? [], ['billingBasis' => 'claim_evidence'])]);
                PayApplicationAllocation::where('pay_application_id', $app->id)->delete();
                foreach ($allocations as $allocation) {
                    PayApplicationAllocation::create($allocation + ['pay_application_id' => $app->id]);
                }

                return ['success' => true, 'id' => $app->id, 'applicationNo' => $app->application_no, 'thisPeriodAmount' => $total, 'amountDue' => (float) $app->amount_due, 'allocationCount' => count($allocations), 'updated' => $existing !== null];
            });
        });
    }

    public function getPacket(int $applicationId): array
    {
        return $this->respond(function () use ($applicationId): array {
            $app = PayApplication::find($applicationId);
            if (! $app) {
                throw new InvalidArgumentException('청구 회차를 찾을 수 없습니다.');
            }
            $contract = $this->contract($app->project_contract_id, false);
            $rows = PayApplicationAllocation::where('pay_application_id', $app->id)->orderBy('id')->get()->map(fn ($a) => ['id' => $a->id, 'recordId' => $a->claim_work_record_id, 'quantity' => (float) $a->quantity, 'amount' => (float) $a->amount, 'snapshot' => $a->snapshot])->all();
            foreach ($rows as $row) {
                $this->resolveEvidence($contract, $row['snapshot']['evidence'] ?? []);
                $this->resolveEvidence($contract, $row['snapshot']['contractEvidence'] ?? []);
            }

            return ['success' => true, 'contract' => $this->contractRow($contract), 'application' => ['id' => $app->id, 'applicationNo' => $app->application_no, 'status' => $app->status, 'periodStart' => $app->period_start?->toDateString(), 'periodEnd' => $app->period_end?->toDateString(), 'currency' => $contract->currency, 'thisPeriodAmount' => (float) $app->this_period_amount, 'amountDue' => (float) $app->amount_due, 'retainageHeld' => (float) $app->retainage_held, 'sourceRef' => $app->source_ref], 'allocations' => $rows, 'totals' => ['amount' => round(array_sum(array_column($rows, 'amount')), 2), 'count' => count($rows)], 'immutable' => $app->status !== 'draft' || (bool) data_get($app->payload, 'evidenceSubmittedAt')];
        });
    }

    /**
     * 줄별 확인 실적과 그 값 — 공정별 화면이 대장과 같은 계산 규칙(단계 비율 × 계약 단가)을 쓰도록
     * 여기서만 계산한다. 화면마다 비율을 다시 곱하면 두 화면의 금액이 갈라진다.
     *
     * @param  iterable<ContractBoqLine>  $lines
     * @return array<int, array{verified: array<string, float>, pending: array<string, float>, pendingCount: int, earned: float}>
     */
    public function progressByLine(iterable $lines): array
    {
        $lines = collect($lines)->keyBy('id');
        $out = $lines->map(fn () => ['verified' => [], 'pending' => [], 'pendingCount' => 0, 'earned' => 0.0])->all();
        if ($lines->isEmpty()) {
            return $out;
        }
        $records = ClaimWorkRecord::query()->whereIn('contract_boq_line_id', $lines->keys()->all())
            ->where('record_kind', 'actual')->whereIn('status', ['verified', 'pending'])
            ->get(['contract_boq_line_id', 'stage', 'status', 'reported_qty', 'verified_qty']);
        foreach ($records as $r) {
            $row = &$out[$r->contract_boq_line_id];
            if ($r->status === 'verified') {
                $row['verified'][$r->stage] = ($row['verified'][$r->stage] ?? 0.0) + (float) $r->verified_qty;
            } else {
                $row['pending'][$r->stage] = ($row['pending'][$r->stage] ?? 0.0) + (float) $r->reported_qty;
                $row['pendingCount']++;
            }
            unset($row);
        }
        foreach ($out as $id => $row) {
            $line = $lines->get($id);
            $weighted = 0.0;
            foreach ($row['verified'] as $stage => $qty) {
                try {
                    $weighted += $this->weightedQuantity($line, $stage, $qty);
                } catch (InvalidArgumentException) {
                    continue;   // 인정 비율이 없는 단계는 대장과 똑같이 값으로 치지 않는다.
                }
            }
            $out[$id]['earned'] = $this->lineValue($line, $weighted);
        }

        return $out;
    }

    /** Submission gate; callers already hold the contract lock. */
    public function validateApplication(PayApplication $app): ?string
    {
        try {
            $contract = $this->contract($app->project_contract_id);
            $allocations = PayApplicationAllocation::where('pay_application_id', $app->id)->with('record.line')->get();
            if ($allocations->isEmpty()) {
                return 'BOQ별 확인 실적 배정이 없는 청구입니다. 근거 대장에서 초안을 생성하세요.';
            }
            if ((int) round((float) $allocations->sum('amount') * 100) !== (int) round((float) $app->this_period_amount * 100) || (float) $app->stored_materials_amount !== 0.0) {
                return '청구 금액과 근거 배정 합계가 다릅니다. 근거 대장에서 다시 계산하세요.';
            }
            foreach ($allocations as $allocation) {
                $record = $allocation->record;
                if (! $record || $record->line->project_contract_id !== $app->project_contract_id || $record->record_kind !== 'actual' || $record->status !== 'verified' || $record->line->status !== 'accepted' || $record->work_date->toDateString() > min($this->siteToday($contract), $app->period_end->toDateString())) {
                    return '청구 근거의 계약·확인 상태·작업일이 유효하지 않습니다.';
                }
                if ((float) $record->allocations()->sum('quantity') > (float) $record->verified_qty + 0.00001) {
                    return '같은 실적 수량이 중복 배정되었습니다.';
                }
                $currentEvidence = $this->resolveEvidence($contract, $record->evidence ?? [], true);
                if (! $currentEvidence) {
                    return '근거 원문을 확인할 수 없습니다.';
                }
                $this->ensureUnchanged($allocation->snapshot['evidence'] ?? [], $currentEvidence);
                $currentContract = $this->resolveEvidence($contract, [['type' => 'document', 'id' => $record->line->source_document_id, 'locator' => $record->line->source_locator]], true);
                $this->ensureUnchanged($allocation->snapshot['contractEvidence'] ?? [], $currentContract);
                $group = PayApplicationAllocation::with('record')->whereHas('record', fn ($q) => $q->where('contract_boq_line_id', $record->contract_boq_line_id))->get();
                $weightedQty = (float) $group->sum(fn ($a) => $this->weightedQuantity($record->line, $a->record->stage, (float) $a->quantity));
                if ((int) round($this->lineValue($record->line, $weightedQty) * 100) !== (int) round((float) $group->sum('amount') * 100)) {
                    return '확정 계약 조건과 BOQ 항목별 청구 배정 합계가 다릅니다.';
                }
            }

            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /** Guard before existing model hooks delete the actual file. */
    public static function sourceIsProtected(string $type, int $id): bool
    {
        if (! Schema::hasTable('claim_work_records')) {
            return false;
        }
        if ($type === 'document' && ContractBoqLine::where('source_document_id', $id)->exists()) {
            return true;
        }

        return ClaimWorkRecord::whereJsonContains('evidence', [['type' => $type, 'id' => $id]])->exists();
    }

    private function contract(int $id, bool $write = true): ProjectContract
    {
        if ($write ? ! $this->billing->canManage() : ! $this->billing->canView()) {
            throw new InvalidArgumentException('기성 근거 관리 권한이 없습니다.');
        }
        $contract = $this->billing->findAccessibleContract($id);
        if (! $contract || $contract->direction !== 'receivable') {
            throw new InvalidArgumentException('접근 가능한 수주 계약을 찾을 수 없습니다.');
        }

        return $contract;
    }

    private function lock(ProjectContract $contract): void
    {
        ProjectContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
    }

    private function resolveEvidence(ProjectContract $contract, mixed $input, bool $requirePhysical = false): array
    {
        if (! is_array($input) || count($input) > 20) {
            throw new InvalidArgumentException('근거 자료는 최대 20개까지 연결할 수 있습니다.');
        }
        $projectCode = $contract->project?->project_code;
        $resolved = [];
        foreach ($input as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('근거 형식을 확인하세요.');
            }
            $type = (string) ($entry['type'] ?? '');
            $id = (int) ($entry['id'] ?? 0);
            $locator = $this->text($entry['locator'] ?? '', '근거 위치', 255);
            $source = match ($type) {
                'document' => IntelligentDocument::visibleTo(auth()->user())->find($id),
                'intake' => OpsIntakeItem::find($id),
                'photo' => WbsPhoto::find($id),
                default => null,
            };
            if (! $source) {
                throw new InvalidArgumentException('접근 가능한 ERP 근거 자료를 찾을 수 없습니다.');
            }
            $same = false;
            if ($type === 'document') {
                if ($source->access_level === 'private') {
                    throw new InvalidArgumentException('개인 문서는 공동 기성 근거로 사용할 수 없습니다. 공유 문서로 정리한 뒤 연결하세요.');
                }
                $same = $source->project_contract_id
                    ? (int) $source->project_contract_id === (int) $contract->id
                    : ($contract->project_id ? (int) $source->project_id === (int) $contract->project_id : ($contract->site_id && (int) $source->site_id === (int) $contract->site_id));
                if ($source->company_id && $contract->company_id && (int) $source->company_id !== (int) $contract->company_id) {
                    $same = false;
                }
            } else {
                $same = $contract->site_id && (int) $source->site_id === (int) $contract->site_id && (! $projectCode || $source->project_code === $projectCode);
            }
            if (! $same) {
                throw new InvalidArgumentException('다른 계약·프로젝트의 자료를 기성 근거로 연결할 수 없습니다.');
            }
            $resolved[$type.':'.$id.':'.$locator] = ['type' => $type, 'id' => $id, 'locator' => $locator, 'note' => $this->optionalText($entry['note'] ?? null, 2000), 'title' => match ($type) {
                'document' => $source->title ?: $source->original_file_name, 'intake' => $source->summary ?: mb_substr($source->raw_text ?? '', 0, 120), default => $source->caption ?: $source->original_name
            }, 'revision' => $type === 'document' ? $source->revision : null, 'sha256' => $type === 'document' ? $source->sha256 : null, 'filePath' => $type === 'document' ? $source->file_path : ($type === 'photo' ? $source->path : null), 'disk' => $source->disk ?? null, 'url' => match ($type) {
                'document' => route('document-intelligence.preview', $source, false), 'photo' => route('wbs-photos.file', $source, false), default => null
            }];
            if ($type === 'photo') {
                $resolved[$type.':'.$id.':'.$locator] += ['originalFilePath' => $source->original_path, 'originalSha256' => $source->original_sha256, 'originalPreserved' => filled($source->original_path) && filled($source->original_sha256)];
                $resolved[$type.':'.$id.':'.$locator]['sha256'] = $source->original_sha256;
            } elseif ($type === 'intake') {
                $resolved[$type.':'.$id.':'.$locator] += ['rawText' => $source->raw_text, 'originalPhotos' => $source->batch?->original_photos ?? [], 'batchId' => $source->ops_intake_batch_id];
            }
            if ($requirePhysical) {
                $resolved[$type.':'.$id.':'.$locator] += $this->physicalProof($type, $source);
            }
        }

        return array_values($resolved);
    }

    private function physicalProof(string $type, mixed $source): array
    {
        if ($type === 'intake') {
            if (trim((string) $source->raw_text) !== '') {
                return ['verifiedTextSha256' => hash('sha256', $source->raw_text), 'fileCheckAt' => now()->toIso8601String()];
            }
            $batch = $source->batch;
            foreach ($batch?->photo_paths ?? [] as $path) {
                if (is_string($path) && Storage::disk($batch->photo_disk ?: config('filesystems.default'))->exists($path)) {
                    return $this->physicalProof('photo', (object) ['path' => $path, 'disk' => $batch->photo_disk ?: config('filesystems.default'), 'original_path' => null, 'original_sha256' => null]);
                }
            }
            throw new InvalidArgumentException('현장 기록의 원문과 사진 파일을 확인할 수 없습니다.');
        }
        $original = $type === 'photo' && filled($source->original_path);
        $path = $type === 'document' ? $source->file_path : ($original ? $source->original_path : $source->path);
        $disk = Storage::disk($source->disk ?: ($type === 'document' ? config('document-intelligence.disk') : config('filesystems.wbs_photos_disk')));
        if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
            throw new InvalidArgumentException('연결된 근거의 실제 파일이 없습니다. 문서·사진 원본을 복구한 뒤 확인하세요.');
        }
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('연결된 근거 파일을 읽을 수 없습니다.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actual = hash_final($hash);
        } finally {
            fclose($stream);
        }
        $expected = $type === 'document' ? $source->sha256 : ($original ? $source->original_sha256 : null);
        if (filled($expected) && ! hash_equals(strtolower((string) $expected), $actual)) {
            throw new InvalidArgumentException('근거 파일의 SHA256이 등록 원본과 다릅니다. 원본 변경 여부를 확인하세요.');
        }

        return ['verifiedFilePath' => $path, 'verifiedFileSha256' => $actual, 'registeredHashMatched' => filled($expected), 'fileCheckAt' => now()->toIso8601String()];
    }

    private function ensureUnchanged(array $reviewed, array $current): void
    {
        $keys = fn (array $proof): string => $proof['type'].':'.$proof['id'].':'.$proof['locator'];
        $original = collect($reviewed)->keyBy($keys);
        foreach ($current as $proof) {
            $saved = $original->get($keys($proof));
            $field = isset($proof['verifiedTextSha256']) ? 'verifiedTextSha256' : 'verifiedFileSha256';
            if (! $saved || empty($saved[$field]) || ! hash_equals((string) $saved[$field], (string) ($proof[$field] ?? ''))) {
                throw new InvalidArgumentException('검토·초안 생성 이후 근거 원문이 변경되었습니다. 기존 근거를 보존하고 다시 검토하세요.');
            }
        }
    }

    private function sourceOptions(ProjectContract $contract): array
    {
        $docs = IntelligentDocument::visibleTo(auth()->user())->where(function ($q) use ($contract): void {
            $q->where('project_contract_id', $contract->id)->orWhere(function ($q) use ($contract): void {
                $q->whereNull('project_contract_id');
                $contract->project_id ? $q->where('project_id', $contract->project_id) : $q->where('site_id', $contract->site_id ?: -1);
            });
        })->orderByDesc('id')->limit(200)->get();
        $sources = [];
        $documentOptions = [];
        foreach ($docs as $doc) {
            try {
                $this->resolveEvidence($contract, [['type' => 'document', 'id' => $doc->id, 'locator' => '문서 전체']]);
                $label = '문서 #'.$doc->id.' · '.($doc->title ?: $doc->original_file_name);
                $sources[] = ['value' => 'document:'.$doc->id, 'label' => $label];
                $documentOptions[] = ['id' => $doc->id, 'label' => $label];
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        $code = $contract->project?->project_code;
        foreach (['intake' => OpsIntakeItem::class, 'photo' => WbsPhoto::class] as $type => $model) {
            $query = $model::where('site_id', $contract->site_id ?: -1)->when($code, fn ($q) => $q->where('project_code', $code))->orderByDesc('id')->limit(200);
            foreach ($query->get() as $source) {
                $sources[] = ['value' => $type.':'.$source->id, 'label' => ($type === 'intake' ? '현장 기록' : '사진').' #'.$source->id.' · '.($type === 'intake' ? ($source->summary ?: mb_substr($source->raw_text ?? '', 0, 100)) : ($source->caption ?: $source->original_name))];
            }
        }

        return ['sourceOptions' => $sources, 'sourceDocumentOptions' => $documentOptions];
    }

    /**
     * 계약서의 자재·노무·경비 단가(G·H·I열). 셋의 합이 계약 단가와 다르면 한쪽이 틀린 것이다 —
     * 반입 자재 기성과 청구서의 반입 자재 칸이 이 값으로 계산되므로 어긋난 채 저장하지 않는다.
     *
     * @return array{material_price: ?float, labor_price: ?float, expense_price: ?float}
     */
    private function priceSplit(array $input, ?ContractBoqLine $existing, float $unitPrice): array
    {
        $split = [];
        foreach (['material_price' => 'materialPrice', 'labor_price' => 'laborPrice', 'expense_price' => 'expensePrice'] as $column => $key) {
            $value = array_key_exists($key, $input) ? $input[$key] : $existing?->{$column};
            $split[$column] = $value === null || $value === '' ? null : $this->number($value, '단가 구성');
        }
        if (array_filter($split, fn ($v) => $v !== null) !== [] && abs(array_sum($split) - $unitPrice) > 0.00005) {
            throw new InvalidArgumentException('자재·노무·경비 단가의 합이 계약 단가와 다릅니다.');
        }

        return $split;
    }

    private function stageWeight(ContractBoqLine $line, string $stage, bool $accepted = true): float
    {
        if ($line->recognition_basis === 'quantity') {
            if ($stage !== 'installed') {
                throw new InvalidArgumentException('수량 기준 계약은 installed 단계만 계산할 수 있습니다. 제작 기성은 단계별 조건을 먼저 설정하세요.');
            }

            return 100.0;
        }
        $weights = $line->stage_weights ?? [];
        if (! isset($weights[$stage]) || ($accepted && abs(array_sum($weights) - 100) > 0.00001)) {
            throw new InvalidArgumentException('이 작업 단계의 계약상 인정 비율이 확정되지 않았습니다.');
        }

        return (float) $weights[$stage];
    }

    private function weightedQuantity(ContractBoqLine $line, string $stage, float $qty): float
    {
        return $qty * $this->stageWeight($line, $stage) / 100;
    }

    private function lineValue(ContractBoqLine $line, float $weightedQty): float
    {
        return round($weightedQty * (float) $line->unit_price, 2);
    }

    private function lineRow(ContractBoqLine $line): array
    {
        return ['id' => $line->id, 'projectContractId' => $line->project_contract_id, 'lineNo' => $line->line_no, 'description' => $line->description, 'unit' => $line->unit, 'contractQty' => (float) $line->contract_qty, 'unitPrice' => (float) $line->unit_price, 'contractAmount' => round((float) $line->contract_qty * (float) $line->unit_price, 2), 'recognitionBasis' => $line->recognition_basis, 'stageWeights' => $line->stage_weights, 'status' => $line->status, 'acceptanceNote' => $line->acceptance_note, 'acceptedBy' => $line->accepted_by, 'acceptedAt' => $line->accepted_at?->toIso8601String(), 'sourceRef' => $line->source_ref, 'sourceDocumentId' => $line->source_document_id, 'sourceLocator' => $line->source_locator,
            'workSectionId' => $line->work_section_id, 'groupLabel' => $line->group_label, 'spec' => $line->spec,
            'materialPrice' => $line->material_price !== null ? (float) $line->material_price : null,
            'laborPrice' => $line->labor_price !== null ? (float) $line->labor_price : null,
            'expensePrice' => $line->expense_price !== null ? (float) $line->expense_price : null];
    }

    private function recordRow(ClaimWorkRecord $record): array
    {
        $allocated = (float) $record->allocations->sum('quantity');
        $evidence = array_map(function (array $proof): array {
            if (($proof['type'] ?? '') === 'document') {
                $document = IntelligentDocument::visibleTo(auth()->user())->find((int) ($proof['id'] ?? 0));
                if (! $document || $document->access_level === 'private') {
                    return ['type' => 'document', 'id' => (int) ($proof['id'] ?? 0), 'title' => '현재 열람할 수 없는 원문', 'locator' => '', 'unavailable' => true];
                }
            }

            return $proof;
        }, $record->evidence ?? []);

        return ['id' => $record->id, 'lineId' => $record->contract_boq_line_id, 'lineNo' => $record->line?->line_no, 'recordKind' => $record->record_kind, 'workDate' => $record->work_date?->toDateString(), 'location' => $record->location, 'stage' => $record->stage, 'reportedQty' => (float) $record->reported_qty, 'verifiedQty' => $record->verified_qty !== null ? (float) $record->verified_qty : null, 'status' => $record->status, 'evidence' => $evidence, 'notes' => $record->notes, 'reviewNote' => $record->review_note, 'reviewHistory' => $record->review_history ?? [], 'sourceRef' => $record->source_ref, 'allocatedQty' => $allocated, 'availableQty' => $record->status === 'verified' ? max(0, round((float) $record->verified_qty - $allocated, 4)) : 0.0, 'createdBy' => $record->reported_by, 'createdAt' => $record->created_at?->toIso8601String(), 'reviewedBy' => $record->reviewed_by, 'reviewedAt' => $record->reviewed_at?->toIso8601String()];
    }

    private function contractRow(ProjectContract $contract): array
    {
        return ['id' => $contract->id, 'title' => $contract->title, 'currency' => $contract->currency, 'today' => $this->siteToday($contract), 'currentAmount' => $contract->current_amount !== null ? (float) $contract->current_amount : null];
    }

    private function siteToday(ProjectContract $contract): string
    {
        return Carbon::today($contract->site?->timezone ?: config('app.timezone'))->toDateString();
    }

    private function number(mixed $value, string $label, bool $positive = false): float
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || ($positive && (float) $value <= 0) || (float) $value > 99999999999.9999 || abs((float) $value - round((float) $value, 4)) > 0.0000001) {
            throw new InvalidArgumentException($label.'은 소수 4자리 이내의 유효한 '.($positive ? '양수' : '0 이상 숫자').'여야 합니다.');
        }

        return round((float) $value, 4);
    }

    private function date(mixed $value): string
    {
        $date = (string) $value;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new InvalidArgumentException('작업·청구일은 YYYY-MM-DD 형식의 실제 날짜여야 합니다.');
        }

        return $date;
    }

    private function text(mixed $value, string $label, int $max): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            throw new InvalidArgumentException($label.'을 입력하세요.');
        }
        $text = trim((string) $value);
        if ($text === '' || mb_strlen($text) > $max) {
            throw new InvalidArgumentException($label.'을 '.$max.'자 이내로 입력하세요.');
        }

        return $text;
    }

    private function optionalText(mixed $value, int $max): ?string
    {
        return $value === null || $value === '' ? null : $this->text($value, '내용', $max);
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

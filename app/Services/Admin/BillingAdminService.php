<?php

namespace App\Services\Admin;

use App\Models\BillingReceipt;
use App\Models\PayApplication;
use App\Models\ProjectContract;
use App\Models\User;
use App\Services\Finance\BillingCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 기성 청구 · 수금 — 수주 계약(project_contracts, receivable)의 청구 원장 관리.
 *
 * 재무 대시보드의 '기성 수금액' 카드가 직원 경비를 보여주다 제거된 근본 원인은
 * 수금 원장 부재였다(교정 커밋 64e2162). 이 서비스가 기성 회차(pay application) →
 * 수금(receipt) → 미수금(AR)·유보금 잔액의 원장을 관리한다 — 발주 쪽
 * "계약 대비 발주 누계"(ContractAdminService poTotals)의 수주 측 대칭이다.
 *
 * 원칙:
 *  - 파생 금액(D·G·held·line6·line7·due)의 산식 정본은 BillingCalculator 다.
 *    여기서 재구현하지 않는다 — 사용자가 보낸 파생값은 무시하고 서버 계산으로 덮어쓴다.
 *  - 방어는 항상 서버다. 프론트 canManage 는 노출 제어일 뿐이다.
 *  - 금액 동치·대소 비교에 float == 를 쓰지 않는다 — BillingCalculator 의
 *    센트 정수 비교(isSettled/releaseWithinCap)나 (int) round(×100) 를 경유한다.
 *  - 확정 후 불변: 금액 수정은 draft 만, approved 이후 정정은 다음 회차 조정으로.
 */
class BillingAdminService
{
    public const VIEW_ROLES = ['super_admin', 'admin', 'site_manager', 'payroll'];

    public const MANAGE_ROLES = ['super_admin', 'admin', 'site_manager'];

    public const DELETE_ROLES = ['super_admin', 'admin'];

    /**
     * NOT NULL 인데 DB 기본값이 있는 칸. 비었을 때 null 을 쓰면 제약 위반이 나므로
     * 키 자체를 빼서 DB 기본값이 들어가게 한다 (ContractAdminService 패턴).
     *
     * @var array<int, string>
     */
    private const DB_DEFAULTED = ['type', 'method'];

    public function canView(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::VIEW_ROLES, true);
    }

    public function canManage(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::MANAGE_ROLES, true);
    }

    private function canDelete(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::DELETE_ROLES, true);
    }

    // ── 조회 ────────────────────────────────────────────────────────────

    /**
     * 계약 목록 + 청구·수금 집계. 발주 누계(poTotals) 선례의 수주 측 대칭.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getBillingContracts(array $filters = []): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '기성 조회 권한이 없습니다.'];
        }

        $query = ProjectContract::query()
            ->with(['counterparty:id,name', 'company:id,name', 'site:id,code'])
            ->where('direction', 'receivable')
            ->orderByDesc('updated_at');
        $this->applyScope($query);

        $status = trim((string) ($filters['status'] ?? ''));
        if (array_key_exists($status, ProjectContract::STATUS_OPTIONS)) {
            $query->where('status', $status);
        }
        if ($siteId = $this->intOrNull($filters['siteId'] ?? null)) {
            $query->where('site_id', $siteId);
        }

        $contracts = $query->get();
        $ids = $contracts->pluck('id');

        // 합산형 지표(billedTotal·submittedPending)는 집계 1쿼리로.
        // billedTotal 은 approved·paid 만 — 미인증(submitted) 청구를 섞으면 AR 과대계상.
        $appAgg = PayApplication::query()
            ->whereIn('project_contract_id', $ids)
            ->selectRaw("project_contract_id,
                COUNT(*) as app_count,
                SUM(CASE WHEN status IN ('approved', 'paid') THEN COALESCE(approved_amount, amount_due) ELSE 0 END) as billed_total,
                SUM(CASE WHEN status = 'submitted' THEN amount_due ELSE 0 END) as submitted_pending")
            ->groupBy('project_contract_id')->get()->keyBy('project_contract_id');

        // 누계형 지표(G·held)는 합산이 아니라 "계약별 최신 회차 값" 이다 — 회차를
        // 합산하면 이중 계상. application_no 오름차순으로 keyBy 하면 최신 행만 남는다.
        $appRows = PayApplication::query()
            ->whereIn('project_contract_id', $ids)
            ->orderBy('application_no')
            ->get(['project_contract_id', 'application_no', 'status', 'cumulative_amount', 'retainage_held']);
        $latestAny = $appRows->keyBy('project_contract_id');
        $latestBilled = $appRows->filter(fn (PayApplication $a): bool => in_array($a->status, ['approved', 'paid'], true))->keyBy('project_contract_id');
        $latestConfirmed = $appRows->filter(fn (PayApplication $a): bool => $a->status !== 'draft')->keyBy('project_contract_id');

        $receiptAgg = BillingReceipt::query()
            ->whereIn('project_contract_id', $ids)
            ->selectRaw('project_contract_id,
                SUM(amount) as received_total,
                SUM(CASE WHEN deduction_accepted IS TRUE THEN deduction_amount ELSE 0 END) as accepted_deductions,
                SUM(CASE WHEN deduction_accepted IS FALSE THEN deduction_amount ELSE 0 END) as disputed_deductions,
                SUM(CASE WHEN pay_application_id IS NULL THEN 1 ELSE 0 END) as unassigned_count')
            ->groupBy('project_contract_id')->get()->keyBy('project_contract_id');

        $rows = $contracts->map(function (ProjectContract $c) use ($appAgg, $latestAny, $latestBilled, $latestConfirmed, $receiptAgg): array {
            $billed = round((float) ($appAgg[$c->id]->billed_total ?? 0), 2);
            $received = round((float) ($receiptAgg[$c->id]->received_total ?? 0), 2);
            $accepted = round((float) ($receiptAgg[$c->id]->accepted_deductions ?? 0), 2);
            $cumulative = round((float) ($latestConfirmed[$c->id]->cumulative_amount ?? 0), 2);
            $current = $c->current_amount !== null ? (float) $c->current_amount : null;
            $latest = $latestAny[$c->id] ?? null;

            return [
                'id' => $c->id,
                'title' => $c->title,
                'contractNumber' => $c->contract_number,
                'internalReference' => $c->internal_reference,
                'counterparty' => $c->counterparty?->name,
                'company' => $c->company?->name,
                'companyId' => $c->company_id,
                'siteId' => $c->site_id,
                'site' => $c->site?->code,
                'status' => $c->status,
                'statusLabel' => ProjectContract::STATUS_OPTIONS[$c->status] ?? (string) $c->status,
                'currency' => $c->currency ?: 'USD',
                'currentAmount' => $current,
                'retainagePercent' => $c->retainage_percent !== null ? (float) $c->retainage_percent : null,
                'paymentTerms' => $c->payment_terms,
                'billedTotal' => $billed,
                'submittedPending' => round((float) ($appAgg[$c->id]->submitted_pending ?? 0), 2),
                'cumulative' => $cumulative,
                'receivedTotal' => $received,
                'outstanding' => round($billed - $received - $accepted, 2),
                'retainageHeld' => round((float) ($latestBilled[$c->id]->retainage_held ?? 0), 2),
                'disputedDeductions' => round((float) ($receiptAgg[$c->id]->disputed_deductions ?? 0), 2),
                'balanceToFinish' => $current !== null ? round($current - $cumulative, 2) : null,
                'applicationCount' => (int) ($appAgg[$c->id]->app_count ?? 0),
                'latestApplicationNo' => $latest?->application_no,
                'latestStatus' => $latest?->status,
                'latestStatusLabel' => $latest ? (PayApplication::STATUS_OPTIONS[$latest->status] ?? (string) $latest->status) : null,
                'unassignedCount' => (int) ($receiptAgg[$c->id]->unassigned_count ?? 0),
            ];
        })->values()->all();

        return ['success' => true, 'rows' => $rows, 'canManage' => $this->canManage()];
    }

    /**
     * 계약 상세 — 회차(수금 포함) + 미배정 입금 + 요약 스트립.
     *
     * @return array<string, mixed>
     */
    public function getBillings(int $contractId): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '기성 조회 권한이 없습니다.'];
        }

        $contract = $this->findAccessibleContract($contractId);
        if (! $contract) {
            return ['success' => false, 'error' => '계약을 찾을 수 없습니다.'];
        }
        $contract->load(['counterparty:id,name', 'site:id,code']);

        $applications = $contract->payApplications()
            ->with(['receipts' => fn ($q) => $q->with('recordedBy:id,name')->orderBy('received_on')->orderBy('id')])
            ->orderBy('application_no')
            ->get();

        $currentAmount = $contract->current_amount !== null ? (float) $contract->current_amount : null;
        $latestNo = (int) $applications->max('application_no');

        $billedTotal = 0.0;
        $submittedPending = 0.0;
        $retainageHeld = 0.0;
        $cumulative = 0.0;
        $rows = [];

        foreach ($applications as $app) {
            $receipts = $app->receipts;
            $received = round((float) $receipts->sum(fn (BillingReceipt $r): float => (float) $r->amount), 2);
            $expected = (float) ($app->approved_amount ?? $app->amount_due);
            $isBilled = in_array($app->status, ['approved', 'paid'], true);
            // 잔액은 승인 이후에만 의미가 있다 — 미승인 회차는 수금 기대액이 아직 없다
            $outstanding = $isBilled ? BillingCalculator::outstanding($expected, $receipts->all()) : null;
            $disputed = BillingCalculator::disputedDeductions($receipts->all());

            if ($isBilled) {
                $billedTotal += $expected;
                // 누계형 지표는 최신 회차 값 — 오름차순 순회라 마지막 값이 남는다
                $retainageHeld = (float) $app->retainage_held;
            }
            if ($app->status === 'submitted') {
                $submittedPending += (float) $app->amount_due;
            }
            if ($app->status !== 'draft') {
                $cumulative = (float) $app->cumulative_amount;
            }

            $rows[] = [
                'id' => $app->id,
                'applicationNo' => $app->application_no,
                'internalReference' => $app->internal_reference,
                'type' => $app->type,
                'typeLabel' => PayApplication::TYPE_OPTIONS[$app->type] ?? (string) $app->type,
                'status' => $app->status,
                'statusLabel' => PayApplication::STATUS_OPTIONS[$app->status] ?? (string) $app->status,
                'isLatest' => $app->application_no === $latestNo,
                'periodStart' => $app->period_start?->toDateString(),
                'periodEnd' => $app->period_end?->toDateString(),
                'submittedOn' => $app->submitted_on?->toDateString(),
                'approvedOn' => $app->approved_on?->toDateString(),
                'dueOn' => $app->due_on?->toDateString(),
                'thisPeriodAmount' => (float) $app->this_period_amount,
                'storedMaterialsAmount' => (float) $app->stored_materials_amount,
                'previousBilledAmount' => (float) $app->previous_billed_amount,
                'cumulativeAmount' => (float) $app->cumulative_amount,
                'retainagePercent' => $app->retainage_percent !== null ? (float) $app->retainage_percent : null,
                'retainageReleased' => (float) $app->retainage_released,
                'retainageHeld' => (float) $app->retainage_held,
                'earnedLessRetainage' => (float) $app->earned_less_retainage,
                'previousCertificates' => (float) $app->previous_certificates,
                'amountDue' => (float) $app->amount_due,
                'approvedAmount' => $app->approved_amount !== null ? (float) $app->approved_amount : null,
                'expectedAmount' => $expected,
                'receivedTotal' => $received,
                'outstanding' => $outstanding,
                'disputedDeductions' => $disputed,
                'paidAt' => $app->paid_at?->toDateTimeString(),
                'conditionalWaiverOn' => $app->conditional_waiver_on?->toDateString(),
                'unconditionalWaiverOn' => $app->unconditional_waiver_on?->toDateString(),
                'notes' => $app->notes,
                'closedManually' => (bool) data_get($app->payload, 'closedManually'),
                'closedReason' => data_get($app->payload, 'reason'),
                'alerts' => $this->applicationAlerts($app, $outstanding, $disputed, $currentAmount, $received),
                'receipts' => $receipts->map(fn (BillingReceipt $r): array => $this->receiptRow($r))->values()->all(),
            ];
        }

        // AR 잔액 = 확정 청구(billedTotal) − 전체 입금 − 인정 차감. 산식은 정본에 위임.
        $allReceipts = $contract->billingReceipts()->get(['amount', 'deduction_amount', 'deduction_accepted']);
        $receivedTotal = round((float) $allReceipts->sum(fn (BillingReceipt $r): float => (float) $r->amount), 2);
        $arOutstanding = BillingCalculator::outstanding(round($billedTotal, 2), $allReceipts->all());

        $unassignedReceipts = $contract->billingReceipts()
            ->whereNull('pay_application_id')
            ->with('recordedBy:id,name')
            ->orderByDesc('received_on')->orderByDesc('id')
            ->get()
            ->map(fn (BillingReceipt $r): array => $this->receiptRow($r))->values()->all();

        return [
            'success' => true,
            'contract' => [
                'id' => $contract->id,
                'title' => $contract->title,
                'contractNumber' => $contract->contract_number,
                'internalReference' => $contract->internal_reference,
                'counterparty' => $contract->counterparty?->name,
                'status' => $contract->status,
                'statusLabel' => ProjectContract::STATUS_OPTIONS[$contract->status] ?? (string) $contract->status,
                'currency' => $contract->currency ?: 'USD',
                'currentAmount' => $currentAmount,
                'retainagePercent' => $contract->retainage_percent !== null ? (float) $contract->retainage_percent : null,
                'paymentTerms' => $contract->payment_terms,
                'siteId' => $contract->site_id,
                'site' => $contract->site?->code,
                'billedTotal' => round($billedTotal, 2),
                'submittedPending' => round($submittedPending, 2),
                'receivedTotal' => $receivedTotal,
                'arOutstanding' => $arOutstanding,
                'disputedDeductions' => BillingCalculator::disputedDeductions($allReceipts->all()),
                'retainageHeld' => round($retainageHeld, 2),
                'cumulative' => round($cumulative, 2),
                'balanceToFinish' => $currentAmount !== null ? round($currentAmount - $cumulative, 2) : null,
            ],
            'rows' => $rows,
            'unassignedReceipts' => $unassignedReceipts,
            'canManage' => $this->canManage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getBillingOptions(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '기성 조회 권한이 없습니다.'];
        }

        $pairs = fn (array $map): array => array_map(
            fn ($k, $v): array => ['value' => (string) $k, 'label' => $v],
            array_keys($map),
            array_values($map),
        );

        $contractQuery = ProjectContract::query()
            ->with('counterparty:id,name')
            ->where('direction', 'receivable')
            ->where('status', 'active')
            ->orderBy('title');
        $this->applyScope($contractQuery);

        return [
            'success' => true,
            // 새 회차 모달 프리필용 — 유보율·Net 조건·통화를 계약에서 물려받는다
            'contracts' => $contractQuery->get()->map(fn (ProjectContract $c): array => [
                'value' => (string) $c->id,
                'label' => trim(($c->counterparty?->name ? $c->counterparty->name.' — ' : '').$c->title),
                'retainagePercent' => $c->retainage_percent !== null ? (float) $c->retainage_percent : null,
                'paymentTerms' => $c->payment_terms,
                'currency' => $c->currency ?: 'USD',
            ])->all(),
            'statuses' => $pairs(PayApplication::STATUS_OPTIONS),
            'types' => $pairs(PayApplication::TYPE_OPTIONS),
            'methods' => $pairs(BillingReceipt::METHOD_OPTIONS),
            'deductionReasons' => $pairs(BillingReceipt::DEDUCTION_REASON_OPTIONS),
            // 차감 판단 3상태 — 빈 값이 곧 "미판단" 이다
            'deductionDecisions' => [
                ['value' => '', 'label' => '미판단'],
                ['value' => '1', 'label' => '인정 (잔액 차감)'],
                ['value' => '0', 'label' => '불인정 (분쟁)'],
            ],
        ];
    }

    // ── 회차 쓰기 ───────────────────────────────────────────────────────

    /**
     * 회차 저장 — draft 만. 파생 금액은 서버 강제 재계산(사용자 입력 무시).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveBilling(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '기성 관리 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $row = null;
        if ($id > 0) {
            $row = $this->findAccessibleApplication($id);
            if (! $row) {
                return ['success' => false, 'error' => '기성 회차를 찾을 수 없습니다.'];
            }
            if ($row->status !== 'draft') {
                // 확정 후 불변 — 승인 이후 정정은 다음 회차 조정으로 (§3.1)
                return ['success' => false, 'error' => '작성 중(draft) 회차만 수정할 수 있습니다. 제출·승인된 회차는 다음 회차에서 조정하세요.'];
            }
            $contract = $row->contract;
        } else {
            $contract = $this->findAccessibleContract((int) ($input['projectContractId'] ?? 0));
            if (! $contract) {
                return ['success' => false, 'error' => '계약을 찾을 수 없습니다.'];
            }
        }

        if ($contract->direction !== 'receivable') {
            return ['success' => false, 'error' => '기성 청구는 수주(receivable) 계약에만 만들 수 있습니다.'];
        }

        // 새 회차 게이트: draft 동시 2건 금지 — 어느 회차가 다음 청구인지 흐려지고
        // 연속성 사슬(D = 전회 D+E)이 갈라진다. 직전 회차가 submitted 이상이어야 한다.
        if (! $row) {
            $openDraft = PayApplication::query()
                ->where('project_contract_id', $contract->id)
                ->where('status', 'draft')
                ->exists();
            if ($openDraft) {
                return ['success' => false, 'error' => '작성 중인 회차가 이미 있습니다. 먼저 제출하거나 삭제한 뒤 새 회차를 만드세요.'];
            }
        }

        $errors = [];

        $type = trim((string) ($input['type'] ?? ''));
        if ($type !== '' && ! array_key_exists($type, PayApplication::TYPE_OPTIONS)) {
            $errors['type'] = '회차 유형을 선택하세요.';
        }
        $effectiveType = $type !== '' ? $type : ($row->type ?? 'progress');

        $dates = [];
        foreach (['periodStart', 'periodEnd', 'dueOn', 'conditionalWaiverOn', 'unconditionalWaiverOn'] as $k) {
            $v = trim((string) ($input[$k] ?? ''));
            if ($v === '') {
                $dates[$k] = null;

                continue;
            }
            try {
                $dates[$k] = Carbon::parse($v)->toDateString();
            } catch (\Throwable) {
                $errors[$k] = '날짜 형식이 올바르지 않습니다.';
            }
        }
        if (empty($dates['periodEnd']) && ! isset($errors['periodEnd'])) {
            $errors['periodEnd'] = '청구 대상 기간의 말일을 입력하세요.';
        }
        if (! empty($dates['periodStart']) && ! empty($dates['periodEnd']) && $dates['periodEnd'] < $dates['periodStart']) {
            $errors['periodEnd'] = '기간 말일이 시작일보다 앞설 수 없습니다.';
        }

        $nums = [];
        foreach (['thisPeriodAmount' => '금회 시공분(E)', 'storedMaterialsAmount' => '보관 자재(F)',
            'retainageReleased' => '유보 해제액', 'retainagePercent' => '유보율'] as $k => $label) {
            $v = trim((string) ($input[$k] ?? ''));
            if ($v === '') {
                $nums[$k] = null;

                continue;
            }
            if (! is_numeric($v)) {
                $errors[$k] = $label.'은(는) 숫자만 입력하세요.';

                continue;
            }
            if ((float) $v < 0) {
                $errors[$k] = $label.'은(는) 음수가 될 수 없습니다.';

                continue;
            }
            $nums[$k] = (float) $v;
        }

        $e = (float) ($nums['thisPeriodAmount'] ?? 0);
        $f = (float) ($nums['storedMaterialsAmount'] ?? 0);
        $released = (float) ($nums['retainageReleased'] ?? 0);
        // 유보율 미입력 시 계약 스냅샷 — 회차별 수정 가능 (율 인하 시 이 회차부터 새 율)
        $rate = $nums['retainagePercent'] ?? ($contract->retainage_percent !== null ? (float) $contract->retainage_percent : 0.0);
        if ($rate > 100) {
            $errors['retainagePercent'] = '유보율은 0~100 사이여야 합니다.';
        }
        if ($released > 0 && ! in_array($effectiveType, ['retainage_release', 'final'], true)) {
            $errors['retainageReleased'] = '유보 해제는 유보 해제(retainage_release)·최종(final) 회차에서만 입력할 수 있습니다.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        // 파생 금액은 서버 강제 재계산 — 사용자가 보낸 D·G·held 류는 무시한다 (정본: BillingCalculator)
        $computed = $this->computeDerived($contract->id, $row?->application_no, $e, $f, $rate, $released);
        $alerts = $this->moneyAlerts($computed['G'], $contract);

        $data = [
            'type' => $type ?: null,
            'period_start' => $dates['periodStart'],
            'period_end' => $dates['periodEnd'],
            'due_on' => $dates['dueOn'],
            'conditional_waiver_on' => $dates['conditionalWaiverOn'],
            'unconditional_waiver_on' => $dates['unconditionalWaiverOn'],
            'this_period_amount' => $e,
            'stored_materials_amount' => $f,
            'previous_billed_amount' => $computed['D'],
            'cumulative_amount' => $computed['G'],
            'retainage_percent' => $rate,
            'retainage_released' => $released,
            'retainage_held' => $computed['held'],
            'earned_less_retainage' => $computed['line6'],
            'previous_certificates' => $computed['line7'],
            'amount_due' => $computed['due'],
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];

        // NOT NULL + DB 기본값 칸 — null 대신 키를 빼서 DB 기본값이 들어가게 한다
        foreach (self::DB_DEFAULTED as $col) {
            if (($data[$col] ?? null) === null) {
                unset($data[$col]);
            }
        }

        $saved = DB::transaction(function () use ($row, $contract, $data): PayApplication {
            if ($row) {
                $row->update($data);

                return $row;
            }

            return PayApplication::create($data + [
                'project_contract_id' => $contract->id,
                // 스코프 3컬럼은 계약에서 복사 — applyScope 필터의 근거
                'company_id' => $contract->company_id,
                'site_id' => $contract->site_id,
                'project_id' => $contract->project_id,
            ]);
        });

        return [
            'success' => true,
            'id' => $saved->id,
            'applicationNo' => $saved->application_no,
            'computed' => $computed,
            'alerts' => $alerts,
        ];
    }

    /**
     * 상태 전이 — §3.1 전이표 그대로. action ∈ submit|withdraw|approve|unapprove|close|reopen.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function setBillingStatus(array $input): array
    {
        $action = (string) ($input['action'] ?? '');

        // 승인 취소·수동 종결·재개는 확정 기록을 되돌리는 행위라 삭제 권한자만 (§3.1)
        $needsDelete = in_array($action, ['unapprove', 'close', 'reopen'], true);
        if ($needsDelete ? ! $this->canDelete() : ! $this->canManage()) {
            return ['success' => false, 'error' => '기성 상태 변경 권한이 없습니다.'];
        }

        $row = $this->findAccessibleApplication((int) ($input['id'] ?? 0));
        if (! $row) {
            return ['success' => false, 'error' => '기성 회차를 찾을 수 없습니다.'];
        }

        return match ($action) {
            'submit' => $this->submitApplication($row),
            'withdraw' => $this->revertApplication($row, 'submitted', 'draft'),
            'approve' => $this->approveApplication($row, $input),
            'unapprove' => $this->revertApplication($row, 'approved', 'submitted'),
            'close' => $this->closeApplication($row, trim((string) ($input['memo'] ?? ''))),
            'reopen' => $this->reopenApplication($row),
            default => ['success' => false, 'error' => '지원하지 않는 상태 변경입니다.'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteBilling(int $id): array
    {
        if (! $this->canDelete()) {
            return ['success' => false, 'error' => '기성 삭제 권한이 없습니다.'];
        }

        $row = $this->findAccessibleApplication($id);
        if (! $row) {
            return ['success' => false, 'error' => '기성 회차를 찾을 수 없습니다.'];
        }
        if ($row->status !== 'draft') {
            return ['success' => false, 'error' => '제출된 회차는 삭제할 수 없습니다. 다음 회차에서 조정하세요.'];
        }
        // 중간 회차가 빠지면 후속 회차의 전회 누계(D·line7)가 허공을 가리킨다
        if (! $this->isLatestApplication($row)) {
            return ['success' => false, 'error' => '후속 회차가 있는 회차는 삭제할 수 없습니다.'];
        }
        if ($row->receipts()->exists()) {
            return ['success' => false, 'error' => '수금이 배정된 회차는 삭제할 수 없습니다. 수금을 먼저 재배정하세요.'];
        }

        DB::transaction(fn () => $row->delete());

        return ['success' => true];
    }

    // ── 수금 쓰기 ───────────────────────────────────────────────────────

    /**
     * 수금 기록 — 회차 배정 또는 미배정(계약 직속, 매칭 대기). 수정은 없다(삭제 후 재입력).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveBillingReceipt(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '수금 기록 권한이 없습니다.'];
        }

        $contract = $this->findAccessibleContract((int) ($input['projectContractId'] ?? 0));
        if (! $contract) {
            return ['success' => false, 'error' => '계약을 찾을 수 없습니다.'];
        }

        $application = null;
        $applicationId = $this->intOrNull($input['payApplicationId'] ?? null);
        if ($applicationId !== null) {
            $application = PayApplication::query()
                ->where('project_contract_id', $contract->id)
                ->find($applicationId);
            if (! $application) {
                return ['success' => false, 'errors' => ['payApplicationId' => '이 계약의 기성 회차가 아닙니다.']];
            }
            if (! in_array($application->status, ['approved', 'paid'], true)) {
                return ['success' => false, 'errors' => ['payApplicationId' => '승인된 회차에만 수금을 배정할 수 있습니다. 미승인 회차 입금은 미배정(계약 직속)으로 두세요.']];
            }
        }

        $errors = [];

        $receivedOn = null;
        $v = trim((string) ($input['receivedOn'] ?? ''));
        if ($v === '') {
            $errors['receivedOn'] = '입금일을 입력하세요.';
        } else {
            try {
                $receivedOn = Carbon::parse($v)->toDateString();
            } catch (\Throwable) {
                $errors['receivedOn'] = '날짜 형식이 올바르지 않습니다.';
            }
        }

        $amount = trim((string) ($input['amount'] ?? ''));
        if (! is_numeric($amount) || (float) $amount <= 0) {
            $errors['amount'] = '입금액은 0보다 큰 숫자만 입력하세요.';
        }

        $method = trim((string) ($input['method'] ?? ''));
        if ($method !== '' && ! array_key_exists($method, BillingReceipt::METHOD_OPTIONS)) {
            $errors['method'] = '입금 수단을 선택하세요.';
        }

        $deduction = trim((string) ($input['deductionAmount'] ?? ''));
        if ($deduction === '') {
            $deduction = 0.0;
        } elseif (! is_numeric($deduction) || (float) $deduction < 0) {
            $errors['deductionAmount'] = '차감액은 0 이상의 숫자만 입력하세요.';
            $deduction = 0.0;
        } else {
            $deduction = (float) $deduction;
        }

        $reason = trim((string) ($input['deductionReason'] ?? '')) ?: null;
        if ($reason !== null && ! array_key_exists($reason, BillingReceipt::DEDUCTION_REASON_OPTIONS)) {
            $errors['deductionReason'] = '차감 사유를 선택하세요.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        // 차감이 없으면 사유·판단도 의미가 없다 — 함께 비운다
        $hasDeduction = (int) round($deduction * 100) > 0;

        $data = [
            'project_contract_id' => $contract->id,
            'pay_application_id' => $application?->id,
            'company_id' => $contract->company_id,
            'site_id' => $contract->site_id,
            'received_on' => $receivedOn,
            'amount' => (float) $amount,
            'method' => $method ?: null,
            'reference' => trim((string) ($input['reference'] ?? '')) ?: null,
            'deduction_amount' => $deduction,
            'deduction_reason' => $hasDeduction ? $reason : null,
            'deduction_accepted' => $hasDeduction ? $this->triState($input['deductionAccepted'] ?? null) : null,
            'recorded_by_user_id' => auth()->id(),
            'memo' => trim((string) ($input['memo'] ?? '')) ?: null,
        ];
        foreach (self::DB_DEFAULTED as $col) {
            if (array_key_exists($col, $data) && $data[$col] === null) {
                unset($data[$col]);
            }
        }

        $result = DB::transaction(function () use ($application, $data): array {
            $receipt = BillingReceipt::create($data);

            // 잔액 ≤ 0 이면 자동 paid — 판정 기준은 단 하나, BillingCalculator::isSettled
            return [
                'id' => $receipt->id,
                'applicationStatus' => $application ? $this->refreshSettlement($application) : null,
            ];
        });

        return ['success' => true, 'id' => $result['id'], 'applicationStatus' => $result['applicationStatus']];
    }

    /**
     * 매칭 대기 배정 / 재배정 / 차감 판단(3상태) 변경 — 관련 회차 잔액을 재계산한다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function assignBillingReceipt(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '수금 배정 권한이 없습니다.'];
        }

        $receipt = $this->findAccessibleReceipt((int) ($input['id'] ?? 0));
        if (! $receipt) {
            return ['success' => false, 'error' => '수금 기록을 찾을 수 없습니다.'];
        }

        $previousApplication = $receipt->application;
        $target = $previousApplication;

        // payApplicationId 키가 온 경우에만 배정을 바꾼다 — 판단 변경만 온 호출을 보호
        if (array_key_exists('payApplicationId', $input)) {
            $targetId = $this->intOrNull($input['payApplicationId']);
            if ($targetId === null) {
                $target = null;   // 배정 해제 → 매칭 대기로 되돌림
            } elseif ($targetId !== $previousApplication?->id) {
                $target = PayApplication::query()
                    ->where('project_contract_id', $receipt->project_contract_id)
                    ->find($targetId);
                if (! $target) {
                    return ['success' => false, 'errors' => ['payApplicationId' => '같은 계약의 기성 회차에만 배정할 수 있습니다.']];
                }
                if (! in_array($target->status, ['approved', 'paid'], true)) {
                    return ['success' => false, 'errors' => ['payApplicationId' => '승인된 회차에만 수금을 배정할 수 있습니다.']];
                }
            }
        }

        $data = ['pay_application_id' => $target?->id];

        // 차감 판단 3상태(null=미판단/true=인정/false=불인정) — 분쟁 해소를 잔액에 반영
        if (array_key_exists('deductionAccepted', $input)) {
            $hasDeduction = (int) round(((float) $receipt->deduction_amount) * 100) > 0;
            $data['deduction_accepted'] = $hasDeduction ? $this->triState($input['deductionAccepted']) : null;
        }

        $status = DB::transaction(function () use ($receipt, $previousApplication, $target, $data): ?string {
            $receipt->update($data);

            // 떠난 회차 먼저 재계산 — paid 였다면 잔액 재발생으로 approved 복귀
            if ($previousApplication && $previousApplication->id !== $target?->id) {
                $this->refreshSettlement($previousApplication);
            }

            return $target ? $this->refreshSettlement($target) : null;
        });

        return ['success' => true, 'applicationStatus' => $status];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteBillingReceipt(int $id): array
    {
        if (! $this->canDelete()) {
            return ['success' => false, 'error' => '수금 삭제 권한이 없습니다.'];
        }

        $receipt = $this->findAccessibleReceipt($id);
        if (! $receipt) {
            return ['success' => false, 'error' => '수금 기록을 찾을 수 없습니다.'];
        }

        $application = $receipt->application;

        $status = DB::transaction(function () use ($receipt, $application): ?string {
            $receipt->delete();

            // 잔액이 재발생하면 paid → approved 자동 복귀 + 지급 필드 소거
            return $application ? $this->refreshSettlement($application) : null;
        });

        return ['success' => true, 'applicationStatus' => $status];
    }

    // ── 상태 전이 내부 ───────────────────────────────────────────────────

    /**
     * draft → submitted. 제출 시점에 직전 회차의 최신 확정값 기준으로 D·line7 을
     * 재확정한다 — 직전 회차가 제출 후 수정됐어도 사슬이 최신을 가리키게.
     *
     * @return array<string, mixed>
     */
    private function submitApplication(PayApplication $row): array
    {
        if ($row->status !== 'draft') {
            return ['success' => false, 'error' => '작성 중(draft) 회차만 제출할 수 있습니다.'];
        }

        $contract = $row->contract;
        $e = (float) $row->this_period_amount;
        $f = (float) $row->stored_materials_amount;
        $released = (float) $row->retainage_released;
        $rate = (float) ($row->retainage_percent ?? 0);

        // 금액이 전부 0이면 청구서가 아니다
        if ((int) round(($e + $f + $released) * 100) === 0) {
            return ['success' => false, 'errors' => ['thisPeriodAmount' => '청구 금액을 입력하세요. 금회 시공분·보관 자재·유보 해제가 모두 0 입니다.']];
        }

        // 유보 해제 상한: 직전 승인 회차의 유보 잔액을 넘을 수 없다 (§3.1 검증 ②)
        if ($released > 0 && in_array($row->type, ['retainage_release', 'final'], true)) {
            $priorHeld = (float) (PayApplication::query()
                ->where('project_contract_id', $row->project_contract_id)
                ->where('application_no', '<', $row->application_no)
                ->whereIn('status', ['approved', 'paid'])
                ->orderByDesc('application_no')
                ->value('retainage_held') ?? 0);
            if (! BillingCalculator::releaseWithinCap($released, $priorHeld)) {
                return ['success' => false, 'errors' => [
                    'retainageReleased' => sprintf('유보 해제액이 직전 승인 회차의 유보 잔액(%s)을 초과합니다.', number_format($priorHeld, 2)),
                ]];
            }
        }

        $computed = $this->computeDerived($row->project_contract_id, $row->application_no, $e, $f, $rate, $released);

        $submittedOn = Carbon::now()->toDateString();
        // 지급 기일 기본값: payment_terms 의 "Net N" 파싱, 실패 시 +45일. 수동 입력값은 존중
        $dueOn = $row->due_on?->toDateString();
        if ($dueOn === null) {
            $netDays = BillingCalculator::parseNetDays($contract?->payment_terms) ?? BillingCalculator::DEFAULT_NET_DAYS;
            $dueOn = Carbon::parse($submittedOn)->addDays($netDays)->toDateString();
        }

        DB::transaction(fn () => $row->update([
            'status' => 'submitted',
            'submitted_on' => $submittedOn,
            'due_on' => $dueOn,
            'previous_billed_amount' => $computed['D'],
            'cumulative_amount' => $computed['G'],
            'retainage_held' => $computed['held'],
            'earned_less_retainage' => $computed['line6'],
            'previous_certificates' => $computed['line7'],
            'amount_due' => $computed['due'],
        ]));

        return [
            'success' => true,
            'status' => 'submitted',
            'computed' => $computed,
            // 과청구는 경고만 — CO 승인 지연 중 선청구가 현실이라 차단하지 않는다 (§4.1)
            'alerts' => $contract ? $this->moneyAlerts($computed['G'], $contract) : [],
        ];
    }

    /**
     * withdraw(submitted→draft) · unapprove(approved→submitted) 공용.
     * 둘 다 "수금 0건 AND 최신 회차만" — 중간 회차를 되돌리면 후속 회차의
     * 전회 누계(D·line7)가 낡는다 (F3).
     *
     * @return array<string, mixed>
     */
    private function revertApplication(PayApplication $row, string $from, string $to): array
    {
        if ($row->status !== $from) {
            return ['success' => false, 'error' => sprintf('%s 상태의 회차만 되돌릴 수 있습니다.', PayApplication::STATUS_OPTIONS[$from] ?? $from)];
        }
        if (! $this->isLatestApplication($row)) {
            return ['success' => false, 'error' => '후속 회차가 있어 되돌릴 수 없습니다. 정정은 다음 회차에서 조정하세요.'];
        }
        // 돈이 들어온 회차를 되돌리면 수금의 귀속처가 사라진다
        if ($row->receipts()->exists()) {
            return ['success' => false, 'error' => '수금이 기록된 회차는 되돌릴 수 없습니다. 수금을 먼저 삭제하거나 재배정하세요.'];
        }

        $data = ['status' => $to];
        if ($to === 'draft') {
            $data['submitted_on'] = null;   // 제출 자체를 무른 것 — 제출일도 함께 지운다
        }
        if ($from === 'approved') {
            $data['approved_on'] = null;
            $data['approved_amount'] = null;   // 승인 취소 — 승인액도 함께 소거
        }

        DB::transaction(fn () => $row->update($data));

        return ['success' => true, 'status' => $to];
    }

    /**
     * submitted → approved. approvedAmount 는 선택 입력 — GC 삭감액.
     * 비우면 null = 청구액 그대로 승인. 청구 원본(amount_due)은 불변 (G1).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function approveApplication(PayApplication $row, array $input): array
    {
        if ($row->status !== 'submitted') {
            return ['success' => false, 'error' => '제출된(submitted) 회차만 승인 기록할 수 있습니다.'];
        }

        $approvedAmount = null;
        $v = trim((string) ($input['approvedAmount'] ?? ''));
        if ($v !== '') {
            if (! is_numeric($v) || (float) $v < 0) {
                return ['success' => false, 'errors' => ['approvedAmount' => '승인액은 0 이상의 숫자만 입력하세요.']];
            }
            $approvedAmount = (float) $v;
        }

        $approvedOn = Carbon::now()->toDateString();
        $d = trim((string) ($input['approvedOn'] ?? ''));
        if ($d !== '') {
            try {
                $approvedOn = Carbon::parse($d)->toDateString();
            } catch (\Throwable) {
                return ['success' => false, 'errors' => ['approvedOn' => '날짜 형식이 올바르지 않습니다.']];
            }
        }

        $status = DB::transaction(function () use ($row, $approvedAmount, $approvedOn): string {
            $row->update([
                'status' => 'approved',
                'approved_on' => $approvedOn,
                'approved_amount' => $approvedAmount,
            ]);

            // 승인액이 0 이면 승인 즉시 잔액이 소멸할 수 있다 — 단일 기준으로 재판정
            return $this->refreshSettlement($row);
        });

        return ['success' => true, 'status' => $status];
    }

    /**
     * 수동 종결(write-off): approved → paid. 양수 잔액 회수 포기 — 사유 필수.
     *
     * @return array<string, mixed>
     */
    private function closeApplication(PayApplication $row, string $memo): array
    {
        if ($row->status !== 'approved') {
            return ['success' => false, 'error' => '승인된(approved) 회차만 수동 종결할 수 있습니다.'];
        }
        if ($memo === '') {
            // 회수 포기는 사업 판단이다 — 왜 포기했는지가 반드시 남아야 한다
            return ['success' => false, 'errors' => ['memo' => '종결 사유를 입력하세요. 사유 없는 회수 포기는 기록할 수 없습니다.']];
        }

        DB::transaction(function () use ($row, $memo): void {
            $payload = (array) ($row->payload ?? []);
            $payload['closedManually'] = true;
            $payload['reason'] = $memo;
            $payload['closedBy'] = auth()->id();
            $payload['closedAt'] = Carbon::now()->toIso8601String();

            $row->update([
                'status' => 'paid',
                'paid_at' => now(),
                'paid_by_user_id' => auth()->id(),
                'payload' => $payload,
            ]);
        });

        return ['success' => true, 'status' => 'paid'];
    }

    /**
     * 수동 종결의 취소: paid → approved. 자동 paid 는 수금 삭제로 자동 복귀하므로
     * 재개는 수동 종결분 전용이다 — 아니면 잔액 0 인 회차가 approved 로 떠 있게 된다.
     *
     * @return array<string, mixed>
     */
    private function reopenApplication(PayApplication $row): array
    {
        if ($row->status !== 'paid') {
            return ['success' => false, 'error' => '수금 완료(paid) 상태의 회차만 재개할 수 있습니다.'];
        }
        if (! (bool) data_get($row->payload, 'closedManually')) {
            return ['success' => false, 'error' => '수동 종결된 회차만 재개할 수 있습니다. 자동 완료된 회차는 수금을 삭제하면 되돌아갑니다.'];
        }

        DB::transaction(function () use ($row): void {
            $payload = (array) ($row->payload ?? []);
            unset($payload['closedManually'], $payload['reason'], $payload['closedBy'], $payload['closedAt']);

            $row->update([
                'status' => 'approved',
                'paid_at' => null,
                'paid_by_user_id' => null,
                'payload' => $payload ?: null,
            ]);
        });

        return ['success' => true, 'status' => 'approved'];
    }

    // ── 내부 계산 ───────────────────────────────────────────────────────

    /**
     * 회차 파생 금액 재계산 — 직전 회차(이 회차보다 앞선 최대 application_no)의
     * 최신 확정값 기준. $beforeNo 가 null 이면 신규 회차(계약의 마지막 회차 뒤).
     * 해제 누계는 BillingCalculator 계약대로 "금회 해제 포함" 으로 넘긴다.
     *
     * @return array{D: float, G: float, held: float, line6: float, line7: float, due: float}
     */
    private function computeDerived(int $contractId, ?int $beforeNo, float $e, float $f, float $rate, float $released): array
    {
        $prevQuery = PayApplication::query()->where('project_contract_id', $contractId);
        if ($beforeNo !== null) {
            $prevQuery->where('application_no', '<', $beforeNo);
        }
        $previous = (clone $prevQuery)->orderByDesc('application_no')->first();
        $releasedToDate = round((float) $prevQuery->sum('retainage_released') + $released, 2);

        return BillingCalculator::derive(
            (float) ($previous->cumulative_amount ?? 0),
            (float) ($previous->stored_materials_amount ?? 0),
            $e,
            $f,
            $rate,
            $releasedToDate,
            (float) ($previous->earned_less_retainage ?? 0),
        );
    }

    /**
     * 자동 paid ↔ approved 복귀의 단일 재판정 지점. 기준은 단 하나 —
     * outstanding ≤ 0.00 (F4). 수동 종결(closedManually)분은 잔액이 양수라도
     * paid 로 두므로 자동 복귀에서 제외한다.
     */
    private function refreshSettlement(PayApplication $app): string
    {
        if (! in_array($app->status, ['approved', 'paid'], true)) {
            return (string) $app->status;
        }

        $expected = (float) ($app->approved_amount ?? $app->amount_due);
        $receipts = $app->receipts()->get(['amount', 'deduction_amount', 'deduction_accepted'])->all();
        $outstanding = BillingCalculator::outstanding($expected, $receipts);

        if ($app->status === 'approved' && BillingCalculator::isSettled($outstanding)) {
            $app->update(['status' => 'paid', 'paid_at' => now(), 'paid_by_user_id' => auth()->id()]);
        } elseif (
            $app->status === 'paid'
            && ! BillingCalculator::isSettled($outstanding)
            && ! (bool) data_get($app->payload, 'closedManually')
        ) {
            // 수금 삭제·재배정으로 잔액 재발생 — 자동 복귀 + 지급 필드 소거
            $app->update(['status' => 'approved', 'paid_at' => null, 'paid_by_user_id' => null]);
        }

        return (string) $app->status;
    }

    /**
     * 저장·제출 공통 경고(차단 아님) — 과청구, 비 USD 계약.
     *
     * @return array<int, array<string, string>>
     */
    private function moneyAlerts(float $cumulative, ProjectContract $contract): array
    {
        $alerts = [];

        if ($contract->current_amount !== null
            && BillingCalculator::isOverbilled($cumulative, (float) $contract->current_amount)) {
            $alerts[] = [
                'label' => '과청구',
                'state' => 'warn',
                'detail' => '청구 누계가 계약 금액을 초과합니다. 변경계약(CO) 승인 여부를 확인하세요.',
            ];
        }
        if (strtoupper((string) ($contract->currency ?: 'USD')) !== 'USD') {
            $alerts[] = [
                'label' => '비 USD 계약',
                'state' => 'warn',
                'detail' => '이 계약의 통화는 '.$contract->currency.' 입니다. 금액 단위를 확인하세요.',
            ];
        }

        return $alerts;
    }

    /**
     * 회차 배지 — 서버가 계산해 내려보내고 화면은 그리기만 한다.
     *
     * @return array<int, array<string, string>>
     */
    private function applicationAlerts(PayApplication $app, ?float $outstanding, float $disputed, ?float $contractAmount, float $received): array
    {
        $alerts = [];
        $today = Carbon::now();

        if ($contractAmount !== null && BillingCalculator::isOverbilled((float) $app->cumulative_amount, $contractAmount)) {
            $alerts[] = ['label' => '과청구', 'state' => 'warn'];
        }

        // 제출 후 경과일 배지 — 45일 주황(soon) / 75일 빨강(expired). 제출~입금 45~75일 현실
        if (in_array($app->status, ['submitted', 'approved'], true) && $app->submitted_on) {
            $days = (int) $app->submitted_on->diffInDays($today);
            if ($days >= 75) {
                $alerts[] = ['label' => "제출 후 {$days}일", 'state' => 'expired'];
            } elseif ($days >= 45) {
                $alerts[] = ['label' => "제출 후 {$days}일", 'state' => 'soon'];
            }
        }

        if ($app->status === 'approved' && $outstanding !== null && ! BillingCalculator::isSettled($outstanding)) {
            if ($app->due_on && $app->due_on->toDateString() < $today->toDateString()) {
                $alerts[] = ['label' => '연체', 'date' => $app->due_on->toDateString(), 'state' => 'expired'];
            }
            if ($received > 0) {
                $alerts[] = ['label' => '부분수금', 'state' => 'soon'];
            }
        }

        if ($disputed > 0) {
            $alerts[] = ['label' => '분쟁 차감', 'state' => 'warn'];
        }

        if ($app->status === 'paid') {
            // 과입금: 잔액이 음수 — paid 는 유지하고 배지로만 알린다 (F4)
            if ($outstanding !== null && (int) round($outstanding * 100) < 0) {
                $alerts[] = ['label' => '과입금', 'state' => 'warn'];
            }
            if ((bool) data_get($app->payload, 'closedManually')) {
                $alerts[] = ['label' => '수동 종결', 'state' => 'warn'];
            }
        }

        return $alerts;
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptRow(BillingReceipt $r): array
    {
        return [
            'id' => $r->id,
            'payApplicationId' => $r->pay_application_id,
            'receivedOn' => $r->received_on?->toDateString(),
            'amount' => (float) $r->amount,
            'method' => $r->method,
            'methodLabel' => BillingReceipt::METHOD_OPTIONS[$r->method] ?? (string) $r->method,
            'reference' => $r->reference,
            'deductionAmount' => (float) $r->deduction_amount,
            'deductionReason' => $r->deduction_reason,
            'deductionReasonLabel' => $r->deduction_reason ? (BillingReceipt::DEDUCTION_REASON_OPTIONS[$r->deduction_reason] ?? $r->deduction_reason) : null,
            'deductionAccepted' => $r->deduction_accepted,   // true|false|null 3상태 그대로
            'recordedBy' => $r->recordedBy?->name,
            'memo' => $r->memo,
            'createdAt' => $r->created_at?->toDateTimeString(),
        ];
    }

    // ── 접근 · 스코프 ────────────────────────────────────────────────────

    private function findAccessibleContract(int $id): ?ProjectContract
    {
        if ($id <= 0) {
            return null;
        }

        $query = ProjectContract::query()->whereKey($id);
        $this->applyScope($query);

        return $query->first();
    }

    /** 담당 범위 밖 회차는 없는 것으로 답한다 — 존재 여부도 정보다. */
    private function findAccessibleApplication(int $id): ?PayApplication
    {
        if ($id <= 0) {
            return null;
        }

        $row = PayApplication::find($id);
        if (! $row || ! $this->findAccessibleContract((int) $row->project_contract_id)) {
            return null;
        }

        return $row;
    }

    private function findAccessibleReceipt(int $id): ?BillingReceipt
    {
        if ($id <= 0) {
            return null;
        }

        $row = BillingReceipt::find($id);
        if (! $row || ! $this->findAccessibleContract((int) $row->project_contract_id)) {
            return null;
        }

        return $row;
    }

    private function isLatestApplication(PayApplication $row): bool
    {
        return ! PayApplication::query()
            ->where('project_contract_id', $row->project_contract_id)
            ->where('application_no', '>', $row->application_no)
            ->exists();
    }

    private function applyScope($query): void
    {
        $user = auth()->user();
        if (! $user) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (in_array($user->access_role, ['super_admin', 'admin', 'payroll'], true) || $user->access_scope === 'all_sites') {
            return;
        }
        if ($user->access_scope === 'site' && $user->allowed_site_id) {
            $query->where('site_id', $user->allowed_site_id);

            return;
        }
        if ($user->access_scope === 'company' && $user->allowed_company_id) {
            $query->where('company_id', $user->allowed_company_id);

            return;
        }
        $query->whereRaw('1 = 0');
    }

    /** 차감 판단 입력 정규화 — ''·null 은 미판단(null), 그 외는 boolean. */
    private function triState(mixed $v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }

        return filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    private function intOrNull(mixed $v): ?int
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === null || $v === '' || $v === '0') ? null : (int) $v;
    }
}

<?php

namespace App\Services\Finance;

use App\Models\PayApplication;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\WbsItem;
use Illuminate\Support\Carbon;

/**
 * 기성 자동 초안 — 공정률에서 청구 금액을 계산해 draft 회차를 만든다.
 *
 * 지금까지 기성 청구는 사람이 "이번 달 얼마 했지?"를 엑셀로 재계산해 금회 시공분(E)을
 * 손으로 넣었다. 그런데 그 답은 이미 시스템 안에 있다: 공정표의 배분원가(planned_cost)와
 * 실측 진척률(effectiveProgress — 안전카드 실적까지 반영된 값). 이 서비스가
 *
 *   기성고(G목표) = Σ(planned_cost × 진척률) − 이전 회차 누계
 *
 * 를 계산해 draft 를 만들어 두면, 청구 준비가 "계산"에서 "검토"로 준다.
 *
 * 원칙:
 *  - **초안까지만.** 제출·승인은 사람이 한다 — 청구는 대외 문서다.
 *  - 파생 금액(유보·차감·금회 지급 청구액)은 BillingCalculator 정본 산식 그대로.
 *  - 멱등: 같은 계약의 자동 초안이 draft 로 남아 있으면 새로 만들지 않고 그 초안의
 *    금액을 최신 공정률로 갱신한다(초안이 쌓이면 어느 것이 진짜인지 모르게 된다).
 */
class ProgressBillingDrafter
{
    /** 자동 초안 멱등 키 접두어. */
    public const SOURCE_PREFIX = 'wbs-progress:';

    /**
     * @return array<string, mixed>
     */
    public function draft(int $contractId, ?string $periodEnd = null): array
    {
        // 권한은 기성 관리와 동일 — 초안도 청구 기록이다.
        if (! app(\App\Services\Admin\BillingAdminService::class)->canManage()) {
            return ['success' => false, 'error' => '기성 청구를 관리할 권한이 없습니다.'];
        }

        $contract = ProjectContract::query()->find($contractId);
        if (! $contract) {
            return ['success' => false, 'error' => '계약을 찾을 수 없습니다.'];
        }
        if ($contract->direction !== 'receivable') {
            return ['success' => false, 'error' => '받을 돈(원청 청구) 계약에서만 기성 초안을 만들 수 있습니다.'];
        }

        $projectCode = $contract->project_id
            ? Project::query()->whereKey($contract->project_id)->value('project_code')
            : null;
        if (! $projectCode) {
            return ['success' => false, 'error' => '계약에 프로젝트가 연결되어 있지 않습니다. 계약 화면에서 프로젝트를 먼저 지정하세요.'];
        }

        // 기성고 = Σ(배분원가 × 진척률). 배분원가가 없는 작업은 청구 근거가 없으므로 제외 —
        // 그 합이 0 이면 공정표에 원가 배분이 아직 안 된 것이다.
        $subtasks = WbsItem::query()
            ->where('project_code', $projectCode)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->whereNotNull('planned_cost')
            ->with('safetyWorkItems.signatures')
            ->get();

        $plannedTotal = round((float) $subtasks->sum(fn (WbsItem $i) => (float) $i->planned_cost), 2);
        if ($plannedTotal <= 0) {
            return ['success' => false, 'error' => '공정표에 배분원가(planned_cost)가 없어 기성고를 계산할 수 없습니다.'];
        }

        $earned = round((float) $subtasks->sum(
            fn (WbsItem $i) => (float) $i->planned_cost * $i->effectiveProgress() / 100
        ), 2);

        // 이전 회차 누계(G·F)와 금회 시공분(E). draft 자동 초안은 "이전"으로 치지 않는다 —
        // 자기 자신을 기준으로 삼으면 갱신할 때마다 E 가 0 으로 수렴한다.
        $previous = PayApplication::query()
            ->where('project_contract_id', $contract->id)
            ->where(function ($q): void {
                $q->whereNull('source_ref')
                    ->orWhere('source_ref', 'not like', self::SOURCE_PREFIX.'%')
                    ->orWhere('status', '!=', 'draft');
            })
            ->orderByDesc('application_no')
            ->first();

        $prevG = (float) ($previous->cumulative_amount ?? 0);
        $prevF = (float) ($previous->stored_materials_amount ?? 0);
        $thisPeriod = round($earned - $prevG, 2);

        if ($thisPeriod <= 0) {
            return [
                'success' => false,
                'error' => sprintf('청구할 신규 기성이 없습니다 — 기성고 $%s 는 이미 청구된 누계 $%s 이하입니다.',
                    number_format($earned, 2), number_format($prevG, 2)),
                'earned' => $earned,
                'previousCumulative' => $prevG,
            ];
        }

        $rate = $contract->retainage_percent !== null ? (float) $contract->retainage_percent : 0.0;
        $releasedToDate = round((float) PayApplication::query()
            ->where('project_contract_id', $contract->id)->sum('retainage_released'), 2);

        $computed = BillingCalculator::derive($prevG, $prevF, $thisPeriod, 0.0, $rate, $releasedToDate,
            (float) ($previous->earned_less_retainage ?? 0));

        $periodEnd = $periodEnd ?: now()->toDateString();
        $periodStart = $previous?->period_end
            ? Carbon::parse((string) $previous->period_end)->addDay()->toDateString()
            : Carbon::parse($periodEnd)->startOfMonth()->toDateString();

        $overallPct = (int) round($earned / $plannedTotal * 100);
        $data = [
            'type' => 'progress',
            'status' => 'draft',
            'period_start' => min($periodStart, $periodEnd),
            'period_end' => $periodEnd,
            'this_period_amount' => $thisPeriod,
            'stored_materials_amount' => 0,
            'previous_billed_amount' => $computed['D'],
            'cumulative_amount' => $computed['G'],
            'retainage_percent' => $rate,
            'retainage_released' => 0,
            'retainage_held' => $computed['held'],
            'earned_less_retainage' => $computed['line6'],
            'previous_certificates' => $computed['line7'],
            'amount_due' => $computed['due'],
            'notes' => sprintf('[자동 초안] 공정률 기준 — 진척 %d%% · 기성고 $%s = Σ(배분원가×진척률). 검토 후 제출하세요.',
                $overallPct, number_format($earned, 2)),
        ];

        // 멱등: 이 계약의 자동 초안이 draft 로 남아 있으면 갱신, 없으면 생성.
        $existing = PayApplication::query()
            ->where('project_contract_id', $contract->id)
            ->where('status', 'draft')
            ->where('source_ref', 'like', self::SOURCE_PREFIX.'%')
            ->orderByDesc('application_no')
            ->first();

        if ($existing) {
            $existing->update($data);
            $app = $existing;
        } else {
            $app = PayApplication::create($data + [
                'project_contract_id' => $contract->id,
                'company_id' => $contract->company_id,
                'site_id' => $contract->site_id,
                'project_id' => $contract->project_id,
                'source_ref' => self::SOURCE_PREFIX.$contract->id.':'.$periodEnd,
            ]);
        }

        return [
            'success' => true,
            'id' => $app->id,
            'applicationNo' => $app->application_no,
            'updated' => $existing !== null,
            'earned' => $earned,
            'plannedTotal' => $plannedTotal,
            'progressPct' => $overallPct,
            'thisPeriod' => $thisPeriod,
            'amountDue' => $computed['due'],
            'retainageHeld' => $computed['held'],
        ];
    }
}

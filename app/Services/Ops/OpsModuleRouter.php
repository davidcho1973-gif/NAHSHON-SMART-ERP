<?php

namespace App\Services\Ops;

use App\Models\Company;
use App\Models\MobileExpense;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\OpsLaborReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 판독된 항목을 실제 모듈로 보낸다 — 상황실이 "모든 정보가 모이는 곳" 이 되는 마지막 단계.
 *
 * 예전에는 도착지가 공정(WBS)·조달 둘뿐이라, 인원·지출·안전은 분류만 되고 갈 곳이 없어
 * 목록에만 남았다(사용자 표현: "다른 모듈과 연동이 안 된다").
 *
 * 반영 강도를 셋으로 나눈다.
 *   즉시   — 인원 보고처럼 되돌리기 쉽고 급여에 직접 닿지 않는 것
 *   확인   — 공정·조달처럼 되돌릴 수 있으나 영향이 큰 것 (기존 apply 경로)
 *   항상확인 — 지출(돈). 되돌려도 나간 돈은 돌아오지 않으므로 자동 반영하지 않는다
 */
class OpsModuleRouter
{
    /**
     * 판독 직후 자동으로 반영되는 것들.
     *
     * 인원 보고를 여기 두는 이유: 이 현장의 목적이 "오늘 몇 명 나왔나" 를 바로 아는 것이고,
     * 보고 인원은 근태(급여 근거)와 분리된 별도 값이라 틀려도 지우면 그만이다.
     *
     * @return array{labor: int, expense: int}
     */
    public function autoRoute(OpsIntakeBatch $batch, OpsIntakeItem $item): array
    {
        $done = ['labor' => 0, 'expense' => 0];

        try {
            if ($item->category === 'labor') {
                $done['labor'] = $this->recordLabor($batch, $item) ? 1 : 0;
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('상황실 자동 반영 실패(item '.$item->id.'): '.$e->getMessage());
        }

        return $done;
    }

    /**
     * 출역 인원 보고를 기록한다.
     *
     * attendance_logs 에는 절대 쓰지 않는다 — 그건 본인이 QR 로 찍은 것만 들어가는 급여 근거다.
     * 여기 값과 QR 실적의 차이가 곧 관리 포인트다(보고 3명 / 실제 2명 → 1명 미확인).
     */
    private function recordLabor(OpsIntakeBatch $batch, OpsIntakeItem $item): bool
    {
        $proposed = (array) ($item->proposed ?? []);
        $headcount = (int) ($proposed['headcount'] ?? 0);
        if ($headcount < 1) {
            return false;   // 인원수를 못 읽었으면 보고로 세지 않는다(추측 금지).
        }

        $label = trim((string) ($proposed['company'] ?? ''));
        $company = $label !== '' ? $this->matchCompany($label) : null;
        $workDate = $item->occurred_on
            ? Carbon::parse($item->occurred_on)->toDateString()
            : Carbon::parse($batch->created_at)->toDateString();

        // 같은 날·같은 업체 보고가 다시 오면 덮어쓴다 — 하루에 여러 번 보고해도 중복 계상되지 않게.
        OpsLaborReport::updateOrCreate(
            [
                'site_id' => $batch->site_id,
                'work_date' => $workDate,
                'company_label' => $label ?: '소속 미상',
            ],
            [
                'ops_intake_batch_id' => $batch->id,
                'ops_intake_item_id' => $item->id,
                'company_id' => $company?->id,
                'trade' => trim((string) ($proposed['trade'] ?? '')) ?: null,
                'headcount' => $headcount,
                'note' => $item->summary,
                'reported_by_id' => $batch->created_by_id,
            ],
        );

        $item->update(['status' => 'applied', 'applied_at' => now(), 'result_note' => '인원 보고 반영']);

        return true;
    }

    /** AI 가 읽은 업체명을 등록된 회사와 맞춘다. 못 찾으면 원문만 남긴다(억지 매칭 금지). */
    private function matchCompany(string $label): ?Company
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }

        return Company::query()
            ->where('name', $label)
            ->orWhere('name', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $label).'%')
            ->orderByRaw('length(name)')
            ->first();
    }

    /**
     * 지출(영수증)을 재무에 등록한다 — 관리자가 확인 버튼을 눌렀을 때만 호출된다.
     *
     * 금액은 자동 반영하지 않는다. 되돌려도 이미 나간 돈은 돌아오지 않고, 잘못 계상된 지출은
     * 정산에서 조용히 섞여 들어가 나중에 찾기가 매우 어렵다.
     *
     * @return array<string, mixed>
     */
    public function applyExpense(OpsIntakeItem $item, ?int $userId = null): array
    {
        $proposed = (array) ($item->proposed ?? []);
        $amount = (float) ($proposed['amount'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'error' => '금액을 읽지 못했습니다. 영수증을 다시 올리거나 직접 입력하세요.'];
        }

        $batch = $item->ops_intake_batch_id ? OpsIntakeBatch::find($item->ops_intake_batch_id) : null;

        $expense = MobileExpense::create([
            'site_id' => $item->site_id,
            'employee_id' => null,
            'payment_type' => 'company_card',
            'category' => trim((string) ($proposed['category'] ?? '')) ?: '자재비',
            'description' => trim((string) ($proposed['vendor'] ?? '')).' '.$item->summary,
            'amount' => $amount,
            'expense_date' => ($proposed['spent_on'] ?? null)
                ? Carbon::parse((string) $proposed['spent_on'])->toDateString()
                : Carbon::parse($batch?->created_at ?? now())->toDateString(),
            'status' => 'pending',
            'ocr_data' => ['source' => 'ops-room', 'batch_id' => $item->ops_intake_batch_id, 'item_id' => $item->id],
        ]);

        $item->update([
            'status' => 'applied',
            'applied_at' => now(),
            'result_note' => '재무(지출) 등록 #'.$expense->id,
        ]);

        return ['success' => true, 'expenseId' => $expense->id, 'amount' => $amount];
    }
}

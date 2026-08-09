<?php

namespace App\Services\Finance;

use App\Models\Equipment;
use App\Models\Housing;
use App\Models\MobileExpense;
use Illuminate\Support\Carbon;

/**
 * 임대 자산의 고정비를 매월 자동으로 원장(경비)에 넣는다.
 *
 * 장비 대장에는 일대(daily_rate)·임대 기간이, 숙소 대장에는 월세(monthly_rent)가
 * 저장돼 있었지만 어떤 합산에도 참여하지 않았다 — 재무 화면에 잡히려면 사람이
 * 매월 같은 금액을 경비로 다시 쳐야 했다. 급여가 이미 쓰는 자동 원장 패턴
 * (PayrollExpenseConnector)을 임대 자산에 복제한 것이 이 클래스다.
 *
 * 원칙:
 *  - 멱등: source_ref("equipment:{id}:{YYYY-MM}")가 같으면 다시 만들지 않는다.
 *    금액이 바뀌었으면(요율 수정 등) 미승인 건에 한해 갱신한다.
 *  - 자동 생성 건은 항상 'pending' — 장부에 바로 확정하지 않고 사람이 승인한다.
 *    이미 승인/지급된 건은 절대 덮어쓰지 않는다.
 */
class RentalExpenseConnector
{
    /**
     * 한 달치 임대비를 산출해 경비로 넣는다. 반환: 만든/갱신한 건수.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function accrueMonth(?string $month = null): array
    {
        $start = Carbon::parse(($month ?: now()->format('Y-m')).'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $tag = $start->format('Y-m');

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        // ── 장비: 임대 기간과 이 달이 겹치는 일수 × 일대 ────────────────
        Equipment::query()
            ->where('daily_rate', '>', 0)
            ->whereNotNull('rent_start')
            ->get()
            ->each(function (Equipment $eq) use ($start, $end, $tag, &$counts): void {
                // 날짜 단위로 자른다 — 시각이 섞이면(endOfMonth 23:59) 하루가 더 세진다.
                $from = Carbon::parse($eq->rent_start)->max($start)->startOfDay();
                $to = ($eq->rent_end ? Carbon::parse($eq->rent_end) : $end)->min($end)->startOfDay();
                if ($from->gt($to)) {
                    return; // 이 달에는 임대 기간이 없다.
                }

                $days = (int) $from->diffInDays($to) + 1;
                $amount = round($days * (float) $eq->daily_rate, 2);
                // 운반비는 임대 시작 달에 한 번만.
                if ((float) $eq->delivery_fee > 0 && Carbon::parse($eq->rent_start)->between($start, $end)) {
                    $amount += (float) $eq->delivery_fee;
                }
                if ($amount <= 0) {
                    return;
                }

                $label = trim(($eq->equipment_type ?: '장비').' '.($eq->model ?: $eq->equipment_code));
                $this->upsert("equipment:{$eq->id}:{$tag}", [
                    'company_id' => $eq->company_id,
                    'site_id' => $eq->site_id ?: $eq->purchased_for_site_id,
                    'project_id' => $eq->project_id,
                    'payment_type' => 'corporate',
                    'category' => '5401 Equipment Rental',
                    'accounting_account' => '5401 Equipment Rental',
                    'description' => "[자동] {$label} 임대료 {$tag} ({$days}일 × {$eq->daily_rate}"
                        .((float) $eq->delivery_fee > 0 && Carbon::parse($eq->rent_start)->between($start, $end) ? ' + 운반비' : '').')'
                        .($eq->vendor ? " · {$eq->vendor}" : ''),
                    'amount' => $amount,
                    'expense_date' => $to->toDateString(),
                ], $counts);
            });

        // ── 숙소: 활성 숙소의 월세 ──────────────────────────────────────
        Housing::query()
            ->where('monthly_rent', '>', 0)
            ->get()
            ->each(function (Housing $h) use ($tag, $end, &$counts): void {
                if (in_array((string) $h->status, ['closed', 'inactive', '종료'], true)) {
                    return;
                }
                $this->upsert("housing:{$h->id}:{$tag}", [
                    'company_id' => $h->company_id,
                    'site_id' => $h->site_id,
                    'payment_type' => 'corporate',
                    'category' => '5503 Crew Lodging & Housing',
                    'accounting_account' => '5503 Crew Lodging & Housing',
                    'description' => "[자동] 숙소 '{$h->name}' 월세 {$tag}",
                    'amount' => (float) $h->monthly_rent,
                    'expense_date' => $end->toDateString(),
                ], $counts);
            });

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{created: int, updated: int, skipped: int}  $counts
     */
    private function upsert(string $sourceRef, array $attributes, array &$counts): void
    {
        $existing = MobileExpense::query()->where('source_ref', $sourceRef)->first();

        if (! $existing) {
            MobileExpense::query()->create($attributes + ['source_ref' => $sourceRef, 'status' => 'pending']);
            $counts['created']++;

            return;
        }

        // 사람이 이미 승인/지급한 건은 손대지 않는다 — 장부 확정 후 소급 변경 금지.
        if ($existing->status !== 'pending') {
            $counts['skipped']++;

            return;
        }

        if ((float) $existing->amount !== (float) $attributes['amount']
            || (string) $existing->description !== (string) $attributes['description']) {
            $existing->update($attributes);
            $counts['updated']++;

            return;
        }

        $counts['skipped']++;
    }
}

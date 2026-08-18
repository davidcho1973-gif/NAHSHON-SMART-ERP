<?php

namespace App\Services\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentRental;
use Illuminate\Support\Facades\DB;

/**
 * 장비 불출·반납 — 이 규칙은 여기 한 곳에만 있다.
 *
 * 규칙 자체는 짧다: 열려 있던 수불을 닫고, 새 수불을 열고, 장비 대장의 상태를 맞춘다.
 * 문제는 그 짧은 규칙이 세 군데에 각각 적혀 있었다는 것이다 — 데스크톱 배정, 데스크톱
 * 반납, 그리고 현장앱. 세 벌이면 언젠가 갈라지고, 갈라진 뒤에는 "이 장비 지금 어디
 * 있나" 에 답이 여러 개가 된다.
 *
 * 현장앱은 심지어 `field_equipment_logs` 라는 자기 표에 장비명을 글자로 적고 있었다.
 * 그 기록은 장비 대장과 이어지지 않아서 <b>임대료가 원가에 안 잡혔다</b> —
 * `RentalExpenseConnector` 는 `equipments.daily_rate` 를 보는데, 현장에서 부른 장비는
 * 거기 없었다. 현장에서 굴착기를 한 달 굴려도 원가는 0원이었다는 뜻이다.
 *
 * 그래서 현장앱도 <b>등록된 장비만</b> 다룬다. 출퇴근이 등록된 직원만 찍게 만든 것과
 * 같은 이유다 — 대장에 없으면 돈으로 이어지지 않는다.
 */
class EquipmentAssignmentService
{
    public const IN_USE = '사용중';

    public const AVAILABLE = '대기중';

    /**
     * 장비를 불출한다(배정). 이미 열린 수불이 있으면 먼저 닫는다.
     *
     * @param  array{company_id?: int|null, team_id?: int|null, employee_id?: int|null, site_id?: int|null, notes?: string|null}  $to
     */
    public function assign(Equipment $equipment, array $to = []): EquipmentRental
    {
        return DB::transaction(function () use ($equipment, $to): EquipmentRental {
            $this->closeOpenRentals($equipment);

            // site_id 를 안 주면 장비가 지금 있는 현장을 그대로 쓴다. 데스크톱 배정은
            // 현장을 안 바꾸고 소속만 바꾸는 흐름이라 기존 동작이 유지된다.
            $siteId = array_key_exists('site_id', $to) && $to['site_id'] !== null
                ? (int) $to['site_id']
                : $equipment->site_id;

            $rental = EquipmentRental::create([
                'equipment_id' => $equipment->id,
                'company_id' => $to['company_id'] ?? null,
                'team_id' => $to['team_id'] ?? null,
                'employee_id' => $to['employee_id'] ?? null,
                'site_id' => $siteId,
                'rented_at' => now(),
                'status' => 'active',
                'notes' => $to['notes'] ?? null,
            ]);

            $equipment->update([
                'company_id' => $to['company_id'] ?? null,
                'team_id' => $to['team_id'] ?? null,
                'employee_id' => $to['employee_id'] ?? null,
                'site_id' => $siteId,
                'status' => self::IN_USE,
            ]);

            return $rental;
        });
    }

    /** 반납한다 — 수불을 닫고 장비를 대기 상태로 되돌린다. */
    public function returnToStock(Equipment $equipment, ?string $note = null): void
    {
        DB::transaction(function () use ($equipment, $note): void {
            $open = EquipmentRental::query()
                ->where('equipment_id', $equipment->id)
                ->whereNull('returned_at')
                ->first();

            if ($open) {
                $open->update([
                    'returned_at' => now(),
                    'status' => 'returned',
                    'notes' => filled($note)
                        ? trim(($open->notes ? $open->notes."\n" : '').'반납시 메모: '.$note)
                        : $open->notes,
                ]);
            }

            // 현장(site_id)은 남긴다. 임대료는 현장별 원가로 잡히므로, 반납했다고
            // 현장을 지우면 그 달의 임대료가 어느 현장 것인지 알 수 없어진다.
            $equipment->update([
                'company_id' => null,
                'team_id' => null,
                'employee_id' => null,
                'status' => self::AVAILABLE,
            ]);
        });
    }

    /** 가동중 ↔ 대기중. 현장에서 "지금 돌고 있나" 를 표시하는 용도. */
    public function toggleRunning(Equipment $equipment): string
    {
        $next = $equipment->status === self::IN_USE ? self::AVAILABLE : self::IN_USE;
        $equipment->update(['status' => $next]);

        return $next;
    }

    private function closeOpenRentals(Equipment $equipment): void
    {
        EquipmentRental::query()
            ->where('equipment_id', $equipment->id)
            ->whereNull('returned_at')
            ->update(['returned_at' => now(), 'status' => 'returned']);
    }
}

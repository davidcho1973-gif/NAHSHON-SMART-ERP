<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Housing;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Services\Finance\RentalExpenseConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 임대 고정비의 자동 원장 계상.
 *
 * 장비 일대·숙소 월세는 대장에 저장만 되고 아무 합산에도 안 잡혔다 — 재무에
 * 잡히려면 사람이 매월 같은 금액을 경비로 다시 쳐야 했다. 이 커넥터가 매월
 * pending 경비를 만들어 주면 재무는 승인만 하면 된다.
 */
class RentalExpenseConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function connector(): RentalExpenseConnector
    {
        return app(RentalExpenseConnector::class);
    }

    private function site(): Site
    {
        return Site::create(['code' => 'AZ-01', 'name' => 'LG PHOENIX', 'timezone' => 'America/Phoenix', 'status' => 'active']);
    }

    public function test_장비_일대가_그_달_경비로_계상된다(): void
    {
        $site = $this->site();
        Equipment::create([
            'equipment_code' => 'EQ-001', 'equipment_type' => '시저리프트', 'model' => 'GS-1932',
            'site_id' => $site->id, 'acquisition_type' => '임대', 'vendor' => 'United Rentals',
            'rent_start' => '2026-08-01', 'rent_end' => '2026-08-31', 'daily_rate' => 100,
        ]);

        $r = $this->connector()->accrueMonth('2026-08');

        $this->assertSame(1, $r['created']);
        $exp = MobileExpense::firstOrFail();
        $this->assertSame(3100.0, (float) $exp->amount, '8월 31일 × $100');
        $this->assertSame('5401 Equipment Rental', $exp->category);
        $this->assertSame('pending', $exp->status, '자동 계상은 사람이 승인하기 전까지 pending');
        $this->assertSame($site->id, $exp->site_id);
        $this->assertStringContainsString('United Rentals', $exp->description);
    }

    public function test_월중_반납이면_겹치는_일수만_계상한다(): void
    {
        $this->site();
        Equipment::create([
            'equipment_code' => 'EQ-002', 'equipment_type' => '용접기', 'model' => 'M-1',
            'rent_start' => '2026-07-20', 'rent_end' => '2026-08-10', 'daily_rate' => 50,
        ]);

        $this->connector()->accrueMonth('2026-08');

        // 8/1 ~ 8/10 = 10일 × 50.
        $this->assertSame(500.0, (float) MobileExpense::firstOrFail()->amount);
    }

    public function test_운반비는_임대_시작_달에_한_번만(): void
    {
        $this->site();
        Equipment::create([
            'equipment_code' => 'EQ-003', 'equipment_type' => '시저리프트', 'model' => 'M-1',
            'rent_start' => '2026-08-15', 'rent_end' => '2026-09-30', 'daily_rate' => 100, 'delivery_fee' => 250,
        ]);

        $this->connector()->accrueMonth('2026-08');
        $this->connector()->accrueMonth('2026-09');

        $aug = MobileExpense::where('source_ref', 'like', '%:2026-08')->firstOrFail();
        $sep = MobileExpense::where('source_ref', 'like', '%:2026-09')->firstOrFail();
        $this->assertSame(1950.0, (float) $aug->amount, '8/15~31 = 17일×100 + 운반비 250');
        $this->assertSame(3000.0, (float) $sep->amount, '9월 30일×100, 운반비 없음');
    }

    public function test_두_번_돌려도_중복_계상되지_않는다(): void
    {
        $this->site();
        Equipment::create([
            'equipment_code' => 'EQ-004', 'equipment_type' => '발전기', 'model' => 'M-1',
            'rent_start' => '2026-08-01', 'daily_rate' => 30,
        ]);

        $this->connector()->accrueMonth('2026-08');
        $r2 = $this->connector()->accrueMonth('2026-08');

        $this->assertSame(0, $r2['created']);
        $this->assertSame(1, MobileExpense::count(), '스케줄이 매일 돌아도 한 달에 한 건이다');
    }

    public function test_요율이_바뀌면_미승인_건만_갱신된다(): void
    {
        $this->site();
        $eq = Equipment::create([
            'equipment_code' => 'EQ-005', 'equipment_type' => '발전기', 'model' => 'M-1',
            'rent_start' => '2026-08-01', 'rent_end' => '2026-08-31', 'daily_rate' => 30,
        ]);
        $this->connector()->accrueMonth('2026-08');

        // 승인 전 요율 수정 → 갱신된다.
        $eq->update(['daily_rate' => 40]);
        $this->connector()->accrueMonth('2026-08');
        $this->assertSame(31 * 40.0, (float) MobileExpense::firstOrFail()->amount);

        // 승인 후에는 요율이 또 바뀌어도 장부를 소급 변경하지 않는다.
        MobileExpense::query()->update(['status' => 'approved']);
        $eq->update(['daily_rate' => 99]);
        $this->connector()->accrueMonth('2026-08');
        $this->assertSame(31 * 40.0, (float) MobileExpense::firstOrFail()->amount);
    }

    public function test_숙소_월세가_경비로_계상된다(): void
    {
        $site = $this->site();
        Housing::create([
            'site_id' => $site->id, 'code' => 'H-01', 'name' => 'Phoenix Crew House',
            'beds' => 8, 'occupied' => 6, 'monthly_rent' => 3200, 'status' => 'active',
        ]);

        $r = $this->connector()->accrueMonth('2026-08');

        $this->assertSame(1, $r['created']);
        $exp = MobileExpense::firstOrFail();
        $this->assertSame(3200.0, (float) $exp->amount);
        $this->assertSame('5503 Crew Lodging & Housing', $exp->category);
    }

    public function test_일대_없는_소유_장비는_계상하지_않는다(): void
    {
        $this->site();
        Equipment::create(['equipment_code' => 'EQ-006', 'equipment_type' => '공구', 'model' => 'M-1', 'acquisition_type' => '소유']);

        $r = $this->connector()->accrueMonth('2026-08');

        $this->assertSame(0, $r['created']);
    }
}

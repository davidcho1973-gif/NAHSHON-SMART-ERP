<?php

namespace Tests\Feature;

use App\Livewire\FieldApp\FieldCommandApp;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentRental;
use App\Models\Site;
use App\Models\User;
use App\Services\Equipment\EquipmentAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 장비 수불 대장은 하나다.
 *
 * 현장앱은 장비명을 <b>글자로 적어</b> `field_equipment_logs` 라는 자기 표에 남겼다.
 * 그 기록은 `equipments` 대장과 아무 관계가 없어서 임대료가 원가에 안 잡혔다 —
 * `RentalExpenseConnector` 는 `equipments.daily_rate` 를 보는데, 현장에서 부른 장비는
 * 거기 없었다. <b>현장에서 굴착기를 한 달 굴려도 원가는 0원이었다.</b>
 *
 * 게다가 불출 규칙이 세 곳에 각각 적혀 있었다(데스크톱 배정 · 데스크톱 반납 · 현장앱).
 * 세 벌이면 언젠가 갈라지고, 갈라진 뒤에는 "이 장비 지금 어디 있나" 에 답이 여러 개가 된다.
 *
 * 여기서 지키는 것은 둘이다 — <b>대장이 하나일 것</b>, 그리고
 * <b>현장에서 부른 장비도 돈으로 이어질 것.</b>
 */
class EquipmentSingleLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::create([
            'code' => 'S-EQ', 'name' => '장비 현장',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function equipment(array $attributes = []): Equipment
    {
        return Equipment::create(array_merge([
            'equipment_code' => 'EQ-'.fake()->unique()->numerify('####'),
            'equipment_type' => '굴착기',
            'model' => 'CAT 320',
            'daily_rate' => 850,
            'status' => EquipmentAssignmentService::AVAILABLE,
        ], $attributes));
    }

    private function admin(): User
    {
        return User::factory()->create(['access_role' => 'super_admin']);
    }

    // ── 그림자 대장이 사라졌는가 ───────────────────────────────────────

    public function test_the_field_app_no_longer_keeps_its_own_equipment_table(): void
    {
        $this->assertFalse(Schema::hasTable('field_equipment_logs'),
            'field_equipment_logs 가 다시 생겼습니다. 글자로 적은 장비는 '
            .'equipments.daily_rate 에 닿지 않아 임대료가 원가에 안 잡힙니다.');
    }

    public function test_the_unused_commute_table_is_gone(): void
    {
        // 현장앱 QR 출퇴근은 이미 attendance_logs 로 간다. 빈 표가 남아 있으면
        // 다음 사람이 "여기에도 쓰라는 뜻인가" 를 묻게 된다.
        $this->assertFalse(Schema::hasTable('field_commute_logs'));
    }

    // ── 현장에서 부른 장비가 돈으로 이어지는가 ─────────────────────────

    public function test_dispatching_from_the_field_app_lands_in_the_real_ledger(): void
    {
        $site = $this->site();
        $equipment = $this->equipment();

        Livewire::actingAs($this->admin())
            ->test(FieldCommandApp::class)
            ->set('site_id', $site->id)
            ->set('new_eq_id', $equipment->id)
            ->call('addEquipment');

        $rental = EquipmentRental::query()->where('equipment_id', $equipment->id)->first();

        $this->assertNotNull($rental, '현장앱 불출이 수불 대장에 안 남았습니다.');
        $this->assertSame($site->id, $rental->site_id);
        $this->assertNull($rental->returned_at);
        $this->assertSame($site->id, $equipment->fresh()->site_id, '장비가 그 현장으로 안 옮겨졌습니다.');
        $this->assertSame(EquipmentAssignmentService::IN_USE, $equipment->fresh()->status);
    }

    public function test_the_daily_rate_that_drives_cost_travels_with_it(): void
    {
        // 이게 없으면 원가는 예전처럼 0원이다.
        $site = $this->site();
        $equipment = $this->equipment(['daily_rate' => 1200]);

        app(EquipmentAssignmentService::class)->assign($equipment, ['site_id' => $site->id]);

        $this->assertSame(1200, (int) $equipment->fresh()->daily_rate);
        $this->assertSame($site->id, (int) $equipment->fresh()->site_id,
            '임대료가 어느 현장 것인지 알 수 없으면 원가로 못 넘깁니다.');
    }

    public function test_it_says_so_when_the_cost_link_would_be_silently_zero(): void
    {
        // 일대나 임대 시작일이 비면 RentalExpenseConnector 가 그 장비를 건너뛴다.
        // 조용히 0원이 되면 몇 달 뒤 정산에서야 알게 되고, 그때는 근거가 없다.
        $site = $this->site();
        $equipment = $this->equipment(['daily_rate' => 0, 'rent_start' => null]);

        $component = Livewire::actingAs($this->admin())
            ->test(FieldCommandApp::class)
            ->set('site_id', $site->id)
            ->set('new_eq_id', $equipment->id)
            ->call('addEquipment');

        $this->assertSame(1, EquipmentRental::query()->count(), '불출 자체는 되어야 합니다.');
        $this->assertStringContainsString('원가로 잡히지 않습니다', (string) $component->get('toastMessage'));
    }

    public function test_a_fully_set_up_machine_gets_no_warning(): void
    {
        $site = $this->site();
        $equipment = $this->equipment(['daily_rate' => 850, 'rent_start' => now()->toDateString()]);

        $component = Livewire::actingAs($this->admin())
            ->test(FieldCommandApp::class)
            ->set('site_id', $site->id)
            ->set('new_eq_id', $equipment->id)
            ->call('addEquipment');

        $this->assertStringNotContainsString('원가로 잡히지 않습니다', (string) $component->get('toastMessage'));
    }

    public function test_an_unregistered_machine_cannot_be_dispatched(): void
    {
        // 글자로 적을 수 있게 두면 대장 밖 장비가 다시 생기고, 그게 원인이었다.
        $site = $this->site();

        $component = Livewire::actingAs($this->admin())
            ->test(FieldCommandApp::class)
            ->set('site_id', $site->id)
            ->set('new_eq_id', null)
            ->call('addEquipment');

        $this->assertSame(0, EquipmentRental::query()->count());
        $this->assertStringContainsString('골라', (string) $component->get('toastMessage'));
    }

    // ── 규칙이 한 곳에만 있는가 ────────────────────────────────────────

    public function test_dispatching_twice_does_not_leave_two_open_rentals(): void
    {
        // 열린 수불이 둘이면 같은 장비의 임대료가 두 번 잡힌다.
        $site = $this->site();
        $other = Site::create([
            'code' => 'S-EQ2', 'name' => '두번째 현장',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $equipment = $this->equipment();

        $service = app(EquipmentAssignmentService::class);
        $service->assign($equipment, ['site_id' => $site->id]);
        $service->assign($equipment, ['site_id' => $other->id]);

        $this->assertSame(1, EquipmentRental::query()
            ->where('equipment_id', $equipment->id)->whereNull('returned_at')->count());
        $this->assertSame(2, EquipmentRental::query()->where('equipment_id', $equipment->id)->count(),
            '이전 수불 이력이 사라졌습니다 — 그 기간의 임대료 근거가 없어집니다.');
    }

    public function test_returning_keeps_the_site_so_the_cost_still_has_an_owner(): void
    {
        // 반납하면서 현장을 지우면 그 달의 임대료가 어느 현장 것인지 알 수 없어진다.
        $site = $this->site();
        $equipment = $this->equipment();

        $service = app(EquipmentAssignmentService::class);
        $service->assign($equipment, ['site_id' => $site->id]);
        $service->returnToStock($equipment, '현장앱 반납');

        $fresh = $equipment->fresh();
        $this->assertSame(EquipmentAssignmentService::AVAILABLE, $fresh->status);
        $this->assertSame($site->id, (int) $fresh->site_id, '반납이 현장을 지웠습니다.');
        $this->assertNull($fresh->employee_id);
    }

    public function test_returning_from_the_field_app_does_not_delete_the_history(): void
    {
        // 예전 현장앱의 X 버튼은 기록을 지웠다. 지우면 그 기간의 임대료 근거가 사라진다.
        $site = $this->site();
        $equipment = $this->equipment();
        app(EquipmentAssignmentService::class)->assign($equipment, ['site_id' => $site->id]);

        Livewire::actingAs($this->admin())
            ->test(FieldCommandApp::class)
            ->set('site_id', $site->id)
            ->call('removeEquipment', $equipment->id);

        $rental = EquipmentRental::query()->where('equipment_id', $equipment->id)->first();

        $this->assertNotNull($rental, '반납이 수불 이력을 지웠습니다.');
        $this->assertNotNull($rental->returned_at);
        $this->assertSame('returned', $rental->status);
    }

    public function test_the_assignment_rule_lives_in_exactly_one_place(): void
    {
        // 규칙이 세 곳에 적혀 있던 것이 원인이었다. 다시 흩어지면 여기서 잡는다.
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/EquipmentApiController.php'));
        $fieldApp = (string) file_get_contents(base_path('app/Livewire/FieldApp/FieldCommandApp.php'));

        foreach (['EquipmentApiController' => $controller, 'FieldCommandApp' => $fieldApp] as $name => $source) {
            $this->assertStringNotContainsString("EquipmentRental::create", $source,
                "{$name} 이 수불을 직접 만들고 있습니다. 불출·반납 규칙은 "
                ."EquipmentAssignmentService 한 곳에만 둡니다 — 세 벌이던 것이 원인이었습니다.");
        }
    }

    // ── 조종원이 실제 사람인가 ─────────────────────────────────────────

    public function test_an_operator_who_is_not_on_the_roster_is_refused(): void
    {
        // 이름만 적히면 그 사람이 누구인지 아무도 모른다. 출퇴근을 등록된 직원만
        // 찍게 만든 것과 같은 이유다.
        $site = $this->site();
        $equipment = $this->equipment();

        $component = Livewire::actingAs($this->admin())
            ->test(FieldCommandApp::class)
            ->set('site_id', $site->id)
            ->set('new_eq_id', $equipment->id)
            ->set('new_eq_operator', '없는사람')
            ->call('addEquipment');

        $this->assertSame(0, EquipmentRental::query()->count());
        $this->assertStringContainsString('찾지 못했습니다', (string) $component->get('toastMessage'));
    }

    public function test_a_registered_operator_is_attached_to_the_rental(): void
    {
        $site = $this->site();
        $equipment = $this->equipment();
        $employee = Employee::create([
            'name' => '김기사',
            'site_id' => $site->id,
            'employment_status' => 'active',
        ]);

        Livewire::actingAs($this->admin())
            ->test(FieldCommandApp::class)
            ->set('site_id', $site->id)
            ->set('new_eq_id', $equipment->id)
            ->set('new_eq_operator', '김기사')
            ->call('addEquipment');

        $rental = EquipmentRental::query()->where('equipment_id', $equipment->id)->firstOrFail();

        $this->assertSame($employee->id, $rental->employee_id);
    }
}

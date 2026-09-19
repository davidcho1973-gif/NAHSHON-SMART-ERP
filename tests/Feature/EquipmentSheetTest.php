<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentChecklistLog;
use App\Models\EquipmentRental;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\EquipmentSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 자재·장비 대장 정리 — 내보내기 · 골라 지우기 · 전부 비우기.
 *
 * ── 물량/BOQ 와 다른 점, 그리고 이 시험들이 지키는 것 ──────────────────
 * BOQ 는 아무도 가리키지 않아 지워도 딸려 깨지는 표가 없었다. <b>장비는 다르다.</b>
 * 데이터베이스에 직접 물어보니 두 표가 장비를 CASCADE 로 가리킨다:
 *
 *   · equipment_rentals        — 불출·반납 이력 (임대료가 원가로 잡히는 근거)
 *   · equipment_checklist_logs — QR 사용 전/반납 점검 기록 (사고 조사에서 쓰는 것)
 *
 * 장비를 지우면 그것들이 <b>말없이</b> 같이 사라진다. 그래서:
 *  ① 지우기 전에 «장비 몇 대, 이력 몇 건, 점검 기록 몇 건» 을 세어 돌려준다.
 *     숫자를 안 보여 주고 지우게 하면 나중에 «점검 기록 어디 갔냐» 에 아무도
 *     답하지 못한다.
 *  ② 지우기 직전의 대장을 CSV 로 손에 쥐여 준다.
 *  ③ 범위를 잘못 읽어 «한 현장만» 이 «전부» 가 되는 일이 없어야 한다.
 */
class EquipmentSheetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $siteA;

    private Site $siteB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
        $this->siteA = $this->makeSite('S-A', 'A 현장');
        $this->siteB = $this->makeSite('S-B', 'B 현장');

        $this->admin = User::query()->create([
            'name' => 'Boss', 'email' => 'boss@example.test', 'password' => Hash::make('x'),
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
        $this->actingAs($this->admin);
    }

    private function makeSite(string $code, string $name): Site
    {
        return Site::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'name' => $name,
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function svc(): EquipmentSheetService
    {
        return app(EquipmentSheetService::class);
    }

    private function gear(Site $site, string $type = 'Excavator 320', string $group = 'equipment'): Equipment
    {
        return Equipment::query()->create([
            'company_id' => $this->company->id, 'site_id' => $site->id,
            'equipment_type' => $type, 'model' => 'CAT 320',
            'category_group' => $group, 'trade' => 'heavy',
            'status' => Equipment::STATUS_AVAILABLE,
        ]);
    }

    /** 이 장비에 이력과 점검 기록을 하나씩 달아 둔다 — 그것들이 같이 사라지는지 보기 위해. */
    private function withHistory(Equipment $equipment): Equipment
    {
        $employee = Employee::query()->create([
            'company_id' => $this->company->id, 'site_id' => $equipment->site_id,
            'employee_number' => 'W-'.$equipment->id, 'first_name' => 'A', 'last_name' => 'B', 'name' => 'A B',
            'employment_status' => 'active',
        ]);

        EquipmentRental::query()->create([
            'equipment_id' => $equipment->id, 'company_id' => $this->company->id,
            'employee_id' => $employee->id, 'site_id' => $equipment->site_id,
            'rented_at' => now()->subDay(), 'status' => 'active',
        ]);

        EquipmentChecklistLog::query()->create([
            'equipment_id' => $equipment->id, 'company_id' => $this->company->id,
            'site_id' => $equipment->site_id, 'employee_id' => $employee->id,
            'stage' => 'pre_use', 'result' => EquipmentChecklistLog::PASS,
            'answers' => [], 'submitted_at' => now(),
        ]);

        return $equipment;
    }

    // ── 내보내기 ────────────────────────────────────────────────────────

    public function test_export_writes_every_asset_into_the_sheet(): void
    {
        $this->gear($this->siteA, '굴착기');
        $this->gear($this->siteB, '그라인더');

        $r = $this->svc()->export();

        $this->assertTrue($r['success']);
        $this->assertSame(2, $r['count']);
        $this->assertStringContainsString('굴착기', $r['csv']);
        $this->assertStringContainsString('그라인더', $r['csv']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $r['csv'], 'BOM 이 없으면 엑셀이 한글 품명을 깨서 연다.');
    }

    // ── 딸려 지워지는 것 ────────────────────────────────────────────────

    public function test_the_count_says_what_else_will_disappear(): void
    {
        // 이 시험이 이 작업의 핵심이다. 장비만 세어 보여 주고 지우게 하면,
        // 나중에 «점검 기록 어디 갔냐» 에 아무도 답하지 못한다.
        $this->withHistory($this->gear($this->siteA));
        $this->withHistory($this->gear($this->siteA));

        $p = $this->svc()->clear('ALL', 'ALL', '', true);

        $this->assertSame(2, $p['count']);
        $this->assertSame(2, $p['rentals'], '불출·반납 이력이 함께 사라진다는 것을 말해 줘야 한다.');
        $this->assertSame(2, $p['checks'], 'QR 점검 기록이 함께 사라진다는 것을 말해 줘야 한다.');
        $this->assertSame(2, Equipment::query()->count(), '세기만 할 때는 아무것도 지우지 않는다.');
    }

    public function test_deleting_really_does_take_the_history_with_it(): void
    {
        // 화면이 «함께 사라집니다» 라고 말했으면 실제로 그래야 한다. 반대로,
        // 말만 하고 남아 있으면 고아 기록이 쌓인다.
        $this->withHistory($this->gear($this->siteA));

        $this->assertSame(1, EquipmentRental::query()->count());
        $this->assertSame(1, EquipmentChecklistLog::query()->count());

        $this->svc()->clear('ALL', 'ALL', '전부 삭제');

        $this->assertSame(0, Equipment::query()->count());
        $this->assertSame(0, EquipmentRental::query()->count());
        $this->assertSame(0, EquipmentChecklistLog::query()->count());
    }

    // ── 비우기 ──────────────────────────────────────────────────────────

    public function test_clearing_needs_the_phrase_typed_out(): void
    {
        $this->gear($this->siteA);
        $this->gear($this->siteA);

        $this->assertFalse($this->svc()->clear('ALL', 'ALL', '네')['success']);
        $this->assertFalse($this->svc()->clear('ALL', 'ALL', '')['success']);
        $this->assertSame(2, Equipment::query()->count());

        $this->assertTrue($this->svc()->clear('ALL', 'ALL', '전부 삭제')['success']);
        $this->assertSame(0, Equipment::query()->count());
    }

    public function test_what_was_deleted_comes_back_in_your_hand(): void
    {
        $this->gear($this->siteA, '굴착기');

        $r = $this->svc()->clear('ALL', 'ALL', '전부 삭제');

        $this->assertNotEmpty($r['backupCsv']);
        $this->assertStringContainsString('굴착기', $r['backupCsv']);
        $this->assertStringContainsString('삭제전백업', $r['backupName']);
    }

    public function test_one_site_is_one_site_and_not_everything(): void
    {
        // 범위를 잘못 읽어 «A 현장만» 이 «전부» 가 되면, 한 현장을 정리하려다
        // 회사 전체 자산을 날린다.
        $this->gear($this->siteA);
        $this->gear($this->siteA);
        $this->gear($this->siteB);

        $this->svc()->clear($this->siteA->code, 'ALL', '전부 삭제');

        $this->assertSame(1, Equipment::query()->count());
        $this->assertSame($this->siteB->id, Equipment::query()->value('site_id'));
    }

    public function test_an_unknown_site_code_deletes_nothing(): void
    {
        // 없는 코드를 «전체» 로 읽으면 한 현장만 지우려다 전부 지운다.
        $this->gear($this->siteA);
        $this->gear($this->siteB);

        $r = $this->svc()->clear('없는코드', 'ALL', '', true);

        $this->assertSame(0, $r['count']);
        $this->assertFalse($this->svc()->clear('없는코드', 'ALL', '전부 삭제')['success']);
        $this->assertSame(2, Equipment::query()->count());
    }

    public function test_it_can_wipe_only_one_category(): void
    {
        $this->gear($this->siteA, '굴착기', 'equipment');
        $this->gear($this->siteA, '전선관', 'material');

        $this->svc()->clear('ALL', 'material', '전부 삭제');

        $this->assertSame(1, Equipment::query()->count());
        $this->assertSame('굴착기', Equipment::query()->value('equipment_type'));
    }

    // ── 골라 지우기 ─────────────────────────────────────────────────────

    public function test_only_the_ticked_ones_go(): void
    {
        $keep = $this->gear($this->siteA, '남길 것');
        $goA = $this->gear($this->siteA, '지울 것 1');
        $goB = $this->gear($this->siteA, '지울 것 2');

        $r = $this->svc()->deleteMany([$goA->id, $goB->id]);

        $this->assertTrue($r['success']);
        $this->assertSame(2, $r['count']);
        $this->assertSame([$keep->id], Equipment::query()->pluck('id')->all());
        $this->assertStringContainsString('지울 것 1', $r['backupCsv']);
    }

    public function test_picking_nothing_is_refused(): void
    {
        $this->gear($this->siteA);

        $this->assertFalse($this->svc()->deleteMany([])['success']);
        $this->assertFalse($this->svc()->deleteMany([0, null, ''])['success']);
        $this->assertSame(1, Equipment::query()->count());
    }

    // ── 권한 ────────────────────────────────────────────────────────────

    public function test_a_site_manager_may_tidy_but_may_not_empty_the_register(): void
    {
        $a = $this->gear($this->siteA);
        $this->gear($this->siteA);

        $manager = User::query()->create([
            'name' => 'M', 'email' => 'm@example.test', 'password' => Hash::make('x'),
            'access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
        $this->actingAs($manager);

        $this->assertTrue($this->svc()->canManage());
        $this->assertFalse($this->svc()->canClear());

        $this->assertFalse($this->svc()->clear('ALL', 'ALL', '전부 삭제')['success']);
        $this->assertSame(2, Equipment::query()->count());

        // 골라 지우는 것은 할 수 있어야 한다 — 매일의 정리다.
        $this->assertTrue($this->svc()->deleteMany([$a->id])['success']);
        $this->assertSame(1, Equipment::query()->count());
    }

    public function test_a_worker_cannot_touch_the_register(): void
    {
        $gear = $this->gear($this->siteA);
        $worker = User::query()->create([
            'name' => 'W', 'email' => 'w@example.test', 'password' => Hash::make('x'),
            'access_role' => 'worker', 'access_scope' => 'assigned_sites', 'account_status' => 'active',
        ]);
        $this->actingAs($worker);

        $this->assertFalse($this->svc()->export()['success']);
        $this->assertFalse($this->svc()->deleteMany([$gear->id])['success']);
        $this->assertFalse($this->svc()->clear('ALL', 'ALL', '전부 삭제')['success']);
        $this->assertSame(1, Equipment::query()->count());
    }

    // ── 경로 ────────────────────────────────────────────────────────────

    public function test_the_whole_round_trip_works_through_the_real_api(): void
    {
        // 서비스만 시험하면 디스패치 오타(이름·인자 순서)가 그대로 배포된다.
        $this->withHistory($this->gear($this->siteA, '굴착기'));

        $call = fn (string $method, array $args) => $this->postJson(
            '/smart-company-api/'.$method, ['args' => $args],
        );

        $call('api_exportEquipment', ['ALL'])->assertOk()->assertJson(['success' => true, 'count' => 1]);

        $call('api_clearEquipment', ['ALL', '', true])
            ->assertOk()->assertJson(['success' => true, 'count' => 1, 'rentals' => 1, 'checks' => 1]);
        $this->assertSame(1, Equipment::query()->count());

        $call('api_clearEquipment', ['ALL', '네', false])->assertOk()->assertJson(['success' => false]);
        $this->assertSame(1, Equipment::query()->count());

        $call('api_clearEquipment', ['ALL', '전부 삭제', false])
            ->assertOk()->assertJson(['success' => true, 'count' => 1]);
        $this->assertSame(0, Equipment::query()->count());
    }

    public function test_the_screen_offers_all_of_this(): void
    {
        // 서버가 받을 준비가 돼 있어도 화면에 길이 없으면 아무도 못 쓴다.
        $html = (string) file_get_contents(base_path('resources/views/smart-company/index.blade.php'));

        foreach (['api_exportEquipment', 'api_clearEquipment', 'api_deleteEquipmentMany',
            '표로 내보내기(백업)', '전체 비우기', '선택 삭제', 'toggleEquipmentPick'] as $needle) {
            $this->assertStringContainsString($needle, $html, "화면에 {$needle} 가 없습니다.");
        }
    }

    public function test_the_screen_is_told_whether_to_show_the_wipe_button(): void
    {
        $r = app(\App\Services\Inventory\InventoryService::class)->dashboard('ALL');

        $this->assertTrue($r['canClear'], '최고관리자에게는 보여야 한다.');

        $this->actingAs(User::query()->create([
            'name' => 'M', 'email' => 'm2@example.test', 'password' => Hash::make('x'),
            'access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        $this->assertFalse(
            app(\App\Services\Inventory\InventoryService::class)->dashboard('ALL')['canClear'],
            '누를 수 없는 버튼을 보여 주면 «왜 안 되냐» 를 묻게 된다.',
        );
    }
}

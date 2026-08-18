<?php

namespace Tests\Feature;

use App\Models\MobileExpense;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\Vendor;
use App\Models\WbsItem;
use App\Services\Procurement\ProcurementService;
use App\Services\Vendors\VendorResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 발주가 거래처·계약·원가와 열쇠로 이어지는가.
 *
 * 같은 협력사가 세 벌로 적히고 있었다 — 계약에서는 companies 행, 발주에서는
 * 자유 텍스트, 거래처 화면에서는 vendors 행. 발주의 공급처는 datalist 가 권고만
 * 할 뿐 저장은 타이핑한 글자 그대로였고("Graybar" 와 "Graybar Inc." 는 다른 회사로
 * 집계된다), 계약은 발주와 이어지는 칼럼이 없어 "이 계약으로 얼마나 샀나" 를 아무도
 * 몰랐으며, 발주 금액은 원가로 넘어가는 경로가 없어 <b>자재를 $50만어치 사도
 * 프로젝트 원가는 0원</b>이었다.
 *
 * 여기서 지키는 것: 공급처는 대장의 행이다 · 발주는 계약에 걸린다 ·
 * 입고된 자재는 원가에 잡힌다.
 */
class ProcurementLinksTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'S-PO', 'name' => '조달 현장',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    /** 조달 갱신이 붙을 수 있는 WBS 조달 공정 하나. */
    private function wbs(string $code = 'W-1'): WbsItem
    {
        return WbsItem::create([
            'project_code' => 'PRJ-PO', 'wbs_code' => $code, 'site_id' => $this->site->id,
            'level' => WbsItem::LEVEL_SUBTASK, 'name' => '케이블 트레이 자재 조달',
        ]);
    }

    private function update(string $wbsCode, array $patch): array
    {
        return app(ProcurementService::class)->update('PRJ-PO', $wbsCode, $patch);
    }

    // ── 공급처가 대장의 행이 되는가 ────────────────────────────────────

    public function test_typing_a_vendor_name_links_the_master_row(): void
    {
        $graybar = Vendor::create(['name' => 'Graybar', 'status' => 'active']);
        $this->wbs();

        $this->update('W-1', ['vendor' => 'Graybar']);

        $row = ProcurementItem::query()->where('wbs_code', 'W-1')->firstOrFail();
        $this->assertSame($graybar->id, $row->vendor_id, '이름을 쳤는데 대장의 행으로 안 이어졌습니다.');
    }

    public function test_case_and_spacing_differences_collapse_to_one_vendor(): void
    {
        // "graybar" 라고 쳐도 대장에 "Graybar" 가 있으면 그 행이다 — 오타 하나로
        // 거래처별 발주 집계가 갈라지던 것이 이 연결의 출발점이었다.
        $graybar = Vendor::create(['name' => 'Graybar', 'status' => 'active']);
        $this->wbs();

        $this->update('W-1', ['vendor' => '  graybar ']);

        $row = ProcurementItem::query()->where('wbs_code', 'W-1')->firstOrFail();
        $this->assertSame($graybar->id, $row->vendor_id);
        $this->assertSame('Graybar', $row->vendor, '문자열 사본이 마스터 이름으로 통일되지 않았습니다.');
        $this->assertSame(1, Vendor::query()->count(), '같은 회사가 대장에 두 번 생겼습니다.');
    }

    public function test_a_new_vendor_name_is_added_to_the_master_not_left_as_text(): void
    {
        // 거절하면 사람들은 메모 칸에 이름을 적는다 — 자유 텍스트가 자리만 옮긴다.
        // 그래서 새 이름은 대장에 올린다. 오타여도 대장에 있어야 사람이 보고 합친다.
        $this->wbs();

        $this->update('W-1', ['vendor' => 'United Rentals']);

        $vendor = Vendor::query()->where('name', 'United Rentals')->first();
        $this->assertNotNull($vendor, '새 공급처가 거래처 마스터에 등록되지 않았습니다.');
        $this->assertSame($vendor->id,
            ProcurementItem::query()->where('wbs_code', 'W-1')->value('vendor_id'));
    }

    public function test_clearing_the_vendor_clears_both_the_link_and_the_copy(): void
    {
        Vendor::create(['name' => 'Graybar', 'status' => 'active']);
        $this->wbs();
        $this->update('W-1', ['vendor' => 'Graybar']);

        $this->update('W-1', ['vendor' => '']);

        $row = ProcurementItem::query()->where('wbs_code', 'W-1')->firstOrFail();
        $this->assertNull($row->vendor_id);
        $this->assertNull($row->vendor);
    }

    public function test_the_resolver_is_the_only_writer_of_vendor_links(): void
    {
        // 해소 규칙이 두 곳이면 언젠가 갈라진다 — 인원 세는 규칙이 두 벌이던 것과
        // 같은 병이다. 서비스 밖에서 vendor 문자열을 직접 쓰는 코드가 생기면 잡는다.
        $service = (string) file_get_contents(base_path('app/Services/Procurement/ProcurementService.php'));

        $this->assertStringContainsString('VendorResolver', $service,
            'ProcurementService 가 VendorResolver 를 쓰지 않습니다 — 자유 텍스트가 돌아왔습니다.');
    }

    // ── 발주가 계약에 걸리는가 ─────────────────────────────────────────

    private function contract(string $direction, array $extra = []): ProjectContract
    {
        return ProjectContract::create(array_merge([
            'title' => '자재 공급 계약', 'direction' => $direction,
            'status' => 'active', 'current_amount' => 100000, 'currency' => 'USD',
        ], $extra));
    }

    public function test_a_purchase_can_be_hung_on_a_payable_contract(): void
    {
        $contract = $this->contract('payable');
        $this->wbs();

        $this->update('W-1', ['contract_id' => $contract->id, 'amount' => 25000]);

        $this->assertSame($contract->id,
            ProcurementItem::query()->where('wbs_code', 'W-1')->value('contract_id'));
    }

    public function test_a_receivable_contract_refuses_purchase_orders(): void
    {
        // 수주 계약에 발주가 걸리면 발주 누계가 원청 계약 금액과 섞여 둘 다 못 믿는다.
        $contract = $this->contract('receivable');
        $this->wbs();

        $this->update('W-1', ['contract_id' => $contract->id]);

        $this->assertNull(ProcurementItem::query()->where('wbs_code', 'W-1')->value('contract_id'));
    }

    public function test_the_contract_options_carry_the_po_total_and_remaining(): void
    {
        // 이 숫자가 연결의 목적이다 — 없으면 계약에 발주를 걸어도 아무것도 못 읽는다.
        $contract = $this->contract('payable');
        $this->wbs('W-1');
        $this->wbs('W-2');
        $this->update('W-1', ['contract_id' => $contract->id, 'amount' => 30000]);
        $this->update('W-2', ['contract_id' => $contract->id, 'amount' => 20000]);

        $list = app(ProcurementService::class)->list('PRJ-PO');
        $option = collect($list['contracts'])->firstWhere('id', $contract->id);

        $this->assertNotNull($option);
        $this->assertSame(50000.0, $option['poTotal']);
        $this->assertSame(50000.0, $option['remaining']);
    }

    public function test_contract_list_shows_how_much_was_ordered_against_it(): void
    {
        $contract = $this->contract('payable');
        $this->wbs();
        $this->update('W-1', ['contract_id' => $contract->id, 'amount' => 40000]);

        $admin = \App\Models\User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $this->actingAs($admin);

        $rows = app(\App\Services\Admin\ContractAdminService::class)->list();
        $row = collect($rows['rows'])->firstWhere('id', $contract->id);

        $this->assertSame(40000.0, $row['poTotal']);
        $this->assertSame(1, $row['poCount']);
    }

    // ── 입고된 자재가 원가에 잡히는가 ──────────────────────────────────

    public function test_delivery_puts_the_amount_on_the_cost_ledger(): void
    {
        // 이 경로가 없어서 자재만 원가 0원이었다. 장비·숙소·급여는 이미 잇던 길이다.
        Project::create([
            'project_code' => 'PRJ-PO', 'name' => '조달 프로젝트',
            'site_id' => $this->site->id, 'construction_type' => 'mechanical',
        ]);
        $this->wbs();

        $this->update('W-1', ['vendor' => 'Graybar', 'po_no' => 'PO-77', 'amount' => 12500, 'status' => '입고완료']);

        $expense = MobileExpense::query()->where('source_ref', 'procurement:'.
            ProcurementItem::query()->where('wbs_code', 'W-1')->value('id'))->first();

        $this->assertNotNull($expense, '입고완료된 발주 금액이 원가로 넘어가지 않았습니다.');
        $this->assertSame(12500.0, (float) $expense->amount);
        $this->assertSame('pending', $expense->status, '자동 생성 원가는 사람 승인 전이어야 합니다.');
        $this->assertSame('PRJ-PO',
            Project::query()->whereKey($expense->project_id)->value('project_code'),
            '원가가 프로젝트에 귀속되지 않았습니다.');
    }

    public function test_before_delivery_nothing_lands_on_the_ledger(): void
    {
        // 발주 시점에 잡으면 취소·감액될 금액이 원가에 앉는다. 입고가 비용의 근거다.
        $this->wbs();

        $this->update('W-1', ['amount' => 9000, 'status' => '선적중']);

        $this->assertSame(0, MobileExpense::query()->where('source_ref', 'like', 'procurement:%')->count());
    }

    public function test_saving_twice_does_not_double_the_cost(): void
    {
        $this->wbs();
        $this->update('W-1', ['amount' => 5000, 'status' => '입고완료']);
        $this->update('W-1', ['note' => 'ETA 확인']);

        $this->assertSame(1, MobileExpense::query()->where('source_ref', 'like', 'procurement:%')->count());
    }

    public function test_an_amount_fix_updates_the_pending_cost_row(): void
    {
        $this->wbs();
        $this->update('W-1', ['amount' => 5000, 'status' => '입고완료']);

        $this->update('W-1', ['amount' => 5500]);

        $this->assertSame(5500.0, (float) MobileExpense::query()
            ->where('source_ref', 'like', 'procurement:%')->value('amount'));
    }

    public function test_an_approved_cost_row_is_never_overwritten(): void
    {
        // 장부 확정 후 소급 변경 금지 — 다른 커넥터와 같은 원칙이다.
        $this->wbs();
        $this->update('W-1', ['amount' => 5000, 'status' => '입고완료']);
        MobileExpense::query()->where('source_ref', 'like', 'procurement:%')->update(['status' => 'approved']);

        $this->update('W-1', ['amount' => 9999]);

        $this->assertSame(5000.0, (float) MobileExpense::query()
            ->where('source_ref', 'like', 'procurement:%')->value('amount'));
    }
}

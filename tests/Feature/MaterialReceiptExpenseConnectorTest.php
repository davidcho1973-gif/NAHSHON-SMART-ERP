<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialReceipt;
use App\Models\MobileExpense;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\MaterialReceiptExpenseConnector;
use App\Services\Inventory\MaterialReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 확정된 자재 입고 → 회계 대기(경비 원장 pending). 사장이 보류했다 풀어 준 연결.
 *
 * 잠그는 것: 확정해야 넘어간다, 단가 없는 입고는 «모름» 이라 안 넘어간다, 두 번 확정해도
 * 한 건이다, 확정을 풀면 대기에서 빠지되 승인된 건은 남는다, 같은 PO 가 조달에서 이미
 * 갔으면 두 번 넣지 않는다.
 */
class MaterialReceiptExpenseConnectorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'legal_name' => 'ABC ENG LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => '703K', 'name' => 'Savannah',
            'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']));
    }

    private function svc(): MaterialReceiptService
    {
        return app(MaterialReceiptService::class);
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function receipt(array $lines, array $extra = []): int
    {
        $res = $this->svc()->save(array_merge([
            'site_id' => $this->site->id, 'received_on' => '2026-09-22', 'vendor' => 'Graybar', 'po_no' => 'PO-77', 'lines' => $lines,
        ], $extra), '703K');
        $this->assertTrue($res['success'], json_encode($res, JSON_UNESCAPED_UNICODE));

        return (int) $res['id'];
    }

    private function ledger(int $id): ?MobileExpense
    {
        return MobileExpense::query()->where('source_ref', "material-receipt:{$id}")->first();
    }

    public function test_confirming_a_priced_receipt_puts_its_total_in_the_pending_ledger(): void
    {
        $vendor = Vendor::create(['name' => 'Graybar', 'status' => 'active']);
        $id = $this->receipt([
            ['name' => 'EMT 3/4"', 'quantity' => 100, 'unit' => 'ea', 'unit_price' => 2.5],
            ['name' => '커플링', 'quantity' => 40, 'unit_price' => 0.75],
            ['name' => '테이프', 'quantity' => 3],                       // 단가 없음 — 합에서 빠진다
        ]);
        $this->assertNull($this->ledger($id), '확정 전에는 장부가 아니다');

        $res = $this->svc()->confirm($id);

        $this->assertTrue($res['success']);
        $this->assertTrue($res['finance']['posted']);
        $this->assertSame(280.0, $res['finance']['amount']);
        $e = $this->ledger($id);
        $this->assertNotNull($e);
        $this->assertSame('pending', $e->status, '자동 건은 회계 대기 — 사람이 승인해야 장부');
        $this->assertSame(280.0, (float) $e->amount);
        $this->assertSame('5201 Job Materials', $e->accounting_account);
        $this->assertSame($vendor->id, $e->vendor_id);
        $this->assertSame($this->site->id, $e->site_id);
        $this->assertSame($this->company->id, $e->company_id);
        $this->assertSame('2026-09-22', $e->expense_date->toDateString(), '비용 날짜는 입고일');
        $this->assertStringContainsString('EMT 3/4" 외 2건', $e->description);
        $this->assertStringContainsString('PO PO-77', $e->description);
    }

    public function test_a_receipt_without_any_unit_price_is_unknown_not_zero(): void
    {
        $id = $this->receipt([['name' => 'EMT', 'quantity' => 100]]);

        $res = $this->svc()->confirm($id);

        $this->assertTrue($res['success']);
        $this->assertFalse($res['finance']['posted']);
        $this->assertStringContainsString('단가가 없어', $res['finance']['note']);
        $this->assertNull($this->ledger($id), '0 달러가 원가가 되면 그 자재는 공짜였던 것이 된다');
    }

    public function test_confirming_twice_makes_one_entry_and_unconfirming_removes_a_pending_one(): void
    {
        $id = $this->receipt([['name' => 'EMT', 'quantity' => 10, 'unit_price' => 5]]);

        $this->svc()->confirm($id);
        $this->svc()->confirm($id);
        app(MaterialReceiptExpenseConnector::class)->sync(MaterialReceipt::findOrFail($id));
        $this->assertSame(1, MobileExpense::query()->count(), '멱등 — 몇 번을 돌려도 한 건');

        $this->svc()->confirm($id, false);
        $this->assertNull($this->ledger($id), '확정을 풀면 회계 대기에서 빠진다');

        $this->svc()->confirm($id);
        $this->assertNotNull($this->ledger($id), '다시 확정하면 다시 간다');
    }

    public function test_an_approved_entry_survives_unconfirming(): void
    {
        $id = $this->receipt([['name' => 'EMT', 'quantity' => 10, 'unit_price' => 5]]);
        $this->svc()->confirm($id);
        $this->ledger($id)->forceFill(['status' => 'approved'])->save();

        $this->svc()->confirm($id, false);

        $this->assertSame('approved', $this->ledger($id)->status, '장부에 확정된 건은 소급해서 지우지 않는다');
    }

    public function test_a_po_already_costed_from_procurement_is_not_counted_twice(): void
    {
        $po = ProcurementItem::create(['site_id' => $this->site->id, 'project_code' => 'P1', 'wbs_code' => 'P1-W-1',
            'po_no' => 'po-77', 'status' => '입고완료', 'vendor' => 'Graybar', 'amount' => 300]);
        MobileExpense::create(['site_id' => $this->site->id, 'source_ref' => "procurement:{$po->id}", 'status' => 'pending',
            'payment_type' => 'corporate', 'category' => '5201 Job Materials', 'accounting_account' => '5201 Job Materials',
            'description' => '[자동] 자재 입고', 'amount' => 300, 'expense_date' => '2026-09-20']);
        $id = $this->receipt([['name' => 'EMT', 'quantity' => 100, 'unit_price' => 3]]);

        $res = $this->svc()->confirm($id);

        $this->assertTrue($res['success']);
        $this->assertFalse($res['finance']['posted']);
        $this->assertStringContainsString('두 번 넣지 않았습니다', $res['finance']['note']);
        $this->assertSame(1, MobileExpense::query()->count(), '한 트럭은 한 번만 원가가 된다');
    }
}

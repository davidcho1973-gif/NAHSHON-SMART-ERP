<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 영수증 앱 — 사진 한 장이 ERP 등록과 똑같은 경비(승인대기)가 되는가.
 */
class ExpenseAppTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.gemini.api_key' => 'test-gemini-key', 'services.gemini.model' => 'gemini-2.5-flash']);

        $company = Company::create(['code' => 'C1', 'name' => '자사', 'status' => 'active']);
        $site = Site::create(['code' => 'S1', 'name' => '현장', 'status' => 'active']);
        $this->employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'first_name' => '수', 'last_name' => '김', 'name' => '김수', 'employment_status' => 'active',
        ]);
        $this->user = User::factory()->create(['employee_id' => $this->employee->id, 'access_role' => 'worker', 'account_status' => 'active']);
    }

    private function fakeOcr(array $fields): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($fields)]]]]],
        ])]);
    }

    private function submit(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post(route('expense-app.submit'), array_merge([
            'receipt' => UploadedFile::fake()->image('receipt.jpg', 800, 1200),
            'payment_type' => 'personal',
            'lang' => 'ko',
        ], $extra));
    }

    public function test_사진_한_장이_ERP_등록과_같은_승인대기_경비가_된다(): void
    {
        $this->fakeOcr([
            'vendor_name' => 'Home Depot', 'amount' => 84.2, 'date' => '2026-08-24',
            'accounting_account' => '5201 Job Materials', 'category' => '5201 Job Materials',
            'description' => 'PVC pipe, fittings', 'handwritten_notes' => '',
        ]);

        $res = $this->submit();

        $res->assertOk()->assertJson(['success' => true]);
        $expense = MobileExpense::query()->first();
        $this->assertNotNull($expense);
        $this->assertSame('pending', $expense->status, '자동은 승인대기까지 — 승인은 재무가 한다');
        $this->assertSame(84.2, (float) $expense->amount);
        $this->assertSame('5201 Job Materials', $expense->accounting_account, '계정과목 정본을 지난다');
        $this->assertSame($this->employee->id, $expense->employee_id);
        $this->assertSame($this->employee->site_id, $expense->site_id);
        $this->assertSame('personal', $expense->payment_type);
        $this->assertSame('2026-08-24', $expense->expense_date->toDateString());
        $this->assertNotNull($expense->receipt_file, '원본이 DB 에도 남아야 장부 근거가 산다');
        $this->assertSame('expense-app', $expense->ocr_data['source'] ?? null);
    }

    public function test_판독이_흐리면_금액을_물어보고_수기_금액으로_접수한다(): void
    {
        // 판독 실패(빈 응답) — 금액 없이 내면 접수하지 않는다.
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $this->submit()->assertStatus(422)->assertJson(['success' => false, 'code' => 'need_amount']);
        $this->assertSame(0, MobileExpense::query()->count(), '0원 경비는 원장 오염이다');

        // 금액을 적어 다시 내면 접수된다.
        $res = $this->submit(['amount' => 45.5, 'memo' => '공구 구매']);

        $res->assertOk()->assertJson(['success' => true]);
        $expense = MobileExpense::query()->first();
        $this->assertSame(45.5, (float) $expense->amount);
        $this->assertStringContainsString('공구 구매', $expense->description);
    }

    public function test_퇴사자는_제출할_수_없다(): void
    {
        $this->employee->update(['employment_status' => 'terminated']);
        $this->fakeOcr(['vendor_name' => 'X', 'amount' => 10, 'date' => '', 'accounting_account' => '', 'category' => '', 'description' => '', 'handwritten_notes' => '']);

        $this->submit()->assertStatus(422)->assertJson(['code' => 'not_active']);
        $this->assertSame(0, MobileExpense::query()->count());
    }

    public function test_내_영수증_목록은_내_것만_보이고_환급_예정이_계산된다(): void
    {
        MobileExpense::create(['employee_id' => $this->employee->id, 'site_id' => $this->employee->site_id,
            'payment_type' => 'personal', 'category' => '5403 Small Tools & Consumables',
            'description' => '내 공구', 'amount' => 30, 'expense_date' => '2026-08-20', 'status' => 'approved']);
        $other = Employee::create(['company_id' => $this->employee->company_id, 'first_name' => '남', 'last_name' => '이', 'name' => '이남', 'employment_status' => 'active']);
        MobileExpense::create(['employee_id' => $other->id, 'payment_type' => 'personal',
            'category' => '5403 Small Tools & Consumables', 'description' => '남의 것', 'amount' => 99,
            'expense_date' => '2026-08-20', 'status' => 'approved']);

        $res = $this->actingAs($this->user)->getJson(route('expense-app.list'));

        $res->assertOk();
        $items = $res->json('items');
        $this->assertCount(1, $items, '남의 경비가 보이면 안 된다');
        $this->assertSame('내 공구', $items[0]['description']);
        $this->assertSame(30.0, (float) $res->json('claimable'), '승인된 개인카드 = 다음 급여 환급 예정');
    }

    public function test_수기_현장_메모가_경비를_그_현장으로_귀속시킨다(): void
    {
        $hff = Site::create(['code' => 'HFF-02', 'name' => '수소충전소 2호', 'status' => 'active']);
        $project = \App\Models\Project::create(['project_code' => 'HFF-P', 'name' => 'HFF 공사', 'construction_type' => 'equipment_setting', 'site_id' => $hff->id]);

        $this->fakeOcr([
            'vendor_name' => 'Graybar', 'amount' => 210.0, 'date' => '2026-08-25',
            'accounting_account' => '5201 Job Materials', 'category' => '5201 Job Materials',
            'description' => 'conduit', 'handwritten_notes' => 'HFF-02 현장 배관자재', 'site_hint' => 'HFF-02',
            'subtotal' => 195.0, 'tax' => 15.0, 'tip' => 0,
        ]);

        $this->submit()->assertOk()->assertJsonPath('analyzed.siteMatched', 'HFF-02');

        $expense = MobileExpense::query()->first();
        $this->assertSame($hff->id, $expense->site_id, '수기 메모의 현장이 소속 현장을 이긴다 — 원가가 맞는 현장에 앉는다');
        $this->assertSame($project->id, $expense->project_id, '그 현장의 유일한 프로젝트까지 귀속');
        $this->assertSame(15.0, (float) $expense->ocr_data['tax'], '세액이 분리 저장된다');
    }

    public function test_같은_돈이_두번_들어오면_중복_의심이_표시된다(): void
    {
        // 다른 입구(문서함 등)로 이미 들어와 있던 같은 영수증.
        MobileExpense::create([
            'site_id' => $this->employee->site_id, 'payment_type' => 'corporate',
            'category' => '5201 Job Materials', 'description' => '[문서함] Home Depot · 자재',
            'amount' => 84.2, 'expense_date' => '2026-08-24', 'status' => 'pending',
            'ocr_data' => ['vendor_name' => 'Home Depot'],
        ]);

        $this->fakeOcr([
            'vendor_name' => 'Home Depot', 'amount' => 84.2, 'date' => '2026-08-24',
            'accounting_account' => '5201 Job Materials', 'category' => '5201 Job Materials',
            'description' => 'PVC pipe', 'handwritten_notes' => '', 'site_hint' => '',
        ]);

        $this->submit()->assertOk()->assertJsonPath('analyzed.duplicateSuspect', true);

        $mine = MobileExpense::query()->orderByDesc('id')->first();
        $this->assertStringContainsString('중복 의심', $mine->description, '승인 목록에서 바로 보여야 한다');
        $this->assertSame('pending', $mine->status, '막지 않는다 — 판단은 승인하는 사람이 한다');
    }

    public function test_거래처가_다르면_같은_금액이라도_의심하지_않는다(): void
    {
        // 오탐은 신뢰를 깎는다 — 금액·날짜가 같아도 거래처가 다르면 조용해야 한다.
        MobileExpense::create([
            'site_id' => $this->employee->site_id, 'payment_type' => 'corporate',
            'category' => '5201 Job Materials', 'description' => '[문서함] Home Depot · 자재',
            'amount' => 84.2, 'expense_date' => '2026-08-24', 'status' => 'pending',
            'ocr_data' => ['vendor_name' => 'Home Depot'],
        ]);
        $this->fakeOcr([
            'vendor_name' => 'Lowes', 'amount' => 84.2, 'date' => '2026-08-24',
            'accounting_account' => '5201 Job Materials', 'category' => '5201 Job Materials',
            'description' => 'other stuff', 'handwritten_notes' => '', 'site_hint' => '',
        ]);

        $this->submit()->assertOk()->assertJsonPath('analyzed.duplicateSuspect', false);
    }

    public function test_앱_화면과_매니페스트가_열린다(): void
    {
        $this->actingAs($this->user)->get(route('expense-app.index'))->assertOk()->assertSee('영수증');
        $this->get(route('expense-app.manifest'))->assertOk()->assertJson(['id' => 'expense-app', 'theme_color' => '#FEE500']);
    }

    public function test_로그인_없이는_들어갈_수_없다(): void
    {
        $this->get(route('expense-app.index'))->assertRedirect();
    }
}

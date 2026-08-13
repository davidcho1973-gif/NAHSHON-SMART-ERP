<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 내일 처음 오는 협력사 인원을 그 자리에서 등록할 수 있는가.
 *
 * 협력사는 매일 오는 사람이 다르다. 회사도 공정도 우리 목록에 없을 수 있는데, 예전에는
 * 둘 다 목록에서만 고를 수 있었다 — 목록에 없으면 등록 자체가 막혔고, 그러면 그 사람은
 * 그날 기록이 아예 남지 않는다. 출역 인원이 비면 원청 정산에서 문제가 된다.
 */
class WorkerJoinFreeTextTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $own = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $this->site = Site::create([
            'company_id' => $own->id, 'code' => 'LG_ESS_PH', 'name' => 'LG ESS Phoenix',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function submit(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('worker-join.store', ['site' => $this->site]), array_merge([
            'full_name' => 'Miguel Torres',
            'company_name' => 'Sun Valley Mechanical',
            'role' => 'Insulation',
            'email' => 'miguel@example.com',
            'phone' => '480-555-0100',
            'employment_type' => 'indirect',
            'preferred_language' => 'es',
        ], $overrides));
    }

    public function test_a_worker_can_type_a_company_that_is_not_on_the_list(): void
    {
        $this->submit()->assertOk();

        $this->assertDatabaseHas('companies', ['name' => 'Sun Valley Mechanical']);

        $employee = Employee::query()->where('name', 'Miguel Torres')->firstOrFail();
        $this->assertSame('Sun Valley Mechanical', $employee->company->name);
        $this->assertSame(Employee::TYPE_INDIRECT, $employee->employment_type);
    }

    public function test_a_typed_company_stays_unclassified_so_it_keeps_asking(): void
    {
        // 이름만 봐서는 자사인지 협력사인지 알 수 없다. 관리자가 분류하기 전까지는
        // 이 회사로 등록되는 다음 사람에게도 계속 물어야 한다.
        $this->submit()->assertOk();

        $company = Company::query()->where('name', 'Sun Valley Mechanical')->firstOrFail();

        $this->assertSame(Company::TYPE_UNKNOWN, $company->company_type);
        $this->assertNull($company->employmentType());
    }

    public function test_a_typed_company_must_say_who_pays_the_wages(): void
    {
        // 처음 보는 회사인데 소속 구분을 안 고르면 급여 방식이 정해지지 않는다.
        $this->submit(['employment_type' => null])
            ->assertSessionHasErrors('employment_type');

        $this->assertDatabaseMissing('companies', ['name' => 'Sun Valley Mechanical']);
    }

    public function test_the_same_company_typed_again_does_not_become_a_second_row(): void
    {
        // 매일 사람이 바뀌니 같은 업체 이름이 며칠에 걸쳐 여러 번 들어온다.
        // 대소문자·앞뒤 공백만 다른 것을 새 회사로 만들면 한 업체가 표에 넷씩 생긴다.
        $this->submit()->assertOk();
        $this->submit([
            'full_name' => 'Ana Reyes', 'email' => 'ana@example.com',
            'company_name' => '  sun valley MECHANICAL ',
        ])->assertOk();

        $this->assertSame(1, Company::query()->where('name', 'Sun Valley Mechanical')->count());
        $this->assertSame(
            Employee::query()->where('name', 'Miguel Torres')->value('company_id'),
            Employee::query()->where('name', 'Ana Reyes')->value('company_id'),
        );
    }

    public function test_picking_a_known_company_still_decides_the_employment_type(): void
    {
        // 목록에서 고른 회사는 분류가 이미 있으니 작업자에게 묻지 않는다.
        $partner = Company::create([
            'code' => 'PARTNER_A', 'name' => 'Partner A', 'status' => 'active',
            'company_type' => Company::TYPE_PARTNER,
        ]);

        $this->submit(['company_name' => null, 'company_id' => $partner->id, 'employment_type' => null])
            ->assertOk();

        $employee = Employee::query()->where('name', 'Miguel Torres')->firstOrFail();
        $this->assertSame(Employee::TYPE_INDIRECT, $employee->employment_type);
        $this->assertSame($partner->id, $employee->company_id);
    }

    public function test_a_worker_must_still_give_a_company_one_way_or_the_other(): void
    {
        $this->submit(['company_name' => null, 'company_id' => null])
            ->assertSessionHasErrors('company_id');
    }

    // ── 공정 ────────────────────────────────────────────────────────

    public function test_a_worker_can_type_a_trade_that_is_not_on_the_list(): void
    {
        $this->submit(['role' => 'Scaffolding'])->assertOk();

        $this->assertSame('Scaffolding', Employee::query()->where('name', 'Miguel Torres')->value('role'));
    }

    public function test_a_trade_typed_in_a_different_case_folds_into_the_existing_one(): void
    {
        // 자유 입력을 열면 'Insulation' 과 'insulation' 이 다른 공정이 되고 인원 집계가 갈린다.
        $this->submit()->assertOk();
        $this->submit([
            'full_name' => 'Ana Reyes', 'email' => 'ana@example.com', 'role' => '  insulation ',
        ])->assertOk();

        $roles = Employee::query()->whereIn('name', ['Miguel Torres', 'Ana Reyes'])->pluck('role')->unique();

        $this->assertCount(1, $roles, '같은 공정이 대소문자 때문에 둘로 갈렸습니다.');
        $this->assertSame('Insulation', $roles->first());
    }

    public function test_the_form_offers_both_the_list_and_a_free_text_box(): void
    {
        $html = $this->get(route('worker-join.form', ['site' => $this->site]))->assertOk()->getContent();

        $this->assertStringContainsString('name="company_name"', $html);
        $this->assertStringContainsString('__other__', $html);
        // 공정은 목록을 제안하되 직접 적을 수 있어야 한다.
        $this->assertStringContainsString('list="trade-list"', $html);
        $this->assertStringContainsString('<datalist id="trade-list">', $html);
    }

    public function test_the_free_text_wording_exists_in_all_three_languages(): void
    {
        foreach (\App\Support\WorkerLang::join() as $code => $t) {
            foreach (['companyOther', 'companyOtherPlaceholder', 'companyOtherHint'] as $key) {
                $this->assertArrayHasKey($key, $t, "[{$code}.{$key}] 가 없습니다.");
                $this->assertNotSame('', trim($t[$key]));
            }
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 회사 구분 설정 — 간편 등록 QR 한 장으로 고용 형태를 자동 판정하는 근거 데이터.
 */
class CompanyTypeAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'admin'): User
    {
        return User::factory()->create(['access_role' => $role, 'account_status' => 'active']);
    }

    private function api(string $method, array $args = []): TestResponse
    {
        return $this->postJson('/smart-company-api/'.$method, ['args' => $args, 'siteId' => 'ALL']);
    }

    public function test_listing_puts_unclassified_first_and_counts_them(): void
    {
        Company::create(['code' => 'A', 'name' => 'ZZ 미분류', 'status' => 'active', 'company_type' => Company::TYPE_UNKNOWN]);
        Company::create(['code' => 'B', 'name' => 'AA 자사', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);

        $res = $this->actingAs($this->admin())->api('api_getCompanyTypes');

        $res->assertStatus(200);
        $body = $res->json();
        $this->assertSame(1, $body['unclassified']);
        // 이름순이면 AA 가 먼저지만, 설정이 필요한 미지정이 위로 온다.
        $this->assertSame('ZZ 미분류', $body['companies'][0]['name']);
        $this->assertSame('direct', $body['companies'][1]['employmentType']);
    }

    public function test_admin_can_classify_a_company(): void
    {
        $company = Company::create(['code' => 'C', 'name' => '한빛전기', 'status' => 'active']);
        // 구분을 주지 않고 만든 회사는 미지정으로 남는다.
        $this->assertSame(Company::TYPE_UNKNOWN, $company->fresh()->company_type);

        $this->actingAs($this->admin())
            ->api('api_setCompanyType', [$company->id, Company::TYPE_PARTNER])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'type' => Company::TYPE_PARTNER]);

        $this->assertSame(Company::TYPE_PARTNER, $company->fresh()->company_type);
    }

    public function test_unknown_type_is_rejected(): void
    {
        $company = Company::create(['code' => 'C', 'name' => '한빛전기', 'status' => 'active']);

        $this->actingAs($this->admin())
            ->api('api_setCompanyType', [$company->id, 'whatever'])
            ->assertStatus(200)
            ->assertJson(['success' => false]);

        $this->assertSame(Company::TYPE_UNKNOWN, $company->fresh()->company_type);
    }

    public function test_non_admin_cannot_classify(): void
    {
        $company = Company::create(['code' => 'C', 'name' => '한빛전기', 'status' => 'active']);

        $this->actingAs($this->admin('foreman'))
            ->api('api_setCompanyType', [$company->id, Company::TYPE_OWN])
            ->assertStatus(200)
            ->assertJson(['success' => false]);

        $this->assertSame(Company::TYPE_UNKNOWN, $company->fresh()->company_type);
    }

    public function test_read_only_client_cannot_classify(): void
    {
        $company = Company::create(['code' => 'C', 'name' => '한빛전기', 'status' => 'active']);

        // 쓰기 엔드포인트라 열람 전용 계정은 진입 자체가 막힌다.
        $this->actingAs($this->admin('client'))
            ->api('api_setCompanyType', [$company->id, Company::TYPE_OWN])
            ->assertStatus(403);
    }

    public function test_company_type_maps_to_employment_type(): void
    {
        $map = [
            Company::TYPE_OWN => 'direct',
            Company::TYPE_PARTNER => 'indirect',
            Company::TYPE_CLIENT => 'client',
            Company::TYPE_UNKNOWN => null,
        ];

        foreach ($map as $companyType => $expected) {
            $c = new Company(['company_type' => $companyType]);
            $this->assertSame($expected, $c->employmentType(), $companyType);
        }
    }
}

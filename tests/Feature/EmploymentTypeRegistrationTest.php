<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 고용 형태별 간편 등록 QR — 스캔한 포스터(직접고용/협력사)에 따라 employment_type 이 정해진다.
 */
class EmploymentTypeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::create(['code' => 'AZ-01', 'name' => 'Arizona Site', 'timezone' => 'America/Phoenix', 'status' => 'active']);
    }

    public function test_poster_defaults_to_direct_and_links_to_typed_form(): void
    {
        $site = $this->site();

        $res = $this->get('/join/w/'.$site->id.'/qr');

        $res->assertStatus(200);
        $res->assertSee('직접고용');
        $res->assertSee('type=direct', false);
    }

    public function test_indirect_poster_renders_partner_wording(): void
    {
        $site = $this->site();

        $res = $this->get('/join/w/'.$site->id.'/qr?type=indirect');

        $res->assertStatus(200);
        $res->assertSee('협력사');
        $res->assertSee('하청업체 소속 작업자용');
        $res->assertSee('type=indirect', false);
    }

    public function test_form_carries_type_in_hidden_field(): void
    {
        $site = $this->site();

        $this->get('/join/w/'.$site->id.'?type=indirect')
            ->assertStatus(200)
            ->assertSee('name="employment_type" value="indirect"', false);

        $this->get('/join/w/'.$site->id)
            ->assertStatus(200)
            ->assertSee('name="employment_type" value="direct"', false);
    }

    public function test_registration_stores_employment_type_from_qr(): void
    {
        $site = $this->site();
        $company = Company::create(['code' => 'C1', 'name' => '대한설비', 'status' => 'active']);

        $this->post('/join/w/'.$site->id, [
            'full_name' => 'Partner Worker',
            'company_id' => $company->id,
            'role' => 'Pipefitter',
            'email' => 'partner@example.com',
            'phone' => '480-555-0111',
            'employment_type' => Employee::TYPE_INDIRECT,
        ])->assertStatus(200)->assertSee('협력사');

        $this->assertSame(
            Employee::TYPE_INDIRECT,
            Employee::where('email', 'partner@example.com')->first()->employment_type
        );
    }

    public function test_registration_without_type_is_direct(): void
    {
        $site = $this->site();
        $company = Company::create(['code' => 'C1', 'name' => '대한설비', 'status' => 'active']);

        $this->post('/join/w/'.$site->id, [
            'full_name' => 'Own Worker',
            'company_id' => $company->id,
            'role' => 'Electrician',
            'email' => 'own@example.com',
            'phone' => '480-555-0112',
        ])->assertStatus(200);

        $this->assertSame(
            Employee::TYPE_DIRECT,
            Employee::where('email', 'own@example.com')->first()->employment_type
        );
    }

    public function test_unknown_type_falls_back_to_direct(): void
    {
        $site = $this->site();
        $company = Company::create(['code' => 'C1', 'name' => '대한설비', 'status' => 'active']);

        $this->post('/join/w/'.$site->id, [
            'full_name' => 'Odd Worker',
            'company_id' => $company->id,
            'role' => 'Electrician',
            'email' => 'odd@example.com',
            'phone' => '480-555-0113',
            'employment_type' => 'ceo',
        ])->assertStatus(200);

        $this->assertSame(
            Employee::TYPE_DIRECT,
            Employee::where('email', 'odd@example.com')->first()->employment_type
        );
    }
}

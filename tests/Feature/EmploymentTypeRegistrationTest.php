<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 간편 등록 QR 은 현장당 한 장이다. 고용 형태는 작업자가 고른 소속회사로 정해지고,
 * 회사가 아직 분류되지 않았을 때만 폼에서 한 번 물어본다.
 */
class EmploymentTypeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::create(['code' => 'AZ-01', 'name' => 'Arizona Site', 'timezone' => 'America/Phoenix', 'status' => 'active']);
    }

    private function company(string $name, string $type): Company
    {
        return Company::create([
            'code' => strtoupper(substr(md5($name), 0, 6)),
            'name' => $name,
            'status' => 'active',
            'company_type' => $type,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function register(Site $site, Company $company, string $email, array $extra = [], string $query = ''): TestResponse
    {
        return $this->post('/join/w/'.$site->id.$query, [
            'full_name' => 'Worker '.$email,
            'company_id' => $company->id,
            'role' => 'Electrician',
            'email' => $email,
            'phone' => '480-555-0100',
            ...$extra,
        ]);
    }

    private function typeOf(string $email): ?string
    {
        return Employee::where('email', $email)->first()?->employment_type;
    }

    public function test_single_poster_has_no_employment_type_split(): void
    {
        $site = $this->site();

        $res = $this->get('/join/w/'.$site->id.'/qr');

        $res->assertStatus(200);
        $res->assertSee('자사·협력사 모두 이 QR 하나');
        $res->assertDontSee('type=direct', false);
        $res->assertDontSee('type=indirect', false);
    }

    public function test_own_company_registers_as_direct(): void
    {
        $site = $this->site();
        $own = $this->company('NAHSHON MEP', Company::TYPE_OWN);

        $this->register($site, $own, 'own@example.com')->assertStatus(200);

        $this->assertSame(Employee::TYPE_DIRECT, $this->typeOf('own@example.com'));
    }

    public function test_partner_company_registers_as_indirect(): void
    {
        $site = $this->site();
        $sub = $this->company('한빛전기', Company::TYPE_PARTNER);

        $this->register($site, $sub, 'sub@example.com')->assertStatus(200);

        $this->assertSame(Employee::TYPE_INDIRECT, $this->typeOf('sub@example.com'));
    }

    public function test_company_wins_over_what_the_worker_picks(): void
    {
        $site = $this->site();
        $sub = $this->company('한빛전기', Company::TYPE_PARTNER);

        // 협력사 직원이 실수로 "NAHSHON 소속" 을 골라도 회사 분류가 이긴다(급여 오분류 방지).
        $this->register($site, $sub, 'mistake@example.com', ['employment_type' => Employee::TYPE_DIRECT])
            ->assertStatus(200);

        $this->assertSame(Employee::TYPE_INDIRECT, $this->typeOf('mistake@example.com'));
    }

    public function test_unclassified_company_asks_the_worker(): void
    {
        $site = $this->site();
        $unknown = $this->company('미분류산업', Company::TYPE_UNKNOWN);

        $this->register($site, $unknown, 'ask@example.com')
            ->assertStatus(302)
            ->assertSessionHasErrors(['employment_type']);
        $this->assertNull($this->typeOf('ask@example.com'));

        $this->register($site, $unknown, 'ask@example.com', ['employment_type' => Employee::TYPE_INDIRECT])
            ->assertStatus(200);
        $this->assertSame(Employee::TYPE_INDIRECT, $this->typeOf('ask@example.com'));
    }

    public function test_form_shows_company_types_for_live_hint(): void
    {
        $site = $this->site();
        $this->company('NAHSHON MEP', Company::TYPE_OWN);
        $this->company('한빛전기', Company::TYPE_PARTNER);

        $res = $this->get('/join/w/'.$site->id);

        $res->assertStatus(200);
        $res->assertSee('data-etype="direct"', false);
        $res->assertSee('data-etype="indirect"', false);
        $res->assertSee('소속회사를 고르면 고용 구분이 자동으로 정해집니다');
    }

    public function test_already_printed_legacy_qr_still_forces_its_type(): void
    {
        $site = $this->site();
        $unknown = $this->company('미분류산업', Company::TYPE_UNKNOWN);

        // 현장에 이미 붙어 있는 예전 "협력사 QR" — 폼이 묻지 않고 그대로 협력사로 등록된다.
        $this->get('/join/w/'.$site->id.'?type=indirect')
            ->assertStatus(200)
            ->assertSee('name="qr_type" value="indirect"', false);

        $this->register($site, $unknown, 'legacy@example.com', ['qr_type' => 'indirect'])->assertStatus(200);

        $this->assertSame(Employee::TYPE_INDIRECT, $this->typeOf('legacy@example.com'));
    }

    public function test_legacy_qr_loses_to_a_classified_company(): void
    {
        $site = $this->site();
        $own = $this->company('NAHSHON MEP', Company::TYPE_OWN);

        // 예전 협력사 QR 로 들어왔어도 회사가 자사로 분류돼 있으면 회사가 이긴다.
        $this->register($site, $own, 'both@example.com', ['qr_type' => 'indirect'])->assertStatus(200);

        $this->assertSame(Employee::TYPE_DIRECT, $this->typeOf('both@example.com'));
    }
}

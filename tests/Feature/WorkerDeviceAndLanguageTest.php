<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\WorkerDevice;
use App\Support\WorkerLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 한 번 등록한 휴대폰은 기억된다 — 게이트 QR 을 스캔하면 이름을 찾지 않고 본인으로 인식되고,
 * 등록할 때 고른 언어로 화면이 열린다.
 */
class WorkerDeviceAndLanguageTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->partner = Company::create([
            'code' => 'SUB', 'name' => '한빛전기', 'status' => 'active',
            'company_type' => Company::TYPE_PARTNER,
        ]);
    }

    /** 간편 등록을 마치고 발급된 기기 토큰을 돌려준다. */
    private function register(string $email, string $lang = 'ko'): string
    {
        $res = $this->post('/join/w/'.$this->site->id, [
            'full_name' => 'Carlos Ramirez',
            'company_id' => $this->partner->id,
            'role' => 'Welder', // 공정은 목록 선택만 허용 — 기본 직군 목록에 있는 값이어야 한다.
            'email' => $email,
            'phone' => '480-555-0100',
            'preferred_language' => $lang,
        ]);
        $res->assertStatus(200);

        return $res->viewData('deviceToken');
    }

    public function test_registration_stores_language_and_issues_a_device_token(): void
    {
        $token = $this->register('carlos@example.com', 'es');

        $employee = Employee::where('email', 'carlos@example.com')->first();
        $this->assertSame('es', $employee->preferred_language);

        $this->assertNotEmpty($token);
        $this->assertSame(1, $employee->devices()->count());
        // 원문 토큰은 기기에만 남는다 — 서버에는 해시만 저장된다.
        $this->assertDatabaseMissing('worker_devices', ['token_hash' => $token]);
        $this->assertDatabaseHas('worker_devices', ['token_hash' => WorkerDevice::hash($token)]);
    }

    public function test_unknown_language_falls_back_to_default(): void
    {
        $this->post('/join/w/'.$this->site->id, [
            'full_name' => 'Odd', 'company_id' => $this->partner->id, 'role' => 'X',
            'email' => 'odd@example.com', 'phone' => '1', 'preferred_language' => 'fr',
        ])->assertSessionHasErrors(['preferred_language']);

        $this->register('nolang@example.com');
        $this->assertSame(WorkerLang::DEFAULT, Employee::where('email', 'nolang@example.com')->first()->preferred_language);
    }

    public function test_remembered_device_is_recognized_with_its_language(): void
    {
        $token = $this->register('carlos@example.com', 'es');

        $res = $this->postJson('/gate/'.$this->site->id.'/me', ['device_token' => $token]);

        $res->assertStatus(200)->assertJson([
            'recognized' => true,
            'lang' => 'es',
            'next' => 'clock_in',
        ]);
        $this->assertSame('Carlos Ramirez', $res->json('employee.name'));
        $this->assertSame('한빛전기', $res->json('employee.company'));
    }

    public function test_unknown_or_missing_token_is_not_recognized(): void
    {
        $this->postJson('/gate/'.$this->site->id.'/me', ['device_token' => 'nonsense'])
            ->assertStatus(200)->assertJson(['recognized' => false]);

        $this->postJson('/gate/'.$this->site->id.'/me', [])
            ->assertStatus(200)->assertJson(['recognized' => false]);
    }

    public function test_device_of_another_site_is_not_recognized(): void
    {
        $token = $this->register('carlos@example.com');
        $other = Site::create(['code' => 'TX-9', 'name' => 'Texas', 'timezone' => 'America/Chicago', 'status' => 'active']);

        $this->postJson('/gate/'.$other->id.'/me', ['device_token' => $token])
            ->assertStatus(200)->assertJson(['recognized' => false]);
    }

    public function test_inactive_worker_is_not_recognized(): void
    {
        $token = $this->register('carlos@example.com');
        Employee::where('email', 'carlos@example.com')->update(['employment_status' => 'terminated']);

        $this->postJson('/gate/'.$this->site->id.'/me', ['device_token' => $token])
            ->assertStatus(200)->assertJson(['recognized' => false]);
    }

    public function test_recognized_worker_sees_next_action_flip_after_punching(): void
    {
        $token = $this->register('carlos@example.com');
        $employee = Employee::where('email', 'carlos@example.com')->first();

        $this->postJson('/gate/'.$this->site->id.'/punch', ['employee_id' => $employee->id])
            ->assertStatus(200)->assertJson(['event' => 'clock_in']);

        $this->postJson('/gate/'.$this->site->id.'/me', ['device_token' => $token])
            ->assertStatus(200)->assertJson(['recognized' => true, 'next' => 'clock_out']);
    }

    public function test_existing_worker_can_bind_a_phone_without_re_registering(): void
    {
        $employee = Employee::create([
            'company_id' => $this->partner->id, 'site_id' => $this->site->id,
            'name' => 'Old Hand', 'email' => 'old@example.com',
            'employment_status' => 'active', 'employment_type' => Employee::TYPE_INDIRECT,
            'preferred_language' => 'en',
        ]);

        $res = $this->postJson('/gate/'.$this->site->id.'/remember', ['employee_id' => $employee->id]);

        $res->assertStatus(200)->assertJson(['success' => true, 'lang' => 'en']);
        $token = $res->json('device_token');

        $this->postJson('/gate/'.$this->site->id.'/me', ['device_token' => $token])
            ->assertStatus(200)->assertJson(['recognized' => true, 'lang' => 'en']);
    }

    public function test_remember_rejects_a_worker_from_another_site(): void
    {
        $other = Site::create(['code' => 'TX-9', 'name' => 'Texas', 'timezone' => 'America/Chicago', 'status' => 'active']);
        $employee = Employee::create([
            'company_id' => $this->partner->id, 'site_id' => $other->id,
            'name' => 'Elsewhere', 'email' => 'else@example.com', 'employment_status' => 'active',
        ]);

        $this->postJson('/gate/'.$this->site->id.'/remember', ['employee_id' => $employee->id])
            ->assertStatus(404)->assertJson(['success' => false]);
    }

    public function test_forget_drops_the_device(): void
    {
        $token = $this->register('carlos@example.com');

        $this->postJson('/gate/'.$this->site->id.'/forget', ['device_token' => $token])
            ->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseMissing('worker_devices', ['token_hash' => WorkerDevice::hash($token)]);
        $this->postJson('/gate/'.$this->site->id.'/me', ['device_token' => $token])
            ->assertStatus(200)->assertJson(['recognized' => false]);
    }

    public function test_gate_page_ships_all_three_languages(): void
    {
        $res = $this->get('/gate/'.$this->site->id);

        $res->assertStatus(200);
        foreach (WorkerLang::OPTIONS as $code => $name) {
            $res->assertSee('data-lang="'.$code.'"', false);
            $res->assertSee($name);
        }
        // 사전이 통째로 실려야 새로고침 없이 언어를 바꿀 수 있다.
        $res->assertSee(WorkerLang::gate()['es']['clockIn']);
        $res->assertSee(WorkerLang::gate()['en']['clockOut']);
    }

    public function test_join_form_ships_all_three_languages(): void
    {
        $res = $this->get('/join/w/'.$this->site->id);

        $res->assertStatus(200);
        foreach (WorkerLang::OPTIONS as $code => $name) {
            $res->assertSee('data-lang="'.$code.'"', false);
        }
        $res->assertSee(WorkerLang::join()['es']['submit']);
        $res->assertSee('name="preferred_language"', false);
    }

    public function test_deleting_a_worker_removes_their_devices(): void
    {
        $token = $this->register('carlos@example.com');
        Employee::where('email', 'carlos@example.com')->first()->delete();

        $this->assertDatabaseMissing('worker_devices', ['token_hash' => WorkerDevice::hash($token)]);
    }
}

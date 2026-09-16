<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LoginDevice;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 기기 메모(label) 칸은 120자다. 폰 이름표(User-Agent)를 118자로 자른 뒤 «...» 를
 * 붙여 121자가 됐고, 운영 DB(pgsql)가 그 줄을 거절해 등록 완료 화면이 500 이 됐다.
 *
 * 카톡 안에서 열리는 브라우저는 이름표가 170자를 넘어 매번 걸렸다. 시험 요청의 기본
 * 이름표는 «Symfony» 7자라 이 길이를 한 번도 지나가지 않았다 — 그래서 실제 이름표로 찍는다.
 */
class DeviceLabelLengthTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-09-15 운영 로그에 남은 갤럭시 카톡 브라우저 이름표. */
    private const KAKAO_UA = 'Mozilla/5.0 (Linux; Android 16; SM-S948N Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/140.0.7339.207 Mobile Safari/537.36;KAKAOTALK 2610420';

    private Site $site;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => '703K', 'name' => 'Building 703K',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->company = Company::create([
            'code' => 'NAHSHON', 'name' => 'NAHSHON MEP', 'status' => 'active',
            'company_type' => Company::TYPE_PARTNER,
        ]);
    }

    public function test_registration_from_kakao_browser_reaches_the_done_screen(): void
    {
        $this->withHeader('User-Agent', self::KAKAO_UA)
            ->post('/join/'.$this->site->id, [
                'full_name' => '이대웅',
                'company_id' => $this->company->id,
                'role' => 'Piping',
                'position' => 'worker',
                'phone' => '010-5555-0100',
            ])
            ->assertOk()
            ->assertViewHas('done', true);

        $label = WorkerDevice::query()->sole()->label;
        $this->assertLessThanOrEqual(120, mb_strlen($label));
        $this->assertStringStartsWith('Mozilla/5.0 (Linux; Android 16', $label);
    }

    public function test_gate_remember_accepts_a_long_browser_name(): void
    {
        $employee = Employee::create([
            'name' => '김창돈', 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'phone' => '480-555-0101', 'employment_status' => 'active',
        ]);

        $this->withHeader('User-Agent', self::KAKAO_UA)
            ->postJson('/gate/'.$this->site->id.'/remember', ['employee_id' => $employee->id])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_login_device_accepts_a_long_browser_name(): void
    {
        $user = User::factory()->create();

        LoginDevice::issueFor($user, self::KAKAO_UA);

        $this->assertLessThanOrEqual(120, mb_strlen(LoginDevice::query()->sole()->label));
    }
}

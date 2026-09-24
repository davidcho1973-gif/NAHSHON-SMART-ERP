<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
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
        $this->actingAs(User::factory()->create(['access_role' => 'hr_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']));

        $this->site = Site::create([
            'code' => '703K', 'name' => 'Building 703K',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->company = Company::create([
            // 픽스처 회사명은 중립으로 둔다 — 공개 저장소라 고객사 이름이 남으면
            // 다음 고객 배포에 남의 회사 이름이 먼저 들어가 앉는다(OrgIdentityTest).
            'code' => 'ACME', 'name' => 'ACME MECHANICAL', 'status' => 'active',
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
        $employee = Employee::create(['name' => 'Worker', 'company_id' => $this->company->id, 'site_id' => $this->site->id, 'employment_status' => 'active']);
        WorkerDevice::issueFor($employee, self::KAKAO_UA, verified: true);
        $this->assertLessThanOrEqual(120, mb_strlen(WorkerDevice::sole()->label));
    }
}

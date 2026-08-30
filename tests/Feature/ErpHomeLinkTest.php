<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 사이드 앱에서 ERP 로 돌아가는 문 — 있는가, 그리고 엉뚱한 사람에게 열려 있지 않은가.
 */
class ErpHomeLinkTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $company->id, 'code' => 'S1', 'name' => '현장', 'status' => 'active']);
        $this->employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'name' => '김성훈', 'employment_status' => 'active',
        ]);
    }

    private function userWith(string $role): User
    {
        return User::factory()->create([
            'access_role' => $role, 'account_status' => 'active', 'employee_id' => $this->employee->id,
        ]);
    }

    /** @return array<int, string> ERP 로 돌아가는 문이 있어야 하는 화면들 */
    private function sideApps(): array
    {
        return [
            route('attendance-app.index'),
            route('expense-app.index'),
            route('attendance-app.ops-room'),
        ];
    }

    public function test_ERP_가_자기_집인_사람에게는_모든_사이드_앱에_돌아가는_문이_있다(): void
    {
        // 홈 화면 아이콘으로 바로 열리는 화면들이라, 나가는 길이 없으면 주소창을
        // 손으로 고쳐야 ERP 로 돌아간다.
        $admin = $this->userWith('admin');

        foreach ($this->sideApps() as $url) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertSee('class="erp-home"', false, "돌아가는 문이 없습니다: {$url}");
        }
    }

    public function test_작업자에게는_보이지_않는다(): void
    {
        // 작업자에게 ERP 는 자기 화면이 아니다. 눌러서 회사 전체 화면이 뜨면
        // 뭘 잘못 눌렀다고 생각하고 앱을 지운다(landingPath 가 존재하는 이유와 같다).
        foreach (['worker', 'foreman'] as $role) {
            $user = $this->userWith($role);

            $this->actingAs($user)->get(route('attendance-app.index'))
                ->assertOk()
                ->assertDontSee('class="erp-home"', false, "[{$role}] 에게 ERP 문이 열려 있습니다");
        }
    }

    public function test_판정은_로그인_직후_보내는_곳과_같은_규칙을_쓴다(): void
    {
        // 규칙이 두 벌이면 "로그인하면 앱으로 보내면서 앱에서는 ERP 로 가라고 하는"
        // 모순이 생긴다.
        $this->assertSame('/attendance-app', $this->userWith('worker')->landingPath());
        $this->assertSame('/', $this->userWith('admin')->landingPath());
    }

    public function test_로그인하지_않은_공개_화면에는_나오지_않는다(): void
    {
        // 게이트는 로그인 없이 쓰는 화면이다. 갈 수도 없는 곳으로 가는 문을 그리면 안 된다.
        $this->get(route('gate.show', ['site' => $this->site]))
            ->assertOk()
            ->assertDontSee('class="erp-home"', false);
    }

    public function test_사이드_앱은_이_문을_각자_만들지_않고_한_곳에서_가져다_쓴다(): void
    {
        // 복사해 두면 앱이 늘어날 때마다 하나씩 빠지고, 빠진 앱에서는 아무도
        // 그 사실을 말해 주지 않는다.
        $views = glob(resource_path('views/{attendance-app,expense-app}/*.blade.php'), GLOB_BRACE) ?: [];
        $this->assertNotEmpty($views);

        foreach ($views as $file) {
            $body = (string) file_get_contents($file);
            if (! str_contains($body, 'erp-home')) {
                continue;
            }

            $this->assertStringContainsString(
                "@include('partials.erp-home')",
                $body,
                basename($file).' 가 돌아가는 문을 직접 그리고 있습니다 — partials.erp-home 을 include 하세요.',
            );
        }
    }
}

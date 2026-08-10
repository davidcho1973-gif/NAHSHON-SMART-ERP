<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Support\WorkerLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 작업자가 이 화면을 자기 휴대폰에 "앱으로" 놓을 수 있는가.
 *
 * 왜 이걸 시험하나 — 설치는 조용히 실패한다. 매니페스트를 못 읽으면 안드로이드는 설치를
 * 아예 제안하지 않고, 아이폰은 추가는 되는데 아이콘 자리에 회색 화면 조각이 붙는다.
 * 둘 다 오류 화면이 없어서, 우리는 잘 되는 줄 알고 작업자는 앱이 없는 채로 지낸다.
 */
class AppInstallTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::firstOrCreate(['code' => 'DP'], ['name' => 'DASOL PRISM', 'status' => 'active']);
    }

    private function site(string $code = 'LG_ESS_PH', string $name = 'LG ESS Phoenix'): Site
    {
        return Site::create([
            'company_id' => $this->company()->id,
            'code' => $code,
            'name' => $name,
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
    }

    public function test_the_gate_manifest_opens_without_logging_in(): void
    {
        // 게이트는 로그인 없이 쓰는 화면이다. 매니페스트가 로그인 뒤에 있으면 브라우저가
        // 로그인 페이지를 매니페스트로 읽고 설치가 통째로 무산된다.
        $this->get(route('gate.manifest', ['site' => $this->site()]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');
    }

    public function test_the_gate_icon_opens_that_sites_screen(): void
    {
        // 아이콘을 눌렀을 때 홈이 아니라 그 현장 출퇴근 화면이 떠야 한다.
        $site = $this->site();

        $m = $this->get(route('gate.manifest', ['site' => $site]))->json();

        $this->assertSame(route('gate.show', ['site' => $site]), $m['start_url']);
        $this->assertSame('standalone', $m['display']);   // 주소창을 숨긴다
        $this->assertStringContainsString('LG_ESS_PH', $m['name']);
    }

    public function test_two_sites_do_not_collide_into_one_icon(): void
    {
        // 두 현장을 오가는 사람은 아이콘이 둘이어야 한다. id 가 같으면 하나로 합쳐진다.
        $a = $this->site();
        $b = $this->site('KSA_TX', 'KSA Texas');

        $ma = $this->get(route('gate.manifest', ['site' => $a]))->json();
        $mb = $this->get(route('gate.manifest', ['site' => $b]))->json();

        $this->assertNotSame($ma['id'], $mb['id']);
        $this->assertNotSame($ma['start_url'], $mb['start_url']);
    }

    public function test_the_icons_are_png_files_that_actually_exist(): void
    {
        // SVG 를 가리키면 아이폰이 무시한다. 파일이 없으면 안드로이드가 설치를 거부한다.
        $m = $this->get(route('gate.manifest', ['site' => $this->site()]))->json();

        $this->assertNotEmpty($m['icons']);

        foreach ($m['icons'] as $icon) {
            $this->assertSame('image/png', $icon['type']);
            $file = public_path('images/'.basename($icon['src']));
            $this->assertFileExists($file, "아이콘 파일이 없습니다: {$icon['src']}");
            [$w, $h] = getimagesize($file);
            $this->assertSame($icon['sizes'], "{$w}x{$h}", '적힌 크기와 실제 그림 크기가 다릅니다.');
        }
    }

    public function test_android_gets_a_maskable_icon(): void
    {
        // 안드로이드는 아이콘을 원·눈물방울 모양으로 깎는다. maskable 이 없으면 시계가 잘린다.
        $m = $this->get(route('gate.manifest', ['site' => $this->site()]))->json();

        $purposes = array_column($m['icons'], 'purpose');

        $this->assertContains('maskable', $purposes);
        $this->assertContains('any', $purposes);
    }

    public function test_apple_touch_icon_has_no_transparent_corners(): void
    {
        // 아이폰은 apple-touch-icon 에 자기 마스크를 씌운다. 투명 모서리를 주면
        // 홈 화면에서 그 자리가 검게 남는다.
        $im = imagecreatefrompng(public_path('images/apple-touch-icon.png'));

        foreach ([[0, 0], [179, 0], [0, 179], [179, 179]] as [$x, $y]) {
            $alpha = (imagecolorat($im, $x, $y) >> 24) & 0x7F;
            $this->assertSame(0, $alpha, "모서리({$x},{$y})가 투명합니다.");
        }
    }

    public function test_the_gate_screen_points_at_its_manifest_and_icon(): void
    {
        // 매니페스트가 있어도 화면이 가리키지 않으면 브라우저는 찾아가지 않는다.
        $site = $this->site();

        $this->get(route('gate.show', ['site' => $site]))
            ->assertOk()
            ->assertSee(route('gate.manifest', ['site' => $site]), false)
            ->assertSee('apple-touch-icon.png', false)
            ->assertSee('apple-mobile-web-app-capable', false);
    }

    public function test_the_gate_screen_carries_the_install_guide_in_three_languages(): void
    {
        // 이 현장 작업자 명단은 Español 이 많다. 한국어로만 안내하면 아무도 설치하지 않는다.
        $res = $this->get(route('gate.show', ['site' => $this->site()]))->assertOk();

        $res->assertSee('Agregar a la pantalla de inicio', false);   // es
        $res->assertSee('Add to Home Screen', false);                // en
        $res->assertSee('홈 화면에 추가', false);                      // ko
    }

    public function test_every_install_string_exists_in_every_language(): void
    {
        // 한 언어만 키가 빠지면 그 언어에서 안내가 빈칸으로 뜬다 — 화면은 안 깨져서 모른다.
        $dict = WorkerLang::install();
        $keys = array_keys($dict[WorkerLang::DEFAULT]);

        foreach (WorkerLang::OPTIONS as $code => $_) {
            $this->assertSame($keys, array_keys($dict[$code]), "[{$code}] 문구 키가 다릅니다.");

            foreach ($dict[$code] as $key => $text) {
                $this->assertNotSame('', trim($text), "[{$code}.{$key}] 가 비어 있습니다.");
            }
        }
    }

    public function test_the_gate_poster_tells_workers_the_screen_can_become_an_app(): void
    {
        // 포스터는 이미 게이트 벽에 붙어 있다. 설치를 알릴 가장 싼 자리다.
        $langs = WorkerLang::poster()['gate'];

        foreach (['ko', 'en', 'es'] as $code) {
            $steps = implode(' ', $langs[$code]['steps']);
            $this->assertMatchesRegularExpression(
                '/홈 화면에 추가|Home Screen|pantalla de inicio/u',
                $steps,
                "[{$code}] 포스터에 설치 안내가 없습니다."
            );
        }
    }

    public function test_the_logged_in_worker_app_is_installable_too(): void
    {
        // 반장·계정 있는 작업자가 쓰는 화면. 게이트와 아이콘이 달라야 홈 화면에서 섞이지 않는다.
        $gate = $this->get(route('gate.manifest', ['site' => $this->site()]))->json();
        $worker = $this->get(route('worker-app.manifest'))->json();

        $this->assertNotSame($gate['id'], $worker['id']);
        $this->assertStringEndsWith('/attendance-app', $worker['start_url']);
    }

    public function test_the_worker_app_screen_points_at_its_manifest(): void
    {
        $employee = Employee::create([
            'company_id' => $this->company()->id,
            'site_id' => $this->site()->id,
            'name' => 'Cristian rosas',
            'employment_status' => 'active',
            'preferred_language' => 'es',
        ]);
        $user = User::factory()->create([
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
            'employee_id' => $employee->id,
        ]);

        $this->actingAs($user)
            ->get(route('attendance-app.index'))
            ->assertOk()
            ->assertSee(route('worker-app.manifest'), false)
            ->assertSee('apple-touch-icon.png', false)
            // 스페인어 사용자로 등록됐으면 설치 안내도 스페인어로 시작해야 한다.
            ->assertSee('"es"', false);
    }
}

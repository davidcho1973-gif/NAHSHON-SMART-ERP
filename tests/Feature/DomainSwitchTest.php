<?php

namespace Tests\Feature;

use App\Models\SystemHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 도메인이 절반만 바뀌는 사고를 밖에서 알아챌 수 있는가.
 *
 * 새 주소로 열리는데 APP_URL 은 옛 주소인 상태가 흔하다. 화면은 멀쩡해 보이지만
 * QR·앱 설치 카드·매니페스트가 전부 옛 주소를 가리킨다 — 인쇄해서 벽에 붙인 뒤에야
 * 알게 되고, 그때는 종이를 다시 뽑아야 한다.
 */
class DomainSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_configured_and_the_served_address(): void
    {
        config(['app.url' => 'https://erp.example.com']);

        $res = $this->get('/build-version')->assertOk();

        $res->assertJsonPath('domain.app_url', 'https://erp.example.com');
        $this->assertNotEmpty($res->json('domain.served_from'));
    }

    public function test_it_says_plainly_when_the_two_do_not_match(): void
    {
        // 옛 주소가 APP_URL 에 남아 있는 상태.
        config(['app.url' => 'https://old-address.example.net']);

        $this->get('/build-version')
            ->assertOk()
            ->assertJsonPath('domain.matches', false);
    }

    public function test_it_matches_when_the_switch_is_complete(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->get('/build-version')
            ->assertOk()
            ->assertJsonPath('domain.matches', true);
    }

    public function test_the_google_redirect_is_visible_too(): void
    {
        // 구글 로그인은 이 값과 구글 콘솔이 둘 다 맞아야 된다. 한쪽만 바꾸면 로그인이
        // 통째로 막히는데, 화면에는 영어 오류 한 줄만 뜬다.
        config(['services.google.redirect' => 'https://erp.example.com/auth/google/callback']);

        $this->get('/build-version')
            ->assertOk()
            ->assertJsonPath('domain.google_redirect', 'https://erp.example.com/auth/google/callback');
    }

    public function test_the_deploy_check_warns_about_a_mismatch(): void
    {
        $sh = file_get_contents(base_path('scripts/deploy/check-scheduler.sh'));

        $this->assertStringContainsString('도메인 불일치', $sh);
        $this->assertStringContainsString('domain_ok=', $sh);
    }

    public function test_the_switch_guide_is_written_down(): void
    {
        // 이 절차는 사람이 다섯 군데를 손으로 바꿔야 한다. 어디를 빠뜨렸는지 나중에
        // 되짚을 수 있어야 한다.
        $doc = base_path('docs/도메인-전환.md');

        $this->assertFileExists($doc);
        $md = file_get_contents($doc);

        foreach (['APP_URL', 'GOOGLE_REDIRECT_URI', 'STAGING_URL', 'CNAME', 'SESSION_DOMAIN'] as $key) {
            $this->assertStringContainsString($key, $md, "안내서에 {$key} 가 빠졌습니다.");
        }
    }

    public function test_nothing_in_the_code_hardcodes_the_old_host(): void
    {
        // 코드에 주소가 박혀 있으면 환경변수를 바꿔도 안 따라온다.
        $hits = [];

        foreach (['app', 'routes', 'config'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && str_contains(file_get_contents($file->getPathname()), 'laravel.cloud')) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $hits, '코드에 옛 주소가 박혀 있습니다: '.implode(', ', $hits));
    }
}

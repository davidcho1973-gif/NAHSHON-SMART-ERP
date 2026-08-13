<?php

namespace Tests\Feature;

use App\Models\OrgSetting;
use App\Models\Site;
use App\Models\User;
use App\Support\Org;
use App\Support\OrgLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 고객이 자기 로고 그림을 올린다.
 *
 * 이름에서 뽑은 머리글자는 "틀리지 않은" 로고일 뿐, 그 회사의 로고는 아니다.
 * 명함과 안전모에 붙어 있는 그림이 화면에도 있어야 고객은 이 화면을 자기 것으로 본다.
 *
 * 그림을 데이터베이스에 넣는 이유는 하나다 — 파일로 두면 배포마다 사라진다.
 * Laravel Cloud 의 로컬 디스크는 배포할 때 초기화되고, 오브젝트 스토리지를 먼저
 * 붙이게 하면 "환경변수만 채우면 새 고객이 선다"는 규칙이 로고 하나 때문에 깨진다.
 */
class OrgLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Org::forget();
    }

    protected function tearDown(): void
    {
        Org::forget();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create([
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create([
            'access_role' => 'staff', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    /** 투명한 자리를 가진 작은 PNG. 로고는 대개 배경이 뚫려 있다. */
    private function png(int $w = 900, int $h = 300): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 0, 0, (int) ($w / 2), $h, imagecolorallocate($im, 20, 160, 90));
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function upload(string $bytes, string $name = 'logo.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo-test-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    // ── 올린다 ─────────────────────────────────────────────────────────

    public function test_an_admin_can_upload_a_logo(): void
    {
        $this->actingAs($this->admin())
            ->post(route('org.logo.store'), ['file' => $this->upload($this->png())])
            ->assertOk()->assertJsonPath('success', true);

        Org::forget();
        $this->assertTrue(Org::hasLogo());
        $this->assertSame('image/png', Org::logoMime());
        $this->assertNotNull(Org::logoBytes());
    }

    public function test_a_big_logo_is_shrunk_before_it_is_stored(): void
    {
        // 회사 로고 원본은 인쇄용이라 3000px 인 경우가 흔하다. 그대로 두면 32px
        // 배지 자리에 3000px 그림을 내려받게 되고, 첫 화면이 느려진 이유가
        // 아무 데도 안 적힌 채로 남는다.
        $this->actingAs($this->admin())
            ->post(route('org.logo.store'), ['file' => $this->upload($this->png(3000, 1200))])
            ->assertOk();

        Org::forget();
        $size = getimagesizefromstring((string) Org::logoBytes());

        $this->assertNotFalse($size);
        $this->assertLessThanOrEqual(OrgLogo::MAX_EDGE, max($size[0], $size[1]));
    }

    public function test_the_transparent_background_survives(): void
    {
        // 투명한 자리를 흰색으로 채우면 어두운 사이드바에 흰 판때기가 하나 붙는다.
        $this->actingAs($this->admin())
            ->post(route('org.logo.store'), ['file' => $this->upload($this->png())])
            ->assertOk();

        Org::forget();
        $im = imagecreatefromstring((string) Org::logoBytes());
        $this->assertNotFalse($im);

        // 오른쪽 절반은 칠하지 않았다 — 투명이어야 한다.
        $corner = imagecolorat($im, imagesx($im) - 2, 2);
        $alpha = ($corner >> 24) & 0x7F;
        imagedestroy($im);

        $this->assertGreaterThan(100, $alpha, '투명한 자리가 불투명하게 채워졌습니다.');
    }

    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('org.logo.store'), ['file' => $this->upload('이건 그림이 아닙니다', 'logo.png')])
            ->assertStatus(422)->assertJsonPath('success', false);

        Org::forget();
        $this->assertFalse(Org::hasLogo());
    }

    public function test_a_file_that_is_too_big_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('org.logo.store'), [
                'file' => $this->upload(str_repeat('x', OrgLogo::MAX_UPLOAD_BYTES + 10), 'logo.png'),
            ])
            ->assertStatus(422);

        Org::forget();
        $this->assertFalse(Org::hasLogo());
    }

    // ── SVG 는 그림이 아니라 문서다 ────────────────────────────────────

    public function test_an_svg_logo_is_kept_as_a_vector(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';

        $this->actingAs($this->admin())
            ->post(route('org.logo.store'), ['file' => $this->upload($svg, 'logo.svg')])
            ->assertOk();

        Org::forget();
        $this->assertSame('image/svg+xml', Org::logoMime());
        $this->assertStringContainsString('<circle', (string) Org::logoBytes());
    }

    public function test_a_script_inside_an_svg_does_not_survive(): void
    {
        // SVG 는 스크립트를 품을 수 있다. 우리 도메인에서 그대로 돌려주면
        // 그 스크립트도 우리 도메인 것이 되고, 로그인한 사람의 화면에서 돈다.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
            .'<script>fetch("/steal")</script><circle cx="5" cy="5" r="4"/></svg>';

        $this->actingAs($this->admin())
            ->post(route('org.logo.store'), ['file' => $this->upload($svg, 'logo.svg')])
            ->assertOk();

        Org::forget();
        $stored = (string) Org::logoBytes();

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onload', $stored);
        $this->assertStringContainsString('<circle', $stored);
    }

    public function test_the_picture_is_served_with_headers_that_stop_it_running(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="5" cy="5" r="4"/></svg>';
        Org::putLogo($svg, 'image/svg+xml');

        $this->get(route('org.logo'))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/svg+xml');
    }

    // ── 권한 ───────────────────────────────────────────────────────────

    public function test_only_a_super_admin_can_change_the_logo(): void
    {
        $this->actingAs($this->staff())
            ->post(route('org.logo.store'), ['file' => $this->upload($this->png())])
            ->assertStatus(403);

        Org::forget();
        $this->assertFalse(Org::hasLogo());
    }

    public function test_a_stranger_cannot_change_the_logo(): void
    {
        $this->post(route('org.logo.store'), ['file' => $this->upload($this->png())])
            ->assertRedirect(route('login'));
    }

    // ── 내보낸다 ───────────────────────────────────────────────────────

    public function test_the_picture_is_public_because_the_login_screen_needs_it(): void
    {
        // 로그인 화면과 게이트 화면이 로고를 그린다. 그 둘은 아직 로그인하기 전이다.
        Org::putLogo($this->png(), 'image/png');

        $this->get(route('org.logo'))->assertOk();
    }

    public function test_there_is_no_picture_before_anyone_uploads_one(): void
    {
        // 로고가 없는 것은 정상이다. 새로 선 배포는 전부 이 상태다.
        $this->get(route('org.logo'))->assertNotFound();
    }

    public function test_changing_the_logo_changes_the_address(): void
    {
        // 주소가 그대로면 브라우저가 어제 받아 둔 그림을 계속 보여 준다.
        // "바꿨는데 안 바뀐다" 는 화면만큼 사람을 지치게 하는 것이 없다.
        Org::putLogo($this->png(400, 400), 'image/png');
        Org::forget();
        $first = Org::logoVersion();

        Org::putLogo($this->png(300, 200), 'image/png');
        Org::forget();

        $this->assertNotSame($first, Org::logoVersion());
    }

    // ── 지운다 ─────────────────────────────────────────────────────────

    public function test_an_admin_can_remove_the_logo(): void
    {
        Org::putLogo($this->png(), 'image/png');

        $this->actingAs($this->admin())->delete(route('org.logo.destroy'))
            ->assertOk()->assertJsonPath('success', true);

        Org::forget();
        $this->assertFalse(Org::hasLogo());
        $this->assertNull(Org::logoBytes());
        $this->get(route('org.logo'))->assertNotFound();
    }

    // ── 화면이 따라오는가 ──────────────────────────────────────────────

    public function test_the_screens_show_the_picture_instead_of_the_initials(): void
    {
        OrgSetting::query()->updateOrCreate(['key' => 'name'], ['value' => 'ABC 건설']);
        Org::putLogo($this->png(), 'image/png');
        Org::forget();

        $site = Site::create([
            'code' => 'S1', 'name' => 'Site One',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);

        $screens = [
            route('login') => false,
            route('gate.show', ['site' => $site]) => false,
            '/' => true,
        ];

        foreach ($screens as $url => $needsLogin) {
            $req = $needsLogin ? $this->actingAs($this->admin()) : $this;
            $body = $req->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('/org/logo?v='.Org::logoVersion(), $body,
                "로고가 안 걸린 화면이 있습니다: {$url}");
        }
    }

    public function test_the_initials_come_back_when_the_picture_is_removed(): void
    {
        OrgSetting::query()->updateOrCreate(['key' => 'name'], ['value' => 'ABC 건설']);
        Org::putLogo($this->png(), 'image/png');
        Org::removeLogo();

        $body = $this->actingAs($this->admin())->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="brand-logo">ABC</div>', $body);
        $this->assertStringNotContainsString('/org/logo', $body);
    }

    // ── 무거운 줄은 매 요청에 끌고 오지 않는다 ─────────────────────────

    public function test_the_picture_is_not_loaded_with_every_setting_read(): void
    {
        // 그림은 수십 KB 다. 회사 이름 한 줄 읽자고 매 요청에 같이 끌고 오면
        // 로고를 쓰지 않는 화면까지 전부 느려진다.
        Org::putLogo($this->png(), 'image/png');
        Org::forget();

        $this->assertNull(Org::stored(Org::LOGO_KEY));
        $this->assertNotNull(Org::stored(Org::LOGO_MIME_KEY));
        $this->assertNotNull(Org::logoBytes());
    }
}

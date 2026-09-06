<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Support\AppLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 첫 화면에서 고른 언어가 <b>그 아래 화면까지</b> 따라가는지 지킨다.
 *
 * 2026-09-06: 사장이 앱 첫 화면에서 EN 을 골랐는데, 거기서 들어간 «현장 기록» 은
 * 한국어로 나왔다. 원인은 첫 화면이 고른 언어를 브라우저 안(localStorage)에만 두어서
 * 서버가 그 선택을 아예 몰랐던 것이다 — 서버가 그리는 화면은 전부 기본 언어로 나왔다.
 *
 * 그리고 가입할 때 고른 언어도 첫 화면의 자동 전환에만 쓰였을 뿐, 서버 렌더 화면은
 * 그것마저 몰랐다. 스페인어로 가입한 작업자가 매번 버튼부터 찾아야 했다.
 */
class AppLocaleFollowsChoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cookie_decides_when_it_is_set(): void
    {
        $user = $this->worker('ko');

        $this->actingAs($user)
            ->withUnencryptedCookie(AppLocale::COOKIE, 'en')
            ->get('/attendance-app')
            ->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    public function test_an_unknown_cookie_value_is_ignored(): void
    {
        // 쿠키는 화면이 평문으로 쓴다 — 값을 그대로 믿으면 안 된다.
        $user = $this->worker('es');

        $this->actingAs($user)
            ->withUnencryptedCookie(AppLocale::COOKIE, 'zz')
            ->get('/attendance-app')
            ->assertOk();

        $this->assertSame('es', app()->getLocale(), '엉뚱한 값이면 가입 언어로 떨어져야 한다.');
    }

    public function test_the_signup_language_is_used_when_no_cookie_exists(): void
    {
        $user = $this->worker('es');

        $response = $this->actingAs($user)->get('/attendance-app')->assertOk();

        $this->assertSame('es', app()->getLocale());
        // 다음 요청부터는 사용자를 몰라도 같은 언어가 나와야 한다.
        $response->assertCookie(AppLocale::COOKIE, 'es', false);
    }

    public function test_it_falls_back_to_the_deployment_default(): void
    {
        // 직원 기록이 아직 안 붙은 계정 — 따를 가입 언어가 없다.
        config(['app.locale' => 'en']);

        $user = User::query()->create([
            'name' => 'No Employee',
            'email' => 'none-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'access_role' => 'super_admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $this->actingAs($user)->get('/attendance-app')->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    public function test_choosing_a_language_saves_it_on_the_person_and_in_the_cookie(): void
    {
        $user = $this->worker('ko');

        $this->actingAs($user)
            ->postJson(route('attendance-app.language'), ['lang' => 'en'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertCookie(AppLocale::COOKIE, 'en', false);

        // 폰을 바꿔도 따라오도록 사람에게도 남는다.
        $this->assertSame('en', $user->employee->fresh()->preferred_language);
    }

    public function test_an_unsupported_language_is_refused(): void
    {
        $user = $this->worker('ko');

        $this->actingAs($user)
            ->postJson(route('attendance-app.language'), ['lang' => 'fr'])
            ->assertStatus(422);

        $this->assertSame('ko', $user->employee->fresh()->preferred_language);
    }

    public function test_viewing_someone_elses_screen_does_not_change_their_language(): void
    {
        // 관리자가 남의 화면을 들여다보다 언어를 바꾸면 그 사람 폰의 말이 바뀐다.
        $admin = $this->worker('ko', role: 'super_admin');
        $other = $this->worker('es');

        $this->actingAs($admin)
            ->postJson(route('attendance-app.language').'?as='.$other->employee->id, ['lang' => 'en'])
            ->assertOk()
            ->assertCookie(AppLocale::COOKIE, 'en', false);

        $this->assertSame('es', $other->employee->fresh()->preferred_language);
    }

    public function test_the_home_screen_tells_the_server_what_was_picked(): void
    {
        // 예전에는 localStorage 에만 두어서 서버가 선택을 몰랐다. 그 구조로 돌아가면
        // 아래 화면들이 다시 한국어가 된다.
        $html = $this->actingAs($this->worker('ko'))->get('/attendance-app')->assertOk()->getContent();

        $this->assertStringContainsString('rememberLangOnServer', $html);
        $this->assertStringContainsString('app_locale=', $html);
        $this->assertStringContainsString('attendance-app\\/language', $html, '@json 은 슬래시를 이스케이프한다.');
    }

    public function test_the_site_log_screen_follows_the_chosen_language(): void
    {
        // 사장이 겪은 바로 그 화면이다 — 첫 화면에서 EN 을 골랐는데 여기가 한글이었다.
        $user = $this->worker('ko');

        $korean = $this->actingAs($user)
            ->withUnencryptedCookie(AppLocale::COOKIE, 'ko')
            ->get('/attendance-app/ops-room')->assertOk()->getContent();
        $this->assertStringContainsString('현장 기록', $korean);
        $this->assertStringContainsString('눌러서 말하기', $korean);

        $english = $this->actingAs($user)
            ->withUnencryptedCookie(AppLocale::COOKIE, 'en')
            ->get('/attendance-app/ops-room')->assertOk()->getContent();
        $this->assertStringContainsString('Site log', $english);
        $this->assertStringContainsString('Press to speak', $english);
        $this->assertStringContainsString('Attach a photo', $english);
        // 화면에 «그려지는» 자리에 한글이 남으면 안 된다. 사전(TR)은 열쇠가 한국어라
        // 페이지 원문에는 한글이 들어 있다 — 그것은 보이는 글이 아니다.
        $this->assertStringNotContainsString('>눌러서 말하기<', $english, '영어 화면의 버튼에 한글이 남으면 안 된다.');
        $this->assertStringNotContainsString('>현장 기록<', $english);

        $spanish = $this->actingAs($user)
            ->withUnencryptedCookie(AppLocale::COOKIE, 'es')
            ->get('/attendance-app/ops-room')->assertOk()->getContent();
        $this->assertStringContainsString('Registro de obra', $spanish);
        $this->assertStringContainsString('Pulse para hablar', $spanish);
    }

    public function test_the_screen_and_its_script_read_the_same_dictionary(): void
    {
        // 사전이 두 벌이면 «버튼은 영어인데 알림 문구는 한글» 이 된다.
        $html = $this->actingAs($this->worker('ko'))
            ->withUnencryptedCookie(AppLocale::COOKIE, 'en')
            ->get('/attendance-app/ops-room')->assertOk()->getContent();

        $this->assertStringContainsString('const TR =', $html);
        $this->assertStringContainsString('Sorting it out', $html, '화면 안 JS 문구도 같은 사전에서 나와야 한다.');
    }

    public function test_every_phrase_the_site_log_uses_is_translated(): void
    {
        // 한 줄이라도 빠지면 그 자리만 한글로 남는다 — 반쯤 번역된 화면이 가장 나쁘다.
        $blade = file_get_contents(resource_path('views/attendance-app/ops-room.blade.php'));

        preg_match_all("/__\\('([^']+)'[,)]/", $blade, $a);
        preg_match_all("/(?<![\\w.])t\\('([^']+)'\\)/", $blade, $b);
        $keys = array_values(array_filter(
            array_unique(array_merge($a[1], $b[1])),
            fn (string $k): bool => preg_match('/[가-힣]/u', $k) === 1,
        ));

        $this->assertNotEmpty($keys);

        foreach (['en', 'es'] as $locale) {
            $dictionary = AppLocale::dictionary($locale);
            $missing = array_values(array_diff($keys, array_keys($dictionary)));
            $this->assertSame([], $missing, $locale.' 사전에 빠진 문구: '.implode(' | ', array_slice($missing, 0, 5)));
        }
    }

    private function worker(string $lang, string $role = 'worker'): User
    {
        $company = Company::query()->firstOrCreate(
            ['code' => 'XYZ'],
            ['name' => 'XYZ MEP', 'status' => 'active'],
        );
        $site = Site::query()->firstOrCreate(
            ['code' => 'SITE-1'],
            [
                'company_id' => $company->id,
                'name' => 'Test Site',
                'country' => 'US',
                'timezone' => 'America/Phoenix',
                'status' => 'active',
            ],
        );

        $user = User::query()->create([
            'name' => 'Worker '.uniqid(),
            'email' => 'w-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'access_role' => $role,
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        // 연결은 users.employee_id 쪽이다(User::employee 가 belongsTo).
        $employee = Employee::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'name' => $user->name,
            'employment_status' => 'active',
            'preferred_language' => $lang,
        ]);
        $user->forceFill(['employee_id' => $employee->id])->save();

        return $user->fresh();
    }
}

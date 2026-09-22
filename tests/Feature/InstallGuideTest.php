<?php

namespace Tests\Feature;

use App\Support\PhoneInstallGuide;
use App\Support\WorkerLang;
use Tests\TestCase;

/**
 * 안내서는 <b>지금 이 폰의 방법</b>을 맨 위에 펼쳐 보여야 한다.
 *
 * 현장에서 막히는 사람은 «버튼을 못 찾는» 사람이 아니라 <b>그 버튼이 자기 폰에 없는</b>
 * 사람이다. 판정이 틀리면 안내서는 그 사람에게 <b>없는 버튼을 누르라고</b> 말한다 —
 * 아무 안내도 없는 것보다 나쁘다.
 */
class InstallGuideTest extends TestCase
{
    private function open(string $userAgent)
    {
        return $this->withHeaders(['User-Agent' => $userAgent])->get('/app/install');
    }

    public function test_it_opens_without_signing_in(): void
    {
        // 이 화면을 가장 필요로 하는 사람은 아직 앱에 못 들어간 사람이다.
        $this->get('/app/install')->assertOk()->assertSee('홈 화면에 추가하는 방법');
    }

    public function test_kakaotalk_is_told_to_leave_the_in_app_browser_first(): void
    {
        // 현장에서 가장 흔한 실패다. 여기서 사파리 공유 버튼을 안내하면, 그 화면에는
        // 그 버튼이 아예 없어서 본인은 «안 된다» 고만 말할 수 있게 된다.
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 KAKAOTALK 10.4.5';

        $this->assertSame(PhoneInstallGuide::IN_APP, PhoneInstallGuide::detect($ua));
        $this->open($ua)->assertOk()->assertSee('다른 브라우저로 열기', false);
    }

    public function test_an_iphone_on_safari_gets_the_share_button_steps(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

        $this->assertSame(PhoneInstallGuide::IOS_SAFARI, PhoneInstallGuide::detect($ua));
        $this->open($ua)->assertOk()->assertSee('홈 화면에 추가', false);
    }

    public function test_an_iphone_on_chrome_is_sent_to_safari(): void
    {
        // 아이폰의 크롬도 UserAgent 에 Safari 를 달고 다닌다. 그것만 보고 판정하면
        // 공유 목록에 없는 항목을 누르라고 안내하게 된다.
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 CriOS/126.0 Mobile/15E148 Safari/604.1';

        $this->assertSame(PhoneInstallGuide::IOS_OTHER, PhoneInstallGuide::detect($ua));
        $this->open($ua)->assertOk()->assertSee('사파리에서만', false);
    }

    public function test_samsung_internet_gets_its_own_menu_names(): void
    {
        // 삼성 인터넷은 「앱 설치」 가 아니라 「현재 페이지 추가」 다. 크롬 안내를 주면
        // 그 사람은 있지도 않은 메뉴를 찾는다.
        $ua = 'Mozilla/5.0 (Linux; Android 14; SM-S911N) AppleWebKit/537.36 SamsungBrowser/25.0 Chrome/121 Mobile Safari/537.36';

        $this->assertSame(PhoneInstallGuide::SAMSUNG, PhoneInstallGuide::detect($ua));
        $this->open($ua)->assertOk()->assertSee('현재 페이지 추가', false);
    }

    public function test_android_chrome_gets_the_install_prompt_steps(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 14; SM-S911N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';

        $this->assertSame(PhoneInstallGuide::ANDROID, PhoneInstallGuide::detect($ua));
    }

    public function test_a_computer_is_told_to_use_a_phone(): void
    {
        $this->assertSame(PhoneInstallGuide::DESKTOP, PhoneInstallGuide::detect(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36',
        ));
    }

    public function test_every_phone_case_has_steps_in_every_language(): void
    {
        // 한 언어에서 한 경우만 비어 있으면, 그 언어를 쓰는 그 폰의 사람만 빈 화면을 본다.
        // 화면은 안 깨지므로 아무도 모른다.
        $dict = WorkerLang::installGuide();

        foreach (['ko', 'en', 'es'] as $lang) {
            foreach (PhoneInstallGuide::ALL as $case) {
                $this->assertArrayHasKey($case, $dict[$lang]['cases'], "{$lang} 에 {$case} 안내가 없습니다.");
                $this->assertNotEmpty($dict[$lang]['cases'][$case]['steps'], "{$lang}/{$case} 의 단계가 비어 있습니다.");
                $this->assertNotEmpty($dict[$lang]['cases'][$case]['name'], "{$lang}/{$case} 의 이름이 비어 있습니다.");
            }
        }
    }

    public function test_a_person_can_pick_their_phone_when_the_guess_is_wrong(): void
    {
        // 판정은 틀릴 수 있다. 틀렸을 때 사람이 넘어갈 길이 없으면 거기서 끝난다.
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh)'])
            ->get('/app/install?phone='.PhoneInstallGuide::SAMSUNG)
            ->assertOk()->assertSee('현재 페이지 추가', false);
    }

    public function test_a_made_up_phone_falls_back_to_what_the_browser_says(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh)'])
            ->get('/app/install?phone=nonsense')
            ->assertOk()->assertSee('컴퓨터로 보고 계십니다', false);
    }
}

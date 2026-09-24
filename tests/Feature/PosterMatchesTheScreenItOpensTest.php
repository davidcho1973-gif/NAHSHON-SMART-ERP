<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use App\Models\User;
use App\Support\QrPosters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 벽에 붙는 종이와 그 종이가 여는 화면은 <b>같은 이름·같은 색</b>이어야 한다.
 *
 * ── 겪은 일 ────────────────────────────────────────────────────────────
 * 등록 QR 이 여는 화면이 일곱 칸짜리(인사담당자용)에서 두 칸짜리(현장 공용)로 바뀌었다.
 * 화면은 바뀌었는데 포스터는 안 바뀌었다 — 고칠 이유가 없었기 때문이다. 그래서 벽에는
 * 「직원 간편 등록」 이라고 적힌 노란 종이가 걸려 있고, 찍으면 파란 「새 작업자 등록」
 * 화면이 열렸다. 게이트 포스터도 같은 노랑이라 둘은 벽에서 구별되지 않았다.
 *
 * 작업자에게 이것은 두 번 멈칫하는 일이다: 어느 종이를 찍어야 하는지 한 번,
 * 찍고 넘어가서 «잘못 찍었나» 하고 한 번.
 *
 * 문구 한 줄 고치고 끝내면 다음에 화면을 손볼 때 또 어긋난다. 그래서 시험이 지킨다.
 */
class PosterMatchesTheScreenItOpensTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);

        $this->site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'S-1', 'name' => '1 현장',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    public function test_the_registration_poster_is_named_after_the_screen_it_opens(): void
    {
        $poster = QrPosters::make($this->site, QrPosters::JOIN);
        $screen = (string) $this->get($poster['url'])->assertOk()->getContent();

        // 화면 제목이 포스터 제목 안에 그대로 있어야 한다.
        $this->assertStringContainsString('현장 출퇴근', $screen);
        $this->assertStringContainsString('등록 · 출근 · 퇴근', $poster['langs']['ko']['title']);
        $this->assertStringContainsString('현장 등록 · 출퇴근 QR', QrPosters::LABELS[QrPosters::GATE]);
    }

    public function test_the_registration_poster_is_the_same_colour_as_its_screen(): void
    {
        $poster = QrPosters::make($this->site, QrPosters::JOIN);
        $screen = (string) $this->get($poster['url'])->assertOk()->getContent();

        // 화면의 주 색상(버튼·칩)이 포스터 바탕색과 같아야 한다 — 찍고 넘어가서
        // 색이 바뀌면 «잘못 찍었나» 하고 멈칫한다.
        $this->assertStringContainsStringIgnoringCase($poster['accent']['bg'], $screen);
    }

    public function test_the_registration_poster_does_not_look_like_the_gate_poster(): void
    {
        // 벽에 나란히 붙는다. 둘이 같은 색이면 작업자가 아무거나 찍는다.
        $this->assertSame(
            QrPosters::make($this->site, QrPosters::GATE)['accent'],
            QrPosters::make($this->site, QrPosters::JOIN)['accent'],
        );
    }

    /**
     * 포스터는 화면에 <b>없는 것</b>을 가르치면 안 된다.
     *
     * PIN 을 걷어냈을 때(2026-09-23) 화면에서는 사라졌지만 벽에 붙은 종이는 그대로
     * "개인 PIN 설정" 을 시켰다. 문구가 화면과 다른 파일에 살기 때문에, 기능을 지워도
     * 종이는 아무도 안 고친다. 종이는 한 번 붙으면 몇 달을 간다.
     */
    public function test_no_poster_teaches_a_step_the_screen_no_longer_has(): void
    {
        foreach (QrPosters::ORDER as $key) {
            foreach (QrPosters::make($this->site, $key)['langs'] as $lang => $copy) {
                $words = $copy['title'].' '.$copy['hint'].' '.implode(' ', $copy['steps']);

                $this->assertStringNotContainsStringIgnoringCase(
                    'PIN', $words, "포스터 [{$key}/{$lang}] 가 없어진 PIN 을 가르칩니다.",
                );
            }
        }
    }

    /** 반대쪽도 잠근다 — 화면이 실제로 시키는 일(뒷 4자리)을 종이가 말해야 한다. */
    public function test_the_gate_poster_tells_people_how_the_gate_actually_opens(): void
    {
        $poster = QrPosters::make($this->site, QrPosters::GATE);
        $screen = (string) $this->get($poster['url'])->assertOk()->getContent();

        $this->assertStringContainsString('뒷 4자리', $screen, '게이트 화면이 뒷 4자리를 묻지 않습니다.');

        foreach (['ko' => '뒷 4자리', 'en' => 'last 4 digits', 'es' => '4 dígitos'] as $lang => $needle) {
            $this->assertStringContainsString(
                $needle, implode(' ', $poster['langs'][$lang]['steps']),
                "포스터 [gate/{$lang}] 가 들어가는 방법을 말하지 않습니다.",
            );
        }
    }

    /**
     * 포스터 색은 <b>화면에서만</b> 칠한다 — 종이는 흰 바탕에 판만 찍힌다.
     *
     * 공용 스타일의 인쇄 규칙(바탕 흰색)보다 각 화면의 색칠 한 줄이 뒤에 와서 그것을
     * 덮고 있었다. 그대로 뽑으면 A4 한 장이 가장자리까지 노랗게 나온다 — 한 장은
     * 몰라도 현장마다 몇 장씩 붙이는 종이다. 사람은 «잉크가 아깝다» 고 느끼면
     * 인쇄를 안 하고, 그러면 벽에 아무것도 안 붙는다.
     */
    public function test_the_poster_does_not_flood_the_paper_with_ink(): void
    {
        foreach ([QrPosters::GATE => 'gate.qr', QrPosters::JOIN => 'worker-join.qr'] as $key => $route) {
            $poster = QrPosters::make($this->site, $key);
            $html = (string) $this->get(route($route, ['site' => $this->site]))->assertOk()->getContent();

            // 화면 전용 블록을 걷어낸 나머지에 바닥 색칠이 남아 있으면 인쇄에도 칠해진다.
            $outsideScreen = (string) preg_replace('/@media screen\s*\{.*?\}\s*\}/s', '', $html);

            $this->assertStringNotContainsString(
                'body { background: '.$poster['accent']['bg'],
                $outsideScreen,
                "[{$key}] 포스터가 인쇄에서도 바닥을 칠합니다.",
            );
        }
    }

    public function test_every_poster_declares_a_colour(): void
    {
        // 색을 안 정한 포스터가 생기면 조용히 게이트 노랑으로 인쇄되어 또 섞인다.
        foreach (QrPosters::ORDER as $key) {
            $this->assertArrayHasKey($key, QrPosters::ACCENTS, "포스터 [{$key}] 의 색이 정해져 있지 않습니다.");
        }
    }

    public function test_the_printed_poster_actually_carries_its_colour(): void
    {
        $boss = User::query()->create([
            'name' => 'Boss', 'email' => 'boss@example.test', 'password' => Hash::make('x'),
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        // 「모아 인쇄」는 한 페이지에 여러 장을 세운다. 색이 판 안쪽에 걸려 있지 않으면
        // 마지막 포스터의 색이 전부를 덮는다.
        $html = (string) $this->actingAs($boss)
            ->get(route('qr-print.sheet', ['site' => $this->site]))
            ->assertOk()->getContent();

        foreach (QrPosters::ORDER as $key) {
            $this->assertStringContainsStringIgnoringCase(
                '--kakao: '.QrPosters::ACCENTS[$key]['bg'],
                $html,
                "모아 인쇄에서 포스터 [{$key}] 가 자기 색으로 서지 않습니다.",
            );
        }
    }
}

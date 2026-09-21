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
        $this->assertStringContainsString('새 작업자 등록', $screen);
        $this->assertStringContainsString('새 작업자 등록', $poster['langs']['ko']['title']);
        $this->assertStringContainsString('새 작업자 등록', QrPosters::LABELS[QrPosters::JOIN]);
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
        $this->assertNotSame(
            QrPosters::ACCENTS[QrPosters::GATE]['bg'],
            QrPosters::ACCENTS[QrPosters::JOIN]['bg'],
        );
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

        foreach ([QrPosters::GATE, QrPosters::JOIN] as $key) {
            $this->assertStringContainsStringIgnoringCase(
                '--kakao: '.QrPosters::ACCENTS[$key]['bg'],
                $html,
                "모아 인쇄에서 포스터 [{$key}] 가 자기 색으로 서지 않습니다.",
            );
        }
    }
}

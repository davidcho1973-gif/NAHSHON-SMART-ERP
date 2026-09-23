<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 「직원 QR 등록」 창이 작업자에게 건네는 링크는 <b>로그인 없이 열려야</b> 한다.
 *
 * ── 겪은 일 ────────────────────────────────────────────────────────────
 * 그 창의 「링크」 버튼은 /join 을 복사해 줬다. 그런데 /join 은 인사담당자 전용
 * (로그인 + 인사 권한)이다. 사장님이 그 링크를 복사해 작업자에게 보내면, 작업자는
 * 등록 화면 대신 <b>로그인 화면</b>을 만난다 — 그 사람에게는 아직 계정이 없으니
 * 거기서 끝이다. 그리고 왜 안 되는지는 아무도 모른다(링크는 멀쩡해 보인다).
 *
 * 공개 등록 화면이 따로 생겼는데(/join/w/{site}) 창의 링크만 옛 주소로 남아 있었다.
 * 화면이 바뀔 때 링크가 따라오지 않는 것 — 포스터 이름·색이 어긋났던 것과 같은 일이다.
 */
class WorkerJoinLinkIsPublicTest extends TestCase
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

    public function test_the_link_handed_to_a_worker_opens_without_logging_in(): void
    {
        // 계정 없는 사람이 그대로 열 수 있어야 한다.
        $this->followingRedirects()->get(route('worker-join.form', ['site' => $this->site]))
            ->assertOk()
            ->assertSee('처음 온 작업자 등록');
    }

    public function test_the_hr_only_link_still_needs_a_login(): void
    {
        // 반대쪽도 같이 잠가 둔다 — 이 문이 열려 버리면 위의 시험은 의미가 없어진다.
        $this->get(route('employee-join.entry'))->assertRedirect();
    }

    public function test_the_qr_window_never_hands_out_the_hr_only_address(): void
    {
        $boss = User::query()->create([
            'name' => 'Boss', 'email' => 'boss@example.test', 'password' => Hash::make('x'),
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $html = (string) $this->actingAs($boss)->get('/')->assertOk()->getContent();

        // 복사되는 링크와 포스터 주소는 둘 다 공개 경로(/join/w/…)를 가리켜야 한다.
        $this->assertStringContainsString("'/gate/' + s.id + '?lang=ko'", $html);
        $this->assertStringContainsString("'/gate/' + s.id + '/qr'", $html);

        // 작업자에게 주는 자리에 로그인 필요한 주소를 넣지 않는다.
        $this->assertStringNotContainsString("window.location.origin + '/join?lang=ko'", $html);

        // 인사담당자용 링크는 남아 있되, 무엇인지 적혀 있어야 한다.
        $this->assertStringContainsString('관리자용 · 로그인 필요', $html);
    }

    public function test_the_window_describes_the_two_fields_it_actually_asks_for(): void
    {
        $boss = User::query()->create([
            'name' => 'Boss2', 'email' => 'boss2@example.test', 'password' => Hash::make('x'),
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $html = (string) $this->actingAs($boss)->get('/')->assertOk()->getContent();

        // 설명이 화면과 어긋나면 사장님이 잘못된 안내를 현장에 내려보낸다.
        $this->assertStringContainsString('현장 QR 하나로 등록·출퇴근', $html);
        $this->assertStringNotContainsString('이름·소속회사·공정·직책·전화번호를 작성합니다', $html);
    }
}

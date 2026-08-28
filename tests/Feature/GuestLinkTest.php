<?php

namespace Tests\Feature;

use App\Models\GuestLink;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 손님 전용 링크 — 계정 없이 공정 현황만, 회수하면 그 자리에서 죽는가.
 */
class GuestLinkTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create(['code' => 'HFF-02', 'name' => '수소충전소 2호', 'status' => 'active']);
        $project = Project::create([
            'project_code' => 'HFF-P1', 'name' => '기계설비 공사',
            'construction_type' => 'equipment_setting', 'site_id' => $this->site->id,
        ]);

        // Stage → Task → SubTask 두 개(완료 1 · 예정 1, 공수 동일 → 진척 50%).
        $stage = WbsItem::create([
            'project_id' => $project->id, 'project_code' => 'HFF-P1', 'level' => WbsItem::LEVEL_STAGE,
            'wbs_code' => 'S1', 'name' => '배관 공사', 'sort_order' => 1, 'site_id' => $this->site->id,
        ]);
        $task = WbsItem::create([
            'project_id' => $project->id, 'project_code' => 'HFF-P1', 'level' => WbsItem::LEVEL_TASK,
            'parent_id' => $stage->id, 'wbs_code' => 'S1-T1', 'name' => '주배관', 'sort_order' => 2, 'site_id' => $this->site->id,
        ]);
        WbsItem::create([
            'project_id' => $project->id, 'project_code' => 'HFF-P1', 'level' => WbsItem::LEVEL_SUBTASK,
            'parent_id' => $task->id, 'wbs_code' => 'S1-T1-1', 'name' => '지하 배관', 'sort_order' => 3,
            'status' => WbsItem::STATUS_DONE, 'progress' => 100, 'manhours' => 10,
            'planned_end' => '2026-09-10', 'planned_cost' => 34567.89, 'site_id' => $this->site->id,
        ]);
        WbsItem::create([
            'project_id' => $project->id, 'project_code' => 'HFF-P1', 'level' => WbsItem::LEVEL_SUBTASK,
            'parent_id' => $task->id, 'wbs_code' => 'S1-T1-2', 'name' => '지상 배관', 'sort_order' => 4,
            'status' => '예정', 'progress' => 0, 'manhours' => 10,
            'planned_end' => '2026-11-20', 'site_id' => $this->site->id,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
    }

    private function api(User $user, string $method, array $args): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson('/smart-company-api/'.$method, ['args' => $args, 'siteId' => 'ALL']);
    }

    public function test_관리자가_링크를_만들고_손님이_로그인_없이_현황을_본다(): void
    {
        $res = $this->api($this->admin(), 'api_createGuestLink', ['HFF-02', '발주처 GC', 90]);
        $res->assertOk()->assertJson(['success' => true]);
        $url = $res->json('url');
        $this->assertNotEmpty($url);

        // 손님: 로그인 없이 그 주소를 연다.
        auth()->logout();
        $page = $this->get($url);
        $page->assertOk()
            ->assertSee('수소충전소 2호')
            ->assertSee('기계설비 공사')
            ->assertSee('배관 공사')
            ->assertSee('50%')            // 공수 동일 2건 중 1건 완료
            ->assertSee('2026-11-20');    // 예상 준공 = 마지막 계획 종료일

        // 돈은 이 화면의 세계에 없다 — 계획원가 숫자가 어떤 형태로도 새면 안 된다.
        $page->assertDontSee('34567')->assertDontSee('34,567');

        $link = GuestLink::query()->first();
        $this->assertSame(1, $link->view_count, '열람 횟수가 남아야 누가 보는지 안다');
    }

    public function test_회수하면_이미_전달된_링크도_그_자리에서_죽는다(): void
    {
        $admin = $this->admin();
        $url = $this->api($admin, 'api_createGuestLink', ['HFF-02', null, null])->json('url');
        $id = GuestLink::query()->value('id');

        $this->api($admin, 'api_revokeGuestLink', [$id])->assertOk()->assertJson(['success' => true]);

        auth()->logout();
        $this->get($url)->assertStatus(410)->assertSee('링크가 만료되었습니다');
    }

    public function test_기간이_지난_링크는_열리지_않는다(): void
    {
        $link = GuestLink::issue($this->site->id, null, 7, null);
        $link->update(['expires_at' => now()->subDay()]);

        $this->get($link->url())->assertStatus(410);
    }

    public function test_없는_토큰은_404_다(): void
    {
        $this->get('/guest/'.str_repeat('x', 40))->assertNotFound();
    }

    public function test_열람_전용_계정과_작업자는_링크를_만들_수_없다(): void
    {
        // 원청(열람 전용)은 쓰기 관문에서 잘린다.
        $client = User::factory()->create(['access_role' => 'client', 'account_status' => 'active']);
        $this->api($client, 'api_createGuestLink', ['HFF-02', null, 30])->assertStatus(403);

        // 작업자는 관문은 지나지만 서비스가 거절한다.
        $worker = User::factory()->create(['access_role' => 'worker', 'account_status' => 'active']);
        $this->api($worker, 'api_createGuestLink', ['HFF-02', null, 30])
            ->assertOk()->assertJson(['success' => false]);
        $this->assertSame(0, GuestLink::query()->count());
    }

    public function test_QR_카드는_관리자만_연다(): void
    {
        $link = GuestLink::issue($this->site->id, '발주처 GC', 30, null);

        $this->actingAs($this->admin())->get(route('guest-link.qr', $link))
            ->assertOk()->assertSee('수소충전소 2호')->assertSee($link->token);

        $worker = User::factory()->create(['access_role' => 'worker', 'account_status' => 'active']);
        $this->actingAs($worker)->get(route('guest-link.qr', $link))->assertForbidden();
    }
}

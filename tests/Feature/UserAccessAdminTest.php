<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Services\Admin\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 계정 · 권한 관리 — Filament Access Control 을 SPA 로 옮긴 화면의 뒷단.
 *
 * 이 화면은 권한을 나눠주는 곳이라 "누가 무엇을 줄 수 있는가" 가 전부다.
 * 화면에서 선택지를 숨기는 것은 방어가 아니므로, 요청을 직접 만들어 보내는 경우를 기준으로 검증한다.
 */
class UserAccessAdminTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $status = 'active', string $scope = 'all_sites'): User
    {
        return User::factory()->create([
            'access_role' => $role,
            'access_scope' => $scope,
            'account_status' => $status,
        ]);
    }

    /**
     * 마이그레이션이 심는 소유자 계정(super_admin)을 치운다.
     *
     * "마지막 슈퍼관리자" 규칙을 검증하려면 슈퍼관리자 수를 테스트가 직접 통제해야 하는데,
     * 시드 계정이 늘 한 명 더 있으면 "마지막" 상황 자체가 만들어지지 않는다.
     * 서비스를 거치지 않고 지워야 규칙에 막히지 않는다.
     */
    private function removeSeededOwner(): void
    {
        User::query()->where('access_role', 'super_admin')->delete();
    }

    private function api(string $method, array $args = []): TestResponse
    {
        return $this->postJson('/smart-company-api/'.$method, ['args' => $args, 'siteId' => 'ALL']);
    }

    private function svc(): UserAccessService
    {
        return app(UserAccessService::class);
    }

    // ── 화면 접근 ────────────────────────────────────────────────────────

    public function test_a_worker_cannot_read_the_account_list(): void
    {
        $this->actingAs($this->user('worker', 'active', 'self'));

        $this->assertFalse($this->svc()->list()['success'], '작업자에게 전 직원 계정 목록이 보이면 안 된다');
    }

    public function test_a_client_account_cannot_read_the_account_list(): void
    {
        // 원청(client)은 열람 전용이지만, 열람 대상에 남의 계정 목록은 포함되지 않는다.
        $this->actingAs($this->user('client'));

        $this->assertFalse($this->svc()->list()['success']);
    }

    public function test_a_suspended_admin_cannot_read_the_account_list(): void
    {
        $this->actingAs($this->user('admin', 'suspended'));

        $this->assertFalse($this->svc()->list()['success'], '정지된 계정은 권한이 남아 있어도 막혀야 한다');
    }

    public function test_an_hr_manager_can_read_the_account_list(): void
    {
        $me = $this->user('hr_manager');
        $this->actingAs($me);
        $worker = $this->user('worker');

        $res = $this->svc()->list();
        $this->assertTrue($res['success']);
        $ids = array_column($res['rows'], 'id');
        $this->assertContains($me->id, $ids);
        $this->assertContains($worker->id, $ids);
    }

    // ── 권한 상승 방지 ───────────────────────────────────────────────────

    public function test_an_hr_manager_cannot_grant_admin_or_super_admin(): void
    {
        $this->actingAs($this->user('hr_manager'));

        $roles = array_keys($this->svc()->assignableRoles());
        $this->assertNotContains('super_admin', $roles);
        $this->assertNotContains('admin', $roles);
        $this->assertContains('site_manager', $roles);
    }

    public function test_an_admin_cannot_grant_super_admin(): void
    {
        $this->actingAs($this->user('admin'));

        $this->assertNotContains('super_admin', array_keys($this->svc()->assignableRoles()));
    }

    public function test_only_a_super_admin_can_grant_super_admin(): void
    {
        $this->actingAs($this->user('super_admin'));

        $this->assertContains('super_admin', array_keys($this->svc()->assignableRoles()));
    }

    public function test_escalation_is_refused_not_silently_downgraded(): void
    {
        // 조용히 낮추면 화면에는 저장됐다고 뜨는데 실제 권한은 다른 상태가 된다.
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->save([
            'name' => '침입자', 'email' => 'x@dasolprism.com',
            'role' => 'super_admin', 'scope' => 'all_sites', 'status' => 'active',
        ]);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('role', $res['errors']);
        $this->assertSame(0, User::where('email', 'x@dasolprism.com')->count(), '거절된 요청은 계정을 만들지 않아야 한다');
    }

    // ── 자기 잠금 방지 ───────────────────────────────────────────────────

    public function test_you_cannot_change_your_own_role(): void
    {
        $me = $this->user('admin');
        $this->actingAs($me);

        $res = $this->svc()->save([
            'id' => $me->id, 'name' => $me->name, 'email' => $me->email,
            'role' => 'worker', 'scope' => 'self', 'status' => 'active',
        ]);

        $this->assertFalse($res['success']);
        $this->assertSame('admin', $me->fresh()->access_role, '실수로 자기를 강등하면 되돌릴 사람이 없을 수 있다');
    }

    public function test_you_cannot_suspend_or_delete_yourself(): void
    {
        // 시드 소유자가 남아 있으므로 "마지막 슈퍼관리자" 규칙에는 걸리지 않는다.
        $me = $this->user('super_admin');
        $this->actingAs($me);

        $this->assertFalse($this->svc()->setStatus($me->id, 'suspended')['success']);
        $this->assertFalse($this->svc()->delete($me->id)['success']);
        $this->assertSame('active', $me->fresh()->account_status);
    }

    // ── 마지막 슈퍼관리자 보호 ───────────────────────────────────────────

    public function test_the_last_super_admin_cannot_be_demoted(): void
    {
        $this->removeSeededOwner();
        $admin = $this->user('admin');
        $last = $this->user('super_admin');
        $this->actingAs($admin);

        $res = $this->svc()->save([
            'id' => $last->id, 'name' => $last->name, 'email' => $last->email,
            'role' => 'site_manager', 'scope' => 'all_sites', 'status' => 'active',
        ]);

        $this->assertFalse($res['success']);
        $this->assertSame('super_admin', $last->fresh()->access_role, '시스템을 관리할 사람이 0명이 되면 안 된다');
    }

    public function test_the_last_super_admin_cannot_be_suspended_or_deleted(): void
    {
        $this->removeSeededOwner();
        $admin = $this->user('admin');
        $last = $this->user('super_admin');
        $this->actingAs($admin);

        $this->assertFalse($this->svc()->setStatus($last->id, 'suspended')['success']);
        $this->assertFalse($this->svc()->delete($last->id)['success']);
        $this->assertNotNull(User::find($last->id));
    }

    public function test_a_super_admin_can_be_removed_when_another_one_remains(): void
    {
        $this->removeSeededOwner();
        $me = $this->user('super_admin');
        $other = $this->user('super_admin');
        $this->actingAs($me);

        $this->assertTrue($this->svc()->delete($other->id)['success']);
        $this->assertNull(User::find($other->id));
    }

    public function test_an_admin_cannot_delete_a_super_admin(): void
    {
        // 시드 소유자가 남아 있어 "마지막" 규칙에는 걸리지 않는다 — 역할 규칙만 검증된다.
        $target = $this->user('super_admin');
        $this->actingAs($this->user('admin'));

        $this->assertFalse($this->svc()->delete($target->id)['success']);
        $this->assertNotNull(User::find($target->id));
    }

    public function test_an_hr_manager_cannot_delete_accounts_at_all(): void
    {
        $target = $this->user('worker');
        $this->actingAs($this->user('hr_manager'));

        $this->assertFalse($this->svc()->delete($target->id)['success'], '인사담당자는 만들고 고칠 수는 있어도 지울 수는 없다');
        $this->assertNotNull(User::find($target->id));
    }

    // ── 입력 검증 ────────────────────────────────────────────────────────

    public function test_a_scope_of_site_requires_a_site(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->save([
            'name' => '김현장', 'email' => 'site@dasolprism.com',
            'role' => 'site_manager', 'scope' => 'site', 'status' => 'active',
        ]);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('siteId', $res['errors'], '현장 범위인데 현장이 비면 그 사람은 아무것도 못 본다');
    }

    public function test_a_duplicate_email_is_reported_on_the_email_field(): void
    {
        $this->actingAs($this->user('admin'));
        $existing = $this->user('worker');

        $res = $this->svc()->save([
            'name' => '중복', 'email' => strtoupper($existing->email),
            'role' => 'worker', 'scope' => 'self', 'status' => 'active',
        ]);

        $this->assertFalse($res['success']);
        $this->assertSame('이미 등록된 이메일입니다.', $res['errors']['email']);
    }

    public function test_editing_keeps_your_own_email(): void
    {
        $this->actingAs($this->user('admin'));
        $row = $this->user('worker');

        $res = $this->svc()->save([
            'id' => $row->id, 'name' => '이름만 변경', 'email' => $row->email,
            'role' => 'worker', 'scope' => 'self', 'status' => 'active',
        ]);

        $this->assertTrue($res['success'], '자기 이메일을 그대로 두고 저장할 수 있어야 한다');
        $this->assertSame('이름만 변경', $row->fresh()->name);
    }

    public function test_a_new_account_is_created_with_the_chosen_scope(): void
    {
        $this->actingAs($this->user('admin'));
        $site = Site::create(['code' => 'LG_ESS_PH', 'name' => 'LG PHOENIX', 'timezone' => 'America/Phoenix', 'status' => 'active']);

        $res = $this->svc()->save([
            'name' => '강민철', 'email' => 'MC.Kang@DasolPrism.com',
            'role' => 'site_manager', 'scope' => 'site', 'status' => 'active', 'siteId' => (string) $site->id,
            'notes' => 'LG 현장 담당',
        ]);

        $this->assertTrue($res['success']);
        $new = User::find($res['id']);
        $this->assertSame('mc.kang@dasolprism.com', $new->email, '이메일은 소문자로 정규화돼야 중복 판정이 샌다');
        $this->assertSame($site->id, $new->allowed_site_id);
        $this->assertSame('LG 현장 담당', $new->access_notes);
    }

    // ── API 경로 ─────────────────────────────────────────────────────────

    public function test_the_api_exposes_the_screen_to_an_admin(): void
    {
        $this->actingAs($this->user('admin'));

        $this->api('api_getUserAccessList')->assertOk()->assertJsonPath('success', true);
        $this->api('api_getUserAccessOptions')->assertOk()->assertJsonPath('success', true);
    }

    public function test_the_api_blocks_a_read_only_client_from_mutating(): void
    {
        // 열람 전용 계정은 api_get 이 아닌 엔드포인트에서 컨트롤러가 먼저 403 을 준다.
        $this->actingAs($this->user('client'));

        $this->api('api_saveUserAccess', [['name' => 'x', 'email' => 'x@x.com']])->assertStatus(403);
    }

    public function test_the_api_blocks_a_worker_from_mutating_even_though_it_is_not_read_only(): void
    {
        // worker 는 열람 전용 역할이 아니라 컨트롤러를 통과한다 — 그래서 서비스가 막아야 한다.
        $this->actingAs($this->user('worker', 'active', 'self'));

        $this->api('api_saveUserAccess', [['name' => '침입', 'email' => 'w@dasolprism.com', 'role' => 'admin']])
            ->assertOk()->assertJsonPath('success', false);
        $this->assertSame(0, User::where('email', 'w@dasolprism.com')->count());
    }

    public function test_options_only_offer_roles_the_caller_may_grant(): void
    {
        $this->actingAs($this->user('hr_manager'));

        $values = array_column($this->svc()->options()['roles'], 'value');
        $this->assertNotContains('super_admin', $values);
        $this->assertNotContains('admin', $values);
    }

    public function test_the_list_marks_your_own_row(): void
    {
        $me = $this->user('admin');
        $this->actingAs($me);

        $rows = collect($this->svc()->list()['rows']);
        $this->assertTrue($rows->firstWhere('id', $me->id)['isSelf']);
    }
}

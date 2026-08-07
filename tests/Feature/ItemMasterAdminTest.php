<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use App\Services\Admin\ItemMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 품목 · 분류 마스터 — Filament ItemResource / ItemCategoryResource 를 SPA 로 옮긴 화면의 뒷단.
 *
 * 자재를 세는 단위를 정하는 곳이라, 이름을 고치는 것보다 "쓰이는 것을 못 지우게" 하는 쪽이 중요하다.
 */
class ItemMasterAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $this->otherCompany = Company::create(['code' => 'OT', 'name' => 'Other Co', 'status' => 'active']);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ], $extra));
    }

    private function svc(): ItemMasterService
    {
        return app(ItemMasterService::class);
    }

    private function category(array $extra = []): ItemCategory
    {
        return ItemCategory::create(array_merge(['name' => '전선', 'status' => 'active', 'sort' => 0], $extra));
    }

    private function item(array $extra = []): Item
    {
        return Item::create(array_merge(['name' => 'THHN #12', 'status' => 'active'], $extra));
    }

    // ── 접근 ────────────────────────────────────────────────────────────

    public function test_a_worker_cannot_read_the_item_master(): void
    {
        $this->actingAs($this->user('worker', ['access_scope' => 'self']));

        $this->assertFalse($this->svc()->list()['success']);
    }

    public function test_a_site_manager_can_read_but_not_manage(): void
    {
        $this->actingAs($this->user('site_manager'));

        $res = $this->svc()->list();
        $this->assertTrue($res['success']);
        $this->assertFalse($res['canManage'], '현장소장은 마스터를 바꾸는 사람이 아니다');
        $this->assertFalse($this->svc()->saveItem(['name' => '몰래'])['success']);
    }

    // ── 회사 범위 ────────────────────────────────────────────────────────

    public function test_a_company_scoped_user_sees_their_own_plus_shared(): void
    {
        $mine = $this->item(['name' => '우리 품목', 'company_id' => $this->company->id]);
        $shared = $this->item(['name' => '전사 공통', 'company_id' => null]);
        $theirs = $this->item(['name' => '남의 품목', 'company_id' => $this->otherCompany->id]);

        $this->actingAs($this->user('payroll', [
            'access_scope' => 'company', 'allowed_company_id' => $this->company->id,
        ]));

        $names = array_column($this->svc()->list()['items'], 'name');
        $this->assertContains('우리 품목', $names);
        $this->assertContains('전사 공통', $names, '전사 공통은 모두가 봐야 한다');
        $this->assertNotContains('남의 품목', $names);
    }

    public function test_a_company_scoped_user_cannot_create_a_shared_item(): void
    {
        // 회사를 비우고 저장하면 "전사 공통" 이 된다. 회사 범위 계정이 그렇게 하면
        // 남의 회사 목록까지 오염되므로 자기 회사로 묶는다.
        $this->actingAs($this->user('payroll', [
            'access_scope' => 'company', 'allowed_company_id' => $this->company->id,
        ]));

        $res = $this->svc()->saveItem(['name' => '슬쩍 공통', 'companyId' => '']);

        $this->assertTrue($res['success']);
        $this->assertSame($this->company->id, Item::find($res['id'])->company_id);
    }

    public function test_an_admin_creates_a_shared_item_when_no_company_is_chosen(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveItem(['name' => '공통 자재', 'companyId' => '']);

        $this->assertNull(Item::find($res['id'])->company_id);
    }

    // ── 코드 중복 ────────────────────────────────────────────────────────

    public function test_a_duplicate_code_within_the_same_company_is_refused(): void
    {
        $this->item(['code' => 'W-12', 'company_id' => $this->company->id]);
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveItem(['name' => '중복', 'code' => 'W-12', 'companyId' => (string) $this->company->id]);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('code', $res['errors']);
    }

    public function test_the_same_code_is_allowed_in_a_different_company(): void
    {
        $this->item(['code' => 'W-12', 'company_id' => $this->company->id]);
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveItem(['name' => '다른 회사', 'code' => 'W-12', 'companyId' => (string) $this->otherCompany->id]);

        $this->assertTrue($res['success'], '코드는 회사 안에서만 유일하면 된다');
    }

    public function test_editing_keeps_your_own_code(): void
    {
        $row = $this->item(['code' => 'W-12', 'company_id' => $this->company->id]);
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveItem([
            'id' => $row->id, 'name' => '이름만 변경', 'code' => 'W-12', 'companyId' => (string) $this->company->id,
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame('이름만 변경', $row->fresh()->name);
    }

    public function test_a_negative_cost_is_refused(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveItem(['name' => '음수', 'standardCost' => '-5']);
        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('standardCost', $res['errors']);

        $res = $this->svc()->saveItem(['name' => '문자', 'standardCost' => 'abc']);
        $this->assertArrayHasKey('standardCost', $res['errors']);
    }

    // ── 분류 트리 ────────────────────────────────────────────────────────

    public function test_a_category_cannot_be_its_own_parent(): void
    {
        $c = $this->category();
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveCategory(['id' => $c->id, 'name' => '전선', 'parentId' => (string) $c->id]);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('parentId', $res['errors']);
    }

    public function test_a_category_cannot_take_its_own_descendant_as_parent(): void
    {
        // 상위 ← 하위 ← 손자 를 만들고, 상위의 부모로 손자를 지정하면 고리가 된다.
        $top = $this->category(['name' => '자재']);
        $mid = $this->category(['name' => '전기', 'parent_id' => $top->id]);
        $leaf = $this->category(['name' => '전선', 'parent_id' => $mid->id]);
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveCategory(['id' => $top->id, 'name' => '자재', 'parentId' => (string) $leaf->id]);

        $this->assertFalse($res['success'], '고리가 생기면 화면이 무한히 돈다');
        $this->assertNull($top->fresh()->parent_id);
    }

    public function test_a_normal_reparent_is_allowed(): void
    {
        $top = $this->category(['name' => '자재']);
        $leaf = $this->category(['name' => '전선']);
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveCategory(['id' => $leaf->id, 'name' => '전선', 'parentId' => (string) $top->id]);

        $this->assertTrue($res['success']);
        $this->assertSame($top->id, $leaf->fresh()->parent_id);
    }

    // ── 삭제 보호 ────────────────────────────────────────────────────────

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $c = $this->category();
        $this->item(['item_category_id' => $c->id]);
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->deleteCategory($c->id);

        $this->assertFalse($res['success'], '지우면 품목의 분류가 통째로 비어버린다');
        $this->assertStringContainsString('1개', $res['error']);
        $this->assertNotNull(ItemCategory::find($c->id));
    }

    public function test_a_category_with_children_cannot_be_deleted(): void
    {
        $top = $this->category(['name' => '자재']);
        $this->category(['name' => '전선', 'parent_id' => $top->id]);
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->deleteCategory($top->id);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('하위 분류', $res['error']);
    }

    public function test_an_empty_category_can_be_deleted(): void
    {
        $c = $this->category();
        $this->actingAs($this->user('admin'));

        $this->assertTrue($this->svc()->deleteCategory($c->id)['success']);
        $this->assertNull(ItemCategory::find($c->id));
    }

    // ── 목록 부가 정보 ───────────────────────────────────────────────────

    public function test_the_category_list_carries_item_and_child_counts(): void
    {
        $top = $this->category(['name' => '자재']);
        $leaf = $this->category(['name' => '전선', 'parent_id' => $top->id]);
        $this->item(['item_category_id' => $leaf->id]);
        $this->item(['item_category_id' => $leaf->id, 'name' => 'THHN #10']);
        $this->actingAs($this->user('admin'));

        $rows = collect($this->svc()->list()['categories']);
        $this->assertSame(2, $rows->firstWhere('name', '전선')['itemCount']);
        $this->assertSame(1, $rows->firstWhere('name', '자재')['childCount']);
        $this->assertSame(0, $rows->firstWhere('name', '자재')['itemCount']);
    }

    public function test_the_api_exposes_the_screen_and_blocks_read_only_clients(): void
    {
        $this->actingAs($this->user('admin'));
        $this->postJson('/smart-company-api/api_getItemMaster', ['args' => [], 'siteId' => 'ALL'])
            ->assertOk()->assertJsonPath('success', true);

        $this->actingAs($this->user('client'));
        $this->postJson('/smart-company-api/api_saveItem', ['args' => [['name' => 'x']], 'siteId' => 'ALL'])
            ->assertStatus(403);
    }
}

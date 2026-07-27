<?php

namespace Tests\Feature;

use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\OpsIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 상황실 원문 기록 수정·삭제 — 원문은 "왜 이렇게 반영됐지?"를 되짚는 근거라
 * 고칠 수 있게 하되 이력을 남기고, 이미 반영된 항목이 딸린 원문은 지우지 못하게 한다.
 */
class OpsBatchManageTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function batch(string $raw = '천장 배관 12개 완료'): OpsIntakeBatch
    {
        return OpsIntakeBatch::create([
            'site_id' => $this->site->id,
            'source' => 'paste',
            'raw_text' => $raw,
            'parsed_count' => 1,
            'actionable_count' => 1,
            'noise_count' => 0,
        ]);
    }

    private function item(OpsIntakeBatch $batch, string $status = 'pending'): OpsIntakeItem
    {
        return OpsIntakeItem::create([
            'site_id' => $this->site->id,
            'ops_intake_batch_id' => $batch->id,
            'category' => 'progress',
            'summary' => '배관 진행률 60%',
            'raw_text' => '천장 배관 12개 완료',
            'confidence' => 90,
            'target_type' => 'wbs',
            'target_code' => 'P1-A1',
            'proposed' => ['progress' => 60],
            'status' => $status,
        ]);
    }

    private function admin(string $role = 'admin'): User
    {
        return User::factory()->create(['access_role' => $role, 'account_status' => 'active']);
    }

    private function api(string $method, array $args = []): TestResponse
    {
        return $this->postJson('/smart-company-api/'.$method, ['args' => $args, 'siteId' => 'ALL']);
    }

    public function test_editing_keeps_the_first_version_and_records_who_changed_it(): void
    {
        $batch = $this->batch('천장 배간 12개 완료');   // 오타
        $user = $this->admin();

        $this->actingAs($user)->api('api_updateOpsBatch', [$batch->id, '천장 배관 12개 완료'])
            ->assertStatus(200)->assertJson(['success' => true]);

        $batch->refresh();
        $this->assertSame('천장 배관 12개 완료', $batch->raw_text);
        $this->assertSame('천장 배간 12개 완료', $batch->original_text);
        $this->assertSame($user->id, $batch->edited_by_id);
        $this->assertNotNull($batch->edited_at);
    }

    public function test_second_edit_does_not_overwrite_the_first_version(): void
    {
        $batch = $this->batch('처음 원문');
        $svc = app(OpsIntakeService::class);

        $svc->updateBatch($batch->id, '두 번째');
        $svc->updateBatch($batch->id, '세 번째');

        $batch->refresh();
        $this->assertSame('세 번째', $batch->raw_text);
        $this->assertSame('처음 원문', $batch->original_text);
    }

    public function test_editing_to_the_same_text_is_a_no_op(): void
    {
        $batch = $this->batch('그대로');

        $res = app(OpsIntakeService::class)->updateBatch($batch->id, '그대로');

        $this->assertTrue($res['unchanged']);
        $this->assertNull($batch->refresh()->original_text);
    }

    public function test_text_cannot_be_emptied(): void
    {
        $batch = $this->batch('내용');

        $res = app(OpsIntakeService::class)->updateBatch($batch->id, '   ');

        $this->assertFalse($res['success']);
        $this->assertSame('내용', $batch->refresh()->raw_text);
    }

    public function test_delete_removes_the_batch_and_its_items(): void
    {
        $batch = $this->batch();
        $item = $this->item($batch, 'pending');

        $this->actingAs($this->admin())->api('api_deleteOpsBatch', [$batch->id])
            ->assertStatus(200)->assertJson(['success' => true, 'deletedItems' => 1]);

        $this->assertDatabaseMissing('ops_intake_batches', ['id' => $batch->id]);
        $this->assertDatabaseMissing('ops_intake_items', ['id' => $item->id]);
    }

    public function test_delete_is_blocked_while_applied_items_exist(): void
    {
        $batch = $this->batch();
        $this->item($batch, 'applied');

        $res = $this->actingAs($this->admin())->api('api_deleteOpsBatch', [$batch->id]);

        $res->assertStatus(200)->assertJson(['success' => false, 'appliedCount' => 1]);
        $this->assertStringContainsString('되돌린', $res->json('error'));
        $this->assertDatabaseHas('ops_intake_batches', ['id' => $batch->id]);
    }

    public function test_only_managers_may_edit_or_delete(): void
    {
        $batch = $this->batch('원문');

        foreach (['foreman', 'worker', 'payroll'] as $role) {
            $this->actingAs($this->admin($role))
                ->api('api_updateOpsBatch', [$batch->id, '바꿈'])
                ->assertStatus(200)->assertJson(['success' => false]);
            $this->actingAs($this->admin($role))
                ->api('api_deleteOpsBatch', [$batch->id])
                ->assertStatus(200)->assertJson(['success' => false]);
        }

        $this->assertSame('원문', $batch->refresh()->raw_text);
    }

    public function test_read_only_client_is_blocked_before_reaching_the_endpoint(): void
    {
        $batch = $this->batch();

        $this->actingAs($this->admin('client'))
            ->api('api_deleteOpsBatch', [$batch->id])
            ->assertStatus(403);
    }

    public function test_listing_flags_edited_and_applied_and_exposes_permission(): void
    {
        $batch = $this->batch();
        $this->item($batch, 'applied');
        app(OpsIntakeService::class)->updateBatch($batch->id, '고친 원문');

        $res = $this->actingAs($this->admin())->api('api_getOpsBatches');

        $res->assertStatus(200)->assertJson(['canManage' => true]);
        $this->assertTrue($res->json('batches.0.edited'));
        $this->assertSame(1, $res->json('batches.0.applied'));

        $this->actingAs($this->admin('foreman'))->api('api_getOpsBatches')
            ->assertJson(['canManage' => false]);
    }

    public function test_detail_returns_the_edit_trail(): void
    {
        $batch = $this->batch('처음');
        $user = $this->admin();
        app(OpsIntakeService::class)->updateBatch($batch->id, '고침', $user->id);

        $res = $this->actingAs($user)->api('api_getOpsBatch', [$batch->id]);

        $res->assertStatus(200)->assertJson([
            'success' => true,
            'raw' => '고침',
            'originalText' => '처음',
            'editedBy' => $user->name,
        ]);
        $this->assertNotNull($res->json('editedAt'));
    }

    public function test_missing_batch_is_reported(): void
    {
        $this->actingAs($this->admin())->api('api_updateOpsBatch', [999999, 'x'])
            ->assertStatus(200)->assertJson(['success' => false]);
        $this->actingAs($this->admin())->api('api_deleteOpsBatch', [999999])
            ->assertStatus(200)->assertJson(['success' => false]);
    }
}

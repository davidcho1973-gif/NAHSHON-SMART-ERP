<?php

namespace Tests\Feature;

use App\Models\SafetyWorkItem;
use App\Models\Site;
use App\Models\User;
use App\Services\Safety\SafetyWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SafetyWorkPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private SafetyWorkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SafetyWorkService::class);
    }

    private function sampleItem(array $overrides = []): array
    {
        return array_merge([
            'id' => 'WRK-TEST-001',
            'project' => 'LGES-AZ 전기',
            'site' => '2층',
            'title' => '케이블 포설',
            'crew' => 3,
            'qty' => 30,
            'unit' => 'm',
            'due' => '오늘 17:00',
            'planStatus' => '승인완료',
            'tbmStatus' => '완료',
            'closeStatus' => '마감대기',
            'progressStatus' => '미분석',
            'progress' => 60,
            'doneQty' => 18,
            'totalQty' => 30,
            'workText' => '천장 배선 포설',
            'closeText' => '18m 완료',
            'signatures' => [
                ['name' => '김철수', 'role' => '전기공', 'signed' => true],
                ['name' => '임성훈', 'role' => '감시자', 'signed' => false],
            ],
            'issues' => [
                ['type' => '미조치', 'text' => '자재 부족', 'owner' => '구매팀', 'status' => '조치중'],
            ],
        ], $overrides);
    }

    public function test_save_persists_item_with_signatures_and_issues(): void
    {
        $this->service->save([$this->sampleItem()], 'ALL', null);

        $item = SafetyWorkItem::where('work_code', 'WRK-TEST-001')->with(['signatures', 'issues'])->first();
        $this->assertNotNull($item);
        $this->assertSame('케이블 포설', $item->title);
        $this->assertSame(2, $item->signatures->count());
        $this->assertSame(1, $item->issues->count());
        $this->assertSame('미조치', $item->issues->first()->type);
    }

    public function test_round_trips_back_to_client_shape(): void
    {
        $this->service->save([$this->sampleItem()], 'ALL', null);

        $items = collect($this->service->items('ALL'));
        $mine = $items->firstWhere('id', 'WRK-TEST-001');
        $this->assertNotNull($mine);
        $this->assertSame('2층', $mine['site']);
        $this->assertSame(60, $mine['progress']);
        $this->assertTrue($mine['signatures'][0]['signed']);
    }

    public function test_signature_timestamp_is_server_stamped_and_preserved(): void
    {
        Carbon::setTestNow('2026-06-24 07:42:00');
        $this->service->save([$this->sampleItem()], 'ALL', null);

        $sig = SafetyWorkItem::where('work_code', 'WRK-TEST-001')->first()->signatures()->where('sort_order', 0)->first();
        $this->assertNotNull($sig->signed_at);
        $firstStamp = $sig->signed_at->toDateTimeString();

        // Re-save later: the original sign-off time must not move.
        Carbon::setTestNow('2026-06-24 15:00:00');
        $this->service->save([$this->sampleItem()], 'ALL', null);

        $sig->refresh();
        $this->assertSame($firstStamp, $sig->signed_at->toDateTimeString());
        Carbon::setTestNow();
    }

    public function test_unsigning_clears_timestamp(): void
    {
        $this->service->save([$this->sampleItem()], 'ALL', null);
        $unsigned = $this->sampleItem();
        $unsigned['signatures'][0]['signed'] = false;
        $this->service->save([$unsigned], 'ALL', null);

        $sig = SafetyWorkItem::where('work_code', 'WRK-TEST-001')->first()->signatures()->where('sort_order', 0)->first();
        $this->assertFalse((bool) $sig->signed);
        $this->assertNull($sig->signed_at);
    }

    public function test_items_are_scoped_by_site(): void
    {
        $site = Site::create(['name' => 'LG AZ', 'code' => 'LGES-AZ']);
        $this->service->save([$this->sampleItem(['id' => 'WRK-SITE-1'])], 'LGES-AZ', null);
        $this->service->save([$this->sampleItem(['id' => 'WRK-GLOBAL'])], 'ALL', null);

        $scoped = collect($this->service->items('LGES-AZ'))->pluck('id')->all();
        $this->assertContains('WRK-SITE-1', $scoped);
        $this->assertNotContains('WRK-GLOBAL', $scoped);
        $this->assertSame($site->id, SafetyWorkItem::where('work_code', 'WRK-SITE-1')->value('site_id'));
    }

    public function test_api_endpoints_round_trip(): void
    {
        $user = User::factory()->create(['access_role' => 'safety_manager', 'account_status' => 'active']);

        $save = $this->actingAs($user)->postJson('/smart-company-api/api_saveSafetyWorkItems', [
            'args' => [[$this->sampleItem(['id' => 'WRK-API-1'])]],
            'siteId' => 'ALL',
        ]);
        $save->assertStatus(200)->assertJsonPath('success', true)->assertJsonPath('saved', 1);

        $get = $this->actingAs($user)->postJson('/smart-company-api/api_getSafetyWorkItems', ['siteId' => 'ALL']);
        $get->assertStatus(200)->assertJsonPath('success', true);
        $this->assertContains('WRK-API-1', collect($get->json('items'))->pluck('id')->all());
    }
}

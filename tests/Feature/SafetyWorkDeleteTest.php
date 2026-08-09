<?php

namespace Tests\Feature;

use App\Models\SafetyWorkIssue;
use App\Models\SafetyWorkItem;
use App\Models\SafetyWorkSignature;
use App\Services\Safety\SafetyWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 오늘 작업 흐름 — 잘못 등록된 작업 카드 1건 삭제(서명·이슈 포함, work_code 기준).
 */
class SafetyWorkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function card(string $code): SafetyWorkItem
    {
        $item = SafetyWorkItem::create([
            'work_code' => $code, 'site_id' => null, 'project' => 'P', 'title' => 'T', 'work_date' => '2026-07-20',
        ]);
        SafetyWorkSignature::create(['safety_work_item_id' => $item->id, 'name' => '김철수', 'signed' => true]);
        SafetyWorkIssue::create(['safety_work_item_id' => $item->id, 'type' => 'risk', 'body' => '이슈']);

        return $item;
    }

    public function test_delete_removes_only_target_card_with_signatures_and_issues(): void
    {
        $this->card('WRK-1');
        $keep = $this->card('WRK-2');

        $result = app(SafetyWorkService::class)->deleteWork('WRK-1', 'ALL');

        $this->assertTrue($result['success']);
        $this->assertNull(SafetyWorkItem::where('work_code', 'WRK-1')->first());
        $this->assertNotNull(SafetyWorkItem::where('work_code', 'WRK-2')->first());
        // 남은 카드의 서명·이슈만 유지.
        $this->assertSame(1, SafetyWorkSignature::count());
        $this->assertSame(1, SafetyWorkIssue::count());
        $this->assertSame($keep->id, SafetyWorkSignature::first()->safety_work_item_id);
    }

    public function test_delete_missing_work_returns_error(): void
    {
        $result = app(SafetyWorkService::class)->deleteWork('WRK-NONE', 'ALL');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }
}

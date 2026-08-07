<?php

namespace Tests\Feature;

use App\Models\WbsItem;
use App\Services\Wbs\WbsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 공정관리 상세 편집에 추가된 필드(투입조·장비·안전위험도·진척률)가 저장되는지.
 */
class WbsEditFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function sub(): WbsItem
    {
        return WbsItem::create([
            'project_code' => 'EDT-01', 'level' => 'subtask', 'wbs_code' => 'EDT-01-W-A010',
            'node_no' => '1.1.1', 'name' => '배관', 'trade' => 'PLUMB', 'status' => '진행중',
        ]);
    }

    public function test_crew_text_is_saved_and_reparsed_into_size_and_roles(): void
    {
        $sub = $this->sub();
        app(WbsService::class)->updateRow('EDT-01-W-A010', ['투입조' => '2 electricians + 1 helper']);

        $sub->refresh();
        $this->assertSame('2 electricians + 1 helper', $sub->crew_text);
        $this->assertSame(3.0, (float) $sub->crew_size);          // CrewParser 재파싱
        $this->assertNotEmpty($sub->crew_roles);
    }

    public function test_equipment_comma_string_becomes_array(): void
    {
        $this->sub();
        app(WbsService::class)->updateRow('EDT-01-W-A010', ['장비' => '시저리프트, 용접기']);

        $this->assertSame(['시저리프트', '용접기'], WbsItem::where('wbs_code', 'EDT-01-W-A010')->first()->equipment);
    }

    public function test_ehs_and_progress_are_saved_and_clamped(): void
    {
        $this->sub();
        app(WbsService::class)->updateRow('EDT-01-W-A010', ['EHS' => 'high', '진척률' => '150']);

        $item = WbsItem::where('wbs_code', 'EDT-01-W-A010')->first();
        $this->assertSame('high', $item->ehs);
        $this->assertSame(100, $item->progress); // 0~100 clamp
    }
}

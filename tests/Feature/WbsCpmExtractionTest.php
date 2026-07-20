<?php

namespace Tests\Feature;

use App\Models\WbsItem;
use App\Services\Wbs\ClaudeWbsAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 충실한 CPM 추출: AI 가 공정표를 요약하지 않고 모든 액티비티 행을 그대로 추출하면,
 * ScheduleImporter 의 검증된 영속화(마일스톤 → 공종 → 액티비티)로 저장되어
 * 선행관계·여유·임계경로·날짜·투입조가 전부 보존된다.
 */
class WbsCpmExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCpmResponse(): void
    {
        config(['services.anthropic.api_key' => 'sk-ant-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'activities' => [
                            [
                                'id' => 'A010', 'name_ko' => '착공 및 인허가', 'name_en' => 'NTP & Permit',
                                'dur' => 2, 'preds' => [], 'es' => '2026-08-03', 'ef' => '2026-08-10',
                                'ls' => '2026-08-03', 'lf' => '2026-08-10', 'float_days' => '0', 'is_critical' => true,
                                'cost' => 24000, 'trade' => 'GC', 'crew' => '2 carpenters + 3 laborers',
                            ],
                            [
                                'id' => 'A020', 'name_ko' => '천장 전기 배관', 'name_en' => 'Overhead Conduit',
                                'dur' => 5, 'preds' => ['A010'], 'es' => '2026-08-12', 'ef' => '2026-08-18',
                                'ls' => '2026-08-15', 'lf' => '2026-08-21', 'float_days' => '3', 'is_critical' => false,
                                'cost' => 40000, 'trade' => 'ELEC', 'crew' => '2 electricians + lifts',
                            ],
                            [
                                'id' => 'A030', 'name_ko' => '위생기구 설치', 'name_en' => 'Plumbing Fixtures',
                                'dur' => 3, 'preds' => ['A020'], 'es' => '2026-09-10', 'ef' => '2026-09-15',
                                'ls' => '2026-09-10', 'lf' => '2026-09-15', 'float_days' => '0', 'is_critical' => true,
                                'cost' => 12000, 'trade' => 'PLUMB', 'crew' => '1 plumber + 1 helper',
                            ],
                        ],
                        'milestones' => [
                            ['name' => '슬래브 폐합', 'date' => '2026-08-20'],
                            ['name' => '사용승인(CofO)', 'date' => '2026-10-06'],
                        ],
                        'stages' => [],
                    ]),
                ]],
            ]),
        ]);
    }

    public function test_cpm_is_extracted_row_for_row_not_summarized(): void
    {
        $this->fakeCpmResponse();

        $result = app(ClaudeWbsAnalyzer::class)->processManual('CPMX-01');

        $this->assertTrue($result['success']);
        $this->assertSame('cpm', $result['results'][0]['mode']);
        $this->assertSame(3, $result['results'][0]['activities']);
        // 마일스톤 2개 → Stage 2개, 공종별 Task, 액티비티 3개 모두 SubTask.
        $this->assertSame(2, $result['results'][0]['stages']);
        $this->assertSame(3, $result['results'][0]['subTasks']);
        $this->assertSame(3, WbsItem::where('project_code', 'CPMX-01')->where('level', 'subtask')->count());
    }

    public function test_every_cpm_field_is_preserved_on_the_activity(): void
    {
        $this->fakeCpmResponse();

        app(ClaudeWbsAnalyzer::class)->processManual('CPMX-01');

        $a010 = WbsItem::where('wbs_code', 'CPMX-01-W-A010')->first();
        $this->assertNotNull($a010);
        $this->assertSame('A010', $a010->activity_id);
        $this->assertSame('GC', $a010->trade);
        $this->assertNull($a010->company);                       // 협력사는 사람이 나중에 배정
        $this->assertTrue($a010->is_critical);
        $this->assertSame('2026-08-03', $a010->planned_start->toDateString());
        $this->assertSame('2026-08-10', $a010->planned_end->toDateString());
        $this->assertSame(0, $a010->float_days);
        $this->assertSame('2 carpenters + 3 laborers', $a010->crew_text);
        $this->assertSame(5.0, (float) $a010->crew_size);        // CrewParser: 2 + 3

        $a020 = WbsItem::where('wbs_code', 'CPMX-01-W-A020')->first();
        $this->assertSame(['A010'], $a020->preds);               // 선행관계 보존
        $this->assertSame(3, $a020->float_days);
        $this->assertFalse($a020->is_critical);
        $this->assertSame('ELEC', $a020->trade);
    }

    public function test_activities_are_phased_by_milestone(): void
    {
        $this->fakeCpmResponse();

        app(ClaudeWbsAnalyzer::class)->processManual('CPMX-01');

        // A010(EF 08-10)·A020(EF 08-18)은 "슬래브 폐합"(08-20) 구간, A030(EF 09-15)은 "사용승인" 구간.
        $slab = WbsItem::where('project_code', 'CPMX-01')->where('level', 'stage')->where('name', '슬래브 폐합')->first();
        $cofo = WbsItem::where('project_code', 'CPMX-01')->where('level', 'stage')->where('name', '사용승인(CofO)')->first();
        $this->assertNotNull($slab);
        $this->assertNotNull($cofo);

        $a010 = WbsItem::where('wbs_code', 'CPMX-01-W-A010')->first();
        $a030 = WbsItem::where('wbs_code', 'CPMX-01-W-A030')->first();
        // 액티비티 → 공종(Task) → Stage 이므로 부모의 부모가 Stage.
        $this->assertSame($slab->id, $a010->parent->parent_id);
        $this->assertSame($cofo->id, $a030->parent->parent_id);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CommunicationRoom;
use App\Models\OpsIntakeItem;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\Site;
use App\Models\WbsItem;
use App\Services\Communication\CommunicationService;
use App\Services\Ops\OpsIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 현장 상황실 1단계 — 자유 형식 글(카톡 붙여넣기 포함)을 판독해 "반영 제안"으로 만든다.
 */
class OpsIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'NAHSHON', 'name' => 'NAHSHON MEP', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $company->id, 'code' => 'LG-PH', 'name' => 'LG Phoenix', 'status' => 'active']);

        Project::firstOrCreate(['project_code' => 'LG-01'], ['name' => 'LG', 'construction_type' => 'equipment_setting']);
        WbsItem::create([
            'project_code' => 'LG-01', 'site_id' => $this->site->id, 'level' => 'subtask',
            'wbs_code' => 'LG-01-W-A100', 'activity_id' => 'A100', 'node_no' => '1.1',
            'name' => '천장 전기 배관', 'status' => '검수완료', 'crew_size' => 3,
            'planned_start' => now()->toDateString(), 'planned_end' => now()->addDays(2)->toDateString(),
        ]);
    }

    private function service(): OpsIntakeService
    {
        return app(OpsIntakeService::class);
    }

    /** Gemini 응답을 고정한다. */
    private function fakeAi(array $items): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => $items])]]]]],
            ]),
        ]);
    }

    // ── 방 생성 ────────────────────────────────────────────

    public function test_ops_room_is_created_for_the_site(): void
    {
        app(CommunicationService::class)->ensureSiteRooms($this->site);

        $room = CommunicationRoom::where('site_id', $this->site->id)
            ->where('type', CommunicationRoom::TYPE_SITE_OPS)->first();

        $this->assertNotNull($room, '현장마다 상황실 방이 있어야 한다.');
        $this->assertStringContainsString('현장 상황실', $room->name);
        $this->assertFalse((bool) $room->is_read_only, '작업자가 올릴 수 있어야 한다.');
    }

    // ── 판독 ───────────────────────────────────────────────

    public function test_progress_message_becomes_a_proposal_on_the_matched_activity(): void
    {
        $this->fakeAi([[
            'raw_text' => '천장 배관 20개 중 12개 완료했습니다',
            'speaker' => '김철수', 'category' => 'progress', 'confidence' => 90,
            'summary' => '천장 전기 배관 12/20 완료 → 진행률 60%',
            'target_type' => 'wbs', 'target_code' => 'LG-01-W-A100', 'target_name' => '천장 전기 배관',
            'occurred_on' => now()->toDateString(),
            'proposed' => ['progress' => 60], 'question' => '',
        ]]);

        $r = $this->service()->ingest('천장 배관 20개 중 12개 완료했습니다', $this->site);

        $this->assertTrue($r['success']);
        $this->assertSame(1, $r['actionable']);
        $item = OpsIntakeItem::firstOrFail();
        $this->assertSame('progress', $item->category);
        $this->assertSame('LG-01-W-A100', $item->target_code);
        $this->assertSame(60, $item->proposed['progress']);
        $this->assertSame('pending', $item->status, '확신도가 높고 대상이 명확하면 바로 적용 대기여야 한다.');
    }

    public function test_small_talk_is_filed_as_noise_and_hidden(): void
    {
        $this->fakeAi([[
            'raw_text' => '오늘 점심 뭐 먹지', 'speaker' => '', 'category' => 'noise',
            'confidence' => 95, 'summary' => '잡담', 'target_type' => '', 'target_code' => '',
            'target_name' => '', 'occurred_on' => '', 'proposed' => [], 'question' => '',
        ]]);

        $r = $this->service()->ingest('오늘 점심 뭐 먹지', $this->site);

        $this->assertSame(1, $r['noise']);
        $this->assertSame(0, $r['actionable']);
        $this->assertSame('ignored', OpsIntakeItem::firstOrFail()->status);
        $this->assertSame(0, $this->service()->pending($this->site->id)['count'], '잡담은 목록에 뜨면 안 된다.');
    }

    public function test_invented_activity_code_is_rejected_and_turned_into_a_question(): void
    {
        // AI 가 존재하지 않는 코드를 지어낸 경우 — 엉뚱한 작업에 반영되면 안 된다.
        $this->fakeAi([[
            'raw_text' => '배관 작업 밀렸어요', 'speaker' => '', 'category' => 'progress', 'confidence' => 95,
            'summary' => '배관 지연', 'target_type' => 'wbs', 'target_code' => 'LG-01-W-XXXX',
            'target_name' => '없는 작업', 'occurred_on' => '', 'proposed' => ['status' => '지연'], 'question' => '',
        ]]);

        $this->service()->ingest('배관 작업 밀렸어요', $this->site);

        $item = OpsIntakeItem::firstOrFail();
        $this->assertNull($item->target_code, '목록에 없는 코드는 버려야 한다.');
        $this->assertSame('needs_input', $item->status);
        $this->assertNotNull($item->question);
    }

    public function test_low_confidence_becomes_needs_input(): void
    {
        $this->fakeAi([[
            'raw_text' => '그거 좀 밀렸어', 'speaker' => '', 'category' => 'progress', 'confidence' => 35,
            'summary' => '어떤 작업인지 불명확', 'target_type' => '', 'target_code' => '', 'target_name' => '',
            'occurred_on' => '', 'proposed' => [], 'question' => '어느 작업인가요?',
        ]]);

        $this->service()->ingest('그거 좀 밀렸어', $this->site);

        $this->assertSame('needs_input', OpsIntakeItem::firstOrFail()->status);
    }

    public function test_pasted_chat_log_splits_into_multiple_items(): void
    {
        ProcurementItem::create([
            'project_code' => 'LG-01', 'site_id' => $this->site->id, 'wbs_code' => 'LG-01-W-A100',
            'status' => '발주', 'vendor' => 'Graybar', 'po_no' => 'PO-118',
        ]);
        $this->fakeAi([
            [
                'raw_text' => '천장 배관 오늘 끝냈습니다', 'speaker' => '김철수', 'category' => 'progress',
                'confidence' => 90, 'summary' => '천장 배관 완료', 'target_type' => 'wbs',
                'target_code' => 'LG-01-W-A100', 'target_name' => '천장 전기 배관',
                'occurred_on' => now()->toDateString(), 'proposed' => ['status' => '완료'], 'question' => '',
            ],
            [
                'raw_text' => '그레이바 자재 화요일 도착', 'speaker' => '이민준', 'category' => 'procurement',
                'confidence' => 85, 'summary' => 'PO-118 ETA 변경', 'target_type' => 'procurement',
                'target_code' => 'PO-118', 'target_name' => 'Graybar',
                'occurred_on' => '', 'proposed' => ['eta' => now()->addDays(3)->toDateString()], 'question' => '',
            ],
            [
                'raw_text' => '다들 수고하셨습니다', 'speaker' => '', 'category' => 'noise',
                'confidence' => 99, 'summary' => '인사', 'target_type' => '', 'target_code' => '',
                'target_name' => '', 'occurred_on' => '', 'proposed' => [], 'question' => '',
            ],
        ]);

        $r = $this->service()->ingest("김철수: 천장 배관 오늘 끝냈습니다\n이민준: 그레이바 자재 화요일 도착\n박: 다들 수고하셨습니다", $this->site);

        $this->assertSame(3, $r['parsed']);
        $this->assertSame(2, $r['actionable']);
        $this->assertSame(1, $r['noise']);
        // 발주 건도 정상 매칭돼야 한다.
        $po = OpsIntakeItem::where('category', 'procurement')->firstOrFail();
        $this->assertSame('PO-118', $po->target_code);
    }

    public function test_empty_input_is_rejected(): void
    {
        $this->assertFalse($this->service()->ingest('   ', $this->site)['success']);
    }

    public function test_ai_failure_is_reported_without_crashing(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('boom', 500)]);

        $r = $this->service()->ingest('천장 배관 완료', $this->site);

        $this->assertFalse($r['success']);
        $this->assertStringContainsString('판독', $r['error']);
    }

    public function test_dismiss_removes_item_from_pending(): void
    {
        $this->fakeAi([[
            'raw_text' => 'x', 'speaker' => '', 'category' => 'issue', 'confidence' => 90,
            'summary' => '이슈', 'target_type' => '', 'target_code' => '', 'target_name' => '',
            'occurred_on' => '', 'proposed' => [], 'question' => '',
        ]]);
        $this->service()->ingest('x', $this->site);
        $id = OpsIntakeItem::firstOrFail()->id;

        $this->service()->dismiss($id, '중복');

        $this->assertSame(0, $this->service()->pending($this->site->id)['count']);
        $this->assertSame('dismissed', OpsIntakeItem::find($id)->status);
    }
}

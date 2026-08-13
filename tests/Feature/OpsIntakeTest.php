<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CommunicationRoom;
use App\Models\OpsIntakeBatch;
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
        $company = Company::create(['code' => 'ABC ENG', 'name' => 'ABC ENG', 'status' => 'active']);
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

    // ── 2단계: 실제 반영 ────────────────────────────────────

    /** 판독 없이 제안 1건을 직접 만든다(반영 로직만 검증). */
    private function proposal(array $attrs = []): OpsIntakeItem
    {
        return OpsIntakeItem::create(array_merge([
            'site_id' => $this->site->id, 'source' => 'paste', 'raw_text' => '천장 배관 12/20',
            'category' => 'progress', 'confidence' => 90, 'summary' => '진행률 60%',
            'target_type' => 'wbs', 'target_code' => 'LG-01-W-A100', 'target_name' => '천장 전기 배관',
            'proposed' => ['progress' => 60], 'status' => 'pending',
        ], $attrs));
    }

    public function test_apply_writes_progress_to_the_wbs_row(): void
    {
        $item = $this->proposal();

        $r = $this->service()->apply($item->id);

        $this->assertTrue($r['success'], $r['error'] ?? '');
        $this->assertSame(60, (int) WbsItem::where('wbs_code', 'LG-01-W-A100')->value('progress'));
        $item->refresh();
        $this->assertSame('applied', $item->status);
        $this->assertNotNull($item->applied_at);
        // 되돌리기용 이전 값이 보관돼야 한다.
        $this->assertArrayHasKey('progress', $item->previous);
    }

    public function test_apply_writes_schedule_dates(): void
    {
        $item = $this->proposal([
            'proposed' => ['planned_start' => '2026-08-03', 'planned_end' => '2026-08-05'],
        ]);

        $this->assertTrue($this->service()->apply($item->id)['success']);

        $w = WbsItem::where('wbs_code', 'LG-01-W-A100')->firstOrFail();
        $this->assertSame('2026-08-03', $w->planned_start->toDateString());
        $this->assertSame('2026-08-05', $w->planned_end->toDateString());
    }

    public function test_revert_restores_the_previous_value(): void
    {
        WbsItem::where('wbs_code', 'LG-01-W-A100')->update(['progress' => 20]);
        $item = $this->proposal();
        $this->service()->apply($item->id);
        $this->assertSame(60, (int) WbsItem::where('wbs_code', 'LG-01-W-A100')->value('progress'));

        $r = $this->service()->revert($item->id);

        $this->assertTrue($r['success'], $r['error'] ?? '');
        $this->assertSame(20, (int) WbsItem::where('wbs_code', 'LG-01-W-A100')->value('progress'), '되돌리면 이전 값으로 복원돼야 한다.');
    }

    public function test_fields_outside_the_whitelist_are_ignored(): void
    {
        // AI 가 엉뚱한 필드를 제안해도 그대로 쓰이면 안 된다.
        $item = $this->proposal(['proposed' => ['site_id' => 999, 'is_critical' => true]]);

        $r = $this->service()->apply($item->id);

        $this->assertFalse($r['success']);
        $this->assertSame($this->site->id, (int) WbsItem::where('wbs_code', 'LG-01-W-A100')->value('site_id'));
    }

    public function test_apply_without_target_is_refused(): void
    {
        $item = $this->proposal(['target_code' => null, 'status' => 'needs_input']);

        $r = $this->service()->apply($item->id);

        $this->assertFalse($r['success']);
        $this->assertStringContainsString('대상', $r['error']);
    }

    public function test_applying_twice_is_refused(): void
    {
        $item = $this->proposal();
        $this->service()->apply($item->id);

        $this->assertFalse($this->service()->apply($item->id)['success']);
    }

    public function test_procurement_eta_is_applied_to_the_purchase_order(): void
    {
        ProcurementItem::create([
            'project_code' => 'LG-01', 'site_id' => $this->site->id, 'wbs_code' => 'LG-01-W-A100',
            'status' => '발주완료', 'vendor' => 'Graybar', 'po_no' => 'PO-118',
        ]);
        $item = $this->proposal([
            'category' => 'procurement', 'target_type' => 'procurement', 'target_code' => 'PO-118',
            'target_name' => 'Graybar', 'proposed' => ['eta' => '2026-08-04'],
        ]);

        $r = $this->service()->apply($item->id);

        $this->assertTrue($r['success'], $r['error'] ?? '');
        $this->assertSame('2026-08-04', ProcurementItem::where('po_no', 'PO-118')->firstOrFail()->eta->toDateString());
    }

    public function test_apply_all_skips_items_that_need_input(): void
    {
        $this->proposal();                                              // 반영 가능
        $this->proposal(['status' => 'needs_input', 'target_code' => null]); // 건너뜀

        $r = $this->service()->applyAll($this->site->id);

        $this->assertSame(1, $r['applied']);
        $this->assertSame(1, $this->service()->pending($this->site->id)['count'], '확인 필요 항목은 남아 있어야 한다.');
    }

    public function test_business_rule_failure_is_reported_not_bypassed(): void
    {
        // 상태 변경은 TBM 게이트 등 기존 규칙을 그대로 탄다 — 규칙을 우회하면 안 된다.
        $item = $this->proposal(['proposed' => ['status' => '완료']]);

        $r = $this->service()->apply($item->id);

        if (! ($r['success'] ?? false)) {
            $this->assertNotEmpty($r['error']);
            $this->assertSame('pending', $item->fresh()->status, '실패하면 반영됨으로 바뀌면 안 된다.');
        } else {
            $this->assertSame('완료', WbsItem::where('wbs_code', 'LG-01-W-A100')->value('status'));
        }
    }

    // ── 사진 첨부 판독 ──────────────────────────────────────

    private function photoItem(array $over = []): array
    {
        return array_merge([
            'raw_text' => '[사진]', 'speaker' => '', 'category' => 'progress', 'confidence' => 80,
            'summary' => '사진상 트레이 2/3 구간 포설 완료', 'target_type' => 'wbs',
            'target_code' => 'LG-01-W-A100', 'target_name' => '천장 전기 배관',
            'occurred_on' => '', 'proposed' => ['progress' => 66], 'question' => '',
        ], $over);
    }

    public function test_photo_only_input_is_accepted(): void
    {
        $this->fakeAi([$this->photoItem()]);

        $r = $this->service()->ingest('', $this->site, null, [
            ['data' => 'aGVsbG8=', 'mime_type' => 'image/jpeg'],
        ]);

        $this->assertTrue($r['success'], '사진만 올려도 판독돼야 한다.');
        $this->assertSame(66, OpsIntakeItem::firstOrFail()->proposed['progress']);
    }

    public function test_photo_is_sent_to_the_vision_engine_as_inline_data(): void
    {
        $this->fakeAi([$this->photoItem()]);

        $this->service()->ingest('2층 트레이 사진입니다', $this->site, null, [
            ['data' => 'aGVsbG8=', 'mime_type' => 'image/jpeg'],
        ]);

        Http::assertSent(function ($request): bool {
            $parts = data_get($request->data(), 'contents.0.parts', []);
            $hasImage = collect($parts)->contains(fn ($p) => isset($p['inline_data']['data']));
            $prompt = json_encode($parts, JSON_UNESCAPED_UNICODE);

            // 사진이 실려 나가고, 사진 판독 지침도 프롬프트에 붙어야 한다.
            return $hasImage && str_contains((string) $prompt, '첨부 사진 판독');
        });
    }

    public function test_data_url_prefixed_photo_is_accepted(): void
    {
        $this->fakeAi([$this->photoItem()]);

        $r = $this->service()->ingest('', $this->site, null, [
            ['data' => 'data:image/png;base64,iVBORw0KGgo=', 'mime_type' => ''],
        ]);

        $this->assertTrue($r['success']);
    }

    public function test_non_image_attachment_is_rejected(): void
    {
        $this->fakeAi([$this->photoItem()]);

        // PDF 만 넣고 글이 없으면 판독할 게 없다.
        $r = $this->service()->ingest('', $this->site, null, [
            ['data' => 'JVBERi0=', 'mime_type' => 'application/pdf'],
        ]);

        $this->assertFalse($r['success']);
    }

    public function test_text_only_input_does_not_use_the_photo_prompt(): void
    {
        $this->fakeAi([$this->photoItem()]);

        $this->service()->ingest('천장 배관 끝났습니다', $this->site);

        Http::assertSent(function ($request): bool {
            $prompt = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return ! str_contains((string) $prompt, '첨부 사진 판독');
        });
    }

    // ── 원문 보관(대화가 사라지지 않게) ─────────────────────

    public function test_original_conversation_is_kept_as_a_batch(): void
    {
        $this->fakeAi([[
            'raw_text' => '천장 배관 끝', 'speaker' => '김철수', 'category' => 'progress', 'confidence' => 90,
            'summary' => '완료', 'target_type' => 'wbs', 'target_code' => 'LG-01-W-A100',
            'target_name' => '천장 전기 배관', 'occurred_on' => '', 'proposed' => ['status' => '완료'], 'question' => '',
        ]]);
        $conversation = "김철수: 천장 배관 끝\n박현우: 오늘 수고하셨습니다";

        $r = $this->service()->ingest($conversation, $this->site);

        $batch = OpsIntakeBatch::firstOrFail();
        $this->assertSame($conversation, $batch->raw_text, '붙여넣은 대화 원문이 그대로 남아야 한다.');
        $this->assertSame($batch->id, $r['batchId']);
        // 판독된 항목이 그 원문에 연결된다 — "왜 이렇게 반영됐지?"를 되짚을 수 있게.
        $this->assertSame($batch->id, OpsIntakeItem::firstOrFail()->ops_intake_batch_id);
    }

    public function test_batch_records_counts_and_appears_in_history(): void
    {
        $this->fakeAi([
            [
                'raw_text' => 'a', 'speaker' => '', 'category' => 'progress', 'confidence' => 90, 'summary' => 's',
                'target_type' => 'wbs', 'target_code' => 'LG-01-W-A100', 'target_name' => 'n',
                'occurred_on' => '', 'proposed' => ['progress' => 50], 'question' => '',
            ],
            [
                'raw_text' => 'b', 'speaker' => '', 'category' => 'noise', 'confidence' => 99, 'summary' => '잡담',
                'target_type' => '', 'target_code' => '', 'target_name' => '', 'occurred_on' => '',
                'proposed' => [], 'question' => '',
            ],
        ]);
        $this->service()->ingest("작업 절반 했습니다\n다들 수고", $this->site);

        $list = $this->service()->batches($this->site->id);

        $this->assertSame(1, $list['count']);
        $this->assertSame(2, $list['batches'][0]['parsed']);
        $this->assertSame(1, $list['batches'][0]['actionable']);
        $this->assertSame(1, $list['batches'][0]['noise']);
        $this->assertStringContainsString('작업 절반', $list['batches'][0]['preview']);
    }

    public function test_batch_detail_returns_original_text_with_its_items(): void
    {
        $this->fakeAi([[
            'raw_text' => 'a', 'speaker' => '', 'category' => 'issue', 'confidence' => 90, 'summary' => '개구부 위험',
            'target_type' => '', 'target_code' => '', 'target_name' => '', 'occurred_on' => '',
            'proposed' => [], 'question' => '',
        ]]);
        $id = $this->service()->ingest('3층 개구부 덮개 없습니다', $this->site)['batchId'];

        $d = $this->service()->batch($id);

        $this->assertTrue($d['success']);
        $this->assertSame('3층 개구부 덮개 없습니다', $d['raw']);
        $this->assertCount(1, $d['items']);
        $this->assertSame('개구부 위험', $d['items'][0]['summary']);
    }

    public function test_photo_only_batch_is_still_recorded(): void
    {
        $this->fakeAi([[
            'raw_text' => '[사진]', 'speaker' => '', 'category' => 'progress', 'confidence' => 70, 'summary' => '사진 판독',
            'target_type' => '', 'target_code' => '', 'target_name' => '', 'occurred_on' => '',
            'proposed' => [], 'question' => '',
        ]]);

        $this->service()->ingest('', $this->site, null, [['data' => 'aGVsbG8=', 'mime_type' => 'image/jpeg']]);

        $batch = OpsIntakeBatch::firstOrFail();
        $this->assertSame(1, $batch->image_count);
        $this->assertSame('(사진만 첨부)', $batch->preview());
    }

    public function test_missing_batch_returns_error(): void
    {
        $this->assertFalse($this->service()->batch(999999)['success']);
    }
}

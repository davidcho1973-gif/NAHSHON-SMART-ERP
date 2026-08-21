<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\OpsIntakeItem;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\WbsItem;
use App\Services\Ops\OpsConflictDetector;
use App\Services\Ops\OpsRoomAutoReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 확정된 것과 다르게 흘러가면 AI 가 끼어들어 묻는다.
 *
 * 도면에는 4인치인데 대화에서는 6인치로 시공한다고 한다. 진행률이 80% 로 적혀 있는데
 * 30% 라고 한다. 이런 어긋남은 <b>새 사실</b>일 수도 있고 <b>착오</b>일 수도 있다 —
 * 어느 쪽인지는 사람만 안다.
 *
 * 그래서 지켜야 할 것이 둘이다.
 *  1. 어긋난 값을 <b>조용히 덮어쓰지 않는다.</b> 잘못 읽은 한 줄이 공정표를 흔들면
 *     나중에 추적조차 안 된다.
 *  2. 근거를 들고 <b>그 자리에서 묻는다.</b> "확인 필요" 라고만 하면 아무도 안 본다.
 */
class OpsConflictTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'CONF-CO', 'name' => 'Conflict Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'CONF-SITE', 'name' => '현장', 'status' => 'active',
        ]);
    }

    private function wbs(array $attributes = []): WbsItem
    {
        return WbsItem::create(array_merge([
            'site_id' => $this->site->id,
            'project_code' => 'CONF-SITE',
            'wbs_code' => 'A140',
            'name' => '3층 동편 배관',
            'level' => WbsItem::LEVEL_SUBTASK,
            'status' => '진행중',
            'progress' => 80,
        ], $attributes));
    }

    // ── 코드가 확실히 아는 어긋남 ──────────────────────────────────────

    public function test_progress_going_backwards_is_questioned_not_applied(): void
    {
        $this->wbs(['progress' => 80]);

        $conflict = app(OpsConflictDetector::class)->check('wbs', 'A140', ['progress' => 30], $this->site->id);

        $this->assertNotNull($conflict, '진행률이 80%→30% 로 뒤로 가는데 아무도 묻지 않았습니다.');
        $this->assertStringContainsString('80%', $conflict['expected']);
        $this->assertStringContainsString('30%', $conflict['heard']);
        $this->assertStringContainsString('A140', $conflict['with']);
    }

    public function test_a_small_difference_is_not_nagged_about(): void
    {
        // 1~2% 오차까지 붙잡으면 잔소리가 되고, 그러면 진짜 경고도 안 읽힌다.
        $this->wbs(['progress' => 80]);

        $this->assertNull(app(OpsConflictDetector::class)->check('wbs', 'A140', ['progress' => 78], $this->site->id));
    }

    public function test_progress_moving_forward_is_normal(): void
    {
        $this->wbs(['progress' => 40]);

        $this->assertNull(app(OpsConflictDetector::class)->check('wbs', 'A140', ['progress' => 65], $this->site->id));
    }

    public function test_a_finished_task_turning_unfinished_is_questioned(): void
    {
        $this->wbs(['status' => WbsItem::STATUS_DONE, 'progress' => 100]);

        $conflict = app(OpsConflictDetector::class)->check('wbs', 'A140', ['status' => '진행중'], $this->site->id);

        $this->assertNotNull($conflict);
        $this->assertStringContainsString('재시공', $conflict['note']);
    }

    public function test_a_delivery_date_jumping_far_is_questioned(): void
    {
        ProcurementItem::create([
            'site_id' => $this->site->id,
            'project_code' => 'CONF-SITE',
            'wbs_code' => 'A140',
            'po_no' => 'PO-2026-11',
            'vendor' => 'Ferguson',
            'item_name' => '배관 자재',
            'eta' => '2026-09-01',
        ]);

        $late = app(OpsConflictDetector::class)->check('procurement', 'PO-2026-11', ['eta' => '2026-11-15'], $this->site->id);
        $this->assertNotNull($late, '납기가 두 달 넘게 밀리는데 아무도 묻지 않았습니다.');
        $this->assertStringContainsString('밀립니다', $late['note']);

        $early = app(OpsConflictDetector::class)->check('procurement', 'PO-2026-11', ['eta' => '2026-07-01'], $this->site->id);
        $this->assertNotNull($early);
        $this->assertStringContainsString('출고일', $early['note'], '출고일과 도착일 혼동은 흔한 착오라 짚어 줘야 합니다.');

        // 며칠 차이는 정상적인 조정이다.
        $this->assertNull(app(OpsConflictDetector::class)->check('procurement', 'PO-2026-11', ['eta' => '2026-09-05'], $this->site->id));
    }

    public function test_the_question_carries_the_evidence(): void
    {
        // 근거 없는 지적은 사람을 설득하지 못하고 잔소리로만 남는다.
        $question = app(OpsConflictDetector::class)->question([
            'with' => 'M-101 Rev.2', 'expected' => '4인치', 'heard' => '6인치', 'note' => '',
        ]);

        $this->assertStringContainsString('M-101 Rev.2', $question);
        $this->assertStringContainsString('4인치', $question);
        $this->assertStringContainsString('6인치', $question);
        $this->assertStringContainsString('맞나요', $question);
    }

    // ── 방에서 실제로 끼어드는가 ───────────────────────────────────────

    public function test_the_room_reply_leads_with_the_disagreement(): void
    {
        $room = CommunicationRoom::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_OPS, 'name' => '현장 상황실', 'status' => 'active',
        ]);
        $parent = CommunicationMessage::query()->create([
            'communication_room_id' => $room->id,
            'kind' => CommunicationMessage::KIND_MESSAGE,
            'body' => '3층 동편 배관 6인치로 시공합니다',
            'status' => 'active',
        ]);

        // 판독 결과를 흉내낸다 — 여기서 확인하는 것은 "방에 어떻게 말하는가" 이다.
        $reader = new class extends OpsRoomAutoReader
        {
            public function __construct() {}

            public function callReply(CommunicationMessage $message, array $result): void
            {
                $reflection = new \ReflectionMethod(OpsRoomAutoReader::class, 'reply');
                $reflection->invoke($this, $message, $result);
            }
        };

        $reader->callReply($parent, ['items' => [[
            'id' => 1,
            'category' => 'progress',
            'status' => 'needs_input',
            'summary' => '3층 동편 배관 6인치 시공',
            'targetName' => '3층 동편 배관',
            'conflict' => [
                'with' => 'M-101 Rev.2',
                'expected' => '4인치',
                'heard' => '6인치',
                'note' => '도면이 개정됐는지 확인이 필요합니다.',
            ],
        ]]]);

        $reply = CommunicationMessage::query()
            ->where('parent_id', $parent->id)
            ->where('kind', CommunicationMessage::KIND_SYSTEM)
            ->first();

        $this->assertNotNull($reply, 'AI 가 어긋남을 보고도 아무 말 하지 않았습니다.');
        $this->assertStringContainsString('⚠️', $reply->body);
        $this->assertStringContainsString('M-101 Rev.2', $reply->body);
        $this->assertStringContainsString('4인치', $reply->body);
        $this->assertStringContainsString('6인치', $reply->body);
        $this->assertStringContainsString('바뀐 것이 맞나요', $reply->body);
        $this->assertStringContainsString('자동 반영하지 않았습니다', $reply->body,
            '어긋난 값을 조용히 반영했다고 오해하게 만들면 안 됩니다.');
    }

    // ── 규칙이 살아 있는가 ─────────────────────────────────────────────

    public function test_a_conflicting_item_never_carries_a_silent_change(): void
    {
        // 저장 단계에서 proposed 를 비워야 [반영] 버튼이 잘못된 값을 들고 있지 않는다.
        $service = (string) file_get_contents(base_path('app/Services/Ops/OpsIntakeService.php'));
        $this->assertStringContainsString('$proposed = [];', $service,
            '어긋난 항목이 변경안을 그대로 들고 있으면 누군가 눌러서 반영해 버립니다.');
        $this->assertStringContainsString('OpsConflictDetector', $service);

        $analyzer = (string) file_get_contents(base_path('app/Services/Ops/OpsIntakeAnalyzer.php'));
        $this->assertStringContainsString('이미 확정된 사양', $analyzer,
            '판독기가 도면·문서의 확정 사양을 보지 못하면 어긋남을 알 수 없습니다.');
        $this->assertStringContainsString("'conflict'", $analyzer);
    }

    public function test_a_conflict_forces_human_review(): void
    {
        $item = OpsIntakeItem::create([
            'site_id' => $this->site->id,
            'source' => 'room',
            'raw_text' => '6인치로 갑니다',
            'category' => 'progress',
            'confidence' => 95,
            'summary' => '6인치 시공',
            'target_type' => 'wbs',
            'target_code' => 'A140',
            'conflict' => ['with' => 'M-101', 'expected' => '4인치', 'heard' => '6인치', 'note' => ''],
            'status' => 'needs_input',
        ]);

        $this->assertSame('needs_input', $item->fresh()->status);
        $this->assertSame('M-101', $item->fresh()->conflict['with']);
    }
}

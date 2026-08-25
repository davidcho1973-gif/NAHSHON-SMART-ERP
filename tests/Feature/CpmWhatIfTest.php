<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OpsIntakeItem;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Communication\ChatAssistant;
use App\Services\Communication\CommunicationService;
use App\Services\Wbs\CpmEngine;
use App\Services\Wbs\DelayRiskService;
use App\Services\Wbs\WeeklyPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 공정 P4 — AI 공정 도우미: what-if 시뮬레이션 · 자사 이력 위험 · 아침 브리핑.
 */
class CpmWhatIfTest extends TestCase
{
    use RefreshDatabase;

    private const P = 'WI-01';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.api_key', 'test-key');
        $this->site = Site::create(['code' => 'S1', 'name' => '현장', 'status' => 'active']);

        $stage = WbsItem::create(['project_code' => self::P, 'level' => 'stage', 'wbs_code' => self::P.'-S-1', 'name' => 'S', 'sort_order' => 0, 'site_id' => $this->site->id]);
        $task = WbsItem::create(['project_code' => self::P, 'level' => 'task', 'parent_id' => $stage->id, 'wbs_code' => self::P.'-T-1', 'name' => 'T', 'sort_order' => 0, 'site_id' => $this->site->id]);
        foreach ([
            ['A100', '천장 배관', '2026-01-05', '2026-01-09', []],
            ['A200', '배관 검사', '2026-01-10', '2026-01-12', ['A100']],
            ['A300', '마감', '2026-01-13', '2026-01-14', ['A200']],
        ] as $i => [$id, $name, $start, $end, $preds]) {
            WbsItem::create([
                'project_code' => self::P, 'level' => 'subtask', 'parent_id' => $task->id,
                'wbs_code' => self::P.'-W-'.$id, 'activity_id' => $id, 'name' => $name,
                'planned_start' => $start, 'planned_end' => $end, 'preds' => $preds,
                'status' => '검수완료', 'sort_order' => $i + 1, 'site_id' => $this->site->id,
            ]);
        }
        app(CpmEngine::class)->recompute(self::P); // 기준선 포착
    }

    public function test_시뮬레이션은_저장하지_않고_파급만_계산한다(): void
    {
        $sim = app(CpmEngine::class)->simulate(self::P, 'A100', 3);

        $this->assertTrue($sim['success']);
        $this->assertSame('2026-01-14', $sim['projectedEndBefore']);
        $this->assertSame('2026-01-17', $sim['projectedEndAfter'], 'A100 이 3일 밀리면 준공도 3일 밀린다(임계 사슬)');
        $this->assertSame(3, $sim['projectDelayDays']);
        $this->assertSame(2, $sim['movedCount'], 'A200·A300 이 밀린다');

        // 아무것도 저장되지 않았다.
        $this->assertSame('2026-01-09', WbsItem::query()->where('activity_id', 'A100')->first()->planned_end->toDateString());
        $this->assertSame('2026-01-14', WbsItem::query()->where('activity_id', 'A300')->first()->planned_end->toDateString());
    }

    public function test_이름으로도_작업을_특정한다(): void
    {
        $sim = app(CpmEngine::class)->simulate(self::P, '천장 배관', 2);

        $this->assertTrue($sim['success']);
        $this->assertSame('A100', $sim['activity']);
    }

    public function test_AI_에게_밀리면_이라고_물으면_시뮬레이션과_반영_제안이_만들어진다(): void
    {
        Http::fake(['*api.anthropic.com*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'A100 이 3일 밀리면 검사·마감이 밀려 준공이 1/17 이 됩니다.']],
            'stop_reason' => 'end_turn',
        ])]);

        $company = Company::create(['code' => 'C1', 'name' => '자사', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $room = CommunicationRoom::create(['company_id' => $company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => '현장방', 'status' => 'active']);
        $employee = Employee::create(['company_id' => $company->id, 'site_id' => $this->site->id,
            'first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active']);
        $user = User::factory()->create(['employee_id' => $employee->id, 'access_role' => 'site_manager', 'account_status' => 'active']);
        app(CommunicationService::class)->ensureRoomMember($room, $employee);

        $question = app(CommunicationService::class)->postMessage($user, $room, '@AI A100 3일 밀리면 뭐가 밀려?');
        $reply = app(ChatAssistant::class)->answer($question->fresh());

        $this->assertNotNull($reply);
        $this->assertStringContainsString('제안을 등록했습니다', $reply->body, '상용 제품은 조회까지 — 우리는 반영까지 간다');

        $proposal = OpsIntakeItem::query()->where('target_code', self::P.'-W-A100')->first();
        $this->assertNotNull($proposal);
        $this->assertSame('2026-01-12', $proposal->proposed['planned_end'], '종료 1/9 + 3일');
        $this->assertSame('pending', $proposal->status, '반영은 사람이 [반영]을 눌러야 한다');
        $this->assertSame($question->id, $proposal->communication_message_id, '반영 결과가 이 메시지 답글로 돌아온다');
    }

    public function test_약속을_자주_못_지킨_공종은_새_공정표에서_경고된다(): void
    {
        // 자사 이력: ELEC 공종이 지난 주 약속 4건 중 3건 미이행(자재 지연).
        $past = WeeklyPlanService::weekKey(Carbon::now()->subWeek());
        foreach ([1, 2, 3, 4] as $i) {
            WbsItem::create([
                'project_code' => 'OLD-01', 'level' => 'subtask', 'wbs_code' => "OLD-01-W-E{$i}",
                'name' => "전기 {$i}", 'trade' => 'ELEC', 'committed_week' => $past,
                'status' => $i === 1 ? '완료' : '진행중',
                'incomplete_reason' => $i === 1 ? null : 'materials', 'sort_order' => $i,
            ]);
        }
        // 새 공정표에 ELEC 작업 존재.
        WbsItem::create(['project_code' => 'NEW-01', 'level' => 'subtask', 'wbs_code' => 'NEW-01-W-E1',
            'name' => '전기 배관', 'trade' => 'ELEC', 'status' => '검수완료', 'sort_order' => 1]);

        $warnings = app(DelayRiskService::class)->warningsFor('NEW-01');

        $this->assertNotEmpty($warnings, '자사 이력이 말해 주는 위험을 흘려보내면 안 된다');
        $this->assertStringContainsString('ELEC', $warnings[0]);
        $this->assertStringContainsString('자재 지연', $warnings[0]);

        // 표본이 적은 공종은 낙인찍지 않는다.
        WbsItem::create(['project_code' => 'OLD-01', 'level' => 'subtask', 'wbs_code' => 'OLD-01-W-P1',
            'name' => '배관 1', 'trade' => 'PLUMB', 'committed_week' => $past, 'status' => '진행중', 'sort_order' => 9]);
        $this->assertCount(1, app(DelayRiskService::class)->warningsFor('NEW-01'));
    }

    public function test_아침_브리핑은_가장_위험한_것부터_방에_올린다(): void
    {
        $room = CommunicationRoom::create(['site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_OPS, 'name' => '상황실', 'status' => 'active']);
        // 임계경로 A200 이 이미 늦었다 (테스트 기준일은 실제 오늘 — 2026-01 일정은 전부 과거).
        ProcurementItem::create(['project_code' => self::P, 'site_id' => $this->site->id,
            'wbs_code' => self::P.'-W-A300', 'po_no' => 'PO-1', 'status' => '발주완료',
            'eta' => now()->subDays(2)->toDateString()]);

        $this->artisan('ops:morning-brief')->assertSuccessful();

        $brief = CommunicationMessage::query()->where('communication_room_id', $room->id)->first();
        $this->assertNotNull($brief);
        $this->assertStringContainsString('준공 직결', $brief->body);
        $this->assertStringContainsString('PO-1', $brief->body);
        $this->assertSame('morning_brief', $brief->payload['bot'] ?? null);
    }

    public function test_위험이_없으면_아침_브리핑은_조용하다(): void
    {
        $room = CommunicationRoom::create(['site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_OPS, 'name' => '상황실', 'status' => 'active']);
        WbsItem::query()->where('level', 'subtask')->update(['status' => '완료']);

        $this->artisan('ops:morning-brief')->assertSuccessful();

        $this->assertSame(0, CommunicationMessage::query()->where('communication_room_id', $room->id)->count(),
            '매일 오는 "이상 없음"은 사흘이면 아무도 안 읽는다');
    }
}

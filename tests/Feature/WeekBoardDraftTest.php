<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\OpsMeeting;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Models\WeekBoardLine;
use App\Services\Ocr\OcrEngine;
use App\Services\Ops\VoiceNoteTranscriber;
use App\Services\Wbs\WeekBoardDrafter;
use App\Services\Wbs\WeekBoardService;
use App\Support\SiteClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 작업판 비서 — 말·수기 노트 사진·회의 녹음을 듣고 「공종 · 하는 일 · 인원」 줄로.
 *
 * 사장 말: 「이번 주 할 일을 따로 시간 내서 만들고 싶지 않다. 회의에서 오간 말을 AI 가
 * 비서처럼 듣고 정리해서 처리하는 시스템」. 이 시험이 지키는 것은 세 가지다 —
 * 비서는 초안만 낸다(저장은 사람이), 없는 말은 만들지 않는다(인원 추측 금지),
 * 공종은 이 현장의 낱말로 맞춘다(「전기」 와 「전기 」 가 두 공종이 되지 않게).
 */
class WeekBoardDraftTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    /** @var array<int, string> */
    private array $prompts = [];

    protected function setUp(): void
    {
        parent::setUp();
        SiteClock::forget();
        Carbon::setTestNow('2026-09-23 10:00:00');   // 수요일

        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create([
            'code' => '703K', 'name' => 'Savannah', 'country' => 'US',
            'timezone' => 'America/New_York', 'status' => 'active', 'company_id' => $this->company->id,
        ]);
        foreach (['배관', '전기', '덕트'] as $trade) {
            Employee::create(['name' => $trade.' 반장', 'role' => $trade, 'company_id' => $this->company->id,
                'site_id' => $this->site->id, 'employment_status' => 'active']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function manager(string $role = 'site_manager'): User
    {
        $user = User::factory()->create(['access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($user);

        return $user;
    }

    /**
     * 모델 대신 — 프롬프트를 기록하고 정해진 줄을 돌려준다.
     *
     * @param  array<string, mixed>  $data
     */
    private function fakeEngine(array $data): void
    {
        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->andReturnUsing(function (array $images, string $prompt) use ($data): array {
            $this->prompts[] = $prompt;

            return ['data' => $data, 'model' => 'fake'];
        });
        $engine->shouldReceive('name')->andReturn('fake');
        $this->app->instance(OcrEngine::class, $engine);
    }

    /** @param array<string, mixed> $result */
    private function fakeVoice(array $result): void
    {
        $voice = \Mockery::mock(VoiceNoteTranscriber::class)->makePartial();
        $voice->shouldReceive('transcribe')->andReturn($result);
        $this->app->instance(VoiceNoteTranscriber::class, $voice);
    }

    private function trades(): array
    {
        return app(WeekBoardService::class)->tradeOptions($this->site);
    }

    // ── 정리 규칙 ─────────────────────────────────────────────────────

    public function test_text_becomes_lines_in_the_sites_own_trade_words(): void
    {
        $this->manager();
        $this->fakeEngine(['summary' => '배관 둘, 전기 셋', 'lines' => [
            ['trade' => '배 관', 'task' => '급탕 배관', 'headcount' => 2, 'note' => ''],
            ['trade' => 'ELEC', 'task' => '후드 배선', 'headcount' => null],
            ['trade' => '전기', 'task' => '', 'headcount' => 1],          // 일이 없는 줄은 버린다
            ['trade' => '', 'task' => '뭔가', 'headcount' => 1],           // 공종이 없는 줄도
            ['trade' => '덕트', 'task' => '주방 덕트', 'headcount' => 0, 'note' => '자재 오면 시작'],
        ]]);

        $out = app(WeekBoardDrafter::class)->fromText('배관은 급탕 마무리, 전기는 후드 배선', $this->trades());

        $this->assertSame('배관은 급탕 마무리, 전기는 후드 배선', $out['heard']);
        $this->assertSame('배관 둘, 전기 셋', $out['summary']);
        $this->assertCount(3, $out['lines']);
        $this->assertSame(['trade' => '배관', 'task' => '급탕 배관', 'headcount' => 2.0, 'note' => null], $out['lines'][0], '띄어쓰기 차이는 현장 낱말로 맞춘다');
        $this->assertSame('ELEC', $out['lines'][1]['trade'], '현장 낱말에 없으면 말한 대로 둔다 — 지어내지 않는다');
        $this->assertNull($out['lines'][1]['headcount'], '인원이 안 나왔으면 비워 둔다');
        $this->assertNull($out['lines'][2]['headcount'], '0 은 계획이 아니다 — 비운다');
        $this->assertSame('자재 오면 시작', $out['lines'][2]['note']);

        foreach (['배관', '전기', '덕트'] as $t) {
            $this->assertStringContainsString($t, $this->prompts[0], '이 현장의 공종 목록을 모델에 준다');
        }
        $this->assertStringContainsString('이번 주에 할 일', $this->prompts[0]);
        $this->assertStringContainsString('추측 금지', $this->prompts[0]);
    }

    public function test_empty_text_is_refused_before_calling_the_model(): void
    {
        $this->manager();
        $this->fakeEngine(['lines' => []]);

        $this->expectException(\RuntimeException::class);
        app(WeekBoardDrafter::class)->fromText('   ', $this->trades());
    }

    public function test_a_handwritten_note_photo_returns_what_was_read_as_evidence(): void
    {
        $this->manager();
        $this->fakeEngine(['heard' => '배관 2 급탕 / 전기 3 후드', 'summary' => 's', 'lines' => [
            ['trade' => '배관', 'task' => '급탕', 'headcount' => 2],
        ]]);

        $out = app(WeekBoardDrafter::class)->fromImage('png-bytes', 'image/png', $this->trades());

        $this->assertSame('배관 2 급탕 / 전기 3 후드', $out['heard']);
        $this->assertCount(1, $out['lines']);
        $this->assertStringContainsString('손글씨', $this->prompts[0]);
    }

    public function test_a_recording_is_transcribed_first_and_the_spoken_words_are_kept(): void
    {
        $this->manager();
        $this->fakeVoice(['success' => true, 'text' => '배관 급탕 2명.', 'heard' => '어… 배관은 급탕 둘이요']);
        $this->fakeEngine(['summary' => 's', 'lines' => [['trade' => '배관', 'task' => '급탕', 'headcount' => 2]]]);

        $out = app(WeekBoardDrafter::class)->fromAudio('webm-bytes', 'audio/webm', $this->trades());

        $this->assertSame('어… 배관은 급탕 둘이요', $out['heard']);
        $this->assertStringContainsString('배관 급탕 2명.', $this->prompts[0], '정리한 문장을 모델에 준다');
    }

    public function test_a_recording_that_cannot_be_transcribed_stops_with_that_reason(): void
    {
        $this->manager();
        $this->fakeVoice(['success' => false, 'error' => '녹음이 비어 있습니다.']);
        $this->fakeEngine(['lines' => []]);

        $this->expectExceptionMessage('녹음이 비어 있습니다.');
        app(WeekBoardDrafter::class)->fromAudio('', 'audio/webm', $this->trades());
    }

    public function test_a_finished_meeting_uses_its_transcript_for_next_week(): void
    {
        $user = $this->manager();
        $this->fakeEngine(['summary' => 's', 'lines' => [['trade' => '전기', 'task' => '후드 배선', 'headcount' => 3]]]);
        $meeting = $this->meeting($user, ['scribe' => ['status' => 'done', 'text' => '다음 주 전기는 후드 배선 셋'],
            'gemini' => ['status' => 'done', 'text' => '다른 받아쓰기']]);

        $out = app(WeekBoardDrafter::class)->fromMeeting($meeting, $this->trades());

        $this->assertSame('다음 주 전기는 후드 배선 셋', $out['heard'], 'scribe 가 있으면 그것을 쓴다');
        $this->assertStringContainsString('다음 주에 할 일', $this->prompts[0]);

        $this->prompts = [];
        $only = $this->meeting($user, ['gemini' => ['status' => 'done', 'text' => '제미나이만 있음']]);
        $this->assertSame('제미나이만 있음', app(WeekBoardDrafter::class)->fromMeeting($only, $this->trades())['heard']);

        $this->expectExceptionMessage('받아쓰기가 끝나지 않았습니다');
        app(WeekBoardDrafter::class)->fromMeeting($this->meeting($user, ['scribe' => ['status' => 'running']]), $this->trades());
    }

    // ── 공종 목록 ─────────────────────────────────────────────────────

    public function test_trade_options_start_with_the_trades_found_in_the_drawing_schedule(): void
    {
        $this->manager();
        WbsItem::create(['site_id' => $this->site->id, 'project_code' => '703K', 'level' => 'subtask', 'wbs_code' => '703K-W-A1',
            'node_no' => '1.1', 'name' => '그리스 트랩', 'trade' => '기계설비', 'planned_start' => '2026-09-21', 'planned_end' => '2026-09-22']);
        WbsItem::create(['site_id' => $this->site->id, 'project_code' => '703K', 'level' => 'subtask', 'wbs_code' => '703K-W-A2',
            'node_no' => '1.2', 'name' => '후드', 'trade' => '전기', 'planned_start' => '2026-09-21', 'planned_end' => '2026-09-22']);

        $trades = $this->trades();

        $this->assertContains('기계설비', $trades, '도면에서 분석한 공정의 공종이 목록에 있다');
        $this->assertSame(1, count(array_keys($trades, '전기', true)), '직원 공종과 겹치면 하나만');
        foreach (['배관', '덕트'] as $t) {
            $this->assertContains($t, $trades);
        }
    }

    // ── 저장은 사람이 ─────────────────────────────────────────────────

    public function test_reviewed_lines_are_saved_together_and_bad_lines_are_reported_not_hidden(): void
    {
        $this->manager();

        $res = app(WeekBoardService::class)->saveMany([
            ['siteId' => $this->site->id, 'trade' => '배관', 'task' => '급탕 배관', 'headcount' => '2', 'note' => ''],
            ['siteId' => $this->site->id, 'trade' => '전기', 'task' => '', 'headcount' => ''],
            ['siteId' => $this->site->id, 'trade' => '덕트', 'task' => '주방 덕트', 'note' => '자재 오면'],
        ], '703K', '2026-09-28', '회의 09/20 토요 미팅');

        $this->assertTrue($res['success']);
        $this->assertSame(2, $res['saved']);
        $this->assertCount(1, $res['errors']);
        $this->assertStringContainsString('2번째 줄', $res['errors'][0]);

        $rows = WeekBoardLine::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-09-28', $rows[0]->week_start->toDateString(), '다음 주에 적으면 다음 주 판에 간다');
        $this->assertSame('[회의 09/20 토요 미팅]', $rows[0]->note, '어디서 왔는지 남긴다');
        $this->assertSame('자재 오면 [회의 09/20 토요 미팅]', $rows[1]->note);
        $this->assertSame('planned', $rows[0]->status);
    }

    // ── 입구(HTTP) ────────────────────────────────────────────────────

    public function test_the_draft_endpoint_is_for_site_operators_only(): void
    {
        $this->manager('safety_manager');
        $this->fakeEngine(['lines' => []]);

        $this->postJson('/week-board-api/draft', ['site_id' => $this->site->id, 'text' => '배관 급탕'])
            ->assertStatus(403);
    }

    public function test_the_draft_endpoint_returns_lines_with_the_sites_trades_and_never_saves(): void
    {
        $this->manager();
        $this->fakeEngine(['summary' => '한 줄', 'lines' => [['trade' => '배관', 'task' => '급탕 배관', 'headcount' => 2]]]);

        $res = $this->post('/week-board-api/draft', ['site_id' => $this->site->id, 'text' => '배관 급탕 둘', 'week_label' => '다음 주'])
            ->assertOk()->json();

        $this->assertTrue($res['success']);
        $this->assertSame('메모', $res['source']);
        $this->assertSame('배관 급탕 둘', $res['heard']);
        $this->assertSame('급탕 배관', $res['lines'][0]['task']);
        $this->assertContains('배관', $res['trades']);
        $this->assertSame($this->site->id, $res['siteId']);
        $this->assertStringContainsString('다음 주에 할 일', $this->prompts[0]);
        $this->assertSame(0, WeekBoardLine::query()->count(), '초안은 저장하지 않는다 — 사람이 보고 저장한다');
    }

    public function test_the_draft_endpoint_takes_a_photo_or_a_recording_but_nothing_else(): void
    {
        $this->manager();
        $this->fakeEngine(['heard' => '손글씨', 'lines' => [['trade' => '배관', 'task' => '급탕']]]);
        $this->fakeVoice(['success' => true, 'text' => '배관 급탕', 'heard' => '배관 급탕이요']);

        $photo = $this->post('/week-board-api/draft', ['site_id' => $this->site->id,
            'file' => UploadedFile::fake()->image('note.jpg')])->assertOk()->json();
        $this->assertSame('수기 노트 사진', $photo['source']);
        $this->assertSame('손글씨', $photo['heard']);

        $audio = $this->post('/week-board-api/draft', ['site_id' => $this->site->id, 'mime' => 'audio/webm',
            'file' => UploadedFile::fake()->createWithContent('v.webm', 'bytes')])->assertOk()->json();
        $this->assertSame('녹음', $audio['source']);
        $this->assertSame('배관 급탕이요', $audio['heard']);

        $this->post('/week-board-api/draft', ['site_id' => $this->site->id, 'mime' => 'application/pdf',
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertStatus(422);
    }

    public function test_the_meeting_list_shows_only_transcribed_meetings_of_this_site(): void
    {
        // 회의 접근은 공정미팅 화면의 규칙(MeetingAccess)을 그대로 따른다 — 여기서 따로 열지 않는다.
        $user = $this->manager('super_admin');
        $this->meeting($user, ['scribe' => ['status' => 'done', 'text' => '있음']], ['title' => '토요 미팅']);
        $this->meeting($user, ['scribe' => ['status' => 'running']], ['title' => '아직']);

        $list = $this->getJson('/week-board-api/meetings?site_id='.$this->site->id)->assertOk()->json('meetings');

        $this->assertCount(1, $list);
        $this->assertSame('토요 미팅', $list[0]['title']);
    }

    // ── 도우미 ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>|null  $transcripts
     * @param  array<string, mixed>  $extra
     */
    private function meeting(User $user, ?array $transcripts, array $extra = []): OpsMeeting
    {
        return OpsMeeting::create($extra + [
            'site_id' => $this->site->id, 'created_by_id' => $user->id, 'title' => '공정 미팅', 'meeting_on' => '2026-09-20',
            'upload_token' => (string) Str::uuid(), 'audio_hash' => hash('sha256', 'a'), 'audio_bytes' => 5,
            'audio_path' => 't.audio', 'disk' => 'meeting-test', 'audio_mime' => 'audio/wav', 'part_count' => 1,
            'parts' => [], 'status' => 'done', 'transcripts' => $transcripts,
        ]);
    }
}

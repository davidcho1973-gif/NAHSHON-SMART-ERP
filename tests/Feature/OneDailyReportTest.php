<?php

namespace Tests\Feature;

use App\Models\DailyClosingReport;
use App\Models\Site;
use App\Services\Ops\DailyClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 하루에 대한 보고서는 하나다.
 *
 * 현장앱의 일일보고(`field_daily_reports`)와 상황실의 일일마감(`daily_closing_reports`)이
 * <b>똑같은 고유키 `(현장, 날짜)`</b> 를 각자 갖고 있었다. 같은 것을 가리키는 표가 둘이면
 * "그날 그 현장의 보고서" 라는 물음에 답이 두 개가 된다.
 *
 * 실제로 무슨 일이 일어났나 — 현장소장이 현장앱에 "오늘 한 일 / 내일 할 일" 을 쓰고,
 * 상황실에서 마감을 누르면 AI 가 <b>같은 것을 다시 썼다.</b> 현장이 쓴 글을 못 봤기 때문이다.
 * 두 글이 어긋나면 어느 쪽이 맞는지 가릴 방법이 없었다.
 *
 * 두 표를 베끼는 동기화 다리를 놓는 것이 쉬운 길이었지만, 그러면 정본이 여전히 둘이라
 * 언젠가 갈라지고 그때 맞추는 층이 또 필요해진다. 그래서 표를 하나로 합쳤다.
 *
 * 여기서 지키는 것은 둘이다 — <b>표가 다시 갈라지지 않을 것</b>, 그리고
 * <b>현장이 쓴 것이 마감까지 도달할 것.</b>
 */
class OneDailyReportTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::create([
            'code' => 'S-DAY', 'name' => '데이 사이트',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    // ── 표가 갈라져 있지 않은가 ────────────────────────────────────────

    public function test_there_is_no_second_table_holding_the_same_daily_report(): void
    {
        // 이게 다시 생기면 같은 날에 대한 보고서가 두 벌이 된다. 화면 하나 만들자고
        // 표를 하나 더 세우는 것이 매번 그 시작이었다.
        $this->assertFalse(Schema::hasTable('field_daily_reports'),
            "field_daily_reports 가 다시 생겼습니다. 그날 그 현장의 보고서는 "
            ."daily_closing_reports 한 줄입니다 — 두 표가 같은 (현장, 날짜) 를 가지면 "
            ."정본이 둘이 되고, 사람이 같은 것을 두 번 쓰게 됩니다.");
    }

    public function test_the_single_report_can_hold_what_the_field_writes(): void
    {
        foreach (['weather', 'trades', 'work_today', 'work_tomorrow',
            'progress_rate', 'tbm_completed', 'safety_checks', 'field_status'] as $column) {
            $this->assertTrue(Schema::hasColumn('daily_closing_reports', $column),
                "daily_closing_reports 에 {$column} 이 없습니다 — 현장이 쓴 것을 담을 곳이 "
                ."없으면 결국 표를 하나 더 만들게 됩니다.");
        }
    }

    public function test_field_submission_and_closing_state_are_kept_apart(): void
    {
        // 한 칸에 섞으면 현장이 제출한 순간 마감이 끝난 것처럼 보인다.
        // 그러면 "마감 안 한 날" 을 아무도 못 찾는다.
        $site = $this->site();

        $report = DailyClosingReport::create([
            'site_id' => $site->id,
            'report_date' => '2026-08-18',
            'status' => DailyClosingReport::OPEN,
            'field_status' => 'submitted',
            'work_today' => '3층 트레이 포설',
        ]);

        $this->assertSame(DailyClosingReport::OPEN, $report->fresh()->status);
        $this->assertSame('submitted', $report->fresh()->field_status);
    }

    // ── 현장이 쓴 것이 마감까지 가는가 ─────────────────────────────────

    public function test_what_the_field_wrote_reaches_the_closing_metrics(): void
    {
        // 안 가면 AI 가 "오늘 한 일" 을 처음부터 다시 쓴다 — 같은 것을 두 번 쓰는 일이다.
        $site = $this->site();

        DailyClosingReport::create([
            'site_id' => $site->id,
            'report_date' => '2026-08-18',
            'status' => DailyClosingReport::OPEN,
            'field_status' => 'submitted',
            'weather' => '☀️ 맑음',
            'work_today' => '3층 케이블 트레이 포설',
            'work_tomorrow' => '4층 배관 지지대',
            'progress_rate' => 62,
            'tbm_completed' => true,
            'trades' => [['name' => '전기', 'count' => 4]],
        ]);

        $metrics = app(DailyClosingService::class)->metrics($site->id, '2026-08-18');

        $this->assertNotNull($metrics['field'], '현장이 쓴 보고가 마감 집계에 안 들어왔습니다.');
        $this->assertSame('3층 케이블 트레이 포설', $metrics['field']['workToday']);
        $this->assertSame('4층 배관 지지대', $metrics['field']['workTomorrow']);
        $this->assertSame(62, $metrics['field']['progressRate']);
        $this->assertTrue($metrics['field']['tbmCompleted']);
    }

    public function test_an_empty_row_is_not_reported_as_a_field_report(): void
    {
        // 현장앱을 열기만 해도 줄이 생긴다. 그 빈 줄을 "현장 보고 있음" 으로 세면
        // AI 가 빈 내용을 정본으로 삼고, 진짜 보고가 없다는 사실이 가려진다.
        $site = $this->site();

        DailyClosingReport::create([
            'site_id' => $site->id,
            'report_date' => '2026-08-18',
            'status' => DailyClosingReport::OPEN,
            'field_status' => 'draft',
        ]);

        $metrics = app(DailyClosingService::class)->metrics($site->id, '2026-08-18');

        $this->assertNull($metrics['field'], '빈 줄이 현장 보고로 잡혔습니다.');
    }

    public function test_the_ai_is_told_not_to_rewrite_what_the_field_already_wrote(): void
    {
        // 값을 넘겨도 프롬프트가 그걸 정본으로 쓰라고 하지 않으면 AI 는 자기 문장을 쓴다.
        // 그러면 표를 합친 의미가 없다.
        $source = (string) file_get_contents(base_path('app/Services/Ops/DailyClosingService.php'));

        $this->assertStringContainsString('field.workToday', $source,
            'AI 프롬프트가 현장이 쓴 오늘 한 일을 언급하지 않습니다.');
        $this->assertStringContainsString('정본', $source,
            'AI 프롬프트가 현장이 쓴 글을 정본으로 삼으라고 말하지 않습니다.');
    }

    // ── 마감 안 한 날이 보이는가 ───────────────────────────────────────

    public function test_a_day_the_field_wrote_but_nobody_closed_still_shows_up(): void
    {
        // 예전에는 이 날이 다른 표에 있어서 마감 목록에 아예 안 나왔다.
        // 빠진 날을 아무도 모르는 상태가 된다.
        $site = $this->site();

        DailyClosingReport::create([
            'site_id' => $site->id,
            'report_date' => '2026-08-18',
            'status' => DailyClosingReport::OPEN,
            'field_status' => 'submitted',
            'work_today' => '되메우기',
        ]);

        $recent = app(DailyClosingService::class)->recent($site->id);

        $this->assertCount(1, $recent['reports']);
        $this->assertSame(DailyClosingReport::OPEN, $recent['reports'][0]['status']);
        $this->assertTrue($recent['reports'][0]['hasField']);
    }

    public function test_closing_does_not_erase_what_the_field_wrote(): void
    {
        // 마감이 그 줄을 덮어쓰면 현장이 쓴 글이 사라진다. 같은 줄을 두 저자가
        // 나눠 쓰는 구조이므로, 각자 자기 칸만 건드려야 한다.
        $site = $this->site();

        DailyClosingReport::create([
            'site_id' => $site->id,
            'report_date' => '2026-08-18',
            'status' => DailyClosingReport::OPEN,
            'field_status' => 'submitted',
            'work_today' => '3층 트레이 포설',
            'progress_rate' => 62,
        ]);

        app(DailyClosingService::class)->start($site->id, '2026-08-18');

        $report = DailyClosingReport::query()
            ->where('site_id', $site->id)->whereDate('report_date', '2026-08-18')->firstOrFail();

        $this->assertSame('3층 트레이 포설', $report->work_today, '마감이 현장이 쓴 글을 지웠습니다.');
        $this->assertSame(62, (int) $report->progress_rate);
        $this->assertSame('submitted', $report->field_status);
    }

    public function test_closing_reuses_the_row_the_field_created(): void
    {
        // 새 줄을 만들면 같은 날에 보고서가 둘이 된다 — 표를 합친 것이 무의미해진다.
        $site = $this->site();

        DailyClosingReport::create([
            'site_id' => $site->id,
            'report_date' => '2026-08-18',
            'status' => DailyClosingReport::OPEN,
            'work_today' => '되메우기',
        ]);

        app(DailyClosingService::class)->start($site->id, '2026-08-18');

        $this->assertSame(1, DailyClosingReport::query()
            ->where('site_id', $site->id)->whereDate('report_date', '2026-08-18')->count());
    }
}

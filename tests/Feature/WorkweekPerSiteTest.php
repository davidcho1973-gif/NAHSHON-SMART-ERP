<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Admin\SiteAdminService;
use App\Services\Wbs\CpmEngine;
use App\Services\Wbs\WbsService;
use App\Support\WorkCalendar;
use App\Support\WorkRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 주 작업일은 현장마다 — 「703K 는 주 6일 작업으로」 (사장 지시).
 *
 * 회사 전체 설정 하나(ORG_WORKWEEK)로는 한 현장을 6일로 바꾸는 순간 다른 현장의 공정표가
 * 밀린다. 그래서 값은 현장에 두고, 공정 달력은 그 현장의 값을 읽는다. 비어 있으면
 * 지금까지처럼 회사 설정이다.
 */
class WorkweekPerSiteTest extends TestCase
{
    use RefreshDatabase;

    private const P = 'WW-01';

    protected function setUp(): void
    {
        parent::setUp();
        WorkRules::forget();
        config(['org.workweek' => 7]);
    }

    protected function tearDown(): void
    {
        WorkRules::forget();
        parent::tearDown();
    }

    private function site(?int $workweek): Site
    {
        return Site::create(['code' => '703K', 'name' => 'Savannah', 'timezone' => 'America/New_York',
            'status' => 'active', 'workweek_days' => $workweek]);
    }

    /** A(금 01-09 종료) → B(01-10~01-14). 두 작업 모두 그 현장 것. */
    private function seedChain(?Site $site): void
    {
        $task = WbsItem::create(['project_code' => self::P, 'level' => WbsItem::LEVEL_TASK, 'site_id' => $site?->id,
            'wbs_code' => self::P.'-T-1', 'node_no' => '1', 'name' => 'GC']);
        foreach ([
            'A' => ['planned_start' => '2026-01-05', 'planned_end' => '2026-01-09', 'preds' => []],
            'B' => ['planned_start' => '2026-01-10', 'planned_end' => '2026-01-14', 'preds' => ['A']],
        ] as $id => $attrs) {
            WbsItem::create($attrs + ['project_code' => self::P, 'level' => WbsItem::LEVEL_SUBTASK, 'parent_id' => $task->id,
                'site_id' => $site?->id, 'wbs_code' => self::P.'-W-'.$id, 'node_no' => '1.'.$id, 'activity_id' => $id,
                'name' => '작업 '.$id, 'status' => '검수완료', 'sort_order' => $id === 'A' ? 1 : 2]);
        }
    }

    public function test_the_site_rule_carries_the_workweek_and_falls_back_to_the_company_setting(): void
    {
        $six = $this->site(6);
        $none = Site::create(['code' => 'X1', 'name' => 'Other', 'status' => 'active']);

        $this->assertSame(6, WorkRules::forSite($six)->workweekDays);
        $this->assertStringContainsString('주 6일', WorkRules::forSite($six)->summary());
        $this->assertSame(7, WorkRules::forSite($none)->workweekDays, '값이 없는 현장은 회사 설정');

        config(['org.workweek' => 5]);
        WorkRules::forget();
        $this->assertSame(5, WorkRules::forSite($none)->workweekDays);
        $this->assertSame(6, WorkRules::forSite($six)->workweekDays, '현장 값이 회사 설정을 이긴다');
    }

    public function test_a_six_day_site_rests_on_sunday_only(): void
    {
        $cal = WorkCalendar::forSite($this->site(6));

        $this->assertTrue($cal->isWorkday(CarbonImmutable::parse('2026-01-10')), '토요일은 일한다');
        $this->assertFalse($cal->isWorkday(CarbonImmutable::parse('2026-01-11')), '일요일은 쉰다');
        $this->assertSame(6, $cal->workweek());
        $this->assertSame(7, WorkCalendar::forSite(null)->workweek(), '현장 없는 옛 공정표는 회사 설정');
    }

    public function test_the_schedule_of_a_six_day_site_never_lands_on_a_sunday(): void
    {
        $this->seedChain($this->site(6));
        app(CpmEngine::class)->recompute(self::P);   // 기준선

        // A 종료를 하루 늦춘다(금 01-09 → 토 01-10). 7일 달력이면 B 가 일요일(01-11)에 시작한다.
        $res = app(WbsService::class)->updateRow(self::P.'-W-A', ['종료예정' => '2026-01-10']);

        $this->assertTrue($res['success']);
        $b = WbsItem::where('project_code', self::P)->where('activity_id', 'B')->firstOrFail();
        $this->assertSame('2026-01-12', $b->planned_start->toDateString(), '일요일을 건너뛰어 월요일 시작');
        $this->assertFalse($b->planned_end->isSunday());
    }

    public function test_a_site_without_the_setting_still_works_seven_days(): void
    {
        $this->seedChain($this->site(null));
        app(CpmEngine::class)->recompute(self::P);

        app(WbsService::class)->updateRow(self::P.'-W-A', ['종료예정' => '2026-01-10']);

        $b = WbsItem::where('project_code', self::P)->where('activity_id', 'B')->firstOrFail();
        $this->assertSame('2026-01-11', $b->planned_start->toDateString(), '지금까지의 7일 달력 그대로');
    }

    public function test_the_site_form_saves_the_workweek_and_blank_means_company_default(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']));
        $site = $this->site(null);

        $res = app(SiteAdminService::class)->saveSite(['id' => $site->id, 'code' => '703K', 'name' => 'Savannah', 'timezone' => 'America/New_York', 'workweek_days' => '6']);
        $this->assertTrue($res['success'], json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->assertSame(6, $site->fresh()->workweek_days);

        $row = collect(app(SiteAdminService::class)->list()['sites'])->firstWhere('id', $site->id);
        $this->assertSame(6, $row['workweekDays']);
        $this->assertStringContainsString('주 6일', $row['workRules']);

        app(SiteAdminService::class)->saveSite(['id' => $site->id, 'code' => '703K', 'name' => 'Savannah', 'timezone' => 'America/New_York', 'workweek_days' => '']);
        $this->assertNull($site->fresh()->workweek_days, '비우면 회사 설정으로 돌아간다');

        app(SiteAdminService::class)->saveSite(['id' => $site->id, 'code' => '703K', 'name' => 'Savannah', 'timezone' => 'America/New_York', 'workweek_days' => '4']);
        $this->assertNull($site->fresh()->workweek_days, '5·6·7 밖의 값은 받지 않는다');
    }
}

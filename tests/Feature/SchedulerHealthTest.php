<?php

namespace Tests\Feature;

use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Support\SmartCompanyData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 스케줄러가 살아 있는지 밖에서 확인할 수 있는가.
 *
 * 스케줄러가 꺼져도 앱은 멀쩡해 보인다 — 출근은 찍히고 화면도 정상이다. 다만 퇴근이 안
 * 찍히고, 문서가 "분석 중"에 머물고, 경비가 안 잡힐 뿐이다. 무엇이 안 도는지 알아채는 데
 * 며칠이 걸리고, 그 사이 급여가 0 으로 계산된다.
 *
 * 그래서 스케줄러가 1분마다 시각을 남기고, 여기서 그 시각이 최근인지 본다.
 */
class SchedulerHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_a_healthy_scheduler(): void
    {
        SystemHeartbeat::create(['key' => SystemHeartbeat::SCHEDULER, 'beat_at' => now()]);

        $this->get('/build-version')
            ->assertOk()
            ->assertJsonPath('scheduler.running', true);
    }

    public function test_it_reports_a_stalled_scheduler_with_how_long(): void
    {
        SystemHeartbeat::create(['key' => SystemHeartbeat::SCHEDULER, 'beat_at' => now()->subMinutes(90)]);

        $res = $this->get('/build-version')->assertOk();

        $res->assertJsonPath('scheduler.running', false);
        $this->assertSame(90, $res->json('scheduler.minutes_ago'));
        // 숫자만 주면 사람이 판단해야 한다. 무엇이 멈췄는지까지 말해 준다.
        $this->assertStringContainsString('자동 퇴근', $res->json('scheduler.message'));
    }

    public function test_a_short_gap_still_counts_as_running(): void
    {
        // 배포 중에는 잠깐 끊긴다. 그때마다 경보를 울리면 아무도 안 믿게 된다.
        SystemHeartbeat::create(['key' => SystemHeartbeat::SCHEDULER, 'beat_at' => now()->subMinutes(12)]);

        $this->get('/build-version')->assertJsonPath('scheduler.running', true);
    }

    public function test_the_heartbeat_does_not_keep_the_serverless_database_awake(): void
    {
        // 데이터베이스가 서버리스라 5분 놀면 잠든다. 이미 10분마다 도는 작업이 있으니
        // 같은 리듬에 얹는다 — 감시를 붙이느라 요금을 더 내지는 않는다.
        $events = collect(app(Schedule::class)->events());

        $heartbeat = $events->first(fn ($e) => $e->description === 'scheduler-heartbeat');

        $this->assertSame('*/10 * * * *', $heartbeat->expression);
    }

    public function test_it_says_plainly_when_the_scheduler_never_ran(): void
    {
        // 표가 비어 있으면 한 번도 돈 적이 없는 것이다.

        $res = $this->get('/build-version')->assertOk();

        $res->assertJsonPath('scheduler.running', false);
        $this->assertNull($res->json('scheduler.last_beat_at'));
        $this->assertStringContainsString('한 번도', $res->json('scheduler.message'));
    }

    public function test_the_heartbeat_is_registered(): void
    {
        // 맥박 자체가 스케줄에 없으면 이 화면은 영원히 "멈춤"이라고 말한다.
        $events = collect(app(Schedule::class)->events());

        $heartbeat = $events->first(fn ($e) => $e->description === 'scheduler-heartbeat');

        $this->assertNotNull($heartbeat, '스케줄러 맥박이 등록되어 있지 않습니다.');
    }

    public function test_the_heartbeat_lives_in_the_database_not_the_cache(): void
    {
        // 캐시를 파일로 돌렸다 — 그래야 schedule:run 이 매분 데이터베이스를 깨우지 않는다.
        // 파일 캐시는 컨테이너마다 따로라, 맥박을 캐시에 두면 스케줄러가 쓴 값을 웹 화면이
        // 못 읽는다. 그래서 표에 둔다.
        SystemHeartbeat::beat(SystemHeartbeat::SCHEDULER);

        $this->assertDatabaseHas('system_heartbeats', ['key' => SystemHeartbeat::SCHEDULER]);

        // 여러 번 찍어도 줄이 하나여야 한다 — 기록이 아니라 "마지막 시각"이다.
        SystemHeartbeat::beat(SystemHeartbeat::SCHEDULER);
        $this->assertSame(1, SystemHeartbeat::count());
    }

    public function test_the_evening_close_runs_at_eight_and_skips_active_workers(): void
    {
        // 저녁 마감 시각이 바뀌면 근무시간 계산이 통째로 달라진다. 못 박아 둔다.
        $events = collect(app(Schedule::class)->events());

        $evening = $events->first(fn ($e) => str_contains((string) $e->command, 'finalize-sessions --today'));

        $this->assertNotNull($evening, '저녁 자동 퇴근 마감이 등록되어 있지 않습니다.');
        $this->assertSame('0 20 * * *', $evening->expression);
        $this->assertStringContainsString('--grace=30', (string) $evening->command);
    }

    public function test_a_stalled_scheduler_shows_up_in_the_alert_centre(): void
    {
        // 스케줄러가 멈추면 알림을 만드는 수집기도 같이 멈춘다 — 화면이 조용해지는데,
        // 그 조용함이 "이상 없음"으로 읽힌다. 이 한 줄만은 스케줄러 없이 그 자리에서 만든다.
        SystemHeartbeat::create(['key' => SystemHeartbeat::SCHEDULER, 'beat_at' => now()->subHours(3)]);
        $this->actingAs(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        $alerts = SmartCompanyData::alerts();

        $this->assertSame('SCHEDULER-STALLED', $alerts[0]['id']);
        $this->assertSame('긴급', $alerts[0]['severity']);
        $this->assertStringContainsString('근무시간이 0', $alerts[0]['content']);
    }

    public function test_a_healthy_scheduler_adds_no_noise_to_the_alert_centre(): void
    {
        SystemHeartbeat::create(['key' => SystemHeartbeat::SCHEDULER, 'beat_at' => now()]);
        $this->actingAs(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        $ids = array_column(SmartCompanyData::alerts(), 'id');

        $this->assertNotContains('SCHEDULER-STALLED', $ids);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}

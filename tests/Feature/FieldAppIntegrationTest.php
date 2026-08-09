<?php

namespace Tests\Feature;

use App\Livewire\FieldApp\FieldCommandApp;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\OpsLaborReport;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\OpsLaborService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 현장앱은 고립 섬이 아니어야 한다.
 *
 * 처음 만들어졌을 때 현장앱의 출퇴근·일일보고는 자기만의 테이블에 쌓였고,
 * 그 테이블은 급여도 일일마감도 읽지 않았다 — 현장은 같은 출근을 QR 로 한 번 더
 * 찍고, 같은 인원을 상황실에 한 번 더 보고해야 했다. 이 테스트들은 현장앱의
 * 입력이 기존 사슬(출퇴근→급여, 보고→마감 대조)로 실제로 흘러가는지 지킨다.
 */
class FieldAppIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['code' => 'AZ-01', 'name' => 'LG PHOENIX', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
    }

    public function test_등록_안_된_이름은_기록을_거부한다(): void
    {
        // 이름 문자열만 남는 기록은 급여로 이어질 수 없다 — 받는 척하면 안 된다.
        Livewire::test(FieldCommandApp::class)
            ->set('site_id', $this->site->id)
            ->set('commute_worker_name', '아무개')
            ->call('recordCommute', 'in')
            ->assertSet('toastMessage', fn (?string $m): bool => str_contains((string) $m, '찾지 못했습니다'));

        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_동명이인이_애매하면_기록하지_않는다(): void
    {
        Employee::create(['name' => '김철수', 'employee_number' => 'E-1', 'employment_status' => 'active', 'site_id' => $this->site->id]);
        Employee::create(['name' => '김철수한', 'employee_number' => 'E-2', 'employment_status' => 'active', 'site_id' => $this->site->id]);

        Livewire::test(FieldCommandApp::class)
            ->set('site_id', $this->site->id)
            ->set('commute_worker_name', '김철수')
            ->call('recordCommute', 'in')
            ->assertSet('toastMessage', fn (?string $m): bool => str_contains((string) $m, '여러 명'));

        $this->assertSame(0, AttendanceLog::count(), '엉뚱한 사람 급여에 붙는 것보다 안 찍히는 게 낫다');
    }

    public function test_일일보고_제출이_운영_인원보고로_흘러간다(): void
    {
        Livewire::test(FieldCommandApp::class)
            ->set('site_id', $this->site->id)
            ->call('incrementTrade', 'elec')
            ->call('incrementTrade', 'elec')
            ->call('incrementTrade', 'weld')
            ->call('saveDailyReport');

        $rows = OpsLaborReport::where('site_id', $this->site->id)->get();

        $this->assertCount(2, $rows, '인원이 0 인 공종은 넘기지 않는다');
        $this->assertSame(3, (int) $rows->sum('headcount'));
        $this->assertEqualsCanonicalizing(['전기/배관', '용접/제작'], $rows->pluck('trade')->all());

        // 일일마감의 대조 화면(보고 vs QR 실적)에 이 보고가 그대로 잡힌다.
        $labor = app(OpsLaborService::class)->forDate($this->site->id, now()->toDateString());
        $this->assertSame(3, $labor['reportedTotal']);
    }

    public function test_다시_제출하면_현장앱_행만_갈아끼운다(): void
    {
        // 상황실(AI)이 만든 인원 보고를 지우면 안 된다 — 현장앱 표식이 붙은 행만.
        OpsLaborReport::create([
            'site_id' => $this->site->id, 'work_date' => now()->toDateString(),
            'company_label' => '한빛전기', 'headcount' => 5, 'note' => '상황실 보고',
        ]);

        $c = Livewire::test(FieldCommandApp::class)->set('site_id', $this->site->id);
        $c->call('incrementTrade', 'elec')->call('saveDailyReport');
        $c->call('incrementTrade', 'elec')->call('saveDailyReport');   // 재제출

        $this->assertSame(1, OpsLaborReport::where('note', '현장앱 일일보고')->count(), '재제출이 중복 행을 만들면 안 된다');
        $this->assertSame(2, (int) OpsLaborReport::where('note', '현장앱 일일보고')->value('headcount'));
        $this->assertSame(1, OpsLaborReport::where('note', '상황실 보고')->count(), '남의 보고는 건드리지 않는다');
    }

    public function test_현장앱_출퇴근_목록에_q_r_로_찍은_기록도_보인다(): void
    {
        // 대장이 하나이므로 어느 경로로 찍었든 같은 목록에 보여야 한다.
        $emp = Employee::create(['name' => '박용접', 'employee_number' => 'E-9', 'employment_status' => 'active', 'site_id' => $this->site->id]);
        AttendanceLog::create([
            'employee_id' => $emp->id, 'site_id' => $this->site->id,
            'attendance_date' => now()->toDateString(), 'event_type' => 'clock_in',
            'event_at' => now(), 'source' => 'qr', 'status' => 'approved',
        ]);

        Livewire::test(FieldCommandApp::class)
            ->set('site_id', $this->site->id)
            ->call('setTab', 'qr')
            ->assertSee('박용접');
    }
}

<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Services\Attendance\GateAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 게이트에서 "누구인가"와 "어디인가"를 확인한다.
 *
 * 예전에는 둘 다 없었다 — 이름을 고르면 아무나 될 수 있었고, 현장 밖에서 찍어도
 * 그대로 승인됐다. 그 기록이 급여 계산으로 그대로 흘러간다.
 */
class GateIdentityAndSiteCheckTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'ABC', 'name' => 'ABC ENG', 'status' => 'active']);
        // 현장 좌표가 있어야 "밖" 을 판정할 수 있다(피닉스 시내, 반경 200m).
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'LG-PH', 'name' => 'LG Phoenix', 'status' => 'active',
            'latitude' => 33.4484, 'longitude' => -112.0740, 'radius_meters' => 200,
        ]);
    }

    private function worker(string $name, ?string $phone = null, ?Site $site = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'site_id' => ($site ?? $this->site)->id,
            'name' => $name, 'first_name' => $name, 'last_name' => '',
            'phone' => $phone, 'employment_status' => 'active',
        ]);
    }

    // ── 누구인가 ────────────────────────────────────────────────────────

    public function test_전화번호_뒷_4자리로_본인을_찾는다(): void
    {
        $kim = $this->worker('김철수', '480-555-0199');
        $this->worker('이민준', '(602) 555-7412');

        $res = $this->postJson(route('gate.identify', ['site' => $this->site]), ['last4' => '0199']);

        $res->assertOk();
        $workers = $res->json('workers');
        $this->assertCount(1, $workers, '뒷자리가 맞는 사람만 나와야 명단이 열리지 않는다');
        $this->assertSame($kim->id, $workers[0]['id']);
        $this->assertSame('김철수', $workers[0]['name']);
    }

    public function test_표기가_달라도_같은_번호로_읽는다(): void
    {
        // 등록 화면마다 하이픈·괄호·국가번호가 제각각이다. 숫자만 남겨 비교한다.
        $this->worker('Miguel Torres', '+1 (480) 555-0100');

        $res = $this->postJson(route('gate.identify', ['site' => $this->site]), ['last4' => '0100']);

        $res->assertOk();
        $this->assertCount(1, $res->json('workers'));
    }

    public function test_다른_현장_사람과_번호가_없는_사람은_나오지_않는다(): void
    {
        $other = Site::create(['company_id' => $this->company->id, 'code' => 'SK', 'name' => 'SK', 'status' => 'active']);
        $this->worker('남의현장', '480-555-0199', $other);
        $this->worker('번호없음', null);

        $res = $this->postJson(route('gate.identify', ['site' => $this->site]), ['last4' => '0199']);

        $res->assertOk()->assertJsonPath('workers', []);
    }

    public function test_네_자리가_아니면_아무것도_돌려주지_않는다(): void
    {
        $this->worker('김철수', '480-555-0199');

        foreach (['', '19', '01990', 'abcd'] as $bad) {
            $res = $this->postJson(route('gate.identify', ['site' => $this->site]), ['last4' => $bad]);
            $res->assertOk()->assertJsonPath('workers', [], "'{$bad}' 로 사람이 나오면 안 된다");
        }
    }

    // ── 어디인가 ────────────────────────────────────────────────────────

    public function test_현장_밖에서_찍으면_검토_대기로_들어간다(): void
    {
        $kim = $this->worker('김철수', '480-555-0199');

        // 현장에서 수십 킬로 떨어진 곳(투손 방향).
        $res = $this->postJson(route('gate.punch', ['site' => $this->site]), [
            'employee_id' => $kim->id, 'lat' => 32.2226, 'lng' => -110.9747, 'accuracy' => 20,
        ]);

        $res->assertOk()->assertJsonPath('success', true)->assertJsonPath('pending', true);

        $log = AttendanceLog::query()->where('employee_id', $kim->id)->firstOrFail();
        $this->assertSame('pending', $log->status, '현장 밖 기록이 그대로 승인되면 급여가 그 위에 얹힌다');
        $this->assertFalse($log->payload['verified_on_site'], '작업자 앱과 같은 칸 이름으로 남는다');
    }

    public function test_현장_안에서_찍으면_바로_승인된다(): void
    {
        $kim = $this->worker('김철수', '480-555-0199');

        $res = $this->postJson(route('gate.punch', ['site' => $this->site]), [
            'employee_id' => $kim->id, 'lat' => 33.4484, 'lng' => -112.0740, 'accuracy' => 15,
        ]);

        $res->assertOk()->assertJsonPath('pending', false);
        $log = AttendanceLog::query()->where('employee_id', $kim->id)->firstOrFail();
        $this->assertSame('approved', $log->status);
        $this->assertTrue($log->payload['verified_on_site']);
    }

    public function test_확인할_수_없으면_보류하지_않는다(): void
    {
        // 위치를 못 읽었거나 현장에 좌표가 없는 경우 — "밖" 이 아니라 "모른다" 다.
        // 전원을 보류로 돌리면 그 목록은 매일 전원이 쌓여 아무도 안 보게 되고,
        // 그러면 진짜 이상한 기록도 같이 묻힌다.
        $kim = $this->worker('김철수', '480-555-0199');

        $res = $this->postJson(route('gate.punch', ['site' => $this->site]), ['employee_id' => $kim->id]);

        $res->assertOk()->assertJsonPath('pending', false);
        $log = AttendanceLog::query()->where('employee_id', $kim->id)->firstOrFail();
        $this->assertSame('approved', $log->status);
        $this->assertNull($log->payload['verified_on_site'], '모르는 것은 모른다고 남긴다 — 참/거짓으로 지어내지 않는다');
    }

    public function test_오차가_반경보다_크면_밖이라고_단정하지_않는다(): void
    {
        // GPS 는 건물 안에서 수백 미터씩 튄다. 그 튐으로 임금을 보류하면 안 된다.
        $kim = $this->worker('김철수', '480-555-0199');

        $this->postJson(route('gate.punch', ['site' => $this->site]), [
            'employee_id' => $kim->id, 'lat' => 32.2226, 'lng' => -110.9747, 'accuracy' => 5000,
        ])->assertOk()->assertJsonPath('pending', false);
    }

    // ── 규칙은 한 벌 ────────────────────────────────────────────────────

    public function test_게이트와_작업자앱이_같은_판정을_쓴다(): void
    {
        $geo = app(\App\Services\Attendance\AttendanceGeoService::class);

        $inside = ['lat' => 33.4484, 'lng' => -112.0740, 'accuracy' => 15];
        $outside = ['lat' => 32.2226, 'lng' => -110.9747, 'accuracy' => 20];

        $this->assertSame(\App\Services\Attendance\AttendanceGeoService::ON_SITE, $geo->verdict($this->site, $inside));
        $this->assertSame(\App\Services\Attendance\AttendanceGeoService::OFF_SITE, $geo->verdict($this->site, $outside));
        $this->assertSame(\App\Services\Attendance\AttendanceGeoService::UNVERIFIED, $geo->verdict($this->site, []));

        // 게이트가 그 판정을 그대로 따른다.
        $kim = $this->worker('김철수', '480-555-0199');
        $out = app(GateAttendanceService::class)->punch($kim, $this->site, $outside);
        $this->assertSame(\App\Services\Attendance\AttendanceGeoService::OFF_SITE, $out['verdict']);
    }

    public function test_기록에_확인_방법과_날짜가_남는다(): void
    {
        $kim = $this->worker('김철수', '480-555-0199');

        $res = $this->postJson(route('gate.punch', ['site' => $this->site]), [
            'employee_id' => $kim->id, 'identified_by' => 'phone4',
        ]);

        $res->assertOk();
        $this->assertNotEmpty($res->json('date'), '자정을 넘기는 야간 작업에서 어느 날로 찍혔는지 알아야 한다');
        $log = AttendanceLog::query()->where('employee_id', $kim->id)->firstOrFail();
        $this->assertSame('phone4', $log->payload['identified_by']);
    }
}

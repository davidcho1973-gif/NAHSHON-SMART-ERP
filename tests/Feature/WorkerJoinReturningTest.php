<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 간편 등록의 현장 흐름 — 이메일 없이도 등록되고, 다시 찍어도 명단이 부풀지 않는다.
 */
class WorkerJoinReturningTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $other;

    private Company $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create(['code' => 'AZ-01', 'name' => '1현장', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $this->other = Site::create(['code' => 'AZ-02', 'name' => '2현장', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $this->partner = Company::create(['code' => 'C1', 'name' => '대한설비', 'status' => 'active', 'company_type' => Company::TYPE_PARTNER]);
    }

    /** @param array<string, mixed> $extra */
    private function join(Site $site, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/join/w/'.$site->id, array_merge([
            'full_name' => 'Miguel Torres',
            'company_id' => $this->partner->id,
            'role' => 'Piping',
            'phone' => '480-555-0100',
        ], $extra));
    }

    public function test_이메일_없이도_등록된다(): void
    {
        // 현장에서 이메일이 없거나 기억나지 않는 사람이 여기서 막히면, 그날 그 사람은
        // 명단에 없는 채로 일한다 — 출퇴근도 안전서명도 남지 않는다.
        $this->join($this->site)->assertOk();

        $employee = Employee::query()->where('name', 'Miguel Torres')->first();
        $this->assertNotNull($employee);
        $this->assertSame('active', $employee->employment_status);
        $this->assertNull($employee->email);
        $this->assertSame($this->site->id, $employee->site_id);
        $this->assertSame(Employee::TYPE_INDIRECT, $employee->employment_type, '회사 분류가 고용형태를 정한다');
    }

    public function test_같은_사람이_다시_찍어도_명단은_한_줄이다(): void
    {
        $this->join($this->site)->assertOk();
        $first = Employee::query()->where('name', 'Miguel Torres')->firstOrFail();

        // 다른 현장에서, 표기가 다른 같은 번호로 다시 등록.
        $res = $this->join($this->other, ['phone' => '(480) 555-0100', 'role' => 'Welding']);

        $res->assertOk();
        $this->assertSame(1, Employee::query()->where('name', 'Miguel Torres')->count(), '한 사람이 두 줄이 되면 인원 집계가 그만큼 부푼다');

        $first->refresh();
        $this->assertSame($this->other->id, $first->site_id, '오늘 있는 현장으로 옮겨 붙는다');
        $this->assertSame('Welding', $first->role);
    }

    public function test_번호가_같아도_이름이_다르면_남의_기록을_덮지_않는다(): void
    {
        $this->join($this->site)->assertOk();

        $this->join($this->site, ['full_name' => 'Someone Else'])->assertOk();

        $this->assertSame(1, Employee::query()->where('name', 'Miguel Torres')->count());
        $this->assertSame(1, Employee::query()->where('name', 'Someone Else')->count(), '두 사람으로 남는다 — 합치는 건 나중에 할 수 있지만 덮은 신원은 되돌릴 수 없다');
    }

    public function test_퇴사자는_QR_로_스스로_되살아나지_않는다(): void
    {
        $this->join($this->site)->assertOk();
        Employee::query()->where('name', 'Miguel Torres')->update(['employment_status' => 'terminated']);

        $this->join($this->site)->assertSessionHasErrors('phone');

        $this->assertSame(1, Employee::query()->where('name', 'Miguel Torres')->count(), '새 줄로 우회 복직해서도 안 된다');
        $this->assertSame('terminated', Employee::query()->where('name', 'Miguel Torres')->value('employment_status'));
    }

    public function test_자사_직영으로_들어오면_급여_대상이_되고_직책이_남는다(): void
    {
        $own = Company::create(['code' => 'OWN', 'name' => '자사', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);

        $this->join($this->site, ['company_id' => $own->id, 'position' => 'foreman'])->assertOk();

        $employee = Employee::query()->where('name', 'Miguel Torres')->firstOrFail();
        $this->assertSame(Employee::TYPE_DIRECT, $employee->employment_type);
        $this->assertTrue($employee->isHourly(), '자사 직영은 출퇴근 시간이 그대로 급여가 된다');
        $this->assertSame('foreman', $employee->position, '직책은 공정과 따로 남는다');
        $this->assertSame('Piping', $employee->role, '공정은 공정 칸에 그대로');
        $this->assertSame(now()->toDateString(), $employee->start_date?->toDateString(), '급여 기간을 가르는 값이라 비워 두지 않는다');
    }

    public function test_자사_직영인데_임금률이_없으면_그_자리에서_알린다(): void
    {
        // 임금 프로필은 0원으로 태어난다. 아무도 안 채우면 급여를 돌리는 날에야
        // $0 명세서로 드러나는데, 그때는 이미 2주치가 지나 있다.
        $own = Company::create(['code' => 'OWN', 'name' => '자사', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);

        $this->join($this->site, ['company_id' => $own->id, 'position' => 'worker'])->assertOk();

        $employee = Employee::query()->where('name', 'Miguel Torres')->firstOrFail();
        $this->assertDatabaseHas('unified_alerts', [
            'fingerprint' => "payroll-setup-missing:{$employee->id}",
            'event_type' => 'payroll_setup_missing',
        ]);
    }

    public function test_협력사는_급여_알림을_만들지_않는다(): void
    {
        // 게이트를 쓰는 사람 대부분이다. 그들에게 임금률 알림을 띄우면 그 목록이
        // 매일 쌓여서 정작 봐야 할 자사 직원 알림을 덮는다.
        $this->join($this->site, ['position' => 'worker'])->assertOk();

        $this->assertDatabaseMissing('unified_alerts', ['event_type' => 'payroll_setup_missing']);
    }

    public function test_직책이_급여의_관리자_구분을_정한다(): void
    {
        // 예전에는 공정 글자에서 'foreman' 을 찾아 짐작했다 — "Piping" 이라고 적은
        // 반장은 작업자로 계산됐다.
        $own = Company::create(['code' => 'OWN', 'name' => '자사', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $this->join($this->site, ['company_id' => $own->id, 'position' => 'foreman'])->assertOk();

        $employee = Employee::query()->where('name', 'Miguel Torres')->firstOrFail();
        $this->assertContains($employee->position, Employee::SUPERVISORY_POSITIONS);
        $this->assertSame('반장', $employee->positionLabel());
    }

    public function test_관리자_기록은_공개_폼이_건드리지_못한다(): void
    {
        // 이름과 번호를 아는 사람이 남의 소속 현장을 옮겨 버리는 길을 열어 두지 않는다.
        $boss = Employee::create([
            'company_id' => $this->partner->id, 'site_id' => $this->site->id,
            'name' => 'Miguel Torres', 'phone' => '480-555-0100', 'employment_status' => 'active',
        ]);
        User::factory()->create(['employee_id' => $boss->id, 'access_role' => 'admin', 'account_status' => 'active']);

        $this->join($this->other)->assertOk();

        $boss->refresh();
        $this->assertSame($this->site->id, $boss->site_id, '관리자 기록의 현장이 공개 폼 입력으로 바뀌었다');
        $this->assertSame(2, Employee::query()->where('name', 'Miguel Torres')->count(), '대신 새 작업자로 등록된다');
    }
}

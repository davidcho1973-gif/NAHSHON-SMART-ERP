<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollTimesheet;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 급여 시트는 자기 입력 <b>전부</b>를 따라야 한다.
 *
 * 이 표는 출퇴근 기록과 고용형태 두 가지에서 나오는데, 다시 계산하는 손잡이가
 * 출퇴근 기록 쪽에만 달려 있었다. 고용형태는 «이 표가 있느냐 없느냐» 를 정하는
 * 값인데도 그쪽이 바뀌면 아무 일도 일어나지 않았다.
 *
 * 그래서 소속이 확인되기 전에 일한 날은, 나중에 자사 직영으로 확인해도 급여에서
 * 빠진 채 남는다. 사람은 일했고 출퇴근 기록도 멀쩡한데 임금만 없다 — 급여를 뽑는
 * 날에야 드러나고, 그때는 이미 2주치가 지나 있다.
 */
class TimesheetFollowsEmploymentTypeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC MEP', 'status' => 'active']);
    }

    private function worker(string $type): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => '이대웅',
            'employee_number' => 'E-9001',
            'employment_status' => 'active',
            'employment_type' => $type,
        ]);
    }

    /** 하루치 출근·퇴근(07:00~16:00, 9시간). */
    private function workDay(Employee $employee, string $date): void
    {
        foreach (['clock_in' => '07:00', 'clock_out' => '16:00'] as $type => $time) {
            AttendanceLog::create([
                'employee_id' => $employee->id,
                'company_id' => $employee->company_id,
                'site_id' => $this->site->id,
                'attendance_date' => $date,
                'event_type' => $type,
                'event_at' => Carbon::parse($date.' '.$time, 'America/Phoenix'),
                'source' => 'gate_qr',
                'status' => 'approved',
            ]);
        }
    }

    private function sheets(Employee $employee): Collection
    {
        return PayrollTimesheet::query()->where('employee_id', $employee->id)->orderBy('work_date')->get();
    }

    public function test_days_worked_before_the_employment_type_was_known_come_back_with_it(): void
    {
        // 소속이 확인되기 전 — 누가 임금을 주는지 모르므로 급여 시트를 만들지 않는다.
        $employee = $this->worker(Employee::TYPE_INDIRECT);
        foreach (['2026-09-21', '2026-09-22', '2026-09-23'] as $date) {
            $this->workDay($employee, $date);
        }

        $this->assertCount(0, $this->sheets($employee), '협력사 인원에게는 우리 급여 시트가 없다');

        // 인사가 «자사 직영» 으로 확인한 순간, 이미 일한 사흘이 급여로 돌아와야 한다.
        $employee->forceFill(['employment_type' => Employee::TYPE_DIRECT])->save();

        $sheets = $this->sheets($employee);
        $this->assertCount(3, $sheets, '확인 전에 일한 날이 급여에서 빠지면 그건 임금이 사라진 것이다');
        $this->assertSame(480, (int) $sheets[0]->payable_minutes, '9시간 - 점심 60분 = 480분');
        $this->assertSame('2026-09-21', Carbon::parse($sheets[0]->work_date)->toDateString());
        $this->assertSame('2026-09-23', Carbon::parse($sheets[2]->work_date)->toDateString());
    }

    public function test_reclassifying_to_a_subcontractor_takes_the_sheets_back_out(): void
    {
        $employee = $this->worker(Employee::TYPE_DIRECT);
        $this->workDay($employee, '2026-09-21');
        $this->assertCount(1, $this->sheets($employee));

        $employee->forceFill(['employment_type' => Employee::TYPE_INDIRECT])->save();

        $this->assertCount(0, $this->sheets($employee), '협력사로 정정하면 우리 급여 시트는 남아 있으면 안 된다');
    }

    /** 이름·전화만 고쳤을 때 한 해치 출퇴근을 다시 계산하지는 않는다. */
    public function test_an_unrelated_edit_does_not_touch_the_sheets(): void
    {
        $employee = $this->worker(Employee::TYPE_DIRECT);
        $this->workDay($employee, '2026-09-21');
        $before = $this->sheets($employee)->first();

        $employee->forceFill(['phone' => '480-555-0100'])->save();

        $after = $this->sheets($employee)->first();
        $this->assertSame($before->updated_at?->toDateTimeString(), $after->updated_at?->toDateTimeString());
    }
}

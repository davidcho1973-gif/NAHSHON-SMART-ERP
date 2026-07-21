<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TBM 서명 배정: 그날 출근(clock_in)한 사람을 출석자로 표시하고 목록 상단에 올린다.
 * (계획 인원은 미리, 실제 작업자는 출근 기록으로 확정 — 사장님 설계)
 */
class AssignableAttendeesTest extends TestCase
{
    use RefreshDatabase;

    public function test_present_today_employees_are_flagged_and_sorted_first(): void
    {
        $present = Employee::create(['name' => '김철수', 'employee_number' => 'E-001']);
        $absent = Employee::create(['name' => '가나다', 'employee_number' => 'E-002']); // 이름이 앞서지만 미출근

        AttendanceLog::create([
            'employee_id' => $present->id, 'attendance_date' => '2026-08-10',
            'event_type' => 'clock_in', 'event_at' => '2026-08-10 07:02:00',
        ]);

        $list = SmartCompanyData::assignableEmployees('ALL', '2026-08-10');

        // 출근자가 먼저.
        $this->assertSame($present->id, $list[0]['id']);
        $this->assertTrue($list[0]['present']);
        $this->assertSame('07:02', $list[0]['clockIn']);

        $absentRow = collect($list)->firstWhere('id', $absent->id);
        $this->assertFalse($absentRow['present']);
        $this->assertNull($absentRow['clockIn']);
    }

    public function test_without_date_nobody_is_marked_present(): void
    {
        $e = Employee::create(['name' => '김철수']);
        AttendanceLog::create([
            'employee_id' => $e->id, 'attendance_date' => '2026-08-10',
            'event_type' => 'clock_in', 'event_at' => '2026-08-10 07:00:00',
        ]);

        $list = SmartCompanyData::assignableEmployees('ALL', null);
        $this->assertFalse($list[0]['present']); // 날짜 없으면 출석 판정 안 함
    }

    public function test_clock_out_only_does_not_count_as_present(): void
    {
        $e = Employee::create(['name' => '김철수']);
        AttendanceLog::create([
            'employee_id' => $e->id, 'attendance_date' => '2026-08-10',
            'event_type' => 'clock_out', 'event_at' => '2026-08-10 17:00:00',
        ]);

        $list = SmartCompanyData::assignableEmployees('ALL', '2026-08-10');
        $this->assertFalse($list[0]['present']); // 퇴근만 있으면(출근 기록 없음) 미출근 처리
    }
}

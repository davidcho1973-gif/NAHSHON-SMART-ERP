<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\PayrollTimesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * payroll_timesheets 의 기록자는 AttendanceTimesheetSync 하나여야 한다.
 *
 * 예전에는 AttendanceQrService 에 두 번째 기록자가 있었다. 규칙이 달랐다 —
 * 점심 공제가 없었다. AttendanceLog 저장 훅이 공제한 행을 쓰고 나면 곧바로
 * QR 쪽 기록자가 공제 없는 값으로 덮어써서, QR 로 찍은 사람만 하루 60분을
 * 더 받았다. 같은 8시간이 찍는 방법에 따라 다른 급여가 되는 버그다.
 */
class TimesheetSingleWriterTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        return Employee::create(['name' => '김철수', 'employee_number' => 'E-001', 'employment_status' => 'active']);
    }

    private function log(Employee $emp, string $type, string $at, string $source = 'qr', string $status = 'approved'): AttendanceLog
    {
        return AttendanceLog::create([
            'employee_id' => $emp->id, 'attendance_date' => '2026-08-07',
            'event_type' => $type, 'event_at' => $at, 'source' => $source, 'status' => $status,
        ]);
    }

    public function test_qr_로그도_점심_공제된_한_행만_남는다(): void
    {
        $emp = $this->employee();

        // 07:00 → 16:00 = 540분. 점심 60분 공제 → 480분 (정규 8시간, OT 0).
        $this->log($emp, 'clock_in', '2026-08-07 07:00:00');
        $this->log($emp, 'clock_out', '2026-08-07 16:00:00');

        $rows = PayrollTimesheet::where('employee_id', $emp->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(480, (int) $rows[0]->payable_minutes, '점심 60분이 공제돼야 한다 — 공제 없는 540이면 이중 기록자가 되살아난 것');
        $this->assertSame(480, (int) $rows[0]->regular_minutes);
        $this->assertSame(0, (int) $rows[0]->overtime_minutes);
        $this->assertSame('attendance_logs', $rows[0]->source, '기록자는 AttendanceTimesheetSync 하나다');
    }

    public function test_어느_경로로_찍어도_같은_시간이_나온다(): void
    {
        // QR / NFC / 관리자 입력 — 같은 07:00-16:00 이면 급여 시간도 같아야 한다.
        foreach (['qr', 'nfc_reader', 'manual'] as $i => $source) {
            $emp = Employee::create(['name' => "작업자{$i}", 'employee_number' => "E-10{$i}", 'employment_status' => 'active']);
            $this->log($emp, 'clock_in', '2026-08-07 07:00:00', $source);
            $this->log($emp, 'clock_out', '2026-08-07 16:00:00', $source);
        }

        $minutes = PayrollTimesheet::query()->pluck('payable_minutes')->map(fn ($m) => (int) $m)->unique();

        $this->assertSame([480], $minutes->values()->all(), '경로에 따라 급여 시간이 달라지면 안 된다');
    }

    public function test_거절된_날은_타임시트도_사라진다(): void
    {
        $emp = $this->employee();
        $in = $this->log($emp, 'clock_in', '2026-08-07 07:00:00');
        $out = $this->log($emp, 'clock_out', '2026-08-07 16:00:00');
        $this->assertSame(1, PayrollTimesheet::count());

        $in->forceFill(['status' => 'rejected'])->save();
        $out->forceFill(['status' => 'rejected'])->save();

        $this->assertSame(0, PayrollTimesheet::count(), '근거 로그가 전부 거절되면 파생 타임시트도 지워야 한다');
    }

    public function test_타임시트에_근거_로그가_남는다(): void
    {
        $emp = $this->employee();
        $in = $this->log($emp, 'clock_in', '2026-08-07 07:00:00');
        $out = $this->log($emp, 'clock_out', '2026-08-07 16:00:00');

        $row = PayrollTimesheet::firstOrFail();

        $this->assertSame([$in->id, $out->id], $row->payload['attendance_log_ids'], '급여 이의 때 어느 로그에서 나온 시간인지 추적할 수 있어야 한다');
    }
}

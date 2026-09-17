<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Attendance\WorkerAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 출입구 QR 을 찍었다는 사실을 <b>현장 확인 증거로 인정한다.</b>
 *
 * ── 왜 ─────────────────────────────────────────────────────────────────
 * 앱에서 출퇴근을 찍으려면 반드시 그 현장 출입구의 QR 을 스캔해야 한다. 컨트롤러가
 * gate_site 를 필수로 받고, 배정 현장과 다르면 그 자리에서 막는다. 코드 주석도
 * «찍었으면 그 사람은 거기 없었다» 고 적어 두었다.
 *
 * 그런데 승인 판정은 그 QR 을 무시하고 GPS·WiFi 만 봤다. 그래서:
 *   · 실내에서 GPS 정확도가 반경보다 나쁘면 코드가 판정을 포기하고 → 대기
 *   · 현장 WiFi 는 원청사 것이라 일반 작업자에게 비밀번호가 없어 → 그 길도 없음
 * 결국 현장에 서서 문 앞 QR 을 찍은 사람 전원이 매일 «확인 필요» 로 쌓이고,
 * 반장이 그것을 하나씩 눌렀다. 2026-09-16 사장 본인이 그대로 겪었다.
 *
 * ── 새 규칙 ────────────────────────────────────────────────────────────
 * <b>«확실히 현장 밖» 일 때만</b> 대기로 돌린다. QR 을 사진 찍어 집에서 스캔하는
 * 경우가 그것이고, 그것만 사람이 본다. 판정 불가는 승인한다 — QR 이 증거이기 때문이다.
 */
class GateQrCountsAsOnSiteTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
        $this->site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'S-1', 'name' => 'Test Site',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
            'latitude' => 33.4484, 'longitude' => -112.0740, 'radius_meters' => 300,
        ]);
        $this->employee = Employee::query()->create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'employee_number' => 'W-1', 'first_name' => 'A', 'last_name' => 'B', 'name' => 'A B',
            'employment_status' => 'active',
        ]);
        User::query()->create([
            'name' => 'A B', 'email' => 'ab@example.test', 'password' => Hash::make('x'),
            'access_role' => 'worker', 'access_scope' => 'assigned_sites',
            'account_status' => 'active', 'employee_id' => $this->employee->id,
        ]);
    }

    /** @param array<string, mixed> $signal */
    private function punch(array $signal, string $direction = 'in'): array
    {
        return app(WorkerAttendanceService::class)->punch($this->employee, $direction, $signal, 'ko');
    }

    private function lastLog(): AttendanceLog
    {
        return AttendanceLog::query()->latest('id')->firstOrFail();
    }

    public function test_indoors_with_no_usable_gps_is_approved_because_the_qr_was_scanned(): void
    {
        // 실내. 좌표는 잡히는데 정확도가 반경(300m)보다 나빠 코드가 판정을 포기한다.
        $result = $this->punch(['lat' => 33.4484, 'lng' => -112.0740, 'accuracy' => 900]);

        $this->assertTrue($result['success']);
        $this->assertSame('approved', $this->lastLog()->status, '문 앞 QR 을 찍었는데 반장을 기다리게 하면 안 된다.');
        $this->assertSame('gate_qr', $this->lastLog()->payload['verified_by']);
    }

    public function test_no_location_at_all_is_approved_too(): void
    {
        // 위치 권한을 껐거나 지하라 좌표가 아예 없다. QR 은 찍었다.
        $result = $this->punch([]);

        $this->assertTrue($result['success']);
        $this->assertSame('approved', $this->lastLog()->status);
        $this->assertSame('gate_qr', $this->lastLog()->payload['verified_by']);
    }

    public function test_a_site_with_no_coordinates_still_approves(): void
    {
        // 현장 좌표가 아직 안 들어간 경우 — GPS 판정 자체가 불가능하다.
        // 예전에는 이 현장 전원이 영원히 «확인 필요» 였다.
        // radius_meters 는 NOT NULL 이라 항상 값이 있다. 실제 «미설정» 은 좌표가 빈 것이다.
        $this->site->update(['latitude' => null, 'longitude' => null]);

        $this->punch(['lat' => 33.4484, 'lng' => -112.0740, 'accuracy' => 20]);

        $this->assertSame('approved', $this->lastLog()->status);
    }

    public function test_inside_the_radius_is_still_approved_and_still_says_it_was_gps(): void
    {
        $this->punch(['lat' => 33.4484, 'lng' => -112.0740, 'accuracy' => 20]);

        $log = $this->lastLog();
        $this->assertSame('approved', $log->status);
        $this->assertSame('geo', $log->payload['verified_by'], '반경 안이면 그 사실 그대로 남겨야 한다.');
    }

    public function test_clearly_far_away_still_waits_for_the_foreman(): void
    {
        // QR 을 사진 찍어 집에서 스캔한 경우. 이것만은 사람이 본다.
        // 애리조나 현장인데 조지아에서 찍혔다.
        $result = $this->punch(['lat' => 32.0809, 'lng' => -81.0912, 'accuracy' => 20]);

        $this->assertTrue($result['success'], '기록 자체는 남아야 한다 — 임금이 걸린 기록이다.');
        $log = $this->lastLog();
        $this->assertSame('pending', $log->status);
        $this->assertSame('none', $log->payload['verified_by']);
        $this->assertStringContainsString('멀리 떨어진', $result['message'], '왜 기다리는지 말해 줘야 한다.');
    }

    public function test_clock_out_follows_the_same_rule(): void
    {
        // 퇴근도 임금이 걸린 기록이라 같은 규칙을 쓴다. 출근만 고치면 퇴근이 남는다.
        $this->punch(['lat' => 33.4484, 'lng' => -112.0740, 'accuracy' => 900]);
        $this->punch(['lat' => 33.4484, 'lng' => -112.0740, 'accuracy' => 900], 'out');

        $out = AttendanceLog::query()->where('event_type', 'clock_out')->latest('id')->firstOrFail();
        $this->assertSame('approved', $out->status);
    }

    public function test_the_work_tab_does_not_call_an_existing_feature_coming_soon(): void
    {
        // 「출근 시각 정정 요청」 버튼은 이미 화면에 있고 서버 경로도 있다. 그런데
        // 「근무」 탭은 «준비 중입니다» 라고 말하고 있었다. 있는 기능을 없다고 말하면
        // 쓸 수 있는 사람이 안 쓰고, 화면을 믿지 않게 된다.
        $html = (string) file_get_contents(base_path('resources/views/attendance-app/index.blade.php'));

        $this->assertStringNotContainsString('준비 중입니다', $html);
        $this->assertStringNotContainsString('corrections are coming', $html);
        $this->assertStringNotContainsString('Pronto podrá corregirlo', $html);

        // 대신 어디를 눌러야 하는지 말해야 한다.
        $this->assertStringContainsString('출근 시각 정정 요청', $html);
        $this->assertStringContainsString('Fix clock-in time', $html);
        $this->assertStringContainsString('Corregir hora de entrada', $html);
    }

    public function test_the_record_says_what_it_was_judged_on(): void
    {
        // «왜 승인됐나» 에 답할 수 없는 임금 기록은 나중에 아무도 설명하지 못한다.
        $this->punch(['lat' => 33.4484, 'lng' => -112.0740, 'accuracy' => 900]);

        $payload = $this->lastLog()->payload;
        $this->assertArrayHasKey('verified_by', $payload);
        $this->assertArrayHasKey('geo_verdict', $payload);
        $this->assertArrayHasKey('verified_on_site', $payload);
    }
}

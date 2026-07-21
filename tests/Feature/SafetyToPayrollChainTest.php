<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\PayrollTimesheet;
use App\Models\SafetyWorkItem;
use App\Models\WbsItem;
use App\Services\Safety\SafetyWorkService;
use App\Services\Wbs\WbsLaborService;
use App\Services\Wbs\WbsService;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * E2E 검증: 등록(WBS→안전카드) → AI 계획(풀세트) → 출석자 배정 → 서명 → 급여(타임시트)
 *           → 실투입/공정 진척 롤업. 사슬의 조인 키((employee_id, 날짜)·wbs_code)가
 * 실제 서비스로 끝까지 이어지는지 한 번에 확인한다(사장님 요청 ③).
 */
class SafetyToPayrollChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_chain_register_plan_attendee_sign_payroll_and_rollup(): void
    {
        $date = '2026-08-10';

        // 1) 공정관리(WBS): 전기 작업 1건 — 전기공 2명 계획, 계획공수 32MH, 고위험.
        $wbs = WbsItem::create([
            'project_code' => 'E2E-01', 'level' => 'subtask', 'wbs_code' => 'E2E-01-W-A010',
            'node_no' => '1.1.1', 'activity_id' => 'A010', 'name' => '천장 전기 배관', 'trade' => 'ELEC',
            'ehs' => 'high', 'crew_text' => '2 electricians', 'crew_size' => 2,
            'crew_roles' => [['count' => 2, 'role' => 'electrician', 'external' => false]],
            'equipment' => ['시저리프트'], 'manhours' => 32, 'days' => 3,
            'planned_start' => $date, 'planned_end' => '2026-08-12', 'status' => '검수완료',
        ]);

        // 2) 오늘 작업 등록 → 안전카드: 계획 인원 → 서명란 2칸, plan_payload 에 공종/위험도 이관.
        $reg = app(WbsService::class)->createSafetyCard('E2E-01-W-A010', $date, null);
        $this->assertTrue($reg['success']);
        $card = SafetyWorkItem::where('work_code', $reg['work_code'])->firstOrFail();
        $this->assertSame('ELEC', $card->plan_payload['trade']);
        $this->assertSame('high', $card->plan_payload['ehs']);
        $this->assertSame(2, $card->signatures()->count());

        // 3) AI 계획서(풀세트): 공종·위험도·장비가 프롬프트에 반영되고 폭염이 출력에 포함.
        $aiJson = json_encode([
            'summary' => '고소 전기 배관', 'hazards' => [['hazard' => '감전', 'risk_level' => '상', 'control' => 'LOTO 차단']],
            'ptp_steps' => ['LOTO 확인'], 'required_ppe' => ['절연장갑', '안전대'], 'tbm_topics' => ['LOTO 담당'],
            'permits' => ['전기 LOTO', '고소작업 허가'], 'heat_environment' => ['15분마다 수분 섭취'], 'key_risk' => '감전·추락',
        ]);
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-2.5-pro']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $aiJson]]]]],
            ]),
        ]);
        $planRes = app(SafetyWorkService::class)->generatePlan($card->fresh()->toClientArray(), 'ALL', null);
        $this->assertSame(['15분마다 수분 섭취'], $planRes['plan']['heat_environment']);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => str_contains($r->url(), ':generateContent')
            && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'ELEC'));

        // 4) 실제 출석자: 김철수가 오늘 출근(clock_in) → 배정 목록에 present.
        $emp = Employee::create(['name' => '김철수', 'employee_number' => 'E-001']);
        AttendanceLog::create(['employee_id' => $emp->id, 'attendance_date' => $date,
            'event_type' => 'clock_in', 'event_at' => $date . ' 07:00:00']);
        $assignable = SmartCompanyData::assignableEmployees('ALL', $date);
        $this->assertTrue(collect($assignable)->firstWhere('id', $emp->id)['present']);

        // 5) 서명란에 배정(assignEmployee) + TBM 서명.
        $sig = $card->signatures()->orderBy('id')->first();
        $this->assertTrue(app(WbsLaborService::class)->assignEmployee($sig->id, $emp->id)['success']);
        $sig->refresh();
        $this->assertSame($emp->id, $sig->employee_id);
        $sig->update(['signed' => true, 'signed_at' => now()]);

        // 6) 급여: 퇴근(clock_out) → 출퇴근이 타임시트로 자동 동기화(점심 공제 규칙 포함).
        AttendanceLog::create(['employee_id' => $emp->id, 'attendance_date' => $date,
            'event_type' => 'clock_out', 'event_at' => $date . ' 16:00:00']);
        $ts = PayrollTimesheet::where('employee_id', $emp->id)->where('work_date', $date)->first();
        $this->assertNotNull($ts, '출퇴근이 타임시트로 자동 동기화되어야 한다');
        $this->assertGreaterThan(0, (int) $ts->payable_minutes);

        // 7) 실투입 집계: 서명(employee_id) → 타임시트(같은 날) → 이 공정에 그대로 귀속.
        $labor = app(WbsLaborService::class)->laborFor('E2E-01-W-A010');
        $this->assertTrue($labor['success']);
        $expectedHours = round((int) $ts->payable_minutes / 60, 1);
        $this->assertSame($expectedHours, $labor['actualHours']);   // 타임시트 시간이 공정 실투입으로
        $this->assertGreaterThan(0, $labor['actualHours']);
        $this->assertSame($emp->id, $labor['days'][0]['people'][0]['employeeId']);
        $this->assertSame($expectedHours, $labor['days'][0]['people'][0]['hours']);
        $this->assertSame((int) round($expectedHours / 32 * 100), $labor['consumedPct']);

        // 8) 공정 진척 롤업: 카드 진척 60% → WbsItem effectiveProgress 반영.
        $card->update(['progress' => 60]);
        $wbs->load('safetyWorkItems');
        $this->assertSame(60, $wbs->effectiveProgress());
    }
}

<?php

namespace Tests\Feature;

use App\Models\SafetyPermit;
use App\Models\SafetyWorkItem;
use App\Services\Safety\SafetyPermitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 작업허가서(PTW): AI 계획서의 필요 허가를 실제 발행 → 승인 → 서명완료 문서로.
 */
class SafetyPermitTest extends TestCase
{
    use RefreshDatabase;

    private function cardWithPlan(): SafetyWorkItem
    {
        return SafetyWorkItem::create([
            'work_code' => 'WRK-PTW', 'title' => '고소 전기', 'work_date' => '2026-08-10', 'wbs_code' => 'P-W-1',
            'plan_payload' => ['plan' => [
                'permits' => ['전기 LOTO', '고소작업 허가'],
                'hazards' => [['hazard' => '감전', 'risk_level' => '상', 'control' => 'LOTO 차단·검전']],
                'required_ppe' => ['절연장갑', '안전대'],
            ]],
        ]);
    }

    public function test_issue_from_card_creates_permits_from_plan_with_precautions(): void
    {
        $this->cardWithPlan();

        $res = app(SafetyPermitService::class)->issueFromCard('WRK-PTW', null);

        $this->assertTrue($res['success']);
        $this->assertSame(2, $res['issued']);
        $this->assertSame(2, SafetyPermit::count());

        $lockout = SafetyPermit::where('type', '전기 LOTO')->first();
        $this->assertSame('발행', $lockout->status);
        $this->assertStringStartsWith('PTW-260810-', $lockout->permit_no);
        // 안전 조치 = 계획의 대책 + PPE.
        $this->assertContains('LOTO 차단·검전', $lockout->precautions);
        $this->assertContains('절연장갑', $lockout->precautions);
    }

    public function test_issue_is_idempotent_per_type(): void
    {
        $this->cardWithPlan();
        app(SafetyPermitService::class)->issueFromCard('WRK-PTW', null);
        $again = app(SafetyPermitService::class)->issueFromCard('WRK-PTW', null);

        $this->assertSame(0, $again['issued']); // 이미 발행된 유형은 재발행 안 함
        $this->assertSame(2, SafetyPermit::count());
    }

    public function test_approve_then_sign_workflow_and_guards(): void
    {
        $this->cardWithPlan();
        app(SafetyPermitService::class)->issueFromCard('WRK-PTW', null);
        $permit = SafetyPermit::first();
        $svc = app(SafetyPermitService::class);
        // 승인·서명한 사람이 실제로 있어야 한다. 예전에는 마이그레이션이 1번 계정을
        // 만들어 둬서 우연히 통했는데, 그 계정은 이제 원본에 없다.
        $actor = \App\Models\User::factory()->create();

        // 발행 상태에서 바로 서명 불가.
        $this->assertFalse($svc->act($permit->id, 'sign')['success']);

        // 승인 → 서명.
        $this->assertTrue($svc->act($permit->id, 'approve', $actor->id)['success']);
        $this->assertSame('승인', $permit->fresh()->status);
        $this->assertTrue($svc->act($permit->id, 'sign', $actor->id, '김반장')['success']);

        $permit->refresh();
        $this->assertSame('서명완료', $permit->status);
        $this->assertSame('김반장', $permit->signed_by);
        $this->assertNotNull($permit->signed_at);
    }

    public function test_stats_and_missing_plan(): void
    {
        SafetyWorkItem::create(['work_code' => 'WRK-NP', 'title' => 'x', 'work_date' => '2026-08-10']);
        $noPlan = app(SafetyPermitService::class)->issueFromCard('WRK-NP', null);
        $this->assertFalse($noPlan['success']); // 계획서에 permits 없음

        $this->cardWithPlan();
        app(SafetyPermitService::class)->issueFromCard('WRK-PTW', null);
        $stats = app(SafetyPermitService::class)->stats();
        $this->assertSame(2, $stats['issued']);
        $this->assertSame(2, $stats['total']);
    }
}

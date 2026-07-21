<?php

namespace Tests\Feature;

use App\Models\SafetyWorkItem;
use App\Services\Safety\SafetyWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI 안전계획 편집·승인: AI 초안을 현장 판단으로 수정해 저장/승인하는 마지막 고리.
 */
class SafetyPlanEditTest extends TestCase
{
    use RefreshDatabase;

    private function card(): SafetyWorkItem
    {
        return SafetyWorkItem::create([
            'work_code' => 'WRK-P1', 'title' => '전기', 'work_date' => '2026-08-10',
            'plan_status' => '검토중',
            'plan_payload' => ['plan' => ['summary' => 'AI 초안', 'required_ppe' => ['안전모']]],
        ]);
    }

    public function test_save_plan_persists_edited_plan(): void
    {
        $this->card();
        $edited = ['summary' => '현장 수정본', 'required_ppe' => ['안전모', '절연장갑'], 'permits' => ['전기 LOTO'], 'heat_environment' => ['수분 섭취']];

        $res = app(SafetyWorkService::class)->savePlan('WRK-P1', $edited, false);

        $this->assertTrue($res['success']);
        $card = SafetyWorkItem::where('work_code', 'WRK-P1')->first();
        $this->assertSame('현장 수정본', $card->plan_payload['plan']['summary']);
        $this->assertSame(['안전모', '절연장갑'], $card->plan_payload['plan']['required_ppe']);
        $this->assertSame(['전기 LOTO'], $card->plan_payload['plan']['permits']);
        $this->assertArrayHasKey('plan_edited_at', $card->plan_payload);
        $this->assertSame('검토중', $card->plan_status); // 승인 아님
    }

    public function test_save_and_approve_sets_status(): void
    {
        $this->card();

        app(SafetyWorkService::class)->savePlan('WRK-P1', ['summary' => 'ok'], true);

        $this->assertSame('승인완료', SafetyWorkItem::where('work_code', 'WRK-P1')->first()->plan_status);
    }

    public function test_save_plan_missing_card_returns_error(): void
    {
        $res = app(SafetyWorkService::class)->savePlan('NOPE', ['summary' => 'x'], false);
        $this->assertFalse($res['success']);
    }
}

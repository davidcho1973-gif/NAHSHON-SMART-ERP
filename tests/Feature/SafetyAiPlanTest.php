<?php

namespace Tests\Feature;

use App\Models\SafetyWorkItem;
use App\Models\User;
use App\Services\Safety\GeminiSafetyAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafetyAiPlanTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGemini(array $payload): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]],
            ]),
        ]);
    }

    private function item(array $overrides = []): array
    {
        return array_merge([
            'id' => 'WRK-AI-1', 'project' => 'LGES-AZ 전기', 'site' => '2층',
            'title' => '천장 케이블 포설', 'crew' => 3, 'qty' => 30, 'unit' => 'm',
            'planStatus' => '초안', 'tbmStatus' => '대기', 'closeStatus' => '시작전', 'progressStatus' => '미분석',
            'progress' => 0, 'doneQty' => 0, 'totalQty' => 30,
            'workText' => '천장 전기 배선 정리 및 신규 케이블 30m 포설. 사다리 사용.',
            'closeText' => '', 'signatures' => [], 'issues' => [],
        ], $overrides);
    }

    public function test_generate_plan_calls_gemini_and_persists_plan(): void
    {
        $this->fakeGemini([
            'summary' => '천장 전기 케이블 포설 작업',
            'hazards' => [['hazard' => '고소작업 추락', 'risk_level' => '상', 'control' => '사다리 고정·안전대 착용']],
            'ptp_steps' => ['작업구역 통제', '사다리 점검'],
            'required_ppe' => ['안전모', '안전대', '절연장갑'],
            'tbm_topics' => ['추락 예방', '전기 안전'],
            'permits' => ['고소작업 허가'],
            'key_risk' => '고소작업 중 추락',
        ]);

        $result = app(\App\Services\Safety\SafetyWorkService::class)->generatePlan($this->item(), 'ALL', null);

        $this->assertSame('천장 전기 케이블 포설 작업', $result['plan']['summary']);
        $this->assertSame('상', $result['plan']['hazards'][0]['risk_level']);

        // Persisted on the work item and exposed to the client shape.
        $item = SafetyWorkItem::where('work_code', 'WRK-AI-1')->first();
        $this->assertSame('검토중', $item->plan_status);
        $this->assertSame('고소작업 중 추락', $item->plan_payload['plan']['key_risk']);
        $this->assertSame('고소작업 중 추락', $result['item']['aiPlan']['key_risk']);
    }

    public function test_recommend_progress_reflects_close_report(): void
    {
        $this->fakeGemini([
            'recommended_progress' => 55,
            'status' => '지연',
            'rationale' => '18m 완료했으나 자재 부족으로 잔여 12m 지연.',
            'follow_up' => '자재 입고 후 재개.',
        ]);

        $item = $this->item([
            'doneQty' => 18, 'totalQty' => 30,
            'closeText' => '천장 배선 18m 완료. 자재 부족으로 나머지 12m 지연.',
        ]);

        $result = app(\App\Services\Safety\SafetyWorkService::class)->recommendProgress($item, 'ALL', null);

        $this->assertSame(55, $result['recommendation']['recommended_progress']);
        $this->assertSame('지연', $result['recommendation']['status']);

        $saved = SafetyWorkItem::where('work_code', 'WRK-AI-1')->first();
        $this->assertSame(55, (int) $saved->progress);
        $this->assertSame('추천완료', $saved->progress_status);
    }

    public function test_progress_is_clamped_between_0_and_100(): void
    {
        $this->fakeGemini(['recommended_progress' => 250, 'status' => '완료', 'rationale' => 'x', 'follow_up' => '']);

        $rec = app(GeminiSafetyAnalyzer::class)->recommendProgress(['title' => 't', 'closeText' => 'done']);
        $this->assertSame(100, $rec['recommended_progress']);
    }

    public function test_api_generate_plan_endpoint(): void
    {
        $this->fakeGemini([
            'summary' => 's', 'hazards' => [], 'ptp_steps' => [], 'required_ppe' => [],
            'tbm_topics' => [], 'permits' => [], 'key_risk' => '핵심 위험',
        ]);
        $user = User::factory()->create(['access_role' => 'safety_manager', 'account_status' => 'active']);

        $res = $this->actingAs($user)->postJson('/smart-company-api/api_generateSafetyPlan', [
            'args' => [$this->item(['id' => 'WRK-API-AI'])],
            'siteId' => 'ALL',
        ]);

        $res->assertStatus(200)->assertJsonPath('success', true)->assertJsonPath('plan.key_risk', '핵심 위험');
    }

    public function test_plan_uses_trade_risk_context_and_returns_heat_environment(): void
    {
        // 풀세트: 공종·안전위험도·장비가 프롬프트에 반영되고, 폭염/환경 대책이 출력에 포함된다.
        $this->fakeGemini([
            'summary' => '고소 전기 배선', 'hazards' => [['hazard' => '감전', 'risk_level' => '상', 'control' => 'LOTO 차단']],
            'ptp_steps' => ['LOTO 확인'], 'required_ppe' => ['절연장갑', '안전대'], 'tbm_topics' => ['LOTO 담당'],
            'permits' => ['고소작업 허가', '전기 LOTO'],
            'heat_environment' => ['15분마다 수분 섭취', '그늘에서 30분 주기 휴식'],
            'key_risk' => '감전·추락',
        ]);

        $plan = app(GeminiSafetyAnalyzer::class)->generatePlan([
            'title' => '천장 전기 배선', 'workText' => '고소 전기 배선 작업',
            'trade' => 'ELEC', 'ehs' => 'high', 'crew_text' => '2 electricians + 1 helper', 'equipment' => '시저리프트',
        ]);

        $this->assertSame(['15분마다 수분 섭취', '그늘에서 30분 주기 휴식'], $plan['heat_environment']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            if (! str_contains($request->url(), ':generateContent')) {
                return false;
            }
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'ELEC') && str_contains($body, '안전위험도')
                && str_contains($body, '시저리프트') && str_contains($body, '폭염');
        });
    }

    public function test_missing_api_key_returns_graceful_error(): void
    {
        config(['services.gemini.api_key' => '']);
        $user = User::factory()->create(['access_role' => 'safety_manager', 'account_status' => 'active']);

        $res = $this->actingAs($user)->postJson('/smart-company-api/api_generateSafetyPlan', [
            'args' => [$this->item()],
            'siteId' => 'ALL',
        ]);

        $res->assertStatus(200)->assertJsonPath('success', false);
    }
}

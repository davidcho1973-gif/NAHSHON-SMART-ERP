<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 어떤 AI 가 살아 있는지 배포 화면에서 보인다.
 *
 * 키를 넣었다고 믿었는데 실은 안 들어간 경우가 화면에서는 전혀 드러나지 않는다.
 * 문서함은 분석이 조용히 실패하고, 도면 판독과 교차검증은 <b>기능 자체가 조용히
 * 사라진다</b> — 오류도 안 나고 메뉴도 그대로라서 몇 주 뒤에야 알아챈다.
 *
 * 그래서 /build-version 이 "무엇이 켜져 있는가" 를 말한다. 값은 절대 내보내지 않는다 —
 * 이 주소는 로그인 없이 열리기 때문이다.
 */
class AiEngineVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_says_which_engines_are_live(): void
    {
        config([
            'services.gemini.api_key' => 'gem-secret-value',
            'services.anthropic.api_key' => 'ant-secret-value',
            'document-intelligence.cross_check.enabled' => true,
        ]);

        $response = $this->get('/build-version')->assertOk();

        $response->assertJsonPath('ai.gemini', true);
        $response->assertJsonPath('ai.anthropic', true);
        $response->assertJsonPath('ai.document_analysis', true);
        $response->assertJsonPath('ai.drawing_vision', true);
        $response->assertJsonPath('ai.cross_check.live', true);
    }

    public function test_cross_check_is_not_live_without_the_second_engine(): void
    {
        // 설정만 켜져 있고 키가 없으면 "두 번째 눈" 은 실제로 뜨지 않는다.
        // enabled=true 만 보고 안심하면 검증이 없는 채로 몇 달이 간다.
        config([
            'services.anthropic.api_key' => '',
            'document-intelligence.cross_check.enabled' => true,
        ]);

        $response = $this->get('/build-version')->assertOk();

        $response->assertJsonPath('ai.cross_check.enabled', true);
        $response->assertJsonPath('ai.cross_check.live', false);
        $response->assertJsonPath('ai.drawing_vision', false);
    }

    public function test_it_never_leaks_the_keys_themselves(): void
    {
        config([
            'services.gemini.api_key' => 'gem-secret-value',
            'services.anthropic.api_key' => 'ant-secret-value',
        ]);

        $body = $this->get('/build-version')->assertOk()->getContent();

        $this->assertStringNotContainsString('gem-secret-value', $body, 'API 키가 공개 주소로 새어 나갑니다.');
        $this->assertStringNotContainsString('ant-secret-value', $body, 'API 키가 공개 주소로 새어 나갑니다.');
    }
}

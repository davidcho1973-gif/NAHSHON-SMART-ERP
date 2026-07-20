<?php

namespace Tests\Feature;

use App\Support\GeminiModelResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gemini 모델 해석 — 하드코딩된 존재하지 않는 모델명(gemini-3.5-pro 등)으로 인한 404 를
 * 막기 위해, 키가 실제로 쓸 수 있는 모델을 ListModels 로 조회해서 고른다.
 */
class GeminiModelResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // 조회 결과 캐시가 테스트 간 새지 않도록.
        config(['services.gemini.api_key' => 'test-gemini-key']);
    }

    public function test_it_picks_available_models_and_ignores_a_nonexistent_configured_model(): void
    {
        // env 의 GEMINI_MODEL 이 존재하지 않는 이름이어도(=실제 사고), 조회 목록에 없으면 무시된다.
        config(['services.gemini.model' => 'gemini-3.5-pro']);
        Http::fake([
            '*/v1beta/models*' => Http::response([
                'models' => [
                    ['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent']],
                    ['name' => 'models/gemini-2.5-pro', 'supportedGenerationMethods' => ['generateContent']],
                    ['name' => 'models/gemini-1.5-flash', 'supportedGenerationMethods' => ['generateContent']],
                    ['name' => 'models/text-embedding-004', 'supportedGenerationMethods' => ['embedContent']],
                ],
            ]),
        ]);

        $models = app(GeminiModelResolver::class)->candidates();

        $this->assertNotContains('gemini-3.5-pro', $models);      // 존재하지 않으므로 제외
        $this->assertNotContains('text-embedding-004', $models);  // generateContent 미지원 제외
        $this->assertContains('gemini-2.5-pro', $models);
        $this->assertSame('gemini-2.5-pro', $models[0]);          // pro·최신 우선 정렬
        $this->assertContains('gemini-2.5-flash', $models);
    }

    public function test_it_falls_back_to_real_static_models_and_trusts_config_when_discovery_fails(): void
    {
        // ListModels 가 실패(오프라인/오류)하면 실명 정적 목록으로 폴백하고, 설정 모델은 신뢰해 맨 앞에 둔다.
        config(['services.gemini.model' => 'gemini-2.5-flash']);
        Http::fake(['*/v1beta/models*' => Http::response('nope', 500)]);

        $models = app(GeminiModelResolver::class)->candidates();

        $this->assertSame('gemini-2.5-flash', $models[0]);
        $this->assertContains('gemini-2.5-pro', $models);
        $this->assertContains('gemini-2.0-flash', $models);
        $this->assertNotContains('gemini-3.5-pro', $models);      // 정적 폴백에 가짜 이름 없음
        $this->assertNotContains('gemini-2.0-pro', $models);
    }
}

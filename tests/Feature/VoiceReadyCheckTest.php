<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 음성 보고 점검 — 점검이 거짓말을 하면 점검이 아니다.
 *
 * 특히 «키가 틀렸는데 괜찮다고 답하는» 경우를 막는다. 모델 결정기는 목록 조회가
 * 실패해도 조용히 정적 목록으로 넘어가는데(판독을 계속 굴리려는 설계라 그 자체는
 * 옳다), 점검하는 자리에서 그걸 그대로 믿으면 잘못된 키에도 초록불이 켜진다.
 */
class VoiceReadyCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_says_the_key_is_missing_and_what_the_foreman_sees(): void
    {
        config(['services.gemini.api_key' => '']);

        $this->artisan('ops:voice-check')
            ->expectsOutputToContain('AI 키가 없습니다')
            ->expectsOutputToContain('글로 적어 주세요')
            ->assertExitCode(1);
    }

    public function test_a_bad_key_is_reported_as_bad_not_as_ready(): void
    {
        config(['services.gemini.api_key' => 'wrong-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'nope'], 401)]);

        $this->artisan('ops:voice-check')
            ->expectsOutputToContain('물어보지 못했습니다')
            ->assertExitCode(1);
    }

    public function test_a_working_key_reports_ready(): void
    {
        config([
            'services.gemini.api_key' => 'good-key',
            'services.gemini.voice_model' => 'gemini-2.5-flash',
        ]);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'models' => [
                ['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-2.5-pro', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-embedding-001', 'supportedGenerationMethods' => ['embedContent']],
            ],
        ])]);

        $this->artisan('ops:voice-check')
            ->expectsOutputToContain('음성 보고를 쓸 수 있습니다')
            ->assertExitCode(0);
    }

    public function test_it_warns_when_the_fast_model_is_not_available(): void
    {
        // 느려도 되긴 하지만, 반장이 현장에 서서 기다린다는 사실은 말해 줘야 한다.
        config([
            'services.gemini.api_key' => 'good-key',
            'services.gemini.voice_model' => 'gemini-2.5-flash',
        ]);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'models' => [
                ['name' => 'models/gemini-2.5-pro', 'supportedGenerationMethods' => ['generateContent']],
            ],
        ])]);

        $this->artisan('ops:voice-check')
            ->expectsOutputToContain('느릴 수 있습니다')
            ->assertExitCode(0);
    }
}

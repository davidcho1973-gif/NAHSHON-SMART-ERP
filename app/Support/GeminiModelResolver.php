<?php

namespace App\Support;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 어떤 Gemini 모델을 호출할지 결정한다 — Google 이 모델명을 자주 바꾸고/폐기해서 하드코딩이 계속 깨진다.
 *
 * 실제 사고: env 의 GEMINI_MODEL 이 `gemini-3.5-pro`(존재하지 않는 이름)로 잘못 잡혀 있었고,
 * 폴백 목록에도 없는 이름(`gemini-2.0-pro`)과 이미 폐기된 `gemini-1.5-flash` 가 섞여 있어
 * 모든 시도가 404("model is not found for API version v1beta") → 분석 전체 실패.
 *
 * 그래서 "추측"을 버리고 **키가 실제로 쓸 수 있는 모델을 ListModels 로 조회**해서 고른다:
 *   1) ListModels(캐시 1h) 로 generateContent 지원 모델을 받아온다.
 *   2) 검증된 선호 순서(pro→flash, 최신→구형)로 정렬하고, 조회된 것 중에 있는 것만 남긴다.
 *   3) 설정된 GEMINI_MODEL 은 "실제로 사용 가능할 때만" 맨 앞에 둔다(존재하지 않으면 무시).
 *   4) 조회 자체가 실패하면(네트워크/테스트) 실명 정적 목록으로 폴백한다.
 *
 * 이렇게 하면 env 가 낡았거나 Google 이 모델을 갈아치워도 스스로 살아있는 모델을 찾아 붙는다.
 */
class GeminiModelResolver
{
    /** 조회 실패 시 폴백 — 모두 실제 존재하는(또는 -latest 별칭) 이름만. 선호 순. */
    private const STATIC_FALLBACK = [
        'gemini-2.5-pro',
        'gemini-2.5-flash',
        'gemini-2.0-flash',
        'gemini-flash-latest',
        'gemini-pro-latest',
        'gemini-1.5-pro',
        'gemini-1.5-flash',
    ];

    /** 텍스트 생성 대상이 아닌 모델(임베딩/이미지/음성 등)은 제외. */
    private const EXCLUDE = '/embedding|imagen|image-generation|aqa|tts|gemma|learnlm|veo|-live|native-audio/i';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * 시도할 모델 후보를 선호 순으로 반환한다.
     *
     * @return array<int, string>
     */
    public function candidates(): array
    {
        $available = $this->discover();               // 실제 사용 가능 목록(실패 시 [])
        $base = $available !== [] ? $this->rank($available) : self::STATIC_FALLBACK;

        $configured = trim((string) config('services.gemini.model'));
        // 설정 모델은 "실제로 있을 때"만 최우선. 조회 실패(오프라인/테스트)면 신뢰해서 앞에 둔다.
        $trustConfigured = $configured !== '' && ($available === [] || in_array($configured, $available, true));
        if ($trustConfigured) {
            $base = array_values(array_filter($base, fn ($m) => $m !== $configured));
            array_unshift($base, $configured);
        }

        // 상위 후보만 시도한다 — 실패 시 순차 재시도가 여러 모델로 쌓여 시간이 폭증하는 것을 막는다.
        return array_slice(array_values(array_unique($base)), 0, 6);
    }

    /**
     * ListModels 로 generateContent 지원 모델을 조회(1시간 캐시). 실패하면 빈 배열.
     *
     * @return array<int, string>
     */
    private function discover(): array
    {
        $key = (string) config('services.gemini.api_key');
        if ($key === '') {
            return [];
        }

        return Cache::remember('gemini.available_models.v1', 3600, function () use ($key): array {
            try {
                $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/')
                    . '/v1beta/models';

                $response = $this->http
                    ->timeout(15)
                    ->withHeaders(['x-goog-api-key' => $key])
                    ->get($endpoint, ['pageSize' => 1000]);

                if ($response->failed()) {
                    return [];
                }

                $ids = [];
                foreach ((array) $response->json('models', []) as $model) {
                    $methods = $model['supportedGenerationMethods'] ?? [];
                    if (! is_array($methods) || ! in_array('generateContent', $methods, true)) {
                        continue;
                    }
                    $id = str_replace('models/', '', (string) ($model['name'] ?? ''));
                    if ($id !== '' && ! preg_match(self::EXCLUDE, $id)) {
                        $ids[] = $id;
                    }
                }

                return array_values(array_unique($ids));
            } catch (\Throwable $e) {
                Log::warning('Gemini ListModels discovery failed: ' . $e->getMessage());

                return [];
            }
        });
    }

    /**
     * 조회된 실제 모델을 선호 순으로 정렬 — 안정판 우선, pro>flash, 버전 높은 순.
     * (preview/exp 는 뒤로, lite 는 뒤로.)
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private function rank(array $ids): array
    {
        usort($ids, fn (string $a, string $b) => $this->score($b) <=> $this->score($a));

        return array_values($ids);
    }

    private function score(string $id): float
    {
        $score = 0.0;

        // 버전 번호(예 gemini-2.5-pro → 2.5)가 높을수록 우선.
        if (preg_match('/gemini-(\d+)\.(\d+)/', $id, $m)) {
            $score += ((int) $m[1]) * 100 + ((int) $m[2]) * 10;
        } elseif (str_contains($id, 'latest')) {
            $score += 250; // -latest 별칭은 최신을 가리키므로 높게.
        }

        if (str_contains($id, 'pro')) {
            $score += 5;
        } elseif (str_contains($id, 'flash')) {
            $score += 3;
        }

        // 미리보기/실험/경량은 감점 — 운영 기본은 안정판.
        if (preg_match('/preview|exp|-\d{3,}$/', $id)) {
            $score -= 40;
        }
        if (str_contains($id, 'lite') || str_contains($id, '8b')) {
            $score -= 6;
        }

        return $score;
    }
}

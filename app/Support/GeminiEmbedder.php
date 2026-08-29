<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 텍스트 → 숫자 지문(임베딩) — 의미 검색의 재료.
 *
 * "방수" 로 물어도 "waterproofing" 문서를 찾으려면 글자가 아니라 뜻을 비교해야
 * 한다. Gemini 임베딩(768차원)을 받아 L2 정규화한 뒤 float32 로 pack 해 base64
 * 문자열로 저장한다(카드당 약 4KB). 정규화해 두면 코사인 유사도가 내적 한 번이다.
 *
 * pgvector 를 일부러 쓰지 않았다 — 로컬·클라우드 어디서든 확장 설치 없이 돌고,
 * 카드 수천 장까지는 PHP 내적으로 충분하다. 규모가 커지면 이 파일만 바꾸면 된다.
 */
final class GeminiEmbedder
{
    private const DIMENSIONS = 768;

    private const BATCH = 100;

    public static function available(): bool
    {
        return filled(config('services.gemini.api_key'));
    }

    /**
     * 여러 문장을 한 번에 임베딩한다. 실패한 자리는 null — 지식 축적은 임베딩이
     * 없어도 계속되어야 한다(키워드 검색은 여전히 동작한다).
     *
     * @param  array<int, string>  $texts
     * @return array<int, ?string> 입력과 같은 순서의 base64 벡터
     */
    public static function embedBatch(array $texts): array
    {
        if ($texts === [] || ! self::available()) {
            return array_fill(0, count($texts), null);
        }

        $model = (string) config('services.gemini.embed_model', 'gemini-embedding-001');
        $endpoint = rtrim((string) config('services.gemini.endpoint'), '/');
        $out = [];

        foreach (array_chunk($texts, self::BATCH) as $chunk) {
            try {
                $response = Http::timeout(60)
                    ->withHeaders(['x-goog-api-key' => (string) config('services.gemini.api_key')])
                    ->post("{$endpoint}/v1beta/models/{$model}:batchEmbedContents", [
                        'requests' => array_map(fn (string $t): array => [
                            'model' => "models/{$model}",
                            'content' => ['parts' => [['text' => mb_substr($t, 0, 6000)]]],
                            'outputDimensionality' => self::DIMENSIONS,
                        ], $chunk),
                    ]);

                $embeddings = $response->successful() ? ($response->json('embeddings') ?? []) : [];

                foreach ($chunk as $i => $_) {
                    $values = $embeddings[$i]['values'] ?? null;
                    $out[] = is_array($values) && $values !== [] ? self::packVector($values) : null;
                }
            } catch (Throwable) {
                foreach ($chunk as $_) {
                    $out[] = null;
                }
            }
        }

        return $out;
    }

    public static function embed(string $text): ?string
    {
        return self::embedBatch([$text])[0] ?? null;
    }

    /** 두 base64 벡터의 코사인 유사도. 저장 시 정규화했으므로 내적이 곧 코사인이다. */
    public static function cosine(string $a, string $b): float
    {
        $va = unpack('g*', (string) base64_decode($a, true)) ?: [];
        $vb = unpack('g*', (string) base64_decode($b, true)) ?: [];
        if ($va === [] || count($va) !== count($vb)) {
            return 0.0;
        }

        $dot = 0.0;
        foreach ($va as $i => $x) {
            $dot += $x * ($vb[$i] ?? 0.0);
        }

        return $dot;
    }

    /** L2 정규화 후 float32 리틀엔디언으로 pack → base64. */
    private static function packVector(array $values): string
    {
        $norm = sqrt(array_sum(array_map(fn ($v): float => (float) $v * (float) $v, $values)));
        if ($norm <= 0.0) {
            $norm = 1.0;
        }
        $normalized = array_map(fn ($v): float => (float) $v / $norm, $values);

        return base64_encode(pack('g*', ...$normalized));
    }
}

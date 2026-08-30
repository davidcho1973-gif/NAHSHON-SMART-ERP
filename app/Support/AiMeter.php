<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AI 호출 계량기 — 부를 때마다 토큰과 값을 적는다.
 *
 * 계량이 서비스를 멈추면 안 된다. 기록이 실패해도 분석은 그대로 끝나야 하므로
 * 모든 경로를 삼킨다. 표가 아직 없는 배포(마이그레이션 전)에서도 조용히 지나간다.
 *
 * 단가는 config/ai-pricing.php 에 둔다 — 자주 바뀌는 값이라 코드에 박지 않는다.
 * 계산된 금액은 <b>그때의 단가</b>로 함께 저장해, 나중에 단가가 바뀌어도 과거 기록이
 * 흔들리지 않게 한다.
 */
final class AiMeter
{
    private static ?bool $ready = null;

    private static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('ai_usage_logs');
    }

    /**
     * 호출 하나를 기록한다.
     *
     * @param  array<string, mixed>  $usage  엔진이 돌려준 토큰 정보(형식은 엔진마다 다르다)
     */
    public static function record(
        string $engine,
        string $feature,
        ?string $model = null,
        array $usage = [],
        int $durationMs = 0,
        bool $ok = true,
        ?string $error = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): void {
        if (! self::ready()) {
            return;
        }

        try {
            [$in, $out] = self::tokens($usage);

            DB::table('ai_usage_logs')->insert([
                'engine' => $engine,
                'model' => $model,
                'feature' => $feature,
                'company_id' => self::companyId(),
                'user_id' => auth()->id(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'input_tokens' => $in,
                'output_tokens' => $out,
                'cost_usd' => self::cost($engine, $model, $in, $out),
                'duration_ms' => $durationMs,
                'ok' => $ok,
                'error' => $error !== null ? mb_substr($error, 0, 250) : null,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * 엔진마다 토큰 필드 이름이 다르다 — 아는 이름을 전부 훑는다.
     *
     * @param  array<string, mixed>  $usage
     * @return array{0: int, 1: int}
     */
    private static function tokens(array $usage): array
    {
        $pick = function (array $keys) use ($usage): int {
            foreach ($keys as $k) {
                $v = data_get($usage, $k);
                if (is_numeric($v)) {
                    return (int) $v;
                }
            }

            return 0;
        };

        return [
            $pick(['input_tokens', 'prompt_tokens', 'promptTokenCount', 'usage.input_tokens', 'usage.prompt_tokens', 'usageMetadata.promptTokenCount']),
            $pick(['output_tokens', 'completion_tokens', 'candidatesTokenCount', 'usage.output_tokens', 'usage.completion_tokens', 'usageMetadata.candidatesTokenCount']),
        ];
    }

    private static function cost(string $engine, ?string $model, int $in, int $out): float
    {
        $rates = config('ai-pricing.'.$engine, []);
        // 모델별 단가가 있으면 그것, 없으면 엔진 기본값. 백만 토큰당 달러.
        $rate = (is_array($rates) && $model !== null && isset($rates[$model]))
            ? $rates[$model]
            : ($rates['default'] ?? null);

        if (! is_array($rate)) {
            return 0.0;
        }

        return round(
            ($in / 1_000_000) * (float) ($rate['in'] ?? 0)
            + ($out / 1_000_000) * (float) ($rate['out'] ?? 0),
            6,
        );
    }

    private static function companyId(): ?int
    {
        try {
            return \App\Support\CurrentCompany::id();
        } catch (Throwable) {
            return null;
        }
    }
}

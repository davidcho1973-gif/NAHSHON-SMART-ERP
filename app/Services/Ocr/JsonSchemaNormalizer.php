<?php

namespace App\Services\Ocr;

/**
 * 도메인 스키마를 엔진이 알아듣는 형태로 다듬는 곳.
 *
 * 이 앱의 스키마 정본은 Gemini 형식이고, 도메인 분석기들이 그 형식으로 스키마를 쓴다.
 * 문제는 표기가 한 벌이 아니라는 것이다 — 대부분 소문자('object')를 쓰는데 문서함
 * 분석기만 대문자('OBJECT')를 쓴다. 그래서 대문자 스키마를 Claude 로 보내면
 * additionalProperties 주입이 통째로 건너뛰어지고, 구조 강제가 조용히 풀린 채
 * 나간다 — 오류가 아니라 "가끔 형식이 어긋난 답" 으로 나타나 원인을 찾기 어렵다.
 *
 * 엔진마다 이 처리를 복제하면 세 번째 엔진에서 같은 함정을 다시 밟는다. 한 곳에 둔다.
 */
final class JsonSchemaNormalizer
{
    /**
     * 타입 표기를 소문자로 통일한다(OBJECT → object). 구조는 그대로 둔다.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function lowerTypes(array $schema): array
    {
        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = strtolower($schema['type']);
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $prop) {
                if (is_array($prop)) {
                    $schema['properties'][$key] = self::lowerTypes($prop);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::lowerTypes($schema['items']);
        }

        return $schema;
    }

    /**
     * 엄격 모드(strict structured outputs)용 — 모든 object 에
     * additionalProperties:false 와 required 를 채운다. Claude·OpenAI 가 요구한다.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function strict(array $schema): array
    {
        $schema = self::lowerTypes($schema);

        return self::applyStrict($schema);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function applyStrict(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object' && isset($schema['properties']) && is_array($schema['properties'])) {
            $schema['additionalProperties'] = false;
            // 엄격 모드는 모든 속성이 required 여야 한다 — 빠진 칸은 null 로 온다.
            if (! isset($schema['required'])) {
                $schema['required'] = array_keys($schema['properties']);
            }
            foreach ($schema['properties'] as $key => $prop) {
                if (is_array($prop)) {
                    $schema['properties'][$key] = self::applyStrict($prop);
                }
            }
        }

        if (($schema['type'] ?? null) === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::applyStrict($schema['items']);
        }

        return $schema;
    }
}

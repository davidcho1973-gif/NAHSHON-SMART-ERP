<?php

namespace App\Services\Ocr;

/**
 * 공통 OCR/문서·이미지 분석 엔진 계약.
 *
 * 도메인 분석기(영수증·배지·차량·장비 등)는 자신의 프롬프트·스키마·정규화 로직만 갖고,
 * 실제 비전 LLM 호출은 이 엔진에 위임한다 → Gemini/Claude 를 환경변수로 전환 가능.
 */
interface OcrEngine
{
    /**
     * 이미지(1장 이상) + 프롬프트 + JSON 스키마 → 구조화 결과.
     *
     * @param  array<int, array{data: string, mime_type: string}>  $images  base64 이미지 파트
     * @param  array<string, mixed>  $schema  응답 JSON 스키마(Gemini responseSchema 형식)
     * @return array{data: array<string, mixed>, model: string}
     */
    public function analyze(array $images, string $prompt, array $schema): array;

    /** 엔진 식별자 ('gemini' | 'claude'). */
    public function name(): string;
}

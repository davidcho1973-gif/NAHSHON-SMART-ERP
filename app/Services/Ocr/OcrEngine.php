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

    /**
     * 원본을 그대로 붙여 보낼 수 있는 최대 바이트.
     *
     * 예전에는 이 값이 설정 한 곳에 상수로 박혀 있었다(15MB). 그런데 한도는 엔진마다 다르다 —
     * 요청 본문에 base64 로 실어 보내는 엔진은 요청 크기에 묶이지만, 파일을 먼저 올려 두고
     * 주소만 넘기는 엔진은 사실상 묶이지 않는다. 한 숫자로 묶어 두면 큰 도면을 보낼 수 있는
     * 엔진까지 못 보내게 막힌다 — 실제로 17MB 도면이 그렇게 막혀 «글자만» 보내다 실패했다.
     * 그래서 한도는 엔진 자신이 말한다.
     */
    public function maxAttachmentBytes(): int;
}

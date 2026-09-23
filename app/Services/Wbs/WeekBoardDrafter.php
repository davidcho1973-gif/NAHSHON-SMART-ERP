<?php

namespace App\Services\Wbs;

use App\Models\OpsMeeting;
use App\Services\Ocr\OcrEngine;
use App\Services\Ops\VoiceNoteTranscriber;
use RuntimeException;

/**
 * 작업판 비서 — 말·수기 노트 사진·회의 녹음을 듣고 「공종 · 하는 일 · 인원」 줄로 정리한다.
 *
 * ── 왜 필요한가 ────────────────────────────────────────────────────────
 * 사장의 말: 「이번 주 할 일을 따로 시간 내서 만들고 싶지 않다. 매일 공정 미팅과 토요일
 * 다음 주 미팅에서 오간 말을 AI 가 비서처럼 듣고 정리해서 처리하는 시스템」. 작업판이
 * 좋아도 타이핑을 요구하면 안 채워진다. 채워지지 않는 판은 죽은 공정표와 같다.
 *
 * ── 지키는 것 ──────────────────────────────────────────────────────────
 *  1. <b>초안이다.</b> 여기서 나온 줄은 사람이 화면에서 보고 고친 뒤 저장한다. AI 가 들은
 *     대로 바로 작업판이 되면, 잘못 들은 「후드」 가 이번 주 일이 된다.
 *  2. <b>없는 말은 만들지 않는다.</b> 인원이 안 나왔으면 비워 둔다. 0 이나 추정치를
 *     적으면 그 숫자가 계획이 된다.
 *  3. <b>공종은 이 현장의 낱말로.</b> 도면에서 분석한 공정표의 공종과 직원 공종을 목록으로
 *     주고 그중에서 고르게 한다 — 「전기」 와 「전기/배관」 이 두 공종이 되지 않게.
 *  4. 들은 그대로(heard)를 함께 돌려준다 — «AI 가 왜 이렇게 적었나» 의 근거다.
 */
class WeekBoardDrafter
{
    public function __construct(
        private readonly OcrEngine $engine,
        private readonly VoiceNoteTranscriber $voice,
    ) {}

    /**
     * 글 → 줄. (녹음·사진은 먼저 글로 옮긴 뒤 여기로 온다.)
     *
     * @param  array<int, string>  $trades  이 현장의 공종 낱말
     * @return array{lines: array<int, array<string, mixed>>, heard: string, summary: string}
     */
    public function fromText(string $text, array $trades, string $weekLabel = '이번 주'): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('읽을 내용이 없습니다.');
        }

        $result = $this->engine->analyze([], $this->prompt($text, $trades, $weekLabel), $this->schema());

        return $this->normalize(is_array($result['data'] ?? null) ? $result['data'] : [], $trades) + ['heard' => $text];
    }

    /**
     * 수기 노트·화이트보드 사진 → 줄.
     *
     * @param  array<int, string>  $trades
     * @return array{lines: array<int, array<string, mixed>>, heard: string, summary: string}
     */
    public function fromImage(string $bytes, string $mime, array $trades, string $weekLabel = '이번 주'): array
    {
        $prompt = "먼저 사진 속 손글씨·판서를 있는 그대로 읽어 heard 에 옮겨 적으세요.\n"
            .$this->prompt('(사진 본문은 위 이미지)', $trades, $weekLabel);

        $result = $this->engine->analyze(
            [['data' => base64_encode($bytes), 'mime_type' => $mime]],
            $prompt,
            $this->schema(),
        );
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        return $this->normalize($data, $trades) + ['heard' => trim((string) ($data['heard'] ?? ''))];
    }

    /**
     * 녹음 → 글 → 줄. 받아쓰기는 이미 있는 현장 음성 경로를 그대로 쓴다.
     *
     * @param  array<int, string>  $trades
     * @return array{lines: array<int, array<string, mixed>>, heard: string, summary: string}
     */
    public function fromAudio(string $bytes, string $mime, array $trades, string $weekLabel = '이번 주'): array
    {
        $spoken = $this->voice->transcribe($bytes, $mime);
        if (! ($spoken['success'] ?? false)) {
            throw new RuntimeException((string) ($spoken['error'] ?? '녹음을 옮기지 못했습니다.'));
        }

        $out = $this->fromText((string) $spoken['text'], $trades, $weekLabel);
        $out['heard'] = (string) ($spoken['heard'] ?: $spoken['text']);

        return $out;
    }

    /**
     * 끝난 회의(받아쓰기 완료) → 줄. 토요일 「다음 주」 미팅이 여기로 온다.
     *
     * @param  array<int, string>  $trades
     * @return array{lines: array<int, array<string, mixed>>, heard: string, summary: string}
     */
    public function fromMeeting(OpsMeeting $meeting, array $trades, string $weekLabel = '다음 주'): array
    {
        $t = $meeting->transcripts ?? [];
        // 두 받아쓰기 중 있는 것을 쓴다. 둘 다 있으면 사람 이름·용어에 강한 쪽(scribe)을 먼저.
        $text = trim((string) ($t['scribe']['text'] ?? '')) ?: trim((string) ($t['gemini']['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('이 회의는 아직 받아쓰기가 끝나지 않았습니다.');
        }

        $out = $this->fromText($text, $trades, $weekLabel);
        $out['heard'] = $text;

        return $out;
    }

    /** @param array<int, string> $trades */
    private function prompt(string $text, array $trades, string $weekLabel): string
    {
        $list = $trades !== [] ? implode(', ', $trades) : '(등록된 공종 없음 — 말한 대로 적으세요)';

        return <<<PROMPT
당신은 미국 건설 현장 소장의 비서입니다. 아래 내용(회의·지시·메모)에서 **{$weekLabel}에 할 일**을
「공종 · 하는 일 · 인원」 줄로 정리합니다. JSON 만 반환합니다.

[이 현장의 공종 목록 — 반드시 이 중에서 고르세요. 정말 없을 때만 새 이름]
{$list}

규칙:
- 하는 일은 현장에서 부르는 말 그대로, 짧게 (예: 급탕 배관, 후드 배선, 그리스 트랩 설치). 코드·전문용어로 바꾸지 마세요.
- 같은 공종의 여러 일은 줄을 나눕니다. 한 줄에 한 일.
- 인원이 말로 나왔을 때만 headcount 에 숫자. 안 나왔으면 null. 추측 금지.
- 「하지 말자」「취소」「다음에」 로 끝난 일은 넣지 않습니다. 마지막 결정을 따릅니다.
- 자재 주문·안전 지적·잡담은 할 일이 아니면 뺍니다. 단 「자재 오면 시작」 같은 조건은 note 에 적습니다.
- summary 는 한국어 한 문장.

[내용]
{$text}
PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'heard' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'trade' => ['type' => 'string'],
                            'task' => ['type' => 'string'],
                            'headcount' => ['type' => 'number'],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @param  array<int, string>  $trades
     * @return array{lines: array<int, array<string, mixed>>, summary: string}
     */
    private function normalize(array $d, array $trades): array
    {
        $lines = [];
        foreach (is_array($d['lines'] ?? null) ? $d['lines'] : [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $task = trim((string) ($raw['task'] ?? ''));
            $trade = $this->matchTrade(trim((string) ($raw['trade'] ?? '')), $trades);
            if ($task === '' || $trade === '') {
                continue;   // 공종도 일도 없는 줄은 할 일이 아니다.
            }
            $lines[] = [
                'trade' => $trade,
                'task' => mb_substr($task, 0, 255),
                'headcount' => isset($raw['headcount']) && is_numeric($raw['headcount']) && (float) $raw['headcount'] > 0
                    ? (float) $raw['headcount'] : null,
                'note' => trim((string) ($raw['note'] ?? '')) ?: null,
            ];
        }

        return ['lines' => $lines, 'summary' => trim((string) ($d['summary'] ?? ''))];
    }

    /**
     * 모델이 적은 공종을 현장 낱말에 맞춘다 — 대소문자·공백 차이로 새 공종이 생기지 않게.
     *
     * @param  array<int, string>  $trades
     */
    private function matchTrade(string $given, array $trades): string
    {
        if ($given === '') {
            return '';
        }
        $norm = fn (string $s): string => mb_strtolower(preg_replace('/\s+/u', '', $s) ?? $s);
        foreach ($trades as $t) {
            if ($norm($t) === $norm($given)) {
                return $t;
            }
        }
        foreach ($trades as $t) {
            if (str_contains($norm($t), $norm($given)) || str_contains($norm($given), $norm($t))) {
                return $t;
            }
        }

        return mb_substr($given, 0, 60);
    }
}

<?php

namespace App\Services\Drawings;

use App\Models\DrawingSheet;
use App\Models\WorkSectionSheet;
use App\Services\Ocr\OcrEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 도면 한 장의 글자와 번호를 읽는다.
 *
 * ── 두 갈래 ───────────────────────────────────────────────────────────
 *  - 도면에 글자가 들어 있는 장(CAD 에서 바로 뽑은 PDF): 브라우저가 뽑은 글자를 그대로 쓰고,
 *    AI 는 표제란에서 «이 장의» 번호·제목만 고른다. 글자를 새로 지어내지 않는다.
 *  - 사진으로 저장된 장(703K 건축 도면처럼 한 장 전체가 그림 한 장): 브라우저가 장을 네 조각으로
 *    잘라 보내고, AI 가 조각마다 글자를 읽어 붙인다(text_source = ocr). 조각으로 자르는 이유는
 *    44×34 인치 도면을 한 장짜리 그림으로 넘기면 방 이름·치수 글자가 뭉개지기 때문이다.
 *
 * 사람이 번호를 고친 장(manual)은 다시 읽어도 번호·제목을 덮지 않는다 — 글자만 새로 붙인다.
 */
class DrawingSheetReader
{
    public function __construct(private readonly OcrEngine $engine) {}

    /** 조각 사진이 임시로 머무는 곳 — 읽고 나면 지운다. */
    public static function tileDir(DrawingSheet $sheet): string
    {
        return 'drawing-sheets/'.$sheet->intelligent_document_id.'/tiles/p'.$sheet->page_no;
    }

    public function read(DrawingSheet $sheet): void
    {
        // 사진 조각 판독은 수십 초 걸린다. 응답을 보낸 뒤라 게이트웨이 제한은 없지만 PHP·HTTP 한도는 있다.
        @set_time_limit(600);
        config(['services.gemini.timeout' => max(300, (int) config('services.gemini.timeout'))]);

        $disk = Storage::disk((string) ($sheet->thumb_disk ?: config('filesystems.documents_disk')));
        $tiles = collect($disk->files(self::tileDir($sheet)))->sort()->values();

        try {
            $hints = $this->knownSheetNos($sheet);
            if ($tiles->isNotEmpty()) {
                $images = $tiles->map(fn (string $p): array => ['data' => base64_encode((string) $disk->get($p)), 'mime_type' => 'image/jpeg'])->all();
                $result = $this->engine->analyze($images, $this->ocrPrompt(count($images), $hints), $this->schema(true));
                $data = is_array($result['data'] ?? null) ? $result['data'] : [];
                $this->save($sheet, $data, 'ocr', trim((string) ($data['text'] ?? '')), (string) ($result['model'] ?? ''));
            } else {
                $text = trim((string) $sheet->text);   // 브라우저가 뽑아 둔 글자
                $data = [];
                $model = '';
                if ($text !== '') {
                    // 글자가 든 쪽의 정본은 그 글자다. AI 는 번호·제목을 고르는 보조일 뿐이라,
                    // AI 가 멈춰도 쪽 읽기는 끝난다 — 번호는 아래 알려진 번호로 찾거나 사람이 적는다.
                    try {
                        $result = $this->engine->analyze([], $this->titlePrompt($text, $hints), $this->schema(false));
                        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
                        $model = (string) ($result['model'] ?? '');
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                // AI 가 번호를 못 찾으면 알려진 번호 중 글자에 나오는 것을 쓴다(표제란 번호는 거의 늘 글자에 있다).
                $data['sheet_no'] = ($data['sheet_no'] ?? '') ?: $this->guessFromHints($text, $hints);
                $this->save($sheet, $data, $text !== '' ? 'pdf' : 'none', $text, $model);
            }
        } catch (\Throwable $e) {
            report($e);
            $sheet->forceFill([
                'status' => DrawingSheet::STATUS_FAILED,
                'error' => mb_substr('읽지 못했습니다: '.$e->getMessage(), 0, 500),
            ])->save();
        } finally {
            foreach ($tiles as $p) {
                try {
                    $disk->delete($p);
                } catch (\Throwable $e) {
                    Log::warning('도면 조각 삭제 실패: '.$e->getMessage());
                }
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function save(DrawingSheet $sheet, array $data, string $source, string $text, string $model): void
    {
        $attrs = [
            'text_source' => $source,
            'text' => $text !== '' ? $text : null,
            'status' => DrawingSheet::STATUS_DONE,
            'error' => null,
            'ai_model' => $model !== '' ? mb_substr($model, 0, 60) : null,
            'read_at' => Carbon::now(),
        ];
        if (! $sheet->manual) {
            $attrs['sheet_no'] = DrawingSheet::normalizeNo((string) ($data['sheet_no'] ?? '')) ?? $sheet->sheet_no;
            $title = trim((string) ($data['title'] ?? ''));
            $attrs['title'] = $title !== '' ? mb_substr($title, 0, 255) : $sheet->title;
            $discipline = trim((string) ($data['discipline'] ?? ''));
            $attrs['discipline'] = $discipline !== '' ? mb_substr($discipline, 0, 40) : $sheet->discipline;
        }
        $sheet->forceFill($attrs)->save();
    }

    /**
     * 이 현장에서 이미 알고 있는 도면 번호 — 공정에 골라 둔 번호와 다른 장에서 읽은 번호.
     * AI 에게 «이 중에 있으면 이 표기로» 라고 준다. 없는 번호를 억지로 고르게 하지는 않는다.
     *
     * @return array<int, string>
     */
    private function knownSheetNos(DrawingSheet $sheet): array
    {
        $picked = WorkSectionSheet::query()
            ->whereHas('section', fn ($q) => $q->where('site_id', $sheet->site_id))
            ->pluck('sheet_no');
        $read = DrawingSheet::query()->where('site_id', $sheet->site_id)->whereKeyNot($sheet->id)
            ->whereNotNull('sheet_no')->pluck('sheet_no');

        return $picked->concat($read)->map(fn ($n) => DrawingSheet::normalizeNo($n))->filter()->unique()->values()->all();
    }

    /** @param array<int, string> $hints */
    private function guessFromHints(string $text, array $hints): ?string
    {
        $upper = strtoupper($text);
        $found = array_values(array_filter($hints, fn (string $n): bool => str_contains($upper, $n)));

        // 글자에 알려진 번호가 딱 하나만 나오면 그것이 이 장의 번호다. 여럿이면(다른 장을 가리키는
        // 참조가 섞임) 고르지 않는다 — 틀린 번호가 붙느니 비워 두고 사람이 고치게 한다.
        return count($found) === 1 ? $found[0] : null;
    }

    /** @param array<int, string> $hints */
    private function titlePrompt(string $text, array $hints): string
    {
        $known = $hints !== [] ? implode(', ', array_slice($hints, 0, 120)) : '(없음)';
        $body = mb_substr($text, 0, 20000);

        return <<<PROMPT
아래는 건설 도면 세트 중 한 장에서 뽑은 글자입니다. 이 장 자신의 표제란(title block)에 적힌
도면 번호(sheet number)와 도면 제목(sheet title), 공종(discipline)을 JSON 으로 돌려주세요.

규칙:
- 다른 장을 가리키는 참조("SEE 703K-P405", "REFER TO SHEET ...")는 이 장의 번호가 아닙니다.
- 이 현장에서 알려진 도면 번호: {$known}. 이 장의 번호가 이 목록에 있으면 목록의 표기 그대로 쓰세요.
- 찾을 수 없으면 빈 문자열. 추측 금지.
- discipline 은 General / Civil / Structural / Architectural / Life Safety / Interior / Fire Protection / Plumbing / Mechanical / Electrical 중 하나.

[글자]
{$body}
PROMPT;
    }

    /** @param array<int, string> $hints */
    private function ocrPrompt(int $pieces, array $hints): string
    {
        $known = $hints !== [] ? implode(', ', array_slice($hints, 0, 120)) : '(없음)';
        $layout = $pieces === 4 ? '왼쪽 위 · 오른쪽 위 · 왼쪽 아래 · 오른쪽 아래 순서의 네 조각' : "{$pieces}개 조각";

        return <<<PROMPT
첨부한 그림들은 사진으로 저장된 건설 도면 한 장을 {$layout}으로 자른 것입니다.
이 장에 적힌 글자를 읽어 JSON 으로 돌려주세요.

- text: 읽을 수 있는 글자를 모두 옮겨 적습니다. 방 이름과 번호(예: KITCHEN 100, OFFICE 108), 벽·문 부호,
  치수, 노트, 범례, 일람표 내용을 빠짐없이. 한 줄에 한 항목. 보이지 않는 글자는 지어내지 않습니다.
- sheet_no / title: 오른쪽 아래(또는 오른쪽 끝) 표제란에 적힌 이 장의 도면 번호와 제목.
  다른 장을 가리키는 참조는 이 장의 번호가 아닙니다.
  이 현장에서 알려진 도면 번호: {$known}. 이 장의 번호가 목록에 있으면 목록의 표기 그대로.
- discipline: General / Civil / Structural / Architectural / Life Safety / Interior / Fire Protection / Plumbing / Mechanical / Electrical 중 하나.
- 찾을 수 없는 값은 빈 문자열.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(bool $withText): array
    {
        $props = [
            'sheet_no' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'discipline' => ['type' => 'string'],
        ];
        if ($withText) {
            $props['text'] = ['type' => 'string'];
        }

        return ['type' => 'object', 'properties' => $props, 'required' => array_keys($props)];
    }
}

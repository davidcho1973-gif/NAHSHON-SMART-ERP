<?php

namespace App\Services\Takeoff;

use App\Models\BoqItem;
use App\Models\IntelligentDocument;
use App\Models\Submittal;
use Illuminate\Support\Str;

/**
 * 이 조항과 관련된 도면 찾기 — "이게 어느 도면 얘기지?" 를 대신 뒤진다.
 *
 * 제출물 대장의 한 줄은 시방 조항이다. 그런데 실제로 일할 때 필요한 것은 그 조항이
 * <b>어느 도면의 무엇</b>을 말하는지다. 지금은 사람이 도면 55시트에서 찾아야 한다.
 *
 * AI 를 부르지 않는다. 이미 시스템 안에 있는 세 가지 신호를 합치면 충분하다:
 *  1. <b>물량 대장</b> — 같은 품목의 물량을 어느 도면에서 뽑았는지 이미 적혀 있다.
 *     이게 가장 강한 신호다(사람이나 AI 가 실제로 그 도면을 보고 세었다는 뜻).
 *  2. <b>문서 본문·제목</b> — 조항의 낱말이 도면 제목이나 판독 내용에 나오는가.
 *  3. <b>공종</b> — 같은 discipline 이면 한 표 더.
 *
 * 근거를 함께 돌려준다 — "왜 이 도면인가" 를 말하지 못하면 사람이 믿지 않는다.
 */
class RelatedDrawingFinder
{
    /** 도면으로 볼 문서 종류. 시방서는 조항의 출처라 여기서 뺀다(이미 원문 보기가 있다). */
    private const DRAWING_TYPES = ['drawing', 'specification', 'submittal', 'other'];

    /**
     * CSI 앞 두 자리 → 그 조항이 그려지는 도면 공종.
     *
     * 도면 파일 이름은 공종으로 갈려 있고(01_일반·02_토목·04_건축…), 시방 조항 번호는
     * CSI 체계다. 둘을 잇는 표가 없으면 "08 3800 트래픽도어" 가 건축 도면에 있다는
     * 사실을 기계가 알 수 없다. 사람은 당연히 아는 것이라 어디에도 적혀 있지 않았다.
     */
    private const CSI_TO_TRADE = [
        '01' => '일반', '02' => '토목', '03' => '구조', '04' => '건축', '05' => '구조',
        '06' => '건축', '07' => '건축', '08' => '건축', '09' => '건축', '10' => '건축',
        '11' => '주방', '12' => '건축', '13' => '건축', '14' => '건축',
        '21' => '소방', '22' => '배관', '23' => '기계',
        '26' => '전기', '27' => '전기', '28' => '전기',
        '31' => '토목', '32' => '토목', '33' => '토목',
    ];

    /**
     * @return array{success: bool, error?: string, terms?: array<int, string>, rows?: array<int, array<string, mixed>>}
     */
    public function find(Submittal $submittal, int $limit = 6): array
    {
        $terms = $this->terms($submittal);
        if ($terms === []) {
            return ['success' => true, 'terms' => [], 'rows' => []];
        }

        $scores = [];   // documentId => ['score' => int, 'why' => [..]]

        // ── 신호 1: 같은 품목의 물량을 뽑아 온 도면
        $boq = BoqItem::query()
            ->when($submittal->project_id, fn ($q) => $q->where('project_id', $submittal->project_id))
            ->whereNotNull('source_document_id')
            ->where(function ($q) use ($terms): void {
                foreach ($terms as $t) {
                    $q->orWhere('name_kr', 'ilike', "%{$t}%")
                        ->orWhere('name_en', 'ilike', "%{$t}%")
                        ->orWhere('spec', 'ilike', "%{$t}%");
                }
            })
            ->get(['source_document_id', 'name_kr', 'qty', 'unit']);

        foreach ($boq as $row) {
            $id = (int) $row->source_document_id;
            $scores[$id] ??= ['score' => 0, 'why' => []];
            $scores[$id]['score'] += 6;
            $qty = rtrim(rtrim((string) $row->qty, '0'), '.');
            $why = "물량 «{$row->name_kr}» {$qty}{$row->unit} 를 이 도면에서 산출";
            if (! in_array($why, $scores[$id]['why'], true)) {
                $scores[$id]['why'][] = $why;
            }
        }

        // 조항 번호와 공종 — 703K 는 시방이 "087100_도어하드웨어", 도면이 "04_건축_…"
        // 처럼 파일 이름 자체에 그 정보를 담고 있다. 본문 색인이 아직 없는 문서라도
        // 이 두 신호는 잡히므로, 점수만이 아니라 <b>후보를 고르는 조건</b>에도 넣는다.
        // (낱말만으로 고르면 "트래픽도어" 가 안 적힌 건축 평면도는 아예 후보에서 빠진다.)
        $csiCompact = preg_replace('/[^0-9]/', '', (string) $submittal->csi) ?: null;
        $trade = $csiCompact !== null && strlen($csiCompact) >= 2
            ? (self::CSI_TO_TRADE[substr($csiCompact, 0, 2)] ?? null)
            : null;

        // ── 신호 2·3: 문서 제목·본문·공종
        $docs = IntelligentDocument::query()
            ->when($submittal->project_id, fn ($q) => $q->where('project_id', $submittal->project_id))
            ->when($submittal->site_id, fn ($q) => $q->orWhere('site_id', $submittal->site_id))
            ->whereIn('document_type', self::DRAWING_TYPES)
            ->where('id', '<>', (int) $submittal->source_document_id)   // 조항 출처는 이미 "원문 보기" 가 연다
            ->where(function ($q) use ($terms, $trade, $csiCompact): void {
                foreach ($terms as $t) {
                    $q->orWhere('title', 'ilike', "%{$t}%")
                        ->orWhere('original_file_name', 'ilike', "%{$t}%")
                        ->orWhere('search_text', 'ilike', "%{$t}%");
                }
                if ($trade !== null) {
                    $q->orWhere('original_file_name', 'ilike', "%{$trade}%")
                        ->orWhere('title', 'ilike', "%{$trade}%")
                        ->orWhere('discipline', 'ilike', "%{$trade}%");
                }
                if ($csiCompact !== null && strlen($csiCompact) >= 6) {
                    $q->orWhere('original_file_name', 'ilike', '%'.substr($csiCompact, 0, 6).'%');
                }
            })
            ->limit(80)
            ->get(['id', 'title', 'original_file_name', 'document_number', 'document_type', 'discipline', 'search_text', 'summary']);

        foreach ($docs as $doc) {
            $id = (int) $doc->id;
            $scores[$id] ??= ['score' => 0, 'why' => []];

            $fileName = (string) ($doc->original_file_name.' '.$doc->title.' '.$doc->document_number);

            // 시방 조항 번호가 파일 이름에 그대로 있으면 거의 확실하다.
            if ($csiCompact !== null && strlen($csiCompact) >= 4
                && str_contains(preg_replace('/[^0-9]/', '', $fileName) ?: '', substr($csiCompact, 0, 6))) {
                $scores[$id]['score'] += 8;
                $scores[$id]['why'][] = "조항 번호 «{$submittal->csi}» 가 파일 이름에";
            }

            if ($trade !== null && str_contains($fileName, $trade)) {
                $scores[$id]['score'] += 3;
                $scores[$id]['why'][] = "{$trade} 공종 도면 (CSI ".substr($csiCompact, 0, 2).')';
            }

            foreach ($terms as $t) {
                $inTitle = Str::contains(Str::lower((string) ($doc->title.' '.$doc->original_file_name)), Str::lower($t));
                $inBody = Str::contains(Str::lower((string) $doc->search_text), Str::lower($t));

                if ($inTitle) {
                    $scores[$id]['score'] += 4;
                    $scores[$id]['why'][] = "도면 이름에 «{$t}»";
                } elseif ($inBody) {
                    $scores[$id]['score'] += 1;
                    $scores[$id]['why'][] = "도면 내용에 «{$t}»";
                }
            }

            if ($submittal->section && $doc->discipline
                && Str::contains(Str::lower((string) $doc->discipline), Str::lower(mb_substr($submittal->section, 0, 6)))) {
                $scores[$id]['score'] += 2;
                $scores[$id]['why'][] = '같은 공종';
            }

            // 도면으로 분류된 문서를 앞에 세운다. 감점이 아니라 가점인 이유 —
            // 분류가 아직 'other' 로 남아 있는 도면이 많아서, 감점하면 진짜 도면이
            // 목록에서 사라진다.
            if ($doc->document_type === 'drawing') {
                $scores[$id]['score'] += 2;
            }
        }

        arsort($scores);
        $top = array_slice($scores, 0, $limit, true);
        $ids = array_keys($top);
        if ($ids === []) {
            return ['success' => true, 'terms' => $terms, 'rows' => []];
        }

        $byId = IntelligentDocument::query()->whereKey($ids)
            ->get(['id', 'title', 'original_file_name', 'document_number', 'document_type', 'discipline', 'summary'])
            ->keyBy('id');

        $rows = [];
        foreach ($top as $id => $info) {
            $doc = $byId->get($id);
            if (! $doc || $info['score'] <= 0) {
                continue;
            }
            $rows[] = [
                'id' => $doc->id,
                'title' => $doc->title ?: $doc->original_file_name,
                'number' => $doc->document_number,
                'type' => $doc->document_type,
                'discipline' => $doc->discipline,
                'summary' => $doc->summary ? mb_substr($doc->summary, 0, 150) : null,
                'score' => $info['score'],
                // 왜 이 도면인지 — 근거 없이 목록만 주면 사람이 하나하나 다시 열어 봐야 한다.
                'why' => array_slice(array_values(array_unique($info['why'])), 0, 3),
            ];
        }

        return ['success' => true, 'terms' => $terms, 'rows' => $rows];
    }

    /**
     * 조항에서 찾을 낱말 — 공종 이름과 제목의 명사들.
     *
     * @return array<int, string>
     */
    private function terms(Submittal $submittal): array
    {
        $stop = [
            '제출', '제출물', '제품자료', 'product', 'data', 'submit', 'submittal', 'shop', 'drawings',
            '각', '및', '포함', '명기', '표기', '관련', '기준', '경우', '이상', '이하', '별도', '해당',
            '자료', '서류', '보고서', '내역', '사항', '요구', '작성', '확인', '검토', '승인',
        ];

        $text = trim(($submittal->section ?: '').' '.mb_substr((string) $submittal->title, 0, 120));
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];

        $terms = [];
        foreach ($tokens as $t) {
            $t = trim($t);
            if (mb_strlen($t) < 2 || in_array(mb_strtolower($t), $stop, true) || is_numeric($t)) {
                continue;
            }
            $terms[] = $t;
        }

        // 공종 이름(예: "고성능 트래픽도어")이 가장 좋은 검색어라 앞에 둔다.
        return array_slice(array_values(array_unique($terms)), 0, 5);
    }
}

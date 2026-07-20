<?php

namespace App\Services\Wbs;

/**
 * "AI 메뉴얼 분석"의 충실한 추출(faithful extraction) 사양 — 프롬프트 + 스키마.
 *
 * 왜 별도 클래스인가: 업로드 문서가 CPM/공정표(액티비티 목록·기간·날짜)일 때는 요약하면 안 된다.
 * 예전 분석기는 문서를 3~6개 추상 Stage 로 "요약"해서 정작 현장에서 필요한 각 행(액티비티)과
 * 그 선행관계·여유·임계경로·투입조·날짜가 전부 사라졌다. 이 사양은 그 반대를 강제한다:
 * **문서에 있는 모든 행을 있는 그대로, 하나도 빠뜨리지 말고, 지어내지도 말고 추출**한다.
 *
 * 추출된 activities 는 ScheduleImporter(XLSX 경로와 동일한 검증된 영속화)로 넘겨
 * 마일스톤 → 공종 → 액티비티 트리로 저장한다. 문서가 CPM 이 아니라 산문형 작업범위면
 * activities 를 비우고 기존 WBS 생성(stages)으로 폴백한다.
 *
 * Gemini 와 Claude 두 분석기가 공유한다(스키마 strict 여부만 다름).
 */
final class CpmExtraction
{
    /**
     * 분석 프롬프트. 문서가 공정표면 모든 행 추출, 아니면 WBS 생성.
     *
     * @param  array{label: string, project: string, type: string, scope: string}  $c
     * @param  string|null  $pdfText  서버가 PDF 에서 추출한 표 텍스트(정본). 있으면 이걸 근거로 전량 추출.
     */
    public static function prompt(array $c, ?string $pdfText = null): string
    {
        $tableBlock = '';
        if ($pdfText !== null && trim($pdfText) !== '') {
            $tableBlock = <<<TBL

━━━ [공정표 원문 텍스트 — 정본] ━━━
아래는 첨부 문서(공정표)에서 서버가 추출한 표 텍스트다. **이 텍스트가 정본이며, 여기 있는 모든 행을 하나도 빠짐없이 activities 로 추출하라.**
각 행의 열 순서는 대개: ID → 작업명(한글) → Activity(영문) → 공기(일) → 선행작업 → ES일자 → EF일자 → LS일자 → LF일자 → 여유 → CP(★=임계) → 배분원가 → 공종 → 투입조.
**공종(예: GC/ELEC/PLUMB)과 투입조(예: PM/PE, 2 carpenters + 3 laborers)는 서로 다른 열이다 — 절대 합치지 마라**(trade 에는 공종 코드만, crew 에는 투입조 원문만).
행 수가 많다(수십 개일 수 있다). 몇 개만 반환하면 잘못된 것이다 — 텍스트에 있는 모든 ID 행을 전부 추출하라.
─────────────────────────────
{$pdfText}
─────────────────────────────

TBL;
        }

        return <<<PROMPT
{$tableBlock}
당신은 미국 내 한국 대기업 플랜트/공장(LG배터리·SK반도체·현대차 등) 설치공사의 공정관리(CPM/스케줄) 전문가입니다.
첨부된 문서를 분석하여 공정관리(WBS) 데이터를 만듭니다. JSON 만 반환하세요.

[프로젝트] {$c['project']}
[공종] {$c['type']}
[작업범위(참고)]
{$c['scope']}

■ 판단: 문서가 "공정표/CPM"인가?
문서에 액티비티 목록(예: A010, A020… 같은 ID 나 번호가 매겨진 작업 행), 공기(기간), 날짜(ES/EF),
선행작업, 투입조 같은 표가 있으면 그것은 **공정표(CPM)** 입니다.

━━━ 경우 1: 공정표(CPM)이면 → "activities" 에 모든 행을 그대로 추출 (stages 는 빈 배열) ━━━
철칙:
1. 문서에 있는 **모든 액티비티 행을 하나도 빠짐없이** 추출한다. 요약·병합·대표행 선정 금지.
2. 문서에 없는 값을 **지어내지 않는다**. 없는 필드는 빈 문자열/빈 배열로 둔다.
3. 조달(자재 발주) 패키지 행도 액티비티다 — 있으면 함께 추출한다.
각 activity 필드:
  - id: 액티비티 ID (예 "A010"). 없으면 순번을 "A001" 형태로 부여.
  - name_ko: 한글 작업명 (문서 그대로)
  - name_en: 영문 작업명 (있으면)
  - dur: 공기(일). 정수. 없으면 0.
  - preds: 선행작업 ID 배열 (예 ["A010","A020"]). 없으면 [].
  - es / ef / ls / lf: 각각 ES/EF/LS/LF 일자. "YYYY-MM-DD" 형식. 없으면 "".
  - float_days: 총여유(일). 정수. 없으면 빈 문자열 "".
  - is_critical: 임계경로면 true (문서의 ★ / CP / Critical 표시). 아니면 false.
  - cost: 배분원가(숫자만). 없으면 0.
  - trade: 공종 코드 (문서의 공종 그대로: GC / ELEC / PLUMB / MECH / FIRE / FRAME / DEMO / DOOR / PAINT / CEIL / TILE / MILL / FLOOR / SPEC / INSP / LV / PE 등). 담당 협력사(회사명)가 아니라 공종이다 — 회사명은 절대 넣지 마라(협력사는 사람이 나중에 배정).
  - crew: 투입조 원문 텍스트 (예 "2 carpenters + 3 laborers"). 없으면 "".
그리고 문서에 마일스톤(검사 관문/주요 기점)이 있으면 "milestones" 에 {name, date("YYYY-MM-DD")} 로 추출한다(없으면 []).

━━━ 경우 2: 공정표가 아니라 산문형 작업범위/시방서이면 → "stages" 로 표준 WBS 생성 (activities 는 빈 배열) ━━━
  - stages: 3~6개. stage_no("1"), stage_name, tasks.
  - tasks: stage 당 2~5개. task_no("1.2"), task_name, sub_tasks.
  - sub_tasks: task 당 2~6개. sub_no("1.2.3"), sub_name(구체 작업명),
    trade(공종 — 회사명 금지), manhours(정수), days(정수), ehs(high/medium/low).

반드시 경우 1 또는 경우 2 중 하나만 채우고 나머지 배열은 비웁니다.
PROMPT;
    }

    /**
     * 추출 스키마. $strict=true 이면 Anthropic structured outputs 규칙(모든 object 에
     * additionalProperties:false + required)을 적용한다.
     *
     * @return array<string, mixed>
     */
    public static function schema(bool $strict): array
    {
        $obj = static function (array $properties, array $required) use ($strict): array {
            $o = ['type' => 'object', 'properties' => $properties];
            if ($strict) {
                $o['additionalProperties'] = false;
                $o['required'] = $required;
            }

            return $o;
        };

        $activity = $obj([
            'id' => ['type' => 'string'],
            'name_ko' => ['type' => 'string'],
            'name_en' => ['type' => 'string'],
            'dur' => ['type' => 'integer'],
            'preds' => ['type' => 'array', 'items' => ['type' => 'string']],
            'es' => ['type' => 'string'],
            'ef' => ['type' => 'string'],
            'ls' => ['type' => 'string'],
            'lf' => ['type' => 'string'],
            'float_days' => ['type' => 'string'],
            'is_critical' => ['type' => 'boolean'],
            'cost' => ['type' => 'number'],
            'trade' => ['type' => 'string'],
            'crew' => ['type' => 'string'],
        ], ['id', 'name_ko', 'name_en', 'dur', 'preds', 'es', 'ef', 'ls', 'lf', 'float_days', 'is_critical', 'cost', 'trade', 'crew']);

        $milestone = $obj([
            'name' => ['type' => 'string'],
            'date' => ['type' => 'string'],
        ], ['name', 'date']);

        $subTask = $obj([
            'sub_no' => ['type' => 'string'],
            'sub_name' => ['type' => 'string'],
            'trade' => ['type' => 'string'],
            'manhours' => ['type' => 'integer'],
            'days' => ['type' => 'integer'],
            'ehs' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
        ], ['sub_no', 'sub_name', 'trade', 'manhours', 'days', 'ehs']);

        $task = $obj([
            'task_no' => ['type' => 'string'],
            'task_name' => ['type' => 'string'],
            'sub_tasks' => ['type' => 'array', 'items' => $subTask],
        ], ['task_no', 'task_name', 'sub_tasks']);

        $stage = $obj([
            'stage_no' => ['type' => 'string'],
            'stage_name' => ['type' => 'string'],
            'tasks' => ['type' => 'array', 'items' => $task],
        ], ['stage_no', 'stage_name', 'tasks']);

        return $obj([
            'activities' => ['type' => 'array', 'items' => $activity],
            'milestones' => ['type' => 'array', 'items' => $milestone],
            'stages' => ['type' => 'array', 'items' => $stage],
        ], ['activities', 'milestones', 'stages']);
    }
}

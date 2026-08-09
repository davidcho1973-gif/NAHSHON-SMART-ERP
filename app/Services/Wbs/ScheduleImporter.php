<?php

namespace App\Services\Wbs;

use App\Models\Project;
use App\Models\Site;
use App\Models\WbsItem;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * 외부 CPM 공정표(엑셀) → 공정관리(WBS) 트리.
 *
 * 현장 공정표는 계층 번호(1.1.1)가 없는 "평평한 액티비티 목록"(A010…A750)으로 오는 것이 보통이다.
 * 우리 트리는 Stage → Task → SubTask 3단계이므로 계층을 만들어내야 하는데, 그 기준을
 * **마일스톤(검사 관문)** 으로 잡는다:
 *
 *   Stage   = 마일스톤 구간 (인허가 → 슬래브 폐합 → 벽체 폐합 → 천장 폐합 → 준공 → 사용승인)
 *   Task    = 구간 안의 공종 (GC / ELEC / PLUMB …)
 *   SubTask = 액티비티 (A010 …)
 *
 * 공종이 아니라 마일스톤을 Stage 로 두는 이유: 공종으로 묶으면 시간 순서가 깨진다(전기는 8월에도
 * 1월에도 있다). 마일스톤은 시간 순서이면서 동시에 현장의 검사 관문이라 감각과 맞는다.
 *
 * 선행관계·여유·임계경로·투입조는 그대로 보존한다 — 이 정보가 공정표 가치의 절반이다.
 */
class ScheduleImporter
{
    private const MILESTONE_MARKER = '마일스톤';

    /** 헤더명 → 내부 키. 값은 후보 목록(현장마다 표기가 조금씩 다름). */
    private const COLUMNS = [
        'id' => ['ID', 'Activity ID', '액티비티'],
        'name_ko' => ['작업명', '작업 명', 'Name (KO)'],
        'name_en' => ['Activity', 'Activity Name', '영문작업명'],
        // 현장 간트는 공기 칸에 발주 상태를 같이 적기도 한다("공기/발주").
        'dur' => ['공기(일)', '공기', 'Duration', '공기/발주', '공기(d)', '기간'],
        'preds' => ['선행작업', '선행', 'Predecessors'],
        'es' => ['ES일자', 'ES', 'Early Start', '시작', '시작일'],
        'ef' => ['EF일자', 'EF', 'Early Finish', '종료', '종료일'],
        'ls' => ['LS일자', 'LS', 'Late Start'],
        'lf' => ['LF일자', 'LF', 'Late Finish'],
        'float' => ['여유', 'Float', 'TF'],
        'cp' => ['CP', 'Critical'],
        'cost' => ['배분원가', '원가', 'Cost'],
        'trade' => ['공종', 'Trade'],
        'crew' => ['투입조', 'Crew'],
    ];

    public function __construct(private readonly CrewParser $crewParser) {}

    /**
     * 저장하지 않고 읽기만 한다 — 갈아끼우기 전에 "제대로 읽히는지" 부터 보기 위해서.
     *
     * 공정표 교체는 되돌리기 어렵다(기존 트리와 딸린 안전카드가 지워진다). 그런데 헤더 이름이
     * 하나만 달라도 액티비티가 0개로 읽힌다. 그 상태로 교체하면 공정표가 통째로 날아간다.
     * 그래서 항상 이걸 먼저 보고, 숫자가 납득되면 그때 교체한다.
     *
     * @return array{activities: int, milestones: int, warnings: array<int, string>,
     *               sample: array<int, array{id: string, name: string, trade: ?string, start: ?string, end: ?string}>,
     *               milestoneNames: array<int, string>}
     */
    public function preview(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("공정표 파일을 찾을 수 없습니다: {$path}");
        }

        [$activities, $milestones, $warnings, $sections] = $this->readXlsx($path);

        return [
            'activities' => count($activities),
            'milestones' => count($milestones),
            'warnings' => $warnings,
            // 앞의 몇 줄을 그대로 보여 준다. 숫자만 맞고 내용이 엉뚱한 경우를 눈으로 잡으려면
            // 실제로 읽힌 행을 봐야 한다.
            // array_values 가 필요하다 — $activities 는 액티비티 ID 를 키로 갖는 맵이라
            // array_slice 가 그 키를 유지하고, JSON 으로 나가면 배열이 아니라 객체가 된다.
            // 화면은 배열로 받아 .map 을 돌리므로 객체가 오면 그 자리에서 멈춘다.
            'sample' => array_values(array_map(fn (array $a): array => [
                'id' => (string) ($a['activity_id'] ?? ''),
                'name' => (string) ($a['name'] ?? ''),
                'trade' => isset($a['trade']) ? (string) $a['trade'] : null,
                'start' => isset($a['es']) ? (string) $a['es'] : null,
                'end' => isset($a['ef']) ? (string) $a['ef'] : null,
                'section' => $a['section'] ?? null,
            ], array_slice($activities, 0, 8))),
            'milestoneNames' => array_values(array_filter(array_map(
                fn (array $m): string => (string) ($m['name'] ?? ''),
                $milestones,
            ))),
            // 시트가 스스로 나눈 구간. 이것이 곧 공정 단계가 된다.
            'sections' => $sections,
            // 어느 작업이 들어오는지 — 무엇이 유지되고 무엇이 사라지는지 계산하는 데 쓴다.
            'activityIds' => array_keys($activities),
        ];
    }

    /**
     * @return array{stages: int, tasks: int, subtasks: int, milestones: int, activities: int, warnings: array<int, string>}
     */
    public function importFromXlsx(string $path, string $projectCode, string $siteId = 'ALL'): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("공정표 파일을 찾을 수 없습니다: {$path}");
        }

        [$activities, $milestones, $warnings, $sections] = $this->readXlsx($path);

        if ($activities === []) {
            throw new RuntimeException('공정표에서 액티비티를 하나도 읽지 못했습니다. 시트/헤더 이름을 확인하세요.');
        }

        return $this->persist($projectCode, $siteId, $activities, $milestones, $warnings, $sections);
    }

    /**
     * AI(문서 추출) 경로용: 이미 파싱된 액티비티 배열을 받아 XLSX 와 **동일한** 영속화 로직을 재사용한다.
     *
     * AI 가 CPM/공정표 PDF 에서 뽑아낸 각 행을 내부 액티비티 형태로 정규화(투입조는 CrewParser 로,
     * 날짜/여유는 XLSX 리더와 같은 함수로)한 뒤 persist() 로 넘긴다 — 그래서 PDF 든 엑셀이든
     * 결과 트리(마일스톤 → 공종 → 액티비티)와 보존 규칙(협력사 배정 유지)이 정확히 같다.
     *
     * @param  array<int, array<string, mixed>>  $rawActivities  각 항목 키: id, name_ko, name_en, dur,
     *                                                           preds(string[]|string), es, ef, ls, lf, float_days, is_critical(bool), cost, trade, crew(string)
     * @param  array<int, array{name?: string, date?: string}>  $rawMilestones
     * @param  array<int, string>  $warnings
     * @return array{stages: int, tasks: int, subtasks: int, milestones: int, activities: int, warnings: array<int, string>}
     */
    public function persistExtracted(
        string $projectCode,
        string $siteId,
        array $rawActivities,
        array $rawMilestones = [],
        array $warnings = []
    ): array {
        $activities = [];
        foreach ($rawActivities as $r) {
            if (! is_array($r)) {
                continue;
            }
            $id = trim((string) ($r['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $predsRaw = $r['preds'] ?? '';
            $preds = is_array($predsRaw)
                ? array_values(array_filter(array_map(fn ($p) => trim((string) $p), $predsRaw)))
                : $this->splitPreds($predsRaw);

            $float = $r['float_days'] ?? null;
            $activities[$id] = [
                'activity_id' => $id,
                'name' => trim((string) ($r['name_ko'] ?? '')) ?: trim((string) ($r['name_en'] ?? '')) ?: $id,
                'name_en' => trim((string) ($r['name_en'] ?? '')),
                'dur' => (int) $this->numeric($r['dur'] ?? 0),
                'preds' => $preds,
                'es' => $this->toDate($r['es'] ?? null),
                'ef' => $this->toDate($r['ef'] ?? null),
                'ls' => $this->toDate($r['ls'] ?? null),
                'lf' => $this->toDate($r['lf'] ?? null),
                'float' => ($float === null || $float === '') ? null : (int) $this->numeric($float),
                'cp' => filter_var($r['is_critical'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'cost' => $this->numeric($r['cost'] ?? 0),
                'trade' => trim((string) ($r['trade'] ?? '')) ?: '미지정',
                'crew' => $this->crewParser->parse((string) ($r['crew'] ?? '')),
            ];
        }

        if ($activities === []) {
            throw new RuntimeException('추출된 액티비티가 없습니다. 문서가 공정표(액티비티 목록)인지 확인하세요.');
        }

        $milestones = [];
        foreach ($rawMilestones as $m) {
            if (! is_array($m)) {
                continue;
            }
            $name = trim((string) ($m['name'] ?? ''));
            $date = $this->toDate($m['date'] ?? null);
            if ($name !== '' && $date !== null) {
                $milestones[] = ['name' => $name, 'date' => $date];
            }
        }
        usort($milestones, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $this->persist($projectCode, $siteId, $activities, $milestones, $warnings);
    }

    /**
     * 구간 제목 행인가 — 첫 칸에만 글자가 있고 나머지 주요 칸이 비었으면 제목이다.
     *
     * "천장배관 / MEP Overhead" 처럼 작업을 묶는 줄이다. 작업 행과 구별하는 기준은
     * "작업명·공기·날짜가 비었는가" 로 잡는다. ID 칸에 글자가 있다는 것만으로는
     * 부족하다 — 작업 행도 ID 칸이 차 있기 때문이다.
     *
     * @param  array<int, mixed>  $cells
     * @param  array<string, int>  $map
     */
    private function isSectionTitle(array $cells, array $map): bool
    {
        $at = fn (string $k) => isset($map[$k]) ? trim((string) ($cells[$map[$k]] ?? '')) : '';

        if ($at('id') === '') {
            return false;
        }

        return $at('name_ko') === '' && $at('dur') === '' && $at('es') === '' && $at('ef') === '';
    }

    /**
     * 공기 칸을 일수로 읽는다 — "17d" 는 17, "3" 은 3.
     *
     * 같은 칸에 발주 상태를 적는 현장이 있다("07-30발주", "미발주", "검토중").
     * 그런 값에서 숫자만 긁으면 07-30 이 730 일이 되어 버린다. 숫자로 읽히는
     * 모양이 아니면 공기가 없는 것으로 본다 — 날짜 칸이 따로 있으니 손실이 아니다.
     */
    private function durationDays(mixed $v): int
    {
        if (is_int($v) || is_float($v)) {
            return (int) $v;
        }

        $s = trim((string) $v);

        return preg_match('/^(\d+(?:\.\d+)?)\s*(?:d|일)?$/iu', $s, $m) ? (int) $m[1] : 0;
    }

    /**
     * 구간(시트가 나눈 제목)을 Stage 로 삼아 트리를 만든다.
     *
     * @param  array<int, array<string, mixed>>  $activities
     * @param  array<int, array<string, mixed>>  $milestones
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $sections
     * @return array{stages: int, tasks: int, subtasks: int, milestones: int, activities: int, warnings: array<int, string>}
     */
    private function persistBySection(
        string $projectCode,
        string $siteId,
        array $activities,
        array $milestones,
        array $warnings,
        array $sections
    ): array {
        $siteRowId = $siteId !== 'ALL' ? Site::query()->where('code', $siteId)->value('id') : null;
        $projectRowId = Project::query()->where('project_code', $projectCode)->value('id');

        // 구간이 안 붙은 액티비티(첫 구간 제목보다 위에 있던 행)도 버리지 않는다.
        $orphans = array_filter($activities, fn (array $a) => ($a['section'] ?? null) === null);
        if ($orphans !== []) {
            $sections = array_merge(['구간 미지정'], $sections);
            $warnings[] = count($orphans).'건이 구간 제목 위에 있어 "구간 미지정" 으로 담았습니다.';
        }

        $counts = ['stages' => 0, 'tasks' => 0, 'subtasks' => 0];

        DB::transaction(function () use (
            $projectCode, $siteRowId, $projectRowId, $activities, $sections, &$counts
        ): void {
            // 재수입 시 현장 진행 상태를 activity_id 로 보존한다.
            $prior = WbsItem::query()
                ->where('project_code', $projectCode)
                ->where('level', WbsItem::LEVEL_SUBTASK)
                ->get()
                ->keyBy(fn (WbsItem $i) => (string) ($i->activity_id ?: $i->node_no));

            WbsItem::query()->where('project_code', $projectCode)->delete();

            $base = [
                'project_id' => $projectRowId,
                'project_code' => $projectCode,
                'site_id' => $siteRowId,
                'source' => 'import',
            ];

            foreach (array_values($sections) as $sIdx => $sectionName) {
                $inStage = array_filter($activities, function (array $a) use ($sectionName): bool {
                    $s = $a['section'] ?? null;

                    return $sectionName === '구간 미지정' ? $s === null : $s === $sectionName;
                });
                if ($inStage === []) {
                    continue;
                }

                $stageNo = (string) ($sIdx + 1);
                $ends = array_filter(array_map(fn (array $a) => $a['ef'] ?? null, $inStage));
                $stage = WbsItem::query()->create($base + [
                    'parent_id' => null,
                    'level' => WbsItem::LEVEL_STAGE,
                    'wbs_code' => "{$projectCode}-S-{$stageNo}",
                    'node_no' => $stageNo,
                    'name' => $sectionName,
                    'planned_end' => $ends ? max($ends) : null,
                    'sort_order' => $sIdx,
                ]);
                $counts['stages']++;

                // Task = 공종. 구간 안에서 가장 이른 시작 순으로 둬 시간 흐름을 유지한다.
                $trades = [];
                foreach ($inStage as $a) {
                    $trades[$a['trade']][] = $a;
                }
                uasort($trades, function (array $x, array $y): int {
                    $ex = min(array_map(fn (array $a) => $a['es'] ?? '9999', $x));
                    $ey = min(array_map(fn (array $a) => $a['es'] ?? '9999', $y));

                    return strcmp((string) $ex, (string) $ey);
                });

                $tIdx = 0;
                foreach ($trades as $trade => $acts) {
                    $tIdx++;
                    $taskNo = "{$stageNo}.{$tIdx}";
                    $task = WbsItem::query()->create($base + [
                        'parent_id' => $stage->id,
                        'level' => WbsItem::LEVEL_TASK,
                        'wbs_code' => "{$projectCode}-T-{$stageNo}-{$tIdx}",
                        'node_no' => $taskNo,
                        'name' => (string) $trade,
                        'trade' => (string) $trade,
                        'sort_order' => $tIdx,
                    ]);
                    $counts['tasks']++;

                    usort($acts, fn (array $a, array $b) => strcmp((string) ($a['es'] ?? '9999'), (string) ($b['es'] ?? '9999')));

                    foreach (array_values($acts) as $aIdx => $a) {
                        $keep = $prior->get((string) $a['activity_id']);
                        WbsItem::query()->create($base + [
                            'parent_id' => $task->id,
                            'level' => WbsItem::LEVEL_SUBTASK,
                            'wbs_code' => "{$projectCode}-W-".$a['activity_id'],
                            'node_no' => $taskNo.'.'.($aIdx + 1),
                            'activity_id' => $a['activity_id'],
                            'name' => $a['name'],
                            'trade' => (string) $trade,
                            'days' => $a['dur'] ?: null,
                            'planned_start' => $a['es'],
                            'planned_end' => $a['ef'],
                            'sort_order' => $aIdx + 1,
                            // 재수입해도 현장이 올린 진행 상태와 협력사 배정은 지키다.
                            'status' => $keep->status ?? '검수완료',
                            'progress' => $keep->progress ?? 0,
                            'company' => $keep->company ?? null,
                            'company_id' => $keep->company_id ?? null,
                            'manhours' => $keep->manhours ?? null,
                            'ehs' => $keep->ehs ?? null,
                            'payload' => [
                                'preds' => $a['preds'],
                                'float' => $a['float'],
                                'critical' => $a['cp'],
                                'name_en' => $a['name_en'],
                                'section' => $a['section'] ?? null,
                            ],
                        ]);
                        $counts['subtasks']++;
                    }
                }
            }
        });

        return $counts + [
            'milestones' => count($milestones),
            'activities' => count($activities),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, string>}
     */
    private function readXlsx(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);

        $activities = [];
        $milestones = [];
        $warnings = [];
        $sections = [];
        $found = false;

        // 시트명이 아니라 "헤더가 공정표처럼 생겼는가"로 찾는다 — 현장마다 시트명이 제각각이라
        // 이름에 의존하면 다른 파일을 못 받는다. 헤더 조건(ID+작업명+공기)은 견적/물량 시트와 겹치지 않는다.
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($found) {
                break;
            }

            $map = null;
            $inMilestones = false;
            $currentSection = null;

            foreach ($sheet->getRowIterator() as $rowNo => $row) {
                $cells = array_map(fn ($c) => $this->cellValue($c->getValue()), $row->getCells());
                $joined = trim(implode('', array_map(fn ($v) => (string) $v, $cells)));

                if ($joined === '') {
                    continue;
                }

                if ($map === null) {
                    $map = $this->headerMap($cells);
                    if ($map === null) {
                        continue; // 제목/설명 행, 또는 공정표가 아닌 시트
                    }
                    $found = true;

                    continue;
                }

                // 구간 제목 행 — 첫 칸에만 글자가 있고 나머지가 빈 줄.
                // 현장 간트는 "천장배관 / MEP Overhead" 처럼 구간을 제목 행으로 나눈다.
                // 이 구분이 곧 공정 단계이므로, 있으면 이것을 Stage 로 삼는다.
                // 마일스톤보다 이쪽이 낫다 — 시트가 스스로 선언한 구조라 추측이 없다.
                if ($this->isSectionTitle($cells, $map)) {
                    $section = trim((string) ($cells[$map['id']] ?? ''));
                    // "◆ 마일스톤 / Milestones" 구간을 만나면 그 아래는 마일스톤 목록이다.
                    if (str_contains($section, self::MILESTONE_MARKER)) {
                        $inMilestones = true;
                        $currentSection = null;

                        continue;
                    }
                    $inMilestones = false;
                    $currentSection = $section;
                    $sections[] = $section;

                    continue;
                }

                if ($inMilestones) {
                    $name = trim((string) ($cells[1] ?? ''));
                    $date = $this->toDate($cells[5] ?? null);
                    if ($name !== '' && $date !== null) {
                        $milestones[] = ['name' => $name, 'date' => $date];
                    }

                    continue;
                }

                $act = $this->readActivity($cells, $map, $rowNo, $warnings);
                if ($act !== null) {
                    $act['section'] = $currentSection;
                    $activities[$act['activity_id']] = $act;
                }
            }
        }

        $reader->close();

        if (! $found) {
            throw new RuntimeException(
                '공정표 시트를 찾지 못했습니다. 최소한 "ID", "작업명", "공기(일)" 헤더가 있는 시트가 필요합니다.'
            );
        }

        usort($milestones, fn ($a, $b) => strcmp($a['date'], $b['date']));

        // 시트 하단의 개정 이력·주석도 "첫 칸만 채워진 줄" 이라 구간처럼 보인다.
        // 길이나 문구로 걸러내려 하면 현장마다 틀리므로, 사실로 판단한다 —
        // 아래에 작업이 하나도 안 붙은 구간은 구간이 아니다.
        $used = array_unique(array_filter(array_map(fn (array $a) => $a['section'] ?? null, $activities)));
        $sections = array_values(array_filter($sections, fn (string $x) => in_array($x, $used, true)));

        return [$activities, $milestones, $warnings, $sections];
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array<string, int>|null
     */
    private function headerMap(array $cells): ?array
    {
        $labels = array_map(fn ($v) => trim((string) $v), $cells);
        $map = [];

        foreach (self::COLUMNS as $key => $candidates) {
            foreach ($labels as $i => $label) {
                if ($label !== '' && in_array($label, $candidates, true)) {
                    $map[$key] = $i;
                    break;
                }
            }
        }

        // ID + 작업명 + 공기 가 있어야 공정표 헤더로 인정.
        return isset($map['id'], $map['name_ko'], $map['dur']) ? $map : null;
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, int>  $map
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>|null
     */
    private function readActivity(array $cells, array $map, int $rowNo, array &$warnings): ?array
    {
        $get = fn (string $k) => isset($map[$k]) ? ($cells[$map[$k]] ?? null) : null;

        $rawId = trim((string) $get('id'));
        $name = trim((string) $get('name_ko')) ?: trim((string) $get('name_en'));

        // ★ 는 주공정(CP) 표시다. ID 와 작업명 어느 쪽에 붙어 있어도 같은 뜻이므로
        // 떼어내고 플래그로 옮긴다 — 이름에 남겨 두면 정렬·검색이 어긋난다.
        $starred = str_contains($rawId, '★') || str_contains($name, '★');
        $id = trim(str_replace(['★', '◆'], '', $rawId));
        $name = trim(str_replace(['★', '◆'], '', $name));

        // 실적 반영으로 나중에 끼운 행은 ID 칸에 "추가" 라고만 적혀 온다.
        // 그대로 두면 여러 행이 같은 키가 되어 마지막 하나만 남는다. 작업명으로
        // 안정적인 키를 만들어 준다 — 다시 수입해도 같은 키라 진행 상태가 보존된다.
        if ($id === '' || in_array($id, ['추가', '신규', '-'], true)) {
            if ($name === '') {
                return null;
            }
            $id = '추가-'.substr(md5($name), 0, 5);
        }

        $ef = $this->toDate($get('ef'));
        if ($ef === null) {
            $warnings[] = "{$rowNo}행({$id}): 종료일이 없습니다.";
        }

        $crew = $this->crewParser->parse((string) $get('crew'));

        return [
            'activity_id' => $id,
            'name' => $name ?: $id,
            'name_en' => trim((string) $get('name_en')),
            'dur' => $this->durationDays($get('dur')),
            'preds' => $this->splitPreds($get('preds')),
            'es' => $this->toDate($get('es')),
            'ef' => $ef,
            'ls' => $this->toDate($get('ls')),
            'lf' => $this->toDate($get('lf')),
            'float' => $get('float') === null || $get('float') === '' ? null : (int) $this->numeric($get('float')),
            'cp' => $starred || trim((string) $get('cp')) !== '',
            'cost' => $this->numeric($get('cost')),
            'trade' => trim((string) $get('trade')) ?: '미지정',
            'crew' => $crew,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function splitPreds(mixed $raw): array
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,;]/', $s) ?: [])));
    }

    private function cellValue(mixed $v): mixed
    {
        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
    }

    private function toDate(mixed $v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s;
        }
        // 엑셀 시리얼 번호(날짜 서식이 없는 셀)도 받아준다.
        if (is_numeric($s) && (float) $s > 20000) {
            return \DateTimeImmutable::createFromFormat('Y-m-d', '1899-12-30')
                ->modify('+'.(int) $s.' days')->format('Y-m-d');
        }

        return null;
    }

    private function numeric(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[^0-9.\-]/', '', (string) $v);

        return $s === '' || $s === '-' ? 0.0 : (float) $s;
    }

    /**
     * 마일스톤 구간으로 Stage 를 나눈다: 액티비티는 자신의 EF 이상인 첫 마일스톤에 속한다.
     * 마지막 마일스톤보다 늦게 끝나는 작업(준공서류 등)은 마지막 Stage 로 흡수한다.
     *
     * @param  array<int, array<string, mixed>>  $activities
     * @param  array<int, array<string, mixed>>  $milestones
     * @return array<string, int>
     */
    private function assignStages(array $activities, array $milestones): array
    {
        $assign = [];
        $last = count($milestones) - 1;

        foreach ($activities as $id => $a) {
            $stage = $last;
            if ($a['ef'] !== null) {
                foreach ($milestones as $i => $m) {
                    if (strcmp($a['ef'], $m['date']) <= 0) {
                        $stage = $i;
                        break;
                    }
                }
            }
            $assign[$id] = max(0, $stage);
        }

        return $assign;
    }

    /**
     * @param  array<int, array<string, mixed>>  $activities
     * @param  array<int, array<string, mixed>>  $milestones
     * @param  array<int, string>  $warnings
     * @return array{stages: int, tasks: int, subtasks: int, milestones: int, activities: int, warnings: array<int, string>}
     */
    private function persist(string $projectCode, string $siteId, array $activities, array $milestones, array $warnings, array $sections = []): array
    {
        // 시트가 구간을 스스로 나눠 놓았으면 그것을 Stage 로 쓴다.
        // 마일스톤 날짜로 구간을 추정하는 방식은 구간이 시간상 겹칠 때 틀린다
        // (골조 07-24~08-14 와 천장배관 07-29~08-17 은 겹친다).
        // 시트에 적힌 구간은 추정이 아니라 사실이므로 그쪽이 항상 낫다.
        if ($sections !== []) {
            return $this->persistBySection($projectCode, $siteId, $activities, $milestones, $warnings, $sections);
        }

        if ($milestones === []) {
            $warnings[] = '마일스톤이 없어 전체를 단일 Stage 로 만듭니다.';
            $milestones = [['name' => $projectCode.' 전체 공정', 'date' => '9999-12-31']];
        }

        $stageOf = $this->assignStages($activities, $milestones);
        $siteRowId = $siteId !== 'ALL' ? Site::query()->where('code', $siteId)->value('id') : null;
        $projectRowId = Project::query()->where('project_code', $projectCode)->value('id');

        $counts = ['stages' => 0, 'tasks' => 0, 'subtasks' => 0];

        DB::transaction(function () use (
            $projectCode, $siteRowId, $projectRowId, $activities, $milestones, $stageOf, &$counts
        ): void {
            // 재수입 시 현장 진행 상태를 activity_id 로 보존 (node_no 가 아니라 액티비티 ID 가 안정적인 키).
            $prior = WbsItem::query()
                ->where('project_code', $projectCode)
                ->where('level', WbsItem::LEVEL_SUBTASK)
                ->get()
                ->keyBy(fn (WbsItem $i) => (string) ($i->activity_id ?: $i->node_no));

            WbsItem::query()->where('project_code', $projectCode)->delete();

            $base = [
                'project_id' => $projectRowId,
                'project_code' => $projectCode,
                'site_id' => $siteRowId,
                'source' => 'import',
            ];

            foreach ($milestones as $sIdx => $ms) {
                $inStage = array_filter($activities, fn ($a) => $stageOf[$a['activity_id']] === $sIdx);
                if ($inStage === []) {
                    continue;
                }

                $stageNo = (string) ($sIdx + 1);
                $stage = WbsItem::query()->create($base + [
                    'parent_id' => null,
                    'level' => WbsItem::LEVEL_STAGE,
                    'wbs_code' => "{$projectCode}-S-{$stageNo}",
                    'node_no' => $stageNo,
                    'name' => $ms['name'],
                    'planned_end' => $ms['date'] === '9999-12-31' ? null : $ms['date'],
                    'sort_order' => $sIdx,
                ]);
                $counts['stages']++;

                // Task = 공종. 구간 안에서 가장 이른 ES 순으로 배열해 시간 흐름을 유지한다.
                $trades = [];
                foreach ($inStage as $a) {
                    $trades[$a['trade']][] = $a;
                }
                uasort($trades, function ($x, $y) {
                    $ex = min(array_map(fn ($a) => $a['es'] ?? '9999', $x));
                    $ey = min(array_map(fn ($a) => $a['es'] ?? '9999', $y));

                    return strcmp((string) $ex, (string) $ey);
                });

                $tIdx = 0;
                foreach ($trades as $trade => $acts) {
                    $tIdx++;
                    $taskNo = "{$stageNo}.{$tIdx}";
                    $task = WbsItem::query()->create($base + [
                        'parent_id' => $stage->id,
                        'level' => WbsItem::LEVEL_TASK,
                        'wbs_code' => "{$projectCode}-T-{$taskNo}",
                        'node_no' => $taskNo,
                        'name' => $trade,
                        'trade' => $trade,
                        'sort_order' => $tIdx,
                    ]);
                    $counts['tasks']++;

                    usort($acts, fn ($x, $y) => strcmp((string) ($x['es'] ?? ''), (string) ($y['es'] ?? '')));

                    $wIdx = 0;
                    foreach ($acts as $a) {
                        $wIdx++;
                        $subNo = "{$taskNo}.{$wIdx}";
                        $keep = $prior->get($a['activity_id']);

                        WbsItem::query()->create($base + [
                            'parent_id' => $task->id,
                            'level' => WbsItem::LEVEL_SUBTASK,
                            'wbs_code' => "{$projectCode}-W-{$a['activity_id']}", // 액티비티 ID 로 고정 → 재수입해도 안 흔들림
                            'node_no' => $subNo,
                            'activity_id' => $a['activity_id'],
                            'name' => $a['name'],
                            // 공종은 trade 로, 협력사(company)는 사람이 배정 — 재수입 시 기존 배정 보존.
                            'trade' => $a['trade'],
                            'company' => $keep->company ?? null,
                            'days' => $a['dur'] ?: null,
                            'manhours' => $this->estimateManhours($a),
                            'planned_start' => $a['es'],
                            'planned_end' => $a['ef'],
                            'late_start' => $a['ls'],
                            'late_end' => $a['lf'],
                            'preds' => $a['preds'],
                            'float_days' => $a['float'],
                            'is_critical' => $a['cp'],
                            'planned_cost' => $a['cost'] ?: null,
                            'crew_text' => $a['crew']['raw'] !== '' ? $a['crew']['raw'] : null,
                            'crew_size' => $a['crew']['size'] ?: null,
                            'crew_roles' => $a['crew']['roles'] ?: null,
                            'equipment' => $a['crew']['equipment'] ?: null,
                            'ehs' => $this->inferEhs($a),
                            'status' => $keep->status ?? '검수완료',
                            'progress' => $keep->progress ?? 0,
                            'sort_order' => $wIdx,
                            'payload' => ['name_en' => $a['name_en']],
                        ]);
                        $counts['subtasks']++;
                    }
                }
            }
        });

        return $counts + [
            'milestones' => count($milestones),
            'activities' => count($activities),
            'warnings' => $warnings,
        ];
    }

    /**
     * 공수(MH) = 인원 × 공기 × 8h. 공정표가 공수를 직접 주지 않으므로 투입조에서 파생한다.
     * 진척률이 공수 가중 평균이라 이 값이 있어야 집계가 의미를 갖는다.
     *
     * @param  array<string, mixed>  $a
     */
    private function estimateManhours(array $a): ?float
    {
        $size = (float) $a['crew']['size'];
        $dur = (int) $a['dur'];

        if ($size <= 0 || $dur <= 0) {
            return null;
        }

        return round($size * $dur * 8, 1);
    }

    /**
     * EHS 등급 추정 — 고소작업(리프트)·중장비·철거는 고위험으로 본다.
     * 어디까지나 초기값이고, 현장에서 안전계획을 세울 때 사람이 확정한다.
     *
     * @param  array<string, mixed>  $a
     */
    private function inferEhs(array $a): ?string
    {
        $equipment = $a['crew']['equipment'];
        $text = mb_strtolower($a['name'].' '.$a['name_en'].' '.$a['crew']['raw']);

        if ($equipment !== [] || str_contains($text, '고소') || str_contains($text, 'lift')) {
            return 'high';
        }
        if (in_array($a['trade'], ['DEMO', 'FRAME'], true) || str_contains($text, '철거') || str_contains($text, 'operator')) {
            return 'high';
        }
        if (in_array($a['trade'], ['ELEC', 'FIRE', 'MECH', 'PLUMB'], true)) {
            return 'medium';
        }

        return 'low';
    }
}

<?php

namespace App\Services\Finance;

use App\Models\ContractBoqLine;
use App\Models\IntelligentDocument;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\WorkSection;
use App\Services\Admin\BillingAdminService;
use App\Services\Admin\ContractAdminService;
use App\Support\AiInformationAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 원청 계약 기성표(continuation sheet)를 기성 근거 대장의 계약 행으로 올린다.
 *
 * 사장 지시(2026-09-25): «계약서의 줄이 곧 일의 단위다. 기성관리가 공정관리다.»
 * 그래서 계약서의 줄 하나가 contract_boq_lines 한 줄이 되고, 줄은 자기 섹션
 * (work_sections — 공정별 도면 화면의 공정)에 매달린다. 공정을 세는 표를 따로 만들지 않는다.
 *
 * ── 계약서를 읽는 방식 ────────────────────────────────────────────────
 * 섹션의 경계를 추측하지 않는다. 계약서 맨 위 요약표가 섹션마다 «제목 칸(=C44)» 과
 * «소계 칸(=K49)» 을 수식으로 가리키고 있다 — 원청이 직접 적은 구조다. 그 두 줄 사이의
 * 단가 있는 줄이 그 섹션의 계약 행이다. 섹션마다 줄 금액을 다시 더해 계약서의 소계와
 * 맞지 않으면 올리지 않는다 — 잘못 읽은 계약서로 돈을 청구할 수는 없다.
 *
 * ── 반입 자재 기성 ────────────────────────────────────────────────────
 * 원청이 설치 전 반입 자재도 기성으로 인정한다(사장 확인 2026-09-25). 자재 단가(G)와
 * 노무·경비 단가(H·I)가 둘 다 있는 줄은 «보관 자재 G/J%, 설치 나머지%» 의 단계별 인정으로,
 * 한쪽만 있는 줄은 수량 기준으로 올린다. 비율은 계약서의 단가에서 나오므로 사람이
 * 따로 정할 것이 없다.
 *
 * 계약의 줄은 RFI 로만 바뀐다. 다시 올린 계약서의 같은 번호 줄이 수량·단가가 다르면
 * 덮지 않고 멈춘다.
 */
class ContractSheetImportService
{
    public const SOURCE_PREFIX = 'contract-sheet:';

    public function __construct(
        private readonly BillingAdminService $billing,
        private readonly ClaimEvidenceService $evidence,
    ) {}

    /**
     * 올리기 전에 보여줄 것 — 무엇을 읽었는지, 어느 계약·공정에 붙을지.
     *
     * @return array<string, mixed>
     */
    public function preview(int $documentId): array
    {
        return $this->respond(function () use ($documentId): array {
            [$doc, $site] = $this->document($documentId);
            $sheet = $this->parseDocument($doc);
            $sections = WorkSection::query()->where('site_id', $site->id)->orderBy('sort_order')->get();
            $matched = $this->matchSections($sheet['sections'], $sections);
            $contracts = $this->contracts($site);
            $suggested = collect($contracts)->filter(fn (array $c): bool => $c['amount'] !== null && abs($c['amount'] - $sheet['contractTotal']) < 0.01);

            return [
                'success' => true,
                'documentId' => $doc->id,
                'fileName' => $doc->original_file_name,
                'site' => ['id' => $site->id, 'label' => trim($site->code.' — '.$site->name, ' —')],
                'sheetName' => $sheet['sheetName'],
                'project' => $sheet['project'],
                'scope' => $sheet['scope'],
                'contractTotal' => $sheet['contractTotal'],
                'lineTotal' => $sheet['lineTotal'],
                'roundOff' => round($sheet['contractTotal'] - $sheet['lineTotal'], 2),
                'lineCount' => array_sum(array_map(fn (array $s): int => count($s['lines']), $sheet['sections'])),
                'storedLineCount' => array_sum(array_map(fn (array $s): int => count(array_filter($s['lines'], fn (array $l): bool => $this->splitsMaterial($l))), $sheet['sections'])),
                'sections' => array_map(fn (array $s, ?WorkSection $m): array => [
                    'division' => $s['division'], 'name' => $s['name'], 'lines' => count($s['lines']), 'amount' => $s['subtotal'],
                    'matches' => $m ? ['id' => $m->id, 'code' => $m->code, 'name' => $m->name] : null,
                ], $sheet['sections'], $matched),
                'contracts' => $contracts,
                'suggestedContractId' => $suggested->count() === 1 ? $suggested->first()['id'] : null,
                'newContractTitle' => $this->newContractTitle($sheet, $site),
                'canManage' => $this->billing->canManage(),
            ];
        });
    }

    /**
     * 계약 행을 올린다. contractId 가 0 이고 createContract 면 계약서의 금액으로 수주 계약을 만든다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function import(array $input): array
    {
        return $this->respond(function () use ($input): array {
            if (! $this->billing->canManage()) {
                throw new InvalidArgumentException('기성 근거 관리 권한이 없습니다.');
            }
            if (empty($input['confirmContract'])) {
                throw new InvalidArgumentException('이 파일이 원청과 맺은 계약 내역인지 확인해 주세요.');
            }
            [$doc, $site] = $this->document((int) ($input['documentId'] ?? 0));
            $sheet = $this->parseDocument($doc);

            return DB::transaction(function () use ($input, $doc, $site, $sheet): array {
                $contract = $this->resolveContract($input, $site, $sheet);
                if (abs((float) $contract->current_amount - $sheet['contractTotal']) >= 0.01) {
                    throw new InvalidArgumentException('선택한 계약의 금액('.number_format((float) $contract->current_amount, 2).')이 계약서 합계('.number_format($sheet['contractTotal'], 2).')와 다릅니다. 계약을 확인하세요.');
                }
                if ($doc->project_contract_id && (int) $doc->project_contract_id !== $contract->id) {
                    throw new InvalidArgumentException('이 파일은 이미 다른 계약에 연결된 문서입니다.');
                }
                // 이 파일은 이 계약의 계약 내역이다 — 계약 행의 원문 근거로 쓰이므로 계약에 잇는다.
                $doc->forceFill(['project_contract_id' => $contract->id])->save();
                ProjectContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();

                $existingSections = WorkSection::query()->where('site_id', $site->id)->orderBy('sort_order')->get();
                $matched = $this->matchSections($sheet['sections'], $existingSections);
                $created = 0;
                $kept = 0;
                $conflicts = [];
                foreach ($sheet['sections'] as $i => $parsed) {
                    $section = $this->saveSection($site, $contract, $parsed, $matched[$i], $i, $sheet['sheetName']);
                    foreach ($parsed['lines'] as $line) {
                        $result = $this->saveLine($contract, $doc, $section, $line, $sheet['sheetName']);
                        match ($result) {
                            'created' => $created++,
                            'kept' => $kept++,
                            default => $conflicts[] = $result,
                        };
                    }
                }
                if ($conflicts !== []) {
                    throw new InvalidArgumentException('이미 올라온 계약 줄과 수량·단가가 다른 줄이 있습니다. 계약 변경은 RFI 로 처리하세요 — '.implode(' / ', array_slice($conflicts, 0, 5)).(count($conflicts) > 5 ? ' 외 '.(count($conflicts) - 5).'줄' : ''));
                }

                $payload = $contract->payload ?? [];
                $payload['contractSheetImports'][$doc->sha256 ?: 'doc-'.$doc->id] = [
                    'documentId' => $doc->id, 'sheetName' => $sheet['sheetName'],
                    'contractTotal' => $sheet['contractTotal'], 'lineTotal' => $sheet['lineTotal'],
                    'roundOff' => round($sheet['contractTotal'] - $sheet['lineTotal'], 2),
                    'lineCount' => $created + $kept, 'sectionCount' => count($sheet['sections']),
                    'importedBy' => auth()->id(), 'importedAt' => now()->toIso8601String(),
                ];
                $contract->forceFill(['payload' => $payload])->save();

                return [
                    'success' => true, 'contractId' => $contract->id, 'created' => $created, 'kept' => $kept,
                    'sections' => count($sheet['sections']),
                    'message' => '계약 줄 '.($created + $kept).'개를 공정 '.count($sheet['sections']).'개 아래에 올렸습니다.',
                ];
            });
        });
    }

    // ── 계약서 읽기 ───────────────────────────────────────────────────

    /**
     * @return array{sheetName: string, project: ?string, scope: ?string, contractTotal: float, lineTotal: float,
     *               sections: array<int, array{division: ?string, name: string, headerRow: int, subtotalRow: int, subtotal: float, lines: array<int, array<string, mixed>>}>}
     */
    public function parse(string $path): array
    {
        try {
            $book = IOFactory::load($path);
        } catch (\Throwable) {
            throw new InvalidArgumentException('엑셀 파일을 열 수 없습니다.');
        }

        foreach ($book->getWorksheetIterator() as $ws) {
            $cols = $this->columns($ws);
            if ($cols !== null) {
                $parsed = $this->parseSheet($ws, $cols);
                [$project, $scope] = $this->projectInfo($book);

                return ['sheetName' => $ws->getTitle(), 'project' => $project, 'scope' => $scope] + $parsed;
            }
        }

        throw new InvalidArgumentException('계약 내역(continuation sheet) 표를 찾지 못했습니다. «DESCRIPTION OF WORK» 제목 줄이 있는 시트가 필요합니다.');
    }

    /**
     * 제목 줄에서 칸 위치를 찾는다 — 원청마다 칸이 한두 개씩 밀려 있어도 읽히게.
     *
     * @return array<string, string>|null
     */
    private function columns(Worksheet $ws): ?array
    {
        $maxCol = min(Coordinate::columnIndexFromString($ws->getHighestColumn()), 40);
        for ($row = 1; $row <= min(40, $ws->getHighestRow()); $row++) {
            $found = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $text = strtolower(trim((string) $ws->getCell([$c, $row])->getValue()));
                if (str_contains($text, 'description of work')) {
                    $found['description'] = $c;
                }
            }
            if (! isset($found['description'])) {
                continue;
            }
            // 이름 칸 앞이 번호, 제목 줄과 그 아래 세 줄에서 나머지 칸을 찾는다.
            $map = ['item' => $found['description'] - 1, 'description' => $found['description']];
            for ($r = $row; $r <= $row + 3; $r++) {
                for ($c = $found['description'] + 1; $c <= $maxCol; $c++) {
                    $text = strtolower(trim(preg_replace('/\s+/', ' ', (string) $ws->getCell([$c, $r])->getValue())));
                    $key = match (true) {
                        $text === '' => null,
                        str_starts_with($text, 'spec') => 'spec',
                        $text === 'unit' => 'unit',
                        $text === 'quantity' => 'qty',
                        $text === 'material' => 'material',
                        $text === 'labor' || $text === 'labour' => 'labor',
                        $text === 'expense' || $text === 'expenses' => 'expense',
                        $text === 'unit price' => 'price',
                        $text === 'amount' => 'amount',
                        default => null,
                    };
                    if ($key !== null && ! isset($map[$key])) {
                        $map[$key] = $c;
                    }
                }
            }
            foreach (['unit', 'qty', 'price', 'amount'] as $need) {
                if (! isset($map[$need])) {
                    return null;
                }
            }
            // 경비 칸은 이름 없이 노무와 단가 사이에 비어 있기도 하다(원본 I열).
            if (! isset($map['expense']) && isset($map['labor']) && $map['price'] - $map['labor'] === 2) {
                $map['expense'] = $map['labor'] + 1;
            }
            $map['headerRow'] = $row;

            return array_map(fn ($v) => $v, $map);
        }

        return null;
    }

    /**
     * @param  array<string, int>  $cols
     * @return array{contractTotal: float, lineTotal: float, sections: array<int, array<string, mixed>>}
     */
    private function parseSheet(Worksheet $ws, array $cols): array
    {
        $last = $ws->getHighestRow();
        $sections = [];
        $division = null;
        $contractTotal = null;
        $firstDetail = $last + 1;

        // 요약표: 섹션 줄은 제목 칸이 «=C44», 금액 칸이 «=K49» 처럼 본문을 가리킨다.
        for ($row = $cols['headerRow'] + 1; $row <= $last && $row < $firstDetail; $row++) {
            $desc = $ws->getCell([$cols['description'], $row])->getValue();
            $amount = $ws->getCell([$cols['amount'], $row])->getValue();
            $descRef = $this->ref($desc);
            $amountRef = $this->ref($amount);
            if ($descRef !== null && $amountRef !== null) {
                $headerRow = $descRef;
                $firstDetail = min($firstDetail, $headerRow);
                $sections[] = ['division' => $division, 'name' => trim((string) $ws->getCell([$cols['description'], $headerRow])->getCalculatedValue()),
                    'headerRow' => $headerRow, 'subtotalRow' => $amountRef, 'lines' => []];

                continue;
            }
            $text = trim((string) $desc);
            if (is_string($desc) && $text !== '' && ! str_starts_with($text, '=')) {
                if (preg_match('/^total$/i', $text)) {
                    $contractTotal = (float) $ws->getCell([$cols['amount'], $row])->getCalculatedValue();
                } elseif (! preg_match('/round/i', $text)) {
                    $division = $text;
                }
            }
        }
        if ($sections === [] || $contractTotal === null) {
            throw new InvalidArgumentException('계약서 맨 위 요약표(섹션 목록과 TOTAL)를 읽지 못했습니다.');
        }

        $lineTotal = 0.0;
        foreach ($sections as &$section) {
            $group = null;
            $sum = 0.0;
            for ($row = $section['headerRow'] + 1; $row < $section['subtotalRow']; $row++) {
                $desc = trim((string) $ws->getCell([$cols['description'], $row])->getCalculatedValue());
                $unit = trim((string) $ws->getCell([$cols['unit'], $row])->getCalculatedValue());
                $qty = $ws->getCell([$cols['qty'], $row])->getCalculatedValue();
                $parts = [];
                foreach (['material', 'labor', 'expense'] as $k) {
                    $v = isset($cols[$k]) ? $ws->getCell([$cols[$k], $row])->getCalculatedValue() : null;
                    $parts[$k] = is_numeric($v) ? round((float) $v, 4) : null;
                }
                $priced = array_filter($parts, fn ($v) => $v !== null) !== [];
                if ($unit === '' || ! is_numeric($qty) || ! $priced) {
                    if ($desc !== '' && $unit === '') {
                        $group = mb_substr($desc, 0, 255);   // 섹션 안의 작은 제목
                    }

                    continue;
                }
                if ((float) $qty <= 0) {
                    throw new InvalidArgumentException($ws->getTitle().' '.$row.'행: 수량이 0 이하입니다.');
                }
                $price = round(array_sum(array_map(fn ($v) => (float) $v, $parts)), 4);
                $item = $ws->getCell([$cols['item'], $row])->getCalculatedValue();
                $sum += (float) $qty * $price;
                $section['lines'][] = [
                    'row' => $row,
                    'itemNo' => is_numeric($item) && floor((float) $item) == (float) $item ? (string) (int) $item : 'R'.$row,
                    'group' => $group,
                    'description' => mb_substr($desc !== '' ? $desc : '(이름 없음)', 0, 10000),
                    'spec' => isset($cols['spec']) ? (trim((string) $ws->getCell([$cols['spec'], $row])->getCalculatedValue()) ?: null) : null,
                    'unit' => mb_substr($unit, 0, 20),
                    'qty' => round((float) $qty, 4),
                    'material' => $parts['material'] ?? null, 'labor' => $parts['labor'] ?? null, 'expense' => $parts['expense'] ?? null,
                    'price' => $price,
                ];
            }
            $section['subtotal'] = round((float) $ws->getCell([$cols['amount'], $section['subtotalRow']])->getCalculatedValue(), 2);
            if (abs($sum - $section['subtotal']) >= 0.01) {
                throw new InvalidArgumentException('「'.$section['name'].'」 줄 금액의 합('.number_format($sum, 2).')이 계약서 소계('.number_format($section['subtotal'], 2).')와 다릅니다. 계약서를 확인하세요.');
            }
            $lineTotal += $section['subtotal'];
        }
        unset($section);

        return ['contractTotal' => round($contractTotal, 2), 'lineTotal' => round($lineTotal, 2), 'sections' => $sections];
    }

    /** «=C44» · «=$K$49» 처럼 한 칸만 가리키는 수식이면 그 행 번호. */
    private function ref(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^=\$?[A-Z]{1,3}\$?(\d+)$/D', trim($value), $m) ? (int) $m[1] : null;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function projectInfo(Spreadsheet $book): array
    {
        $found = ['project' => null, 'scope of works' => null];
        foreach ($book->getWorksheetIterator() as $ws) {
            for ($row = 1; $row <= min(30, $ws->getHighestRow()); $row++) {
                for ($c = 1; $c <= 8; $c++) {
                    $label = strtolower(trim((string) $ws->getCell([$c, $row])->getValue()));
                    if (array_key_exists($label, $found) && $found[$label] === null) {
                        $value = trim((string) $ws->getCell([$c + 1, $row])->getCalculatedValue());
                        $found[$label] = $value !== '' ? mb_substr($value, 0, 120) : null;
                    }
                }
            }
        }

        return [$found['project'], $found['scope of works']];
    }

    // ── 저장 ─────────────────────────────────────────────────────────

    private function saveSection(Site $site, ProjectContract $contract, array $parsed, ?WorkSection $match, int $index, string $sheetName): WorkSection
    {
        $section = $match ?? new WorkSection([
            'site_id' => $site->id, 'code' => $this->newCode($site, $parsed['name'], $index),
            'name' => mb_substr($parsed['name'], 0, 160), 'sort_order' => $index + 1,
        ]);
        // 이미 있는 공정의 이름은 사람이 고쳤을 수 있다 — 금액·계약·출처만 계약서에 맞춘다.
        $section->fill([
            'company_id' => $site->company_id, 'project_contract_id' => $contract->id,
            'division' => mb_substr($parsed['division'] ?: ($section->division ?: '기타'), 0, 60),
            'contract_amount' => $parsed['subtotal'],
            'source' => mb_substr('원청 계약 기성표 '.$sheetName, 0, 120),
        ])->save();

        return $section;
    }

    /** @return string created|kept|<conflict message> */
    private function saveLine(ProjectContract $contract, IntelligentDocument $doc, WorkSection $section, array $line, string $sheetName): string
    {
        $existing = ContractBoqLine::query()->where('project_contract_id', $contract->id)->where('line_no', $line['itemNo'])->first();
        if ($existing && (abs((float) $existing->contract_qty - $line['qty']) > 0.00005 || abs((float) $existing->unit_price - $line['price']) > 0.00005 || strcasecmp(trim($existing->unit), $line['unit']) !== 0)) {
            return '#'.$line['itemNo'].' '.$line['description'];
        }

        $input = [
            'projectContractId' => $contract->id, 'lineNo' => $line['itemNo'],
            'workSectionId' => $section->id, 'groupLabel' => $line['group'], 'spec' => $line['spec'],
            'description' => $line['description'], 'unit' => $line['unit'],
            'contractQty' => $line['qty'], 'unitPrice' => $line['price'],
            'materialPrice' => $line['material'], 'laborPrice' => $line['labor'], 'expensePrice' => $line['expense'],
            'sourceDocumentId' => $doc->id, 'sourceLocator' => $sheetName.'!'.$line['row'].'행',
            'status' => 'accepted',
        ] + $this->recognition($line);

        if ($existing) {
            if ($existing->records()->where('status', 'verified')->exists()) {
                // 확인된 실적이 있는 줄의 계약 조건은 대장이 잠근다. 같은 줄임은 위에서 확인했으니 공정만 잇는다.
                $existing->forceFill(['work_section_id' => $section->id])->save();

                return 'kept';
            }
            $input['id'] = $existing->id;
            $input['sourceRef'] = $existing->source_ref;
        } else {
            $input['sourceRef'] = self::SOURCE_PREFIX.$line['itemNo'];
        }

        $result = $this->evidence->saveLine($input);
        if (! ($result['success'] ?? false)) {
            throw new InvalidArgumentException('#'.$line['itemNo'].' '.$line['description'].': '.($result['error'] ?? '저장하지 못했습니다.'));
        }

        return $existing ? 'kept' : 'created';
    }

    /** 자재와 설치가 둘 다 값이 있는 줄만 반입 자재를 따로 받는다. */
    private function splitsMaterial(array $line): bool
    {
        return (float) $line['material'] > 0 && (float) $line['labor'] + (float) $line['expense'] > 0;
    }

    /** @return array<string, mixed> */
    private function recognition(array $line): array
    {
        if (! $this->splitsMaterial($line)) {
            return ['recognitionBasis' => 'quantity', 'stageWeights' => [],
                'acceptanceNote' => '원청 계약 기성표 원문 그대로 · 수량 기준(시공 수량 × 계약 단가).'];
        }
        $stored = round((float) $line['material'] / $line['price'] * 100, 4);

        return [
            'recognitionBasis' => 'milestone',
            'stageWeights' => ['stored' => $stored, 'installation' => round(100 - $stored, 4)],
            'acceptanceNote' => '원청 계약 기성표 원문 그대로 · 원청이 설치 전 반입 자재도 기성으로 인정(사장 확인 2026-09-25) · '
                .'반입 인정 = 자재 단가 '.$line['material'].' / 설치 인정 = 노무·경비 단가 '.round((float) $line['labor'] + (float) $line['expense'], 4).'.',
        ];
    }

    private function resolveContract(array $input, Site $site, array $sheet): ProjectContract
    {
        $id = (int) ($input['contractId'] ?? 0);
        if ($id > 0) {
            $contract = $this->billing->findAccessibleContract($id);
            if (! $contract || $contract->direction !== 'receivable' || (int) $contract->site_id !== $site->id) {
                throw new InvalidArgumentException('이 현장의 수주 계약을 찾을 수 없습니다.');
            }

            return $contract;
        }
        if (empty($input['createContract'])) {
            throw new InvalidArgumentException('계약을 고르거나, 계약서 금액으로 새 수주 계약을 만드세요.');
        }
        $saved = app(ContractAdminService::class)->save([
            'title' => $this->newContractTitle($sheet, $site), 'direction' => 'receivable', 'status' => 'active',
            'contractType' => '', 'companyId' => $site->company_id, 'siteId' => $site->id,
            'originalAmount' => (string) $sheet['contractTotal'], 'currency' => 'USD',
            'scopeOfWork' => $sheet['scope'] ?? '',
            'notes' => '원청 계약 기성표에서 만든 계약. 계약 번호·유보율·선급금 조건은 계약서를 보고 채우세요.',
        ]);
        if (! ($saved['success'] ?? false)) {
            throw new InvalidArgumentException($saved['error'] ?? implode(' ', (array) ($saved['errors'] ?? ['계약을 만들지 못했습니다.'])));
        }

        return ProjectContract::query()->findOrFail($saved['id']);
    }

    private function newContractTitle(array $sheet, Site $site): string
    {
        return mb_substr(implode(' · ', array_filter([$sheet['project'] ?? null, $sheet['scope'] ?? null])) ?: $site->name.' 원청 계약', 0, 255);
    }

    /**
     * 계약서 섹션 ↔ 이미 있는 공정. 이름(대소문자·기호 무시)이 같거나, 금액이 같은 공정이 하나뿐이면 같은 공정이다.
     * 공정별 도면 화면에서 사람이 고른 도면이 그대로 이어지게 하려는 것이다.
     *
     * @param  array<int, array<string, mixed>>  $parsed
     * @param  Collection<int, WorkSection>  $existing
     * @return array<int, ?WorkSection>
     */
    private function matchSections(array $parsed, $existing): array
    {
        $norm = fn (string $s): string => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s));
        $used = [];
        $out = [];
        foreach ($parsed as $i => $p) {
            $hit = $existing->first(fn (WorkSection $w): bool => ! isset($used[$w->id]) && $norm($w->name) === $norm($p['name']));
            if ($hit === null && $p['subtotal'] > 0) {
                $byAmount = $existing->filter(fn (WorkSection $w): bool => ! isset($used[$w->id]) && $w->contract_amount !== null && abs($w->contract_amount - $p['subtotal']) < 0.01);
                $hit = $byAmount->count() === 1 ? $byAmount->first() : null;
            }
            if ($hit) {
                $used[$hit->id] = true;
            }
            $out[$i] = $hit;
        }

        return $out;
    }

    private function newCode(Site $site, string $name, int $index): string
    {
        $token = preg_match('/^\s*([A-Za-z]?\d+(?:-\d+)*)/', $name, $m) ? strtoupper($m[1]) : '';
        $code = $token !== '' ? $token : 'S'.($index + 1);
        if (WorkSection::query()->where('site_id', $site->id)->where('code', $code)->exists()) {
            $code = 'S'.($index + 1);
            $n = 1;
            while (WorkSection::query()->where('site_id', $site->id)->where('code', $code)->exists()) {
                $code = 'S'.($index + 1).'-'.$n++;
            }
        }

        return mb_substr($code, 0, 20);
    }

    // ── 문서·계약·권한 ────────────────────────────────────────────────

    /** @return array{0: IntelligentDocument, 1: Site} */
    private function document(int $documentId): array
    {
        $user = auth()->user();
        if (! $this->billing->canView()) {
            throw new InvalidArgumentException('기성 근거를 볼 권한이 없습니다.');
        }
        $doc = IntelligentDocument::query()->visibleTo($user)->whereKey($documentId)->first();
        $site = $doc?->site_id ? Site::query()->find($doc->site_id) : null;
        if (! $doc || ! $site || ! AiInformationAccess::canUseSite($user, $site)) {
            throw new InvalidArgumentException('현장이 지정된 계약서 파일을 찾을 수 없습니다.');
        }
        if ($doc->access_level === 'private') {
            throw new InvalidArgumentException('개인 문서는 계약 근거로 쓸 수 없습니다. 공유 문서로 올려 주세요.');
        }
        if (! in_array(strtolower((string) $doc->extension), ['xlsx', 'xlsm', 'xls'], true)) {
            throw new InvalidArgumentException('엑셀 계약서(.xlsx)를 올려 주세요.');
        }

        return [$doc, $site];
    }

    private function parseDocument(IntelligentDocument $doc): array
    {
        $disk = Storage::disk($doc->disk ?: config('document-intelligence.disk'));
        if (! $doc->file_path || ! $disk->exists($doc->file_path)) {
            throw new InvalidArgumentException('계약서 원본 파일이 저장소에 없습니다. 다시 올려 주세요.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'sheet').'.'.strtolower((string) $doc->extension);
        try {
            file_put_contents($tmp, $disk->get($doc->file_path));

            return $this->parse($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array<int, array{id: int, title: string, amount: ?float}> */
    private function contracts(Site $site): array
    {
        return ProjectContract::query()->where('site_id', $site->id)->where('direction', 'receivable')->orderByDesc('id')->get()
            ->filter(fn (ProjectContract $c): bool => $this->billing->findAccessibleContract($c->id) !== null)
            ->map(fn (ProjectContract $c): array => ['id' => $c->id, 'title' => $c->title, 'amount' => $c->current_amount !== null ? (float) $c->current_amount : null,
                'lines' => ContractBoqLine::query()->where('project_contract_id', $c->id)->count()])
            ->values()->all();
    }

    private function respond(callable $work): array
    {
        try {
            return $work();
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

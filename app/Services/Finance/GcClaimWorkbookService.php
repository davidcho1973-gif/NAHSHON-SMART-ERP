<?php

namespace App\Services\Finance;

use App\Models\ContractBoqLine;
use App\Models\IntelligentDocument;
use App\Models\PayApplication;
use App\Models\PayApplicationAllocation;
use App\Models\ProjectContract;
use App\Services\Admin\BillingAdminService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 원청 청구서 엑셀 — 사장 지시(2026-09-25) «원청 청구서 엑셀 내보내기도 만들어줘».
 *
 * ── 무엇을 만드나 ─────────────────────────────────────────────────────
 * 원청이 준 계약 기성표 파일 그 자체를 채운다(계약서를 올릴 때 문서함에 보관된 원본). 원청은 자기
 * 양식을 받아야 검토하므로 새 양식을 만들지 않는다. 채우는 것은 줄마다 «전회 수량» 과 «금회 수량»,
 * 표지의 회차·날짜·선급금, 그리고 사장이 요청한 «반입 자재» 칸(X·Y)과 RFI 시트다.
 *
 * ── 숫자는 어디서 오나 ────────────────────────────────────────────────
 * 기성 근거 대장이 그 회차에 배정한 확인 수량(pay_application_allocations) — 사람이 확인한 수량만.
 * 설치(시공) 수량 → 원청 양식의 «완료» 수량(단가 전체), 반입했지만 아직 설치 안 한 수량 → 반입 자재
 * 칸(자재 단가). 둘을 더하면 대장이 계산한 금액과 같다. 설치가 반입보다 많은 줄은 대장이 자재값을
 * 인정하지 않은 상태라 두 숫자가 어긋나므로, 내보내기 전에 멈추고 그 줄을 알려 준다.
 *
 * ── 원청 양식의 틀린 수식 ─────────────────────────────────────────────
 * 이 양식의 소계 몇 곳은 SUM 범위가 섹션의 줄을 다 덮지 않는다(예: 7) MISCELLANEOUS 의 전회 금액이
 * 98~102행만 더함). 1차에는 전회가 0 이라 드러나지 않지만 2차부터 금회 금액이 부풀려진다. 우리가
 * 내는 청구서이므로 범위를 바로잡고, 무엇을 고쳤는지 미리보기에 적는다.
 */
class GcClaimWorkbookService
{
    private const INSTALLED = ['installation', 'installed'];

    public function __construct(private readonly BillingAdminService $billing) {}

    /**
     * 내려받기 전에 보여 줄 것 — 파일을 실제로 만들어 그 안의 계산값을 읽는다(화면과 파일이 같은 숫자).
     *
     * @return array<string, mixed>
     */
    public function preview(int $applicationId): array
    {
        return $this->respond(function () use ($applicationId): array {
            $built = $this->make($applicationId);
            @unlink($built['path']);
            unset($built['path']);

            return ['success' => true] + $built;
        });
    }

    /**
     * @return array{success: bool, path?: string, fileName?: string, error?: string}
     */
    public function build(int $applicationId): array
    {
        return $this->respond(fn (): array => ['success' => true] + $this->make($applicationId));
    }

    /**
     * 한 수주 계약의 기성 회차 — 내보내기 창의 목록.
     *
     * @return array<string, mixed>
     */
    public function applications(int $contractId): array
    {
        return $this->respond(function () use ($contractId): array {
            $contract = $this->contract($contractId);
            $apps = PayApplication::where('project_contract_id', $contract->id)->orderBy('application_no')->get();
            $withLedger = PayApplicationAllocation::whereIn('pay_application_id', $apps->pluck('id')->all() ?: [0])
                ->distinct()->pluck('pay_application_id')->all();

            return ['success' => true, 'contractId' => $contract->id, 'canManage' => $this->billing->canManage(),
                'today' => now($contract->site?->timezone ?: config('app.timezone'))->toDateString(),
                'applications' => $apps->map(fn (PayApplication $a): array => [
                    'id' => $a->id, 'no' => (int) $a->application_no, 'status' => $a->status,
                    'periodStart' => $a->period_start?->toDateString(), 'periodEnd' => $a->period_end?->toDateString(),
                    'thisPeriodAmount' => (float) $a->this_period_amount, 'fromLedger' => in_array($a->id, $withLedger, true),
                ])->values()->all()];
        });
    }

    // ── 만들기 ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function make(int $applicationId): array
    {
        $app = PayApplication::find($applicationId);
        if (! $app) {
            throw new InvalidArgumentException('기성 회차를 찾을 수 없습니다.');
        }
        $contract = $this->contract((int) $app->project_contract_id);
        $no = (int) $app->application_no;

        $allocations = PayApplicationAllocation::query()
            ->whereHas('application', fn ($q) => $q->where('project_contract_id', $contract->id)->where('application_no', '<=', $no))
            ->with(['record.line', 'application:id,application_no'])->get();
        if (! $allocations->contains(fn (PayApplicationAllocation $a) => $a->pay_application_id === $app->id)) {
            throw new InvalidArgumentException('이 회차에는 기성 근거 대장의 확인 수량이 배정돼 있지 않습니다. 대장에서 «확인 근거로 초안 만들기» 로 만든 회차만 원청 양식으로 낼 수 있습니다.');
        }

        $lines = ContractBoqLine::where('project_contract_id', $contract->id)->where('status', 'accepted')->orderBy('id')->get()->keyBy('id');
        $qty = $this->quantities($allocations, $no);

        // 설치가 반입보다 많으면 대장은 그 자재값을 인정하지 않았다 — 원청 양식(설치 = 단가 전체)과 어긋난다.
        $gaps = [];
        foreach ($qty as $lineId => $q) {
            $line = $lines->get($lineId);
            if ($line && $line->recognition_basis === 'milestone' && $q['cumInstalled'] > $q['cumStored'] + 0.00001) {
                $gaps[] = '#'.$line->line_no.' '.$line->description.' (설치 '.$this->n($q['cumInstalled']).' · 반입 '.$this->n($q['cumStored']).')';
            }
        }
        if ($gaps !== []) {
            throw new InvalidArgumentException('설치 수량이 반입 수량보다 많은 줄이 있습니다. 반입 기록을 더하고 확인한 뒤 다시 내보내세요 — '.implode(' / ', array_slice($gaps, 0, 5)).(count($gaps) > 5 ? ' 외 '.(count($gaps) - 5).'줄' : ''));
        }

        $template = $this->template($lines);
        $tmpIn = $this->download($template);
        try {
            $book = IOFactory::load($tmpIn);
        } finally {
            @unlink($tmpIn);
        }
        $sheet = $this->continuationSheet($book);
        $cols = $this->columns($sheet);
        $sections = $this->sections($sheet, $cols);

        // 1. 줄 수량 — 원본에 남아 있는 예전 값(1차 예상치 등)을 먼저 지운다.
        foreach ($sections as $sec) {
            foreach ($sec['rows'] as $row) {
                $sheet->setCellValue($cols['prevQty'].$row, null);
                $sheet->setCellValue($cols['thisQty'].$row, null);
                $sheet->setCellValue('X'.$row, null);
            }
        }
        $rfi = [];
        $storedPrevValue = 0.0;
        $unplaced = [];
        foreach ($qty as $lineId => $q) {
            $line = $lines->get($lineId);
            if (! $line) {
                continue;
            }
            $row = $this->rowOf($line);
            if ($row === null) {
                $rfi[] = [$line, $q];

                continue;
            }
            if (! collect($sections)->contains(fn ($s) => in_array($row, $s['rows'], true))) {
                $unplaced[] = '#'.$line->line_no;

                continue;
            }
            $sheet->setCellValue($cols['prevQty'].$row, $this->q($q['prevInstalled']));
            $sheet->setCellValue($cols['thisQty'].$row, $this->q($q['cumInstalled'] - $q['prevInstalled']));
            $onSite = max(0, $q['cumStored'] - $q['cumInstalled']);
            $sheet->setCellValue('X'.$row, $onSite > 0 ? $this->q($onSite) : null);
            $storedPrevValue += max(0, $q['prevStored'] - $q['prevInstalled']) * (float) $line->material_price;
        }
        if ($unplaced !== []) {
            throw new InvalidArgumentException('원청 양식에서 줄의 행을 찾지 못했습니다: '.implode(', ', array_slice($unplaced, 0, 10)));
        }

        // 2. 소계 수식 바로잡기 + 반입 자재 칸(X 수량 · Y 금액).
        $fixes = $this->fixSubtotals($sheet, $cols, $sections);
        $this->storedColumns($sheet, $cols, $sections);

        // 3. RFI 로 더한 일 — 원청 양식에 줄이 없으니 시트를 따로 두고 표지의 V/O 줄에 잇는다.
        [$rfiPrev, $rfiRef] = $this->rfiSheet($book, $rfi);

        // 4. 표지.
        $cover = $book->getSheetByName('1_PROGRESS PAYMENT');
        $notes = $this->cover($book, $cover, $app, $contract, $no, $rfiPrev, $rfiRef, round($storedPrevValue, 2), 'Y'.$cols['total']);

        $file = tempnam(sys_get_temp_dir(), 'gc').'.xlsx';
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(true);
        $writer->save($file);

        return [
            'path' => $file,
            'fileName' => preg_replace('/[^A-Za-z0-9._ -]+/', '', ($contract->site?->code ?: 'Claim').' Progress Payment #'.$no.' '.($app->period_end?->format('Y-m-d') ?? '')).'.xlsx',
            'summary' => $this->summary($book, $cols, $app, $no),
            'fixes' => $fixes,
            'notes' => $notes,
            'rfiLines' => count($rfi),
        ];
    }

    /**
     * 줄마다 전회·누계 설치 수량과 반입 수량.
     *
     * @param  Collection<int, PayApplicationAllocation>  $allocations
     * @return array<int, array{prevInstalled: float, cumInstalled: float, prevStored: float, cumStored: float}>
     */
    private function quantities(Collection $allocations, int $no): array
    {
        $out = [];
        foreach ($allocations as $a) {
            $record = $a->record;
            if (! $record) {
                continue;
            }
            $id = $record->contract_boq_line_id;
            $out[$id] ??= ['prevInstalled' => 0.0, 'cumInstalled' => 0.0, 'prevStored' => 0.0, 'cumStored' => 0.0];
            $prev = (int) $a->application?->application_no < $no;
            $q = (float) $a->quantity;
            if (in_array($record->stage, self::INSTALLED, true)) {
                $out[$id]['cumInstalled'] += $q;
                if ($prev) {
                    $out[$id]['prevInstalled'] += $q;
                }
            } elseif ($record->stage === 'stored') {
                $out[$id]['cumStored'] += $q;
                if ($prev) {
                    $out[$id]['prevStored'] += $q;
                }
            }
        }

        return $out;
    }

    /** 계약 줄들이 원문으로 가리키는 원청 기성표 — 가장 많은 줄이 가리키는 문서. */
    private function template(Collection $lines): IntelligentDocument
    {
        $docId = $lines->filter(fn (ContractBoqLine $l) => $this->rowOf($l) !== null)->countBy('source_document_id')->sortDesc()->keys()->first();
        $doc = $docId ? IntelligentDocument::find($docId) : null;
        if (! $doc) {
            throw new InvalidArgumentException('원청 계약 기성표 원본을 찾지 못했습니다. 계약서를 먼저 올리세요.');
        }

        return $doc;
    }

    private function download(IntelligentDocument $doc): string
    {
        $disk = Storage::disk($doc->disk ?: config('document-intelligence.disk'));
        if (! $doc->file_path || ! $disk->exists($doc->file_path)) {
            throw new InvalidArgumentException('원청 계약 기성표 원본 파일이 저장소에 없습니다. 계약서를 다시 올려 주세요.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'gct').'.'.(strtolower((string) $doc->extension) ?: 'xlsx');
        file_put_contents($tmp, $disk->get($doc->file_path));

        return $tmp;
    }

    /** 계약서 줄의 원문 위치 «3_CONTINUATION SHEET!58행» → 58. RFI 로 더한 줄은 null. */
    private function rowOf(ContractBoqLine $line): ?int
    {
        return str_starts_with((string) $line->source_ref, ContractSheetImportService::SOURCE_PREFIX)
            && preg_match('/!(\d+)행$/u', (string) $line->source_locator, $m) ? (int) $m[1] : null;
    }

    private function continuationSheet(Spreadsheet $book): Worksheet
    {
        foreach ($book->getWorksheetIterator() as $ws) {
            for ($r = 1; $r <= 20; $r++) {
                for ($c = 1; $c <= 8; $c++) {
                    if (stripos((string) $ws->getCell([$c, $r])->getValue(), 'description of work') !== false) {
                        return $ws;
                    }
                }
            }
        }
        throw new InvalidArgumentException('원청 양식에서 continuation sheet 를 찾지 못했습니다.');
    }

    /**
     * 원청 양식의 칸 — 제목으로 찾는다(PREVIOUS · THIS PERIOD · UP TO THIS BILL · BALANCE · RETAINAGE).
     *
     * @return array<string, mixed>
     */
    private function columns(Worksheet $ws): array
    {
        $found = [];
        $header = null;
        for ($r = 1; $r <= 20; $r++) {
            for ($c = 1; $c <= 30; $c++) {
                $t = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $ws->getCell([$c, $r])->getValue())));
                $key = match (true) {
                    $t === 'DESCRIPTION OF WORK' => 'desc',
                    $t === 'PREVIOUS' => 'prevQty',
                    $t === 'THIS PERIOD' => 'thisQty',
                    str_starts_with($t, 'UP TO THIS BILL') => 'cumQty',
                    str_starts_with($t, 'BALANCE') => 'balQty',
                    $t === 'AMOUNT' && ! isset($found['contractAmount']) => 'contractAmount',
                    $t === 'QUANTITY' && ! isset($found['contractQty']) => 'contractQty',
                    default => null,
                };
                if ($key !== null && ! isset($found[$key])) {
                    $found[$key] = $c;
                    if ($key === 'desc') {
                        $header = $r;
                    }
                }
            }
        }
        foreach (['desc', 'prevQty', 'thisQty', 'cumQty', 'balQty', 'contractAmount'] as $need) {
            if (! isset($found[$need])) {
                throw new InvalidArgumentException('원청 양식에서 전회·금회·누계 칸을 찾지 못했습니다.');
            }
        }
        $L = fn (int $i): string => Coordinate::stringFromColumnIndex($i);
        // 합계 행 — 요약표의 «TOTAL». 표지가 이 행을 가리킨다.
        $total = null;
        for ($r = $header + 1; $r <= $header + 80 && $total === null; $r++) {
            if (strtoupper(trim((string) $ws->getCell([$found['desc'], $r])->getValue())) === 'TOTAL') {
                $total = $r;
            }
        }
        if ($total === null) {
            throw new InvalidArgumentException('원청 양식 요약표의 TOTAL 행을 찾지 못했습니다.');
        }

        return [
            'header' => $header, 'total' => $total, 'amount' => $L($found['contractAmount']),
            'qty' => $L($found['contractQty'] ?? ($found['contractAmount'] - 5)),
            'prevQty' => $L($found['prevQty']), 'prevAmt' => $L($found['prevQty'] + 1),
            'thisQty' => $L($found['thisQty']), 'thisAmt' => $L($found['thisQty'] + 1),
            'cumQty' => $L($found['cumQty']), 'cumAmt' => $L($found['cumQty'] + 1),
            'balQty' => $L($found['balQty']), 'balAmt' => $L($found['balQty'] + 1),
        ];
    }

    /**
     * 섹션 — 요약표의 수식(=C44, =K49)이 가리키는 제목 행과 소계 행. 계약서 올리기와 같은 읽는 법.
     *
     * @return array<int, array{header: int, subtotal: int, rows: array<int, int>, summaryRow: int}>
     */
    private function sections(Worksheet $ws, array $cols): array
    {
        $out = [];
        $amount = $cols['amount'];
        $desc = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($amount) - 8);
        $descCol = null;
        for ($c = 1; $c <= 10; $c++) {
            if (strcasecmp(trim((string) $ws->getCell([$c, $cols['header']])->getValue()), 'DESCRIPTION OF WORK') === 0) {
                $descCol = Coordinate::stringFromColumnIndex($c);
            }
        }
        $descCol ??= $desc;
        $first = PHP_INT_MAX;
        for ($r = $cols['header'] + 1; $r < $first && $r <= $ws->getHighestRow(); $r++) {
            $d = $this->ref($ws->getCell($descCol.$r)->getValue());
            $a = $this->ref($ws->getCell($amount.$r)->getValue());
            if ($d === null || $a === null) {
                continue;
            }
            $first = min($first, $d);
            $rows = [];
            $lines = [];
            for ($x = $d + 1; $x < $a; $x++) {
                $rows[] = $x;
                if (is_numeric($ws->getCell($cols['qty'].$x)->getCalculatedValue())) {
                    $lines[] = $x;   // 계약 수량이 있는 행 = 계약 줄
                }
            }
            $out[] = ['header' => $d, 'subtotal' => $a, 'rows' => $rows, 'lines' => $lines, 'summaryRow' => $r];
        }
        if ($out === []) {
            throw new InvalidArgumentException('원청 양식의 요약표(섹션 목록)를 읽지 못했습니다.');
        }

        return $out;
    }

    private function ref(mixed $v): ?int
    {
        return is_string($v) && preg_match('/^=\$?[A-Z]{1,3}\$?(\d+)$/D', trim($v), $m) ? (int) $m[1] : null;
    }

    /**
     * 소계 SUM 이 섹션의 줄을 다 덮지 않으면 바로잡는다.
     *
     * @return array<int, string> 고친 칸의 설명
     */
    private function fixSubtotals(Worksheet $ws, array $cols, array $sections): array
    {
        $fixes = [];
        foreach ($sections as $sec) {
            if ($sec['lines'] === []) {
                continue;
            }
            // 소계가 계약 줄을 전부 덮으면 원청 수식 그대로 둔다 — 틀린 것만 고친다.
            $from = min($sec['lines']);
            $to = max($sec['lines']);
            foreach (['prevAmt', 'cumAmt', 'balAmt'] as $key) {
                $cell = $cols[$key].$sec['subtotal'];
                $formula = (string) $ws->getCell($cell)->getValue();
                if (preg_match('/^=SUM\(\$?[A-Z]+\$?(\d+):\$?[A-Z]+\$?(\d+)\)$/i', str_replace(' ', '', $formula), $m) && (int) $m[1] <= $from && (int) $m[2] >= $to) {
                    continue;
                }
                $ws->setCellValue($cell, '=SUM('.$cols[$key].$from.':'.$cols[$key].$to.')');
                $fixes[] = $cell.' '.$formula.' → =SUM('.$cols[$key].$from.':'.$cols[$key].$to.')';
            }
            // 금회 금액 소계는 누계 − 전회로 두면 위 두 소계가 맞는 한 맞다. SUM 이 아니면 누계 − 전회로 통일.
            $thisCell = $cols['thisAmt'].$sec['subtotal'];
            $thisFormula = str_replace(' ', '', (string) $ws->getCell($thisCell)->getValue());
            if (! preg_match('/^=SUM\(\$?[A-Z]+\$?(\d+):\$?[A-Z]+\$?(\d+)\)$/i', $thisFormula, $m) || (int) $m[1] > $from || (int) $m[2] < $to) {
                $expected = '='.$cols['cumAmt'].$sec['subtotal'].'-'.$cols['prevAmt'].$sec['subtotal'];
                if (strcasecmp($thisFormula, $expected) !== 0) {
                    $ws->setCellValue($thisCell, $expected);
                    $fixes[] = $thisCell.' '.$thisFormula.' → '.$expected;
                }
            }
        }

        return $fixes;
    }

    /** 반입 자재 칸 — X 수량(반입했지만 아직 설치 안 한 것), Y 금액(= X × 자재 단가 G). 합계는 원청 양식처럼 올린다. */
    private function storedColumns(Worksheet $ws, array $cols, array $sections): void
    {
        $h = $cols['header'];
        $ws->setCellValue('X'.($h - 1), 'I');
        $ws->setCellValue('X'.$h, 'MATERIALS PRESENTLY STORED');
        $ws->setCellValue('X'.($h + 1), '(반입 자재 · 미설치)');
        $ws->setCellValue('X'.($h + 2), 'Quantity');
        $ws->setCellValue('Y'.($h + 2), 'Amount');
        foreach (['X', 'Y'] as $c) {
            $src = $c === 'X' ? $cols['balQty'] : $cols['balAmt'];
            for ($r = $h - 1; $r <= $ws->getHighestRow(); $r++) {
                $ws->duplicateStyle($ws->getStyle($src.$r), $c.$r);
            }
        }
        $ws->getColumnDimension('X')->setWidth(12);
        $ws->getColumnDimension('Y')->setWidth(15);
        $material = 'G';
        foreach ($sections as $sec) {
            foreach ($sec['rows'] as $r) {
                if ($ws->getCell($cols['amount'].$r)->getValue() !== null && $ws->getCell('F'.$r)->getValue() !== null) {
                    $ws->setCellValue('Y'.$r, '=IF(X'.$r.'="","",X'.$r.'*'.$material.$r.')');
                }
            }
            $ws->setCellValue('Y'.$sec['subtotal'], '=SUM(Y'.min($sec['rows'] ?: [$sec['header'] + 1]).':Y'.max($sec['rows'] ?: [$sec['subtotal'] - 1]).')');
        }
        // 요약표: 금액 칸(K)의 수식을 그대로 Y 로 옮긴다(=K49 → =Y49, TRUNC(SUM(K13:K21)) → …). 절사 행은 뺀다.
        $amount = $cols['amount'];
        $firstSection = min(array_column($sections, 'header'));
        for ($r = $h + 1; $r < $firstSection; $r++) {
            $f = $ws->getCell($amount.$r)->getValue();
            if (! is_string($f) || ! str_starts_with($f, '=') || stripos($f, 'MOD(') !== false) {
                continue;
            }
            $ws->setCellValue('Y'.$r, preg_replace('/(?<![A-Z$])\$?'.$amount.'\$?(\d+)/', 'Y$1', $f));
        }
        $area = $ws->getPageSetup()->getPrintArea();
        if ($area && preg_match('/^([A-Z]+\d+):([A-Z]+)(\d+)$/', $area, $m) && Coordinate::columnIndexFromString($m[2]) < Coordinate::columnIndexFromString('Y')) {
            $ws->getPageSetup()->setPrintArea($m[1].':Y'.$m[3]);
        }
    }

    /**
     * RFI 시트 — 승인된 RFI 로 더한 줄. 표지의 V/O 줄이 이 시트의 합계를 가리킨다.
     *
     * @param  array<int, array{0: ContractBoqLine, 1: array<string, float>}>  $rfi
     * @return array{0: float, 1: ?string} 전회 금액, 누계 금액 칸 참조
     */
    private function rfiSheet(Spreadsheet $book, array $rfi): array
    {
        if ($rfi === []) {
            return [0.0, null];
        }
        $ws = $book->createSheet();
        $ws->setTitle('4_RFI CHANGE ORDERS');
        $ws->fromArray([['RFI CHANGE ORDERS (승인된 RFI 로 더한 일)'], [], ['ITEM', 'DESCRIPTION', 'UNIT', 'CONTRACT QTY', 'UNIT PRICE', 'CONTRACT AMOUNT', 'PREVIOUS QTY', 'PREVIOUS AMOUNT', 'THIS PERIOD QTY', 'THIS PERIOD AMOUNT', 'TO DATE QTY', 'TO DATE AMOUNT', 'MATERIALS STORED']], null, 'A1');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->getStyle('A3:M3')->getFont()->setBold(true);
        $r = 4;
        $prevTotal = 0.0;
        foreach ($rfi as [$line, $q]) {
            $price = (float) $line->unit_price;
            $onSite = max(0, $q['cumStored'] - $q['cumInstalled']);
            $ws->fromArray([[$line->line_no, $line->description.($line->spec ? ' · '.$line->spec : ''), $line->unit, (float) $line->contract_qty, $price,
                '=D'.$r.'*E'.$r, $this->q($q['prevInstalled']), '=G'.$r.'*E'.$r, $this->q($q['cumInstalled'] - $q['prevInstalled']), '=I'.$r.'*E'.$r,
                '=G'.$r.'+I'.$r, '=K'.$r.'*E'.$r, $onSite > 0 ? round($onSite * (float) $line->material_price, 2) : 0]], null, 'A'.$r);
            $prevTotal += $q['prevInstalled'] * $price + max(0, $q['prevStored'] - $q['prevInstalled']) * (float) $line->material_price;
            $r++;
        }
        $ws->setCellValue('B'.$r, 'TOTAL');
        foreach (['F', 'H', 'J', 'L', 'M'] as $c) {
            $ws->setCellValue($c.$r, '=SUM('.$c.'4:'.$c.($r - 1).')');
        }
        $ws->setCellValue('B'.($r + 1), 'TO DATE INCL. STORED');
        $ws->setCellValue('L'.($r + 1), '=L'.$r.'+M'.$r);
        $ws->getStyle('A'.$r.':M'.($r + 1))->getFont()->setBold(true);
        $ws->getStyle('D4:M'.($r + 1))->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'M') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        return [round($prevTotal, 2), "'4_RFI CHANGE ORDERS'!L".($r + 1)];
    }

    /**
     * 표지 — 회차·기간·날짜, 선급금, V/O(RFI), 반입 자재(OTHERS 줄).
     *
     * @return array<int, string> 사람이 확인할 것
     */
    private function cover(Spreadsheet $book, ?Worksheet $cover, PayApplication $app, ProjectContract $contract, int $no, float $rfiPrev, ?string $rfiRef, float $storedPrev, string $storedTotal): array
    {
        $notes = [];
        $cont = $this->continuationSheet($book);
        $date = ExcelDate::PHPToExcel(now($contract->site?->timezone ?: config('app.timezone'))->startOfDay());
        $cont->setCellValue('U2', $no);
        $cont->setCellValue('U3', $date);
        if (! $cover) {
            $notes[] = '표지(1_PROGRESS PAYMENT)를 찾지 못해 줄 시트만 채웠습니다.';

            return $notes;
        }
        $cover->setCellValue('C38', $no);
        if ($app->period_end) {
            $cover->setCellValue('H38', ExcelDate::PHPToExcel($app->period_end->copy()->startOfDay()));
            $cover->getStyle('H38')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        }
        $cover->setCellValue('C10', $date);
        $cover->getStyle('C10')->getNumberFormat()->setFormatCode('yyyy-mm-dd');

        // 선급금: 계약서 1쪽에 적힌 금액(G19). 1차에 받고, 그 뒤 회차에서는 «이미 받은 것» 이다.
        $advance = $cover->getCell('G19')->getCalculatedValue();
        if (is_numeric($advance) && (float) $advance > 0) {
            $cover->setCellValue('E19', $no > 1 ? (float) $advance : 0);
            $notes[] = '선급금 '.number_format((float) $advance, 2).' — '.($no > 1 ? '1차에 받은 것으로 전회에 넣었습니다.' : '이번 회차에 청구합니다.');
        }
        $notes[] = '선급금 상환(ADV. REPAYMENT)은 원청과 합의한 방식이 계약서에 없어 비워 두었습니다. 필요하면 직접 적으세요.';

        // V/O: 승인된 RFI 로 더한 일.
        $cover->setCellValue('E21', $rfiPrev);
        $cover->setCellValue('G21', $rfiRef ? '='.$rfiRef : 0);
        // OTHERS → 반입 자재(사장 지시: 칸을 따로 보여 달라).
        $cover->setCellValue('C22', 'MATERIALS STORED');
        $cover->setCellValue('E22', $storedPrev);
        $cover->setCellValue('G22', "='".$cont->getTitle()."'!".$storedTotal);

        $sheet2 = $book->getSheetByName('2_APPLICATION');
        if ($sheet2) {
            $sheet2->setCellValue('F18', (float) ($contract->approved_change_amount ?? 0));
        }

        return $notes;
    }

    /** @return array<string, float|int|null> 파일 안의 계산값 — 화면에 그대로 보인다. */
    private function summary(Spreadsheet $book, array $cols, PayApplication $app, int $no): array
    {
        $cont = $this->continuationSheet($book);
        $cover = $book->getSheetByName('1_PROGRESS PAYMENT');
        $v = fn (?Worksheet $ws, string $cell) => $ws ? (is_numeric($x = $ws->getCell($cell)->getCalculatedValue()) ? round((float) $x, 2) : null) : null;

        return [
            'applicationNo' => $no,
            'periodEnd' => $app->period_end?->toDateString(),
            'workThisPeriod' => $v($cont, $cols['thisAmt'].$cols['total']),
            'workToDate' => $v($cont, $cols['cumAmt'].$cols['total']),
            'storedToDate' => $v($cont, 'Y'.$cols['total']),
            'changeOrdersToDate' => $v($cover, 'G21'),
            'grossThisBill' => $v($cover, 'F23'),
            'retentionThisBill' => $v($cover, 'F25'),
            'netThisBill' => $v($cover, 'F34'),
            'ledgerThisPeriod' => round((float) $app->this_period_amount, 2),
        ];
    }

    private function contract(int $id): ProjectContract
    {
        if (! $this->billing->canView()) {
            throw new InvalidArgumentException('기성 청구를 볼 권한이 없습니다.');
        }
        $contract = $this->billing->findAccessibleContract($id);
        if (! $contract || $contract->direction !== 'receivable') {
            throw new InvalidArgumentException('접근 가능한 수주 계약을 찾을 수 없습니다.');
        }

        return $contract;
    }

    private function q(float $v): ?float
    {
        $v = round($v, 4);

        return abs($v) < 0.00005 ? null : $v;
    }

    private function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ','), '0'), '.');
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

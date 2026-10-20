<?php

namespace App\Services\Finance;

use App\Models\ClaimEditDraft;
use App\Models\ContractBoqLine;
use App\Support\JobAccess;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

/** A versioned work report from the existing draft, never a second claim ledger. */
class ClaimRecordReport
{
    public function snapshot(int $lineId, int $revision): array
    {
        $line = ContractBoqLine::findOrFail($lineId);
        $ledger = app(ClaimEvidenceService::class)->getLedger($line->project_contract_id);
        abort_unless($ledger['success'] ?? false, 403);
        abort_if(JobAccess::managed(auth()->user()) && ! JobAccess::can(auth()->user(), 'contracts', 'export'), 403);
        $draft = ClaimEditDraft::where('contract_boq_line_id', $lineId)->where('user_id', auth()->id())->firstOrFail();
        abort_unless($draft->revision === $revision, 409, '초안이 변경되었습니다. 저장 후 미리보기를 다시 만드세요.');
        $c = $draft->content;
        try {
            $proof = app(ClaimEvidenceService::class)->editableReferences($line->project_contract_id, $c['evidence']);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
        foreach ($c['evidence'] as $entry) {
            $current = collect($proof)->first(fn ($p) => $p['type'] === $entry['type'] && $p['id'] === $entry['id']);
            abort_if(! empty($entry['sha256']) && ($current['sha256'] ?? null) !== $entry['sha256'], 409, '증빙 원본이 변경되었습니다. 다시 연결한 후 저장하세요.');
        }
        $calc = app(ClaimQuantityCalculation::class)->calculate($c['calculations']);
        $sources = collect($ledger['sourceOptions'] ?? [])->keyBy('value');

        return ['draft' => $draft, 'line' => $line, 'content' => $c, 'rows' => $calc['rows'],
            'quantity' => $calc['rows'] ? $calc['total'] : $c['quantity'],
            'evidence' => array_map(function ($e) use ($sources, $proof): array {
                $resolved = collect($proof)->first(fn ($p) => $p['type'] === $e['type'] && (int) $p['id'] === (int) $e['id']);

                return ['title' => $sources->get($e['type'].':'.$e['id'])['label'] ?? $resolved['title'] ?? '증빙 #'.$e['id'], 'locator' => $e['locator']];
            }, $c['evidence']),
            'title' => 'BOQ '.$line->line_no.' — '.$line->description,
            'status' => $draft->claim_work_record_id ? '검토 요청 기록 #'.$draft->claim_work_record_id.' · 승인 결과는 ERP에서 확인' : '작성 중 초안 · 시공 및 기성 승인 아님'];
    }

    public function html(array $s): string
    {
        $c = $s['content'];
        $rows = '';
        foreach ($s['rows'] as $r) {
            $rows .= '<tr>'.implode('', array_map(fn ($v) => '<td>'.e($v).'</td>', [$r['label'], $r['length'], $r['height'], $r['count'], $r['deduction'], $r['quantity']])).'</tr>';
        }
        $proof = implode('', array_map(fn ($e) => '<li>'.e($e['title'].' — '.$e['locator']).'</li>', $s['evidence']));

        return '<!doctype html><html lang="ko"><meta charset="utf-8"><title>'.e($s['title']).'</title><style>body{font:14px/1.7 Arial,sans-serif;color:#172333;max-width:960px;margin:30px auto;padding:24px}h1{font-size:24px}table{width:100%;border-collapse:collapse}td,th{padding:8px;border:1px solid #ccd4df;text-align:left}pre{white-space:pre-wrap;font:inherit;overflow-wrap:anywhere}.status{background:#edf4fa;padding:12px}@media print{body{margin:0;padding:0}tr{break-inside:avoid}}</style><h1>'.e($s['title']).'</h1><p class="status">'.e($s['status']).' · v'.$s['draft']->revision.'</p><p>작업일 '.e($c['workDate']).' · 위치 '.e($c['location']).' · 단계 '.e($c['stage']).'</p><h2>작업 설명</h2><pre>'.e($c['notes']).'</pre><h2>산출표</h2><table><thead><tr><th>위치</th><th>길이</th><th>높이</th><th>개수</th><th>공제</th><th>수량</th></tr></thead><tbody>'.$rows.'</tbody></table><p>보고 수량: '.e($s['quantity'].' '.$s['line']->unit).'</p><h2>증빙 목록</h2><ul>'.$proof.'</ul><p>증빙 원본 파일은 이 보고서에 포함되지 않습니다. 도면은 작업실의 내보내기에서 별도로 저장하세요.</p></html>';
    }

    public function file(array $s, string $format): array
    {
        abort_unless(in_array($format, ['docx', 'xlsx'], true), 404);
        $path = tempnam(sys_get_temp_dir(), 'claim-report-');
        try {
            if ($format === 'docx') {
                Settings::setOutputEscapingEnabled(true);
                $doc = new PhpWord;
                $doc->setDefaultFontName('Arial');
                $part = $doc->addSection();
                $part->addText($s['title'], ['bold' => true, 'size' => 18]);
                $part->addText($s['status'].' · v'.$s['draft']->revision);
                foreach (['workDate' => '작업일', 'location' => '위치', 'stage' => '단계', 'notes' => '작업 설명'] as $key => $label) {
                    $part->addText($label, ['bold' => true]);
                    foreach (explode("\n", $s['content'][$key]) as $text) {
                        $part->addText($text);
                    }
                }
                $table = $part->addTable(['borderSize' => 6, 'borderColor' => 'CCCCCC']);
                foreach ([['위치', '길이', '높이', '개수', '공제', '수량'], ...array_map(fn ($r) => [$r['label'], $r['length'], $r['height'], $r['count'], $r['deduction'], $r['quantity']], $s['rows'])] as $row) {
                    $table->addRow();
                    foreach ($row as $v) {
                        $table->addCell(1500)->addText((string) $v);
                    }
                }
                $part->addText('보고 수량: '.$s['quantity'].' '.$s['line']->unit);
                $part->addText('증빙 목록 (원본 별도)', ['bold' => true]);
                foreach ($s['evidence'] as $e) {
                    $part->addText($e['title'].' — '.$e['locator']);
                }
                IOFactory::createWriter($doc, 'Word2007')->save($path);
            } else {
                $book = new Spreadsheet;
                $sheet = $book->getActiveSheet()->setTitle('작업 기록');
                $rows = [[$s['title']], [$s['status'].' · v'.$s['draft']->revision], ['작업일', $s['content']['workDate']], ['위치', $s['content']['location']], ['단계', $s['content']['stage']], ['설명', $s['content']['notes']], ['보고 수량', $s['quantity'], $s['line']->unit], [], ['위치', '길이', '높이', '개수', '공제', '수량']];
                foreach ($s['rows'] as $r) {
                    $rows[] = [$r['label'], $r['length'], $r['height'], $r['count'], $r['deduction'], $r['quantity']];
                }
                $rows[] = ['증빙 목록 (원본 별도)'];
                foreach ($s['evidence'] as $e) {
                    $rows[] = [$e['title'], $e['locator']];
                }
                foreach ($rows as $i => $row) {
                    foreach ($row as $j => $v) {
                        $sheet->setCellValueExplicit([$j + 1, $i + 1], $v, is_int($v) || is_float($v) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    }
                }
                foreach ($s['rows'] as $i => $row) {
                    $n = $i + 10;
                    $sheet->setCellValue('F'.$n, '=ROUND(B'.$n.'*C'.$n.'*D'.$n.'-E'.$n.',4)');
                }
                if ($s['rows']) {
                    $sheet->setCellValue('B7', '=SUM(F10:F'.(9 + count($s['rows'])).')');
                }
                $sheet->freezePane('B10');
                $sheet->getStyle('A1:F'.count($rows))->getAlignment()->setWrapText(true);
                $sheet->getColumnDimension('A')->setWidth(40);
                foreach (range('B', 'F') as $col) {
                    $sheet->getColumnDimension($col)->setWidth(20);
                }
                (new Xlsx($book))->save($path);
                $book->disconnectWorksheets();
            }

            return ['data' => file_get_contents($path), 'name' => 'BOQ-'.$s['line']->id.'-v'.$s['draft']->revision.'.'.$format,
                'mime' => $format === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        } finally {
            @unlink($path);
        }
    }
}

<?php

namespace App\Services\Finance;

use App\Support\Org;
use App\Support\SmartCompanyData;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class ExpenseReportWorkbook
{
    public const STATUSES = ['draft' => '임시저장', 'pending' => '승인대기', 'approved' => '승인완료', 'paid' => '지급완료', 'rejected' => '반려'];

    public function build(string $site, array $options): Spreadsheet
    {
        // List and workbook share the same scoped, validated query; never export just the visible page.
        $result = SmartCompanyData::expenses($site, true, $options, false);
        $filters = $result['filters'];
        $book = new Spreadsheet;
        $book->getProperties()->setCreator(Org::name())->setTitle('비용 조회 보고서');
        $book->getDefaultStyle()->getFont()->setName('맑은 고딕')->setSize(11);
        $summary = $book->getActiveSheet()->setTitle('조회 요약');
        $detail = $book->createSheet()->setTitle('비용 상세');
        $generated = now()->format('Y-m-d H:i T');
        $scope = $site === 'ALL' ? '전체 현장 (접근 허용 범위)' : $site.' · 본사 공통 포함';
        $conditions = ($filters['dateFrom'] ?: '시작일 제한 없음').' ~ '.($filters['dateTo'] ?: '종료일 제한 없음')
            .' | 상태: '.(self::STATUSES[$filters['status']] ?? '전체')
            .' | 결제: '.(['personal' => '개인 결제', 'corporate' => '회사 결제'][$filters['payment']] ?? '전체')
            .' | 검색: '.($filters['search'] ?: '없음');
        foreach ([$summary, $detail] as $sheet) {
            $end = $sheet === $summary ? 'F' : 'K';
            $sheet->mergeCells('A1:'.$end.'1')->setCellValue('A1', '비용 조회 보고서');
            $sheet->mergeCells('A2:'.$end.'2')->setCellValueExplicit('A2', Org::name().' | '.$scope, DataType::TYPE_STRING);
            $sheet->mergeCells('A3:'.$end.'3')->setCellValueExplicit('A3', $conditions, DataType::TYPE_STRING);
            $sheet->mergeCells('A4:'.$end.'4')->setCellValue('A4', '작성: '.$generated.' | 통화: USD | 조회 전체 '.$result['total'].'건');
            $sheet->getRowDimension(1)->setRowHeight(32);
            $sheet->getRowDimension(3)->setRowHeight(40);
            $sheet->getStyle('A1:'.$end.'1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle('A1:'.$end.'1')->getFill()->setFillType('solid')->getStartColor()->setRGB('183A58');
            $sheet->getStyle('A2:'.$end.'4')->getAlignment()->setWrapText(true);
            $sheet->setShowGridlines(false);
            $sheet->getPageSetup()->setPaperSize($sheet === $summary ? PageSetup::PAPERSIZE_A4 : PageSetup::PAPERSIZE_A3)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
            $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.25)->setRight(0.25);
            $sheet->getHeaderFooter()->setOddFooter('&L비용 조회 보고서 · USD&R&P / &N');
        }
        $detail->fromArray(['관리번호', '비용일', '현장', '계정', '세부내역', '금액 (USD)', '상태', '제출자', '결제 구분', '연결 예산', '영수증'], null, 'A6');
        $row = 7;
        foreach ($result['items'] as $expense) {
            $values = [$expense['id'], $expense['date'], $expense['site'], $expense['account'], $expense['detail'], $expense['amount'], self::STATUSES[$expense['status']] ?? $expense['status'], $expense['employeeName'], ['personal' => '개인 결제', 'corporate' => '회사 결제'][$expense['method']] ?? $expense['method'], $expense['preApprovalTitle'], $expense['receiptUrl'] ? '영수증 보기' : '미첨부'];
            foreach ($values as $index => $value) {
                $detail->setCellValueExplicit([$index + 1, $row], $value ?? '', $index === 5 ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            if ($expense['date']) {
                $detail->setCellValue('B'.$row, Date::PHPToExcel(new \DateTimeImmutable($expense['date'])));
                $detail->getStyle('B'.$row)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            }
            if ($expense['receiptUrl']) {
                $detail->getCell('K'.$row)->getHyperlink()->setUrl(url($expense['receiptUrl']));
                $detail->getStyle('K'.$row)->getFont()->getColor()->setRGB('0070C0');
            }
            $lines = max(1, (int) ceil(mb_strlen($expense['detail']) / 24), substr_count($expense['detail'], "\n") + 1);
            $detail->getRowDimension($row)->setRowHeight(min(409, max(42, $lines * 15 + 10)));
            if ($row % 2 === 1) {
                $detail->getStyle('A'.$row.':K'.$row)->getFill()->setFillType('solid')->getStartColor()->setRGB('F1F5F9');
            }
            $row++;
        }
        $last = max(7, $row - 1);
        if ($result['total'] === 0) {
            $detail->setCellValue('E7', '조회 조건에 해당하는 비용이 없습니다.');
            $row = 8;
        }
        $detail->setCellValue('E'.$row, '조회 합계')->setCellValue('F'.$row, '=SUM(F7:F'.$last.')');
        $detail->getStyle('A'.$row.':K'.$row)->getFont()->setBold(true);
        $detail->getStyle('F7:F'.$row)->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00);"-"');
        $detail->getStyle('A7:K'.$row)->getAlignment()->setVertical('top')->setWrapText(true);
        foreach (['A' => 12, 'B' => 12, 'C' => 14, 'D' => 22, 'E' => 44, 'F' => 14, 'G' => 11, 'H' => 14, 'I' => 11, 'J' => 18, 'K' => 13] as $column => $width) {
            $detail->getColumnDimension($column)->setWidth($width);
        }
        $detail->freezePane('F7')->setAutoFilter('A6:K'.$last);
        $detail->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 6)->setPrintArea('A1:K'.$row);
        $summary->fromArray(['상태', '건수', '금액 (USD)'], null, 'A6');
        $row = 7;
        foreach (self::STATUSES as $label) {
            $summary->setCellValue('A'.$row, $label);
            $summary->setCellValue('B'.$row, '=COUNTIF(\'비용 상세\'!G7:G'.$last.',A'.$row.')');
            $summary->setCellValue('C'.$row, '=SUMIF(\'비용 상세\'!G7:G'.$last.',A'.$row.',\'비용 상세\'!F7:F'.$last.')');
            $row++;
        }
        $summary->setCellValue('A12', '조회 합계')->setCellValue('B12', '=SUM(B7:B11)')->setCellValue('C12', '=SUM(C7:C11)');
        $summary->setCellValue('A14', '승인·지급 비용')->setCellValue('C14', '=SUM(C9:C10)');
        $summary->mergeCells('A16:F17')->setCellValue('A16', '조회 합계는 모든 조회 상태를 포함합니다. 실제 승인·지급 비용은 별도로 표시합니다. 영수증 링크는 ERP 로그인과 조회 권한이 필요합니다. 현장별 조회에는 본사 공통 비용이 포함되어 현장별 합계를 단순 합산하면 중복될 수 있습니다.');
        $summary->getStyle('A16:F17')->getAlignment()->setWrapText(true);
        $summary->getRowDimension(16)->setRowHeight(36);
        $summary->getStyle('C7:C14')->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00);"-"');
        foreach (['A' => 23, 'B' => 14, 'C' => 22, 'D' => 16, 'E' => 16, 'F' => 16] as $column => $width) {
            $summary->getColumnDimension($column)->setWidth($width);
        }
        $summary->getStyle('A12:C14')->getFont()->setBold(true);
        $summary->freezePane('A7');
        $summary->getPageSetup()->setPrintArea('A1:F17')->setFitToHeight(1);
        foreach ([$summary, $detail] as $sheet) {
            $end = $sheet === $summary ? 'C' : 'K';
            $sheet->getRowDimension(6)->setRowHeight(28);
            $sheet->getStyle('A6:'.$end.'6')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle('A6:'.$end.'6')->getFill()->setFillType('solid')->getStartColor()->setRGB('315B7A');
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\SmartCompanyData;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HrAttendanceExportController extends Controller
{
    private const HEADERS = ['회사', '팀', '직원명', '사번', '직무', '출근', '퇴근', '상태'];

    public function export(Request $request): BinaryFileResponse
    {
        $site = (string) $request->query('site', 'ALL');
        $date = $request->query('date');
        $date = is_string($date) && $date !== '' ? $date : Carbon::today()->toDateString();

        $detail = SmartCompanyData::realDailyAttendanceDetail($site, $date);
        $companies = $detail['companies'] ?? [];

        $present = 0;
        $absent = 0;
        foreach ($companies as $company) {
            foreach ($company['teams'] ?? [] as $team) {
                foreach ($team['members'] ?? [] as $member) {
                    ($member['isOpen'] ?? false) ? $present++ : $absent++;
                }
            }
        }
        $total = $present + $absent;

        $options = new Options;
        $options->setColumnWidth(18, 1, 2);
        $options->setColumnWidth(20, 3);
        $options->setColumnWidth(16, 4, 5);
        $options->setColumnWidth(12, 6, 7, 8);
        // 제목/요약을 8개 열에 걸쳐 병합 (열 0~7, 행 1~3).
        $options->mergeCells(0, 1, 7, 1);
        $options->mergeCells(0, 2, 7, 2);
        $options->mergeCells(0, 3, 7, 3);

        $thinBorder = new Border(
            new BorderPart(Border::TOP, 'D0D5DD', Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::BOTTOM, 'D0D5DD', Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::LEFT, 'D0D5DD', Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::RIGHT, 'D0D5DD', Border::WIDTH_THIN, Border::STYLE_SOLID),
        );

        $titleStyle = (new Style)->setFontBold()->setFontSize(18)->setFontColor(Color::WHITE)
            ->setBackgroundColor('2563EB')->setCellAlignment(CellAlignment::CENTER)->setShouldWrapText(false);
        $subStyle = (new Style)->setFontSize(11)->setFontColor('475569')
            ->setBackgroundColor('EEF2FF')->setCellAlignment(CellAlignment::CENTER);
        $summaryStyle = (new Style)->setFontBold()->setFontSize(12)->setFontColor('1E293B')
            ->setBackgroundColor('EEF2FF')->setCellAlignment(CellAlignment::CENTER);
        $headerStyle = (new Style)->setFontBold()->setFontSize(11)->setFontColor(Color::WHITE)
            ->setBackgroundColor('1E293B')->setCellAlignment(CellAlignment::CENTER)->setBorder($thinBorder);
        $cellStyle = (new Style)->setFontSize(11)->setBorder($thinBorder);
        $presentStyle = (new Style)->setFontSize(11)->setFontBold()->setFontColor('15803D')->setBorder($thinBorder)->setCellAlignment(CellAlignment::CENTER);
        $absentStyle = (new Style)->setFontSize(11)->setFontColor('B91C1C')->setBorder($thinBorder)->setCellAlignment(CellAlignment::CENTER);

        $siteLabel = $site === 'ALL' ? '전체 현장 (Global)' : $site;

        $path = tempnam(sys_get_temp_dir(), 'hr_att_').'.xlsx';
        $writer = new Writer($options);
        $writer->openToFile($path);

        $blank = array_fill(0, count(self::HEADERS), '');

        $writer->addRow(Row::fromValues(array_replace($blank, [0 => 'NAHSHON MEP · 출퇴근 현황 보고서']), $titleStyle));
        $writer->addRow(Row::fromValues(array_replace($blank, [0 => '현장: '.$siteLabel.'    기준일: '.$date]), $subStyle));
        $writer->addRow(Row::fromValues(array_replace($blank, [
            0 => '총원 '.$total.'명    ·    출근 '.$present.'명    ·    미출근 '.$absent.'명    ·    생성 '.Carbon::now()->format('Y-m-d H:i'),
        ]), $summaryStyle));
        $writer->addRow(Row::fromValues($blank));
        $writer->addRow(Row::fromValues(self::HEADERS, $headerStyle));

        $hasRow = false;
        foreach ($companies as $company) {
            foreach ($company['teams'] ?? [] as $team) {
                foreach ($team['members'] ?? [] as $member) {
                    $hasRow = true;
                    $isOpen = (bool) ($member['isOpen'] ?? false);
                    $statusText = $isOpen ? '근무중' : (($member['todayOut'] ?? null) ? '퇴근완료' : '미출근');

                    $writer->addRow(Row::fromValuesWithStyles([
                        $company['name'] ?? '-',
                        $team['team'] ?? '-',
                        $member['nameEn'] ?? ($member['name'] ?? '-'),
                        $member['badgeId'] ?? '-',
                        $member['role'] ?? '-',
                        $member['todayIn'] ?? '-',
                        $member['todayOut'] ?? '-',
                        $statusText,
                    ], $cellStyle, [
                        7 => $isOpen ? $presentStyle : $absentStyle,
                    ]));
                }
            }
        }

        if (! $hasRow) {
            $writer->addRow(Row::fromValues(array_replace($blank, [0 => '해당 기준일에 출퇴근 대상 인원이 없습니다.']), $cellStyle));
        }

        $writer->close();

        $fileName = '출퇴근현황_'.($site === 'ALL' ? 'ALL' : $site).'_'.$date.'.xlsx';

        return response()->download($path, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}

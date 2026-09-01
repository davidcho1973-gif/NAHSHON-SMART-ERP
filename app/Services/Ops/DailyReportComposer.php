<?php

namespace App\Services\Ops;

use App\Models\DailyClosingReport;
use App\Support\Org;
use Illuminate\Support\Carbon;

/**
 * 보고서를 한 장의 HTML 로 조립한다 — 화면 미리보기·인쇄·메일 본문이 모두 이것 하나다.
 *
 * 왜 첨부가 아니라 <b>본문</b>인가. 원청 담당자는 하루에 메일을 수십 통 받고, 첨부를
 * 열어야 내용을 알 수 있는 보고는 대개 나중으로 밀린다. 그래서 메일을 여는 순간
 * 보고서가 그대로 보이게 하고, 첨부는 사진과 인쇄용 사본으로만 쓴다.
 *
 * 메일 클라이언트(특히 아웃룩)는 CSS 를 거의 지원하지 않으므로 표와 인라인 스타일만
 * 쓴다. flex·grid·외부 스타일시트는 여기서 쓸 수 없다 — 화면에서 예뻐 보여도
 * 받는 쪽에서 무너지면 그건 보고서가 아니다.
 */
class DailyReportComposer
{
    private const INK = '#111827';

    private const MUTED = '#6b7280';

    private const LINE = '#d1d5db';

    private const HEAD_BG = '#f3f4f6';

    private const ACCENT = '#1e3a5f';

    /**
     * 아침 작업계획서.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function plan(DailyClosingReport $report): array
    {
        $plan = $report->plan ?: [];
        $date = $report->report_date->toDateString();
        $siteName = $report->site?->name ?: '전 현장';

        $subject = sprintf('[%s] 일일 작업계획서 Daily Work Plan — %s', $siteName, $date);

        $body = '';
        $body .= $this->kv([
            '날씨 Weather' => trim(($plan['weather'] ?? '').' '.($plan['temperature'] ?? '')),
            'TBM 시각 TBM Time' => (string) ($plan['tbmTime'] ?? ''),
            'TBM 진행자 Leader' => (string) ($plan['tbmLeader'] ?? ''),
            'TBM 참석 Attendees' => ($plan['tbmHeadcount'] ?? 0) ? $plan['tbmHeadcount'].'명' : '',
        ]);

        if (trim((string) ($plan['workScope'] ?? '')) !== '') {
            $body .= $this->section('금일 작업 개요', 'Scope of Work');
            $body .= $this->paragraph((string) $plan['workScope']);
        }

        $crews = $plan['crews'] ?? [];
        if ($crews !== []) {
            $total = array_sum(array_map(fn ($c): int => (int) ($c['headcount'] ?? 0), $crews));
            $body .= $this->section('공종별 투입 인원', 'Manpower by Trade — 계 '.$total.'명');
            $body .= $this->table(
                ['업체 Company', '공종 Trade', '인원', '작업 위치 Location', '작업 내용 Work'],
                array_map(fn ($c): array => [
                    (string) ($c['company'] ?? ''),
                    (string) ($c['trade'] ?? ''),
                    (string) ($c['headcount'] ?? ''),
                    (string) ($c['location'] ?? ''),
                    (string) ($c['work'] ?? ''),
                ], $crews),
                [null, null, '48px', null, null],
            );
        }

        $hazards = $plan['hazards'] ?? [];
        if ($hazards !== []) {
            $body .= $this->section('위험요인 및 대책', 'PTP / JHA — Hazards & Controls');
            $body .= $this->table(
                ['위험요인 Hazard', '안전 대책 Control'],
                array_map(fn ($h): array => [
                    (string) ($h['hazard'] ?? ''),
                    (string) ($h['control'] ?? ''),
                ], $hazards),
            );
        }

        $permits = $plan['permits'] ?? [];
        if ($permits !== []) {
            $body .= $this->section('작업허가서', 'Permits to Work');
            $body .= $this->table(
                ['번호 No.', '종류 Type', '작업 Title'],
                array_map(fn ($p): array => [
                    (string) ($p['no'] ?? ''),
                    (string) ($p['type'] ?? ''),
                    (string) ($p['title'] ?? ''),
                ], $permits),
            );
        }

        $equipment = $plan['equipment'] ?? [];
        if ($equipment !== []) {
            $body .= $this->section('금일 사용 장비', 'Equipment in Use');
            $body .= $this->table(
                ['장비 Equipment', '번호 No.', '용도 Use'],
                array_map(fn ($e): array => [
                    (string) ($e['name'] ?? ''),
                    (string) ($e['code'] ?? ''),
                    (string) ($e['use'] ?? ''),
                ], $equipment),
            );
        }

        if (trim((string) ($plan['notes'] ?? '')) !== '') {
            $body .= $this->section('특이사항 · 요청', 'Notes & Requests');
            $body .= $this->paragraph((string) $plan['notes']);
        }

        if ($body === '') {
            $body = $this->paragraph('작성된 내용이 없습니다.');
        }

        return [
            'subject' => $subject,
            'html' => $this->wrap('일일 작업계획서', 'DAILY WORK PLAN', $siteName, $date, $body, $report, 'DWP'),
            'text' => $this->plainPlan($plan, $siteName, $date),
        ];
    }

    /**
     * 저녁 일일 마감보고서.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function closing(DailyClosingReport $report): array
    {
        $m = $report->metrics ?: [];
        $n = $report->narrative ?: [];
        $f = $report->hasFieldReport() ? $report->fieldReport() : [];
        $date = $report->report_date->toDateString();
        $siteName = $report->site?->name ?: '전 현장';

        $subject = sprintf('[%s] 일일 작업보고서 Daily Construction Report — %s', $siteName, $date);

        $body = '';

        // 한 줄 요약을 맨 앞에. 메일 목록의 미리보기에서도 이게 먼저 보인다.
        if (filled($n['headline'] ?? null)) {
            $body .= '<p style="margin:0 0 16px;padding:12px 14px;background:#eff6ff;'
                .'border-left:3px solid '.self::ACCENT.';font-size:14px;color:'.self::INK.';'
                .'font-weight:600;line-height:1.6">'.e((string) $n['headline']).'</p>';
        }

        $labor = $m['labor'] ?? [];

        // 머리말의 출역 인원. `final` 은 QR 실적을 바탕으로 한 확정치라, 게이트 QR 이
        // 아직 안 도는 현장에서는 0 이 나온다. 그때 머리말에 "0명" 을 적으면 아래
        // 표의 "6명" 과 대놓고 어긋난다 — 숫자 하나가 어긋나면 보고서 전체를 못 믿는다.
        // 확정치가 있으면 그것을, 없으면 현장 보고를 쓰되 출처를 밝힌다.
        $headcount = '';
        if ((int) ($labor['final'] ?? 0) > 0) {
            $headcount = $labor['final'].'명';
        } elseif ((int) ($labor['reported'] ?? 0) > 0) {
            $headcount = $labor['reported'].'명 (현장 보고 기준)';
        }

        // 공정률이 둘일 수 있다: 현장이 손으로 적은 것과, 공정표에서 계산된 것.
        // 하나로 합치지 않는다 — 어긋나는 순간이 곧 관리 포인트이고, 합쳐 버리면
        // 그 어긋남이 보이지 않는다.
        $schedule = $m['schedule'] ?? [];
        $scheduleRate = $schedule['rate'] ?? null;

        $body .= $this->kv([
            '날씨 Weather' => trim((string) (($f['weather'] ?? '').' '.($f['temperature'] ?? ''))),
            '금일 출역 Manpower' => $headcount,
            '진도율 Progress' => isset($f['progressRate']) && $f['progressRate'] > 0 ? $f['progressRate'].'%' : '',
            '공정표 기준 Schedule' => $scheduleRate !== null
                ? sprintf('%d%% (작업 %d건 중 %d건 완료)', (int) $scheduleRate, (int) ($schedule['tasks'] ?? 0), (int) ($schedule['done'] ?? 0))
                : '',
            'TBM' => array_key_exists('tbmCompleted', $f) ? ($f['tbmCompleted'] ? '실시 Completed' : '미실시 NOT DONE') : '',
        ]);

        // ── 인원: 보고와 QR 실적의 차이는 원청이 가장 먼저 보는 숫자다.
        if ($labor !== []) {
            $rows = array_map(fn ($c): array => [
                (string) ($c['company'] ?? ''),
                (string) ($c['trade'] ?? ''),
                (string) ($c['headcount'] ?? ''),
            ], $labor['byCompany'] ?? []);

            $body .= $this->section('출역 인원', 'Manpower');
            if ($rows !== []) {
                $body .= $this->table(['업체 Company', '공종 Trade', '인원'], $rows, [null, null, '60px']);
            }
            $body .= $this->kv([
                '현장 보고 Reported' => ($labor['reported'] ?? 0).'명',
                'QR 실적 Scanned' => ($labor['actualQr'] ?? 0).'명',
                '차이 Gap' => (($labor['gap'] ?? 0) === 0 ? '없음' : sprintf('%+d명 — 출역 확인 필요', $labor['gap'])),
                '최종 확정 Final' => ($labor['final'] ?? 0).'명',
            ]);
        }

        // ── 공종별 보고: 오늘 누가 무엇을 냈는가. 이 표가 없으면 보고서에 숫자만 남고
        // "이 60% 는 누가 본 겁니까" 에 답할 사람이 없다. 안 낸 공종은 안 낸 채로 적는다 —
        // 빠진 줄을 감추면 원청은 그 공종이 오늘 쉰 줄 안다.
        $trades = $m['tradeReports'] ?? [];
        if (($trades['rows'] ?? []) !== []) {
            $rows = array_map(fn (array $t): array => [
                (string) ($t['trade'] ?? ''),
                ($t['submitted'] ?? false)
                    ? '제출 '.trim((string) ($t['submittedAt'] ?? ''))
                    : '미제출 NOT SUBMITTED',
                ((int) ($t['headcount'] ?? 0)).'명',
                (string) ($t['submittedBy'] ?? ''),
                implode("\n", array_slice((array) ($t['highlights'] ?? []), 0, 4)),
            ], $trades['rows']);

            $body .= $this->section('공종별 보고', 'Reports by Trade — '
                .(int) ($trades['submitted'] ?? 0).'/'.(int) ($trades['total'] ?? 0));
            $body .= $this->table(
                ['공종 Trade', '제출 Status', '인원', '보고자 Reported by', '보고 내용 Contents'],
                $rows,
                ['110px', '120px', '52px', '110px', null],
            );

            if (($trades['missingTrades'] ?? []) !== []) {
                $body .= $this->kv([
                    '미제출 공종 Missing' => implode(', ', $trades['missingTrades'])
                        .' — 해당 공종의 금일 실적은 이 보고서에 포함되지 않았습니다.',
                ]);
            }
        }

        // ── 오늘 한 일: 현장이 쓴 것이 먼저, AI 가 집계에서 찾아낸 것이 뒤.
        $done = [];
        if (filled($f['workToday'] ?? null)) {
            $done[] = (string) $f['workToday'];
        }
        foreach (($n['done'] ?? []) as $d) {
            if (is_string($d) && trim($d) !== '') {
                $done[] = $d;
            }
        }
        if ($done !== []) {
            $body .= $this->section('금일 작업 실적', 'Work Performed');
            $body .= $this->bullets($done);
        }

        if (filled($n['progressNote'] ?? null)) {
            $body .= $this->section('공정 진행', 'Progress');
            $body .= $this->paragraph((string) $n['progressNote']);
        }

        // ── 장비·안전은 집계에 새로 들어온 블록이라 없을 수도 있다(예전 보고서).
        // 가동한 장비가 있을 때만 낸다. "0대 가동 / 80대 보유" 는 원청에게 아무것도
        // 알려 주지 않고, 현장마다 장비 대장이 담는 것이 달라(703K 는 설치할 주방기구다)
        // 보유 대수를 «장비» 로 내보내면 오히려 틀린 말이 된다.
        $equipment = $m['equipment'] ?? [];
        if (($equipment['rows'] ?? []) !== []) {
            $body .= $this->section('장비 가동', 'Equipment in Operation');
            $body .= $this->table(
                ['장비 Equipment', '번호 No.', '상태 Status'],
                array_map(fn ($e): array => [
                    (string) ($e['name'] ?? ''),
                    (string) ($e['code'] ?? ''),
                    (string) ($e['status'] ?? ''),
                ], $equipment['rows']),
            );
            if ((int) ($equipment['maintenance'] ?? 0) > 0) {
                $body .= $this->kv(['정비 중 Maintenance' => $equipment['maintenance'].'대']);
            }
        }

        $safety = $m['safety'] ?? [];
        if ($safety !== []) {
            $body .= $this->section('안전 관리', 'Safety');
            $body .= $this->kv([
                // 작업카드가 아예 없는 날 "0건 중 0건 완료" 는 정보가 아니라 잡음이다.
                // TBM 실시 여부는 이미 머리말에 있으므로 여기서는 숫자가 있을 때만 쓴다.
                'TBM 작업카드' => (int) ($safety['cards'] ?? 0) > 0
                    ? sprintf('%d건 중 %d건 완료', (int) $safety['cards'], (int) ($safety['tbmDone'] ?? 0)) : '',
                '작업허가서 Permits' => (int) ($safety['permits'] ?? 0) > 0 ? $safety['permits'].'건 유효' : '',
                '안전 지적 Issues' => isset($safety['issues']) ? $safety['issues'].'건' : '',
            ]);
            if (filled($f['safetyNotes'] ?? null)) {
                $body .= $this->paragraph((string) $f['safetyNotes']);
            }
        }

        foreach ([
            ['issues', '이슈 · 지연 요인', 'Issues & Delays'],
            ['attention', '금일 확인 필요', 'Requires Attention'],
            ['tomorrow', '익일 작업 계획', 'Next Day Plan'],
        ] as [$key, $kr, $en]) {
            $list = array_values(array_filter((array) ($n[$key] ?? []), fn ($v): bool => is_string($v) && trim($v) !== ''));
            if ($key === 'tomorrow' && filled($f['workTomorrow'] ?? null)) {
                array_unshift($list, (string) $f['workTomorrow']);
            }
            if ($list !== []) {
                $body .= $this->section($kr, $en);
                $body .= $this->bullets($list);
            }
        }

        if (filled($n['summary'] ?? null)) {
            $body .= $this->section('종합 의견', 'Summary');
            $body .= $this->paragraph((string) $n['summary']);
        }

        $photos = $m['photos'] ?? [];
        if ((int) ($photos['count'] ?? 0) > 0) {
            $body .= $this->section('작업 사진', 'Photos');
            $body .= $this->paragraph(sprintf('%d장 첨부 — %s', $photos['count'], implode(', ', $photos['captions'] ?? [])));
        }

        if ($body === '') {
            $body = $this->paragraph('작성된 내용이 없습니다.');
        }

        return [
            'subject' => $subject,
            'html' => $this->wrap('일일 작업보고서', 'DAILY CONSTRUCTION REPORT', $siteName, $date, $body, $report, 'DCR'),
            'text' => $this->plainClosing($n, $f, $labor, $siteName, $date, $trades, $schedule),
        ];
    }

    // ── 조립 부품 ────────────────────────────────────────────────────────

    private function wrap(
        string $titleKr,
        string $titleEn,
        string $siteName,
        string $date,
        string $body,
        DailyClosingReport $report,
        string $prefix,
    ): string {
        $weekday = ['일', '월', '화', '수', '목', '금', '토'][Carbon::parse($date)->dayOfWeek];
        $company = Org::name();

        return '<div style="font-family:\'Malgun Gothic\',Arial,sans-serif;max-width:760px;margin:0 auto;'
            .'padding:24px;color:'.self::INK.';background:#ffffff">'

            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            .'style="border-bottom:2px solid '.self::ACCENT.';padding-bottom:12px;margin-bottom:18px">'
            .'<tr><td style="vertical-align:bottom">'
            .'<div style="font-size:20px;font-weight:700;color:'.self::ACCENT.'">'.e($titleKr).'</div>'
            .'<div style="font-size:11px;letter-spacing:1px;color:'.self::MUTED.';margin-top:2px">'.e($titleEn).'</div>'
            .'</td><td style="text-align:right;vertical-align:bottom;font-size:12px;color:'.self::MUTED.'">'
            .'<div style="font-weight:600;color:'.self::INK.';font-size:13px">'.e($siteName).'</div>'
            .e($date).' ('.$weekday.')<br>'.e($company)
            .'</td></tr></table>'

            .$body

            .'<p style="margin:24px 0 0;padding-top:12px;border-top:1px solid '.self::LINE.';'
            .'font-size:11px;color:'.self::MUTED.';line-height:1.6">'
            .'본 보고서는 '.e($company).' 시스템에서 자동 생성되었습니다. '
            // 문서번호는 문서함에 편철되는 번호와 같아야 한다 — 원청이 이 번호로 되묻는다.
            .'문서번호 '.e($prefix).'-'.e($report->site?->code ?: 'ALL').'-'.str_replace('-', '', $date)
            .' · 생성 '.now()->format('Y-m-d H:i')
            .'</p></div>';
    }

    private function section(string $kr, string $en): string
    {
        return '<h3 style="margin:22px 0 8px;font-size:13px;font-weight:700;color:'.self::ACCENT.'">'
            .e($kr).' <span style="font-weight:400;font-size:11px;color:'.self::MUTED.'">'.e($en).'</span></h3>';
    }

    /** @param array<string, string> $pairs */
    private function kv(array $pairs): string
    {
        $rows = '';
        foreach ($pairs as $k => $v) {
            if (trim((string) $v) === '') {
                continue;   // 빈 칸은 아예 안 보여 준다 — 빈칸투성이 보고서는 안 읽힌다
            }
            $rows .= '<tr>'
                .'<td style="padding:5px 10px;background:'.self::HEAD_BG.';border:1px solid '.self::LINE.';'
                .'font-size:12px;color:'.self::MUTED.';width:150px;white-space:nowrap">'.e($k).'</td>'
                .'<td style="padding:5px 10px;border:1px solid '.self::LINE.';font-size:12px">'.e($v).'</td>'
                .'</tr>';
        }

        return $rows === '' ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            .'style="border-collapse:collapse;margin:0 0 6px">'.$rows.'</table>';
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  list<string|null>  $widths
     */
    private function table(array $headers, array $rows, array $widths = []): string
    {
        if ($rows === []) {
            return '';
        }

        $head = '';
        foreach ($headers as $i => $h) {
            $w = $widths[$i] ?? null;
            $head .= '<th style="padding:6px 8px;background:'.self::HEAD_BG.';border:1px solid '.self::LINE.';'
                .'font-size:11px;color:'.self::MUTED.';text-align:left;font-weight:600'
                .($w ? ';width:'.$w : '').'">'.e($h).'</th>';
        }

        $body = '';
        foreach ($rows as $r) {
            $body .= '<tr>';
            foreach ($r as $cell) {
                $body .= '<td style="padding:6px 8px;border:1px solid '.self::LINE.';font-size:12px;'
                    .'vertical-align:top">'.nl2br(e((string) $cell)).'</td>';
            }
            $body .= '</tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            .'style="border-collapse:collapse;margin:0 0 8px"><tr>'.$head.'</tr>'.$body.'</table>';
    }

    private function paragraph(string $text): string
    {
        return '<p style="margin:0 0 10px;font-size:12px;line-height:1.7;color:'.self::INK.'">'
            .nl2br(e(trim($text))).'</p>';
    }

    /** @param list<string> $items */
    private function bullets(array $items): string
    {
        $li = '';
        foreach ($items as $i) {
            $li .= '<li style="margin:0 0 4px">'.nl2br(e(trim($i))).'</li>';
        }

        return '<ul style="margin:0 0 10px;padding-left:18px;font-size:12px;line-height:1.7;color:'.self::INK.'">'
            .$li.'</ul>';
    }

    // ── 평문 대체본 ─────────────────────────────────────────────────────
    // 메일 서버가 아직 없을 때 `mailto:` 로 넘기는데, mailto 본문에는 HTML 이
    // 들어가지 않는다. 그때 쓸 같은 내용의 글자 판본이다.

    /** @param array<string, mixed> $plan */
    private function plainPlan(array $plan, string $siteName, string $date): string
    {
        $out = ["[{$siteName}] 일일 작업계획서 — {$date}", ''];

        if (trim((string) ($plan['workScope'] ?? '')) !== '') {
            $out[] = '■ 금일 작업 개요';
            $out[] = (string) $plan['workScope'];
            $out[] = '';
        }
        if (($plan['crews'] ?? []) !== []) {
            $out[] = '■ 공종별 투입 인원';
            foreach ($plan['crews'] as $c) {
                $out[] = sprintf('- %s / %s / %s명 / %s / %s',
                    $c['company'] ?? '', $c['trade'] ?? '', $c['headcount'] ?? 0,
                    $c['location'] ?? '', $c['work'] ?? '');
            }
            $out[] = '';
        }
        if (($plan['hazards'] ?? []) !== []) {
            $out[] = '■ 위험요인 및 대책 (PTP/JHA)';
            foreach ($plan['hazards'] as $h) {
                $out[] = sprintf('- %s → %s', $h['hazard'] ?? '', $h['control'] ?? '');
            }
            $out[] = '';
        }
        if (trim((string) ($plan['notes'] ?? '')) !== '') {
            $out[] = '■ 특이사항';
            $out[] = (string) $plan['notes'];
        }

        return implode("\n", $out);
    }

    /**
     * @param  array<string, mixed>  $n
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $labor
     * @param  array<string, mixed>  $trades
     * @param  array<string, mixed>  $schedule
     */
    private function plainClosing(array $n, array $f, array $labor, string $siteName, string $date, array $trades = [], array $schedule = []): string
    {
        $out = ["[{$siteName}] 일일 작업보고서 — {$date}", ''];

        if (filled($n['headline'] ?? null)) {
            $out[] = (string) $n['headline'];
            $out[] = '';
        }
        $out[] = sprintf('■ 출역 인원: 최종 %d명 (보고 %d / QR %d)',
            (int) ($labor['final'] ?? 0), (int) ($labor['reported'] ?? 0), (int) ($labor['actualQr'] ?? 0));
        if (($f['progressRate'] ?? 0) > 0) {
            $out[] = '■ 진도율: '.$f['progressRate'].'%';
        }
        if (($schedule['rate'] ?? null) !== null) {
            $out[] = '■ 공정표 기준: '.(int) $schedule['rate'].'%';
        }
        $out[] = '';

        // 공종별 보고 — HTML 본문과 같은 내용이어야 한다. 평문만 보는 사람에게
        // 미제출 공종이 안 보이면 그 사람에게는 빠진 줄이 없는 보고서가 된다.
        if (($trades['rows'] ?? []) !== []) {
            $out[] = sprintf('■ 공종별 보고 (%d/%d 제출)',
                (int) ($trades['submitted'] ?? 0), (int) ($trades['total'] ?? 0));
            foreach ($trades['rows'] as $t) {
                $out[] = sprintf('- %s: %s%s%s',
                    (string) ($t['trade'] ?? ''),
                    ($t['submitted'] ?? false) ? '제출' : '미제출',
                    ($t['submittedBy'] ?? '') ? ' ('.$t['submittedBy'].')' : '',
                    ((array) ($t['highlights'] ?? [])) !== [] ? ' — '.implode('; ', array_slice((array) $t['highlights'], 0, 3)) : '',
                );
            }
            if (($trades['missingTrades'] ?? []) !== []) {
                $out[] = '  ※ 미제출: '.implode(', ', $trades['missingTrades']);
            }
            $out[] = '';
        }

        foreach ([['done', '금일 작업 실적'], ['issues', '이슈'], ['tomorrow', '익일 계획']] as [$k, $label]) {
            $list = array_filter((array) ($n[$k] ?? []), fn ($v): bool => is_string($v) && trim($v) !== '');
            if ($list !== []) {
                $out[] = '■ '.$label;
                foreach ($list as $l) {
                    $out[] = '- '.$l;
                }
                $out[] = '';
            }
        }

        if (filled($n['summary'] ?? null)) {
            $out[] = '■ 종합 의견';
            $out[] = (string) $n['summary'];
        }

        return implode("\n", $out);
    }
}

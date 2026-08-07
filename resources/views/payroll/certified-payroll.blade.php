<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certified Payroll (WH-347) · {{ $run->code }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, 'Segoe UI', Roboto, 'Malgun Gothic', sans-serif; color: #111; margin: 0; padding: 24px; background: #f4f4f5; }
        .sheet { background: #fff; max-width: 1180px; margin: 0 auto; padding: 28px 32px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .sub { font-size: 11px; color: #555; }
        .meta { font-size: 12px; text-align: right; line-height: 1.6; }
        .meta b { display: inline-block; min-width: 70px; color: #555; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: center; }
        th { background: #f1f5f9; font-weight: 700; }
        td.l { text-align: left; }
        td.r, th.r { text-align: right; font-variant-numeric: tabular-nums; }
        tfoot td { font-weight: 700; background: #f8fafc; }
        .statement { font-size: 10px; color: #444; margin-top: 18px; line-height: 1.5; border-top: 1px solid #e2e8f0; padding-top: 10px; }
        .toolbar { max-width: 1180px; margin: 0 auto 14px; display: flex; gap: 8px; }
        .btn { background: #4f46e5; color: #fff; border: 0; border-radius: 6px; padding: 8px 16px; font-size: 13px; cursor: pointer; }
        .btn.secondary { background: #e2e8f0; color: #1e293b; }
        @media print { body { background: #fff; padding: 0; } .toolbar { display: none; } .sheet { box-shadow: none; max-width: none; } }
    </style>
</head>
@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $dayLabel = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('m/d');
    $totals = [
        'hours' => array_sum(array_column($rows, 'totalHours')),
        'gross' => array_sum(array_column($rows, 'gross')),
        'net' => array_sum(array_column($rows, 'net')),
    ];
@endphp
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">🖨️ 인쇄 / PDF 저장</button>
        <button class="btn secondary" onclick="window.close()">닫기</button>
    </div>
    <div class="sheet">
        <div class="head">
            <div>
                <h1>인증 임금대장 · Certified Payroll (WH-347)</h1>
                <div class="sub">U.S. Department of Labor — Davis-Bacon weekly certified payroll</div>
            </div>
            <div class="meta">
                <div><b>Payroll No.</b> {{ $run->code }}</div>
                <div><b>Project</b> {{ $run->site_scope }}</div>
                <div><b>Period</b> {{ $run->period_start->format('Y-m-d') }} ~ {{ $run->period_end->format('Y-m-d') }}</div>
                <div><b>Workers</b> {{ count($rows) }}</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th rowspan="2">#</th>
                    <th rowspan="2">Name / Badge</th>
                    <th rowspan="2">Classification</th>
                    <th colspan="{{ count($dates) }}">Hours by day</th>
                    <th rowspan="2">Total</th>
                    <th rowspan="2">Rate</th>
                    <th rowspan="2">Gross</th>
                    <th rowspan="2">FICA</th>
                    <th rowspan="2">Medicare</th>
                    <th rowspan="2">Fed</th>
                    <th rowspan="2">State</th>
                    <th rowspan="2">401k</th>
                    <th rowspan="2">Net</th>
                </tr>
                <tr>
                    @foreach ($dates as $d)
                        <th>{{ $dayLabel($d) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $i => $row)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td class="l">{{ $row['name'] }}<br><span style="color:#64748b">#{{ $row['badgeId'] }} · {{ $row['division'] }}</span></td>
                        <td class="l">{{ $row['classification'] }}</td>
                        @foreach ($dates as $d)
                            <td>{{ $row['daily'][$d] > 0 ? rtrim(rtrim(number_format($row['daily'][$d], 1), '0'), '.') : '' }}</td>
                        @endforeach
                        <td class="r">{{ number_format($row['totalHours'], 1) }}</td>
                        <td class="r">${{ $fmt($row['rate']) }}</td>
                        <td class="r">${{ $fmt($row['gross']) }}</td>
                        <td class="r">{{ $fmt($row['fica']) }}</td>
                        <td class="r">{{ $fmt($row['medicare']) }}</td>
                        <td class="r">{{ $fmt($row['fedTax']) }}</td>
                        <td class="r">{{ $fmt($row['stateTax']) }}</td>
                        <td class="r">{{ $fmt($row['retirement']) }}</td>
                        <td class="r"><b>${{ $fmt($row['net']) }}</b></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($dates) + 11 }}" style="padding:20px;color:#64748b">이 기간에 산출된 급여 데이터가 없습니다.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="{{ count($dates) + 3 }}" class="r">합계 Totals</td>
                    <td class="r">{{ number_format($totals['hours'], 1) }}</td>
                    <td></td>
                    <td class="r">${{ $fmt($totals['gross']) }}</td>
                    <td colspan="5"></td>
                    <td class="r">${{ $fmt($totals['net']) }}</td>
                </tr>
            </tfoot>
        </table>

        <div class="statement">
            <b>Statement of Compliance.</b> 본 임금대장은 해당 기간 동안 각 근로자에게 지급된 임금이 Davis-Bacon Act 및 관련 규정에서 요구하는
            적용 임금률(prevailing wage) 이상임을 증명하기 위한 자료입니다. 공제 항목(FICA 6.2%, Medicare 1.45%, 연방·주 원천징수, 401(k))은
            적용 세율 기준으로 산출되었습니다. 실제 제출 시 원청·발주처가 요구하는 WH-347 서식과 서명란을 함께 첨부하십시오.
        </div>
    </div>
</body>
</html>

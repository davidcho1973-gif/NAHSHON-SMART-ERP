<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payslip · {{ $payslip->employee?->name }} · {{ $payslip->run?->code }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, 'Segoe UI', Roboto, 'Malgun Gothic', sans-serif; color: #111; margin: 0; padding: 24px; background: #f4f4f5; }
        .sheet { background: #fff; max-width: 660px; margin: 0 auto; padding: 28px 32px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
        .head { border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 18px; margin: 0; }
        .sub { font-size: 11px; color: #555; margin-top: 2px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 18px; font-size: 12px; margin-bottom: 18px; }
        .grid div span { color: #64748b; display: inline-block; min-width: 84px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 6px; }
        td { padding: 6px 4px; border-bottom: 1px solid #eef2f7; }
        td.r { text-align: right; font-variant-numeric: tabular-nums; }
        .sect { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .04em; margin: 14px 0 4px; }
        .net { display: flex; justify-content: space-between; align-items: center; background: #4f46e5; color: #fff; padding: 12px 16px; border-radius: 8px; margin-top: 14px; font-size: 16px; font-weight: 800; }
        .toolbar { max-width: 660px; margin: 0 auto 14px; display: flex; gap: 8px; }
        .btn { background: #4f46e5; color: #fff; border: 0; border-radius: 6px; padding: 8px 16px; font-size: 13px; cursor: pointer; }
        .btn.secondary { background: #e2e8f0; color: #1e293b; }
        @media print { body { background: #fff; padding: 0; } .toolbar { display: none; } .sheet { box-shadow: none; } }
    </style>
</head>
@php
    $fmt = fn ($n) => '$' . number_format((float) $n, 2);
    $basisLabel = ['hourly' => '시급', 'salary' => '연봉', 'daily' => '일급'][$payslip->snap_pay_type] ?? $payslip->snap_pay_type;
@endphp
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">🖨️ 인쇄 / PDF 저장</button>
        <button class="btn secondary" onclick="window.close()">닫기</button>
    </div>
    <div class="sheet">
        <div class="head">
            <h1>급여 명세서 · Payslip</h1>
            <div class="sub">{{ $payslip->run?->code }} · {{ $payslip->run?->period_start?->format('Y-m-d') }} ~ {{ $payslip->run?->period_end?->format('Y-m-d') }}</div>
        </div>

        <div class="grid">
            <div><span>성명</span> {{ $payslip->employee?->name }}</div>
            <div><span>Badge</span> {{ $payslip->employee?->badge_number ?: $payslip->employee?->employee_number }}</div>
            <div><span>소속</span> {{ $payslip->employee?->company?->name ?: '-' }}</div>
            <div><span>직군</span> {{ $payslip->snap_division ?: '-' }}</div>
            <div><span>임금 형태</span> {{ $basisLabel }}</div>
            <div><span>직종</span> {{ $payslip->snap_trade ?: '-' }}</div>
        </div>

        <div class="sect">근로 / 지급 (Earnings)</div>
        <table>
            <tr><td>정규 (Regular)</td><td class="r">{{ number_format($payslip->regular_hours, 1) }} h</td><td class="r">{{ $fmt($payslip->applied_rate) }}/h</td></tr>
            <tr><td>초과근무 (OT 1.5×)</td><td class="r">{{ number_format($payslip->overtime_hours, 1) }} h</td><td class="r">{{ $fmt($payslip->applied_rate * 1.5) }}/h</td></tr>
            @if ((float) $payslip->per_diem > 0)
                <tr><td>Per Diem (비과세)</td><td class="r"></td><td class="r">{{ $fmt($payslip->per_diem) }}</td></tr>
            @endif
            <tr><td><b>총지급 (Gross)</b></td><td></td><td class="r"><b>{{ $fmt($payslip->gross_pay) }}</b></td></tr>
        </table>

        <div class="sect">공제 (Deductions)</div>
        <table>
            <tr><td>Social Security (FICA 6.2%)</td><td></td><td class="r">-{{ number_format($payslip->fica, 2) }}</td></tr>
            <tr><td>Medicare (1.45%)</td><td></td><td class="r">-{{ number_format($payslip->medicare, 2) }}</td></tr>
            <tr><td>연방 소득세 (Federal)</td><td></td><td class="r">-{{ number_format($payslip->fed_tax, 2) }}</td></tr>
            <tr><td>주 소득세 (State)</td><td></td><td class="r">-{{ number_format($payslip->state_tax, 2) }}</td></tr>
            <tr><td>401(k)</td><td></td><td class="r">-{{ number_format($payslip->retirement_401k, 2) }}</td></tr>
        </table>

        <div class="net">
            <span>실지급액 · Net Pay</span>
            <span>{{ $fmt($payslip->net_pay) }} {{ $payslip->currency }}</span>
        </div>
    </div>
</body>
</html>

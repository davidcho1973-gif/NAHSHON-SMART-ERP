<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>W-9 — {{ $employee->name }}</title>
    {{--
        인쇄용 W-9. IRS 양식(Rev. 3-2024)의 배치를 따르되, 우리가 아는 값은 채워서 낸다.

        채우지 않는 두 칸이 있다 — TIN 과 서명.
        TIN 은 우리가 가진 적이 없고(작성자가 직접 넣는다), 서명은 "위증 시 처벌을
        감수한다" 는 본인 진술이다. 대신 써 주면 그건 서류 위조다. 그래서 미제출 상태에서
        인쇄하면 그 두 칸만 빈 줄로 남고, 현장에서 손으로 받으면 된다.

        영어로 쓴다 — 이 종이는 IRS 서류이고, 감사에서 읽는 사람은 영어를 읽는다.
        작성 안내만 한국어·스페인어를 곁들인다.
    --}}
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 22px; background: #e9eaec; color: #000;
            font-family: 'Times New Roman', Times, serif; font-size: 12px; line-height: 1.35;
        }
        .sheet { width: min(100%, 780px); margin: 0 auto; background: #fff; padding: 26px 30px 30px; box-shadow: 0 10px 30px rgba(0,0,0,.12); }

        .head { display: flex; gap: 14px; border-bottom: 2.5px solid #000; padding-bottom: 8px; }
        .head .fno { font-size: 26px; font-weight: bold; letter-spacing: -.02em; width: 92px; }
        .head .fno small { display: block; font-size: 9.5px; font-weight: normal; letter-spacing: 0; line-height: 1.25; }
        .head .mid { flex: 1; text-align: center; }
        .head .mid b { font-size: 15px; }
        .head .mid div { font-size: 10.5px; }
        .head .right { width: 130px; text-align: right; font-size: 10px; }

        .banner { border: 1.5px solid #000; border-top: 0; padding: 7px 10px; font-size: 10.5px; background: #f3f3f3; }

        .lines { border: 1.5px solid #000; border-top: 0; }
        .line { display: flex; border-bottom: 1px solid #000; min-height: 34px; }
        .line:last-child { border-bottom: 0; }
        .line .no { width: 26px; flex: none; border-right: 1px solid #000; text-align: center; font-weight: bold; padding-top: 6px; font-size: 11px; }
        .line .body { flex: 1; padding: 5px 9px; }
        .lbl { font-size: 9.5px; color: #333; }
        .val { font-size: 14px; min-height: 19px; padding-top: 2px; }
        .val.blank { border-bottom: 1px solid #999; min-height: 20px; }

        .cls { display: flex; flex-wrap: wrap; gap: 3px 16px; margin-top: 4px; }
        .cls label { font-size: 11px; display: flex; align-items: center; gap: 5px; }
        .box { width: 11px; height: 11px; border: 1.2px solid #000; display: inline-block; position: relative; }
        .box.on::after { content: "X"; position: absolute; left: 1px; top: -3px; font-size: 11px; font-weight: bold; }

        .part { border: 1.5px solid #000; border-top: 0; }
        .part-h { background: #000; color: #fff; font-weight: bold; padding: 3px 9px; font-size: 11px; }
        .part-b { padding: 8px 10px; }
        .tin { font-family: 'Courier New', monospace; font-size: 19px; letter-spacing: .14em; }
        .tin.blank { border-bottom: 1px solid #999; display: inline-block; min-width: 210px; height: 24px; }

        .sig { display: flex; gap: 16px; align-items: flex-end; margin-top: 8px; }
        .sig div { flex: 1; }
        .sig .rule { border-bottom: 1px solid #000; height: 20px; }
        .sig .cap { font-size: 9.5px; margin-top: 2px; }

        .stamp {
            margin: 14px 0 0; padding: 9px 12px; border: 1.5px dashed #666;
            font-family: system-ui, -apple-system, 'Malgun Gothic', sans-serif; font-size: 11px; line-height: 1.55;
        }
        .stamp b { font-size: 12px; }
        .stamp.todo { border-color: #b45309; background: #fffbeb; }
        .stamp.done { border-color: #15803d; background: #f0fdf4; }

        .meta { margin-top: 12px; font-family: system-ui, sans-serif; font-size: 10px; color: #555; display: flex; justify-content: space-between; gap: 10px; }

        .actions { width: min(100%, 780px); margin: 14px auto 0; display: flex; gap: 9px; font-family: system-ui, sans-serif; }
        .actions button, .actions a {
            appearance: none; border: 1px solid #94a3b8; background: #fff; color: #1e293b;
            border-radius: 8px; padding: 10px 14px; font-size: 13px; font-weight: 700;
            text-decoration: none; cursor: pointer;
        }
        .actions .primary { background: #0f172a; color: #fff; border-color: #0f172a; }

        @media print {
            body { background: #fff; padding: 0; }
            .sheet { box-shadow: none; width: auto; padding: 12mm; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
@php
    $c = $values['tax_classification'] ?? 'individual';
@endphp

<main class="sheet">
    <div class="head">
        <div class="fno">W-9<small>(Rev. March 2024)<br>Dept. of the Treasury<br>Internal Revenue Service</small></div>
        <div class="mid">
            <b>Request for Taxpayer<br>Identification Number and Certification</b>
            <div>Go to <i>www.irs.gov/FormW9</i> for instructions and the latest information.</div>
        </div>
        <div class="right">Give form to the<br>requester. Do not<br>send to the IRS.</div>
    </div>

    <div class="banner">
        <b>Requester:</b> {{ $employee->company?->name ?? 'DASOL PRISM' }}
        @if ($employee->site) &nbsp;·&nbsp; Site: {{ $employee->site->code }} @endif
    </div>

    <div class="lines">
        <div class="line">
            <div class="no">1</div>
            <div class="body">
                <div class="lbl">Name of entity/individual. An entry is required.</div>
                <div class="val {{ $values['legal_name'] ? '' : 'blank' }}">{{ $values['legal_name'] }}</div>
            </div>
        </div>
        <div class="line">
            <div class="no">2</div>
            <div class="body">
                <div class="lbl">Business name/disregarded entity name, if different from above.</div>
                <div class="val {{ $values['business_name'] ? '' : 'blank' }}">{{ $values['business_name'] }}</div>
            </div>
        </div>
        <div class="line">
            <div class="no">3a</div>
            <div class="body">
                <div class="lbl">Check the appropriate box for federal tax classification.</div>
                <div class="cls">
                    @foreach ($classifications as $key => $label)
                        <label><span class="box {{ $c === $key ? 'on' : '' }}"></span>{{ $label }}@if ($key === 'llc' && $c === 'llc' && ! empty($values['llc_tax_class'])) ({{ $values['llc_tax_class'] }})@endif</label>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="line">
            <div class="no">5</div>
            <div class="body">
                <div class="lbl">Address (number, street, and apt. or suite no.)</div>
                <div class="val {{ $values['address'] ? '' : 'blank' }}">{{ $values['address'] }}</div>
            </div>
        </div>
        <div class="line">
            <div class="no">6</div>
            <div class="body">
                <div class="lbl">City, state, and ZIP code</div>
                <div class="val {{ $values['city_state_zip'] ? '' : 'blank' }}">{{ $values['city_state_zip'] }}</div>
            </div>
        </div>
    </div>

    <div class="part">
        <div class="part-h">Part I &nbsp; Taxpayer Identification Number (TIN)</div>
        <div class="part-b">
            <div class="lbl" style="margin-bottom:5px">
                Social security number (SSN) or Employer identification number (EIN)
            </div>
            @if ($tin)
                <div class="tin">{{ $form->tin_type === 'ein' ? substr($tin, 0, 2).'-'.substr($tin, 2) : substr($tin, 0, 3).'-'.substr($tin, 3, 2).'-'.substr($tin, 5) }}</div>
            @elseif ($maskedTin)
                <div class="tin">{{ $maskedTin }}</div>
            @else
                <div class="tin blank"></div>
            @endif
        </div>
    </div>

    <div class="part">
        <div class="part-h">Part II &nbsp; Certification</div>
        <div class="part-b">
            <div style="font-size:10.5px">
                Under penalties of perjury, I certify that: (1) The number shown on this form is my correct taxpayer
                identification number, and (2) I am not subject to backup withholding, and (3) I am a U.S. citizen or
                other U.S. person, and (4) The FATCA code(s) entered on this form (if any) indicating that I am exempt
                from FATCA reporting is correct.
            </div>
            <div class="sig">
                <div>
                    <div class="rule">{{ $form?->signature_name }}</div>
                    <div class="cap">Signature of U.S. person</div>
                </div>
                <div style="max-width:190px">
                    <div class="rule">{{ $form?->certified_at?->format('Y-m-d') }}</div>
                    <div class="cap">Date</div>
                </div>
            </div>
        </div>
    </div>

    @if ($form)
        <div class="stamp done">
            <b>제출 완료 · Submitted</b><br>
            {{ $form->certified_at?->format('Y-m-d H:i') }} 에 {{ $form->signature_name }} 명의로 전자 서명되었습니다.
            @unless ($tin)
                TIN 은 뒤 4자리만 인쇄됩니다 — 전체가 필요하면 아래 <b>TIN 전체 포함 인쇄</b> 를 쓰세요.
            @endunless
        </div>
    @else
        <div class="stamp todo">
            <b>아직 제출되지 않았습니다 · Not submitted yet</b><br>
            아는 칸은 미리 채웠습니다. <b>TIN 과 서명</b>은 본인이 직접 써야 합니다 —
            서명은 “위증 시 처벌을 감수한다”는 본인 진술이라 대신 쓸 수 없습니다.<br>
            이 종이에 손으로 받으시거나, 아래 <b>작성 링크</b>를 작업자에게 보내세요.<br>
            <span style="color:#555">The TIN and signature must be provided by the worker in person or online.</span>
        </div>
    @endif

    <div class="meta">
        <span>{{ $employee->name }} · {{ $employee->employee_number }}</span>
        <span>DASOL PRISM SMART ERP · 인쇄 {{ now()->format('Y-m-d H:i') }}</span>
    </div>
</main>

<div class="actions">
    <button type="button" class="primary" onclick="window.print()">인쇄</button>
    @if ($form && ! $tin)
        <a href="{{ request()->fullUrlWithQuery(['full' => 1]) }}">TIN 전체 포함 인쇄</a>
    @endif
    @unless ($form)
        <a href="{{ $signUrl }}" target="_blank" rel="noopener">작성 링크 열기</a>
    @endunless
</div>
</body>
</html>

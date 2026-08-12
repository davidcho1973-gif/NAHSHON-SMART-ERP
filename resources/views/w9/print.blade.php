<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>W-9 @if ($employee)— {{ $employee->name }}@else(빈 양식)@endif</title>
    {{--
        IRS Form W-9 (Rev. 3-2024) 를 그대로 옮긴 인쇄본.

        왜 그대로여야 하나 — 이건 우리 서식이 아니라 국세청 서식이다. 감사·1099 신고에서
        읽는 사람은 이 배치를 외우고 있고, 칸이 옮겨져 있으면 "다른 서류" 로 본다.
        그래서 서체(Helvetica), 칸 위치, 줄 번호, Part 바, 각주까지 원본을 따른다.

        우리가 하는 일은 하나뿐이다 — 아는 값을 제자리에 얹는 것.
        TIN 과 서명은 얹지 않는다. TIN 은 가진 적이 없고, 서명은 "위증 시 처벌을
        감수한다" 는 본인 진술이라 대신 쓰면 서류 위조다.
    --}}
    <style>
        @page { size: letter portrait; margin: 0.3in; }

        :root { color-scheme: light; }
        * { box-sizing: border-box; }

        body {
            margin: 0; padding: 18px; background: #d9dadd; color: #000;
            /* IRS 양식은 Helvetica 계열이다. Times 로 두면 한눈에 다른 서류로 보인다. */
            font-family: Helvetica, Arial, 'Liberation Sans', sans-serif;
            font-size: 7pt; line-height: 1.15;
        }

        .page {
            width: 7.9in; margin: 0 auto; background: #fff; padding: 0;
            box-shadow: 0 8px 26px rgba(0,0,0,.18);
        }

        table.f { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .bx { border: 1px solid #000; }
        .bt { border-top: 1px solid #000; }
        .bb { border-bottom: 1px solid #000; }
        .bl { border-left: 1px solid #000; }
        .br { border-right: 1px solid #000; }
        .thick { border-bottom: 2.5px solid #000; }

        /* ── 머리 ───────────────────────────────────────────────── */
        .hd { width: 100%; border-collapse: collapse; }
        .hd td { padding: 2px 5px 3px; }
        .hd .c1 { width: 1.35in; }
        .hd .fw9 { font-size: 30pt; font-weight: bold; letter-spacing: -1px; line-height: .95; }
        .hd .fsm { font-size: 6.5pt; }
        .hd .c2 { text-align: center; }
        .hd .t1 { font-size: 13.5pt; font-weight: bold; line-height: 1.1; }
        .hd .t2 { font-size: 7.5pt; margin-top: 2px; }
        .hd .c3 { width: 1.5in; font-size: 7.5pt; font-weight: bold; line-height: 1.25; }

        .before { font-size: 7pt; padding: 2px 5px; }
        .before b { font-weight: bold; }

        /* ── 본문 칸 ─────────────────────────────────────────────── */
        .side {
            width: 15px; text-align: center; font-size: 6.5pt; font-weight: bold;
            writing-mode: vertical-rl; transform: rotate(180deg);
            padding: 6px 0; white-space: nowrap;
        }
        .ln { padding: 2px 5px 1px; }
        .no { width: 16px; text-align: center; font-weight: bold; font-size: 7pt; padding-top: 2px; }
        .lbl { font-size: 6.8pt; line-height: 1.2; }
        .fill { font-size: 10pt; font-family: Arial, Helvetica, sans-serif; min-height: 15px; padding-top: 3px; }
        .h1r { height: 30px; }
        .h2r { height: 26px; }

        .cbrow { display: flex; flex-wrap: wrap; gap: 2px 10px; margin-top: 3px; }
        .cb { display: flex; align-items: flex-start; gap: 3px; font-size: 6.8pt; }
        .sq { width: 8px; height: 8px; border: 1px solid #000; flex: none; margin-top: 1px; position: relative; }
        .sq.on::after { content: "X"; position: absolute; left: 0.5px; top: -3px; font-size: 9pt; font-weight: bold; line-height: 1; }
        .dots { letter-spacing: 2px; }

        /* ── Part 바 ─────────────────────────────────────────────── */
        .part { background: #000; color: #fff; font-weight: bold; font-size: 8.5pt; padding: 2px 5px; }
        .part span { display: inline-block; min-width: 52px; }

        /* TIN 칸 — 원본은 한 칸에 한 자리씩 들어가는 격자다. */
        .tinlbl { font-size: 6.8pt; border: 1px solid #000; padding: 1px 4px; font-weight: normal; }
        .grid { display: flex; align-items: center; gap: 2px; margin-top: 2px; }
        .cell {
            width: 15px; height: 19px; border: 1px solid #000;
            text-align: center; font-size: 11pt; font-family: 'Courier New', monospace;
            line-height: 18px;
        }
        .dash { width: 8px; text-align: center; font-size: 10pt; }
        .orword { font-size: 7.5pt; font-weight: bold; margin: 3px 0 1px; }

        .cert { font-size: 6.9pt; line-height: 1.28; padding: 3px 5px; }
        .cert ol { margin: 2px 0 0; padding-left: 14px; }
        .cert li { margin-bottom: 1px; }

        .signhere { font-size: 8.5pt; font-weight: bold; width: 42px; text-align: center; padding-top: 6px; }
        /* 값이 줄 아래로 떨어지면 양식이 아니라 메모처럼 보인다. 밑줄 바로 위에 앉힌다.
           서명 칸은 손으로 쓸 수 있게 높이를 넉넉히 준다. */
        .sigrow { display: flex; align-items: flex-end; gap: 5px; padding: 3px 0 2px; }
        .sigcap { font-size: 6.8pt; font-weight: bold; line-height: 1.15; flex: none; padding-bottom: 2px; }
        .rule {
            flex: 1; border-bottom: 1px solid #000; height: 30px;
            display: flex; align-items: flex-end; padding: 0 3px 2px;
            font-size: 11pt; font-family: Arial, Helvetica, sans-serif;
        }
        .rule.date { height: 30px; }

        /* ── 하단 안내 ───────────────────────────────────────────── */
        .gi { padding: 6px 5px 4px; }
        .gi h2 { font-size: 14pt; margin: 0 0 3px; font-weight: bold; }
        .gi h3 { font-size: 9pt; margin: 6px 0 2px; font-weight: bold; }
        .gi p { margin: 0 0 4px; font-size: 6.9pt; line-height: 1.3; }
        .cols { column-count: 2; column-gap: 16px; }

        .foot { display: flex; justify-content: space-between; font-size: 7pt; padding: 3px 5px 5px; }
        .foot .cat { flex: 1; text-align: center; }
        .foot .fm { font-weight: bold; }

        /* ── 우리가 덧붙이는 것 — 화면에서만 보이고 종이에는 안 찍힌다 ────
           국세청 양식에 없는 문구가 인쇄물에 섞이면 그 종이는 W-9 이 아니게 된다.
           감사에서 읽는 사람에게는 "손댄 서류" 로 보인다. */
        .screen-only {
            width: 7.9in; margin: 10px auto 0; padding: 9px 12px;
            font-family: system-ui, -apple-system, 'Malgun Gothic', sans-serif;
            font-size: 11px; line-height: 1.55; border-radius: 6px; border: 1px solid;
        }
        .screen-only.todo { border-color: #b45309; background: #fffbeb; color: #713f12; }
        .screen-only.done { border-color: #15803d; background: #f0fdf4; color: #14532d; }
        .actions {
            width: 7.9in; margin: 9px auto 0; display: flex; gap: 8px;
            font-family: system-ui, sans-serif;
        }
        .actions button, .actions a {
            appearance: none; border: 1px solid #94a3b8; background: #fff; color: #1e293b;
            border-radius: 8px; padding: 9px 13px; font-size: 12px; font-weight: 700;
            text-decoration: none; cursor: pointer;
        }
        .actions .primary { background: #0f172a; color: #fff; border-color: #0f172a; }

        @media print {
            body { background: #fff; padding: 0; }
            .page { width: auto; box-shadow: none; }
            /* 종이에는 국세청 양식만 남는다. 안내와 버튼은 화면에서만 쓰는 것이다. */
            .screen-only, .actions { display: none !important; }
        }
    </style>
</head>
<body>
@php
    // 빈 양식은 어떤 칸도 미리 고르지 않는다. 본인이 고를 것을 우리가 정하면 안 된다.
    $c = $values['tax_classification'] ?? null;
    // TIN 격자에 한 자리씩 넣는다. 값이 없으면 빈 칸으로 남는다.
    $digits = $tin ? str_split($tin) : array_fill(0, 9, '');
    // 가려서 인쇄해 달라고 한 경우에만 앞자리를 비운다(?mask=1).
    if (! $tin && $maskedTin) {
        $last4 = str_split((string) $form->tin_last4);
        $digits = ['', '', '', '', '', $last4[0] ?? '', $last4[1] ?? '', $last4[2] ?? '', $last4[3] ?? ''];
    }
    $isEin = ($form?->tin_type ?? 'ssn') === 'ein';
@endphp

<div class="page">
    {{-- ── 머리 ─────────────────────────────────────────────── --}}
    <table class="hd">
        <tr>
            <td class="c1">
                <div class="fsm">Form</div>
                <div class="fw9">W-9</div>
                <div class="fsm" style="margin-top:2px">(Rev. March 2024)</div>
                <div class="fsm">Department of the Treasury</div>
                <div class="fsm">Internal Revenue Service</div>
            </td>
            <td class="c2 bl br">
                <div class="t1">Request for Taxpayer<br>Identification Number and Certification</div>
                <div class="t2">Go to <i>www.irs.gov/FormW9</i> for instructions and the latest information.</div>
            </td>
            <td class="c3">Give form to the<br>requester. Do not<br>send to the IRS.</td>
        </tr>
    </table>

    <div class="before thick"><b>Before you begin.</b> For guidance related to the purpose of Form W-9, see <i>Purpose of Form</i>, below.</div>

    {{-- ── Line 1 ~ 7 ───────────────────────────────────────── --}}
    <table class="f">
        <tr>
            <td rowspan="8" class="side br">Print or type.<br>See <b>Specific Instructions</b> on page 3.</td>
            <td colspan="2" class="ln bb">
                <div class="lbl"><b>1</b> &nbsp;Name of entity/individual. An entry is required. (For a sole proprietor or disregarded entity, enter the owner's name on line 1, and enter the business/disregarded entity's name on line 2.)</div>
                <div class="fill">{{ $values['legal_name'] }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2" class="ln bb">
                <div class="lbl"><b>2</b> &nbsp;Business name/disregarded entity name, if different from above.</div>
                <div class="fill h2r">{{ $values['business_name'] }}</div>
            </td>
        </tr>
        <tr>
            <td class="ln bb br" style="width:63%">
                <div class="lbl"><b>3a</b> Check the appropriate box for federal tax classification of the entity/individual whose name is entered on line 1. Check <b>only one</b> of the following seven boxes.</div>
                <div class="cbrow">
                    <span class="cb"><span class="sq {{ $c === 'individual' ? 'on' : '' }}"></span>Individual/sole proprietor</span>
                    <span class="cb"><span class="sq {{ $c === 'c_corp' ? 'on' : '' }}"></span>C corporation</span>
                    <span class="cb"><span class="sq {{ $c === 's_corp' ? 'on' : '' }}"></span>S corporation</span>
                    <span class="cb"><span class="sq {{ $c === 'partnership' ? 'on' : '' }}"></span>Partnership</span>
                    <span class="cb"><span class="sq {{ $c === 'trust_estate' ? 'on' : '' }}"></span>Trust/estate</span>
                </div>
                <div class="cbrow" style="margin-top:4px">
                    <span class="cb" style="width:100%">
                        <span class="sq {{ $c === 'llc' ? 'on' : '' }}"></span>
                        <span>LLC. Enter the tax classification (C = C corporation, S = S corporation, P = Partnership)
                            <span class="dots">. . . .</span>
                            <b>{{ $c === 'llc' ? ($values['llc_tax_class'] ?? '') : '' }}</b></span>
                    </span>
                </div>
                <div class="lbl" style="margin-top:3px;padding-left:11px">
                    <b>Note:</b> Check the "LLC" box above and, in the entry space, enter the appropriate code (C, S, or P) for the tax
                    classification of the LLC, unless it is a disregarded entity. A disregarded entity should instead check the appropriate
                    box for the tax classification of its owner.
                </div>
                <div class="cbrow" style="margin-top:3px">
                    <span class="cb"><span class="sq {{ $c === 'other' ? 'on' : '' }}"></span>Other (see instructions)</span>
                </div>
            </td>
            <td rowspan="2" class="ln bb" style="width:37%">
                <div class="lbl"><b>4</b> &nbsp;Exemptions (codes apply only to certain entities, not individuals; see instructions on page 3):</div>
                <div class="lbl" style="margin-top:9px">Exempt payee code (if any) <span style="display:inline-block;border-bottom:1px solid #000;width:52px"></span></div>
                <div class="lbl" style="margin-top:9px">Exemption from Foreign Account Tax Compliance Act (FATCA) reporting</div>
                <div class="lbl">code (if any) <span style="display:inline-block;border-bottom:1px solid #000;width:52px"></span></div>
                <div class="lbl" style="margin-top:8px;font-style:italic">(Applies to accounts maintained outside the United States.)</div>
            </td>
        </tr>
        <tr>
            <td class="ln bb br">
                <div class="lbl"><b>3b</b> If on line 3a you checked "Partnership" or "Trust/estate," or checked "LLC" and entered "P" as its tax classification,
                    and you are providing this form to a partnership, trust, or estate in which you have an ownership interest, check
                    this box if you have any foreign partners, owners, or beneficiaries. See instructions
                    <span class="dots">. . . . . . . . .</span>
                    <span class="sq" style="display:inline-block;vertical-align:-1px"></span></div>
            </td>
        </tr>
        <tr>
            <td class="ln bb br">
                <div class="lbl"><b>5</b> &nbsp;Address (number, street, and apt. or suite no.). See instructions.</div>
                <div class="fill">{{ $values['address'] }}</div>
            </td>
            <td rowspan="2" class="ln bb">
                <div class="lbl">Requester's name and address (optional)</div>
                {{-- 빈 양식은 받는 쪽도 비워 둔다 — 어느 회사 이름이 미리 찍힌 종이는
                     다른 현장에서 못 쓴다. --}}
                <div class="fill" style="font-size:9pt">@if ($employee){{ $employee->company?->name ?? \App\Support\Org::name() }}@if ($employee->site)<br><span style="font-size:8pt">Site {{ $employee->site->code }}</span>@endif @endif</div>
            </td>
        </tr>
        <tr>
            <td class="ln bb br">
                <div class="lbl"><b>6</b> &nbsp;City, state, and ZIP code</div>
                <div class="fill">{{ $values['city_state_zip'] }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2" class="ln bb">
                <div class="lbl"><b>7</b> &nbsp;List account number(s) here (optional)</div>
                <div class="fill h2r"></div>
            </td>
        </tr>
    </table>

    {{-- ── Part I ───────────────────────────────────────────── --}}
    <div class="part"><span>Part I</span> Taxpayer Identification Number (TIN)</div>
    <table class="f">
        <tr>
            <td class="ln br" style="width:63%">
                <p style="margin:2px 0 3px;font-size:6.9pt;line-height:1.3">Enter your TIN in the appropriate box. The TIN provided must match the name given on line 1 to avoid
                    backup withholding. For individuals, this is generally your social security number (SSN). However, for a
                    resident alien, sole proprietor, or disregarded entity, see the instructions for Part I, later. For other
                    entities, it is your employer identification number (EIN). If you do not have a number, see <i>How to get a
                    TIN</i>, later.</p>
                <p style="margin:0 0 3px;font-size:6.9pt;line-height:1.3"><b>Note:</b> If the account is in more than one name, see the instructions for line 1. See also <i>What Name and
                    Number To Give the Requester</i> for guidelines on whose number to enter.</p>
            </td>
            <td class="ln" style="width:37%">
                <div class="tinlbl">Social security number</div>
                {{-- 어느 만큼 인쇄됐는지 밖에서 확인할 수 있게 표시해 둔다(전체·뒤4자리·빈칸). --}}
                <div class="grid" data-tin="{{ $tin ? 'full' : ($maskedTin ? 'last4' : 'blank') }}">
                    @foreach ([0,1,2] as $i)<span class="cell">{{ $isEin ? '' : ($digits[$i] ?? '') }}</span>@endforeach
                    <span class="dash">–</span>
                    @foreach ([3,4] as $i)<span class="cell">{{ $isEin ? '' : ($digits[$i] ?? '') }}</span>@endforeach
                    <span class="dash">–</span>
                    @foreach ([5,6,7,8] as $i)<span class="cell">{{ $isEin ? '' : ($digits[$i] ?? '') }}</span>@endforeach
                </div>
                <div class="orword">or</div>
                <div class="tinlbl">Employer identification number</div>
                <div class="grid">
                    @foreach ([0,1] as $i)<span class="cell">{{ $isEin ? ($digits[$i] ?? '') : '' }}</span>@endforeach
                    <span class="dash">–</span>
                    @foreach ([2,3,4,5,6,7,8] as $i)<span class="cell">{{ $isEin ? ($digits[$i] ?? '') : '' }}</span>@endforeach
                </div>
            </td>
        </tr>
    </table>

    {{-- ── Part II ──────────────────────────────────────────── --}}
    <div class="part bt"><span>Part II</span> Certification</div>
    <div class="cert bb">
        Under penalties of perjury, I certify that:
        <ol>
            <li>The number shown on this form is my correct taxpayer identification number (or I am waiting for a number to be issued to me); and</li>
            <li>I am not subject to backup withholding because: (a) I am exempt from backup withholding, or (b) I have not been notified by the Internal Revenue
                Service (IRS) that I am subject to backup withholding as a result of a failure to report all interest or dividends, or (c) the IRS has notified me that I am
                no longer subject to backup withholding; and</li>
            <li>I am a U.S. citizen or other U.S. person (defined below); and</li>
            <li>The FATCA code(s) entered on this form (if any) indicating that I am exempt from FATCA reporting is correct.</li>
        </ol>
        <p style="margin:3px 0 0"><b>Certification instructions.</b> You must cross out item 2 above if you have been notified by the IRS that you are currently subject to backup withholding
            because you have failed to report all interest and dividends on your tax return. For real estate transactions, item 2 does not apply. For mortgage interest paid,
            acquisition or abandonment of secured property, cancellation of debt, contributions to an individual retirement arrangement (IRA), and generally, payments
            other than interest and dividends, you are not required to sign the certification, but you must provide your correct TIN. See the instructions for Part II, later.</p>
    </div>
    <table class="f">
        <tr>
            <td class="signhere br">Sign<br>Here</td>
            <td class="ln br" style="width:58%">
                {{-- 서명은 비워 둔다 — 손으로 직접 서명할 자리다. 타이핑된 이름을 찍으면
                     "본인이 쓴 서명" 이 아니라 우리가 인쇄한 글자가 된다. --}}
                <div class="sigrow">
                    <span class="sigcap">Signature of<br>U.S. person</span>
                    <span class="rule"></span>
                </div>
            </td>
            <td class="ln">
                <div class="sigrow">
                    <span class="sigcap">Date</span>
                    <span class="rule date">{{ $form?->certified_at?->format('m/d/Y') }}</span>
                </div>
            </td>
        </tr>
    </table>

    {{-- ── 하단 안내(원본 1페이지 하단) ─────────────────────── --}}
    <div class="gi bt">
        <div class="cols">
            <h2>General Instructions</h2>
            <p>Section references are to the Internal Revenue Code unless otherwise noted.</p>
            <p><b>Future developments.</b> For the latest information about developments related to Form W-9 and its instructions, such as legislation enacted after they were published, go to <i>www.irs.gov/FormW9</i>.</p>
            <h3>What's New</h3>
            <p>Line 3a has been modified to clarify how a disregarded entity completes this line. An LLC that is a disregarded entity should check the appropriate box for the tax classification of its owner. Otherwise, it should check the "LLC" box and enter its appropriate tax classification.</p>
            <p>New line 3b has been added to this form. A flow-through entity is required to complete this line to indicate that it has direct or indirect foreign partners, owners, or beneficiaries when it provides the Form W-9 to another flow-through entity in which it has an ownership interest. This change is intended to provide a flow-through entity with information regarding the status of its indirect foreign partners, owners, or beneficiaries, so that it can satisfy any applicable reporting requirements. For example, a partnership that has any indirect foreign partners may be required to complete Schedules K-2 and K-3. See the Partnership Instructions for Schedules K-2 and K-3 (Form 1065).</p>
            <h3>Purpose of Form</h3>
            <p>An individual or entity (Form W-9 requester) who is required to file an information return with the IRS is giving you this form because they must obtain your correct taxpayer identification number (TIN).</p>
        </div>
    </div>

    <div class="foot bt">
        <span></span>
        <span class="cat">Cat. No. 10231X</span>
        <span class="fm">Form <b>W-9</b> (Rev. 3-2024)</span>
    </div>
</div>

{{-- 여기부터는 원본에 없는, 우리가 붙이는 안내다. 인쇄물에서도 종이 아래에 남는다. --}}
@if ($blank ?? false)
    <div class="screen-only todo">
        <b>빈 양식 · Blank form</b> —
        아무것도 채워지지 않은 국세청 서식입니다. 현장에 챙겨 가서 손으로 받으실 때 쓰세요.
        등록된 직원이면 <b>인원관리 → W-9 출력</b> 에서 이름·주소가 채워진 종이를 뽑을 수 있습니다.
    </div>
@elseif ($form)
    <div class="screen-only done">
        <b>제출 완료 · Submitted</b> — {{ $form->certified_at?->format('Y-m-d H:i') }},
        {{ $form->signature_name }} 명의 전자 서명.
        @if ($tin)
            TIN 전체가 인쇄됩니다 — 취급에 주의하세요.
        @else
            TIN 을 가린 사본입니다(뒤 4자리만).
        @endif
    </div>
@else
    <div class="screen-only todo">
        <b>아직 제출되지 않았습니다 · Not submitted yet</b> —
        아는 칸은 미리 채웠습니다. <b>TIN(Part I)과 서명(Part II)</b>은 본인이 직접 써야 합니다.
        서명은 “위증 시 처벌을 감수한다”는 본인 진술이라 대신 쓸 수 없습니다.
        이 종이에 손으로 받으시거나, 아래 <b>작성 링크</b>를 작업자에게 보내세요.
    </div>
@endif

<div class="actions">
    <button type="button" class="primary" onclick="window.print()">인쇄</button>
    @unless ($blank ?? false)
        <a href="{{ route('w9.blank') }}" target="_blank" rel="noopener">빈 양식</a>
    @endunless
    @if ($form && $tin)
        <a href="{{ request()->fullUrlWithQuery(['mask' => 1]) }}">TIN 가린 사본</a>
    @endif
    @if (! $form && ! ($blank ?? false))
        <a href="{{ $signUrl }}" target="_blank" rel="noopener">작성 링크 열기</a>
    @endif
</div>
</body>
</html>

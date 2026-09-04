<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SUBMITTAL 커버 @if ($blank ?? false)(빈 양식)@endif</title>
    {{--
        SUBMITTAL 커버 — 현장에서 쓰던 엑셀 표지(Submittal_FAN_of_EOR_vendor_print)를 그대로 옮긴 것.

        받는 쪽(원청·EOR)은 이 배치를 외우고 있다. SUB No. 가 오른쪽 위에, Type 이 일곱 칸으로,
        Action 이 A/B/C/D 네 칸으로 있어야 "늘 오던 그 표지" 로 읽힌다. 그래서 16열 격자,
        행 높이, 라벨 문구, 항목 번호(1/2/3/5 — 원본에 4가 없다)까지 엑셀을 따른다.

        화면은 엑셀과 1:1 크기(열 52px, 행 높이 pt 그대로)라 채우기 편하고, 인쇄할 때만
        엑셀의 인쇄 배율(63%)로 줄여 종이에서도 같은 크기가 나온다.
    --}}
    <style>
        @page { size: letter portrait; margin: 0.4in 0.25in; }

        :root { color-scheme: light; }
        * { box-sizing: border-box; }

        body {
            margin: 0; padding: 18px; background: #d9dadd; color: #000;
            /* 원본은 Arial Narrow — 없으면 같은 폭 계열로 떨어진다. */
            font-family: 'Arial Narrow', 'Liberation Sans Narrow', Arial, 'Malgun Gothic', sans-serif;
            font-size: 12pt; line-height: 1.15;
        }

        .wrap { overflow-x: auto; }
        .page {
            width: 832px; margin: 0 auto; background: #fff; padding: 14px 0 22px;
            box-shadow: 0 8px 26px rgba(0,0,0,.18);
        }

        /* ── 16열 격자 (엑셀 A~P, 열 너비 6.75자 ≈ 52px) ─────────────────────── */
        table.f { width: 832px; table-layout: fixed; border-collapse: collapse; }
        table.f col { width: 52px; }
        td { padding: 0 3px; vertical-align: middle; overflow: hidden; white-space: nowrap; }
        .bl { border-left: 1px solid #000; }
        .br { border-right: 1px solid #000; }
        .bt { border-top: 1px solid #000; }
        .bb { border-bottom: 1px solid #000; }
        .bx { border: 1px solid #000; }
        .c { text-align: center; }
        .l { text-align: left; }
        .b { font-weight: bold; }
        .s10 { font-size: 10pt; }
        .s11 { font-size: 11pt; }
        .s18 { font-size: 18pt; }
        /* 좁은 칸(검토자 이름) — Arial Narrow 가 없는 PC 에서도 열세 글자가 잘리지 않게 여백을 줄인다. */
        .tight { padding: 0 1px; }
        .tight .in { padding: 0; }
        /* 엑셀의 8.1pt 짜리 띠 행 — 위아래 선으로 구획을 나눈다. */
        tr.band td { padding: 0; }

        /* ── 입력칸 — 종이에는 값만 남고 테두리·배경은 안 찍힌다 ────────────── */
        .in {
            width: 100%; border: 0; background: transparent; color: inherit;
            font: inherit; padding: 0 2px; margin: 0; outline: none; min-width: 0;
        }
        .in:focus { background: #fffbe6; }
        .lbl-in { display: flex; align-items: center; gap: 4px; }
        .lbl-in .in { flex: 1; }

        /* ● 표시 — 원본은 해당 칸에 ● 를 타이핑했다. 체크하면 같은 글자가 같은 자리에 찍힌다. */
        .mark { position: relative; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .mark input { position: absolute; opacity: 0; width: 0; height: 0; }
        .mark i { font-style: normal; font-size: 15pt; line-height: 1; min-width: 1em; min-height: 1em; text-align: center; }
        .mark.s12 i { font-size: 12pt; }
        .mark input:checked + i::before { content: "●"; }
        .mark:hover i { outline: 1px dashed #94a3b8; }

        .logo { padding: 0 6px; }
        .logo img { display: block; max-width: 196px; max-height: 60px; }
        .logo .brand { font-size: 15pt; font-weight: bold; letter-spacing: -.3px; white-space: normal; line-height: 1.1; }

        /* ── 화면에서만 보이는 것 — 종이에는 표지만 남는다 ────────────────────── */
        .screen-only {
            width: 832px; margin: 10px auto 0; padding: 9px 12px;
            font-family: system-ui, -apple-system, 'Malgun Gothic', sans-serif;
            font-size: 11px; line-height: 1.55; border-radius: 6px;
            border: 1px solid #b45309; background: #fffbeb; color: #713f12;
        }
        .actions {
            width: 832px; margin: 9px auto 0; display: flex; gap: 8px; flex-wrap: wrap;
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
            .wrap { overflow: visible; }
            /* 엑셀 인쇄 설정(배율 63%, 가로 가운데)과 같은 크기로 종이에 앉는다. */
            .page { zoom: .63; box-shadow: none; padding: 0; margin: 0 auto; }
            .in:focus { background: transparent; }
            .mark:hover i { outline: 0; }
            .screen-only, .actions { display: none !important; }
        }
    </style>
</head>
<body>
@php
    $v = fn (string $key) => e($values[$key] ?? '');
    $types = ['drawing' => 'Drawing', 'schedule' => 'Schedule', 'equipment' => 'Equipment', 'data' => 'Data', 'sample' => 'Sample', 'payment' => 'Payment', 'po' => 'P.O.'];
    $checkedTypes = $values['types'] ?? [];
    // 장비표 열 — 원본 표지의 장비표(EMF 그림)와 같은 순서·문구. 값은 열 폭(16열 격자의 칸 수):
    // 원본 그림의 비율을 따라 SERVICE·Remark 를 넓게, CFM 을 좁게 둔다(B~O 열, 합 14칸).
    $eqCols = ['no' => ['No,', 2], 'cfm' => ['CFM', 1], 'service' => ['SERVICE', 2], 'model' => ['MODEL', 2], 'hp' => ['MOTOR(HP)', 2], 'power' => ['V/ph/Hz', 2], 'remark' => ['Remark', 3]];
    $equipment = $values['equipment'] ?? [];
    $rowCount = max($equipmentRows ?? 9, count($equipment));
@endphp

<div class="wrap">
<div class="page">
    <table class="f">
        <colgroup>@for ($i = 0; $i < 16; $i++)<col>@endfor</colgroup>

        {{-- ── 머리: 로고 | SUBMITTAL | SUB No. (1~2행) ─────────────────────────── --}}
        <tr style="height:39.75pt">
            <td colspan="4" rowspan="2" class="logo">
                @if ($hasLogo ?? false)
                    <img src="{{ route('org.logo') }}?v={{ \App\Support\Org::logoVersion() }}" alt="{{ $issuer }}">
                @else
                    <div class="brand">{{ $issuer }}</div>
                @endif
            </td>
            <td colspan="8" rowspan="2" class="c b s18">SUBMITTAL</td>
            <td colspan="4" class="c s11">SUB No.</td>
        </tr>
        <tr style="height:39.75pt">
            <td colspan="4" class="c s10"><input class="in c" name="sub_no" value="{{ $v('sub_no') }}"></td>
        </tr>

        <tr class="band" style="height:8.1pt"><td colspan="16" class="bl br bt bb"></td></tr>

        {{-- ── Project / Date / Issued by / Issued to (4~5행) ────────────────────── --}}
        <tr style="height:28.5pt">
            <td colspan="3" class="bl br bb l">Project</td>
            <td colspan="6" class="bx s11"><input class="in" name="project" value="{{ $v('project') }}"></td>
            <td colspan="3" class="bl br bb l">Date</td>
            <td colspan="4" class="br bb s11"><input class="in c" name="date" value="{{ $v('date') }}"></td>
        </tr>
        <tr style="height:28.5pt">
            <td colspan="3" class="bl br bt l">Issued by</td>
            <td colspan="6" class="bx s11"><input class="in" name="issued_by" value="{{ $values['issued_by'] ?? $issuer }}"></td>
            <td colspan="3" class="bl br bt l">Issued to</td>
            <td colspan="4" class="br bt s11"><input class="in c" name="issued_to" value="{{ $v('issued_to') }}"></td>
        </tr>

        <tr class="band" style="height:8.1pt"><td colspan="16" class="bl br bt bb"></td></tr>

        {{-- ── Type 일곱 칸 (7~8행) ──────────────────────────────────────────────── --}}
        <tr style="height:21pt">
            <td colspan="2" rowspan="2" class="bl br c">Type</td>
            @foreach ($types as $label)
                <td colspan="2" class="bl br c">{{ $label }}</td>
            @endforeach
        </tr>
        <tr style="height:30pt">
            @foreach ($types as $key => $label)
                <td colspan="2" class="bl br">
                    <label class="mark" title="{{ $label }}">
                        <input type="checkbox" name="types[]" value="{{ $key }}" @checked(in_array($key, $checkedTypes, true))><i></i>
                    </label>
                </td>
            @endforeach
        </tr>

        <tr class="band" style="height:8.1pt"><td colspan="16" class="bl br bt bb"></td></tr>

        {{-- ── Related to / Subject / Description (10~12행) ──────────────────────── --}}
        <tr style="height:26.45pt">
            <td colspan="3" class="bl bb l">Related to</td>
            <td class="bb"></td>
            <td colspan="11" class="bb"><input class="in" name="related_to" value="{{ $v('related_to') }}"></td>
            <td class="br bb"></td>
        </tr>
        <tr style="height:26.45pt">
            <td colspan="3" class="bl bt bb l">Subject</td>
            <td class="bt bb"></td>
            <td colspan="11" class="bt bb"><input class="in" name="subject" value="{{ $v('subject') }}"></td>
            <td class="br bt bb"></td>
        </tr>
        <tr style="height:25.15pt">
            <td colspan="3" class="bl bt l">Description</td>
            <td colspan="12" class="bt"></td>
            <td class="br bt"></td>
        </tr>

        {{-- ── 본문 1 / 2 / 3 (13~19행) — 원본 번호 그대로 ─────────────────────── --}}
        <tr style="height:20.1pt">
            <td class="bl"></td>
            <td colspan="5" class="b l">1. Subcon PKG / Work Trade</td>
            <td colspan="9"><input class="in" name="work_trade" value="{{ $v('work_trade') }}"></td>
            <td class="br"></td>
        </tr>
        <tr style="height:20.1pt"><td class="bl"></td><td colspan="14"></td><td class="br"></td></tr>
        <tr style="height:20.1pt">
            <td class="bl"></td>
            <td colspan="14" class="b l">2. Equipment Included.</td>
            <td class="br"></td>
        </tr>
        <tr style="height:20.1pt">
            <td class="bl"></td>
            <td colspan="14"><input class="in" name="equipment_included" value="{{ $v('equipment_included') }}"></td>
            <td class="br"></td>
        </tr>
        <tr style="height:20.1pt"><td class="bl"></td><td colspan="14"></td><td class="br"></td></tr>
        <tr style="height:20.1pt">
            <td class="bl"></td>
            <td colspan="14" class="b l">3. Equipment Selection:</td>
            <td class="br"></td>
        </tr>
        <tr style="height:20.1pt">
            <td class="bl"></td>
            <td colspan="14"><div class="lbl-in"><span>Maker :</span><input class="in" name="maker" value="{{ $v('maker') }}"></div></td>
            <td class="br"></td>
        </tr>

        {{-- ── 장비표 (20~29행) — 원본은 그림으로 붙어 있던 표. 헤더 1행 + 9행. ──── --}}
        <tr style="height:20.1pt">
            <td class="bl"></td>
            @foreach ($eqCols as [$label, $span])
                <td colspan="{{ $span }}" class="bx c b s10">{{ $label }}</td>
            @endforeach
            <td class="br"></td>
        </tr>
        @for ($i = 0; $i < $rowCount; $i++)
            <tr class="eq" style="height:20.1pt">
                <td class="bl"></td>
                @foreach ($eqCols as $key => [$label, $span])
                    <td colspan="{{ $span }}" class="bx s10"><input class="in c" name="equipment[{{ $i }}][{{ $key }}]" value="{{ e($equipment[$i][$key] ?? '') }}"></td>
                @endforeach
                <td class="br"></td>
            </tr>
        @endfor

        {{-- ── 5. 납기 (30~33행) ───────────────────────────────────────────────── --}}
        <tr style="height:20.1pt">
            <td class="bl"></td>
            <td colspan="14" class="b l">5. Estimated Lead Time</td>
            <td class="br"></td>
        </tr>
        <tr style="height:20.1pt">
            <td class="bl"></td>
            <td colspan="14"><div class="lbl-in"><span>-</span><input class="in" name="lead_time" value="{{ $v('lead_time') }}"></div></td>
            <td class="br"></td>
        </tr>
        <tr style="height:20.1pt"><td class="bl"></td><td colspan="14"></td><td class="br"></td></tr>
        <tr style="height:11.25pt"><td class="bl"></td><td colspan="14"></td><td class="br"></td></tr>

        {{-- ── 첨부 (34~37행) ──────────────────────────────────────────────────── --}}
        <tr style="height:25.15pt">
            <td colspan="3" class="bl bt l">#&nbsp;Attachment</td>
            <td colspan="12" class="bt s10"><div class="lbl-in"><span>1.</span><input class="in" name="attachment_1" value="{{ $v('attachment_1') }}"></div></td>
            <td class="br bt"></td>
        </tr>
        <tr style="height:25.15pt">
            <td colspan="3" class="bl"></td>
            <td colspan="12" class="s10"><div class="lbl-in"><span>2.</span><input class="in" name="attachment_2" value="{{ $v('attachment_2') }}"></div></td>
            <td class="br"></td>
        </tr>
        <tr style="height:25.15pt"><td class="bl"></td><td colspan="14"></td><td class="br"></td></tr>
        <tr style="height:11.45pt"><td class="bl"></td><td colspan="14"></td><td class="br"></td></tr>

        <tr class="band" style="height:8.1pt"><td colspan="16" class="bl br bt bb"></td></tr>

        {{-- ── Reviewer / Comment / Action (39~42행) ─────────────────────────────── --}}
        <tr style="height:21pt">
            <td colspan="2" rowspan="2" class="bx c">Reviewer</td>
            <td colspan="10" rowspan="2" class="bx c">Comment</td>
            <td colspan="4" class="br bt bb c">Action</td>
        </tr>
        <tr style="height:21pt">
            <td class="br bt bb c">A</td>
            <td class="bx c">B</td>
            <td class="bx c">C</td>
            <td class="bx c">D</td>
        </tr>
        <tr style="height:27.95pt">
            <td colspan="2" class="bl br bb s11 tight"><input class="in c" name="reviewer" value="{{ $v('reviewer') }}"></td>
            <td colspan="10" class="bl br bb"><input class="in" name="comment" value="{{ $v('comment') }}"></td>
            @foreach (['A', 'B', 'C', 'D'] as $act)
                <td class="bl br bb">
                    <label class="mark s12" title="Action {{ $act }}">
                        <input type="checkbox" name="actions[]" value="{{ $act }}" @checked(in_array($act, $values['actions'] ?? [], true))><i></i>
                    </label>
                </td>
            @endforeach
        </tr>
        <tr style="height:22.9pt">
            <td colspan="16" class="bx c s11">A : Complies&nbsp;&nbsp;/&nbsp;&nbsp;B : Deviates&nbsp;&nbsp;/&nbsp;&nbsp;C : Exceptions taken&nbsp;&nbsp;/&nbsp;&nbsp;D: Etc</td>
        </tr>

        <tr class="band" style="height:8.1pt"><td colspan="16" class="bl br bt bb"></td></tr>

        {{-- ── 발행처 서명란 (44~49행) — 바깥 틀 없이 왼쪽 6열에만 붙는다 ─────── --}}
        <tr style="height:21pt">
            <td colspan="16" class="b l">Issued by</td>
        </tr>
        <tr style="height:21pt">
            <td colspan="6" class="bx c s11">{{ $values['issued_by'] ?? $issuer }}</td>
            <td colspan="10"></td>
        </tr>
        <tr style="height:27.95pt">
            <td colspan="2" class="bx c s11">Manager</td>
            <td colspan="2" class="bx c s11">Team Leader</td>
            <td colspan="2" class="bx c s11">P.M.</td>
            <td colspan="10"></td>
        </tr>
        <tr style="height:50.1pt">
            <td colspan="2" class="bx c s10">Signature</td>
            <td colspan="2" class="bx c s10">Signature</td>
            <td colspan="2" class="bx c s10">Signature</td>
            <td colspan="10"></td>
        </tr>
        <tr style="height:27.95pt">
            <td colspan="2" class="bx"></td><td colspan="2" class="bx"></td><td colspan="2" class="bx"></td>
            <td colspan="10"></td>
        </tr>
        <tr style="height:27.95pt">
            <td colspan="2" class="bx"></td><td colspan="2" class="bx"></td><td colspan="2" class="bx"></td>
            <td colspan="10"></td>
        </tr>
    </table>
</div>
</div>

{{-- 여기부터는 원본에 없는, 우리가 붙이는 안내다. 종이에는 안 찍힌다. --}}
<div class="screen-only">
    <b>빈 양식 · Blank form</b> —
    화면에서 칸을 채운 뒤 <b>인쇄</b>하거나(PDF 저장 포함), 그대로 뽑아 손으로 쓰세요.
    입력한 값은 아직 저장되지 않습니다 — 제출물 대장과 잇는 것은 다음 단계입니다.
    Type 칸과 Action 칸은 눌러서 ● 를 찍습니다.
</div>
<div class="actions">
    <button type="button" class="primary" onclick="window.print()">인쇄</button>
    <button type="button" id="add-row">장비표 행 추가</button>
    <a href="{{ asset('forms/SUBMITTAL_COVER_TEMPLATE.xlsx') }}" download>엑셀 양식 내려받기</a>
</div>

<script>
    // 장비가 아홉 대를 넘으면 마지막 행을 복제해 붙인다. 이름의 인덱스만 올려 두면
    // 나중에 대장과 이을 때 그대로 배열로 읽힌다.
    document.getElementById('add-row').addEventListener('click', function () {
        var rows = document.querySelectorAll('tr.eq');
        var last = rows[rows.length - 1];
        var clone = last.cloneNode(true);
        clone.querySelectorAll('input').forEach(function (el) {
            el.value = '';
            el.name = el.name.replace(/\[\d+\]/, '[' + rows.length + ']');
        });
        last.after(clone);
        clone.querySelector('input').focus();
    });
</script>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Form W-9 · {{ $employee->name }}</title>
    <style>
        :root { color-scheme: light; font-family: 'Malgun Gothic', Arial, Helvetica, sans-serif; background: #f1f5f9; color: #0f172a; }
        body { margin: 0; padding: 20px; display: flex; justify-content: center; }
        .card { width: min(100%, 520px); background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 12px 40px rgba(15,23,42,.08); padding: 26px 22px; box-sizing: border-box; }
        .brand { font-size: .75rem; letter-spacing: .1em; text-transform: uppercase; color: #4f46e5; font-weight: 800; margin: 0 0 4px; }
        h1 { margin: 0 0 4px; font-size: 1.4rem; }
        .sub { color: #475569; font-size: .88rem; margin: 0 0 16px; line-height: 1.5; }
        label { display: block; font-size: .85rem; font-weight: 700; color: #334155; margin: 14px 0 6px; }
        input, select { width: 100%; box-sizing: border-box; padding: 13px 14px; font-size: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; color: #0f172a; font-family: inherit; }
        input:focus, select:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.15); }
        .hint { font-size: .76rem; color: #64748b; margin-top: 5px; line-height: 1.5; }
        .row { display: flex; gap: 10px; }
        .row > div { flex: 1; }
        button[type=submit] { width: 100%; margin-top: 22px; padding: 15px; font-size: 1.05rem; font-weight: 800; color: #fff; background: #4f46e5; border: none; border-radius: 12px; cursor: pointer; }
        button[type=submit]:hover { background: #4338ca; }
        .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 10px 12px; font-size: .85rem; margin-bottom: 14px; }
        .err ul { margin: 4px 0 0; padding-left: 18px; }
        .cert { margin-top: 18px; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 12px; padding: 14px; font-size: .78rem; color: #475569; line-height: 1.55; }
        .cert b { color: #0f172a; }
        .agree { display: flex; align-items: flex-start; gap: 10px; margin-top: 12px; font-size: .85rem; font-weight: 700; color: #334155; cursor: pointer; }
        .agree input { width: 20px; height: 20px; margin-top: 1px; accent-color: #4f46e5; flex-shrink: 0; }
        .done { text-align: center; padding: 12px 0; }
        .check { width: 64px; height: 64px; border-radius: 50%; background: #16a34a; color: #fff; display: grid; place-items: center; font-size: 34px; margin: 0 auto 16px; }
        .done p { color: #475569; line-height: 1.6; }
        .badge { display: inline-block; background: #eef2ff; color: #4338ca; font-weight: 700; border-radius: 8px; padding: 6px 12px; margin-top: 6px; font-family: monospace; }
        .resub { display: inline-block; margin-top: 16px; font-size: .85rem; color: #4f46e5; font-weight: 700; text-decoration: none; }
        .tin-toggle { display: flex; gap: 8px; }
        .tin-toggle label { flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; margin: 0; padding: 12px; border: 1px solid #cbd5e1; border-radius: 10px; cursor: pointer; font-weight: 700; }
        .tin-toggle input { width: 18px; height: 18px; accent-color: #4f46e5; }
    </style>
</head>
<body>
    <div class="card">
        @if ($done && $existing)
            <div class="done">
                <div class="check">✓</div>
                <p class="brand">{{ \App\Support\Org::name() }} · FORM W-9</p>
                <h1>W-9 Submitted</h1>
                <p><b>{{ $existing->legal_name }}</b><br>
                    제출이 완료되었습니다. / Your tax form is on file. / Su formulario está registrado.</p>
                <div class="badge">TIN {{ $existing->maskedTin() }} · {{ $existing->certified_at->format('m/d/Y') }}</div>
                <br>
                <a class="resub" href="{{ $resubmitUrl }}">Re-submit / 다시 작성</a>
            </div>
        @else
            <p class="brand">{{ \App\Support\Org::name() }} · IRS FORM W-9</p>
            <h1>Request for Taxpayer ID</h1>
            <p class="sub">
                U.S. tax form for payment processing — required before your first payment.<br>
                지급 처리를 위한 미국 세무 양식입니다(첫 지급 전 필수). /
                Formulario de impuestos requerido antes de su primer pago.
            </p>

            @if ($errors->any())
                <div class="err">Please check the following: <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
            @endif

            <form method="POST" action="{{ $action }}">
                @csrf

                <label>1. Legal name (as shown on your tax return) <span style="color:#ef4444">*</span></label>
                <input type="text" name="legal_name" value="{{ old('legal_name', $existing->legal_name ?? $employee->name) }}" required>
                <div class="hint">세금 신고서상 성명 / Nombre legal como aparece en su declaración</div>

                <label>2. Business name / DBA (if different)</label>
                <input type="text" name="business_name" value="{{ old('business_name', $existing->business_name ?? '') }}">

                <label>3. Federal tax classification <span style="color:#ef4444">*</span></label>
                <select name="tax_classification" id="tax-class" required>
                    @foreach ($classifications as $key => $labelText)
                        <option value="{{ $key }}" @selected(old('tax_classification', $existing->tax_classification ?? 'individual') === $key)>{{ $labelText }}</option>
                    @endforeach
                </select>
                <div class="hint">대부분의 개인 작업자는 Individual / Sole proprietor 입니다.</div>

                <div id="llc-row" style="display:none">
                    <label>LLC tax class (C / S / P) <span style="color:#ef4444">*</span></label>
                    <select name="llc_tax_class">
                        <option value="">—</option>
                        @foreach (['C', 'S', 'P'] as $c)
                            <option value="{{ $c }}" @selected(old('llc_tax_class', $existing->llc_tax_class ?? '') === $c)>{{ $c }}</option>
                        @endforeach
                    </select>
                </div>

                <label>5. Address (number, street, apt) <span style="color:#ef4444">*</span></label>
                <input type="text" name="address" value="{{ old('address', $existing->address ?? ($prefill['address'] ?? '')) }}" placeholder="1234 W Main St, Apt 2" required>

                <label>6. City, State, ZIP <span style="color:#ef4444">*</span></label>
                <input type="text" name="city_state_zip" value="{{ old('city_state_zip', $existing->city_state_zip ?? ($prefill['city_state_zip'] ?? '')) }}" placeholder="Phoenix, AZ 85001" required>

                <label>Taxpayer Identification Number <span style="color:#ef4444">*</span></label>
                <div class="tin-toggle">
                    <label><input type="radio" name="tin_type" value="ssn" @checked(old('tin_type', $existing->tin_type ?? 'ssn') === 'ssn')> SSN</label>
                    <label><input type="radio" name="tin_type" value="ein" @checked(old('tin_type', $existing->tin_type ?? '') === 'ein')> EIN</label>
                </div>
                <input type="text" name="tin" inputmode="numeric" autocomplete="off" placeholder="XXX-XX-XXXX"
                       value="" style="margin-top:8px" required>
                <div class="hint">
                    숫자 9자리 — 암호화되어 저장되며 화면에는 뒤 4자리만 표시됩니다.<br>
                    Encrypted at rest; only the last 4 digits are ever displayed.
                    @if ($existing)<br><b>On file: {{ $existing->maskedTin() }}</b> — submit again to replace.@endif
                </div>

                <div class="cert">
                    <b>Part II — Certification.</b> Under penalties of perjury, I certify that:
                    (1) the number shown on this form is my correct taxpayer identification number;
                    (2) I am not subject to backup withholding; and
                    (3) I am a U.S. citizen or other U.S. person.
                    <br>위 진술이 사실임을 위증죄 처벌을 전제로 확인합니다.
                </div>

                <label class="agree">
                    <input type="checkbox" name="certify" value="1" required>
                    <span>I certify the statements above / 위 내용을 확인하고 서명합니다</span>
                </label>

                <label>Signature — type your full legal name <span style="color:#ef4444">*</span></label>
                <input type="text" name="signature_name" value="{{ old('signature_name') }}" placeholder="{{ $employee->name }}" required>
                <div class="hint">성명 전체를 입력하면 전자서명으로 처리됩니다. / Typing your name acts as your electronic signature.</div>

                <button type="submit">Submit W-9 / 제출</button>
            </form>

            <script>
                (function () {
                    var sel = document.getElementById('tax-class');
                    var llc = document.getElementById('llc-row');
                    function sync() { llc.style.display = sel.value === 'llc' ? 'block' : 'none'; }
                    sel.addEventListener('change', sync);
                    sync();
                })();
            </script>
        @endif
    </div>
</body>
</html>

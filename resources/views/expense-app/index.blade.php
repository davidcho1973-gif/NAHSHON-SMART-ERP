{{--
    영수증 앱 — 직원 폰에서 사진 한 장으로 경비를 내는 화면.

    ERP 재무 화면의 "등록" 기능만 떼어 온 것이다. 같은 AI 판독기, 같은 계정과목
    정본, 같은 원장(승인대기)으로 들어간다 — 재무 입장에서는 어디서 냈든 같은 건이다.

    작업자앱과 같은 카카오 디자인 언어·같은 언어 설정(workerAppLang 공유)을 쓴다.
    화면은 두 장뿐: [사진 내기] [내 영수증]. 방법을 고르게 하지 않는다 —
    사진 찍으면 서버가 읽고, 흐리면 금액만 물어본다.
--}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#FEE500">
    <title>영수증 · {{ \App\Support\Org::name() }}</title>

    <link rel="manifest" href="{{ route('expense-app.manifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="영수증">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <style>
        :root {
            color-scheme: light;
            --kakao: #FEE500; --kakao-2: #F6DC00; --label: rgba(0,0,0,.85);
            --paper: #F2F3F5; --card: #FFFFFF; --line: #EDEEF0;
            --ink: #191919; --ink-2: #767676; --ink-3: #B0B8C1;
            --ok: #16a34a; --warn: #f59e0b; --bad: #ef4444; --info: #2563eb;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
        body {
            font-family: 'Pretendard Variable', Pretendard, -apple-system, 'Apple SD Gothic Neo', 'Malgun Gothic', sans-serif;
            background: var(--paper); color: var(--ink); min-height: 100vh; font-size: 15px;
        }
        .wrap { max-width: 480px; margin: 0 auto; padding: 0 14px calc(20px + env(safe-area-inset-bottom)); }

        .band { background: var(--kakao); margin: 0 -14px; padding: calc(14px + env(safe-area-inset-top)) 18px 12px; }
        .band h1 { font-size: 17px; font-weight: 800; color: var(--label); display: flex; align-items: center; gap: 8px; }
        .band .who { font-size: 12px; color: rgba(0,0,0,.62); margin-top: 2px; }
        .langs { float: right; }
        .langs button { border: 1px solid rgba(0,0,0,.18); background: transparent; color: var(--label);
            border-radius: 999px; padding: 2px 9px; font-size: 11px; font-weight: 700; margin-left: 4px; cursor: pointer; }
        .langs button.on { background: rgba(0,0,0,.85); color: #FEE500; border-color: transparent; }

        .tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 12px 0; }
        .tabs button { border: none; border-radius: 12px; padding: 11px 0; font-size: 14px; font-weight: 800; cursor: pointer;
            background: var(--card); color: var(--ink-2); border: 1px solid var(--line); }
        .tabs button.on { background: var(--kakao); color: var(--label); border-color: transparent; }

        .card { background: var(--card); border-radius: 12px; border: 1px solid var(--line); padding: 16px; margin-bottom: 10px; }

        .shoot { display: block; width: 100%; border: none; border-radius: 12px; background: var(--kakao); color: var(--label);
            padding: 26px 16px; font-size: 18px; font-weight: 800; cursor: pointer; text-align: center; }
        .shoot:active { background: var(--kakao-2); }
        .shoot .big { font-size: 34px; display: block; margin-bottom: 6px; }
        .preview { width: 100%; border-radius: 12px; margin-top: 10px; display: none; max-height: 300px; object-fit: contain; background: #fafafa; }

        .row { margin-top: 12px; }
        .row label { display: block; font-size: 12px; font-weight: 700; color: var(--ink-2); margin-bottom: 5px; }
        .seg { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .seg button { border: 1px solid var(--line); background: var(--card); border-radius: 10px; padding: 10px 0;
            font-size: 13px; font-weight: 700; color: var(--ink-2); cursor: pointer; }
        .seg button.on { background: rgba(0,0,0,.85); color: #FEE500; border-color: transparent; }
        input[type=number], input[type=text] { width: 100%; border: 1px solid var(--line); border-radius: 10px;
            padding: 11px 12px; font-size: 15px; font-family: inherit; background: #fff; color: var(--ink); }
        .hint { font-size: 11.5px; color: var(--ink-3); margin-top: 4px; }

        .go { display: block; width: 100%; border: none; border-radius: 12px; background: rgba(0,0,0,.85); color: #FEE500;
            padding: 15px 0; font-size: 16px; font-weight: 800; cursor: pointer; margin-top: 14px; }
        .go:disabled { opacity: .45; }

        .done { border-left: 4px solid var(--ok); }
        .done .amt { font-size: 26px; font-weight: 800; }
        .done .meta { font-size: 12.5px; color: var(--ink-2); margin-top: 4px; line-height: 1.5; }

        .item { display: flex; justify-content: space-between; gap: 10px; padding: 12px 2px; border-bottom: 1px solid var(--line); }
        .item:last-child { border-bottom: none; }
        .item .desc { font-size: 13.5px; color: var(--ink); }
        .item .sub { font-size: 11.5px; color: var(--ink-3); margin-top: 2px; }
        .item .right { text-align: right; white-space: nowrap; }
        .item .amt { font-size: 14.5px; font-weight: 800; }
        .chip { display: inline-block; border-radius: 999px; padding: 2px 8px; font-size: 10.5px; font-weight: 800; margin-top: 3px; }
        .chip.pending { background: #FFF7CC; color: #8a6d00; }
        .chip.approved { background: #E7F6EC; color: var(--ok); }
        .chip.rejected { background: #FDECEC; color: var(--bad); }
        .chip.paid { background: #E8F0FE; color: var(--info); }

        .claim { background: var(--kakao); border: none; }
        .claim .t { font-size: 12px; font-weight: 700; color: rgba(0,0,0,.62); }
        .claim .v { font-size: 22px; font-weight: 800; color: var(--label); }

        .toast { position: fixed; left: 50%; transform: translateX(-50%); bottom: calc(24px + env(safe-area-inset-bottom));
            background: rgba(0,0,0,.88); color: #fff; padding: 11px 18px; border-radius: 999px; font-size: 13.5px;
            max-width: 90%; text-align: center; z-index: 50; display: none; }
        .empty { text-align: center; color: var(--ink-3); font-size: 13px; padding: 26px 0; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="band">
        <div class="langs">
            <button data-lang="ko">한국어</button><button data-lang="en">EN</button><button data-lang="es">ES</button>
        </div>
        <h1>🧾 <span data-t="title">영수증</span></h1>
        <div class="who">{{ $employee?->name ?? $user?->name }} · {{ \App\Support\Org::name() }}</div>
    </div>

    @if (! $employee)
        <div class="card" style="border-left:4px solid var(--warn)">
            <b data-t="noEmp">계정에 직원 정보가 연결되어 있지 않습니다.</b>
            <div class="hint" data-t="noEmpHint">관리자(인원관리)에게 연결을 요청하세요.</div>
        </div>
    @else
    <div class="tabs">
        <button id="tab-send" class="on" data-t="tabSend">영수증 내기</button>
        <button id="tab-mine" data-t="tabMine">내 영수증</button>
    </div>

    {{-- ── 제출 화면 ─────────────────────────────────────── --}}
    <div id="pane-send">
        <div class="card" id="result-card" style="display:none"></div>

        <button class="shoot" id="shoot">
            <span class="big">📷</span><span data-t="shoot">영수증 사진 찍기</span>
        </button>
        <input type="file" id="file" accept="image/*" capture="environment" style="display:none">
        <img id="preview" class="preview" alt="">

        <div class="card">
            <div class="row">
                <label data-t="payType">누구 돈으로 냈나요?</label>
                <div class="seg">
                    <button id="pt-personal" class="on" data-t="personal">내 카드 (환급받기)</button>
                    <button id="pt-corporate" data-t="corporate">회사 카드</button>
                </div>
                <div class="hint" id="pt-hint" data-t="personalHint">승인되면 급여에 환급으로 함께 지급됩니다.</div>
            </div>
            <div class="row" id="amount-row" style="display:none">
                <label data-t="amount">금액 ($)</label>
                <input type="number" id="amount" inputmode="decimal" step="0.01" min="0.01" placeholder="0.00">
                <div class="hint" data-t="amountHint">사진이 흐려 금액을 못 읽었을 때만 입력하면 됩니다.</div>
            </div>
            <div class="row">
                <label data-t="memo">메모 (선택)</label>
                <input type="text" id="memo" maxlength="300" data-p="memoPh" placeholder="무엇에 쓴 돈인지 한 줄">
            </div>
            <button class="go" id="go" disabled data-t="send">제출</button>
        </div>
    </div>

    {{-- ── 내 영수증 ─────────────────────────────────────── --}}
    <div id="pane-mine" style="display:none">
        <div class="card claim" id="claim-card" style="display:none">
            <div class="t" data-t="claimT">환급 예정 (승인됨 · 다음 급여에 실림)</div>
            <div class="v" id="claim-v">$0</div>
        </div>
        <div class="card" id="mine-list"><div class="empty" data-t="loading">불러오는 중…</div></div>
    </div>
    @endif
</div>
<div class="toast" id="toast"></div>

<script>
(function () {
    'use strict';
    var CSRF = document.querySelector('meta[name=csrf-token]').content;

    // ── 언어: 작업자앱과 같은 키를 공유한다 — 한 번 고르면 두 앱 모두 그 말로.
    var DICT = {
        ko: { title: '영수증', tabSend: '영수증 내기', tabMine: '내 영수증',
            shoot: '영수증 사진 찍기', reshoot: '다른 사진으로 다시 찍기',
            payType: '누구 돈으로 냈나요?', personal: '내 카드 (환급받기)', corporate: '회사 카드',
            personalHint: '승인되면 급여에 환급으로 함께 지급됩니다.', corporateHint: '회사 카드 지출로 접수됩니다.',
            amount: '금액 ($)', amountHint: '사진이 흐려 금액을 못 읽었을 때만 입력하면 됩니다.',
            memo: '메모 (선택)', memoPh: '무엇에 쓴 돈인지 한 줄', send: '제출', sending: '읽는 중…',
            claimT: '환급 예정 (승인됨 · 다음 급여에 실림)', loading: '불러오는 중…', none: '아직 낸 영수증이 없습니다.',
            analyzed: '읽은 내용', vendor: '거래처', date: '날짜', account: '분류', pending: '승인대기',
            approved: '승인됨', rejected: '반려됨', paid: '지급완료', paidPayroll: '급여로 환급됨',
            needPhoto: '먼저 영수증 사진을 찍어 주세요.',
            noEmp: '계정에 직원 정보가 연결되어 있지 않습니다.', noEmpHint: '관리자(인원관리)에게 연결을 요청하세요.' },
        en: { title: 'Receipts', tabSend: 'Submit', tabMine: 'My receipts',
            shoot: 'Take a photo of the receipt', reshoot: 'Retake with another photo',
            payType: 'Whose money was used?', personal: 'My card (reimburse me)', corporate: 'Company card',
            personalHint: 'Once approved, it is reimbursed with your paycheck.', corporateHint: 'Filed as a company-card expense.',
            amount: 'Amount ($)', amountHint: 'Only needed if the photo is unclear.',
            memo: 'Memo (optional)', memoPh: 'What was this for?', send: 'Submit', sending: 'Reading…',
            claimT: 'To be reimbursed (approved · next paycheck)', loading: 'Loading…', none: 'No receipts yet.',
            analyzed: 'What we read', vendor: 'Vendor', date: 'Date', account: 'Category', pending: 'Pending',
            approved: 'Approved', rejected: 'Rejected', paid: 'Paid', paidPayroll: 'Reimbursed via payroll',
            needPhoto: 'Please take a photo of the receipt first.',
            noEmp: 'No employee record is linked to this account.', noEmpHint: 'Ask your manager to link it.' },
        es: { title: 'Recibos', tabSend: 'Enviar', tabMine: 'Mis recibos',
            shoot: 'Tomar foto del recibo', reshoot: 'Tomar otra foto',
            payType: '¿Con qué dinero se pagó?', personal: 'Mi tarjeta (reembolso)', corporate: 'Tarjeta de la empresa',
            personalHint: 'Al aprobarse, se reembolsa con su pago.', corporateHint: 'Se registra como gasto de la empresa.',
            amount: 'Monto ($)', amountHint: 'Solo si la foto no es clara.',
            memo: 'Nota (opcional)', memoPh: '¿Para qué fue?', send: 'Enviar', sending: 'Leyendo…',
            claimT: 'Por reembolsar (aprobado · próximo pago)', loading: 'Cargando…', none: 'Aún no hay recibos.',
            analyzed: 'Lo que leímos', vendor: 'Comercio', date: 'Fecha', account: 'Categoría', pending: 'Pendiente',
            approved: 'Aprobado', rejected: 'Rechazado', paid: 'Pagado', paidPayroll: 'Reembolsado en nómina',
            needPhoto: 'Primero tome una foto del recibo.',
            noEmp: 'No hay registro de empleado vinculado.', noEmpHint: 'Consulte a su supervisor.' }
    };
    var lang = localStorage.getItem('workerAppLang') || 'ko';
    var T = DICT[lang] || DICT.ko;

    function applyLang() {
        T = DICT[lang] || DICT.ko;
        document.querySelectorAll('[data-t]').forEach(function (el) { if (T[el.dataset.t]) el.textContent = T[el.dataset.t]; });
        document.querySelectorAll('[data-p]').forEach(function (el) { if (T[el.dataset.p]) el.placeholder = T[el.dataset.p]; });
        document.querySelectorAll('.langs button').forEach(function (b) { b.classList.toggle('on', b.dataset.lang === lang); });
        var ptHint = document.getElementById('pt-hint');
        if (ptHint) ptHint.textContent = personal ? T.personalHint : T.corporateHint;
    }
    document.querySelectorAll('.langs button').forEach(function (b) {
        b.addEventListener('click', function () { lang = b.dataset.lang; try { localStorage.setItem('workerAppLang', lang); } catch (e) {} applyLang(); loadMine(); });
    });

    function toast(msg) {
        var el = document.getElementById('toast');
        el.textContent = msg; el.style.display = 'block';
        clearTimeout(el._t); el._t = setTimeout(function () { el.style.display = 'none'; }, 3500);
    }

    var hasEmployee = @json((bool) $employee);
    if (!hasEmployee) { applyLang(); return; }

    // ── 탭
    var tabSend = document.getElementById('tab-send'), tabMine = document.getElementById('tab-mine');
    function show(which) {
        tabSend.classList.toggle('on', which === 'send');
        tabMine.classList.toggle('on', which === 'mine');
        document.getElementById('pane-send').style.display = which === 'send' ? '' : 'none';
        document.getElementById('pane-mine').style.display = which === 'mine' ? '' : 'none';
        if (which === 'mine') loadMine();
    }
    tabSend.addEventListener('click', function () { show('send'); });
    tabMine.addEventListener('click', function () { show('mine'); });

    // ── 사진
    var fileInput = document.getElementById('file'), picked = null;
    document.getElementById('shoot').addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
        picked = fileInput.files && fileInput.files[0];
        if (!picked) return;
        var img = document.getElementById('preview');
        img.src = URL.createObjectURL(picked); img.style.display = 'block';
        document.querySelector('#shoot [data-t]').textContent = T.reshoot;
        document.getElementById('go').disabled = false;
        document.getElementById('result-card').style.display = 'none';
    });

    // ── 결제 수단
    var personal = true;
    function setPt(p) {
        personal = p;
        document.getElementById('pt-personal').classList.toggle('on', p);
        document.getElementById('pt-corporate').classList.toggle('on', !p);
        document.getElementById('pt-hint').textContent = p ? T.personalHint : T.corporateHint;
    }
    document.getElementById('pt-personal').addEventListener('click', function () { setPt(true); });
    document.getElementById('pt-corporate').addEventListener('click', function () { setPt(false); });

    // ── 제출: 사진을 보내면 서버가 ERP 와 같은 AI 로 읽는다. 흐리면 금액만 물어본다.
    var go = document.getElementById('go');
    go.addEventListener('click', async function () {
        if (!picked) { toast(T.needPhoto); return; }
        go.disabled = true; go.textContent = T.sending;
        try {
            var fd = new FormData();
            fd.append('receipt', picked);
            fd.append('payment_type', personal ? 'personal' : 'corporate');
            fd.append('lang', lang);
            var amount = document.getElementById('amount').value;
            if (amount) fd.append('amount', amount);
            var memo = document.getElementById('memo').value;
            if (memo) fd.append('memo', memo);

            var res = await fetch(@json(route('expense-app.submit')), {
                method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd
            });
            var j = await res.json().catch(function () { return {}; });

            if (j.success) {
                var a = j.analyzed || {};
                var rc = document.getElementById('result-card');
                rc.className = 'card done';
                rc.innerHTML = '<div class="amt">$' + Number(a.amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) + '</div>' +
                    '<div class="meta">' + (a.vendor ? T.vendor + ': ' + a.vendor + '<br>' : '') +
                    T.date + ': ' + (a.date || '') + '<br>' + T.account + ': ' + (a.account || '') + '</div>' +
                    '<div class="meta" style="margin-top:6px;font-weight:700">' + j.message + '</div>';
                rc.style.display = 'block';
                // 초기화 — 다음 장을 바로 찍을 수 있게.
                picked = null; fileInput.value = '';
                document.getElementById('preview').style.display = 'none';
                document.getElementById('amount').value = ''; document.getElementById('memo').value = '';
                document.getElementById('amount-row').style.display = 'none';
                document.querySelector('#shoot [data-t]').textContent = T.shoot;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else if (j.code === 'need_amount') {
                document.getElementById('amount-row').style.display = '';
                document.getElementById('amount').focus();
                toast(j.message || '');
            } else {
                toast(j.message || (j.errors ? Object.values(j.errors)[0][0] : '')); // validation 등
            }
        } catch (e) {
            toast(e.message);
        } finally {
            go.textContent = T.send; go.disabled = !picked;
        }
    });

    // ── 내 영수증
    async function loadMine() {
        if (document.getElementById('pane-mine').style.display === 'none') return;
        try {
            var res = await fetch(@json(route('expense-app.list')), { headers: { 'Accept': 'application/json' } });
            var j = await res.json();
            var box = document.getElementById('mine-list');
            if (!j.success || !(j.items || []).length) { box.innerHTML = '<div class="empty">' + T.none + '</div>'; return; }

            if (j.claimable > 0) {
                document.getElementById('claim-card').style.display = '';
                document.getElementById('claim-v').textContent = '$' + Number(j.claimable).toLocaleString(undefined, {minimumFractionDigits: 2});
            } else {
                document.getElementById('claim-card').style.display = 'none';
            }

            box.innerHTML = j.items.map(function (it) {
                var chip = it.paidViaPayroll
                    ? '<span class="chip paid">' + T.paidPayroll + '</span>'
                    : '<span class="chip ' + it.status + '">' + (T[it.status] || it.status) + '</span>';
                return '<div class="item"><div><div class="desc">' + (it.description || '') + '</div>' +
                    '<div class="sub">' + (it.date || '') + (it.account ? ' · ' + it.account : '') + '</div></div>' +
                    '<div class="right"><div class="amt">$' + Number(it.amount).toLocaleString(undefined, {minimumFractionDigits: 2}) + '</div>' + chip + '</div></div>';
            }).join('');
        } catch (e) {
            document.getElementById('mine-list').innerHTML = '<div class="empty">' + e.message + '</div>';
        }
    }

    applyLang();
})();
</script>
</body>
</html>

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
    <title>{{ __('영수증') }} · {{ \App\Support\Org::name() }}</title>

    <link rel="manifest" href="{{ route('expense-app.manifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/upload-apple-touch.png') }}">
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
            padding: 22px 16px; font-size: 17px; font-weight: 800; cursor: pointer; text-align: center; }
        .shoot:active { background: var(--kakao-2); }
        .shoot .big { font-size: 30px; display: block; margin-bottom: 4px; }
        .album { display: block; width: 100%; border: 1px dashed rgba(0,0,0,.25); border-radius: 12px; background: #FFFDF0;
            color: var(--ink-2); padding: 12px; font-size: 13.5px; font-weight: 700; cursor: pointer; text-align: center; margin-top: 8px; }

        /* 올릴 사진 줄 — 장수만큼 쌓인다. 각 줄이 경비 한 건이 된다. */
        .queue { margin-top: 10px; }
        .q-item { display: flex; gap: 10px; align-items: center; background: var(--card); border: 1px solid var(--line);
            border-radius: 12px; padding: 8px; margin-bottom: 8px; }
        .q-item img { width: 54px; height: 54px; object-fit: cover; border-radius: 8px; background: #fafafa; flex: none; }
        .q-item .q-body { flex: 1; min-width: 0; }
        .q-item .q-name { font-size: 12.5px; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .q-item .q-state { font-size: 11.5px; color: var(--ink-3); margin-top: 2px; }
        .q-item .q-state.err { color: var(--bad); font-weight: 700; }
        .q-item .q-state.ok { color: var(--ok); font-weight: 700; }
        .q-item input.q-amount { margin-top: 6px; padding: 8px 10px; font-size: 14px; }
        .q-item .q-x { flex: none; border: none; background: var(--paper); color: var(--ink-2); width: 30px; height: 30px;
            border-radius: 999px; font-size: 15px; font-weight: 800; cursor: pointer; }

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
@include('partials.erp-home')
<div class="wrap">
    <div class="band">
        <div class="langs">
            <button data-lang="ko">{{ __('한국어') }}</button><button data-lang="en">EN</button><button data-lang="es">ES</button>
        </div>
        <h1>🧾 <span data-t="title">{{ __('영수증') }}</span></h1>
        <div class="who">{{ $employee?->name ?? $user?->name }} · {{ \App\Support\Org::name() }}</div>
    </div>

    @if (! $employee)
        <div class="card" style="border-left:4px solid var(--warn)">
            <b data-t="noEmp">{{ __('계정에 직원 정보가 연결되어 있지 않습니다.') }}</b>
            <div class="hint" data-t="noEmpHint">{{ __('관리자(인원관리)에게 연결을 요청하세요.') }}</div>
        </div>
    @else
    <div class="tabs">
        <button id="tab-send" class="on" data-t="tabSend">{{ __('영수증 내기') }}</button>
        <button id="tab-mine" data-t="tabMine">{{ __('내 영수증') }}</button>
    </div>

    {{-- ── 제출 화면 ─────────────────────────────────────── --}}
    <div id="pane-send">
        <div class="card" id="result-card" style="display:none"></div>

        <button class="shoot" id="shoot">
            <span class="big">📷</span><span data-t="shoot">{{ __('영수증 사진 찍기') }}</span>
        </button>
        <button class="album" id="album">🖼 <span data-t="album">{{ __('앨범에서 여러 장 고르기') }}</span></button>
        {{-- 카메라는 한 장씩(찍을 때마다 줄에 쌓임), 앨범은 한 번에 여러 장. --}}
        <input type="file" id="file-cam" accept="image/*" capture="environment" style="display:none">
        <input type="file" id="file-album" accept="image/*" multiple style="display:none">

        <div class="queue" id="queue"></div>

        <div class="card">
            <div class="row">
                <label data-t="payType">{{ __('누구 돈으로 냈나요?') }}</label>
                <div class="seg">
                    <button id="pt-personal" class="on" data-t="personal">{{ __('내 카드 (환급받기)') }}</button>
                    <button id="pt-corporate" data-t="corporate">{{ __('회사 카드') }}</button>
                </div>
                <div class="hint" id="pt-hint" data-t="personalHint">{{ __('승인되면 급여에 환급으로 함께 지급됩니다.') }}</div>
            </div>
            <div class="row">
                <label data-t="memo">{{ __('메모 (선택 · 이번에 올리는 전체에 적용)') }}</label>
                <input type="text" id="memo" maxlength="300" data-p="memoPh" placeholder="무엇에 쓴 돈인지 한 줄">
            </div>
            <button class="go" id="go" disabled data-t="send">{{ __('제출') }}</button>
        </div>
    </div>

    {{-- ── 내 영수증 ─────────────────────────────────────── --}}
    <div id="pane-mine" style="display:none">
        <div class="card claim" id="claim-card" style="display:none">
            <div class="t" data-t="claimT">{{ __('환급 예정 (승인됨 · 다음 급여에 실림)') }}</div>
            <div class="v" id="claim-v">$0</div>
        </div>
        <div class="card" id="mine-list"><div class="empty" data-t="loading">{{ __('불러오는 중…') }}</div></div>
    </div>
    @endif
</div>
<div class="toast" id="toast"></div>

<script>
    // 화면 안의 글도 서버와 같은 사전을 읽는다. 블레이드는 __(), 여기서는 t().
    // 사전이 두 벌이면 한쪽만 번역되는 사고가 난다.
    const TR = @json(\App\Support\AppLocale::dictionary());
    function t(s) { return (TR && TR[s]) || s; }

(function () {
    'use strict';
    var CSRF = document.querySelector('meta[name=csrf-token]').content;

    // ── 언어: 작업자앱과 같은 키를 공유한다 — 한 번 고르면 두 앱 모두 그 말로.
    var DICT = {
        ko: { title: t('영수증'), tabSend: t('영수증 내기'), tabMine: t('내 영수증'),
            shoot: t('영수증 사진 찍기'), shootMore: t('한 장 더 찍기'), album: t('앨범에서 여러 장 고르기'),
            readyToSend: t('제출 대기'), itemNeedAmount: t('금액을 못 읽었습니다 — 금액을 적어 주세요'),
            doneCount: t('{n}건 접수'), doneNote: t('재무 승인 대기로 들어갔습니다.'), dupWarn: t('중복 의심 — 이미 낸 영수증일 수 있습니다'),
            stillNeed: t('{n}장은 금액 입력 후 다시 제출해 주세요.'), failedNote: t('{n}장은 실패했습니다 — 다시 시도해 주세요.'),
            payType: t('누구 돈으로 냈나요?'), personal: t('내 카드 (환급받기)'), corporate: t('회사 카드'),
            personalHint: t('승인되면 급여에 환급으로 함께 지급됩니다.'), corporateHint: t('회사 카드 지출로 접수됩니다.'),
            amount: t('금액 ($)'), amountHint: t('사진이 흐려 금액을 못 읽었을 때만 입력하면 됩니다.'),
            memo: t('메모 (선택)'), memoPh: t('무엇에 쓴 돈인지 한 줄'), send: t('제출'), sending: t('읽는 중…'),
            claimT: t('환급 예정 (승인됨 · 다음 급여에 실림)'), loading: t('불러오는 중…'), none: t('아직 낸 영수증이 없습니다.'),
            analyzed: t('읽은 내용'), vendor: t('거래처'), date: t('날짜'), account: t('분류'), pending: t('승인대기'),
            approved: t('승인됨'), rejected: t('반려됨'), paid: t('지급완료'), paidPayroll: t('급여로 환급됨'),
            needPhoto: t('먼저 영수증 사진을 찍어 주세요.'),
            noEmp: t('계정에 직원 정보가 연결되어 있지 않습니다.'), noEmpHint: t('관리자(인원관리)에게 연결을 요청하세요.') },
        en: { title: 'Receipts', tabSend: 'Submit', tabMine: 'My receipts',
            shoot: 'Take a photo of the receipt', shootMore: 'Take another photo', album: 'Pick multiple from album',
            readyToSend: 'Ready to submit', itemNeedAmount: 'Could not read the amount — please enter it',
            doneCount: '{n} submitted', doneNote: 'Sent for finance approval.', dupWarn: 'Possible duplicate — may already be submitted',
            stillNeed: '{n} photo(s) need an amount — enter and submit again.', failedNote: '{n} photo(s) failed — please retry.',
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
            shoot: 'Tomar foto del recibo', shootMore: 'Tomar otra foto', album: 'Elegir varias del álbum',
            readyToSend: 'Listo para enviar', itemNeedAmount: 'No se pudo leer el monto — ingréselo',
            doneCount: '{n} enviados', doneNote: 'Enviado para aprobación de finanzas.', dupWarn: 'Posible duplicado — quizá ya fue enviado',
            stillNeed: '{n} foto(s) necesitan monto — ingrese y envíe de nuevo.', failedNote: '{n} foto(s) fallaron — reintente.',
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
        b.addEventListener('click', function () {
            lang = b.dataset.lang;
            try { localStorage.setItem('workerAppLang', lang); } catch (e) {}
            applyLang();
            if (hasEmployee) { renderQueue(); loadMine(); }
        });
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

    // ── 사진 줄(queue) — 찍거나 골라서 쌓는다. 한 장 = 경비 한 건.
    var queue = []; // {file, url, state: 'ready'|'sending'|'done'|'need_amount'|'error', msg, amount, result}
    var camInput = document.getElementById('file-cam');
    var albumInput = document.getElementById('file-album');
    document.getElementById('shoot').addEventListener('click', function () { camInput.click(); });
    document.getElementById('album').addEventListener('click', function () { albumInput.click(); });

    function addFiles(list) {
        Array.prototype.forEach.call(list || [], function (f) {
            queue.push({ file: f, url: URL.createObjectURL(f), state: 'ready', msg: '', amount: '' });
        });
        renderQueue();
    }
    camInput.addEventListener('change', function () { addFiles(camInput.files); camInput.value = ''; });
    albumInput.addEventListener('change', function () { addFiles(albumInput.files); albumInput.value = ''; });

    function renderQueue() {
        var box = document.getElementById('queue');
        var pending = queue.filter(function (q) { return q.state !== 'done'; });
        box.innerHTML = pending.map(function (q) {
            var i = queue.indexOf(q);
            var state = '';
            if (q.state === 'sending') state = '<div class="q-state">⏳ ' + T.reading + '</div>';
            else if (q.state === 'need_amount') state = '<div class="q-state err">' + T.itemNeedAmount + '</div>' +
                '<input class="q-amount" type="number" inputmode="decimal" step="0.01" min="0.01" placeholder="$ 0.00" ' +
                'value="' + (q.amount || '') + '" oninput="window._qAmount(' + i + ', this.value)">';
            else if (q.state === 'error') state = '<div class="q-state err">' + (q.msg || '') + '</div>';
            else state = '<div class="q-state">' + T.readyToSend + '</div>';

            return '<div class="q-item"><img src="' + q.url + '" alt="">' +
                '<div class="q-body"><div class="q-name">' + (q.file.name || 'photo') + '</div>' + state + '</div>' +
                (q.state === 'sending' ? '' : '<button class="q-x" onclick="window._qRemove(' + i + ')">✕</button>') +
                '</div>';
        }).join('');

        var go = document.getElementById('go');
        go.disabled = pending.length === 0 || queue.some(function (q) { return q.state === 'sending'; });
        go.textContent = pending.length > 1 ? T.send + ' (' + pending.length + ')' : T.send;
        document.querySelector('#shoot [data-t]').textContent = pending.length ? T.shootMore : T.shoot;
    }
    window._qRemove = function (i) { if (queue[i]) { queue.splice(i, 1); renderQueue(); } };
    window._qAmount = function (i, v) { if (queue[i]) queue[i].amount = v; };

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

    // ── 제출: 줄에 쌓인 사진을 한 장씩 차례로 보낸다(한 요청 한 장 — 용량 제한을
    //    사실상 없앤다). 각 장을 서버가 ERP 와 같은 AI 로 읽고, 흐린 장만 남아서
    //    금액을 물어본다 — 잘 읽힌 장들은 이미 접수된 뒤다.
    var go = document.getElementById('go');

    async function submitOne(q) {
        var fd = new FormData();
        fd.append('receipt', q.file);
        fd.append('payment_type', personal ? 'personal' : 'corporate');
        fd.append('lang', lang);
        if (q.amount) fd.append('amount', q.amount);
        var memo = document.getElementById('memo').value;
        if (memo) fd.append('memo', memo);

        var res = await fetch(@json(route('expense-app.submit')), {
            method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd
        });
        var j = await res.json().catch(function () { return {}; });

        if (j.success) { q.state = 'done'; q.result = j.analyzed || {}; }
        else if (j.code === 'need_amount') { q.state = 'need_amount'; q.msg = j.message || ''; }
        else { q.state = 'error'; q.msg = j.message || (j.errors ? Object.values(j.errors)[0][0] : 'error'); }
    }

    go.addEventListener('click', async function () {
        var pending = queue.filter(function (q) { return q.state !== 'done' && q.state !== 'sending'; });
        if (!pending.length) { toast(T.needPhoto); return; }
        go.disabled = true;

        var doneNow = [];
        for (var i = 0; i < pending.length; i++) {
            var q = pending[i];
            q.state = 'sending'; renderQueue();
            go.disabled = true;
            go.textContent = T.sending + ' ' + (i + 1) + '/' + pending.length;
            try {
                await submitOne(q);
            } catch (e) {
                q.state = 'error'; q.msg = e.message;
            }
            if (q.state === 'done') doneNow.push(q);
            renderQueue(); go.disabled = true;
        }

        // 결과 요약 — 몇 건이 얼마로 접수됐고, 몇 장이 확인을 기다리는지.
        var stillNeed = queue.filter(function (q) { return q.state === 'need_amount'; }).length;
        var failed = queue.filter(function (q) { return q.state === 'error'; }).length;
        if (doneNow.length) {
            var total = doneNow.reduce(function (s, q) { return s + Number(q.result.amount || 0); }, 0);
            var rc = document.getElementById('result-card');
            rc.className = 'card done';
            rc.innerHTML = '<div class="amt">' + T.doneCount.replace('{n}', doneNow.length) +
                ' · $' + total.toLocaleString(undefined, {minimumFractionDigits: 2}) + '</div>' +
                '<div class="meta">' + doneNow.map(function (q) {
                    return '· ' + (q.result.vendor || q.file.name || '') + ' — $' +
                        Number(q.result.amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) +
                        (q.result.siteMatched ? ' <span style="color:var(--info)">📍' + q.result.siteMatched + '</span>' : '') +
                        (q.result.duplicateSuspect ? ' <b style="color:var(--warn)">' + T.dupWarn + '</b>' : '');
                }).join('<br>') + '</div>' +
                '<div class="meta" style="margin-top:6px;font-weight:700">' + T.doneNote +
                (stillNeed ? '<br>' + T.stillNeed.replace('{n}', stillNeed) : '') +
                (failed ? '<br>' + T.failedNote.replace('{n}', failed) : '') + '</div>';
            rc.style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else if (stillNeed) {
            toast(T.stillNeed.replace('{n}', stillNeed));
        }

        // 접수된 장은 줄에서 사라진다(썸네일 메모리도 반환).
        queue.filter(function (q) { return q.state === 'done'; }).forEach(function (q) { try { URL.revokeObjectURL(q.url); } catch (e) {} });
        queue = queue.filter(function (q) { return q.state !== 'done'; });
        if (!queue.length) document.getElementById('memo').value = '';
        renderQueue();

        // 한 장이라도 실제로 올려 본 다음에 앱 설치를 권한다 — 쓸모를 모르는 채로
        // 받는 설치 권유는 그냥 닫힌다(출퇴근앱도 첫 타각 뒤에 권한다).
        if (window.AppInstall) {
            setTimeout(function () { window.AppInstall.offer(); }, 1400);
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

{{-- 홈 화면에 앱 버튼을 만드는 안내 — 안드로이드는 버튼 한 번, 아이폰은 3단계 그림.
     서비스워커 등록도 이 안에서 한다(없으면 크롬이 설치 권한 자체를 안 준다). --}}
@include('partials.install-app')
</body>
</html>

<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $site->code }} {{ $site->name }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <style>
        /*
            현장 등록 — 벽에 붙은 QR 을 찍으면 바로 이 화면이 열린다.

            포스터가 노란 카카오 판이므로 이어지는 이 화면도 같은 규격이어야 한다.
            찍고 넘어온 사람이 "다른 데로 왔나" 하고 멈칫하지 않게.
        */
        :root {
            color-scheme: light;
            font-family: 'Pretendard Variable', Pretendard, -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', 'Malgun Gothic', Arial, sans-serif;
            --kakao: #FEE500; --label: rgba(0,0,0,.85);
            --ink: #191919; --ink-2: #767676; --ink-3: #B0B8C1; --rule: #EDEEF0; --paper: #F2F3F5;
            background: var(--kakao); color: var(--ink);
        }
        * { -webkit-tap-highlight-color: transparent; }
        /* 한글은 기본값이 글자 단위로 끊어져 "작업자 / 등록" 처럼 단어 한가운데가 갈라진다.
           낱말 단위로 넘긴다 — 좁은 폰 화면에서 매번 그랬다. */
        h1, .brand, .done p, .note, label, .device, .shared, .payroll { word-break: keep-all; }
        body { margin: 0; padding: 20px; display: flex; justify-content: center; background: var(--kakao); }
        .card { width: min(100%, 460px); background: #fff; border: 0; border-radius: 20px; padding: 26px 22px; box-sizing: border-box; }
        .top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .brand { font-size: .75rem; letter-spacing: .1em; text-transform: uppercase; color: var(--ink-2); font-weight: 800; margin: 0 0 4px; }
        h1 { margin: 0 0 4px; font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; }
        .site { color: var(--ink-2); font-size: .95rem; margin: 0 0 18px; }
        .langs { display: flex; gap: 4px; flex-shrink: 0; }
        .langs button { border: 1px solid var(--rule); background: #fff; color: var(--ink-2); border-radius: 999px; padding: 7px 11px; font-size: .74rem; font-weight: 800; font-family: inherit; cursor: pointer; }
        .langs button.on { background: var(--label); border-color: transparent; color: #fff; }
        label { display: block; font-size: .85rem; font-weight: 700; color: var(--ink-2); margin: 14px 0 6px; }
        input, select { width: 100%; box-sizing: border-box; padding: 14px; font-size: 1rem; border: 1px solid var(--rule); border-radius: 12px; background: var(--paper); color: var(--ink); font-family: inherit; }
        input:focus, select:focus { outline: 2px solid var(--kakao); outline-offset: -2px; background: #fff; }
        .req { color: #D94C4C; }
        /* 보내기 — 이 화면에서 눌러야 할 것은 이것 하나다. */
        button[type=submit] { width: 100%; margin-top: 22px; padding: 16px; font-size: 1.05rem; font-weight: 800; font-family: inherit; color: var(--label); background: var(--kakao); border: none; border-radius: 12px; min-height: 56px; cursor: pointer; }
        button[type=submit]:active { opacity: .88; }
        .err { background: #FDECEC; border: 0; color: #A63232; border-radius: 12px; padding: 11px 13px; font-size: .85rem; margin-bottom: 14px; }
        .err ul { margin: 4px 0 0; padding-left: 18px; }
        .done { text-align: center; padding: 12px 0; }
        .check { width: 64px; height: 64px; border-radius: 50%; background: var(--kakao); color: var(--label); display: grid; place-items: center; font-size: 34px; font-weight: 800; margin: 0 auto 16px; }
        .done h1 { font-size: 1.5rem; }
        .done p { color: var(--ink-2); line-height: 1.6; }
        .badge { display: inline-block; background: var(--paper); color: var(--ink); font-weight: 700; border-radius: 8px; padding: 6px 12px; margin-top: 6px; font-family: monospace; }
        .device { margin-top: 16px; background: #E8F5EA; border: 0; color: #1E8E3E; border-radius: 12px; padding: 12px 14px; font-size: .85rem; line-height: 1.55; font-weight: 700; }
        .shared { margin-top: 16px; background: #FFF4E0; border: 0; color: #B26A00; border-radius: 12px; padding: 12px 14px; font-size: .85rem; line-height: 1.55; font-weight: 700; text-align: left; }
        .next { display: block; margin-top: 12px; padding: 15px; font-size: 1rem; font-weight: 800; color: var(--label); background: var(--paper); border-radius: 12px; text-decoration: none; }
        /* 자사 직영 안내 — 급여가 걸리는 갈림길이라 노란 판으로 세운다(고용 구분 칸과 같은 규격). */
        .payroll { margin-top: 14px; background: var(--kakao); border-radius: 14px; padding: 13px 15px; font-size: .85rem; line-height: 1.6; color: var(--label); }
        .type { display: inline-block; border-radius: 999px; padding: 6px 15px; font-size: .82rem; font-weight: 800; margin-bottom: 12px; }
        .type-direct { background: var(--label); color: #fff; }
        .type-indirect { background: var(--paper); color: var(--ink-2); }
        .type-client, .type-staff { background: var(--paper); color: var(--ink-2); }
        .type-position { background: var(--kakao); color: var(--label); margin-left: 5px; }
        .note { font-size: .78rem; color: var(--ink-2); margin-top: 6px; line-height: 1.5; }
        .note.on-direct { color: var(--ink); font-weight: 700; }
        .note.on-indirect { color: var(--ink); font-weight: 700; }
        /* 고용 형태를 되묻는 칸 — 여기만 노란 판이다. 잘못 고르면 급여가 통째로 틀어진다. */
        .ask { margin-top: 16px; border: 0; background: var(--kakao); border-radius: 14px; padding: 14px; }
        .ask p { margin: 0 0 10px; font-size: .85rem; font-weight: 800; color: var(--label); }
        .ask .opt { display: flex; align-items: center; gap: 9px; padding: 12px; border: 0; border-radius: 12px; background: #fff; margin-bottom: 8px; cursor: pointer; font-size: .92rem; }
        .ask .opt:last-child { margin-bottom: 0; }
        .ask input[type=radio] { width: 18px; height: 18px; accent-color: #191919; }
        .ask small { display: block; color: var(--ink-2); font-size: .76rem; font-weight: 400; }
    </style>
</head>
<body>
    <div class="card">
        @if ($done)
            <div class="done">
                <div class="check">✓</div>
                <p class="brand">{{ \App\Support\Org::name() }} · {{ $site->code }} {{ $site->name }}</p>
                <h1 id="t-doneTitle"></h1>
                {{-- 고용 구분과 직책을 함께 보여 준다 — 잘못 골랐으면 이 자리에서 알아채야 한다. --}}
                <div class="type type-{{ $employmentType }}">{{ $typeLabel }}</div>
                @if ($employee?->positionLabel())
                    <div class="type type-position">{{ $employee->positionLabel() }}</div>
                @endif
                <p><b>{{ $workerName }}</b><span id="t-doneBody"></span></p>
                @if (!empty($employee?->employee_number))
                    <div class="badge"><span id="t-doneBadge"></span> {{ $employee->employee_number }}</div>
                @endif
                <div class="device" id="t-doneDevice"></div>
                {{-- 한 폰으로 여러 사람을 등록한 경우 — 그 폰을 누구의 것으로도 기억하지 않는다. --}}
                <div class="shared" id="t-shared" style="display:none">
                    <b id="t-sharedTitle"></b><br><span id="t-sharedBody"></span>
                </div>
                {{-- 반장이 팀원을 연달아 등록하는 흐름 — 회사·공정은 다음 사람에게 그대로 이어진다. --}}
                <a class="next" id="t-next" href="{{ route('worker-join.form', ['site' => $site]) }}"></a>

                @if (!empty($w9Url))
                    {{-- 1099 지급 전제조건 — 등록에 이어 바로 작성하게 해 종이 수거 행정을 없앤다. --}}
                    <a href="{{ $w9Url }}" style="display:block;margin-top:16px;padding:15px;font-size:1rem;font-weight:800;color:#fff;background:#0f766e;border-radius:12px;text-decoration:none;">
                        📄 Tax form W-9 작성하기 / Complete your W-9 →
                    </a>
                    <p class="note" style="margin-top:8px">지급 처리를 위해 필요합니다. 지금 이어서 작성해 주세요.<br>Required before your first payment. / Requerido antes de su primer pago.</p>
                @endif
            </div>

            <script>
                // 등록과 동시에 이 휴대폰을 기억한다 — 다음부터 게이트 QR 만 찍으면 본인으로 인식된다.
                (function () {
                    var DICT = @json($dict, JSON_UNESCAPED_UNICODE);
                    var lang = @json($lang);
                    var T = DICT[lang] || DICT.ko;
                    var returning = @json($returning ?? false);
                    var myId = String(@json($employee?->id));

                    document.getElementById('t-doneTitle').textContent = returning ? T.againTitle : T.doneTitle;
                    document.getElementById('t-doneBody').textContent = returning ? T.againBody : T.doneBody;
                    document.getElementById('t-sharedTitle').textContent = T.sharedTitle;
                    document.getElementById('t-sharedBody').textContent = T.sharedBody;
                    document.getElementById('t-next').textContent = T.nextPerson;
                    var badge = document.getElementById('t-doneBadge');
                    if (badge) badge.textContent = T.doneBadge;

                    // 이 휴대폰이 누구의 것인가.
                    //
                    // 한 대로 한 사람만 등록했으면 그 사람의 폰이다 — 기억해 두면 다음부터
                    // 게이트에서 이름을 찾지 않아도 된다. 그런데 반장이 자기 폰으로 팀원을
                    // 여러 명 등록하는 일이 잦다. 그때 마지막 사람으로 기억해 버리면 반장이
                    // 게이트에 폰을 댈 때마다 그 팀원의 출근이 찍힌다 — 남의 근무시간이
                    // 만들어지는 것이고, 아무도 원인을 모른 채 급여까지 간다.
                    //
                    // 그래서 두 사람 이상이 등록된 폰은 누구의 것으로도 기억하지 않는다.
                    // (지운 토큰은 어디에도 남지 않으므로 그 자리에서 쓸 수 없게 된다.)
                    var shared = false;
                    try {
                        var prev = localStorage.getItem('workerJoinLastPerson');
                        shared = !!prev && prev !== myId;

                        if (shared) {
                            localStorage.removeItem('dasolWorkerDevice');
                        } else {
                            localStorage.setItem('dasolWorkerDevice', @json($deviceToken));
                        }
                        localStorage.setItem('workerJoinLastPerson', myId);
                        localStorage.setItem('dasolWorkerLang', lang);
                    } catch (e) {}

                    document.getElementById('t-doneDevice').textContent = T.doneDevice;
                    document.getElementById('t-doneDevice').style.display = shared ? 'none' : '';
                    document.getElementById('t-shared').style.display = shared ? '' : 'none';
                })();
            </script>
        @else
            <div class="top">
                <div>
                    <p class="brand">{{ \App\Support\Org::name() }} · <span id="t-eyebrow"></span></p>
                    <h1 id="t-title"></h1>
                </div>
                <div class="langs" id="langs">
                    @foreach ($langOptions as $code => $name)
                        <button type="button" data-lang="{{ $code }}">{{ $name }}</button>
                    @endforeach
                </div>
            </div>
            <p class="site">{{ $site->code }} {{ $site->name }}</p>

            @if ($errors->any())
                <div class="err"><span id="t-errors"></span><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
            @endif

            <form method="POST" action="{{ route('worker-join.store', ['site' => $site]) }}">
                @csrf
                @if ($lockedType)
                    {{-- 예전에 인쇄해 붙여 둔 고용 형태별 QR 로 들어온 경우 — 그 값을 그대로 지킨다. --}}
                    <input type="hidden" name="qr_type" value="{{ $lockedType }}">
                @endif
                {{-- 여기서 고른 언어가 출퇴근 화면의 기본 언어가 된다. --}}
                <input type="hidden" name="preferred_language" id="lang-field" value="{{ $lang }}">

                <label id="t-name"></label>
                <input type="text" name="full_name" id="f-name" value="{{ old('full_name') }}" required>

                <label id="t-company"></label>
                <select name="company_id" id="company" required>
                    <option value="" id="opt-blank"></option>
                    @foreach ($companies as $c)
                        <option value="{{ $c['id'] }}" data-etype="{{ $c['employment_type'] }}" @selected(old('company_id') == $c['id'])>{{ $c['name'] }}</option>
                    @endforeach
                    {{-- 내일 처음 오는 협력사가 목록에 있을 리 없다. 그때 여기서 막히면 등록 자체를 못 한다. --}}
                    <option value="__other__" id="opt-other" @selected(old('company_name'))></option>
                </select>
                <input type="text" name="company_name" id="company-name" value="{{ old('company_name') }}"
                       style="display:none;margin-top:8px" maxlength="120">
                <div class="note" id="company-note"></div>

                {{-- 회사가 아직 분류되지 않았을 때만 뜬다. 사내 용어 대신 "누가 급여를 주는가" 로 묻는다. --}}
                <div class="ask" id="ask-type" style="display:none">
                    <p><span id="t-askTitle"></span> <span class="req">*</span></p>
                    <label class="opt"><input type="radio" name="employment_type" value="direct" @checked(old('employment_type') === 'direct')>
                        <span id="t-askDirect"></span></label>
                    <label class="opt"><input type="radio" name="employment_type" value="indirect" @checked(old('employment_type') === 'indirect')>
                        <span id="t-askIndirect"></span></label>
                </div>

                <label id="t-trade"></label>
                {{-- 목록에서 고르는 것이 기본이지만, 없는 공정은 적을 수 있다. 서버에서 대소문자·공백만
                     다른 값은 기존 이름으로 맞춘다 — 그래야 집계가 갈리지 않는다. --}}
                <input type="text" name="role" id="f-role" list="trade-list" value="{{ old('role') }}"
                       autocomplete="off" maxlength="60" required>
                <datalist id="trade-list">
                    @foreach ($roles as $t)<option value="{{ $t }}"></option>@endforeach
                </datalist>
                <div class="note" id="t-tradeHint"></div>

                {{-- 직책 — 공정(무슨 일을 하는가)과 다른 값이다(어떤 자리인가).
                     자사 직영은 이 값이 급여의 관리자 구분을 정하므로 반드시 받는다. --}}
                <label id="t-position"></label>
                <select name="position" id="f-position">
                    <option value="" id="opt-position-blank"></option>
                    @foreach ($positions as $code => $label)
                        <option value="{{ $code }}" @selected(old('position') === $code)>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="note" id="t-positionHint"></div>

                {{-- 자사 직영으로 등록되는 순간부터 급여 대상이다. 그 사실을 등록하는
                     자리에서 알려 준다 — 나중에 "왜 급여가 나오지 / 왜 안 나오지" 가 된다. --}}
                <div class="payroll" id="payroll-note" style="display:none">
                    <b id="t-payrollTitle"></b><br><span id="t-payrollBody"></span>
                </div>

                {{-- 전화번호가 신원이다(같은 이름 + 같은 번호 = 같은 사람). 그래서 이메일보다
                     위에 두고, 반드시 받는다. --}}
                <label id="t-phone"></label>
                <input type="tel" name="phone" id="f-phone" value="{{ old('phone') }}" placeholder="480-555-0100" required>
                <div class="note" id="t-phoneHint"></div>

                {{-- 이메일은 선택. 현장에서 이메일이 없거나 기억나지 않는 사람이 여기서 막히면
                     그날 그 사람은 명단에 없는 채로 일하게 된다. --}}
                <label id="t-email"></label>
                <input type="email" name="email" value="{{ old('email') }}" placeholder="name@example.com">
                <div class="note" id="t-emailHint"></div>

                <button type="submit" id="t-submit"></button>
            </form>

            <script>
                (function () {
                    var DICT = @json($dict, JSON_UNESCAPED_UNICODE);
                    var lang = @json($lang);
                    var locked = @json($lockedType);
                    var T = DICT[lang] || DICT.ko;

                    var sel = document.getElementById('company');
                    var posSel = document.getElementById('f-position');
                    var payrollNote = document.getElementById('payroll-note');
                    var nameInput = document.getElementById('company-name');
                    var note = document.getElementById('company-note');
                    var ask = document.getElementById('ask-type');
                    var radios = ask.querySelectorAll('input[type=radio]');
                    var field = document.getElementById('lang-field');

                    function text(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }

                    function paint() {
                        document.documentElement.setAttribute('lang', lang);
                        field.value = lang;
                        text('t-eyebrow', T.eyebrow); text('t-title', T.title);
                        text('t-name', T.name); text('t-company', T.company);
                        text('t-trade', T.trade); text('t-tradeHint', T.tradeHint);
                        text('t-email', T.email); text('t-phone', T.phone);
                        text('t-emailHint', T.emailHint); text('t-phoneHint', T.phoneHint);
                        text('t-position', T.position); text('t-positionHint', T.positionHint);
                        text('opt-position-blank', T.positionPlaceholder);
                        text('t-payrollTitle', T.payrollTitle); text('t-payrollBody', T.payrollBody);
                        // 직책 이름도 언어를 따라간다. 값(코드)은 그대로 두고 보이는 글자만 바꾼다.
                        Array.prototype.forEach.call(posSel.options, function (o) {
                            if (o.value && T.positions && T.positions[o.value]) { o.textContent = T.positions[o.value]; }
                        });
                        text('t-submit', T.submit); text('t-errors', T.errors);
                        text('t-askTitle', T.askTitle);
                        text('opt-blank', T.companyPlaceholder);
                        document.getElementById('f-name').placeholder = T.namePlaceholder;
                        document.getElementById('f-role').placeholder = T.tradePlaceholder;
                        text('opt-other', T.companyOther);
                        document.getElementById('company-name').placeholder = T.companyOtherPlaceholder;
                        document.getElementById('t-askDirect').innerHTML = '';
                        document.getElementById('t-askDirect').append(T.askDirect, Object.assign(document.createElement('small'), { textContent: T.askDirectSub }));
                        document.getElementById('t-askIndirect').innerHTML = '';
                        document.getElementById('t-askIndirect').append(T.askIndirect, Object.assign(document.createElement('small'), { textContent: T.askIndirectSub }));
                        Array.prototype.forEach.call(document.querySelectorAll('#langs button'), function (b) {
                            b.classList.toggle('on', b.getAttribute('data-lang') === lang);
                        });
                        syncCompany();
                    }

                    // 자사 직영이면 급여 대상이다 — 그 사실을 알리고 직책을 반드시 받는다.
                    // (직책이 급여의 관리자 구분을 정한다. 비어 있으면 그 판정이 짐작이 된다.)
                    function syncPayroll(etype) {
                        var direct = etype === 'direct'
                            || (ask.style.display !== 'none' && (document.querySelector('input[name=employment_type]:checked') || {}).value === 'direct');
                        payrollNote.style.display = direct ? 'block' : 'none';
                        posSel.required = direct;
                    }

                    function syncCompany() {
                        var opt = sel.options[sel.selectedIndex];
                        // 회사 분류가 최우선, 없으면 예전 QR 값, 그것도 없으면 작업자에게 묻는다.
                        var etype = (opt && opt.getAttribute('data-etype')) || locked || '';
                        var LABEL = { direct: T.labelDirect, indirect: T.labelIndirect, client: T.labelClient };
                        syncPayroll(etype);

                        // 목록에 없는 회사 — 이름을 받고, 자사인지 협력사인지 물어본다.
                        // 이름만 봐서는 알 수 없고, 그 답이 급여 방식을 정한다.
                        var other = sel.value === '__other__';
                        nameInput.style.display = other ? 'block' : 'none';
                        nameInput.required = other;
                        if (!other) { nameInput.value = ''; }

                        if (other) {
                            note.textContent = T.companyOtherHint; note.className = 'note';
                            ask.style.display = locked ? 'none' : 'block';
                            radios.forEach(function (r) { r.required = !locked; });

                            return;
                        }

                        if (!sel.value) {
                            note.textContent = T.companyHint; note.className = 'note';
                            ask.style.display = 'none';
                            radios.forEach(function (r) { r.required = false; });
                        } else if (etype) {
                            note.textContent = LABEL[etype] + T.suffixRegistered;
                            note.className = 'note on-' + etype;
                            ask.style.display = 'none';
                            radios.forEach(function (r) { r.required = false; });
                        } else {
                            note.textContent = ''; note.className = 'note';
                            ask.style.display = 'block';
                            radios.forEach(function (r) { r.required = true; });
                        }
                    }

                    sel.addEventListener('change', syncCompany);
                    // 목록에 없는 회사라 "누가 급여를 주나요?" 를 직접 고른 경우도 같이 본다.
                    radios.forEach(function (r) { r.addEventListener('change', function () { syncCompany(); }); });
                    Array.prototype.forEach.call(document.querySelectorAll('#langs button'), function (b) {
                        b.addEventListener('click', function () {
                            var code = b.getAttribute('data-lang');
                            if (!DICT[code]) return;
                            lang = code; T = DICT[code];
                            try { localStorage.setItem('dasolWorkerLang', code); } catch (e) {}
                            paint();
                        });
                    });

                    // 반장이 자기 폰으로 팀원을 연달아 등록하는 일이 잦다. 같은 회사를
                    // 사람마다 다시 고르게 하지 않는다 — 마지막에 고른 회사를 채워 두고,
                    // 다르면 바꾸면 된다. (목록에 없는 값이면 브라우저가 무시하므로 안전하다.)
                    document.querySelector('form').addEventListener('submit', function () {
                        try { localStorage.setItem('workerJoinCompany', sel.value || ''); } catch (e) {}
                    });

                    // 처음 열 때: 저장된 언어 → 없으면 브라우저 언어 → 없으면 서버 기본값.
                    (function initial() {
                        var saved = null, savedCompany = null;
                        try {
                            saved = localStorage.getItem('dasolWorkerLang');
                            savedCompany = localStorage.getItem('workerJoinCompany');
                        } catch (e) {}
                        if (savedCompany && !sel.value) { sel.value = savedCompany; }
                        var browser = (navigator.language || '').slice(0, 2);
                        var pick = (saved && DICT[saved]) ? saved : (DICT[browser] ? browser : lang);
                        lang = pick; T = DICT[pick];
                        paint();
                    })();
                })();
            </script>
        @endif
    </div>
</body>
</html>

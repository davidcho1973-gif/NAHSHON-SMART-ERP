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
        .type { display: inline-block; border-radius: 999px; padding: 6px 15px; font-size: .82rem; font-weight: 800; margin-bottom: 12px; }
        .type-direct { background: var(--label); color: #fff; }
        .type-indirect { background: var(--paper); color: var(--ink-2); }
        .type-client, .type-staff { background: var(--paper); color: var(--ink-2); }
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
                <div class="type type-{{ $employmentType }}">{{ $typeLabel }}</div>
                <p><b>{{ $workerName }}</b><span id="t-doneBody"></span></p>
                @if (!empty($employee?->employee_number))
                    <div class="badge"><span id="t-doneBadge"></span> {{ $employee->employee_number }}</div>
                @endif
                <div class="device" id="t-doneDevice"></div>

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
                    document.getElementById('t-doneTitle').textContent = T.doneTitle;
                    document.getElementById('t-doneBody').textContent = T.doneBody;
                    document.getElementById('t-doneDevice').textContent = T.doneDevice;
                    var badge = document.getElementById('t-doneBadge');
                    if (badge) badge.textContent = T.doneBadge;
                    try {
                        localStorage.setItem('dasolWorkerDevice', @json($deviceToken));
                        localStorage.setItem('dasolWorkerLang', lang);
                    } catch (e) {}
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

                <label id="t-email"></label>
                <input type="email" name="email" value="{{ old('email') }}" placeholder="name@example.com" required>

                <label id="t-phone"></label>
                <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="480-555-0100" required>

                <button type="submit" id="t-submit"></button>
            </form>

            <script>
                (function () {
                    var DICT = @json($dict, JSON_UNESCAPED_UNICODE);
                    var lang = @json($lang);
                    var locked = @json($lockedType);
                    var T = DICT[lang] || DICT.ko;

                    var sel = document.getElementById('company');
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

                    function syncCompany() {
                        var opt = sel.options[sel.selectedIndex];
                        // 회사 분류가 최우선, 없으면 예전 QR 값, 그것도 없으면 작업자에게 묻는다.
                        var etype = (opt && opt.getAttribute('data-etype')) || locked || '';
                        var LABEL = { direct: T.labelDirect, indirect: T.labelIndirect, client: T.labelClient };

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
                    Array.prototype.forEach.call(document.querySelectorAll('#langs button'), function (b) {
                        b.addEventListener('click', function () {
                            var code = b.getAttribute('data-lang');
                            if (!DICT[code]) return;
                            lang = code; T = DICT[code];
                            try { localStorage.setItem('dasolWorkerLang', code); } catch (e) {}
                            paint();
                        });
                    });

                    // 처음 열 때: 저장된 언어 → 없으면 브라우저 언어 → 없으면 서버 기본값.
                    (function initial() {
                        var saved = null;
                        try { saved = localStorage.getItem('dasolWorkerLang'); } catch (e) {}
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

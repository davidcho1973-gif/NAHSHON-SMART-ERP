<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $site->code }} {{ $site->name }}</title>

    {{-- 홈 화면에 추가하면 앱이 된다. 이 네 줄이 없으면 아이콘 자리에 화면 캡처가 붙는다. --}}
    <link rel="manifest" href="{{ route('gate.manifest', ['site' => $site]) }}">
    <link rel="apple-touch-icon" href="{{ asset('images/attendance-apple-touch.png') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $site->code }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="theme-color" content="#FEE500">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <style>
        /*
            게이트(현장 출입구 태블릿) — 카카오 디자인 언어.

            작업자 앱·대화방과 같은 규격을 쓴다. 같은 사람이 폰에서는 작업자 앱을,
            출입구에서는 이 화면을 쓰는데 둘이 딴 회사 것처럼 생기면 안 된다.

              노랑 #FEE500 (R255 G232 B18) · 노랑 위 글자 rgba(0,0,0,.85)
              글자 #191919 / #767676 / #B0B8C1 · 구분선 #EDEEF0 · 모서리 12px

            바탕 전체가 노란 면이고 그 위에 흰 판이 얹힌다 — 카카오 가이드의 판(panel)
            구성 그대로다. 출근은 노랑, 퇴근은 검정. 작업자 앱의 버튼과 같은 규칙이라
            어느 화면에서 눌러도 손이 헷갈리지 않는다.
        */
        :root {
            color-scheme: light;
            font-family: "Pretendard Variable", Pretendard, -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', 'Malgun Gothic', 'Noto Sans KR', Arial, sans-serif;
            --kakao: #FEE500;
            --label: rgba(0,0,0,.85);
            --ink: #191919;
            --ink-2: #767676;
            --ink-3: #B0B8C1;
            --rule: #EDEEF0;
            --paper: #F2F3F5;
            background: var(--kakao); color: var(--ink);
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { min-height: 100dvh; margin: 0; display: grid; place-items: start center; padding: 18px; background: var(--kakao); box-sizing: border-box; }
        /* 흰 판이 화면을 채운다. 예전에는 내용이 위쪽에만 몰리고 아래 3분의 2가 노랑이라
           덜 만들어진 페이지처럼 보였다 — 벽에 붙는 포스터는 노랑이 맞지만 이건 손에
           들고 보는 화면이다. */
        .sheet { width: min(100%, 460px); min-height: calc(100dvh - 36px); background: #fff; border-radius: 20px; padding: 24px; box-sizing: border-box; }
        .top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .brand { margin: 0 0 4px; font-size: .72rem; letter-spacing: .12em; text-transform: uppercase; color: var(--ink-2); font-weight: 800; }
        /* 올린 그림 로고. 작업자가 출입구에서 보는 화면이라 회사 표시가 크게 도움이 된다. */
        .brand-logo { display: block; max-height: 34px; max-width: 150px; object-fit: contain; margin: 0 0 8px; }
        /* keep-all: 한글은 기본값이 글자 단위로 끊어져 "현장 출 / 퇴근" 처럼 단어
           한가운데가 갈라진다. 언어 버튼 셋이 위쪽을 차지하는 좁은 폭에서 매번 그랬다. */
        h1 { margin: 0 0 2px; font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; word-break: keep-all; }
        .top > div:first-child { min-width: 0; }
        .site { margin: 0 0 16px; color: var(--ink-2); font-size: .95rem; font-weight: 700; }
        .langs { display: flex; gap: 4px; flex-shrink: 0; }
        .langs button { border: 1px solid var(--rule); background: #fff; color: var(--ink-2); border-radius: 999px; padding: 7px 11px; font-size: .74rem; font-weight: 800; font-family: inherit; cursor: pointer; }
        .langs button.on { background: var(--label); border-color: transparent; color: #fff; }
        label { display: block; font-size: .8rem; color: var(--ink-2); margin: 0 0 6px; font-weight: 700; }
        input[type=text], input[type=tel] { width: 100%; padding: 15px; font-size: 1.1rem; font-family: inherit; border: 1px solid var(--rule); border-radius: 12px; background: var(--paper); box-sizing: border-box; }
        input[type=text]:focus, input[type=tel]:focus { outline: 2px solid var(--kakao); outline-offset: -2px; background: #fff; }
        /* 네 자리는 크게 — 장갑 낀 손으로 햇빛 아래에서 누르는 칸이다. */
        .pin { text-align: center; font-size: 2rem !important; font-weight: 800; letter-spacing: .5em; padding: 18px 15px 18px 25px !important; }
        .hint { color: var(--ink-2); font-size: .85rem; margin: 8px 0 0; text-align: center; }
        .results { margin: 10px 0 0; display: flex; flex-direction: column; gap: 8px; }
        .worker { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 15px 16px; border: 1px solid var(--rule); border-radius: 12px; background: #fff; cursor: pointer; }
        .worker:active { background: var(--paper); }
        .worker .nm { font-weight: 800; font-size: 1.05rem; }
        .worker .co { font-size: .8rem; color: var(--ink-2); }
        .muted { color: var(--ink-3); font-size: .9rem; text-align: center; padding: 14px; white-space: pre-line; }
        .panel { text-align: center; }
        /* "이 기기 주인" 인사 — 노란 형광펜 한 줄. 얼굴 사진이 없는 화면에서 이게 신원 표시다. */
        .hello { display: inline-block; color: var(--label); background: var(--kakao); border-radius: 999px; padding: 4px 12px; font-weight: 800; font-size: .82rem; margin: 0 0 6px; }
        .who { font-size: 1.7rem; font-weight: 800; letter-spacing: -.02em; margin: 6px 0 2px; }
        .sub { color: var(--ink-2); font-size: .92rem; margin: 0 0 18px; }
        .statuschip { display: inline-block; padding: 6px 13px; border-radius: 999px; font-size: .82rem; font-weight: 700; margin-bottom: 18px; background: var(--paper); color: var(--ink-2); }
        /* 검색 결과 오른쪽 "선택" — 줄 전체가 누르는 자리라 글자만 진하게 둔다. */
        .worker .pick { font-weight: 800; color: var(--label); flex-shrink: 0; }
        /* 출근은 노랑(지금 눌러야 할 것), 퇴근은 검정 — 작업자 앱과 같은 규칙이다. */
        .big { width: 100%; border: none; border-radius: 12px; padding: 22px; font-size: 1.4rem; font-weight: 800; font-family: inherit; cursor: pointer; min-height: 68px; }
        .in { background: var(--kakao); color: var(--label); }
        .out { background: var(--label); color: #fff; }
        .big:active { opacity: .88; }
        .ghost { width: 100%; margin-top: 12px; background: #fff; border: 1px solid var(--rule); border-radius: 12px; padding: 14px; font-size: .95rem; font-weight: 700; font-family: inherit; color: var(--ink-2); cursor: pointer; }
        .ok { text-align: center; padding: 8px 0; }
        .ok .mark { font-size: 3.2rem; }
        .ok .msg { font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; margin: 8px 0 2px; }
        .ok .time { color: var(--ink-2); }
        .remembered { color: #1E8E3E; font-weight: 800; font-size: .86rem; margin-top: 12px; }
        /* 공용 폰이라 기억하지 않았다는 안내 — 좋은 소식이 아니므로 초록이 아니다. */
        .remembered.shared { color: #B26A00; }
        .hidden { display: none; }
        .spin { color: var(--ink-3); text-align: center; padding: 10px; }
    </style>
</head>
<body>
    <main class="sheet">
        <div class="top">
            <div>
                @if (\App\Support\Org::hasLogo())
                    <img class="brand-logo" src="{{ route('org.logo') }}?v={{ \App\Support\Org::logoVersion() }}" alt="{{ \App\Support\Org::name() }}">
                @endif
                <p class="brand">{{ \App\Support\Org::name() }}</p>
                <h1 id="t-title"></h1>
            </div>
            <div class="langs" id="langs">
                @foreach ($langOptions as $code => $name)
                    <button type="button" data-lang="{{ $code }}">{{ $name }}</button>
                @endforeach
            </div>
        </div>
        <p class="site">{{ $site->code }} · {{ $site->name }}</p>

        {{-- 0) 기억된 기기 확인 중 --}}
        <section id="screen-boot" class="panel">
            <div class="spin">···</div>
        </section>

        {{-- 1) 전화번호 뒷 4자리로 본인 확인(기억되지 않은 기기) — 게이트의 기본 통로.
             이름 고르기는 확인이 아니었다(남의 이름도 고를 수 있고 명단이 통째로 열렸다). --}}
        <section id="screen-id" class="hidden">
            <label id="t-idLabel"></label>
            <input type="tel" id="last4" inputmode="numeric" autocomplete="off" maxlength="4"
                   pattern="[0-9]*" class="pin">
            <p class="hint" id="t-idHint"></p>
            <div class="results" id="id-results"></div>
            <button type="button" class="ghost" id="to-search"></button>
        </section>

        {{-- 2) 이름으로 찾기 — 번호가 등록되지 않은 사람을 위한 예비 통로. --}}
        <section id="screen-search" class="hidden">
            <label id="t-searchLabel"></label>
            <input type="text" id="q" inputmode="text" autocomplete="off">
            <div class="results" id="results"></div>
            <button type="button" class="ghost" id="to-id"></button>
        </section>

        {{-- 2) 본인 확인 + 출근/퇴근 --}}
        <section id="screen-worker" class="panel hidden">
            <p class="hello hidden" id="w-hello"></p>
            <div class="who" id="w-name"></div>
            <p class="sub" id="w-co"></p>
            <div id="w-status"></div>
            <button class="big" id="punch-btn"></button>
            <button class="ghost" id="remember-btn"></button>
            <button class="ghost" id="back-btn"></button>
        </section>

        {{-- 3) 완료 --}}
        <section id="screen-done" class="panel hidden">
            <div class="ok">
                <div class="mark" id="done-mark">✅</div>
                <div class="msg" id="done-msg"></div>
                <div class="time" id="done-time"></div>
            </div>
            <div class="remembered hidden" id="done-remembered"></div>
            <button class="ghost" id="done-back"></button>
            {{-- 안내를 한 번 닫은 사람이 나중에 마음을 바꿀 자리. 이미 설치했으면 숨는다. --}}
            <button class="ghost hidden" id="done-install"></button>
        </section>
    </main>

    @include('partials.install-app', ['installLang' => $lang])

    <script>
        var URLS = {
            identify: @json(route('gate.identify', ['site' => $site])),
            search: @json(route('gate.search', ['site' => $site])),
            punch: @json(route('gate.punch', ['site' => $site])),
            me: @json(route('gate.me', ['site' => $site])),
            remember: @json(route('gate.remember', ['site' => $site])),
            forget: @json(route('gate.forget', ['site' => $site]))
        };
        var DICT = @json($dict, JSON_UNESCAPED_UNICODE);
        var CSRF = document.querySelector('meta[name=csrf-token]').getAttribute('content');
        var TOKEN_KEY = 'dasolWorkerDevice';
        var LANG_KEY = 'dasolWorkerLang';

        var geo = { lat: null, lng: null, accuracy: null };
        var selected = null;
        var recognized = false;   // 기억된 기기로 인식됐는가
        var identifiedBy = null;  // 'device' | 'phone4' | 'name' — 무엇으로 본인을 확인했나
        var LAST_PERSON_KEY = 'workerGateLastPerson';
        var lang = @json($lang);
        var T = DICT[lang];

        function post(url, body) {
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify(body || {})
            }).then(function (r) { return r.json(); });
        }

        function deviceToken() { try { return localStorage.getItem(TOKEN_KEY) || ''; } catch (e) { return ''; } }
        function setDeviceToken(v) { try { v ? localStorage.setItem(TOKEN_KEY, v) : localStorage.removeItem(TOKEN_KEY); } catch (e) {} }

        // 위치는 있으면 참고용으로만 받는다(없어도 동작).
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (p) {
                geo.lat = p.coords.latitude; geo.lng = p.coords.longitude;
                // 정확도를 함께 보낸다 — 오차가 반경보다 크면 서버가 "밖" 이라고 단정하지 않는다.
                geo.accuracy = p.coords.accuracy;
            }, function () {}, { enableHighAccuracy: true, timeout: 8000 });
        }

        function setLang(code, remember) {
            if (!DICT[code]) return;
            lang = code;
            T = DICT[code];
            document.documentElement.setAttribute('lang', code);
            if (remember) { try { localStorage.setItem(LANG_KEY, code); } catch (e) {} }
            if (window.AppInstall) { window.AppInstall.setLang(code); }
            Array.prototype.forEach.call(document.querySelectorAll('#langs button'), function (b) {
                b.classList.toggle('on', b.getAttribute('data-lang') === code);
            });
            paint();
        }

        // 화면에 이미 그려진 문구를 현재 언어로 다시 칠한다(새로고침 없이 전환).
        function paint() {
            document.getElementById('t-title').textContent = T.title;
            document.getElementById('t-searchLabel').textContent = T.searchLabel;
            document.getElementById('t-idLabel').textContent = T.idLabel;
            document.getElementById('t-idHint').textContent = T.idHint;
            document.getElementById('to-search').textContent = T.idFallback;
            document.getElementById('to-id').textContent = T.idBack;
            document.getElementById('last4').placeholder = '0000';
            document.getElementById('q').placeholder = T.searchPlaceholder;
            document.getElementById('back-btn').textContent = recognized ? T.notMe : T.other;
            document.getElementById('done-back').textContent = T.home;
            var rb = document.getElementById('remember-btn');
            rb.textContent = T.remember;
            rb.classList.toggle('hidden', recognized || !selected);
            var di = document.getElementById('done-install');
            if (window.AppInstall) {
                di.textContent = '＋ ' + window.AppInstall.label();
                di.classList.toggle('hidden', window.AppInstall.installed());
            }
            if (!document.getElementById('results').dataset.filled) {
                document.getElementById('results').innerHTML = '<div class="muted">' + T.searchEmpty + '</div>';
            }
            if (selected) { paintWorker(); }
        }

        function show(id) {
            ['screen-boot', 'screen-id', 'screen-search', 'screen-worker', 'screen-done'].forEach(function (s) {
                document.getElementById(s).classList.toggle('hidden', s !== id);
            });
            if (id === 'screen-id') { setTimeout(function () { last4.focus(); }, 50); }
        }

        // ── 전화번호 뒷 4자리 — 기본 통로.
        var last4 = document.getElementById('last4');
        var idResults = document.getElementById('id-results');

        last4.addEventListener('input', function () {
            var v = last4.value.replace(/\D/g, '').slice(0, 4);
            if (v !== last4.value) { last4.value = v; }
            idResults.innerHTML = '';
            // 네 자리가 채워지면 스스로 찾아본다 — 확인 버튼을 하나 더 누르게 하지 않는다.
            if (v.length === 4) { doIdentify(v); }
        });

        function doIdentify(v) {
            idResults.innerHTML = '<div class="spin">' + T.searching + '</div>';
            post(URLS.identify, { last4: v })
                .then(function (d) {
                    var ws = (d && d.workers) || [];
                    if (!ws.length) { idResults.innerHTML = '<div class="muted">' + T.idNoMatch + '</div>'; return; }
                    // 한 사람이면 곧장 넘어간다. 뒷자리가 겹치는 경우에만 고르게 한다.
                    if (ws.length === 1) { pick(ws[0], false, 'phone4'); return; }
                    idResults.innerHTML = '<div class="muted">' + T.idMany + '</div>';
                    renderWorkers(ws, idResults, 'phone4');
                })
                .catch(function () { idResults.innerHTML = '<div class="muted">' + T.searchError + '</div>'; });
        }

        function renderWorkers(ws, host, how) {
            ws.forEach(function (w) {
                var el = document.createElement('div');
                el.className = 'worker';
                el.innerHTML = '<div><div class="nm"></div><div class="co"></div></div><div class="pick"></div>';
                el.querySelector('.nm').textContent = w.name;
                el.querySelector('.co').textContent = [w.company, w.role].filter(Boolean).join(' · ');
                el.lastElementChild.textContent = T.pick;
                el.addEventListener('click', function () { pick(w, false, how); });
                host.appendChild(el);
            });
        }

        document.getElementById('to-search').addEventListener('click', function () {
            q.value = ''; results.dataset.filled = ''; paint(); show('screen-search');
        });
        document.getElementById('to-id').addEventListener('click', function () {
            last4.value = ''; idResults.innerHTML = ''; show('screen-id');
        });

        var q = document.getElementById('q');
        var results = document.getElementById('results');
        var timer = null;
        q.addEventListener('input', function () {
            clearTimeout(timer);
            var term = q.value.trim();
            // 두 글자 미만은 보내지 않는다 — 한 글자로 명단을 훑는 길을 열지 않는다(서버도 막는다).
            if (term.length < 2) {
                results.dataset.filled = '';
                results.innerHTML = '<div class="muted">' + (term.length ? T.searchShort : T.searchEmpty) + '</div>';
                return;
            }
            timer = setTimeout(function () { doSearch(term); }, 220);
        });

        function doSearch(term) {
            results.dataset.filled = '1';
            results.innerHTML = '<div class="spin">' + T.searching + '</div>';
            post(URLS.search, { q: term })
                .then(function (d) {
                    var ws = (d && d.workers) || [];
                    if (!ws.length) { results.innerHTML = '<div class="muted">' + T.noMatch + '</div>'; return; }
                    results.innerHTML = '';
                    renderWorkers(ws, results, 'name');
                })
                .catch(function () { results.innerHTML = '<div class="muted">' + T.searchError + '</div>'; });
        }

        function pick(w, isRecognized, how) {
            selected = w;
            recognized = !!isRecognized;
            identifiedBy = isRecognized ? 'device' : (how || 'name');
            paintWorker();
            paint();
            show('screen-worker');
        }

        function paintWorker() {
            var w = selected;
            if (!w) return;
            var hello = document.getElementById('w-hello');
            hello.textContent = T.recognized;
            hello.classList.toggle('hidden', !recognized);
            document.getElementById('w-name').textContent = w.name;
            document.getElementById('w-co').textContent = [w.company, w.role].filter(Boolean).join(' · ');

            var chip = document.getElementById('w-status');
            var text = T.noRecord;
            if (w.lastEvent === 'clock_in') { text = T.onDuty + ' ' + (w.lastAt || '') + ')'; }
            else if (w.lastEvent) { text = T.offDuty; }
            chip.innerHTML = '<span class="statuschip"></span>';
            chip.firstChild.textContent = text;

            var next = w.next || 'clock_in';
            var btn = document.getElementById('punch-btn');
            if (next === 'clock_out') { btn.textContent = T.clockOut; btn.className = 'big out'; }
            else { btn.textContent = T.clockIn; btn.className = 'big in'; }
            btn.dataset.next = next;
        }

        // ── 기억된 기기면 이름 검색을 건너뛴다.
        (function boot() {
            var token = deviceToken();
            var saved = null;
            try { saved = localStorage.getItem(LANG_KEY); } catch (e) {}
            if (saved && DICT[saved]) { setLang(saved, false); } else { setLang(lang, false); }

            if (!token) { show('screen-id'); return; }

            post(URLS.me, { device_token: token })
                .then(function (d) {
                    if (!d || !d.recognized) { setDeviceToken(''); show('screen-id'); return; }
                    // 본인의 등록 언어로 화면을 맞춘다.
                    if (d.lang && DICT[d.lang]) { setLang(d.lang, true); }
                    pick({ id: d.employee.id, name: d.employee.name, company: d.employee.company, role: d.employee.role, lastEvent: d.lastEvent, lastAt: d.lastAt, next: d.next }, true);
                })
                .catch(function () { show('screen-id'); });
        })();

        Array.prototype.forEach.call(document.querySelectorAll('#langs button'), function (b) {
            b.addEventListener('click', function () { setLang(b.getAttribute('data-lang'), true); });
        });

        document.getElementById('back-btn').addEventListener('click', function () {
            if (recognized) {
                // "내가 아닙니다" — 이 기기 기억을 지우고 검색으로 돌아간다.
                var token = deviceToken();
                setDeviceToken('');
                if (token) { post(URLS.forget, { device_token: token }).catch(function () {}); }
            }
            selected = null; recognized = false; identifiedBy = null;
            results.dataset.filled = ''; q.value = '';
            last4.value = ''; idResults.innerHTML = '';
            paint();
            show('screen-id');
        });

        document.getElementById('done-back').addEventListener('click', function () {
            if (recognized && selected) { show('screen-worker'); return; }
            last4.value = ''; idResults.innerHTML = ''; paint(); show('screen-id');
        });

        document.getElementById('remember-btn').addEventListener('click', function () {
            if (!selected) return;
            var btn = this;
            btn.disabled = true;
            post(URLS.remember, { employee_id: selected.id })
                .then(function (d) {
                    btn.disabled = false;
                    if (!d || !d.success) { alert((d && d.error) || T.failed); return; }
                    setDeviceToken(d.device_token);
                    recognized = true;
                    if (d.lang && DICT[d.lang]) { setLang(d.lang, true); } else { paint(); }
                })
                .catch(function () { btn.disabled = false; alert(T.network); });
        });

        document.getElementById('punch-btn').addEventListener('click', function () {
            if (!selected) return;
            var btn = this;
            btn.disabled = true;
            var orig = btn.textContent;
            btn.textContent = T.working;
            post(URLS.punch, { employee_id: selected.id, lat: geo.lat, lng: geo.lng, accuracy: geo.accuracy, identified_by: identifiedBy })
                .then(function (d) {
                    btn.disabled = false; btn.textContent = orig;
                    if (!d || d.success === false) { alert((d && d.error) || T.failed); return; }
                    var isOut = d.event === 'clock_out';
                    // 현장 확인이 안 돼 보류된 기록은 완료처럼 보이면 안 된다 — 그 사람은
                    // 찍혔다고 믿고 가는데 급여에는 아직 안 들어가 있다.
                    document.getElementById('done-mark').textContent = d.ignored ? '⏱️' : (d.pending ? '⏳' : (isOut ? '👋' : '✅'));
                    document.getElementById('done-msg').textContent = d.ignored
                        ? T.alreadyDone
                        : (d.pending ? (isOut ? T.pendingOut : T.pendingIn) : (d.name + ' — ' + (isOut ? T.doneOut : T.doneIn)));
                    // 날짜를 함께 적는다 — 자정을 넘기는 야간 작업에서 어느 날로 찍혔는지 알아야 한다.
                    document.getElementById('done-time').textContent =
                        (d.at ? (d.date ? d.date + ' ' : '') + d.at + ' ' + T.recorded : '')
                        + (d.withinSite === false ? ' ' + T.offSite : '');

                    // 이 휴대폰을 기억한다 — 포스터가 "한 번 등록하면 다음부터 자동" 이라고
                    // 적어 두었는데, 실제로는 회색 버튼을 눌러야만 켜져서 아무도 안 켰다.
                    //
                    // 두 조건에서만 기억한다:
                    //  · 번호 뒷자리로 확인된 경우만(이름으로 고른 것은 확인이 아니다)
                    //  · 이 폰으로 다른 사람을 찍은 적이 없을 때(반장 폰이면 남의 출근이 찍힌다)
                    var autoRemembered = false, sharedPhone = false;
                    if (!d.ignored && identifiedBy === 'phone4' && !recognized) {
                        var prev = null;
                        try { prev = localStorage.getItem(LAST_PERSON_KEY); } catch (e) {}
                        sharedPhone = !!prev && prev !== String(selected.id);
                        if (sharedPhone) {
                            setDeviceToken('');
                        } else {
                            post(URLS.remember, { employee_id: selected.id })
                                .then(function (r) { if (r && r.success) { setDeviceToken(r.device_token); recognized = true; } })
                                .catch(function () {});
                            autoRemembered = true;
                        }
                    }
                    try { if (!d.ignored) { localStorage.setItem(LAST_PERSON_KEY, String(selected.id)); } } catch (e) {}

                    var rem = document.getElementById('done-remembered');
                    rem.textContent = sharedPhone ? T.sharedPhone : (autoRemembered ? T.autoRemembered : T.remembered);
                    rem.classList.toggle('shared', sharedPhone);
                    rem.classList.toggle('hidden', !(recognized || autoRemembered || sharedPhone));
                    // 다음 화면에서 출근↔퇴근이 뒤집히도록 상태를 갱신한다.
                    selected.lastEvent = d.event;
                    selected.lastAt = d.at || selected.lastAt;
                    selected.next = isOut ? 'clock_in' : 'clock_out';
                    paintWorker();
                    show('screen-done');

                    // 설치 안내는 여기서만 뜬다 — 출퇴근이 한 번 찍힌 뒤다. 열자마자 권하면
                    // 이 화면이 뭘 해 주는지도 모르는 채로 닫는다. 잠깐 두는 것은 "완료"를
                    // 먼저 읽게 하려는 것이다.
                    if (window.AppInstall && !d.ignored) {
                        setTimeout(function () { window.AppInstall.offer(); }, 1200);
                    }
                })
                .catch(function () { btn.disabled = false; btn.textContent = orig; alert(T.network); });
        });

        document.getElementById('done-install').addEventListener('click', function () {
            window.AppInstall.show();
        });
    </script>
</body>
</html>

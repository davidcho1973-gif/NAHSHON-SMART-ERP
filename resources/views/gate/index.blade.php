<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $site->code }} {{ $site->name }}</title>

    {{-- 홈 화면에 추가하면 앱이 된다. 이 네 줄이 없으면 아이콘 자리에 화면 캡처가 붙는다. --}}
    <link rel="manifest" href="{{ route('gate.manifest', ['site' => $site]) }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $site->code }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0f172a">
    <style>
        :root { color-scheme: light; font-family: 'Malgun Gothic', Arial, Helvetica, sans-serif; background: #0f172a; color: #0f172a; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: start center; padding: 18px; background: #0f172a; }
        .sheet { width: min(100%, 460px); background: #fff; border-radius: 18px; box-shadow: 0 20px 50px rgba(0,0,0,.35); padding: 22px; }
        .top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .brand { margin: 0 0 4px; font-size: .72rem; letter-spacing: .12em; text-transform: uppercase; color: #4f46e5; font-weight: 800; }
        /* 올린 그림 로고. 작업자가 출입구에서 보는 화면이라 회사 표시가 크게 도움이 된다. */
        .brand-logo { display: block; max-height: 34px; max-width: 150px; object-fit: contain; margin: 0 0 8px; }
        h1 { margin: 0 0 2px; font-size: 1.5rem; }
        .site { margin: 0 0 16px; color: #475569; font-size: .95rem; font-weight: 700; }
        .langs { display: flex; gap: 4px; flex-shrink: 0; }
        .langs button { border: 1px solid #e2e8f0; background: #f8fafc; color: #64748b; border-radius: 8px; padding: 6px 9px; font-size: .74rem; font-weight: 800; cursor: pointer; }
        .langs button.on { background: #4f46e5; border-color: #4f46e5; color: #fff; }
        label { display: block; font-size: .8rem; color: #64748b; margin: 0 0 6px; font-weight: 700; }
        input[type=text] { width: 100%; padding: 14px; font-size: 1.1rem; border: 1px solid #cbd5e1; border-radius: 12px; }
        .results { margin: 10px 0 0; display: flex; flex-direction: column; gap: 8px; }
        .worker { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 16px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc; cursor: pointer; }
        .worker:active { background: #eef2ff; }
        .worker .nm { font-weight: 800; font-size: 1.05rem; }
        .worker .co { font-size: .8rem; color: #64748b; }
        .muted { color: #94a3b8; font-size: .9rem; text-align: center; padding: 14px; white-space: pre-line; }
        .panel { text-align: center; }
        .hello { color: #4f46e5; font-weight: 800; font-size: .85rem; margin: 0 0 2px; }
        .who { font-size: 1.6rem; font-weight: 900; margin: 6px 0 2px; }
        .sub { color: #64748b; font-size: .92rem; margin: 0 0 18px; }
        .statuschip { display: inline-block; padding: 5px 12px; border-radius: 999px; font-size: .82rem; font-weight: 800; margin-bottom: 18px; }
        .big { width: 100%; border: none; border-radius: 14px; padding: 22px; font-size: 1.4rem; font-weight: 900; color: #fff; cursor: pointer; }
        .in { background: #059669; }
        .out { background: #dc2626; }
        .ghost { width: 100%; margin-top: 12px; background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 13px; font-size: .95rem; font-weight: 700; color: #475569; cursor: pointer; }
        .ok { text-align: center; padding: 8px 0; }
        .ok .mark { font-size: 3.2rem; }
        .ok .msg { font-size: 1.5rem; font-weight: 900; margin: 8px 0 2px; }
        .ok .time { color: #475569; }
        .remembered { color: #047857; font-weight: 800; font-size: .86rem; margin-top: 12px; }
        .hidden { display: none; }
        .spin { color: #94a3b8; text-align: center; padding: 10px; }
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

        {{-- 1) 이름으로 찾기(기억되지 않은 기기) --}}
        <section id="screen-search" class="hidden">
            <label id="t-searchLabel"></label>
            <input type="text" id="q" inputmode="text" autocomplete="off">
            <div class="results" id="results"></div>
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

        var geo = { lat: null, lng: null };
        var selected = null;
        var recognized = false;   // 기억된 기기로 인식됐는가
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
            navigator.geolocation.getCurrentPosition(function (p) { geo.lat = p.coords.latitude; geo.lng = p.coords.longitude; }, function () {}, { enableHighAccuracy: true, timeout: 8000 });
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
            ['screen-boot', 'screen-search', 'screen-worker', 'screen-done'].forEach(function (s) {
                document.getElementById(s).classList.toggle('hidden', s !== id);
            });
        }

        var q = document.getElementById('q');
        var results = document.getElementById('results');
        var timer = null;
        q.addEventListener('input', function () {
            clearTimeout(timer);
            var term = q.value.trim();
            if (term.length < 1) { results.dataset.filled = ''; results.innerHTML = '<div class="muted">' + T.searchEmpty + '</div>'; return; }
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
                    ws.forEach(function (w) {
                        var el = document.createElement('div');
                        el.className = 'worker';
                        el.innerHTML = '<div><div class="nm"></div><div class="co"></div></div><div style="color:#4f46e5;font-weight:800"></div>';
                        el.querySelector('.nm').textContent = w.name;
                        el.querySelector('.co').textContent = [w.company, w.role].filter(Boolean).join(' · ');
                        el.lastElementChild.textContent = T.pick;
                        el.addEventListener('click', function () { pick(w, false); });
                        results.appendChild(el);
                    });
                })
                .catch(function () { results.innerHTML = '<div class="muted">' + T.searchError + '</div>'; });
        }

        function pick(w, isRecognized) {
            selected = w;
            recognized = !!isRecognized;
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
            chip.innerHTML = '<span class="statuschip" style="background:#f1f5f9;color:#475569"></span>';
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

            if (!token) { show('screen-search'); return; }

            post(URLS.me, { device_token: token })
                .then(function (d) {
                    if (!d || !d.recognized) { setDeviceToken(''); show('screen-search'); return; }
                    // 본인의 등록 언어로 화면을 맞춘다.
                    if (d.lang && DICT[d.lang]) { setLang(d.lang, true); }
                    pick({ id: d.employee.id, name: d.employee.name, company: d.employee.company, role: d.employee.role, lastEvent: d.lastEvent, lastAt: d.lastAt, next: d.next }, true);
                })
                .catch(function () { show('screen-search'); });
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
            selected = null; recognized = false;
            results.dataset.filled = ''; q.value = '';
            paint();
            show('screen-search');
        });

        document.getElementById('done-back').addEventListener('click', function () {
            if (recognized && selected) { show('screen-worker'); return; }
            q.value = ''; results.dataset.filled = ''; paint(); show('screen-search');
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
            post(URLS.punch, { employee_id: selected.id, lat: geo.lat, lng: geo.lng })
                .then(function (d) {
                    btn.disabled = false; btn.textContent = orig;
                    if (!d || d.success === false) { alert((d && d.error) || T.failed); return; }
                    var isOut = d.event === 'clock_out';
                    document.getElementById('done-mark').textContent = d.ignored ? '⏱️' : (isOut ? '👋' : '✅');
                    document.getElementById('done-msg').textContent = d.ignored ? T.alreadyDone : (d.name + ' — ' + (isOut ? T.doneOut : T.doneIn));
                    document.getElementById('done-time').textContent = (d.at ? d.at + ' ' + T.recorded : '') + (d.withinSite === false ? ' ' + T.offSite : '');
                    var rem = document.getElementById('done-remembered');
                    rem.textContent = T.remembered;
                    rem.classList.toggle('hidden', !recognized);
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

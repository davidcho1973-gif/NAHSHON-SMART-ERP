<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>출퇴근 — {{ $site->code }} {{ $site->name }}</title>
    <style>
        :root { color-scheme: light; font-family: 'Malgun Gothic', Arial, Helvetica, sans-serif; background: #0f172a; color: #0f172a; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: start center; padding: 18px; background: #0f172a; }
        .sheet { width: min(100%, 460px); background: #fff; border-radius: 18px; box-shadow: 0 20px 50px rgba(0,0,0,.35); padding: 22px; }
        .brand { margin: 0 0 4px; font-size: .72rem; letter-spacing: .12em; text-transform: uppercase; color: #4f46e5; font-weight: 800; }
        h1 { margin: 0 0 2px; font-size: 1.5rem; }
        .site { margin: 0 0 16px; color: #475569; font-size: .95rem; font-weight: 700; }
        label { display: block; font-size: .8rem; color: #64748b; margin: 0 0 6px; font-weight: 700; }
        input[type=text] { width: 100%; padding: 14px; font-size: 1.1rem; border: 1px solid #cbd5e1; border-radius: 12px; }
        .results { margin: 10px 0 0; display: flex; flex-direction: column; gap: 8px; }
        .worker { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 16px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc; cursor: pointer; }
        .worker:active { background: #eef2ff; }
        .worker .nm { font-weight: 800; font-size: 1.05rem; }
        .worker .co { font-size: .8rem; color: #64748b; }
        .muted { color: #94a3b8; font-size: .9rem; text-align: center; padding: 14px; }
        .panel { text-align: center; }
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
        .hidden { display: none; }
        .spin { color: #94a3b8; text-align: center; padding: 10px; }
    </style>
</head>
<body>
    <main class="sheet">
        <p class="brand">NAHSHON MEP</p>
        <h1>현장 출퇴근</h1>
        <p class="site">{{ $site->code }} · {{ $site->name }}</p>

        {{-- 1) 검색 --}}
        <section id="screen-search">
            <label>이름으로 본인을 찾으세요</label>
            <input type="text" id="q" inputmode="text" autocomplete="off" placeholder="이름 입력 (예: 김철수)">
            <div class="results" id="results"><div class="muted">이름을 입력하면 목록이 나옵니다.</div></div>
        </section>

        {{-- 2) 본인 확인 + 출근/퇴근 --}}
        <section id="screen-worker" class="panel hidden">
            <div class="who" id="w-name"></div>
            <p class="sub" id="w-co"></p>
            <div id="w-status"></div>
            <button class="big" id="punch-btn"></button>
            <button class="ghost" id="back-btn">← 다른 사람</button>
        </section>

        {{-- 3) 완료 --}}
        <section id="screen-done" class="panel hidden">
            <div class="ok">
                <div class="mark" id="done-mark">✅</div>
                <div class="msg" id="done-msg"></div>
                <div class="time" id="done-time"></div>
            </div>
            <button class="ghost" id="done-back">처음으로</button>
        </section>
    </main>

    <script>
        var SITE = @json($site->id);
        var URLS = { search: @json(route('gate.search', ['site' => $site])), punch: @json(route('gate.punch', ['site' => $site])) };
        var CSRF = document.querySelector('meta[name=csrf-token]').getAttribute('content');
        var geo = { lat: null, lng: null };
        var selected = null;

        // 위치는 있으면 참고용으로만 받는다(없어도 동작).
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (p) { geo.lat = p.coords.latitude; geo.lng = p.coords.longitude; }, function () {}, { enableHighAccuracy: true, timeout: 8000 });
        }

        function show(id) {
            ['screen-search', 'screen-worker', 'screen-done'].forEach(function (s) {
                document.getElementById(s).classList.toggle('hidden', s !== id);
            });
        }

        var q = document.getElementById('q');
        var results = document.getElementById('results');
        var timer = null;
        q.addEventListener('input', function () {
            clearTimeout(timer);
            var term = q.value.trim();
            if (term.length < 1) { results.innerHTML = '<div class="muted">이름을 입력하면 목록이 나옵니다.</div>'; return; }
            timer = setTimeout(function () { doSearch(term); }, 220);
        });

        function doSearch(term) {
            results.innerHTML = '<div class="spin">검색 중…</div>';
            fetch(URLS.search, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: JSON.stringify({ q: term }) })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var ws = (d && d.workers) || [];
                    if (!ws.length) { results.innerHTML = '<div class="muted">일치하는 작업자가 없습니다.<br>등록되지 않았다면 먼저 간편등록을 하세요.</div>'; return; }
                    results.innerHTML = '';
                    ws.forEach(function (w) {
                        var el = document.createElement('div');
                        el.className = 'worker';
                        el.innerHTML = '<div><div class="nm"></div><div class="co"></div></div><div style="color:#4f46e5;font-weight:800">선택 ›</div>';
                        el.querySelector('.nm').textContent = w.name;
                        el.querySelector('.co').textContent = [w.company, w.role].filter(Boolean).join(' · ');
                        el.addEventListener('click', function () { pick(w); });
                        results.appendChild(el);
                    });
                })
                .catch(function () { results.innerHTML = '<div class="muted">검색 오류. 다시 시도하세요.</div>'; });
        }

        function pick(w) {
            selected = w;
            document.getElementById('w-name').textContent = w.name;
            document.getElementById('w-co').textContent = [w.company, w.role].filter(Boolean).join(' · ');
            var next = w.next || 'clock_in';
            var chip = document.getElementById('w-status');
            if (w.lastEvent) {
                chip.innerHTML = '<span class="statuschip" style="background:#f1f5f9;color:#475569">현재: ' + (w.lastEvent === 'clock_in' ? '근무중 (출근 ' + (w.lastAt || '') + ')' : '퇴근 완료') + '</span>';
            } else {
                chip.innerHTML = '<span class="statuschip" style="background:#f1f5f9;color:#475569">오늘 기록 없음</span>';
            }
            var btn = document.getElementById('punch-btn');
            if (next === 'clock_out') { btn.textContent = '🔴 퇴근하기'; btn.className = 'big out'; }
            else { btn.textContent = '🟢 출근하기'; btn.className = 'big in'; }
            btn.dataset.next = next;
            show('screen-worker');
        }

        document.getElementById('back-btn').addEventListener('click', function () { show('screen-search'); });
        document.getElementById('done-back').addEventListener('click', function () { q.value = ''; results.innerHTML = '<div class="muted">이름을 입력하면 목록이 나옵니다.</div>'; show('screen-search'); });

        document.getElementById('punch-btn').addEventListener('click', function () {
            if (!selected) return;
            var btn = this;
            btn.disabled = true;
            var orig = btn.textContent;
            btn.textContent = '처리 중…';
            fetch(URLS.punch, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: JSON.stringify({ employee_id: selected.id, lat: geo.lat, lng: geo.lng }) })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    btn.disabled = false; btn.textContent = orig;
                    if (!d || d.success === false) { alert((d && d.error) || '처리 실패. 다시 시도하세요.'); return; }
                    var isOut = d.event === 'clock_out';
                    document.getElementById('done-mark').textContent = d.ignored ? '⏱️' : (isOut ? '👋' : '✅');
                    document.getElementById('done-msg').textContent = d.ignored ? '이미 처리됨' : (d.name + '님 ' + (isOut ? '퇴근 완료' : '출근 완료'));
                    document.getElementById('done-time').textContent = (d.at ? d.at + ' 기록' : '') + (d.withinSite === false ? ' · ⚠ 현장 밖에서 스캔됨' : '');
                    show('screen-done');
                })
                .catch(function () { btn.disabled = false; btn.textContent = orig; alert('네트워크 오류. 다시 시도하세요.'); });
        });
    </script>
</body>
</html>

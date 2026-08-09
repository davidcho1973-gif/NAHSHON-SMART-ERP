{{--
    작업자 출퇴근 화면.

    규칙은 하나다 — 작업자에게 방법을 고르게 하지 않는다. 화면이 상황을 보고 알아서
    자동 → 직접 → QR 순서로 내려가고, 보이는 것은 큰 버튼 하나와 "왜 이 방법인지" 한 줄뿐이다.

    바탕을 밝게 두는 것은 의도다. 애리조나 현장 햇빛 아래에서는 어두운 화면보다 밝은
    화면이 훨씬 잘 보인다. 장갑 낀 손을 생각해 버튼도 크게 잡았다.
--}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#F7F8F4">
    <title>내 출퇴근 · DASOL PRISM</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #14170F;
            --ink-2: #5D6456;
            --ink-3: #7B8174;
            --ground: #F7F8F4;
            --surface: #FFFFFF;
            --line: #E3E6DC;
            --sunken: #EDF0E7;
            --hivis: #C8DC00;
            --good: #17703D;
            --good-bg: #E4F3E9;
            --warn: #8A5605;
            --warn-bg: #FBF0DA;
            --bad: #A03325;
            --bad-bg: #F9E6E2;
            --mono: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body {
            margin: 0; background: var(--ground); color: var(--ink);
            font-family: system-ui, -apple-system, 'Apple SD Gothic Neo', 'Malgun Gothic', sans-serif;
            font-size: 16px; line-height: 1.6; -webkit-font-smoothing: antialiased;
        }
        .app { max-width: 520px; margin: 0 auto; min-height: 100vh; display: flex; flex-direction: column; }
        .body { flex: 1; padding: 8px 18px 24px; }

        .offline { background: var(--ink); color: var(--ground); text-align: center; font-family: var(--mono); font-size: 11px; letter-spacing: .1em; padding: 4px; }

        .who { display: flex; align-items: center; gap: 11px; padding: 14px 0 16px; }
        .avatar { width: 44px; height: 44px; border-radius: 12px; background: var(--ink); color: var(--hivis); display: grid; place-items: center; font-weight: 800; font-size: 15px; }
        .who-name { font-weight: 750; font-size: 17px; line-height: 1.25; }
        .who-sub { font-size: 12px; color: var(--ink-3); font-family: var(--mono); }

        .card { border-radius: 18px; padding: 20px; text-align: center; border: 1px solid transparent; transition: background .25s ease; }
        .card.good { background: var(--good-bg); border-color: #B4DCC3; }
        .card.warn { background: var(--warn-bg); border-color: #EBD3A2; }
        .card.bad  { background: var(--bad-bg);  border-color: #EFC2B8; }
        .card.idle { background: var(--sunken);  border-color: var(--line); }

        .pill { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 700; padding: 5px 13px; border-radius: 999px; background: rgba(255,255,255,.72); }
        .pill i { width: 8px; height: 8px; border-radius: 50%; background: currentColor; display: block; }
        .pill.good { color: var(--good); } .pill.warn { color: var(--warn); }
        .pill.bad { color: var(--bad); }   .pill.idle { color: var(--ink-3); }
        @media (prefers-reduced-motion: no-preference) { .pill.good i { animation: b 2.4s ease-in-out infinite; } }
        @keyframes b { 0%,100% { opacity: 1 } 50% { opacity: .35 } }

        .big { font-size: 50px; font-weight: 800; letter-spacing: -.035em; font-variant-numeric: tabular-nums; line-height: 1.1; margin: 12px 0 2px; }
        .big small { font-size: 21px; font-weight: 700; margin-left: 3px; }
        .under { font-size: 13px; color: var(--ink-2); font-family: var(--mono); }
        .why { margin-top: 15px; padding-top: 14px; border-top: 1px dashed rgba(0,0,0,.16); font-size: 14px; line-height: 1.55; color: #4E5548; text-align: left; }
        .why b { color: var(--ink); }

        .btn { width: 100%; margin-top: 13px; padding: 17px; border: none; border-radius: 14px; font-family: inherit; font-size: 17px; font-weight: 750; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn.hivis { background: var(--hivis); color: var(--ink); box-shadow: inset 0 -3px 0 rgba(0,0,0,.16); }
        .btn.dark  { background: var(--ink); color: var(--ground); }
        .btn.ghost { background: transparent; color: var(--ink-2); border: 1.5px dashed #B9BEB0; font-size: 15px; font-weight: 650; padding: 14px; }
        .btn[disabled] { opacity: .55; }
        .btn:focus-visible, a:focus-visible { outline: 3px solid var(--ink); outline-offset: 2px; }
        .hint { font-size: 12px; color: var(--ink-3); margin-top: 9px; line-height: 1.5; }
        .hint b { color: var(--ink-2); }

        .sect { margin-top: 24px; }
        .sect-h { font-family: var(--mono); font-size: 11px; letter-spacing: .13em; text-transform: uppercase; color: var(--ink-3); margin-bottom: 8px; }
        .row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--line); }
        .row:last-child { border-bottom: none; }
        .row-t { font-family: var(--mono); font-size: 13px; font-weight: 600; width: 52px; flex: none; font-variant-numeric: tabular-nums; }
        .row-m { flex: 1; min-width: 0; }
        .row-a { font-weight: 650; font-size: 15px; }
        .row-b { font-size: 12px; color: var(--ink-3); font-family: var(--mono); }
        .chip { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 999px; font-family: var(--mono); white-space: nowrap; }
        .chip.auto { background: var(--good-bg); color: var(--good); }
        .chip.hand { background: #E7EBFA; color: #2E4A9E; }
        .chip.qr   { background: #F1E9F7; color: #6B3E95; }
        .chip.rev  { background: var(--warn-bg); color: var(--warn); }

        .link { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 17px; border: 1px solid var(--line); border-radius: 14px; background: var(--surface); text-decoration: none; color: inherit; margin-top: 10px; }
        .link b { font-size: 15.5px; font-weight: 700; display: block; }
        .link span { font-size: 12.5px; color: var(--ink-3); }
        .link .go { color: var(--ink-3); font-size: 20px; flex: none; }

        .qrbox { background: var(--surface); border: 1px solid var(--line); border-radius: 18px; padding: 20px; text-align: center; }
        .qrbox img { display: block; margin: 0 auto; width: 240px; height: 240px; max-width: 100%; }
        .qrid { font-family: var(--mono); font-size: 15px; font-weight: 700; letter-spacing: .06em; margin-top: 10px; }

        .empty { color: var(--ink-3); font-size: 14px; padding: 12px 0; }
        .toast { position: fixed; left: 50%; bottom: 26px; transform: translateX(-50%); background: var(--ink); color: var(--ground); padding: 13px 20px; border-radius: 12px; font-size: 14.5px; font-weight: 600; max-width: 88vw; text-align: center; z-index: 50; }
        .fatal { background: var(--bad-bg); border: 1px solid #EFC2B8; color: #7A241A; border-radius: 14px; padding: 18px; font-size: 14.5px; line-height: 1.6; }
    </style>
</head>
<body>
<div class="app">
    <div class="offline" id="offline" hidden>오프라인 · 내 QR 을 반장에게 보여 주세요</div>

    <div class="body">
        <div class="who">
            <div class="avatar" id="ini">··</div>
            <div>
                <div class="who-name" id="nm">{{ $employee?->name ?? $user?->name ?? '작업자' }}</div>
                <div class="who-sub" id="sb">불러오는 중…</div>
            </div>
        </div>

        <div id="main">
            <div class="card idle"><div class="under">불러오는 중…</div></div>
        </div>

        @if ($badgeQr)
            {{-- 인터넷이 끊기면 서버에 QR 을 받으러 갈 수 없다. 그래서 그림째로 미리 박아 둔다. --}}
            <section class="sect qrsect" id="myqr" hidden>
                <div class="sect-h">내 배지 QR</div>
                <div class="qrbox">
                    <img src="{{ $badgeQr['uri'] }}" alt="배지 QR" width="240" height="240">
                    @if ($badgeQr['badge'])
                        <div class="qrid">{{ $badgeQr['badge'] }}</div>
                    @endif
                    <div class="hint">반장이 이 QR 을 스캔하면 기록됩니다. <b>인터넷이 끊겨도 이 화면은 보입니다.</b></div>
                </div>
            </section>
        @endif

        <div class="sect">
            <div class="sect-h">오늘 내 기록</div>
            <div id="logs"><div class="empty">불러오는 중…</div></div>
        </div>

        <div class="sect">
            <div class="sect-h">그 밖에</div>
            <a class="link" href="{{ route('communication.index') }}">
                <div><b>메시지{{ ($messageUnreadCount ?? 0) > 0 ? ' · '.$messageUnreadCount : '' }}</b><span>현장 채팅방과 공지</span></div>
                <span class="go">›</span>
            </a>
            <a class="link" href="{{ route('attendance-app.ops-room') }}">
                <div><b>현장 상황실</b><span>오늘 한 일 · 자재 · 이슈 올리기</span></div>
                <span class="go">›</span>
            </a>
            @if($canProcessCrew)
                <a class="link" href="{{ route('attendance-app.index') }}#crew">
                    <div><b>팀 출퇴근 처리</b><span>팀 QR 을 연 뒤 작업자 배지를 연속 스캔</span></div>
                    <span class="go">›</span>
                </a>
            @endif
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var CSRF = document.querySelector('meta[name="csrf-token"]').content;
    var HAS_QR = document.getElementById('myqr') !== null;
    var state = { data: null, coords: null, permission: 'unknown', busy: false };
    var watchId = null;

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }

    function toast(msg) {
        var t = document.createElement('div');
        t.className = 'toast';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 3600);
    }

    function hhmm(sec) {
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
        return h + '<small>시간</small> ' + m + '<small>분</small>';
    }

    /**
     * 사다리 판정. 작업자가 고르는 게 아니라 여기서 정한다.
     * 서버가 준 상태와 브라우저가 아는 것(온라인 여부·위치 권한)을 합쳐 한 단을 고른다.
     */
    function decide(d) {
        if (!navigator.onLine) {
            return { tier: 'qr', why: '<b>인터넷이 끊겼습니다.</b> 아래 내 QR 을 반장에게 보여 주세요. 기록은 반장 휴대폰이 보냅니다.' };
        }
        if (!d.site) {
            return { tier: 'blocked', why: '<b>배정된 현장이 없습니다.</b> 관리자에게 현장 배정을 요청해 주세요.' };
        }
        if (d.clockedIn) return { tier: 'working' };

        if (!d.site.hasGeofence && !d.site.hasNetwork) {
            return { tier: 'manual', why: '<b>이 현장은 자동 기준이 아직 없습니다.</b> 관리자가 등록할 때까지 직접 눌러 주세요.' };
        }
        if (state.permission === 'denied') {
            return { tier: 'manual', fix: true, why: '<b>위치 권한이 꺼져 있습니다.</b> 켜면 다음부터 자동으로 찍힙니다.' };
        }
        if (d.state === 'on_site') return { tier: 'working' };

        return { tier: 'waiting', why: '<b>아직 현장 밖입니다.</b> 반경 안에 들어오거나 현장 WiFi 에 연결되면 자동으로 찍힙니다.' };
    }

    function chip(log) {
        if (log.needsReview) return '<span class="chip rev">확인 필요</span>';
        if (log.source === 'geo_auto') return '<span class="chip auto">자동</span>';
        if (log.source === 'qr') return '<span class="chip qr">QR</span>';
        return '<span class="chip hand">' + esc(log.sourceLabel) + '</span>';
    }

    function render() {
        var d = state.data;
        var main = document.getElementById('main');
        var offline = document.getElementById('offline');

        offline.hidden = navigator.onLine;

        if (!d || d.success === false) {
            main.innerHTML = '<div class="fatal">' + esc((d && d.error) || '정보를 불러오지 못했습니다. 잠시 뒤 다시 열어 주세요.') + '</div>';
            return;
        }

        var e = d.employee || {};
        document.getElementById('nm').textContent = e.name || '작업자';
        document.getElementById('ini').textContent = (e.name || '··').slice(0, 2);
        document.getElementById('sb').textContent = [e.number, e.trade, d.site ? d.site.code : null].filter(Boolean).join(' · ');

        var v = decide(d);
        var working = v.tier === 'working';
        var tone = working ? 'good' : (v.tier === 'qr' ? 'bad' : (v.tier === 'manual' || v.tier === 'blocked' ? 'warn' : 'idle'));
        var label = working ? '근무중' : (v.tier === 'qr' ? '오프라인' : (v.tier === 'waiting' ? '현장 밖' : '자동 안 됨'));

        var html = '<div class="card ' + tone + '">' +
            '<span class="pill ' + tone + '"><i></i>' + label + '</span>' +
            '<div class="big">' + (working ? hhmm(d.onSiteSeconds || 0) : '0<small>시간</small> 0<small>분</small>') + '</div>' +
            '<div class="under">' + (working && d.firstEnterAt ? '출근 ' + esc(d.firstEnterAt) : (d.site ? esc(d.site.code) + (d.site.radius ? ' · 반경 ' + d.site.radius + 'm' : '') : '현장 미배정')) + '</div>';

        if (working) {
            html += '<button class="btn dark" data-act="out">퇴근하기</button>' +
                '<div class="hint">현장을 벗어나고 10분이 지나면 <b>자동으로 퇴근 처리</b>됩니다. 안 눌러도 됩니다.</div>';
        } else {
            html += '<div class="why">' + v.why + '</div>';
            if (v.fix) html += '<button class="btn dark" data-act="perm">위치 권한 켜기</button>';
            if (v.tier === 'manual' || v.tier === 'waiting') {
                html += '<button class="btn ' + (v.tier === 'manual' ? 'hivis' : 'ghost') + '" data-act="in">' +
                    (v.tier === 'manual' ? '출근 누르기' : '그래도 직접 누르기') + '</button>' +
                    '<div class="hint">현장에 있는 것이 확인되면 바로 기록됩니다. 확인이 안 되면 <b>반장 승인</b>을 거칩니다.</div>';
            }
            if (v.tier === 'qr') {
                // 서버로 이동하지 않는다 — 끊긴 것이 인터넷이라 이동하면 아무 데도 못 간다.
                // 이미 화면에 박혀 있는 QR 을 펼치기만 한다.
                html += HAS_QR
                    ? '<button class="btn hivis" data-act="myqr">내 QR 보여주기</button>'
                    : '<div class="hint">배지 QR 이 아직 발급되지 않았습니다. 반장에게 팀 QR 스캔을 요청해 주세요.</div>';
            }
        }
        html += '</div>';
        main.innerHTML = html;

        var logs = document.getElementById('logs');
        logs.innerHTML = (d.logs || []).length
            ? d.logs.map(function (l) {
                return '<div class="row"><div class="row-t">' + esc(l.at) + '</div>' +
                    '<div class="row-m"><div class="row-a">' + esc(l.typeLabel) + '</div>' +
                    '<div class="row-b">' + esc(d.site ? d.site.code : '') + '</div></div>' + chip(l) + '</div>';
            }).join('')
            : '<div class="empty">아직 기록이 없습니다.</div>';
    }

    async function load() {
        try {
            var r = await fetch('{{ route('attendance-app.home') }}', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            state.data = await r.json();
        } catch (err) {
            // 통신 실패는 화면을 비우지 않는다 — 마지막으로 받은 내용을 그대로 둔다.
        }
        render();
    }

    /** 위치를 켜고 서버에 보낸다. 자동 판정은 서버가 한다. */
    function startWatch() {
        if (!navigator.geolocation || watchId !== null) return;
        watchId = navigator.geolocation.watchPosition(
            function (pos) {
                state.permission = 'granted';
                state.coords = pos.coords;
                ping(pos.coords);
            },
            function (err) {
                state.permission = err.code === 1 ? 'denied' : 'unavailable';
                render();
            },
            { enableHighAccuracy: true, maximumAge: 15000, timeout: 20000 }
        );
    }

    async function ping(c) {
        try {
            await fetch('{{ route('attendance-geo.ping') }}', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                body: JSON.stringify({
                    lat: c.latitude, lng: c.longitude,
                    accuracy: Math.round(c.accuracy || 0),
                    clientTs: Math.floor(Date.now() / 1000)
                })
            });
            await load();
        } catch (err) { /* 일시 오류는 넘어간다 — 다음 신호가 곧 온다 */ }
    }

    async function punch(direction) {
        if (state.busy) return;
        state.busy = true;
        var body = { direction: direction };
        if (state.coords) {
            body.lat = state.coords.latitude;
            body.lng = state.coords.longitude;
            body.accuracy = Math.round(state.coords.accuracy || 0);
        }
        try {
            var r = await fetch('{{ route('attendance-app.punch') }}', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                body: JSON.stringify(body)
            });
            var j = await r.json();
            toast(j.message || j.error || '처리했습니다.');
            await load();
        } catch (err) {
            toast('보내지 못했습니다. 인터넷을 확인하고 다시 눌러 주세요.');
        }
        state.busy = false;
    }

    document.addEventListener('click', function (ev) {
        var el = ev.target.closest('[data-act]');
        if (!el) return;
        ev.preventDefault();
        var act = el.getAttribute('data-act');
        if (act === 'in' || act === 'out') return punch(act === 'in' ? 'in' : 'out');
        if (act === 'perm') { state.permission = 'unknown'; startWatch(); return render(); }
        if (act === 'myqr') {
            var box = document.getElementById('myqr');
            if (!box) return;
            box.hidden = false;
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    window.addEventListener('online', render);
    window.addEventListener('offline', render);

    // 화면을 다시 켰을 때 오래된 내용을 보고 있지 않도록 한 번 더 부른다.
    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });

    load();
    startWatch();
    setInterval(load, 30000);
})();
</script>
</body>
</html>

{{--
    작업자 출퇴근 화면.

    규칙 하나 — 작업자에게 방법을 고르게 하지 않는다. 화면이 상황을 보고 알아서
    자동 → 직접 → QR 로 내려가고, 보이는 것은 큰 버튼 하나와 "왜 이 방법인지" 한 줄이다.

    디자인의 기준은 현장이다. 애리조나 햇빛 아래에서 장갑 낀 손으로 보는 화면이라
    바탕은 밝게(햇빛에서는 밝은 화면이 더 잘 보인다), 손가락이 닿는 것은 크게 잡았다.
    다만 상태 카드 하나만은 짙은 금속판처럼 두었다 — 오늘 몇 시간 일했는지가 이 화면의
    전부이고, 그 숫자가 종이 위에 떠 있으면 눈이 어디를 봐야 할지 헤맨다.
--}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#F4F2ED">
    <title>내 출퇴근 · DASOL PRISM</title>

    {{-- 홈 화면에 추가하면 앱이 된다. --}}
    <link rel="manifest" href="{{ route('worker-app.manifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="내 출퇴근">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <style>
        :root {
            color-scheme: light;

            /* 현장 표지판의 어휘. 형광색은 글자로 쓰지 않는다 — 채움으로 쓰고 검은 글자를 얹는다. */
            --paper:   #F4F2ED;
            --card:    #FFFFFF;
            --slab:    #17160F;
            --slab-2:  #23221A;
            --ink:     #17160F;
            --ink-2:   #625E52;
            --ink-3:   #96917F;
            --rule:    #E2DED3;
            --hivis:   #D8E000;

            --ok:      #167A46;
            --ok-bg:   #E3F1E8;
            --warn:    #96600A;
            --warn-bg: #FAEFD9;
            --bad:     #B0392A;
            --bad-bg:  #F8E5E1;
            --info:    #2B4C9B;
            --info-bg: #E6EAF7;

            --mono: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, monospace;
            --tabh: 66px;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: var(--paper); color: var(--ink);
            font-family: system-ui, -apple-system, 'Apple SD Gothic Neo', 'Malgun Gothic', sans-serif;
            font-size: 16px; line-height: 1.6; -webkit-font-smoothing: antialiased;
        }
        .app { max-width: 520px; margin: 0 auto; min-height: 100dvh; display: flex; flex-direction: column; }

        /* ── 머리 ─────────────────────────────────────────────────── */
        .offline {
            background: var(--slab); color: var(--hivis); text-align: center;
            font-family: var(--mono); font-size: 11px; letter-spacing: .12em; padding: 6px;
        }
        .top {
            position: sticky; top: 0; z-index: 20;
            background: color-mix(in srgb, var(--paper) 88%, transparent);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--rule);
            display: flex; align-items: center; gap: 11px; padding: 12px 18px;
        }
        .tag {
            width: 42px; height: 42px; border-radius: 11px; flex: none;
            background: var(--slab); color: var(--hivis);
            display: grid; place-items: center; font-weight: 800; font-size: 15px; letter-spacing: -.02em;
        }
        .who { flex: 1; min-width: 0; }
        .who b { display: block; font-size: 16px; font-weight: 750; line-height: 1.3; }
        .who span {
            display: block; font-family: var(--mono); font-size: 11px;
            color: var(--ink-3); letter-spacing: .02em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .langs { display: flex; gap: 3px; flex: none; }
        .langs button {
            font-family: var(--mono); font-size: 10.5px; font-weight: 700;
            padding: 5px 7px; border-radius: 6px; border: 1px solid var(--rule);
            background: transparent; color: var(--ink-3); cursor: pointer;
        }
        .langs button[aria-pressed="true"] { background: var(--slab); color: var(--paper); border-color: var(--slab); }

        main { flex: 1; padding: 16px 18px calc(var(--tabh) + env(safe-area-inset-bottom) + 20px); }

        /* ── 계기판 ───────────────────────────────────────────────── */
        .slab {
            background: var(--slab); color: #F6F5EE;
            border-radius: 20px; padding: 22px 20px 20px;
            position: relative; overflow: hidden;
        }
        /* 상태는 위쪽 굵은 선 하나로 말한다. 카드 전체를 물들이는 것보다 조용하고 분명하다. */
        .slab::before {
            content: ""; position: absolute; inset: 0 0 auto 0; height: 4px; background: var(--accent, #55524A);
        }
        .slab.is-working { --accent: var(--hivis); }
        .slab.is-manual  { --accent: #E0A33A; }
        .slab.is-offline { --accent: #D3604E; }
        .slab.is-waiting { --accent: #55524A; }

        .state { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: #CFCCC0; }
        .state i { width: 8px; height: 8px; border-radius: 50%; background: var(--accent, #55524A); display: block; flex: none; }
        @media (prefers-reduced-motion: no-preference) {
            .slab.is-working .state i { animation: pulse 2.4s ease-in-out infinite; }
        }
        @keyframes pulse { 0%,100% { opacity: 1 } 50% { opacity: .3 } }

        .clock {
            font-size: 56px; font-weight: 800; letter-spacing: -.045em; line-height: 1.02;
            font-variant-numeric: tabular-nums; margin: 14px 0 4px; color: #FFFDF5;
        }
        .clock small { font-size: 20px; font-weight: 700; margin: 0 2px 0 3px; color: #9C9889; }
        .meta { font-family: var(--mono); font-size: 12px; color: #9C9889; letter-spacing: .02em; }

        .why {
            margin-top: 16px; padding-top: 15px; border-top: 1px solid #34322A;
            font-size: 14.5px; line-height: 1.55; color: #CFCCC0;
        }
        .why b { color: #FFFDF5; font-weight: 700; }

        .btn {
            width: 100%; margin-top: 14px; padding: 18px; border: none; border-radius: 15px;
            font-family: inherit; font-size: 17px; font-weight: 750; letter-spacing: -.01em;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn.go    { background: var(--hivis); color: var(--slab); box-shadow: inset 0 -3px 0 rgba(0,0,0,.18); }
        .btn.stop  { background: #F6F5EE; color: var(--slab); box-shadow: inset 0 -3px 0 rgba(0,0,0,.14); }
        .btn.quiet { background: transparent; color: #CFCCC0; border: 1.5px solid #3C3A31; font-size: 15px; font-weight: 650; padding: 15px; }
        .btn:active { transform: translateY(1px); }
        .btn:focus-visible { outline: 3px solid var(--hivis); outline-offset: 2px; }
        .note { font-size: 12.5px; color: #8E8A7C; margin-top: 10px; line-height: 1.5; }
        .note b { color: #CFCCC0; }

        /* ── 종이 위 요소 ─────────────────────────────────────────── */
        .sec { margin-top: 26px; }
        .sec-h {
            font-family: var(--mono); font-size: 11px; letter-spacing: .14em;
            text-transform: uppercase; color: var(--ink-3); margin-bottom: 10px;
            display: flex; justify-content: space-between; align-items: baseline; gap: 10px;
        }
        .sec-h em { font-style: normal; color: var(--ink-2); letter-spacing: 0; text-transform: none; font-size: 12px; }

        .panel { background: var(--card); border: 1px solid var(--rule); border-radius: 16px; overflow: hidden; }
        .row { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--rule); }
        .row:last-child { border-bottom: none; }
        .row-k { font-family: var(--mono); font-size: 13px; font-weight: 600; font-variant-numeric: tabular-nums; flex: none; }
        .row-m { flex: 1; min-width: 0; }
        .row-a { font-weight: 650; font-size: 15px; letter-spacing: -.01em; }
        .row-b { font-size: 12px; color: var(--ink-3); font-family: var(--mono); }
        .row-n { font-family: var(--mono); font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; flex: none; }

        .chip {
            font-size: 11px; font-weight: 700; padding: 4px 9px; border-radius: 7px;
            font-family: var(--mono); white-space: nowrap; letter-spacing: .03em; flex: none;
        }
        .chip.auto { background: var(--ok-bg);   color: var(--ok); }
        .chip.hand { background: var(--info-bg); color: var(--info); }
        .chip.qr   { background: #F0E9F6;        color: #63398C; }
        .chip.rev  { background: var(--warn-bg); color: var(--warn); }

        .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .stat { background: var(--card); border: 1px solid var(--rule); border-radius: 16px; padding: 15px 17px; }
        .stat-k { font-family: var(--mono); font-size: 10.5px; letter-spacing: .12em; text-transform: uppercase; color: var(--ink-3); }
        .stat-v { font-size: 30px; font-weight: 800; letter-spacing: -.035em; font-variant-numeric: tabular-nums; margin-top: 2px; }
        .stat-v small { font-size: 15px; font-weight: 650; color: var(--ink-3); margin-left: 1px; }

        .money { background: var(--slab); color: #F6F5EE; border-radius: 20px; padding: 22px 20px; }
        .money .stat-k { color: #8E8A7C; }
        .money .amt {
            font-size: 42px; font-weight: 800; letter-spacing: -.04em;
            font-variant-numeric: tabular-nums; margin: 6px 0 2px; color: #FFFDF5;
        }
        .money .amt em { font-style: normal; color: var(--hivis); }
        .money .sub { font-family: var(--mono); font-size: 11.5px; color: #8E8A7C; }
        .money .line {
            display: flex; justify-content: space-between; gap: 12px;
            font-size: 13.5px; padding: 9px 0; border-top: 1px solid #34322A;
            font-variant-numeric: tabular-nums; color: #CFCCC0;
        }
        .money .line:first-of-type { margin-top: 14px; }
        .money .line b { color: #FFFDF5; font-weight: 700; }

        .qr { background: var(--card); border: 1px solid var(--rule); border-radius: 20px; padding: 22px; text-align: center; }
        .qr img { display: block; margin: 0 auto; width: 232px; height: 232px; max-width: 100%; }
        .qr-id {
            font-family: var(--mono); font-size: 16px; font-weight: 700;
            letter-spacing: .08em; margin-top: 12px;
        }
        .qr-note { font-size: 12.5px; color: var(--ink-3); margin-top: 8px; line-height: 1.5; }
        .qr-note b { color: var(--ink-2); }

        .link {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 16px 17px; border: 1px solid var(--rule); border-radius: 16px;
            background: var(--card); text-decoration: none; color: inherit; margin-top: 10px;
        }
        .link b { font-size: 15.5px; font-weight: 700; display: block; letter-spacing: -.01em; }
        .link span { font-size: 12.5px; color: var(--ink-3); }
        .link .go { color: var(--ink-3); font-size: 22px; flex: none; line-height: 1; }

        .empty { color: var(--ink-3); font-size: 14px; padding: 18px 16px; text-align: center; }
        .fatal {
            background: var(--bad-bg); border: 1px solid #EEC3BB; color: #7C2418;
            border-radius: 16px; padding: 20px; font-size: 14.5px; line-height: 1.6;
        }

        /* ── 아래 탭 ──────────────────────────────────────────────── */
        .tabs {
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 30;
            display: grid; grid-template-columns: repeat(4, 1fr);
            background: color-mix(in srgb, var(--paper) 92%, transparent);
            backdrop-filter: blur(14px);
            border-top: 1px solid var(--rule);
            padding-bottom: env(safe-area-inset-bottom);
        }
        .tabs > div { max-width: 520px; margin: 0 auto; width: 100%; display: contents; }
        .tab {
            background: none; border: none; cursor: pointer; font-family: inherit;
            padding: 9px 0 11px; display: flex; flex-direction: column; align-items: center; gap: 4px;
            font-size: 11px; font-weight: 650; color: var(--ink-3); position: relative;
        }
        .tab svg { width: 22px; height: 22px; display: block; }
        .tab[aria-selected="true"] { color: var(--ink); }
        .tab[aria-selected="true"]::before {
            content: ""; position: absolute; top: 0; left: 50%; transform: translateX(-50%);
            width: 26px; height: 3px; border-radius: 0 0 3px 3px; background: var(--hivis);
        }
        .tab .dot {
            position: absolute; top: 7px; right: calc(50% - 18px);
            min-width: 16px; height: 16px; padding: 0 4px; border-radius: 999px;
            background: var(--bad); color: #fff; font-size: 10px; font-weight: 800;
            display: grid; place-items: center; font-family: var(--mono);
        }

        .toast {
            position: fixed; left: 50%; bottom: calc(var(--tabh) + env(safe-area-inset-bottom) + 18px);
            transform: translateX(-50%); background: var(--slab); color: #F6F5EE;
            padding: 14px 20px; border-radius: 13px; font-size: 14.5px; font-weight: 600;
            max-width: 88vw; text-align: center; z-index: 50; box-shadow: 0 10px 30px -10px rgba(0,0,0,.5);
        }
    </style>
</head>
<body>
<div class="app">
    <div class="offline" id="offline" hidden>오프라인 · 내 QR 을 반장에게 보여 주세요</div>

    <header class="top">
        <div class="tag" id="tag">··</div>
        <div class="who">
            <b id="nm">{{ $employee?->name ?? $user?->name ?? '작업자' }}</b>
            <span id="sb">불러오는 중…</span>
        </div>
        <div class="langs" id="langs">
            <button data-lang="ko" aria-pressed="true">KO</button>
            <button data-lang="en" aria-pressed="false">EN</button>
            <button data-lang="es" aria-pressed="false">ES</button>
        </div>
    </header>

    <main id="view">
        <div class="slab is-waiting"><div class="meta">불러오는 중…</div></div>
    </main>

    <nav class="tabs" id="tabs" aria-label="화면 이동">
        <button class="tab" data-tab="home" aria-selected="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7.2V12l3.1 2"/></svg>
            출퇴근
        </button>
        <button class="tab" data-tab="work" aria-selected="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><rect x="3" y="7.5" width="18" height="12.5" rx="2.5"/><path d="M8.8 7.5V5.6a1.6 1.6 0 0 1 1.6-1.6h3.2a1.6 1.6 0 0 1 1.6 1.6v1.9"/><path d="M3 12.6h18"/></svg>
            근무
        </button>
        <button class="tab" data-tab="pay" aria-selected="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M12 3.5v17"/><path d="M16.2 7.4c0-1.5-1.9-2.6-4.2-2.6s-4.2 1.1-4.2 2.6 1.9 2.3 4.2 3 4.2 1.5 4.2 3-1.9 2.6-4.2 2.6-4.2-1.1-4.2-2.6"/></svg>
            급여
        </button>
        <button class="tab" data-tab="me" aria-selected="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="8.2" r="3.9"/><path d="M4.8 20c0-3.4 3.2-6.1 7.2-6.1s7.2 2.7 7.2 6.1"/></svg>
            나
        </button>
    </nav>
</div>

@if ($badgeQr)
    {{-- 인터넷이 끊기면 서버에 QR 을 받으러 갈 수 없다. 그림째로 미리 박아 둔다. --}}
    <template id="qr-tpl">
        <div class="qr">
            <img src="{{ $badgeQr['uri'] }}" alt="배지 QR" width="232" height="232">
            @if ($badgeQr['badge'])
                <div class="qr-id">{{ $badgeQr['badge'] }}</div>
            @endif
            <div class="qr-note">반장이 이 QR 을 스캔하면 기록됩니다. <b>인터넷이 끊겨도 보입니다.</b></div>
        </div>
    </template>
@endif

@include('partials.install-app', ['installLang' => $employee?->preferred_language])

<script>
(function () {
    'use strict';

    var CSRF = document.querySelector('meta[name="csrf-token"]').content;
    var QR_TPL = document.getElementById('qr-tpl');
    var UNREAD = {{ (int) ($messageUnreadCount ?? 0) }};

    var state = { data: null, coords: null, permission: 'unknown', busy: false, tab: 'home', lang: 'ko', tick: 0 };
    var watchId = null;

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }
    function toast(msg) {
        var t = document.createElement('div');
        t.className = 'toast'; t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 3600);
    }
    function hm(sec) {
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
        return h + '<small>시간</small>' + m + '<small>분</small>';
    }
    function money(v, cur) {
        var sign = cur === 'KRW' ? '₩' : '$';
        return sign + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /**
     * 사다리 판정. 작업자가 고르는 게 아니라 여기서 정한다.
     * 서버가 준 상태와 브라우저만 아는 것(온라인 여부·위치 권한)을 합쳐 한 단을 고른다.
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

    function qrBlock() {
        return QR_TPL ? QR_TPL.innerHTML : '<div class="empty">배지 QR 이 아직 발급되지 않았습니다.</div>';
    }

    // ── 탭 ────────────────────────────────────────────────────────
    function tabHome(d) {
        var v = decide(d);
        var working = v.tier === 'working';
        var tone = working ? 'working' : (v.tier === 'qr' ? 'offline' : (v.tier === 'waiting' ? 'waiting' : 'manual'));
        var label = working ? '근무중' : (v.tier === 'qr' ? '오프라인' : (v.tier === 'waiting' ? '현장 밖' : '자동 안 됨'));
        var secs = (d.elapsedSeconds || 0) + (working ? state.tick : 0);

        var h = '<div class="slab is-' + tone + '">' +
            '<div class="state"><i></i>' + label + '</div>' +
            '<div class="clock">' + hm(secs) + '</div>' +
            '<div class="meta">' + (working && d.firstEnterAt
                ? '출근 ' + esc(d.firstEnterAt) + (d.site ? ' · ' + esc(d.site.code) : '')
                : (d.site ? esc(d.site.code) + (d.site.radius ? ' · 반경 ' + d.site.radius + 'm' : '') : '현장 미배정')) + '</div>';

        if (working) {
            h += '<button class="btn stop" data-act="out">퇴근하기</button>' +
                 '<div class="note">현장을 벗어나고 10분이 지나면 <b>자동으로 퇴근 처리</b>됩니다. 안 눌러도 됩니다.</div>';
        } else {
            h += '<div class="why">' + v.why + '</div>';
            if (v.fix) h += '<button class="btn stop" data-act="perm">위치 권한 켜기</button>';
            if (v.tier === 'manual' || v.tier === 'waiting') {
                h += '<button class="btn ' + (v.tier === 'manual' ? 'go' : 'quiet') + '" data-act="in">' +
                     (v.tier === 'manual' ? '출근 누르기' : '그래도 직접 누르기') + '</button>' +
                     '<div class="note">현장에 있는 것이 확인되면 바로 기록됩니다. 확인이 안 되면 <b>반장 승인</b>을 거칩니다.</div>';
            }
            if (v.tier === 'qr') h += '<button class="btn go" data-act="goqr">내 QR 보여주기</button>';
        }
        h += '</div>';

        h += '<div class="sec"><div class="sec-h">오늘 내 기록</div><div class="panel">';
        h += (d.logs || []).length
            ? d.logs.map(function (l) {
                return '<div class="row"><div class="row-k">' + esc(l.at) + '</div>' +
                    '<div class="row-m"><div class="row-a">' + esc(l.typeLabel) + '</div>' +
                    '<div class="row-b">' + esc(d.site ? d.site.code : '') + '</div></div>' + chip(l) + '</div>';
            }).join('')
            : '<div class="empty">아직 기록이 없습니다.</div>';
        h += '</div></div>';

        h += '<div class="sec"><div class="sec-h">그 밖에</div>' +
            '<a class="link" href="{{ route('communication.index') }}"><div><b>메시지' +
            (UNREAD ? ' · ' + UNREAD : '') + '</b><span>현장 채팅방과 공지</span></div><span class="go">›</span></a>' +
            '<a class="link" href="{{ route('attendance-app.ops-room') }}"><div><b>현장 상황실</b>' +
            '<span>오늘 한 일 · 자재 · 이슈 올리기</span></div><span class="go">›</span></a>' +
            '</div>';
        return h;
    }

    function tabWork(d) {
        var w = d.week || { regularHours: 0, overtimeHours: 0, days: [] };
        var h = '<div class="stats">' +
            '<div class="stat"><div class="stat-k">이번 주 정규</div><div class="stat-v">' + w.regularHours + '<small>시간</small></div></div>' +
            '<div class="stat"><div class="stat-k">연장 ×' + (d.pay ? d.pay.multiplier : 1.5) + '</div><div class="stat-v">' + w.overtimeHours + '<small>시간</small></div></div>' +
            '</div>';

        h += '<div class="sec"><div class="sec-h">일자별<em>' + esc(w.from || '') + ' – ' + esc(w.to || '') + '</em></div><div class="panel">';
        h += (w.days || []).length
            ? w.days.map(function (x) {
                var total = (x.regularHours + x.overtimeHours).toFixed(1);
                return '<div class="row"><div class="row-k">' + esc(x.label) + '<div class="row-b">' + esc(x.weekday) + '</div></div>' +
                    '<div class="row-m"><div class="row-a">' + esc(x.in || '—') + ' → ' + esc(x.out || '—') + '</div>' +
                    '<div class="row-b">' + (x.overtimeHours ? '연장 ' + x.overtimeHours + 'h' : '정규') + '</div></div>' +
                    '<div class="row-n">' + total + 'h</div>' +
                    (x.settled ? '' : '<span class="chip rev">미확정</span>') + '</div>';
            }).join('')
            : '<div class="empty">이번 주 기록이 아직 없습니다.</div>';
        h += '</div></div>';

        h += '<div class="sec"><div class="sec-h">기록이 틀렸다면</div>' +
            '<div class="panel"><div class="empty" style="text-align:left;padding:16px">' +
            '반장에게 말씀해 주세요. 화면에서 바로 정정을 요청하는 기능은 준비 중입니다.</div></div></div>';
        return h;
    }

    function tabPay(d) {
        var p = d.pay || {};
        var w = d.week || {};
        var h = '';

        if (!p.hasRate) {
            h += '<div class="slab is-manual"><div class="state"><i></i>단가 미정</div>' +
                 '<div class="why"><b>아직 시급이 정해지지 않았습니다.</b> 정해지면 이 화면에 이번 주 예상 금액이 나옵니다. ' +
                 '근무 시간은 그대로 쌓이고 있으니 걱정하지 않으셔도 됩니다.</div></div>';
        } else {
            h += '<div class="money"><div class="stat-k">이번 주 예상</div>' +
                '<div class="amt"><em>' + (p.currency === 'KRW' ? '₩' : '$') + '</em>' +
                Number(p.estimated).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div>' +
                '<div class="sub">' + esc(w.from || '') + ' – ' + esc(w.to || '') + ' · 세금·공제 전</div>' +
                '<div class="line"><span>정규 ' + w.regularHours + 'h × ' + money(p.rate, p.currency) + '</span><b>' + money(p.regularPay, p.currency) + '</b></div>' +
                '<div class="line"><span>연장 ' + w.overtimeHours + 'h × ' + money(p.rate * p.multiplier, p.currency) + '</span><b>' + money(p.overtimePay, p.currency) + '</b></div>' +
                '</div>' +
                '<div class="note" style="color:var(--ink-3);margin-top:12px">실제 지급액은 세금과 공제를 뺀 금액입니다. 확정 명세서는 마감 뒤에 올라옵니다.</div>';
        }

        h += '<div class="sec"><div class="sec-h">지난 명세서</div><div class="panel">';
        h += (p.payslips || []).length
            ? p.payslips.map(function (s) {
                return '<div class="row"><div class="row-m"><div class="row-a">' + money(s.net, p.currency) + '</div>' +
                    '<div class="row-b">' + esc(s.from || '') + ' – ' + esc(s.to || '') + '</div></div>' +
                    '<span class="chip ' + (s.status === 'paid' ? 'auto' : 'hand') + '">' + esc(s.status === 'paid' ? '지급' : s.status) + '</span></div>';
            }).join('')
            : '<div class="empty">아직 명세서가 없습니다.</div>';
        h += '</div></div>';
        return h;
    }

    function tabMe(d) {
        var e = d.employee || {};
        var h = '<div class="sec" style="margin-top:0"><div class="sec-h">내 배지 QR</div>' + qrBlock() + '</div>';

        h += '<div class="sec"><div class="sec-h">내 정보</div><div class="panel">' +
            kv('이름', e.name) + kv('사번', e.number) + kv('직종', e.trade) +
            kv('현장', d.site ? d.site.code + ' · ' + d.site.name : null) +
            '</div></div>';

        h += '<div class="sec"><div class="sec-h">현장 자동 인식</div><div class="panel">' +
            kv('GPS 반경', d.site && d.site.hasGeofence ? (d.site.radius + 'm 등록됨') : '미등록') +
            kv('현장 WiFi', d.site && d.site.hasNetwork ? '등록됨' : '미등록') +
            '</div>' +
            '<div class="note" style="color:var(--ink-3);margin-top:10px">둘 중 하나만 등록돼 있어도 자동 출퇴근이 됩니다. 둘 다 없으면 직접 눌러야 합니다.</div>' +
            '</div>';

        // 홈 화면에 이미 있으면 이 줄은 안 보인다 — 있는 걸 또 설치하라고 하지 않는다.
        if (window.DasolInstall && !window.DasolInstall.installed()) {
            h += '<div class="sec"><button type="button" class="link" data-act="install" ' +
                'style="width:100%;cursor:pointer;font-family:inherit;text-align:left">' +
                '<div><b>＋ ' + esc(window.DasolInstall.label()) + '</b>' +
                '<div class="row-a" style="margin-top:3px">다음부터 아이콘만 누르면 열립니다</div></div>' +
                '<span class="go">›</span></button></div>';
        }

        h += '<div class="sec"><form method="POST" action="{{ route('logout') }}">' +
            '<input type="hidden" name="_token" value="' + CSRF + '">' +
            '<button type="submit" class="link" style="width:100%;cursor:pointer;font-family:inherit;text-align:left">' +
            '<div><b style="color:var(--bad)">로그아웃</b></div><span class="go">›</span></button>' +
            '</form></div>';
        return h;
    }

    function kv(k, v) {
        return '<div class="row"><div class="row-m"><div class="row-b">' + esc(k) + '</div>' +
            '<div class="row-a">' + esc(v || '—') + '</div></div></div>';
    }

    // ── 그리기 ────────────────────────────────────────────────────
    function render() {
        var d = state.data;
        var view = document.getElementById('view');
        document.getElementById('offline').hidden = navigator.onLine;

        if (!d || d.success === false) {
            view.innerHTML = '<div class="fatal">' + esc((d && d.error) || '정보를 불러오지 못했습니다. 잠시 뒤 다시 열어 주세요.') + '</div>';
            return;
        }

        var e = d.employee || {};
        document.getElementById('nm').textContent = e.name || '작업자';
        document.getElementById('tag').textContent = (e.name || '··').slice(0, 2);
        document.getElementById('sb').textContent =
            [e.number, e.trade, d.site ? d.site.code : null].filter(Boolean).join(' · ');

        view.innerHTML = state.tab === 'home' ? tabHome(d)
            : state.tab === 'work' ? tabWork(d)
            : state.tab === 'pay' ? tabPay(d) : tabMe(d);

        Array.prototype.forEach.call(document.querySelectorAll('#tabs .tab'), function (b) {
            b.setAttribute('aria-selected', b.dataset.tab === state.tab ? 'true' : 'false');
        });
    }

    async function load() {
        try {
            var r = await fetch('{{ route('attendance-app.home') }}', {
                credentials: 'same-origin', headers: { Accept: 'application/json' }
            });
            state.data = await r.json();
            state.tick = 0;
        } catch (err) {
            // 통신 실패는 화면을 비우지 않는다 — 마지막으로 받은 내용을 그대로 둔다.
        }
        render();
    }

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
            // 한 번 찍어 본 뒤에 권한다. 쓸모를 모르는 채로 받는 설치 권유는 닫힌다.
            if (window.DasolInstall) {
                setTimeout(function () { window.DasolInstall.offer(); }, 1400);
            }
        } catch (err) {
            toast('보내지 못했습니다. 인터넷을 확인하고 다시 눌러 주세요.');
        }
        state.busy = false;
    }

    document.getElementById('view').addEventListener('click', function (ev) {
        var el = ev.target.closest('[data-act]');
        if (!el) return;
        ev.preventDefault();
        var act = el.getAttribute('data-act');
        if (act === 'in' || act === 'out') return punch(act);
        if (act === 'perm') { state.permission = 'unknown'; startWatch(); return render(); }
        if (act === 'install') return window.DasolInstall.show();
        // 서버로 이동하지 않는다 — 끊긴 것이 인터넷이라 이동하면 아무 데도 못 간다.
        if (act === 'goqr') { state.tab = 'me'; render(); window.scrollTo({ top: 0 }); }
    });

    document.getElementById('tabs').addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-tab]');
        if (!b) return;
        state.tab = b.dataset.tab;
        render();
        window.scrollTo({ top: 0 });
    });

    document.getElementById('langs').addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-lang]');
        if (!b) return;
        state.lang = b.dataset.lang;
        Array.prototype.forEach.call(this.querySelectorAll('[data-lang]'), function (n) {
            n.setAttribute('aria-pressed', n.dataset.lang === state.lang ? 'true' : 'false');
        });
        // 화면 글자 번역은 아직 없다. 고른 언어는 기억해 두고 다음 단계에서 쓴다.
    });

    window.addEventListener('online', render);
    window.addEventListener('offline', render);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });

    // 근무중일 때 초가 흐르는 것이 보여야 "지금 세고 있다"는 것이 전달된다.
    setInterval(function () {
        if (state.tab !== 'home' || !state.data || !state.data.clockedIn) return;
        state.tick += 30;
        render();
    }, 30000);

    load();
    startWatch();
    setInterval(load, 60000);
})();
</script>
</body>
</html>

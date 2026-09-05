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
    <meta name="theme-color" content="#FEE500">
    <title>내 출퇴근 · {{ \App\Support\Org::name() }}</title>

    {{-- 홈 화면에 추가하면 앱이 된다. --}}
    <link rel="manifest" href="{{ route('worker-app.manifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/attendance-apple-touch.png') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="내 출퇴근">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <style>
        /*
            카카오 디자인 언어를 그대로 쓴다.

            현장 작업자 대부분이 한국인이고 카카오톡을 매일 쓴다. 익숙한 색·모서리·간격을
            그대로 쓰면 "이건 어떻게 쓰는 거냐" 는 질문이 줄어든다.

            규격(카카오 브랜드 가이드):
              노랑 #FEE500 (R255 G232 B18) · 라벨 rgba(0,0,0,.85) · 모서리 12px · 바탕 #F2F3F5
              글자 #191919 / #767676 / #B0B8C1 · 구분선 #EDEEF0

            카카오 가이드는 판(panel)을 흰 판·노란 판 두 가지로 두고, 어느 쪽이든 그 위의
            글자와 아이콘은 <b>검정</b>으로 쓴다. 그 규칙을 그대로 따른다:
              1. <b>면</b>  — 머리띠·QR 판·지금 일하는 중인 카드. 노란 바닥에 검정 글자.
              2. <b>행동</b> — 지금 눌러야 할 버튼.
              3. <b>동그라미</b> — 아이콘·번호를 담는 원형 배지. 안의 글자는 검정.
            노란 면 위에 또 노란 것을 얹지 않는다(그때는 검정으로 뒤집는다). 목록처럼
            오래 읽는 것은 흰 판 위에 둔다 — 노랑은 눈이 오래 머무는 색이 아니다.
        */
        :root {
            color-scheme: light;

            --kakao:    #FEE500;
            --kakao-2:  #F6DC00;          /* 눌렀을 때 */
            --label:    rgba(0,0,0,.85);  /* 노랑 위 글자 — 카카오 규격 */

            --paper:    #F2F3F5;
            --card:     #FFFFFF;
            --ink:      #191919;
            --ink-2:    #767676;
            --ink-3:    #B0B8C1;
            --rule:     #EDEEF0;

            --ok:      #1E8E3E;  --ok-bg:   #E8F5EA;
            --warn:    #B26A00;  --warn-bg: #FFF4E0;
            --bad:     #D94C4C;  --bad-bg:  #FDECEC;
            --info:    #3E6BE0;  --info-bg: #ECF1FE;

            /* 예전에는 라벨까지 고정폭 글꼴이었다 — 산업용 계기판처럼 보였다.
               카카오는 UI 글자에 고정폭을 쓰지 않는다. 숫자 정렬은 tabular-nums 가 맡는다. */
            --mono: inherit;
            --tabh: 60px;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: var(--paper); color: var(--ink);
            font-family: "Pretendard Variable", Pretendard, -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', 'Malgun Gothic', 'Noto Sans KR', sans-serif;
            font-size: 15px; line-height: 1.55; -webkit-font-smoothing: antialiased;
        }
        .app { max-width: 520px; margin: 0 auto; min-height: 100dvh; display: flex; flex-direction: column; background: var(--paper); }

        /* ── 머리 ─────────────────────────────────────────────────── */
        .offline {
            background: var(--ink); color: #fff; text-align: center;
            font-size: 12px; font-weight: 600; padding: 7px;
        }
        /* 슈퍼관리자가 남의 화면을 들여다보는 중 — 실물이 아님을 분명히. */
        .peek {
            background: #3E6BE0; color: #fff; text-align: center; padding: 7px 12px;
            font-size: 12px; font-weight: 600;
        }
        .peek b { color: #fff; font-weight: 800; }
        /* 머리띠 — 노랑 면. 브랜드가 서는 자리이고, 그 위 글자는 전부 검정이다. */
        .top {
            position: sticky; top: 0; z-index: 20;
            background: var(--kakao); color: var(--label);
            display: flex; align-items: center; gap: 11px; padding: 13px 16px;
        }
        /* 노랑 위의 동그라미는 반대로 검정 — 노랑 위 노랑은 보이지 않는다. */
        .tag {
            width: 40px; height: 40px; border-radius: 50%; flex: none;
            background: rgba(0,0,0,.85); color: var(--kakao);
            display: grid; place-items: center; font-weight: 800; font-size: 14px;
        }
        .who { flex: 1; min-width: 0; }
        .who b { display: block; font-size: 16px; font-weight: 800; line-height: 1.3; color: var(--label); }
        .who span {
            display: block; font-size: 12px; color: rgba(0,0,0,.55);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .langs { display: flex; gap: 4px; flex: none; }
        .langs button {
            font-size: 11px; font-weight: 700; padding: 6px 9px; border-radius: 999px;
            border: 1px solid rgba(0,0,0,.1); background: rgba(255,255,255,.6); color: rgba(0,0,0,.6); cursor: pointer;
            font-family: inherit;
        }
        .langs button[aria-pressed="true"] { background: rgba(0,0,0,.85); color: #fff; border-color: transparent; }

        main { flex: 1; padding: 14px 16px calc(var(--tabh) + env(safe-area-inset-bottom) + 20px); }

        /* ── 오늘 근무 카드 ────────────────────────────────────────
           일하는 중일 때만 노랗다. 그 순간 이 화면에서 가장 중요한 것이 이 카드이고,
           나머지는 전부 흰 종이로 물러난다. */
        .slab {
            background: var(--card); color: var(--ink);
            border-radius: 16px; padding: 20px;
            position: relative; overflow: hidden;
        }
        /* ── 현장 QR 스캔 ─────────────────────────────────────────
           카메라 위에 얹히는 화면이라 바탕은 검정이다. 노란 테두리 하나만 두어
           "여기에 QR 을 맞추라"는 것을 말없이 알린다. */
        .scan {
            position: fixed; inset: 0; z-index: 90; background: #000;
            display: flex; align-items: center; justify-content: center;
        }
        .scan video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .scan-frame {
            position: relative; width: min(72vw, 280px); aspect-ratio: 1;
            border: 3px solid var(--kakao); border-radius: 20px;
            box-shadow: 0 0 0 100vmax rgba(0,0,0,.55);
        }
        .scan-hint {
            position: absolute; left: 0; right: 0; bottom: calc(18% + env(safe-area-inset-bottom));
            margin: 0; padding: 0 24px; text-align: center;
            color: #fff; font-size: 15px; font-weight: 700; line-height: 1.6; word-break: keep-all;
        }
        .scan-cancel {
            position: absolute; top: calc(14px + env(safe-area-inset-top)); right: 14px;
            width: 44px; height: 44px; border: none; border-radius: 50%;
            background: rgba(255,255,255,.9); color: #191919; font-size: 20px; font-weight: 800; cursor: pointer;
        }

        /* ── 전체공지 ───────────────────────────────────────────── */
        .notice { padding: 13px 15px; border-top: 1px solid var(--rule); }
        .notice:first-child { border-top: 0; }
        .notice.pin { background: #FFFCE6; }
        .notice-h { display: flex; gap: 8px; align-items: baseline; font-size: 14px; font-weight: 800; word-break: keep-all; }
        .notice-h span { margin-left: auto; flex: none; font-size: 11.5px; font-weight: 600; color: var(--ink-3); }
        .notice-b { margin-top: 4px; font-size: 13.5px; line-height: 1.6; color: var(--ink-2); white-space: pre-wrap; word-break: keep-all; }

        .slab.is-working { background: var(--kakao); color: var(--label); }
        .slab.is-manual  { background: var(--card); }
        .slab.is-offline { background: var(--card); }
        .slab.is-waiting { background: var(--card); }
        .slab::before { content: none; }   /* 카카오는 카드 위 색 띠를 쓰지 않는다 */

        .state { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 700; color: var(--ink-2); }
        .slab.is-working .state { color: rgba(0,0,0,.62); }
        .state i { width: 7px; height: 7px; border-radius: 50%; background: var(--ink-3); display: block; flex: none; }
        .slab.is-working .state i { background: #1E8E3E; }
        .slab.is-manual  .state i { background: #B26A00; }
        .slab.is-offline .state i { background: var(--bad); }
        @media (prefers-reduced-motion: no-preference) {
            .slab.is-working .state i { animation: pulse 2.4s ease-in-out infinite; }
        }
        @keyframes pulse { 0%,100% { opacity: 1 } 50% { opacity: .3 } }

        .clock {
            font-size: 52px; font-weight: 800; letter-spacing: -.04em; line-height: 1.05;
            font-variant-numeric: tabular-nums; margin: 10px 0 3px; color: inherit;
        }
        .clock small { font-size: 19px; font-weight: 700; margin: 0 2px 0 3px; opacity: .5; }
        .meta { font-size: 12.5px; color: var(--ink-2); }
        .slab.is-working .meta { color: rgba(0,0,0,.6); }

        .why {
            margin-top: 15px; padding-top: 14px; border-top: 1px solid var(--rule);
            font-size: 14px; line-height: 1.55; color: var(--ink-2);
        }
        .slab.is-working .why { border-top-color: rgba(0,0,0,.12); color: rgba(0,0,0,.7); }
        .why b { color: var(--ink); font-weight: 700; }
        .slab.is-working .why b { color: var(--label); }

        /* 버튼 — 카카오 규격: 모서리 12px, 굵은 라벨, 그림자 없음 */
        .btn {
            width: 100%; margin-top: 13px; padding: 15px; border: none; border-radius: 12px;
            font-family: inherit; font-size: 16px; font-weight: 700; letter-spacing: -.01em;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 7px;
            min-height: 52px;
        }
        .btn.go    { background: var(--kakao); color: var(--label); }
        .btn.stop  { background: var(--ink); color: #fff; }
        .btn.quiet { background: var(--card); color: var(--ink-2); border: 1px solid var(--rule); font-size: 15px; }
        .slab.is-working .btn.stop { background: rgba(0,0,0,.85); color: #fff; }
        .slab.is-working .btn.quiet { background: rgba(255,255,255,.5); border-color: rgba(0,0,0,.1); color: rgba(0,0,0,.7); }
        .btn:active { background: var(--kakao-2); }
        .btn.stop:active { opacity: .85; background: var(--ink); }
        .btn:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
        .note { font-size: 12.5px; color: var(--ink-3); margin-top: 9px; line-height: 1.5; }
        .note b { color: var(--ink-2); font-weight: 700; }
        .slab.is-working .note { color: rgba(0,0,0,.55); }
        .slab.is-working .note b { color: rgba(0,0,0,.75); }

        /* ── 목록·카드 ────────────────────────────────────────────── */
        .sec { margin-top: 24px; }
        .sec-h {
            font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 9px;
            display: flex; justify-content: space-between; align-items: baseline; gap: 10px;
            letter-spacing: -.01em;
        }
        .sec-h em { font-style: normal; color: var(--ink-3); font-size: 12px; font-weight: 500; }

        .panel { background: var(--card); border-radius: 14px; overflow: hidden; }
        .row { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--rule); }
        .row:last-child { border-bottom: none; }
        .row-k { font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; flex: none; color: var(--ink-2); }
        .row-m { flex: 1; min-width: 0; }
        .row-a { font-weight: 700; font-size: 15px; letter-spacing: -.01em; }
        .row-b { font-size: 12.5px; color: var(--ink-3); }
        .row-n { font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; flex: none; }

        .chip {
            font-size: 11px; font-weight: 700; padding: 4px 9px; border-radius: 999px;
            white-space: nowrap; flex: none;
        }
        .chip.auto { background: var(--ok-bg);   color: var(--ok); }
        .chip.hand { background: var(--info-bg); color: var(--info); }
        .chip.qr   { background: #F3EEFB;        color: #6B3FA0; }
        .chip.rev  { background: var(--warn-bg); color: var(--warn); }

        .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
        .stat { background: var(--card); border-radius: 14px; padding: 15px 16px; }
        .stat-k { font-size: 12px; color: var(--ink-2); font-weight: 600; }
        .stat-v { font-size: 28px; font-weight: 800; letter-spacing: -.03em; font-variant-numeric: tabular-nums; margin-top: 2px; }
        .stat-v small { font-size: 14px; font-weight: 700; color: var(--ink-3); margin-left: 1px; }

        /* 급여 — 노랑을 또 쓰지 않는다. 흰 카드에 숫자만 크게, 강조는 형광펜처럼. */
        .money { background: var(--card); color: var(--ink); border-radius: 16px; padding: 20px; }
        .money .stat-k { color: var(--ink-2); }
        .money .amt {
            font-size: 38px; font-weight: 800; letter-spacing: -.035em;
            font-variant-numeric: tabular-nums; margin: 5px 0 2px;
        }
        .money .amt em { font-style: normal; background: var(--kakao); border-radius: 6px; padding: 0 6px; }
        .money .sub { font-size: 12.5px; color: var(--ink-3); }
        .money .line {
            display: flex; justify-content: space-between; gap: 12px;
            font-size: 14px; padding: 10px 0; border-top: 1px solid var(--rule);
            font-variant-numeric: tabular-nums; color: var(--ink-2);
        }
        .money .line:first-of-type { margin-top: 14px; }
        .money .line b { color: var(--ink); font-weight: 700; }

        /* QR 판 — 카카오 포스터 그대로. 노랑 바닥에 검정 코드.
           인터넷이 끊겼을 때 반장에게 내미는 화면이라 멀리서도 한눈에 알아보여야 한다. */
        .qr { background: var(--kakao); color: var(--label); border-radius: 16px; padding: 22px; text-align: center; }
        .qr img { display: block; margin: 0 auto; width: 232px; height: 232px; max-width: 100%; }
        .qr-id { font-size: 16px; font-weight: 800; letter-spacing: .06em; margin-top: 12px; color: var(--label); }
        .qr-note { font-size: 12.5px; color: rgba(0,0,0,.6); margin-top: 8px; line-height: 1.5; }
        .qr-note b { color: var(--label); font-weight: 700; }

        .link {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 16px; border-radius: 14px;
            background: var(--card); text-decoration: none; color: inherit; margin-top: 9px;
        }
        .link b { font-size: 15px; font-weight: 700; display: block; letter-spacing: -.01em; }
        .link span { font-size: 12.5px; color: var(--ink-3); }
        /* 화살표는 노랑 동그라미 안에 — 카카오가 아이콘을 담는 방식이다. */
        .link .go {
            width: 28px; height: 28px; border-radius: 50%; flex: none;
            background: var(--kakao); color: var(--label);
            display: grid; place-items: center; font-size: 15px; font-weight: 800; line-height: 1;
        }

        .empty { color: var(--ink-3); font-size: 14px; padding: 20px 16px; text-align: center; }
        .fatal {
            background: var(--bad-bg); color: #A63232;
            border-radius: 14px; padding: 20px; font-size: 14px; line-height: 1.6;
        }
        .retry {
            display: block; width: 100%; margin-top: 14px; padding: 14px;
            border: none; border-radius: 12px; background: var(--ink);
            color: #fff; font-family: inherit; font-size: 15px; font-weight: 700; cursor: pointer;
        }

        /* ── 아직 연결되지 않은 계정 ──────────────────────────────────
           관리자가 이 앱을 처음 열면 반드시 보는 화면이다. 오류가 아니라 "다음에 할 일"로. */
        .setup-h { font-size: 24px; font-weight: 800; letter-spacing: -.03em; line-height: 1.3; margin: 12px 0 0; }
        .setup-who { font-size: 12.5px; color: var(--ink-3); margin-top: 8px; word-break: break-all; }
        .step-n {
            width: 24px; height: 24px; border-radius: 50%; flex: none;
            background: var(--kakao); color: var(--label);
            display: grid; place-items: center; font-size: 12px; font-weight: 800;
        }

        /* ── 현장에서 하는 나머지 일 (네 칸) ─────────────────────────
           손가락이 닿는 자리에 크게. 글자 링크 세 줄로 아래에 숨겨 두었을 때는
           «앱이 따로따로» 처럼 느껴졌다. */
        .quick { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-top: 12px; }
        .tile.wide { grid-column: 1 / -1; }
        .tile {
            position: relative; background: var(--card); border-radius: 14px; padding: 15px 14px 13px;
            text-decoration: none; color: var(--ink); display: block; min-height: 96px;
        }
        .tile:active { background: #F7F8F9; }
        .tile-i {
            display: grid; place-items: center; width: 34px; height: 34px; border-radius: 10px;
            background: var(--paper); color: var(--ink); margin-bottom: 9px;
        }
        .tile-i svg { width: 20px; height: 20px; display: block; }
        .tile b { display: block; font-size: 14.5px; font-weight: 800; line-height: 1.3; }
        .tile-s { display: block; font-size: 11.5px; color: var(--ink-2); line-height: 1.4; margin-top: 2px; }
        .tile-dot {
            position: absolute; top: 11px; right: 11px; min-width: 19px; height: 19px; padding: 0 6px;
            border-radius: 999px; background: #FF3B30; color: #fff; font-size: 11px; font-weight: 800;
            display: grid; place-items: center;
        }

        /* ── 내 직원 정보 만들기 (관리자 자가 연결) ───────────────── */
        .panel.padded { padding: 14px; }
        .link-why { font-size: 13px; color: var(--ink-2); line-height: 1.6; margin-bottom: 12px; }
        .link-why b { color: var(--ink); }
        .fld { display: block; margin-bottom: 10px; }
        .fld > span { display: block; font-size: 12px; font-weight: 700; color: var(--ink-2); margin-bottom: 5px; }
        .fld > span em { font-style: normal; font-weight: 400; color: var(--ink-3); }
        .fld input, .fld select {
            width: 100%; border: 1px solid var(--rule); border-radius: 10px; padding: 12px;
            font-size: 16px; font-family: inherit; background: var(--paper); color: var(--ink);
        }
        .fld-note { font-size: 11.5px; color: var(--ink-2); line-height: 1.5; margin: 2px 0 12px; }
        .fld-note b { color: var(--ink); }

        /* ── 아래 탭 ──────────────────────────────────────────────── */
        .tabs {
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 30;
            display: grid; grid-template-columns: repeat(4, 1fr);
            background: var(--card); border-top: 1px solid var(--rule);
            padding-bottom: env(safe-area-inset-bottom);
        }
        .tabs > div { max-width: 520px; margin: 0 auto; width: 100%; display: contents; }
        .tab {
            background: none; border: none; cursor: pointer; font-family: inherit;
            padding: 8px 0 10px; display: flex; flex-direction: column; align-items: center; gap: 3px;
            font-size: 11px; font-weight: 600; color: var(--ink-3); position: relative;
        }
        .tab svg { width: 24px; height: 24px; display: block; }
        /* 카카오는 고른 칸에 색 막대를 두지 않는다 — 글자와 아이콘이 진해질 뿐이다. */
        .tab[aria-selected="true"] { color: var(--ink); font-weight: 700; }
        .tab[aria-selected="true"]::before { content: none; }
        .tab .dot {
            position: absolute; top: 5px; right: calc(50% - 19px);
            min-width: 17px; height: 17px; padding: 0 5px; border-radius: 999px;
            background: #FF3B30; color: #fff; font-size: 10px; font-weight: 800;
            display: grid; place-items: center;
        }

        .toast {
            position: fixed; left: 50%; bottom: calc(var(--tabh) + env(safe-area-inset-bottom) + 16px);
            transform: translateX(-50%); background: rgba(25,25,25,.92); color: #fff;
            padding: 13px 20px; border-radius: 999px; font-size: 14px; font-weight: 600;
            max-width: 88vw; text-align: center; z-index: 50;
        }
    </style>
</head>
<body>
<div class="app">
    @include('partials.erp-home')
    <div class="offline" id="offline" hidden>오프라인 · 내 QR 을 반장에게 보여 주세요</div>
@isset($viewingAs)
    @if ($viewingAs)
        {{-- 남의 화면을 보고 있다는 사실이 한순간도 안 숨겨져야 한다. --}}
        <div class="peek">보는 중 · {{ $viewingAs->name }} 화면 <b>기록은 남지 않습니다</b></div>
    @endif
@endisset

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

    {{-- 현장 QR 스캔 — 출퇴근은 이 화면을 지나야만 찍힌다. --}}
    <div class="scan" id="scan-box" style="display:none">
        <video id="scan-video" playsinline muted></video>
        <div class="scan-frame"></div>
        <p class="scan-hint" id="scan-hint"></p>
        <button type="button" class="scan-cancel" id="scan-cancel">✕</button>
    </div>

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
            {{-- 세 언어를 함께 — 이 카드는 오프라인에서도 떠야 해서 그림째 박혀 있고,
                 그래서 언어 버튼으로 다시 그릴 수 없다. --}}
            <div class="qr-note">반장이 이 QR 을 스캔하면 기록됩니다. <b>인터넷이 끊겨도 보입니다.</b><br>
                Scanned by your foreman — works offline.<br>
                Su capataz lo escanea — funciona sin internet.</div>
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
    // 스캔한 QR 이 어느 현장인지 읽는다. 게이트 QR 은 .../gate/{현장} 주소 하나뿐이다.
    function gateSiteFrom(text) {
        var m = String(text || '').match(/\/gate\/(\d+)(?:[\/?#]|$)/);
        return m ? Number(m[1]) : null;
    }

    var state = { data: null, coords: null, permission: 'unknown', busy: false, tab: 'home', lang: 'ko', tick: 0 };
    var watchId = null;

    /*
     * 세 언어 사전 — 화면의 모든 글자는 여기서 나온다.
     *
     * 언어 버튼은 있는데 번역이 없어 "눌러도 아무 일 없는 버튼" 이었다(실제로 그렇게
     * 보고됐다). 문구를 코드 곳곳에 두면 언어를 붙일 방법이 없다 — 한 곳에 모으고
     * 렌더는 전부 T.xxx 만 읽는다. 출퇴근 응답 문구만은 서버가 만든다(서버 문장을
     * 화면이 되번역하게 두면 문구가 바뀔 때마다 두 곳이 어긋난다).
     */
    var DICT = {
        ko: {
            working: '근무중', offline: '오프라인', outside: '현장 밖', noAuto: '자동 안 됨',
            offlineBar: '오프라인 · 내 QR 을 반장에게 보여 주세요',
            h: '시간', m: '분', clockInAt: '출근', noSite: '현장 미배정', radius: '반경',
            btnOut: '퇴근하기', btnIn: '출근 누르기', btnInAnyway: '그래도 직접 누르기',
            btnScanIn: '📷 현장 QR 찍고 출근', btnScanOut: '📷 현장 QR 찍고 퇴근',
            noteScan: '출입구에 붙은 현장 QR 을 찍어야 기록됩니다. 현장에 있는 것이 확인되면 바로, 확인이 안 되면 <b>반장 승인</b>을 거칩니다.',
            scanHint: '출입구의 현장 QR 을 네모 안에 맞춰 주세요.',
            scanNotGate: '현장 QR 이 아닙니다. 출입구에 붙은 QR 을 찍어 주세요.',
            scanWrongSite: '다른 현장의 QR 입니다. 배정된 현장의 QR 을 찍어 주세요.',
            scanDenied: '카메라를 열지 못했습니다. 브라우저 설정에서 카메라를 허용해 주세요.',
            scanUnsupported: '이 폰은 앱 안에서 QR 을 못 읽습니다. 휴대폰 기본 카메라로 현장 QR 을 찍으면 출퇴근 화면이 열립니다.',
            notices: '전체공지', noticeUntitled: '공지',
            btnPerm: '위치 권한 켜기', btnQr: '내 QR 보여주기',
            noteAuto: '현장을 벗어나고 10분이 지나면 <b>자동으로 퇴근 처리</b>됩니다. 안 눌러도 됩니다.',
            notePunch: '현장에 있는 것이 확인되면 바로 기록됩니다. 확인이 안 되면 <b>반장 승인</b>을 거칩니다.',
            whyOffline: '<b>인터넷이 끊겼습니다.</b> 아래 내 QR 을 반장에게 보여 주세요. 기록은 반장 휴대폰이 보냅니다.',
            whyNoSite: '<b>배정된 현장이 없습니다.</b> 관리자에게 현장 배정을 요청해 주세요.',
            whyNoAuto: '<b>이 현장은 자동 기준이 아직 없습니다.</b> 관리자가 등록할 때까지 직접 눌러 주세요.',
            whyPerm: '<b>위치 권한이 꺼져 있습니다.</b> 켜면 다음부터 자동으로 찍힙니다.',
            whyWaiting: '<b>아직 현장 밖입니다.</b> 반경 안에 들어오거나 현장 WiFi 에 연결되면 자동으로 찍힙니다.',
            todayLog: '오늘 내 기록', clockIn: '출근', clockOut: '퇴근', noLogs: '아직 기록이 없습니다.',
            chipReview: '확인 필요', chipAuto: '자동', chipHand: '직접',
            more: '그 밖에', messages: '메시지', messagesSub: '현장 채팅방과 공지',
            qReport: '오늘 보고', qReportSub: '말하면 정리됩니다',
            qReceipt: '영수증', qReceiptSub: '사진 한 장으로',
            qDoc: '문서 올리기', qDocSub: '도면 · 계약 · 시방',
            qChat: '메시지', qChatSub: '현장 채팅 · 공지',
            qAsk: '물어보기', qAskSub: '도면 · 서류 · 공정표에서 찾아 답합니다',
            opsRoom: '현장 상황실', opsRoomSub: '오늘 한 일 · 자재 · 이슈 올리기',
            receipts: '영수증 내기', receiptsSub: '사진 한 장으로 경비 접수 · 환급 확인',
            weekRegular: '이번 주 정규', ot: '연장', regular: '정규', byDay: '일자별',
            unsettled: '미확정', inProgress: '진행 중', noWeek: '이번 주 기록이 아직 없습니다.',
            liveNote: '오늘 줄은 지금까지 일한 시간입니다. 연장 구분과 확정은 하루가 끝날 때 계산됩니다.',
            wrong: '기록이 틀렸다면', wrongText: '반장에게 말씀해 주세요. 화면에서 바로 정정을 요청하는 기능은 준비 중입니다.',
            noRate: '단가 미정',
            noRateText: '<b>아직 시급이 정해지지 않았습니다.</b> 정해지면 이 화면에 이번 주 예상 금액이 나옵니다. 근무 시간은 그대로 쌓이고 있으니 걱정하지 않으셔도 됩니다.',
            weekEst: '이번 주 예상', preTax: '세금·공제 전',
            payNote: '실제 지급액은 세금과 공제를 뺀 금액입니다. 확정 명세서는 마감 뒤에 올라옵니다.',
            pastSlips: '지난 명세서', paid: '지급', noSlips: '아직 명세서가 없습니다.',
            myQr: '내 배지 QR', myInfo: '내 정보', name: '이름', number: '사번', trade: '직종', site: '현장',
            autoDetect: '현장 자동 인식', gps: 'GPS 반경', wifi: '현장 WiFi',
            registered: '등록됨', notRegistered: '미등록',
            autoNote: '둘 중 하나만 등록돼 있어도 자동 출퇴근이 됩니다. 둘 다 없으면 직접 눌러야 합니다.',
            installNote: '다음부터 아이콘만 누르면 열립니다', logout: '로그아웃',
            retry: '다시 시도', loadFail: '정보를 불러오지 못했습니다.',
            sentFail: '보내지 못했습니다. 인터넷을 확인하고 다시 눌러 주세요.', done: '처리했습니다.',
            viewOnly: '보는 중입니다. 여기서는 출퇴근을 찍을 수 없습니다.',
            fixTime: '출근 시각 정정 요청',
            fixPrompt: '실제 도착 시각 (맞으면 확인만 누르세요)',
            fixPending: '정정 요청됨 — 반장 확인 대기',
            weekdays: ['일', '월', '화', '수', '목', '금', '토']
        },
        en: {
            working: 'Working', offline: 'Offline', outside: 'Off site', noAuto: 'No auto',
            offlineBar: 'Offline · Show your QR to the foreman',
            h: 'h', m: 'm', clockInAt: 'In', noSite: 'No site assigned', radius: 'radius',
            btnOut: 'Clock out', btnIn: 'Clock in', btnInAnyway: 'Clock in anyway',
            btnScanIn: '📷 Scan site QR to clock in', btnScanOut: '📷 Scan site QR to clock out',
            noteScan: 'You must scan the site QR at the gate. Recorded right away if you are on site; otherwise it waits for <b>foreman approval</b>.',
            scanHint: 'Line up the gate QR inside the square.',
            scanNotGate: 'That is not a site QR. Please scan the one posted at the gate.',
            scanWrongSite: 'That QR belongs to another site. Scan the QR of your assigned site.',
            scanDenied: 'Could not open the camera. Please allow camera access in your browser settings.',
            scanUnsupported: 'This phone cannot read QR inside the app. Use your normal camera app on the site QR — it opens the clock-in screen.',
            notices: 'Announcements', noticeUntitled: 'Notice',
            btnPerm: 'Enable location', btnQr: 'Show my QR',
            noteAuto: 'Leaving the site for 10 minutes <b>clocks you out automatically</b>. No need to press.',
            notePunch: 'Recorded right away if you are on site. Otherwise it waits for <b>foreman approval</b>.',
            whyOffline: '<b>No internet.</b> Show the QR below to your foreman — their phone sends the record.',
            whyNoSite: '<b>No site assigned.</b> Ask your manager to assign you to a site.',
            whyNoAuto: '<b>This site has no auto rule yet.</b> Press the button until it is set up.',
            whyPerm: '<b>Location is off.</b> Turn it on and clock-in becomes automatic.',
            whyWaiting: '<b>You are outside the site.</b> Enter the radius or join site WiFi and it records automatically.',
            todayLog: 'Today', clockIn: 'Clock in', clockOut: 'Clock out', noLogs: 'No records yet.',
            chipReview: 'Review', chipAuto: 'Auto', chipHand: 'Manual',
            more: 'More', messages: 'Messages', messagesSub: 'Site chats and notices',
            qReport: "Today's report", qReportSub: 'Speak, we tidy it up',
            qReceipt: 'Receipts', qReceiptSub: 'One photo is enough',
            qDoc: 'Upload a file', qDocSub: 'Drawings · contracts · specs',
            qChat: 'Messages', qChatSub: 'Site chats · notices',
            qAsk: 'Ask', qAskSub: 'Answers from drawings · specs · schedule',
            opsRoom: 'Ops room', opsRoomSub: 'Report work · materials · issues',
            receipts: 'Receipts', receiptsSub: 'Submit expenses with one photo · check reimbursements',
            weekRegular: 'Regular this week', ot: 'OT', regular: 'Regular', byDay: 'By day',
            unsettled: 'Pending', inProgress: 'In progress', noWeek: 'No records this week yet.',
            liveNote: "Today's row is time worked so far. Overtime split is calculated at day close.",
            wrong: 'Wrong record?', wrongText: 'Tell your foreman. In-app corrections are coming.',
            noRate: 'No rate yet',
            noRateText: '<b>Your hourly rate is not set yet.</b> Once set, the weekly estimate shows here. Your hours are still being counted.',
            weekEst: 'Estimated this week', preTax: 'before tax & deductions',
            payNote: 'Actual pay is after taxes and deductions. Final payslips appear after closing.',
            pastSlips: 'Past payslips', paid: 'Paid', noSlips: 'No payslips yet.',
            myQr: 'My badge QR', myInfo: 'My info', name: 'Name', number: 'ID', trade: 'Trade', site: 'Site',
            autoDetect: 'Site auto-detect', gps: 'GPS radius', wifi: 'Site WiFi',
            registered: 'Registered', notRegistered: 'Not set',
            autoNote: 'Either one enables automatic clock-in. With neither, press the button.',
            installNote: 'Opens with one tap next time', logout: 'Log out',
            retry: 'Retry', loadFail: 'Could not load your data.',
            sentFail: 'Could not send. Check your internet and try again.', done: 'Done.',
            viewOnly: 'View-only mode. You cannot punch here.',
            fixTime: 'Fix clock-in time',
            fixPrompt: 'Actual arrival time (just OK if correct)',
            fixPending: 'Fix requested — waiting for foreman',
            weekdays: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
        },
        es: {
            working: 'Trabajando', offline: 'Sin conexión', outside: 'Fuera del sitio', noAuto: 'Sin auto',
            offlineBar: 'Sin conexión · Muestre su QR al capataz',
            h: 'h', m: 'm', clockInAt: 'Entrada', noSite: 'Sin sitio asignado', radius: 'radio',
            btnOut: 'Marcar salida', btnIn: 'Marcar entrada', btnInAnyway: 'Marcar de todos modos',
            btnScanIn: '📷 Escanear QR para entrada', btnScanOut: '📷 Escanear QR para salida',
            noteScan: 'Debe escanear el QR de la obra en la entrada. Se registra al instante si está en el sitio; si no, espera <b>aprobación del capataz</b>.',
            scanHint: 'Alinee el QR de la entrada dentro del cuadro.',
            scanNotGate: 'Ese no es un QR de obra. Escanee el que está en la entrada.',
            scanWrongSite: 'Ese QR es de otra obra. Escanee el de su obra asignada.',
            scanDenied: 'No se pudo abrir la cámara. Permita el acceso a la cámara en su navegador.',
            scanUnsupported: 'Este teléfono no puede leer QR dentro de la app. Use la cámara normal sobre el QR de la obra — abrirá la pantalla de registro.',
            notices: 'Avisos', noticeUntitled: 'Aviso',
            btnPerm: 'Activar ubicación', btnQr: 'Mostrar mi QR',
            noteAuto: 'Al salir del sitio por 10 minutos <b>la salida se marca sola</b>. No necesita presionar.',
            notePunch: 'Se registra al instante si está en el sitio. Si no, espera <b>aprobación del capataz</b>.',
            whyOffline: '<b>Sin internet.</b> Muestre el QR de abajo a su capataz — su teléfono envía el registro.',
            whyNoSite: '<b>No tiene sitio asignado.</b> Pida a su supervisor que le asigne uno.',
            whyNoAuto: '<b>Este sitio aún no tiene regla automática.</b> Presione el botón hasta que se configure.',
            whyPerm: '<b>La ubicación está apagada.</b> Actívela y la entrada será automática.',
            whyWaiting: '<b>Está fuera del sitio.</b> Entre al radio o conéctese al WiFi del sitio y se registra solo.',
            todayLog: 'Hoy', clockIn: 'Entrada', clockOut: 'Salida', noLogs: 'Sin registros todavía.',
            chipReview: 'Revisar', chipAuto: 'Auto', chipHand: 'Manual',
            more: 'Más', messages: 'Mensajes', messagesSub: 'Chats y avisos del sitio',
            qReport: 'Reporte de hoy', qReportSub: 'Hable y lo ordenamos',
            qReceipt: 'Recibos', qReceiptSub: 'Basta una foto',
            qDoc: 'Subir archivo', qDocSub: 'Planos · contratos · especificaciones',
            qChat: 'Mensajes', qChatSub: 'Chats · avisos del sitio',
            qAsk: 'Preguntar', qAskSub: 'Respuestas de planos · especificaciones · cronograma',
            opsRoom: 'Sala de obra', opsRoomSub: 'Reportar trabajo · materiales · problemas',
            receipts: 'Recibos', receiptsSub: 'Envíe gastos con una foto · vea reembolsos',
            weekRegular: 'Regular esta semana', ot: 'Extra', regular: 'Regular', byDay: 'Por día',
            unsettled: 'Pendiente', inProgress: 'En curso', noWeek: 'Sin registros esta semana.',
            liveNote: 'La fila de hoy es el tiempo trabajado hasta ahora. Las horas extra se calculan al cierre del día.',
            wrong: '¿Registro incorrecto?', wrongText: 'Avise a su capataz. Pronto podrá corregirlo desde la app.',
            noRate: 'Sin tarifa aún',
            noRateText: '<b>Su tarifa por hora aún no está definida.</b> Cuando lo esté, verá aquí el estimado semanal. Sus horas se siguen contando.',
            weekEst: 'Estimado esta semana', preTax: 'antes de impuestos y deducciones',
            payNote: 'El pago real es después de impuestos y deducciones. El recibo final aparece tras el cierre.',
            pastSlips: 'Recibos anteriores', paid: 'Pagado', noSlips: 'Sin recibos todavía.',
            myQr: 'Mi QR de gafete', myInfo: 'Mis datos', name: 'Nombre', number: 'ID', trade: 'Oficio', site: 'Sitio',
            autoDetect: 'Detección automática', gps: 'Radio GPS', wifi: 'WiFi del sitio',
            registered: 'Registrado', notRegistered: 'Sin registrar',
            autoNote: 'Con uno de los dos, la entrada es automática. Sin ninguno, presione el botón.',
            installNote: 'La próxima vez abre con un toque', logout: 'Cerrar sesión',
            retry: 'Reintentar', loadFail: 'No se pudo cargar su información.',
            sentFail: 'No se pudo enviar. Revise su internet e intente de nuevo.', done: 'Listo.',
            viewOnly: 'Modo de solo lectura. No puede marcar aquí.',
            fixTime: 'Corregir hora de entrada',
            fixPrompt: 'Hora real de llegada (OK si es correcta)',
            fixPending: 'Corrección pedida — esperando al capataz',
            weekdays: ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']
        }
    };
    var T = DICT.ko;
    var LANG_KEY = 'workerAppLang';
    var langChosen = false;   // 사람이 고른 적이 있으면 서버의 기본 언어가 못 덮는다

    function setLang(code, remember) {
        if (!DICT[code]) return;
        state.lang = code;
        T = DICT[code];
        langChosen = langChosen || !!remember;
        if (remember) { try { localStorage.setItem(LANG_KEY, code); } catch (e) {} }
        document.documentElement.setAttribute('lang', code);
        Array.prototype.forEach.call(document.querySelectorAll('#langs [data-lang]'), function (n) {
            n.setAttribute('aria-pressed', n.dataset.lang === code ? 'true' : 'false');
        });
        document.getElementById('offline').textContent = T.offlineBar;
    }
    (function () {
        var saved = null;
        try { saved = localStorage.getItem(LANG_KEY); } catch (e) {}
        if (saved && DICT[saved]) { langChosen = true; setLang(saved, false); }
    })();

    /** 요일은 서버(한국어)가 아니라 날짜에서 그 언어로 만든다. */
    function weekday(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr + 'T00:00:00');
        return isNaN(d) ? '' : T.weekdays[d.getDay()];
    }

    // 슈퍼관리자가 ?as=직원ID 로 들어왔으면 부르는 곳마다 달고 다녀야 한다 —
    // 안 그러면 화면은 남의 것인데 데이터만 내 것이 된다.
    var AS = (function () {
        var m = /[?&]as=(\d+)/.exec(window.location.search);
        return m ? m[1] : '';
    })();
    function withAs(url) {
        if (!AS) return url;
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'as=' + AS;
    }

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
        return h + '<small>' + T.h + '</small>' + m + '<small>' + T.m + '</small>';
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
            return { tier: 'qr', why: T.whyOffline };
        }
        if (!d.site) {
            return { tier: 'blocked', why: T.whyNoSite };
        }
        if (d.clockedIn) return { tier: 'working' };

        if (!d.site.hasGeofence && !d.site.hasNetwork) {
            return { tier: 'manual', why: T.whyNoAuto };
        }
        if (state.permission === 'denied') {
            return { tier: 'manual', fix: true, why: T.whyPerm };
        }
        if (d.state === 'on_site') return { tier: 'working' };

        return { tier: 'waiting', why: T.whyWaiting };
    }

    function chip(log) {
        if (log.needsReview) return '<span class="chip rev">' + T.chipReview + '</span>';
        if (log.source === 'geo_auto') return '<span class="chip auto">' + T.chipAuto + '</span>';
        if (log.source === 'qr') return '<span class="chip qr">QR</span>';
        return '<span class="chip hand">' + T.chipHand + '</span>';
    }

    function qrBlock() {
        return QR_TPL ? QR_TPL.innerHTML : '<div class="empty">배지 QR 이 아직 발급되지 않았습니다.</div>';
    }

    // ── 탭 ────────────────────────────────────────────────────────
    function tabHome(d) {
        var v = decide(d);
        var working = v.tier === 'working';
        var tone = working ? 'working' : (v.tier === 'qr' ? 'offline' : (v.tier === 'waiting' ? 'waiting' : 'manual'));
        var label = working ? T.working : (v.tier === 'qr' ? T.offline : (v.tier === 'waiting' ? T.outside : T.noAuto));
        var secs = (d.elapsedSeconds || 0) + (working ? state.tick : 0);

        var h = '<div class="slab is-' + tone + '">' +
            '<div class="state"><i></i>' + label + '</div>' +
            '<div class="clock">' + hm(secs) + '</div>' +
            '<div class="meta">' + (working && d.firstEnterAt
                ? T.clockInAt + ' ' + esc(d.firstEnterAt) + (d.site ? ' · ' + esc(d.site.code) : '')
                : (d.site ? esc(d.site.code) + (d.site.radius ? ' · ' + T.radius + ' ' + d.site.radius + 'm' : '') : T.noSite)) + '</div>';

        // 출퇴근은 현장 QR 을 스캔해야만 찍힌다. 누르기만 하면 되는 버튼은 어디서든
        // 눌리고, 그 기록이 그대로 급여가 된다 — 출입구에 붙은 QR 앞까지 와야 한다.
        if (working) {
            h += '<button class="btn stop" data-act="scan" data-dir="out">' + T.btnScanOut + '</button>' +
                 '<div class="note">' + T.noteAuto + '</div>';
        } else {
            h += '<div class="why">' + v.why + '</div>';
            if (v.fix) h += '<button class="btn stop" data-act="perm">' + T.btnPerm + '</button>';
            if (v.tier === 'manual' || v.tier === 'waiting') {
                h += '<button class="btn go" data-act="scan" data-dir="in">' + T.btnScanIn + '</button>' +
                     '<div class="note">' + T.noteScan + '</div>';
            }
            if (v.tier === 'qr') h += '<button class="btn go" data-act="goqr">' + T.btnQr + '</button>';
        }
        h += '</div>';

        // ── 현장에서 하는 나머지 일 — 출퇴근 바로 아래에 큰 칸으로 둔다.
        //
        // 예전에는 보고·영수증·메시지가 화면 맨 아래 글자 링크 세 줄이었다. 하루에도
        // 몇 번씩 쓰는 것들인데 스크롤을 내려야 보였고, 그래서 «앱이 따로따로» 처럼
        // 느껴졌다. 폰에서 손가락이 닿는 자리에 네 칸으로 모은다.
        h += '<div class="quick">' +
            // 물어보기 — 도면·서류에 대고 묻는 문. 검색창처럼 한 줄 가득 둔다.
            tile('{{ route('attendance-app.ask') }}', ICON.ask, T.qAsk, T.qAskSub, '', true) +
            tile('{{ route('attendance-app.ops-room') }}', ICON.report, T.qReport, T.qReportSub, d.reportBadge) +
            tile('{{ route('expense-app.index') }}', ICON.receipt, T.qReceipt, T.qReceiptSub, '') +
            tile('{{ route('attendance-app.docs') }}', ICON.doc, T.qDoc, T.qDocSub, '') +
            tile('{{ route('communication.index') }}', ICON.chat, T.qChat, T.qChatSub, UNREAD ? String(UNREAD) : '') +
            '</div>';

        // 전체공지 — 출퇴근 바로 아래. 공지방까지 들어가는 사람은 없다.
        var notices = d.notices || [];
        if (notices.length) {
            h += '<div class="sec"><div class="sec-h">' + T.notices + '</div><div class="panel">' +
                notices.map(function (n) {
                    return '<div class="notice' + (n.pinned ? ' pin' : '') + '">' +
                        '<div class="notice-h">' + (n.pinned ? '📌 ' : '') + esc(n.title || T.noticeUntitled) +
                        '<span>' + esc(n.at || '') + '</span></div>' +
                        (n.body ? '<div class="notice-b">' + esc(n.body) + '</div>' : '') +
                        '</div>';
                }).join('') +
                '</div></div>';
        }

        h += '<div class="sec"><div class="sec-h">' + T.todayLog + '</div><div class="panel">';
        h += (d.logs || []).length
            ? d.logs.map(function (l) {
                return '<div class="row"><div class="row-k">' + esc(l.at) + '</div>' +
                    '<div class="row-m"><div class="row-a">' + (l.type === 'clock_in' ? T.clockIn : T.clockOut) + '</div>' +
                    '<div class="row-b">' + esc(d.site ? d.site.code : '') + '</div></div>' + chip(l) + '</div>';
            }).join('')
            : '<div class="empty">' + T.noLogs + '</div>';
        h += '</div>';

        // 출근이 늦게 잡혔을 때의 구제 — 주머니 속 웹 앱은 위치를 못 보내므로
        // 5시 도착이 11시 기록이 될 수 있다. 본인이 실제 시각을 말하면 반장 확인
        // 대기로 돌린다. 이미 요청했으면 그 사실만 보인다.
        var firstIn = (d.logs || []).filter(function (l) { return l.type === 'clock_in'; })[0];
        if (firstIn && !AS) {
            h += firstIn.correctionRequested
                ? '<div class="note" style="margin-top:8px">⏳ ' + T.fixPending + '</div>'
                : '<button type="button" class="btn quiet" data-act="fixtime" style="margin-top:8px;font-size:13.5px;min-height:44px;padding:11px">' + T.fixTime + '</button>';
        }
        h += '</div>';

        return h;
    }

    /* 네 칸 그리드 한 칸. 아이콘 · 이름 · 한 줄 설명 · (필요하면) 빨간 숫자. */
    function tile(href, icon, name, sub, badge, wide) {
        return '<a class="tile' + (wide ? ' wide' : '') + '" href="' + href + '">' +
            (badge ? '<span class="tile-dot">' + esc(badge) + '</span>' : '') +
            '<span class="tile-i">' + icon + '</span>' +
            '<b>' + esc(name) + '</b><span class="tile-s">' + esc(sub) + '</span></a>';
    }

    var ICON = {
        ask: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="10.5" cy="10.5" r="6.2"/><path d="M15.2 15.2 20 20"/><path d="M8.6 9.2a1.9 1.9 0 1 1 2.7 1.7c-.6.3-.8.7-.8 1.3"/><path d="M10.5 14.2h.01"/></svg>',
        report: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4h8a2 2 0 0 1 2 2v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V6a2 2 0 0 1 2-2z"/><path d="M9.5 3.2h5v2.4h-5z"/><path d="M9 11h6M9 14.6h4"/></svg>',
        receipt: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3.6h12v16.8l-2.4-1.5-2.4 1.5-2.4-1.5-2.4 1.5L6 18.9z"/><path d="M9.2 8.4h5.6M9.2 12.2h5.6"/></svg>',
        doc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13.4 3.5H7.6a1.6 1.6 0 0 0-1.6 1.6v13.8a1.6 1.6 0 0 0 1.6 1.6h8.8a1.6 1.6 0 0 0 1.6-1.6V8.1z"/><path d="M13.4 3.5V8h4.6"/><path d="M12 17v-5.4M9.8 13.6 12 11.4l2.2 2.2"/></svg>',
        chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12.2c0 3.7-3.6 6.7-8 6.7-.9 0-1.8-.13-2.6-.36L4.8 20l.9-3.2C4.6 15.6 4 14 4 12.2c0-3.7 3.6-6.7 8-6.7s8 3 8 6.7z"/></svg>',
    };

    function tabWork(d) {
        var w = d.week || { regularHours: 0, overtimeHours: 0, days: [] };
        var hasLive = (w.days || []).some(function (x) { return x.live; });

        var h = '<div class="stats">' +
            '<div class="stat"><div class="stat-k">' + T.weekRegular + '</div><div class="stat-v">' + w.regularHours + '<small>' + T.h + '</small></div></div>' +
            '<div class="stat"><div class="stat-k">' + T.ot + ' ×' + (d.pay ? d.pay.multiplier : 1.5) + '</div><div class="stat-v">' + w.overtimeHours + '<small>' + T.h + '</small></div></div>' +
            '</div>';

        h += '<div class="sec"><div class="sec-h">' + T.byDay + '<em>' + esc(w.from || '') + ' – ' + esc(w.to || '') + '</em></div><div class="panel">';
        h += (w.days || []).length
            ? w.days.map(function (x) {
                var total = (x.regularHours + x.overtimeHours).toFixed(1);
                // 진행 중인 오늘 줄 — 끝 시각 대신 "지금까지" 라는 뜻의 ⋯ 를 둔다.
                var range = x.live
                    ? esc(x.in || '—') + ' → ⋯'
                    : esc(x.in || '—') + ' → ' + esc(x.out || '—');
                var tag = x.live ? '<span class="chip auto">' + T.inProgress + '</span>'
                    : (x.settled ? '' : '<span class="chip rev">' + T.unsettled + '</span>');
                return '<div class="row"><div class="row-k">' + esc(x.label) + '<div class="row-b">' + weekday(x.date) + '</div></div>' +
                    '<div class="row-m"><div class="row-a">' + range + '</div>' +
                    '<div class="row-b">' + (x.overtimeHours ? T.ot + ' ' + x.overtimeHours + 'h' : T.regular) + '</div></div>' +
                    '<div class="row-n">' + total + 'h</div>' + tag + '</div>';
            }).join('')
            : '<div class="empty">' + T.noWeek + '</div>';
        h += '</div>';
        if (hasLive) h += '<div class="note" style="color:var(--ink-3);margin-top:10px">' + T.liveNote + '</div>';
        h += '</div>';

        h += '<div class="sec"><div class="sec-h">' + T.wrong + '</div>' +
            '<div class="panel"><div class="empty" style="text-align:left;padding:16px">' +
            T.wrongText + '</div></div></div>';
        return h;
    }

    function tabPay(d) {
        var p = d.pay || {};
        var w = d.week || {};
        var h = '';

        if (!p.hasRate) {
            h += '<div class="slab is-manual"><div class="state"><i></i>' + T.noRate + '</div>' +
                 '<div class="why">' + T.noRateText + '</div></div>';
        } else {
            h += '<div class="money"><div class="stat-k">' + T.weekEst + '</div>' +
                '<div class="amt"><em>' + (p.currency === 'KRW' ? '₩' : '$') + '</em>' +
                Number(p.estimated).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div>' +
                '<div class="sub">' + esc(w.from || '') + ' – ' + esc(w.to || '') + ' · ' + T.preTax + '</div>' +
                '<div class="line"><span>' + T.regular + ' ' + w.regularHours + 'h × ' + money(p.rate, p.currency) + '</span><b>' + money(p.regularPay, p.currency) + '</b></div>' +
                '<div class="line"><span>' + T.ot + ' ' + w.overtimeHours + 'h × ' + money(p.rate * p.multiplier, p.currency) + '</span><b>' + money(p.overtimePay, p.currency) + '</b></div>' +
                '</div>' +
                '<div class="note" style="color:var(--ink-3);margin-top:12px">' + T.payNote + '</div>';
        }

        h += '<div class="sec"><div class="sec-h">' + T.pastSlips + '</div><div class="panel">';
        h += (p.payslips || []).length
            ? p.payslips.map(function (s) {
                return '<div class="row"><div class="row-m"><div class="row-a">' + money(s.net, p.currency) + '</div>' +
                    '<div class="row-b">' + esc(s.from || '') + ' – ' + esc(s.to || '') + '</div></div>' +
                    '<span class="chip ' + (s.status === 'paid' ? 'auto' : 'hand') + '">' + esc(s.status === 'paid' ? T.paid : s.status) + '</span></div>';
            }).join('')
            : '<div class="empty">' + T.noSlips + '</div>';
        h += '</div></div>';
        return h;
    }

    function tabMe(d) {
        var e = d.employee || {};
        var h = '<div class="sec" style="margin-top:0"><div class="sec-h">' + T.myQr + '</div>' + qrBlock() + '</div>';

        h += '<div class="sec"><div class="sec-h">' + T.myInfo + '</div><div class="panel">' +
            kv(T.name, e.name) + kv(T.number, e.number) + kv(T.trade, e.trade) +
            kv(T.site, d.site ? d.site.code + ' · ' + d.site.name : null) +
            '</div></div>';

        h += '<div class="sec"><div class="sec-h">' + T.autoDetect + '</div><div class="panel">' +
            kv(T.gps, d.site && d.site.hasGeofence ? (d.site.radius + 'm · ' + T.registered) : T.notRegistered) +
            kv(T.wifi, d.site && d.site.hasNetwork ? T.registered : T.notRegistered) +
            '</div>' +
            '<div class="note" style="color:var(--ink-3);margin-top:10px">' + T.autoNote + '</div>' +
            '</div>';

        // 홈 화면에 이미 있으면 이 줄은 안 보인다 — 있는 걸 또 설치하라고 하지 않는다.
        if (window.AppInstall && !window.AppInstall.installed()) {
            h += '<div class="sec"><button type="button" class="link" data-act="install" ' +
                'style="width:100%;cursor:pointer;font-family:inherit;text-align:left">' +
                '<div><b>＋ ' + esc(window.AppInstall.label()) + '</b>' +
                '<div class="row-a" style="margin-top:3px">' + T.installNote + '</div></div>' +
                '<span class="go">›</span></button></div>';
        }

        h += '<div class="sec"><form method="POST" action="{{ route('logout') }}">' +
            '<input type="hidden" name="_token" value="' + CSRF + '">' +
            '<button type="submit" class="link" style="width:100%;cursor:pointer;font-family:inherit;text-align:left">' +
            '<div><b style="color:var(--bad)">' + T.logout + '</b></div><span class="go">›</span></button>' +
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
            // 연결이 안 된 것과 진짜로 실패한 것은 다른 상황이다. 같은 빨간 상자로 보여 주면
            // 관리자는 앱이 깨진 줄 알고, 작업자는 자기가 뭘 잘못했다고 생각한다.
            view.innerHTML = (d && d.code === 'view_as_denied') ? viewDenied(d)
                : (d && d.code === 'no_employee') ? notLinked(d) : failed(d);
            document.getElementById('nm').textContent = (d && d.email) ? d.email : '작업자';
            document.getElementById('tag').textContent = '··';
            document.getElementById('sb').textContent = (d && d.code === 'no_employee') ? '연결 대기 중' : '';
            bindSelfLink();
            paintTabs();
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

        paintTabs();
    }

    function paintTabs() {
        Array.prototype.forEach.call(document.querySelectorAll('#tabs .tab'), function (b) {
            b.setAttribute('aria-selected', b.dataset.tab === state.tab ? 'true' : 'false');
        });
    }

    /**
     * 아직 작업자와 연결되지 않은 계정.
     *
     * 관리자가 이 앱을 처음 열면 반드시 이 화면을 본다 — 관리자 계정에는 직원 기록이
     * 안 붙어 있기 때문이다. 그래서 이 화면이 사실상 이 앱의 첫인상이다. 오류가 아니라
     * "다음에 할 일" 로 보이게 만든다.
     */
    function notLinked(d) {
        var who = d && d.email ? d.email : '';
        var h = '<div class="slab is-waiting">' +
            '<div class="state"><i></i>연결 대기 중</div>' +
            '<div class="setup-h">이 계정은 아직<br>작업자와 연결되지 않았습니다</div>' +
            (who ? '<div class="setup-who">' + esc(who) + '</div>' : '') +
            '<div class="why">근무시간과 급여는 <b>작업자 본인</b>에게만 보입니다. ' +
            '계정과 작업자를 이어 주면 이 화면이 채워집니다.</div>' +
            '</div>';

        // 인원을 관리할 수 있는 사람은 여기서 바로 만든다.
        //
        // 앱 관리를 겸하는 소장에게 «ERP 로 가세요» 나 «남에게 부탁하세요» 라고
        // 말하는 화면은 틀렸다 — 그 사람이 바로 그 «남» 이고, 그 사람도 출퇴근을
        // 찍고 보고를 올리고 영수증을 낸다.
        if (d.canSelfLink && d.selfLink) {
            h += selfLinkForm(d.selfLink);
        } else {
            h += '<div class="sec"><div class="sec-h">' + (d.canManage ? '연결하는 법' : '요청하는 법') + '</div>' +
                '<div class="panel">' + (d.canManage
                    ? step(1, 'ERP 인원관리 화면을 엽니다')
                      + step(2, '이 사람 줄에서 <b>계정 만들기</b> 를 누릅니다')
                      + step(3, '이메일을 <b>' + esc(who || '이 계정 주소') + '</b> 로 맞춥니다')
                    : step(1, '현장 관리자에게 이 화면을 보여 주세요')
                      + step(2, '<b>' + esc(who || '내 계정') + '</b> 을 내 이름과 이어 달라고 하면 됩니다')) +
                '</div></div>';
        }

        // «관리자에게 부탁하세요» 는 부탁할 데가 있는 사람에게만 하는 말이다.
        // 스스로 만들 수 있는 사람에게 이걸 붙이면 방금 준 길을 도로 지운다.
        if (!d.canSelfLink) {
            h += '<div class="sec"><div class="sec-h">English · Español</div><div class="panel">' +
                '<div class="row"><div class="row-m"><div class="row-b">This account is not linked to a worker yet.</div>' +
                '<div class="row-a">Ask your site manager to link it.</div></div></div>' +
                '<div class="row"><div class="row-m"><div class="row-b">Esta cuenta aún no está vinculada a un trabajador.</div>' +
                '<div class="row-a">Pida a su supervisor que la vincule.</div></div></div>' +
                '</div></div>';
        }

        return h;
    }

    /**
     * 내 직원 정보를 여기서 만든다 — 관리자도 현장 사람이다.
     *
     * 묻는 것을 넷으로 줄였다. 나머지(회사·고용형태)는 고른 현장에서 따라오거나
     * 관리직으로 정해진다. 폰에서 첫 화면에 뜨는 양식은 짧아야 채워진다.
     */
    function selfLinkForm(o) {
        var sites = (o.sites || []).map(function (s) {
            return '<option value="' + s.id + '">' + esc(s.label) + '</option>';
        }).join('');
        var positions = (o.positions || []).map(function (p) {
            return '<option value="' + esc(p.key) + '"' + (p.key === 'superintendent' ? ' selected' : '') + '>' + esc(p.label) + '</option>';
        }).join('');

        return '<div class="sec"><div class="sec-h">내 직원 정보 만들기</div>' +
            '<div class="panel padded">' +
            '<div class="link-why">앱을 관리한다고 현장 사람이 아닌 것은 아닙니다. ' +
            '아래를 채우면 <b>출퇴근 · 오늘 보고 · 영수증</b>을 바로 쓸 수 있습니다.</div>' +
            '<label class="fld"><span>이름</span>' +
            '<input id="sl-name" type="text" value="' + esc(o.name || '') + '" placeholder="홍길동"></label>' +
            '<label class="fld"><span>현장</span>' +
            '<select id="sl-site"><option value="">— 고르세요 —</option>' + sites + '</select></label>' +
            '<label class="fld"><span>직책</span><select id="sl-pos">' + positions + '</select></label>' +
            '<label class="fld"><span>공종 <em>(선택)</em></span>' +
            '<input id="sl-trade" type="text" placeholder="예) Piping · Electrical"></label>' +
            '<div class="fld-note">「오늘 보고」의 <b>내 자리</b>는 공종이 정하고, 공종이 없으면 직책이 정합니다 ' +
            '(사무 · 안전 · 현장소장 · 기사). 공정을 맡지 않으시면 비워 두세요.</div>' +
            '<button type="button" class="btn go" id="sl-go">내 직원 정보 만들기</button>' +
            '<div class="fld-note" id="sl-msg"></div>' +
            '</div></div>';
    }

    function bindSelfLink() {
        var go = document.getElementById('sl-go');
        if (!go) return;
        go.addEventListener('click', function () {
            var msg = document.getElementById('sl-msg');
            go.disabled = true;
            msg.textContent = '만드는 중…';
            fetch(@json(route('attendance-app.self-link')), {
                method: 'POST', credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    name: document.getElementById('sl-name').value,
                    siteId: document.getElementById('sl-site').value,
                    position: document.getElementById('sl-pos').value,
                    trade: document.getElementById('sl-trade').value,
                }),
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.success) {
                        go.disabled = false;
                        msg.textContent = (d && d.error) || '만들지 못했습니다.';
                        return;
                    }
                    msg.textContent = d.message + ' 화면을 새로 불러옵니다…';
                    setTimeout(function () { location.reload(); }, 700);
                })
                .catch(function () {
                    go.disabled = false;
                    msg.textContent = '연결에 실패했습니다. 다시 눌러 주세요.';
                });
        });
    }

    /**
     * 다른 사람 화면을 보려 했는데 역할이 안 되는 경우.
     *
     * 예전에는 버튼을 아예 감췄다 — 그러면 "왜 안 보이지" 를 아무도 답할 수 없다.
     * 지금 계정의 역할과 되는 역할을 나란히 보여 주면 그 자리에서 끝난다.
     */
    function viewDenied(d) {
        var h = '<div class="slab is-manual">' +
            '<div class="state"><i></i>권한 없음</div>' +
            '<div class="setup-h">이 계정으로는<br>남의 화면을 볼 수 없습니다</div>' +
            (d.email ? '<div class="setup-who">' + esc(d.email) + '</div>' : '') +
            '<div class="why">이 화면에는 그 사람의 <b>시급과 급여</b>가 그대로 나옵니다. ' +
            '그래서 급여를 볼 수 있는 역할에만 열려 있습니다.</div>' +
            '</div>';

        h += '<div class="sec"><div class="sec-h">지금 이 계정</div><div class="panel">' +
            kv('역할', d.role) + '</div></div>';

        h += '<div class="sec"><div class="sec-h">볼 수 있는 역할</div><div class="panel">' +
            (d.allowedRoles || []).map(function (r) {
                return '<div class="row"><div class="row-m"><div class="row-b">' + esc(r) + '</div></div></div>';
            }).join('') + '</div>' +
            '<div class="note" style="color:var(--ink-3);margin-top:10px">' +
            '계정·권한 관리에서 역할을 바꾸면 바로 됩니다.</div></div>';

        return h;
    }

    function step(n, text) {
        return '<div class="row"><div class="step-n">' + n + '</div>' +
            '<div class="row-m"><div class="row-b">' + text + '</div></div></div>';
    }

    /** 진짜로 못 불러온 경우 — 다시 해 볼 수 있어야 한다. */
    function failed(d) {
        return '<div class="fatal">' +
            esc((d && d.error) || T.loadFail) +
            '<button type="button" class="retry" data-act="retry">' + T.retry + '</button>' +
            '</div>';
    }

    async function load() {
        try {
            var r = await fetch(withAs('{{ route('attendance-app.home') }}'), {
                credentials: 'same-origin', headers: { Accept: 'application/json' }
            });
            state.data = await r.json();
            state.tick = 0;

            // 직접 고른 적이 없으면 직원 정보의 언어를 따른다 — 스페인어 작업자는
            // 버튼을 찾기 전에 이미 자기 말로 보여야 한다.
            var pref = state.data && state.data.employee && state.data.employee.lang;
            if (!langChosen && pref && DICT[pref] && pref !== state.lang) setLang(pref, false);
        } catch (err) {
            // 통신 실패는 화면을 비우지 않는다 — 마지막으로 받은 내용을 그대로 둔다.
        }
        render();
    }

    function startWatch() {
        // 남의 화면을 보는 중이면 위치를 보내지 않는다. 관리자가 사무실에서 열어 본
        // 것이 그 작업자의 "현장 재실" 로 기록되면 자동 퇴근 시각이 통째로 틀어진다.
        if (AS) return;

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

    /**
     * 현장 QR 을 스캔해 출퇴근을 찍는다.
     *
     * 카메라로 QR 을 읽는 기능(BarcodeDetector)은 안드로이드 크롬에는 있고 아이폰
     * 사파리에는 없다. 없는 폰에서는 <b>기본 카메라 앱</b>으로 찍으라고 안내한다 —
     * 게이트 QR 은 주소이므로 기본 카메라로 찍으면 게이트 화면이 열리고 거기서 찍힌다.
     * 라이브러리를 하나 더 싣는 것보다, 이미 있는 길을 알려 주는 편이 덜 깨진다.
     */
    async function scanAndPunch(direction) {
        if (AS) { toast(T.viewOnly); return; }
        var site = (state.data || {}).site;

        if (!('BarcodeDetector' in window)) {
            toast(T.scanUnsupported);
            return;
        }

        var box = document.getElementById('scan-box');
        var video = document.getElementById('scan-video');
        var stream = null, timer = null, done = false;

        function stop() {
            if (timer) clearInterval(timer);
            if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
            box.style.display = 'none';
        }
        document.getElementById('scan-cancel').onclick = stop;

        try {
            var detector = new BarcodeDetector({ formats: ['qr_code'] });
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            video.srcObject = stream;
            await video.play();
            box.style.display = 'flex';
            document.getElementById('scan-hint').textContent = T.scanHint;

            timer = setInterval(async function () {
                if (done) return;
                var codes = [];
                try { codes = await detector.detect(video); } catch (e) { return; }
                if (!codes.length) return;

                var scanned = gateSiteFrom(codes[0].rawValue);
                if (scanned === null) { document.getElementById('scan-hint').textContent = T.scanNotGate; return; }
                if (site && site.id && scanned !== site.id) { document.getElementById('scan-hint').textContent = T.scanWrongSite; return; }

                done = true;
                stop();
                punch(direction, scanned);
            }, 600);
        } catch (err) {
            stop();
            toast(T.scanDenied);
        }
    }

    async function punch(direction, gateSite) {
        if (AS) { toast(T.viewOnly); return; }

        if (state.busy) return;
        state.busy = true;
        var body = { direction: direction, lang: state.lang, gate_site: gateSite };
        if (state.coords) {
            body.lat = state.coords.latitude;
            body.lng = state.coords.longitude;
            body.accuracy = Math.round(state.coords.accuracy || 0);
        }
        try {
            var r = await fetch(withAs('{{ route('attendance-app.punch') }}'), {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                body: JSON.stringify(body)
            });
            var j = await r.json();
            toast(j.message || j.error || T.done);
            await load();
            // 한 번 찍어 본 뒤에 권한다. 쓸모를 모르는 채로 받는 설치 권유는 닫힌다.
            if (window.AppInstall) {
                setTimeout(function () { window.AppInstall.offer(); }, 1400);
            }
        } catch (err) {
            toast('보내지 못했습니다. 인터넷을 확인하고 다시 눌러 주세요.');
        }
        state.busy = false;
    }

    /** 출근 시각 정정 요청 — 평소 시각이 미리 채워져 있어 대부분 확인만 누르면 된다. */
    async function requestFix() {
        var d = state.data || {};
        var time = window.prompt(T.fixPrompt, d.usualTime || '');
        if (!time) return;
        try {
            var r = await fetch('{{ route('attendance-app.correction') }}', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                body: JSON.stringify({ time: time.trim(), lang: state.lang })
            });
            var j = await r.json();
            toast(j.message || j.error || T.done);
            await load();
        } catch (err) {
            toast(T.sentFail);
        }
    }

    document.getElementById('view').addEventListener('click', function (ev) {
        var el = ev.target.closest('[data-act]');
        if (!el) return;
        ev.preventDefault();
        var act = el.getAttribute('data-act');
        if (act === 'scan') return scanAndPunch(el.getAttribute('data-dir'));
        if (act === 'fixtime') return requestFix();
        if (act === 'perm') { state.permission = 'unknown'; startWatch(); return render(); }
        if (act === 'install') return window.AppInstall.show();
        if (act === 'retry') return load();
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
        setLang(b.dataset.lang, true);   // 기억해 둔다 — 다음에 열어도 그 언어다.
        render();
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

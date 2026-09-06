<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $employee->name }} — {{ __('앱 설치 안내') }}</title>
    {{--
        직영 작업자 한 사람에게 건네는 종이 한 장.

        게이트 포스터와 다른 점 — 게이트는 벽에 붙이고 아무나 스캔한다. 이건 사람이
        정해져 있고 계정이 있다. 그래서 가장 흔한 실패가 "설치를 못 한다" 가 아니라
        "어느 구글 계정으로 로그인해야 하는지 모른다" 이다. 휴대폰에 계정이 두세 개
        들어 있는 경우가 흔하다. 그래서 본인 이메일을 크게 찍는다.

        계정이 아직 없으면 그 사실을 숨기지 않고 카드 위에 빨갛게 적는다 — 이 종이를
        받은 사람이 시도했다가 실패하는 것보다, 관리자가 인쇄 단계에서 알아채는 편이 낫다.

        디자인은 출퇴근앱·등록 폼과 같은 카카오 언어다. 이 종이를 받은 사람이 QR 을 찍으면
        바로 그 화면으로 넘어가므로, 종이와 화면이 다른 옷을 입고 있으면 "여기가 맞나" 하고
        한 번 멈칫한다. 종이에서도 살도록 노랑은 머리띠 한 줄에만 쓰고 본문은 흰 바탕에 둔다.
    --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <style>
        :root {
            color-scheme: light;
            --kakao: #FEE500; --label: rgba(0,0,0,.85);
            --paper: #F2F3F5; --ink: #191919; --ink-2: #767676; --ink-3: #B0B8C1; --rule: #EDEEF0;
            --ok: #1E8E3E; --ok-bg: #E8F5EA; --bad: #D94C4C; --bad-bg: #FDECEC; --warn: #B26A00;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--paper); color: var(--ink);
            font-family: 'Pretendard Variable', Pretendard, -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', 'Malgun Gothic', sans-serif;
            display: grid; place-items: start center; padding: 24px; -webkit-font-smoothing: antialiased;
        }
        .sheet { width: min(100%, 560px); background: #fff; border-radius: 20px; overflow: hidden; }

        /* 머리띠 — 노랑 면. 카카오 규격대로 그 위 글자는 전부 검정이다. */
        .top { background: var(--kakao); color: var(--label); padding: 20px 30px 18px; }
        .brand { margin: 0 0 6px; font-size: 12px; font-weight: 800; color: rgba(0,0,0,.55); }
        h1 { margin: 0 0 3px; font-size: 25px; font-weight: 800; line-height: 1.2; letter-spacing: -.02em; }
        .alt { margin: 0; color: rgba(0,0,0,.55); font-size: 13px; }
        .alt span + span::before { content: " · "; }

        .body { padding: 22px 30px 26px; }

        /* 이 종이의 주인 — 이름과 사번. */
        .who { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
        .who .tag {
            width: 44px; height: 44px; border-radius: 50%; flex: none;
            background: rgba(0,0,0,.85); color: var(--kakao);
            display: grid; place-items: center; font-weight: 800; font-size: 15px;
        }
        .who .nm { font-size: 19px; font-weight: 800; line-height: 1.25; }
        .who .no { font-size: 12.5px; color: var(--ink-2); font-variant-numeric: tabular-nums; }

        .qr-wrap { text-align: center; margin: 2px 0 20px; }
        .qr { width: 240px; height: 240px; background: #fff; border-radius: 14px; padding: 8px; }

        /* 로그인 계정 — 이 종이가 존재하는 이유. 상태가 색으로 먼저 보인다. */
        .acct { display: flex; gap: 12px; align-items: flex-start; background: var(--paper); border-radius: 14px; padding: 15px 16px; margin-bottom: 22px; }
        .acct .dot {
            width: 36px; height: 36px; border-radius: 50%; flex: none; display: grid; place-items: center;
            background: var(--ok-bg); color: var(--ok); font-size: 17px; font-weight: 800;
        }
        .acct .lb { font-size: 12px; font-weight: 700; color: var(--ink-2); }
        .acct .em { font-size: 17.5px; font-weight: 800; word-break: break-all; margin-top: 2px; }
        .acct.missing { background: var(--bad-bg); }
        .acct.missing .dot { background: #fff; color: var(--bad); }
        .acct.missing .lb { color: var(--bad); }
        .acct.missing .em { color: var(--bad); font-size: 14px; font-weight: 600; line-height: 1.55; }

        .block { padding: 15px 0; border-top: 1px solid var(--rule); }
        .block:first-child { border-top: 0; padding-top: 4px; }
        .chip {
            display: inline-block; font-size: 11px; font-weight: 800; color: var(--label);
            background: var(--kakao); border-radius: 999px; padding: 3px 10px; margin-bottom: 7px;
        }
        .hint { margin: 0 0 7px; font-size: 13.5px; color: var(--ink-2); }
        ol { margin: 0; padding-left: 19px; font-size: 13.5px; line-height: 1.65; }
        .trouble { margin: 8px 0 0; font-size: 12.5px; color: var(--warn); font-weight: 600; }

        .url { margin: 18px 0 0; text-align: center; font-size: 11.5px; color: var(--ink-3); word-break: break-all; }
        .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-top: 20px; }
        .actions button, .actions a {
            appearance: none; border: none; border-radius: 12px; padding: 14px; font-weight: 800; font-size: 14.5px;
            text-align: center; text-decoration: none; cursor: pointer; font-family: inherit;
        }
        .actions button { background: var(--kakao); color: var(--label); }
        .actions a { background: var(--paper); color: var(--ink); }

        @media print {
            /* 노랑 머리띠와 상태 색이 종이에서도 나와야 한다 — 이 카드에서 색은 장식이
               아니라 "계정이 있다/없다" 를 말하는 신호다. */
            html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { background: #fff; padding: 0; display: block; }
            .sheet { width: auto; border-radius: 0; }
            .top { padding: 10mm 12mm 8mm; }
            .body { padding: 8mm 12mm 10mm; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
@php($primary = \App\Support\WorkerLang::DEFAULT)
<main class="sheet">
    <div class="top">
        <p class="brand">{{ \App\Support\Org::name() }}</p>
        <h1>{{ $langs[$primary]['title'] }}</h1>
        <p class="alt">
            @foreach ($langs as $code => $t)@if ($code !== $primary)<span>{{ $t['title'] }}</span>@endif @endforeach
        </p>
    </div>

    <div class="body">
        <div class="who">
            <div class="tag">{{ mb_substr($employee->name, 0, 2) }}</div>
            <div>
                <div class="nm">{{ $employee->name }}</div>
                <div class="no">{{ $employee->employee_number }}@if ($employee->site) · {{ $employee->site->code }}@endif</div>
            </div>
        </div>

        <div class="qr-wrap"><img class="qr" src="{{ $qrImage }}" alt="앱 설치 QR"></div>

        @if ($loginEmail)
            <div class="acct">
                <div class="dot">✓</div>
                <div>
                    <div class="lb">{{ $langs[$primary]['account'] }}</div>
                    <div class="em">{{ $loginEmail }}</div>
                </div>
            </div>
        @else
            {{-- 계정이 없으면 이 종이는 아직 쓸모가 없다. 인쇄한 사람이 지금 알아야 한다. --}}
            <div class="acct missing">
                <div class="dot">!</div>
                <div>
                    <div class="lb">{{ __('로그인 계정 없음 · No account yet') }}</div>
                    <div class="em">
                        {{ __('이 작업자에게는 아직 로그인 계정이 없습니다.
                        인원관리에서') }} <b>{{ __('계정 만들기') }}</b> {{ __('를 먼저 하신 뒤 다시 인쇄하세요.') }}
                    </div>
                </div>
            </div>
        @endif

        @foreach ($langs as $code => $t)
            <section class="block">
                <span class="chip">{{ \App\Support\WorkerLang::OPTIONS[$code] ?? $code }}</span>
                <p class="hint">{{ $t['hint'] }}</p>
                <ol>@foreach ($t['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                <p class="trouble">{{ $t['trouble'] }}</p>
            </section>
        @endforeach

        <p class="url">{{ $url }}</p>

        <div class="actions">
            <button type="button" onclick="window.print()">{{ __('인쇄') }}</button>
            <a href="{{ $url }}" target="_blank" rel="noopener">{{ __('화면 열어 보기') }}</a>
        </div>
    </div>
</main>
</body>
</html>

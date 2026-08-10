<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $employee->name }} — 앱 설치 안내</title>
    {{--
        직영 작업자 한 사람에게 건네는 종이 한 장.

        게이트 포스터와 다른 점 — 게이트는 벽에 붙이고 아무나 스캔한다. 이건 사람이
        정해져 있고 계정이 있다. 그래서 가장 흔한 실패가 "설치를 못 한다" 가 아니라
        "어느 구글 계정으로 로그인해야 하는지 모른다" 이다. 휴대폰에 계정이 두세 개
        들어 있는 경우가 흔하다. 그래서 본인 이메일을 크게 찍는다.

        계정이 아직 없으면 그 사실을 숨기지 않고 카드 위에 빨갛게 적는다 — 이 종이를
        받은 사람이 시도했다가 실패하는 것보다, 관리자가 인쇄 단계에서 알아채는 편이 낫다.
    --}}
    <style>
        :root { color-scheme: light; font-family: 'Malgun Gothic', Arial, Helvetica, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef0f4; display: grid; place-items: start center; padding: 24px; color: #17160F; }
        .sheet { width: min(100%, 560px); background: #fff; border-radius: 16px; padding: 34px 34px 30px; box-shadow: 0 20px 44px rgba(15,23,42,.10); }

        .brand { margin: 0 0 6px; font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: #6F6100; font-weight: 800; }
        h1 { margin: 0 0 4px; font-size: 27px; line-height: 1.15; }
        .alt { margin: 0 0 16px; color: #8B8880; font-size: 13px; }
        .alt span + span::before { content: " · "; }

        .who { display: flex; align-items: center; gap: 13px; background: #F4F2ED; border-radius: 12px; padding: 14px 16px; margin-bottom: 20px; }
        .who .nm { font-size: 19px; font-weight: 900; }
        .who .no { font-size: 12px; color: #625E52; font-family: ui-monospace, Menlo, monospace; }

        .qr-wrap { text-align: center; margin: 4px 0 20px; }
        .qr { width: 250px; height: 250px; border: 1px solid #E2DED3; border-radius: 14px; padding: 10px; background: #fff; }

        .acct { border: 2px solid #17160F; border-radius: 12px; padding: 14px 16px; margin-bottom: 22px; }
        .acct .lb { font-size: 11px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: #625E52; }
        .acct .em { font-size: 18px; font-weight: 800; word-break: break-all; margin-top: 3px; }
        .acct.missing { border-color: #B0392A; background: #F8E5E1; }
        .acct.missing .em { color: #B0392A; font-size: 15px; }

        .blocks { border-top: 1px solid #E2DED3; }
        .block { padding: 15px 0; border-bottom: 1px solid #E2DED3; }
        .block:last-child { border-bottom: 0; }
        .chip { display: inline-block; font-size: 10.5px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: #6F6100; margin-bottom: 5px; }
        .hint { margin: 0 0 7px; font-size: 13.5px; color: #625E52; }
        ol { margin: 0; padding-left: 19px; font-size: 13.5px; line-height: 1.6; }
        .trouble { margin: 7px 0 0; font-size: 12.5px; color: #96600A; }

        .url { margin: 18px 0 0; text-align: center; font-family: ui-monospace, Menlo, monospace; font-size: 11px; color: #8B8880; word-break: break-all; }
        .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 22px; }
        .actions button, .actions a { appearance: none; border: 1px solid #cbd5e1; border-radius: 10px; padding: 12px; font-weight: 800; font-size: 14px; text-align: center; text-decoration: none; cursor: pointer; font-family: inherit; }
        .actions button { background: #17160F; color: #fff; border-color: #17160F; }
        .actions a { color: #334155; background: #fff; }

        @media print {
            body { background: #fff; padding: 0; }
            .sheet { box-shadow: none; border-radius: 0; width: auto; padding: 14mm; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
@php($primary = \App\Support\WorkerLang::DEFAULT)
<main class="sheet">
    <p class="brand">DASOL PRISM</p>
    <h1>{{ $langs[$primary]['title'] }}</h1>
    <p class="alt">
        @foreach ($langs as $code => $t)@if ($code !== $primary)<span>{{ $t['title'] }}</span>@endif @endforeach
    </p>

    <div class="who">
        <div>
            <div class="nm">{{ $employee->name }}</div>
            <div class="no">{{ $employee->employee_number }}@if ($employee->site) · {{ $employee->site->code }}@endif</div>
        </div>
    </div>

    <div class="qr-wrap"><img class="qr" src="{{ $qrImage }}" alt="앱 설치 QR"></div>

    @if ($loginEmail)
        <div class="acct">
            <div class="lb">{{ $langs[$primary]['account'] }}</div>
            <div class="em">{{ $loginEmail }}</div>
        </div>
    @else
        {{-- 계정이 없으면 이 종이는 아직 쓸모가 없다. 인쇄한 사람이 지금 알아야 한다. --}}
        <div class="acct missing">
            <div class="lb">로그인 계정 없음 · No account yet</div>
            <div class="em">
                이 작업자에게는 아직 로그인 계정이 없습니다.
                인원관리에서 <b>계정 만들기</b> 를 먼저 하신 뒤 다시 인쇄하세요.
            </div>
        </div>
    @endif

    <div class="blocks">
        @foreach ($langs as $code => $t)
            <section class="block">
                <span class="chip">{{ \App\Support\WorkerLang::OPTIONS[$code] ?? $code }}</span>
                <p class="hint">{{ $t['hint'] }}</p>
                <ol>@foreach ($t['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                <p class="trouble">{{ $t['trouble'] }}</p>
            </section>
        @endforeach
    </div>

    <p class="url">{{ $url }}</p>

    <div class="actions">
        <button type="button" onclick="window.print()">인쇄</button>
        <a href="{{ $url }}" target="_blank" rel="noopener">화면 열어 보기</a>
    </div>
</main>
</body>
</html>

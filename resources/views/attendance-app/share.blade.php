<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FEE500">
    <title>{{ $employee->name }} — {{ __('앱 링크 보내기') }}</title>
    {{--
        작업자에게 링크를 "보내는" 화면.

        인쇄 카드와 목적이 다르다. 카드는 손에 쥐여 주는 종이고, 이건 문자·왓츠앱으로
        보내는 것이다. 그래서 인쇄 레이아웃이 아니라 반장 휴대폰에서 쓰기 좋은 배치다.

        세 가지가 한 화면에 있어야 한다.
          링크  — 눌러서 바로 복사. 주소를 손으로 옮겨 적다 오타가 난다.
          QR   — 같이 있을 때 가장 빠르다. 반장 폰을 보여 주고 작업자가 스캔한다.
          문구  — 링크만 덜렁 보내면 모르는 주소라 안 누른다.

        디자인은 출퇴근앱과 같은 카카오 언어를 쓴다 — 이 화면을 여는 반장이 매일 보는
        화면이 출퇴근앱이라, 같은 색·모서리·간격이면 설명이 필요 없다.
    --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <style>
        :root {
            color-scheme: light;
            --kakao: #FEE500; --kakao-2: #F6DC00; --label: rgba(0,0,0,.85);
            --paper: #F2F3F5; --card: #FFFFFF;
            --ink: #191919; --ink-2: #767676; --ink-3: #B0B8C1; --rule: #EDEEF0;
            --ok: #1E8E3E; --ok-bg: #E8F5EA;
            --warn: #B26A00; --warn-bg: #FFF4E0;
            --bad: #D94C4C; --bad-bg: #FDECEC;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: var(--paper); color: var(--ink);
            font-family: "Pretendard Variable", Pretendard, -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', 'Malgun Gothic', 'Noto Sans KR', sans-serif;
            font-size: 15px; line-height: 1.55; -webkit-font-smoothing: antialiased;
        }
        .wrap { max-width: 520px; margin: 0 auto; padding-bottom: 44px; }

        /* 머리띠 — 출퇴근앱과 같은 노랑 면. 그 위 글자는 전부 검정. */
        .top {
            position: sticky; top: 0; z-index: 20;
            background: var(--kakao); color: var(--label);
            display: flex; align-items: center; gap: 11px; padding: 14px 16px;
        }
        .tag {
            width: 42px; height: 42px; border-radius: 50%; flex: none;
            background: rgba(0,0,0,.85); color: var(--kakao);
            display: grid; place-items: center; font-weight: 800; font-size: 14px;
        }
        .who { flex: 1; min-width: 0; }
        .who b { display: block; font-size: 17px; font-weight: 800; line-height: 1.3; color: var(--label); }
        .who span {
            display: block; font-size: 12px; color: rgba(0,0,0,.55); font-variant-numeric: tabular-nums;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .top-role { flex: none; font-size: 11px; font-weight: 700; color: rgba(0,0,0,.55);
                    background: rgba(255,255,255,.6); border-radius: 999px; padding: 5px 10px; }

        main { padding: 16px 16px 0; }
        .sec-h { font-size: 12.5px; font-weight: 700; color: var(--ink-2); margin: 22px 4px 8px; }
        .sec-h:first-child { margin-top: 4px; }
        .card { background: var(--card); border-radius: 16px; padding: 18px 16px; }

        /* 로그인 계정 — 이 화면의 핵심 정보. 상태(정상/불일치/없음)가 색으로 먼저 보인다. */
        .acct { display: flex; gap: 12px; align-items: flex-start; }
        .acct .dot {
            width: 38px; height: 38px; border-radius: 50%; flex: none; display: grid; place-items: center;
            background: var(--ok-bg); color: var(--ok); font-size: 18px;
        }
        .acct.none .dot { background: var(--bad-bg); color: var(--bad); }
        .acct .lb { font-size: 12px; font-weight: 700; color: var(--ink-2); }
        .acct .em { font-size: 16.5px; font-weight: 800; word-break: break-all; margin-top: 2px; }
        .acct.none .em { color: var(--bad); font-size: 14.5px; font-weight: 600; line-height: 1.55; }
        .warn-box {
            margin-top: 12px; background: var(--warn-bg); border-radius: 12px; padding: 11px 13px;
            font-size: 12.5px; line-height: 1.6; color: var(--warn); font-weight: 600;
        }
        .warn-box b { word-break: break-all; }

        /* QR — 흰 판 가운데. 노랑은 행동에 아껴 둔다. */
        .qr-card { text-align: center; }
        .qr { display: block; width: 232px; max-width: 78%; margin: 4px auto 0; border-radius: 12px; }
        .qr-note { font-size: 13px; color: var(--ink-2); margin-top: 12px; }

        .link {
            font-size: 13px; word-break: break-all; color: var(--ink-2); font-variant-numeric: tabular-nums;
            background: var(--paper); border-radius: 12px; padding: 12px 13px;
        }

        button, a.btn {
            appearance: none; font-family: inherit; cursor: pointer; text-decoration: none;
            border: none; border-radius: 12px; font-weight: 700; text-align: center;
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
        }
        .big {
            width: 100%; padding: 15px; font-size: 16px; font-weight: 800;
            background: var(--kakao); color: var(--label); margin-top: 12px;
        }
        .big:active { background: var(--kakao-2); }
        .row-send { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-top: 9px; }
        .row-send a.btn, .row-send button {
            padding: 13px; font-size: 14px; background: var(--paper); color: var(--ink);
        }
        .row-send a.btn:active, .row-send button:active { background: var(--rule); }
        .mini {
            padding: 7px 13px; font-size: 12.5px; font-weight: 700; border-radius: 999px;
            background: var(--paper); color: var(--ink);
        }
        .mini:active { background: var(--rule); }

        .dial { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ok); font-weight: 700; margin-top: 12px; }
        .dial.none { color: var(--ink-2); font-weight: 400; line-height: 1.55; display: block; }
        .dial.none b { color: var(--ink); }

        .msg { white-space: pre-wrap; font-size: 14px; background: var(--paper); border-radius: 12px; padding: 12px 13px; }
        .msg-h { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 7px; }
        .msg-h b { font-size: 13.5px; }
        .msg-h .me {
            font-size: 11px; font-weight: 800; color: var(--label); background: var(--kakao);
            border-radius: 999px; padding: 2px 8px; margin-left: 5px; vertical-align: 1px;
        }
        .lang-block + .lang-block { margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--rule); }

        .toast {
            position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%);
            background: rgba(0,0,0,.85); color: #fff; padding: 12px 20px; border-radius: 999px;
            font-size: 14px; font-weight: 700; z-index: 50; white-space: nowrap;
        }
        .toast[hidden] { display: none; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div class="tag">{{ mb_substr($employee->name, 0, 2) }}</div>
        <div class="who">
            <b>{{ $employee->name }}</b>
            <span>{{ $employee->employee_number }}@if ($employee->site) · {{ $employee->site->code }}@endif</span>
        </div>
        <div class="top-role">{{ __('앱 링크 보내기') }}</div>
    </div>

    <main>
        {{-- 로그인할 계정이 이 화면의 핵심이다. 휴대폰에 구글 계정이 여러 개면 여기서 막힌다. --}}
        <div class="sec-h">{{ __('로그인할 구글 계정') }}</div>
        @if ($loginEmail)
            <div class="card">
                <div class="acct">
                    <div class="dot">✓</div>
                    <div>
                        <div class="lb">{{ __('이 계정으로 로그인해야 합니다') }}</div>
                        <div class="em">{{ $loginEmail }}</div>
                    </div>
                </div>
                {{-- 직원 정보의 이메일을 고쳐도 로그인 계정은 따라오지 않는다. 그 사실을
                     여기서 말해 주지 않으면, 방금 고쳐 놓고 옛 주소를 보게 된 반장이
                     원인을 찾지 못한다 — 어느 화면도 틀려 보이지 않기 때문이다. --}}
                @if ($employeeEmail && mb_strtolower($employeeEmail) !== mb_strtolower($loginEmail))
                    <div class="warn-box">
                        {{ __('직원 정보의 이메일은') }} <b>{{ $employeeEmail }}</b> {{ __('입니다 — 위 계정과 다릅니다.') }}<br>
                        {{ __('작업자가 쓰는 주소가 아래쪽이면, 인원관리') }} <b>{{ __('수정') }}</b> {{ __('에서 저장할 때
                        "로그인 계정도 바꿀까요?" 에') }} <b>{{ __('예') }}</b> {{ __('를 누르세요.') }}
                    </div>
                @endif
            </div>
        @else
            <div class="card">
                <div class="acct none">
                    <div class="dot">!</div>
                    <div>
                        <div class="lb">{{ __('계정 없음') }}</div>
                        <div class="em">
                            {{ __('아직 로그인 계정이 없습니다. 링크를 보내도 로그인에서 막힙니다.
                            인원관리에서') }} <b>{{ __('계정 만들기') }}</b> {{ __('를 먼저 하세요.') }}
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="sec-h">{{ __('같이 있을 때 — QR 을 보여 주세요') }}</div>
        <div class="card qr-card">
            <img class="qr" src="{{ $qrImage }}" alt="앱 링크 QR">
            <div class="qr-note">{{ __('작업자가 휴대폰 카메라로 이 화면을 스캔하면 앱이 열립니다.') }}</div>
        </div>

        <div class="sec-h">{{ __('떨어져 있을 때 — 보내기') }}</div>
        <div class="card">
            <div class="link" id="url">{{ $url }}</div>
            <button type="button" class="big" data-copy="url">{{ __('링크 복사') }}</button>
            @if ($dial)
                <div class="dial">{{ __('받는 사람') }} · {{ $employee->phone }}</div>
            @else
                <div class="dial none">{{ __('전화번호가 없습니다 — 보낼 앱에서 받는 사람을 고르셔야 합니다.') }}
                    <b>{{ __('수정') }}</b> {{ __('에서 번호를 넣어 두면 다음부터 바로 열립니다.') }}</div>
            @endif
            <div class="row-send">
                <button type="button" id="share" hidden>{{ __('공유하기') }}</button>
                <a class="btn" id="sms" href="#">{{ __('문자로 보내기') }}</a>
                <a class="btn" id="wa" href="#" target="_blank" rel="noopener">WhatsApp</a>
            </div>
        </div>

        <div class="sec-h">{{ __('보낼 문구 — 링크만 보내면 안 누릅니다') }}</div>
        <div class="card">
            @foreach ($messages as $code => $text)
                <div class="lang-block">
                    <div class="msg-h">
                        <b>{{ \App\Support\WorkerLang::OPTIONS[$code] ?? $code }}@if ($code === $lang)<span class="me">{{ __('이 작업자의 언어') }}</span>@endif</b>
                        <button type="button" class="mini" data-copy="m-{{ $code }}">{{ __('복사') }}</button>
                    </div>
                    <div class="msg" id="m-{{ $code }}">{{ $text }}</div>
                </div>
            @endforeach
        </div>
    </main>
</div>

<div class="toast" id="toast" hidden></div>

<script>
    // 화면 안의 글도 서버와 같은 사전을 읽는다. 블레이드는 __(), 여기서는 t().
    // 사전이 두 벌이면 한쪽만 번역되는 사고가 난다.
    const TR = @json(\App\Support\AppLocale::dictionary());
    function t(s) { return (TR && TR[s]) || s; }

(function () {
    var toast = document.getElementById('toast');
    function say(m) {
        toast.textContent = m; toast.hidden = false;
        clearTimeout(say._t); say._t = setTimeout(function () { toast.hidden = true; }, 2000);
    }

    function copy(text) {
        // 오래된 브라우저에는 clipboard 가 없다. 그때는 선택해서 복사하게 둔다.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { say(t('복사했습니다')); },
                function () { say(t('복사하지 못했습니다. 길게 눌러 복사해 주세요.')); });
            return;
        }
        say(t('길게 눌러 복사해 주세요.'));
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (b) {
        b.addEventListener('click', function () {
            var el = document.getElementById(b.getAttribute('data-copy'));
            if (el) copy(el.textContent.trim());
        });
    });

    // 이 작업자의 언어로 된 문구를 기본으로 보낸다.
    var lang = @json($lang);
    var body = (document.getElementById('m-' + lang) || document.getElementById('m-ko')).textContent.trim();

    // 번호를 알면 그 사람에게 바로 열린다. 모르면 받는 사람을 고르게 둔다 —
    // 링크와 문구는 어느 쪽이든 채워져 있으므로 반장이 다시 칠 일은 없다.
    var dial = @json($dial);
    document.getElementById('sms').href = 'sms:' + (dial ? '+' + dial : '') + '?&body=' + encodeURIComponent(body);
    document.getElementById('wa').href = 'https://wa.me/' + (dial || '') + '?text=' + encodeURIComponent(body);

    if (navigator.share) {
        var s = document.getElementById('share');
        s.hidden = false;
        s.addEventListener('click', function () {
            navigator.share({ text: body }).catch(function () {});
        });
    }
})();
</script>
</body>
</html>

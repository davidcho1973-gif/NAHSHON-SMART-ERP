<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $employee->name }} — 앱 링크 보내기</title>
    {{--
        작업자에게 링크를 "보내는" 화면.

        인쇄 카드와 목적이 다르다. 카드는 손에 쥐여 주는 종이고, 이건 문자·왓츠앱으로
        보내는 것이다. 그래서 인쇄 레이아웃이 아니라 반장 휴대폰에서 쓰기 좋은 배치다.

        세 가지가 한 화면에 있어야 한다.
          링크  — 눌러서 바로 복사. 주소를 손으로 옮겨 적다 오타가 난다.
          QR   — 같이 있을 때 가장 빠르다. 반장 폰을 보여 주고 작업자가 스캔한다.
          문구  — 링크만 덜렁 보내면 모르는 주소라 안 누른다.
    --}}
    <style>
        :root {
            color-scheme: light;
            --paper: #F4F2ED; --card: #FFF; --ink: #17160F; --ink-2: #625E52; --ink-3: #96917F;
            --rule: #E2DED3; --slab: #17160F; --hivis: #D8E000; --ok: #167A46; --bad: #B0392A;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body {
            margin: 0; background: var(--paper); color: var(--ink);
            font-family: system-ui, -apple-system, 'Apple SD Gothic Neo', 'Malgun Gothic', sans-serif;
            font-size: 16px; line-height: 1.6;
        }
        .wrap { max-width: 560px; margin: 0 auto; padding: 20px 18px 40px; }

        .who { display: flex; align-items: center; gap: 13px; margin-bottom: 20px; }
        .tag { width: 46px; height: 46px; border-radius: 12px; background: var(--slab); color: var(--hivis);
               display: grid; place-items: center; font-weight: 800; flex: none; }
        .who b { display: block; font-size: 19px; font-weight: 800; }
        .who span { font-size: 12px; color: var(--ink-3); font-family: ui-monospace, Menlo, monospace; }

        .sec-h { font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: var(--ink-3);
                 font-family: ui-monospace, Menlo, monospace; margin: 26px 0 9px; }
        .card { background: var(--card); border: 1px solid var(--rule); border-radius: 16px; padding: 16px; }

        .qr { display: block; width: 260px; max-width: 100%; margin: 0 auto; }
        .qr-note { text-align: center; font-size: 13px; color: var(--ink-2); margin-top: 10px; }

        .link { font-family: ui-monospace, Menlo, monospace; font-size: 13px; word-break: break-all;
                background: var(--paper); border-radius: 10px; padding: 11px 12px; }

        .acct { border: 2px solid var(--slab); border-radius: 12px; padding: 12px 14px; }
        .acct .lb { font-size: 11px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: var(--ink-2); }
        .acct .em { font-size: 17px; font-weight: 800; word-break: break-all; margin-top: 3px; }
        .acct.none { border-color: var(--bad); background: #F8E5E1; }
        .acct.none .em { color: var(--bad); font-size: 14.5px; font-weight: 700; }

        .msg { white-space: pre-wrap; font-size: 14.5px; background: var(--paper); border-radius: 10px; padding: 12px; }
        .msg-h { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 7px; }
        .msg-h b { font-size: 13px; }
        .lang-block + .lang-block { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--rule); }

        button, a.btn {
            appearance: none; font-family: inherit; cursor: pointer; text-decoration: none;
            border-radius: 12px; font-weight: 750; text-align: center; display: inline-block;
        }
        .big { width: 100%; padding: 16px; font-size: 16.5px; border: none; background: var(--slab); color: #F6F5EE; margin-top: 12px; }
        .big.go { background: var(--hivis); color: var(--slab); }
        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
        .row2 a.btn, .row2 button { padding: 14px; font-size: 14.5px; border: 1px solid var(--rule); background: var(--card); color: var(--ink); }
        .mini { padding: 7px 11px; font-size: 12.5px; border: 1px solid var(--rule); background: var(--card); color: var(--ink); }

        .toast {
            position: fixed; left: 50%; bottom: 26px; transform: translateX(-50%);
            background: var(--slab); color: #F6F5EE; padding: 12px 18px; border-radius: 12px;
            font-size: 14px; font-weight: 700; z-index: 50;
        }
        .toast[hidden] { display: none; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="who">
        <div class="tag">{{ mb_substr($employee->name, 0, 2) }}</div>
        <div>
            <b>{{ $employee->name }}</b>
            <span>{{ $employee->employee_number }}@if ($employee->site) · {{ $employee->site->code }}@endif</span>
        </div>
    </div>

    {{-- 로그인할 계정이 이 화면의 핵심이다. 휴대폰에 구글 계정이 여러 개면 여기서 막힌다. --}}
    <div class="sec-h">로그인할 구글 계정</div>
    @if ($loginEmail)
        <div class="acct">
            <div class="lb">이 계정으로 로그인해야 합니다</div>
            <div class="em">{{ $loginEmail }}</div>
        </div>
    @else
        <div class="acct none">
            <div class="lb">계정 없음</div>
            <div class="em">
                아직 로그인 계정이 없습니다. 링크를 보내도 로그인에서 막힙니다.
                인원관리에서 <b>계정 만들기</b> 를 먼저 하세요.
            </div>
        </div>
    @endif

    <div class="sec-h">같이 있을 때 — QR 을 보여 주세요</div>
    <div class="card">
        <img class="qr" src="{{ $qrImage }}" alt="앱 링크 QR">
        <div class="qr-note">작업자가 휴대폰 카메라로 이 화면을 스캔하면 앱이 열립니다.</div>
    </div>

    <div class="sec-h">떨어져 있을 때 — 보내기</div>
    <div class="card">
        <div class="link" id="url">{{ $url }}</div>
        <button type="button" class="big go" data-copy="url">링크 복사</button>
        <div class="row2">
            <button type="button" id="share" hidden>공유하기</button>
            <a class="btn" id="sms" href="#">문자로 보내기</a>
            <a class="btn" id="wa" href="#" target="_blank" rel="noopener">WhatsApp</a>
        </div>
    </div>

    <div class="sec-h">보낼 문구 — 링크만 보내면 안 누릅니다</div>
    <div class="card">
        @foreach ($messages as $code => $text)
            <div class="lang-block">
                <div class="msg-h">
                    <b>{{ \App\Support\WorkerLang::OPTIONS[$code] ?? $code }}@if ($code === $lang) · 이 작업자의 언어 @endif</b>
                    <button type="button" class="mini" data-copy="m-{{ $code }}">복사</button>
                </div>
                <div class="msg" id="m-{{ $code }}">{{ $text }}</div>
            </div>
        @endforeach
    </div>
</div>

<div class="toast" id="toast" hidden></div>

<script>
(function () {
    var toast = document.getElementById('toast');
    function say(m) {
        toast.textContent = m; toast.hidden = false;
        clearTimeout(say._t); say._t = setTimeout(function () { toast.hidden = true; }, 2000);
    }

    function copy(text) {
        // 오래된 브라우저에는 clipboard 가 없다. 그때는 선택해서 복사하게 둔다.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { say('복사했습니다'); },
                function () { say('복사하지 못했습니다. 길게 눌러 복사해 주세요.'); });
            return;
        }
        say('길게 눌러 복사해 주세요.');
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

    // 번호를 지정하지 않는다 — 직원 정보에 전화번호 칸이 없다. 문구만 채워 열어 주면
    // 반장이 받는 사람을 고른다. 그게 실제로 더 빠르다.
    document.getElementById('sms').href = 'sms:?&body=' + encodeURIComponent(body);
    document.getElementById('wa').href = 'https://wa.me/?text=' + encodeURIComponent(body);

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

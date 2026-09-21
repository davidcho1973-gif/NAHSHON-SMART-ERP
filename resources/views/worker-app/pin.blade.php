{{--
    메시지·문서로 가는 한 단계 — 출퇴근은 이 화면을 거치지 않는다.

    이메일·비밀번호는 묻지 않는다. 작업자에게는 둘 다 없고, 그걸 물었던 것이
    이 공사가 시작된 이유다. 여기서 묻는 것은 본인이 정한 네 자리 하나뿐이다.
--}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>PIN — {{ \App\Support\Org::name() }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <style>
        :root {
            color-scheme: light;
            font-family: 'Pretendard Variable', Pretendard, -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', Arial, sans-serif;
            --blue: #0877BD; --ink: #10233F; --ink-2: #5A6270; --paper: #F3F6FA; --rule: #D8E0EA;
            background: var(--paper); color: var(--ink);
        }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px; box-sizing: border-box; }
        .card { width: min(100%, 420px); background: #fff; border-radius: 20px; padding: 28px 22px; text-align: center; box-shadow: 0 12px 30px rgba(16,35,63,.08); }
        h1, p, label { word-break: keep-all; }
        h1 { margin: 0 0 8px; font-size: 1.4rem; font-weight: 800; }
        p { margin: 0 0 20px; color: var(--ink-2); font-size: .98rem; line-height: 1.6; }
        input { width: 100%; box-sizing: border-box; min-height: 68px; text-align: center; font-size: 2rem; letter-spacing: .5em; text-indent: .5em; font-family: inherit; border: 1px solid var(--rule); border-radius: 14px; background: var(--paper); color: var(--ink); }
        input:focus { outline: 3px solid rgba(8,119,189,.25); border-color: var(--blue); background: #fff; }
        button { width: 100%; margin-top: 18px; min-height: 62px; border: 0; border-radius: 14px; background: var(--blue); color: #fff; font-family: inherit; font-size: 1.1rem; font-weight: 800; cursor: pointer; }
        .err { margin: 0 0 16px; padding: 12px 14px; border-radius: 12px; background: #FEF2F2; color: #991B1B; font-size: .93rem; line-height: 1.55; }
        .back { display: inline-block; margin-top: 18px; color: var(--ink-2); font-size: .9rem; text-decoration: none; }
    </style>
</head>
<body>
    <form class="card" method="POST" action="{{ route('worker-app.pin.store') }}">
        @csrf
        <input type="hidden" name="next" value="{{ $next }}">

        <h1>PIN 네 자리</h1>
        <p>메시지와 문서는 본인만 열 수 있습니다. 출퇴근은 이 단계 없이 그대로 쓰실 수 있습니다.</p>

        @if ($error)
            <div class="err">{{ $error }}</div>
        @endif

        {{-- 숫자 자판을 띄우고 바로 커서를 둔다 — 장갑 낀 손으로 자판을 찾게 하지 않는다. --}}
        <input name="pin" type="tel" inputmode="numeric" maxlength="4" autocomplete="current-password"
               pattern="[0-9]*" required autofocus aria-label="PIN 네 자리">

        <button type="submit">들어가기</button>

        <a class="back" href="{{ route('attendance-app.index') }}">← 출퇴근 화면으로</a>
    </form>
</body>
</html>

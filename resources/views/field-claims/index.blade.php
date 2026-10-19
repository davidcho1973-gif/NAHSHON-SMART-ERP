<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>담당 공정 작업 · {{ \App\Support\Org::name() }}</title>
    <link rel="stylesheet" href="{{ asset('css/smart-company.css') }}">
    <link rel="stylesheet" href="{{ asset('css/field-claims.css') }}?v={{ filemtime(public_path('css/field-claims.css')) }}">
    <script defer src="{{ asset('js/admin-shell.js') }}?v={{ filemtime(public_path('js/admin-shell.js')) }}"></script>
    <script defer src="{{ asset('js/field-claims.js') }}?v={{ filemtime(public_path('js/field-claims.js')) }}"></script>
</head>
<body class="field-claims">
    <header><div><small>{{ \App\Support\Org::name() }} · 공정팀장</small><h1>담당 공정 작업</h1></div>
        <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">로그아웃</button></form>
    </header>
    <main>
        <p>담당 공정의 수량과 작업 기록을 작성하고 증빙을 첨부해 검토 요청하세요. 제출한 기록은 관리자 확인 후 실적으로 반영됩니다.</p>
        <div id="field-claim-content" aria-live="polite">불러오는 중…</div>
    </main>
</body>
</html>

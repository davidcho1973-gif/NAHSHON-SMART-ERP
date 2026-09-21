<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>현장 출퇴근 QR — {{ $site->code }} {{ $site->name }}</title>
    @include('partials.qr-poster-styles')
    {{-- 판 안쪽은 포스터가 스스로 칠한다. 한 장짜리 화면은 바깥 바닥도 같이 맞춘다. --}}
    <style>:root, body { background: {{ $poster['accent']['bg'] }}; }</style>
</head>
<body>
    <div>
        @include('partials.qr-poster', [
            'site' => $site,
            'langs' => $poster['langs'],
            'qrImage' => $poster['qrImage'],
            'url' => $poster['url'],
            'tags' => $poster['tags'],
            'accent' => $poster['accent'],
        ])
        <div class="actions" style="text-align:center">
            <button type="button" onclick="window.print()">포스터 인쇄 (Print)</button>
            @auth<button type="button" style="background:#fff;color:#191919;border:1px solid #EDEEF0" onclick="window.location.href='{{ route('qr-print.sheet', ['site' => $site]) }}'">현장 QR 모아 인쇄</button>@endauth
        </div>
    </div>
</body>
</html>

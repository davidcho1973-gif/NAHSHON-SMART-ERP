<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>현장 출퇴근 QR — {{ $site->code }} {{ $site->name }}</title>
    @include('partials.qr-poster-styles')
</head>
<body>
    <div>
        @include('partials.qr-poster', [
            'site' => $site,
            'langs' => $poster['langs'],
            'qrImage' => $poster['qrImage'],
            'url' => $poster['url'],
            'tags' => $poster['tags'],
        ])
        <div class="actions" style="text-align:center">
            <button type="button" onclick="window.print()">포스터 인쇄 (Print)</button>
            @auth<button type="button" style="background:#fff;color:#4f46e5" onclick="window.location.href='{{ route('qr-print.sheet', ['site' => $site]) }}'">현장 QR 모아 인쇄</button>@endauth
        </div>
    </div>
</body>
</html>

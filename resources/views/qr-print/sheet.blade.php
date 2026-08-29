<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>현장 QR 모아 인쇄 — {{ $site->code }} {{ $site->name }}</title>
    @include('partials.qr-poster-styles')
    {{--
        포스터는 공통 판형(카카오)을 그대로 쓴다. 이 화면이 더하는 것은 위쪽 도구막대뿐인데,
        거기만 예전 회색 팔레트로 남아 있어 포스터와 다른 옷을 입고 있었다. 같은 색·모서리로 맞춘다.
    --}}
    <style>
        /* 포스터 한 장짜리 화면은 노란 바닥이지만, 여기는 여러 장을 훑어 내리며 고르는
           작업 화면이다. 노랑은 눈이 오래 머무는 색이 아니라 흰 판이 더 잘 보이게
           옅은 바닥에 둔다(카카오도 목록은 #F2F3F5 위에 얹는다). */
        :root, body { background: var(--paper); }
        body { display: block; padding: 0; }
        .bar { position: sticky; top: 0; z-index: 5; background: #fff; border-bottom: 1px solid var(--rule); padding: 14px 20px; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; }
        .bar h2 { margin: 0; font-size: 1rem; font-weight: 800; }
        .bar .sub { color: var(--ink-2); font-size: .82rem; margin: 2px 0 0; }
        .picks { display: flex; gap: 8px; flex-wrap: wrap; }
        .picks label { display: inline-flex; align-items: center; gap: 7px; font-size: .86rem; font-weight: 700; color: var(--ink); cursor: pointer; background: var(--paper); border: 0; border-radius: 12px; padding: 9px 13px; }
        .picks input { width: 16px; height: 16px; accent-color: #191919; }
        .pages { padding: 22px; display: grid; gap: 22px; justify-items: center; }
        .page { width: min(100%, 640px); }
        .page-label { text-align: center; font-size: .78rem; color: var(--ink-3); font-weight: 800; margin: 0 0 8px; }
        .empty { text-align: center; color: var(--ink-2); padding: 40px 20px; font-size: .95rem; }
        @media print {
            .bar { display: none !important; }
            .pages { padding: 0; display: block; }
            .page { width: auto; page-break-after: always; break-after: page; }
            .page:last-child { page-break-after: auto; break-after: auto; }
            .page-label { display: none; }
        }
    </style>
</head>
<body>
    <div class="bar no-print">
        <div style="flex:1;min-width:180px">
            <h2>현장 QR 모아 인쇄</h2>
            <p class="sub">{{ $site->code }} {{ $site->name }} — 포스터 1장이 A4 1페이지로 인쇄됩니다.</p>
        </div>
        <div class="picks" id="picks">
            @foreach ($allKeys as $key)
                <label><input type="checkbox" value="{{ $key }}" @checked(collect($posters)->contains('key', $key))> {{ $labels[$key] }}</label>
            @endforeach
        </div>
        <button type="button" onclick="window.print()">선택한 포스터 인쇄</button>
    </div>

    <div class="pages">
        @forelse ($posters as $poster)
            <section class="page" data-key="{{ $poster['key'] }}">
                <p class="page-label">{{ $poster['label'] }}</p>
                @include('partials.qr-poster', [
                    'site' => $site,
                    'langs' => $poster['langs'],
                    'qrImage' => $poster['qrImage'],
                    'url' => $poster['url'],
                    'tags' => $poster['tags'],
                ])
            </section>
        @empty
            <p class="empty">인쇄할 포스터를 하나 이상 선택하세요.</p>
        @endforelse
    </div>

    <script>
        // 체크박스로 즉시 보이기/숨기기 — 인쇄 미리보기가 화면과 그대로 맞게 한다.
        (function () {
            var picks = document.getElementById('picks');
            if (!picks) return;
            picks.addEventListener('change', function () {
                var on = Array.prototype.slice.call(picks.querySelectorAll('input:checked')).map(function (i) { return i.value; });
                document.querySelectorAll('.page').forEach(function (page) {
                    page.style.display = on.indexOf(page.getAttribute('data-key')) >= 0 ? '' : 'none';
                });
            });
        })();
    </script>
</body>
</html>

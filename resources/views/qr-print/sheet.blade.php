<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>현장 QR 모아 인쇄 — {{ $site->code }} {{ $site->name }}</title>
    @include('partials.qr-poster-styles')
    <style>
        body { display: block; padding: 0; }
        .bar { position: sticky; top: 0; z-index: 5; background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 20px; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; box-shadow: 0 2px 10px rgba(15,23,42,.05); }
        .bar h2 { margin: 0; font-size: 1rem; }
        .bar .sub { color: #64748b; font-size: .82rem; margin: 2px 0 0; }
        .picks { display: flex; gap: 12px; flex-wrap: wrap; }
        .picks label { display: inline-flex; align-items: center; gap: 6px; font-size: .86rem; color: #334155; cursor: pointer; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 11px; }
        .picks input { width: 15px; height: 15px; accent-color: #191919; }
        .pages { padding: 22px; display: grid; gap: 22px; justify-items: center; }
        .page { width: min(100%, 640px); }
        .page-label { text-align: center; font-size: .78rem; letter-spacing: .08em; text-transform: uppercase; color: #94a3b8; font-weight: 800; margin: 0 0 8px; }
        .empty { text-align: center; color: #64748b; padding: 40px 20px; font-size: .95rem; }
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

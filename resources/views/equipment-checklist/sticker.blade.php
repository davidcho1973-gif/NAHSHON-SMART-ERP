@php
    /**
     * 장비에 붙이는 QR 스티커.
     *
     * ── 어디에 붙이나 ──────────────────────────────────────────────────
     * <b>조작부 근처</b> — 시동 스위치·손잡이·전원부 옆. 그 장비를 쓰려고 손을
     * 대는 자리라서 안 보고 지나칠 수 없다. 장비 뒤나 밑면에 붙이면 아무도 안 찍는다.
     *
     * ── 왜 QR 밑에 번호를 크게 찍나 ────────────────────────────────────
     * 옥외 장비의 QR 은 흙과 기름으로 금방 안 읽힌다. 그때 번호를 손으로 넣을 길이
     * 없으면, 스티커가 죽는 순간 그 장비는 점검 대상에서 조용히 빠진다.
     */
    $org = \App\Support\Org::name();
@endphp
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>장비 점검 QR 스티커 ({{ count($stickers) }})</title>
    <style>
        :root { --line: #191919; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans KR", sans-serif; }
        .toolbar { padding: 16px; text-align: center; }
        .toolbar button { min-height: 44px; padding: 0 22px; border: none; border-radius: 8px;
                          background: #191919; color: #fff; font-size: 15px; font-weight: 700; }
        .toolbar p { color: #4b5563; font-size: 14px; max-width: 560px; margin: 12px auto 0; line-height: 1.6; }
        .sheet { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10mm; padding: 10mm; max-width: 210mm; margin: 0 auto; }
        .sticker { background: #fff; border: 2px solid var(--line); border-radius: 4mm; padding: 6mm;
                   text-align: center; break-inside: avoid; }
        .sticker .cap { font-size: 11pt; font-weight: 800; letter-spacing: .02em; margin-bottom: 3mm; }
        .sticker img { width: 46mm; height: 46mm; display: block; margin: 0 auto; }
        .sticker .code { font-size: 20pt; font-weight: 900; margin-top: 3mm; letter-spacing: .04em; }
        .sticker .name { font-size: 10.5pt; margin-top: 1mm; min-height: 6mm; line-height: 1.3; }
        .sticker .how { font-size: 8.5pt; color: #374151; margin-top: 3mm; line-height: 1.45; border-top: 1px solid #d1d5db; padding-top: 2mm; }
        .sticker .org { font-size: 8pt; color: #6b7280; margin-top: 2mm; }
        .empty { text-align: center; padding: 60px 20px; color: #6b7280; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { padding: 8mm; gap: 8mm; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">스티커 인쇄</button>
    <p>
        <b>조작부 근처에 붙이세요</b> — 시동 스위치·손잡이·전원부 옆. 그 장비를 쓰려고 손을 대는 자리라야 안 보고 지나치지 않습니다.<br>
        옥외 장비는 <b>투명 테이프로 덮거나 라미네이팅</b>해 주세요. 흙·기름이 묻으면 QR 이 안 읽힙니다.
    </p>
</div>

@if (count($stickers) === 0)
    <div class="empty">인쇄할 장비가 없습니다. 장비를 먼저 등록해 주세요.</div>
@else
<div class="sheet">
    @foreach ($stickers as $s)
        <div class="sticker">
            <div class="cap">사용 전 점검 · SCAN BEFORE USE</div>
            @if ($s['qr'])
                <img src="{{ $s['qr'] }}" alt="{{ $s['equipment']->equipment_code }}">
            @endif
            <div class="code">{{ $s['equipment']->equipment_code }}</div>
            <div class="name">{{ $s['equipment']->equipment_type }}@if ($s['equipment']->model)<br>{{ $s['equipment']->model }}@endif</div>
            <div class="how">
                휴대폰 카메라로 찍으세요<br>
                Point your phone camera · Apunte la cámara
            </div>
            <div class="org">{{ $org }}</div>
        </div>
    @endforeach
</div>
@endif
</body>
</html>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>작업자 간편 등록 QR — {{ $site->code }} {{ $site->name }}</title>
    <style>
        :root { color-scheme: light; font-family: 'Malgun Gothic', Arial, Helvetica, sans-serif; background: #f1f5f9; color: #0f172a; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; }
        .sheet { width: min(100%, 640px); background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 20px 50px rgba(15,23,42,.1); padding: 40px; box-sizing: border-box; text-align: center; }
        .brand { margin: 0 0 6px; font-size: .8rem; letter-spacing: .12em; text-transform: uppercase; color: #4f46e5; font-weight: 800; }
        h1 { margin: 0; font-size: 2rem; line-height: 1.15; }
        .site { margin: 14px 0 2px; font-size: 1.35rem; font-weight: 800; }
        .addr { margin: 0; color: #64748b; font-size: .98rem; }
        .hint { margin: 14px auto 20px; color: #475569; line-height: 1.6; font-size: 1rem; max-width: 460px; }
        .qr { width: min(78vw, 300px); height: min(78vw, 300px); border: 1px solid #e2e8f0; border-radius: 16px; padding: 14px; background: #fff; }
        .steps { text-align: left; max-width: 420px; margin: 22px auto 0; color: #334155; line-height: 1.7; font-size: .95rem; padding-left: 20px; }
        .url { margin: 22px 0 0; overflow-wrap: anywhere; color: #4f46e5; font-size: .85rem; font-family: monospace; }
        .actions { margin-top: 26px; }
        button { appearance: none; border: 1px solid #4f46e5; background: #4f46e5; color: #fff; border-radius: 10px; padding: 12px 22px; font-weight: 700; font-size: .95rem; cursor: pointer; }
        @media print { :root, body { background: #fff; } body { min-height: auto; padding: 0; } .sheet { width: auto; border: 0; border-radius: 0; box-shadow: none; padding: 15mm; } .actions { display: none; } }
    </style>
</head>
<body>
    <main class="sheet">
        <p class="brand">NAHSHON MEP</p>
        <h1>작업자 간편 등록</h1>
        <p class="site">{{ $site->code }} {{ $site->name }}</p>
        @if ($site->address)<p class="addr">{{ $site->address }}</p>@endif
        <p class="hint">휴대폰 카메라로 아래 QR 코드를 스캔하세요.<br>이름·소속회사·공정·이메일·전화만 입력하면 <b>바로 작업자로 등록</b>됩니다.</p>
        <div><img class="qr" src="{{ $qrImage }}" alt="작업자 등록 QR"></div>
        <ol class="steps">
            <li>휴대폰 카메라로 QR 코드를 스캔합니다.</li>
            <li>이름·소속회사·공정·이메일·전화번호를 입력합니다.</li>
            <li>등록 완료 — 현장 출퇴근을 시작할 수 있습니다.</li>
        </ol>
        <p class="url">{{ $formUrl }}</p>
        <div class="actions"><button type="button" onclick="window.print()">포스터 인쇄 (Print)</button></div>
    </main>
</body>
</html>

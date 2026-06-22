<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NAHSHON MEP Application QR</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Arial, Helvetica, sans-serif;
            background: #f6f7fb;
            color: #111827;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .sheet {
            width: min(100%, 460px);
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.12);
            padding: 26px;
            text-align: center;
        }

        .brand {
            margin: 0 0 8px;
            font-size: 0.8rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #4f46e5;
            font-weight: 800;
        }

        h1 {
            margin: 0;
            font-size: clamp(1.65rem, 6vw, 2.2rem);
            line-height: 1.12;
        }

        .hint {
            margin: 12px 0 22px;
            color: #4b5563;
            line-height: 1.55;
            font-size: 0.98rem;
        }

        .qr {
            width: min(82vw, 320px);
            height: min(82vw, 320px);
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 14px;
            background: #ffffff;
        }

        .url {
            margin: 18px 0 0;
            overflow-wrap: anywhere;
            color: #1d4ed8;
            font-size: 0.88rem;
            line-height: 1.45;
        }

        .actions {
            display: grid;
            gap: 10px;
            margin-top: 22px;
        }

        a.button,
        button {
            appearance: none;
            border: 0;
            border-radius: 12px;
            padding: 13px 16px;
            font-weight: 800;
            font-size: 0.95rem;
            cursor: pointer;
        }

        a.button {
            background: #2563eb;
            color: #ffffff;
            text-decoration: none;
        }

        button {
            background: #eef2ff;
            color: #3730a3;
        }
    </style>
</head>
<body>
    @php
        $qrImage = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=16&data=' . rawurlencode($intakeUrl);
    @endphp

    <main class="sheet">
        <p class="brand">NAHSHON MEP</p>
        <h1>입사지원서 QR 코드</h1>
        <p class="hint">
            지원자가 휴대폰 카메라로 QR 코드를 스캔하면 입사지원서가 열립니다.<br>
            Scan this QR code to open the application form.
        </p>

        <img class="qr" src="{{ $qrImage }}" alt="Application QR code">

        <p class="url">{{ $intakeUrl }}</p>

        <div class="actions">
            <a class="button" href="{{ $intakeUrl }}" target="_blank" rel="noopener">지원서 열기</a>
            <button type="button" onclick="navigator.clipboard?.writeText(@js($intakeUrl))">링크 복사</button>
        </div>
    </main>
</body>
</html>

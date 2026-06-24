<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ($mode ?? null) === 'site' ? 'NAHSHON MEP Site Application QR' : 'NAHSHON MEP Application QR' }}</title>
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
            width: min(100%, 720px);
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.12);
            padding: 34px;
            text-align: left;
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

        .site-name {
            margin: 12px 0 4px;
            font-size: clamp(1.3rem, 4vw, 1.8rem);
            font-weight: 800;
        }

        .site-address {
            margin: 0;
            color: #4b5563;
            font-size: 1rem;
            line-height: 1.45;
        }

        .hint {
            margin: 12px 0 22px;
            color: #4b5563;
            line-height: 1.55;
            font-size: 0.98rem;
        }

        .qr-wrap {
            display: grid;
            place-items: center;
            margin: 22px 0;
            text-align: center;
        }

        .qr {
            width: min(82vw, 320px);
            height: min(82vw, 320px);
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 14px;
            background: #ffffff;
        }

        .section {
            border-top: 1px solid #e5e7eb;
            padding-top: 18px;
            margin-top: 18px;
        }

        .section h2 {
            margin: 0 0 10px;
            font-size: 1rem;
        }

        .steps {
            margin: 0;
            padding-left: 20px;
            color: #111827;
            line-height: 1.75;
        }

        .privacy {
            margin: 0;
            color: #4b5563;
            font-size: 0.92rem;
            line-height: 1.6;
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

        @media print {
            :root,
            body {
                background: #ffffff;
            }

            body {
                min-height: auto;
                padding: 0;
            }

            .sheet {
                width: auto;
                border: 0;
                border-radius: 0;
                box-shadow: none;
                padding: 18mm;
            }

            .actions {
                display: none;
            }
        }
    </style>
</head>
<body>
    @php
        $qrImage = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=16&data=' . rawurlencode($intakeUrl);
    @endphp

    <main class="sheet">
        <p class="brand">NAHSHON MEP</p>
        <h1>{{ ($mode ?? null) === 'site' ? '현장 공용 입사지원 QR' : '입사지원서 QR 코드' }}</h1>
        @if (($mode ?? null) === 'site')
            <p class="site-name">{{ $site->code ?? $registration->site?->code }} {{ $site->name ?? $registration->site?->name }}</p>
            @if (($site->address ?? $registration->site?->address) !== null)
                <p class="site-address">{{ $site->address ?? $registration->site?->address }}</p>
            @endif
            <p class="hint">아래 QR 코드를 스캔하면 이 현장 입사지원서가 열립니다.</p>
        @else
            <p class="hint">
                지원자가 휴대폰 카메라로 QR 코드를 스캔하면 입사지원서가 열립니다.<br>
                Scan this QR code to open the application form.
            </p>
        @endif

        <div class="qr-wrap">
            <img class="qr" src="{{ $qrImage }}" alt="Application QR code">
        </div>

        @if (($mode ?? null) === 'site')
            <section class="section">
                <h2>사용 방법</h2>
                <ol class="steps">
                    <li>휴대폰 카메라로 QR 코드를 스캔합니다.</li>
                    <li>열리는 입사지원서를 작성하고 제출합니다.</li>
                    <li>제출 후 담당자가 검토하여 연락드립니다.</li>
                </ol>
            </section>

            <section class="section">
                <h2>개인정보 수집 안내</h2>
                <p class="privacy">
                    입사지원 처리를 위해 지원서에 입력한 이름, 연락처, 경력, 신분증 이미지 등 필요한 정보를 수집합니다.
                    제출된 정보는 채용 검토, 현장 출입, 인사 등록 목적에만 사용됩니다.
                </p>
            </section>
        @endif

        <p class="url">{{ $intakeUrl }}</p>

        <div class="actions">
            <a class="button" href="{{ $intakeUrl }}" target="_blank" rel="noopener">지원서 열기</a>
            <button type="button" onclick="navigator.clipboard?.writeText(@js($intakeUrl))">링크 복사</button>
            <button type="button" onclick="window.print()">인쇄</button>
        </div>
    </main>
</body>
</html>

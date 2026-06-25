<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NAHSHON SMART ERP - {{ $team->name }} ({{ $team->code }}) 출퇴근 QR</title>
    <style>
        :root {
            color-scheme: light;
            font-family: 'Inter', Arial, Helvetica, sans-serif;
            background: #f1f5f9;
            color: #0f172a;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .sheet {
            width: min(100%, 650px);
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
            padding: 40px;
            box-sizing: border-box;
            position: relative;
        }

        .brand {
            margin: 0 0 8px;
            font-size: 0.85rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #2563eb;
            font-weight: 800;
        }

        h1 {
            margin: 0;
            font-size: 2rem;
            line-height: 1.2;
            color: #1e293b;
        }

        .team-info {
            margin: 20px 0;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 24px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        .info-row:last-child {
            margin-bottom: 0;
        }

        .info-label {
            color: #64748b;
            font-weight: 500;
        }

        .info-val {
            color: #0f172a;
            font-weight: 700;
        }

        .qr-wrap {
            display: grid;
            place-items: center;
            margin: 30px 0;
            text-align: center;
        }

        .qr {
            width: 280px;
            height: 280px;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 12px;
            background: #ffffff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }

        .guide-section {
            border-top: 1px solid #e2e8f0;
            padding-top: 24px;
            margin-top: 24px;
        }

        .guide-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 12px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .steps {
            margin: 0;
            padding-left: 20px;
            color: #334155;
            line-height: 1.6;
            font-size: 0.92rem;
        }

        .steps li {
            margin-bottom: 6px;
        }

        .lang-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            margin-bottom: 16px;
        }

        .lang-tab {
            background: #f1f5f9;
            padding: 12px;
            border-radius: 8px;
            font-size: 0.82rem;
            line-height: 1.4;
            color: #475569;
            border-left: 4px solid #94a3b8;
        }

        .lang-tab.active {
            background: #eff6ff;
            border-left-color: #2563eb;
            color: #1e3a8a;
        }

        .url {
            margin: 20px 0 0;
            overflow-wrap: anywhere;
            color: #2563eb;
            font-size: 0.85rem;
            text-align: center;
            font-family: monospace;
        }

        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 30px;
        }

        a.button,
        button {
            appearance: none;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 12px 16px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }

        a.button {
            background: #2563eb;
            color: #ffffff;
            text-decoration: none;
            border-color: #2563eb;
        }
        a.button:hover {
            background: #1d4ed8;
        }

        button {
            background: #ffffff;
            color: #334155;
        }
        button:hover {
            background: #f8fafc;
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
                padding: 15mm;
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
        <p class="brand">NAHSHON SMART ERP</p>
        <h1>출퇴근 기록용 QR 코드</h1>

        <div class="team-info">
            <div class="info-row">
                <span class="info-label">소속 팀 (Team)</span>
                <span class="info-val" style="color: #2563eb; font-size: 1.1rem;">{{ $team->name }} ({{ $team->code }})</span>
            </div>
            <div class="info-row">
                <span class="info-label">배정 현장 (Site)</span>
                <span class="info-val">{{ $team->site?->name ?? '미지정' }} ({{ $team->site?->code ?? '-' }})</span>
            </div>
            @if($team->company)
            <div class="info-row">
                <span class="info-label">소속 회사 (Company)</span>
                <span class="info-val">{{ $team->company->name }}</span>
            </div>
            @endif
        </div>

        <div class="qr-wrap">
            <img class="qr" src="{{ $qrImage }}" alt="Team QR code">
        </div>

        <div class="guide-section">
            <div class="lang-tabs">
                <div class="lang-tab active">
                    <strong>한국어 (KO)</strong><br>
                    1. 카메라로 QR 스캔<br>
                    2. 로그인 후 본인 인증<br>
                    3. 출근/퇴근 버튼 터치
                </div>
                <div class="lang-tab">
                    <strong>English (EN)</strong><br>
                    1. Scan QR with Camera<br>
                    2. Log in to authenticate<br>
                    3. Tap Clock In / Out
                </div>
                <div class="lang-tab">
                    <strong>Español (ES)</strong><br>
                    1. Escanear QR con Cámara<br>
                    2. Iniciar sesión para validar<br>
                    3. Tocar Entrada / Salida
                </div>
            </div>
        </div>

        <p class="url">{{ $intakeUrl }}</p>

        <div class="actions">
            <button type="button" onclick="window.print()" style="grid-column: span 2; background: #2563eb; color: white; border-color: #2563eb;">QR 코드 인쇄 (Print QR Sheet)</button>
            <button type="button" onclick="navigator.clipboard?.writeText(@js($intakeUrl)).then(() => alert('스캔용 링크가 복사되었습니다.'))">링크 복사 (Copy Link)</button>
            <a class="button" href="{{ $intakeUrl }}" target="_blank" rel="noopener">테스트 접속 (Open URL)</a>
        </div>
    </main>
</body>
</html>

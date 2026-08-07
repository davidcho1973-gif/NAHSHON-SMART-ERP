<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $employee->name }} 배지 QR</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; background: #f1f5f9; color: #0f172a; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; }
        .sheet { width: min(100%, 520px); background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 36px; box-sizing: border-box; box-shadow: 0 20px 40px rgba(15, 23, 42, .08); }
        .brand { margin: 0 0 8px; font-size: 12px; letter-spacing: .1em; text-transform: uppercase; color: #2563eb; font-weight: 800; }
        h1 { margin: 0; font-size: 28px; line-height: 1.2; }
        .info { margin: 18px 0; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; }
        .row { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; padding: 7px 0; border-bottom: 1px solid #e2e8f0; }
        .row:last-child { border-bottom: 0; }
        .row span { color: #64748b; }
        .row strong { text-align: right; }
        .qr-wrap { display: grid; place-items: center; margin: 26px 0; text-align: center; }
        .qr { width: 280px; height: 280px; border: 1px solid #e2e8f0; border-radius: 16px; padding: 12px; background: #fff; }
        .notice { color: #334155; line-height: 1.55; font-size: 14px; border-top: 1px solid #e2e8f0; padding-top: 18px; }
        .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 22px; }
        button, a { appearance: none; border: 1px solid #cbd5e1; border-radius: 10px; padding: 12px 14px; font-weight: 800; font-size: 14px; text-align: center; text-decoration: none; cursor: pointer; }
        button { background: #2563eb; color: #fff; border-color: #2563eb; }
        a { color: #334155; background: #fff; }
        @media print {
            body { min-height: auto; padding: 0; background: #fff; }
            .sheet { border: 0; box-shadow: none; border-radius: 0; width: auto; padding: 15mm; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    @php
        $qrImage = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=16&data=' . rawurlencode($badgeUrl);
    @endphp

    <main class="sheet">
        <p class="brand">NAHSHON SMART ERP</p>
        <h1>작업자 배지 QR</h1>

        <section class="info">
            <div class="row"><span>직원</span><strong>{{ $employee->name }}</strong></div>
            <div class="row"><span>Employee ID</span><strong>{{ $employee->employee_number }}</strong></div>
            <div class="row"><span>기본 원청사</span><strong>{{ $employee->company?->name ?? '-' }}</strong></div>
            <div class="row"><span>상태</span><strong>{{ $employee->employment_status }}</strong></div>
        </section>

        <div class="qr-wrap">
            <img class="qr" src="{{ $qrImage }}" alt="Worker badge QR">
        </div>

        <p class="notice">
            이 QR은 배지 뒷면에 부착하는 작업자 식별 QR입니다. 작업자가 휴대폰을 사용할 수 없을 때 작업반장/안전관리자가 팀 출퇴근 처리 화면에서 이 QR을 스캔합니다.
        </p>

        <div class="actions">
            <button type="button" onclick="window.print()">인쇄</button>
            <a href="{{ $badgeUrl }}" target="_blank" rel="noopener">테스트 열기</a>
        </div>
    </main>
</body>
</html>

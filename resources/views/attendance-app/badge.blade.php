<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>작업자 배지 QR</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; background: #f6f7f9; color: #111827; }
        body { margin: 0; display: grid; min-height: 100vh; place-items: center; padding: 20px; background: #f6f7f9; }
        .card { max-width: 420px; width: 100%; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 22px; box-sizing: border-box; }
        .eyebrow { margin: 0 0 6px; color: #2563eb; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0 0 12px; font-size: 24px; }
        p { color: #6b7280; line-height: 1.5; }
        .worker { background: #eff6ff; color: #1e3a8a; border-radius: 12px; padding: 14px; margin: 16px 0; }
        .worker strong { display: block; font-size: 18px; }
        a { display: block; border-radius: 12px; padding: 14px; background: #145fff; color: #fff; text-align: center; text-decoration: none; font-weight: 800; }
    </style>
</head>
<body>
    <main class="card">
        <p class="eyebrow">Worker Badge QR</p>
        <h1>작업자 식별 QR</h1>
        <div class="worker">
            <strong>{{ $employee?->name ?? 'Unknown worker' }}</strong>
            <span>{{ $employee?->company?->name ?? '소속 회사 미지정' }}</span>
        </div>
        <p>이 QR은 작업자 본인 출퇴근용이 아니라, 작업반장/안전관리자가 팀 출퇴근 처리 화면에서 스캔하는 작업자 식별용입니다.</p>
        <a href="{{ route('attendance-app.index') }}">QR 출퇴근 앱으로 이동</a>
    </main>
</body>
</html>

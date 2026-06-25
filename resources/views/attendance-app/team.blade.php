<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>팀 QR 출퇴근</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; background: #f6f7f9; color: #111827; }
        body { margin: 0; background: #f6f7f9; }
        .app { min-height: 100vh; max-width: 520px; margin: 0 auto; background: #fff; }
        header { padding: 22px 20px 14px; border-bottom: 1px solid #e5e7eb; }
        .eyebrow { margin: 0 0 6px; color: #2563eb; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; font-size: 25px; line-height: 1.2; }
        main { padding: 18px 20px 30px; }
        .context { border: 1px solid #e5e7eb; border-radius: 12px; background: #fafafa; padding: 15px; margin-bottom: 16px; }
        .row { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px solid #eceff3; font-size: 14px; }
        .row:last-child { border-bottom: 0; }
        .row span { color: #6b7280; }
        .row strong { text-align: right; }
        .person { margin: 16px 0; border-radius: 12px; padding: 16px; background: #eff6ff; color: #1e3a8a; }
        .person strong { display: block; font-size: 18px; margin-bottom: 4px; }
        .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        button, .button { appearance: none; border: 1px solid #d1d5db; border-radius: 12px; padding: 15px 12px; background: #fff; color: #111827; font-weight: 800; font-size: 16px; text-align: center; text-decoration: none; cursor: pointer; }
        .primary { background: #145fff; color: #fff; border-color: #145fff; grid-column: span 2; }
        .crew { margin-top: 12px; display: block; }
        .alert { border-radius: 12px; padding: 12px; margin-bottom: 14px; font-size: 14px; }
        .success { background: #dcfce7; color: #166534; }
        .warning { background: #fff7ed; color: #9a3412; }
        .error { background: #fee2e2; color: #991b1b; }
        .note { color: #6b7280; font-size: 13px; line-height: 1.5; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="app">
        <header>
            <p class="eyebrow">Attendance QR</p>
            <h1>{{ $qrCode->team?->name ?? 'Team' }} 출퇴근</h1>
        </header>

        <main>
            @if($error)
                <div class="alert error">{{ $error }}</div>
            @endif

            @if($result)
                <div class="alert {{ ($result['status'] ?? '') === 'pending' ? 'warning' : 'success' }}">
                    <strong>{{ ($result['event_type'] ?? '') === 'clock_out' ? '퇴근' : '출근' }} {{ ($result['ignored'] ?? false) ? '중복 무시' : '기록 완료' }}</strong><br>
                    {{ $result['employee_name'] ?? '' }} · {{ $result['event_at'] ?? '' }} · {{ $result['status'] ?? '' }}
                </div>
            @endif

            <section class="context">
                <div class="row"><span>현장</span><strong>{{ $qrCode->site?->name ?? '-' }}</strong></div>
                <div class="row"><span>원청사/계약</span><strong>{{ $qrCode->siteContractor?->company_name ?? $qrCode->team?->contract_company_name ?? '-' }}</strong></div>
                <div class="row"><span>팀</span><strong>{{ $qrCode->team?->name ?? '-' }}</strong></div>
            </section>

            <section class="person">
                <strong>{{ $employee?->name ?? '직원 정보 없음' }}</strong>
                <span>{{ $employee?->company?->name ?? '소속 회사 미지정' }}</span>
            </section>

            <form method="POST" action="{{ route('attendance-app.team.record', ['token' => $token]) }}">
                @csrf
                <div class="actions">
                    <button class="primary" type="submit" name="mode" value="auto">출근 / 퇴근 자동 기록</button>
                    <button type="submit" name="mode" value="clock_in">출근</button>
                    <button type="submit" name="mode" value="clock_out">퇴근</button>
                </div>
            </form>

            @if($canProcessCrew)
                <a class="button crew" href="{{ route('attendance-app.crew', ['token' => $token]) }}">작업자 배지 QR로 팀 출퇴근 처리</a>
            @endif

            <p class="note">직원 기본 팀은 고정하지 않습니다. 오늘 실제 근무 현장/원청사/팀은 이 QR 기준으로 기록됩니다. 기본 소속과 다른 원청사 QR이면 승인대기로 저장됩니다.</p>
        </main>
    </div>
</body>
</html>

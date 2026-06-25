<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>QR 출퇴근 관리</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; background: #f6f7f9; color: #111827; }
        body { margin: 0; min-height: 100vh; background: #f6f7f9; }
        .app { min-height: 100vh; max-width: 520px; margin: 0 auto; background: #fff; display: flex; flex-direction: column; }
        header { padding: 22px 20px 14px; border-bottom: 1px solid #e5e7eb; }
        .eyebrow { margin: 0 0 6px; color: #2563eb; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; font-size: 26px; line-height: 1.2; }
        .sub { margin: 8px 0 0; color: #6b7280; font-size: 14px; line-height: 1.5; }
        main { padding: 18px 20px 28px; flex: 1; }
        .identity { border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; background: #fafafa; margin-bottom: 16px; }
        .identity strong { display: block; font-size: 17px; }
        .identity span { display: block; color: #6b7280; font-size: 13px; margin-top: 4px; }
        .grid { display: grid; gap: 12px; }
        .tile { display: block; border: 1px solid #d1d5db; border-radius: 12px; padding: 18px; text-decoration: none; color: inherit; background: #fff; }
        .tile strong { display: block; font-size: 17px; margin-bottom: 6px; }
        .tile span { color: #6b7280; font-size: 13px; line-height: 1.45; }
        .primary { background: #145fff; color: #fff; border-color: #145fff; }
        .primary span { color: #dbeafe; }
        .logs { margin-top: 22px; }
        .logs h2 { font-size: 16px; margin: 0 0 10px; }
        .log { display: grid; grid-template-columns: 76px 1fr auto; gap: 10px; align-items: center; border-bottom: 1px solid #eef0f3; padding: 10px 0; font-size: 13px; }
        .badge { border-radius: 999px; padding: 4px 8px; background: #eef2ff; color: #3730a3; font-weight: 700; }
        .pending { background: #fff7ed; color: #9a3412; }
        .empty { color: #6b7280; font-size: 14px; padding: 14px 0; }
        .alert { border-radius: 12px; padding: 12px; margin-bottom: 14px; font-size: 14px; background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="app">
        <header>
            <p class="eyebrow">NAHSHON SMART ERP</p>
            <h1>QR 출퇴근 관리</h1>
            <p class="sub">팀 QR 또는 작업자 배지 QR로 현장 출퇴근을 기록합니다.</p>
        </header>

        <main>
            @if(session('attendance_error'))
                <div class="alert">{{ session('attendance_error') }}</div>
            @endif

            <section class="identity">
                <strong>{{ $employee?->name ?? $user?->name ?? 'ERP User' }}</strong>
                <span>{{ $employee?->company?->name ?? '소속 회사 미지정' }} · {{ $employee?->employment_status ?? 'employee not linked' }}</span>
            </section>

            <div class="grid">
                <a class="tile primary" href="javascript:alert('현장에 부착된 팀 QR을 휴대폰 카메라로 스캔하세요.')">
                    <strong>내 출퇴근</strong>
                    <span>현장에 붙은 팀 QR을 스캔하면 본인 출퇴근 화면이 열립니다.</span>
                </a>

                @if($canProcessCrew)
                    <a class="tile" href="javascript:alert('팀 QR을 먼저 스캔한 뒤 팀 출퇴근 처리 화면을 여세요.')">
                        <strong>팀 출퇴근 처리</strong>
                        <span>작업반장/안전관리자는 팀 QR을 연 뒤 작업자 배지 QR을 연속 스캔합니다.</span>
                    </a>
                @endif
            </div>

            <section class="logs">
                <h2>오늘 내 기록</h2>
                @forelse($todayLogs as $log)
                    <div class="log">
                        <div>{{ $log->event_at?->format('h:i A') }}</div>
                        <div>
                            <strong>{{ $log->event_type === 'clock_in' ? '출근' : '퇴근' }}</strong><br>
                            {{ $log->dailyWorkAssignment?->site?->name ?? $log->employee?->site?->name ?? '-' }}
                            / {{ $log->dailyWorkAssignment?->team?->name ?? '-' }}
                        </div>
                        <span class="badge {{ $log->status === 'pending' ? 'pending' : '' }}">{{ $log->status }}</span>
                    </div>
                @empty
                    <div class="empty">오늘 기록이 아직 없습니다.</div>
                @endforelse
            </section>
        </main>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>팀 출퇴근 처리</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; background: #f6f7f9; color: #111827; }
        body { margin: 0; background: #f6f7f9; }
        .app { min-height: 100vh; max-width: 520px; margin: 0 auto; background: #fff; }
        header { padding: 22px 20px 14px; border-bottom: 1px solid #e5e7eb; }
        .eyebrow { margin: 0 0 6px; color: #2563eb; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; font-size: 25px; line-height: 1.2; }
        main { padding: 18px 20px 30px; }
        .context { border: 1px solid #e5e7eb; border-radius: 12px; background: #fafafa; padding: 14px; margin-bottom: 16px; font-size: 14px; line-height: 1.55; }
        .mode { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin: 14px 0; }
        .mode label { border: 1px solid #d1d5db; border-radius: 10px; padding: 11px 8px; text-align: center; font-weight: 800; font-size: 14px; }
        .mode input { accent-color: #145fff; }
        input[type=text], select { box-sizing: border-box; width: 100%; border: 1px solid #d1d5db; border-radius: 12px; padding: 14px; font-size: 16px; }
        button, .button { appearance: none; border: 1px solid #d1d5db; border-radius: 12px; padding: 14px 12px; background: #fff; color: #111827; font-weight: 800; font-size: 15px; text-align: center; text-decoration: none; cursor: pointer; }
        .primary { background: #145fff; color: #fff; border-color: #145fff; width: 100%; margin-top: 10px; }
        .scanner { display: none; margin: 14px 0; border: 1px solid #d1d5db; border-radius: 12px; overflow: hidden; background: #111827; }
        video { display: block; width: 100%; min-height: 240px; object-fit: cover; }
        .scanner-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 10px 0; }
        .alert { border-radius: 12px; padding: 12px; margin-bottom: 14px; font-size: 14px; }
        .success { background: #dcfce7; color: #166534; }
        .warning { background: #fff7ed; color: #9a3412; }
        .error { background: #fee2e2; color: #991b1b; }
        .logs { margin-top: 22px; }
        .logs h2 { font-size: 16px; margin: 0 0 10px; }
        .log { display: grid; grid-template-columns: 72px 1fr auto; gap: 10px; align-items: center; border-bottom: 1px solid #eef0f3; padding: 10px 0; font-size: 13px; }
        .badge { border-radius: 999px; padding: 4px 8px; background: #eef2ff; color: #3730a3; font-weight: 700; }
        .pending { background: #fff7ed; color: #9a3412; }
        .hint { color: #6b7280; font-size: 13px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="app">
        <header>
            <p class="eyebrow">Foreman / Safety Mode</p>
            <h1>팀 출퇴근 처리</h1>
        </header>

        <main>
            @if($error)
                <div class="alert error">{{ $error }}</div>
            @endif

            @if($result)
                <div class="alert {{ ($result['status'] ?? '') === 'pending' ? 'warning' : 'success' }}">
                    <strong>{{ ($result['event_type'] ?? '') === 'clock_out' ? '퇴근' : '출근' }} {{ ($result['ignored'] ?? false) ? '중복 무시' : '처리 완료' }}</strong><br>
                    {{ $result['employee_name'] ?? '' }} · {{ $result['event_at'] ?? '' }} · {{ $result['status'] ?? '' }}
                </div>
            @endif

            <section class="context">
                <strong>{{ $qrCode->site?->name ?? '-' }}</strong><br>
                {{ $qrCode->siteContractor?->company_name ?? $qrCode->team?->contract_company_name ?? '-' }} / {{ $qrCode->team?->name ?? '-' }}
            </section>

            <form id="crewForm" method="POST" action="{{ route('attendance-app.crew.record', ['token' => $token]) }}">
                @csrf
                <div class="mode">
                    <label><input type="radio" name="mode" value="auto" checked> 자동</label>
                    <label><input type="radio" name="mode" value="clock_in"> 출근</label>
                    <label><input type="radio" name="mode" value="clock_out"> 퇴근</label>
                </div>

                <input type="text" id="badgeToken" name="badge_token" placeholder="작업자 배지 QR 토큰 또는 URL" autocomplete="off" required>
                <input type="text" name="reason" placeholder="사유 선택 사항: worker_no_phone / phone_broken" style="margin-top: 10px;">

                <div class="scanner-actions">
                    <button type="button" id="startScan">카메라 스캔</button>
                    <button type="button" id="stopScan">스캔 중지</button>
                </div>

                <div class="scanner" id="scanner">
                    <video id="preview" muted playsinline></video>
                </div>

                <button class="primary" type="submit">작업자 출퇴근 처리</button>
            </form>

            <p class="hint">카메라 스캔이 지원되지 않는 휴대폰은 배지 QR 링크를 복사하거나 토큰을 입력해도 처리할 수 있습니다.</p>

            <section class="logs">
                <h2>최근 처리</h2>
                @forelse($recentLogs as $log)
                    <div class="log">
                        <div>{{ $log->event_at?->format('h:i A') }}</div>
                        <div>
                            <strong>{{ $log->employee?->name ?? 'Unknown' }}</strong><br>
                            {{ $log->event_type === 'clock_in' ? '출근' : '퇴근' }}
                        </div>
                        <span class="badge {{ $log->status === 'pending' ? 'pending' : '' }}">{{ $log->status }}</span>
                    </div>
                @empty
                    <p class="hint">아직 처리된 기록이 없습니다.</p>
                @endforelse
            </section>
        </main>
    </div>

    <script>
        const input = document.getElementById('badgeToken');
        const scanner = document.getElementById('scanner');
        const video = document.getElementById('preview');
        let stream = null;
        let timer = null;
        let detector = null;

        async function startScan() {
            if (!('BarcodeDetector' in window)) {
                alert('이 브라우저는 카메라 QR 스캔을 지원하지 않습니다. 토큰/URL을 직접 입력하세요.');
                return;
            }

            detector = new BarcodeDetector({ formats: ['qr_code'] });
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            video.srcObject = stream;
            await video.play();
            scanner.style.display = 'block';

            timer = setInterval(async () => {
                const codes = await detector.detect(video);
                if (codes.length > 0) {
                    input.value = codes[0].rawValue;
                    stopScan();
                    document.getElementById('crewForm').submit();
                }
            }, 700);
        }

        function stopScan() {
            if (timer) clearInterval(timer);
            timer = null;
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
            stream = null;
            scanner.style.display = 'none';
        }

        document.getElementById('startScan').addEventListener('click', () => startScan().catch(error => alert(error.message)));
        document.getElementById('stopScan').addEventListener('click', stopScan);
    </script>
</body>
</html>

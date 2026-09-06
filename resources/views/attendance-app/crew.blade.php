<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ __('팀 출퇴근 및 일일 인원 마감') }}</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; background: #f6f7f9; color: #111827; }
        body { margin: 0; background: #f6f7f9; }
        .app { min-height: 100vh; max-width: 560px; margin: 0 auto; background: #fff; }
        header { padding: 22px 20px 14px; border-bottom: 1px solid #e5e7eb; }
        .eyebrow { margin: 0 0 6px; color: #2563eb; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; font-size: 25px; line-height: 1.2; }
        h2 { margin: 0 0 6px; font-size: 18px; }
        main { padding: 18px 20px 30px; }
        .context, .panel { border: 1px solid #e5e7eb; border-radius: 14px; background: #fafafa; padding: 15px; margin-bottom: 18px; }
        .panel { background: #fff; }
        .panel-header { margin-bottom: 14px; }
        .mode { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin: 14px 0; }
        .mode label { border: 1px solid #d1d5db; border-radius: 10px; padding: 11px 8px; text-align: center; font-weight: 800; font-size: 14px; }
        .mode input { accent-color: #145fff; }
        input[type=text], input[type=number], select, textarea { box-sizing: border-box; width: 100%; border: 1px solid #d1d5db; border-radius: 12px; padding: 13px 14px; font-size: 16px; background: #fff; }
        textarea { min-height: 82px; resize: vertical; font-family: inherit; }
        label.field { display: block; margin-top: 12px; color: #374151; font-size: 13px; font-weight: 800; }
        label.field span { display: block; margin-bottom: 6px; }
        button, .button { appearance: none; border: 1px solid #d1d5db; border-radius: 12px; padding: 14px 12px; background: #fff; color: #111827; font-weight: 800; font-size: 15px; text-align: center; text-decoration: none; cursor: pointer; }
        .primary { background: #145fff; color: #fff; border-color: #145fff; width: 100%; margin-top: 10px; }
        .close-button { background: #047857; border-color: #047857; }
        .scanner { display: none; margin: 14px 0; border: 1px solid #d1d5db; border-radius: 12px; overflow: hidden; background: #111827; }
        video { display: block; width: 100%; min-height: 240px; object-fit: cover; }
        .scanner-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 10px 0; }
        .alert { border-radius: 12px; padding: 12px; margin-bottom: 14px; font-size: 14px; line-height: 1.5; }
        .success { background: #dcfce7; color: #166534; }
        .warning { background: #fff7ed; color: #9a3412; }
        .error { background: #fee2e2; color: #991b1b; }
        .metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 12px 0; }
        .metric { border: 1px solid #e5e7eb; border-radius: 12px; padding: 11px 6px; text-align: center; background: #f9fafb; }
        .metric span { display: block; color: #6b7280; font-size: 11px; line-height: 1.3; }
        .metric strong { display: block; margin-top: 4px; font-size: 22px; }
        .metric.final { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        .formula { border-radius: 10px; padding: 10px; background: #f3f4f6; color: #4b5563; font-size: 12px; text-align: center; }
        .closed { margin-top: 10px; color: #047857; font-size: 13px; font-weight: 800; }
        .logs { margin-top: 22px; }
        .logs h2 { font-size: 16px; margin: 0 0 10px; }
        .log { display: grid; grid-template-columns: 72px 1fr auto; gap: 10px; align-items: center; border-bottom: 1px solid #eef0f3; padding: 10px 0; font-size: 13px; }
        .badge { border-radius: 999px; padding: 4px 8px; background: #eef2ff; color: #3730a3; font-weight: 700; }
        .pending { background: #fff7ed; color: #9a3412; }
        .hint { color: #6b7280; font-size: 13px; line-height: 1.5; }
        .divider { height: 1px; background: #e5e7eb; margin: 22px 0; }
    </style>
</head>
<body>
    @include('partials.erp-home')
    <div class="app">
        <header>
            <p class="eyebrow">Foreman / Safety Mode</p>
            <h1>{{ __('팀 출퇴근 및 일일 인원 마감') }}</h1>
        </header>

        <main>
            @if($error)
                <div class="alert error">{{ $error }}</div>
            @endif

            @if($errors->any())
                <div class="alert error">
                    @foreach($errors->all() as $validationError)
                        <div>{{ $validationError }}</div>
                    @endforeach
                </div>
            @endif

            @if($result)
                <div class="alert {{ ($result['status'] ?? '') === 'pending' ? 'warning' : 'success' }}">
                    <strong>{{ ($result['event_type'] ?? '') === 'clock_out' ? __('퇴근') : __('출근') }} {{ ($result['ignored'] ?? false) ? __('중복 무시') : __('처리 완료') }}</strong><br>
                    {{ $result['employee_name'] ?? '' }} · {{ $result['event_at'] ?? '' }} · {{ $result['status'] ?? '' }}
                </div>
            @endif

            @if($dailyCrewResult)
                <div class="alert success">
                    <strong>{{ __('일일 인원 마감 완료') }}</strong><br>
                    최종 {{ $dailyCrewResult['final_headcount'] ?? 0 }}명 · {{ $dailyCrewResult['closed_at'] ?? '' }}
                </div>
            @endif

            <section class="context">
                <strong>{{ $qrCode->site?->name ?? '-' }}</strong><br>
                {{ $qrCode->siteContractor?->company_name ?? $qrCode->team?->effectiveCompanyName() ?? '-' }} / {{ $qrCode->team?->name ?? '-' }}
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h2>{{ __('등록 작업자 QR 처리') }}</h2>
                    <div class="hint">{{ __('급여와 연동되는 자사·등록 작업자의 배지 QR만 처리합니다.') }}</div>
                </div>

                <form id="crewForm" method="POST" action="{{ route('attendance-app.crew.record', ['token' => $token]) }}">
                    @csrf
                    <div class="mode">
                        <label><input type="radio" name="mode" value="auto" checked> {{ __('자동') }}</label>
                        <label><input type="radio" name="mode" value="clock_in"> {{ __('출근') }}</label>
                        <label><input type="radio" name="mode" value="clock_out"> {{ __('퇴근') }}</label>
                    </div>

                    <input type="text" id="badgeToken" name="badge_token" placeholder="작업자 배지 QR 토큰 또는 URL" autocomplete="off" required>
                    <input type="text" name="reason" placeholder="사유 선택사항: worker_no_phone / phone_broken" style="margin-top: 10px;">

                    <div class="scanner-actions">
                        <button type="button" id="startScan">{{ __('카메라 스캔') }}</button>
                        <button type="button" id="stopScan">{{ __('스캔 중지') }}</button>
                    </div>

                    <div class="scanner" id="scanner">
                        <video id="preview" muted playsinline></video>
                    </div>

                    <button class="primary" type="submit">{{ __('등록 작업자 출퇴근 처리') }}</button>
                </form>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h2>{{ $dailyCrewSummary['work_date'] }} {{ __('일일 인원 마감') }}</h2>
                    <div class="hint">{{ __('일일이 관리하지 않는 타사·방문 작업자는 인원수만 입력합니다. 급여에는 반영되지 않습니다.') }}</div>
                </div>

                <div class="metrics">
                    <div class="metric">
                        <span>{{ __('QR/출근 등록') }}</span>
                        <strong id="scannedCount">{{ $dailyCrewSummary['scanned_headcount'] }}</strong>
                    </div>
                    <div class="metric">
                        <span>{{ __('미등록 외부') }}</span>
                        <strong id="externalMetric">{{ old('external_headcount', $dailyCrewSummary['external_headcount']) }}</strong>
                    </div>
                    <div class="metric final">
                        <span>{{ __('최종 인원') }}</span>
                        <strong id="finalCount">{{ $dailyCrewSummary['final_headcount'] }}</strong>
                    </div>
                </div>

                <div class="formula">{{ __('최종 인원 = 등록 작업자 + 미등록 외부 인원 + 수동 보정') }}</div>

                <form method="POST" action="{{ route('attendance-app.crew.daily-close', ['token' => $token]) }}">
                    @csrf
                    <label class="field">
                        <span>{{ __('미등록 외부 인원') }}</span>
                        <input type="number" id="externalHeadcount" name="external_headcount" min="0" max="5000" value="{{ old('external_headcount', $dailyCrewSummary['external_headcount']) }}" required>
                    </label>
                    <label class="field">
                        <span>{{ __('수동 보정') }}</span>
                        <input type="number" id="manualAdjustment" name="manual_adjustment" min="-5000" max="5000" value="{{ old('manual_adjustment', $dailyCrewSummary['manual_adjustment']) }}">
                    </label>
                    <label class="field">
                        <span>{{ __('보정 사유') }}</span>
                        <input type="text" name="adjustment_reason" maxlength="500" value="{{ old('adjustment_reason', $dailyCrewSummary['report']?->adjustment_reason) }}" placeholder="누락 추가 또는 중복 제외 사유">
                    </label>
                    <label class="field">
                        <span>{{ __('주요 작업내용') }}</span>
                        <textarea name="work_description" maxlength="500" placeholder="예: 전기 배관 설치, 장비 반입">{{ old('work_description', $dailyCrewSummary['report']?->work_description) }}</textarea>
                    </label>
                    <label class="field">
                        <span>{{ __('특이사항 / 안전 메모') }}</span>
                        <textarea name="notes" maxlength="2000" placeholder="사고, 지연, 교육 또는 기타 특이사항">{{ old('notes', $dailyCrewSummary['report']?->notes) }}</textarea>
                    </label>

                    <button class="primary close-button" type="submit">
                        {{ $dailyCrewSummary['report'] ? '마감 내용 업데이트' : '오늘 인원 마감' }}
                    </button>
                </form>

                @if($dailyCrewSummary['report']?->closed_at)
                    <div class="closed">
                        마감 완료: {{ $dailyCrewSummary['report']->closed_at->format('Y-m-d H:i') }}
                        @if($dailyCrewSummary['report']->closedBy)
                            · {{ $dailyCrewSummary['report']->closedBy->name }}
                        @endif
                    </div>
                @endif
            </section>

            <p class="hint">{{ __('카메라 QR 스캔이 지원되지 않는 휴대폰은 배지 QR 링크나 토큰을 직접 입력할 수 있습니다.') }}</p>

            <section class="logs">
                <h2>{{ __('최근 등록 작업자 처리') }}</h2>
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
                    <p class="hint">{{ __('아직 처리된 등록 작업자가 없습니다.') }}</p>
                @endforelse
            </section>
        </main>
    </div>

    <script>
    // 화면 안의 글도 서버와 같은 사전을 읽는다. 블레이드는 __(), 여기서는 t().
    // 사전이 두 벌이면 한쪽만 번역되는 사고가 난다.
    const TR = @json(\App\Support\AppLocale::dictionary());
    function t(s) { return (TR && TR[s]) || s; }

        const input = document.getElementById('badgeToken');
        const scanner = document.getElementById('scanner');
        const video = document.getElementById('preview');
        const scannedCount = Number(document.getElementById('scannedCount').textContent || 0);
        const externalInput = document.getElementById('externalHeadcount');
        const adjustmentInput = document.getElementById('manualAdjustment');
        const externalMetric = document.getElementById('externalMetric');
        const finalCount = document.getElementById('finalCount');
        let stream = null;
        let timer = null;
        let detector = null;

        function updateHeadcountPreview() {
            const external = Number(externalInput.value || 0);
            const adjustment = Number(adjustmentInput.value || 0);
            externalMetric.textContent = Math.max(0, external);
            finalCount.textContent = Math.max(0, scannedCount + external + adjustment);
        }

        externalInput.addEventListener('input', updateHeadcountPreview);
        adjustmentInput.addEventListener('input', updateHeadcountPreview);
        updateHeadcountPreview();

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

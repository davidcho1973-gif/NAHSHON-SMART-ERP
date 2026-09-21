<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $site->code }} · 새 작업자 등록</title>
    <style>
        :root { color-scheme:light; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Malgun Gothic",Arial,sans-serif; background:#f3f6fa; color:#10233f; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; padding:24px 16px; display:flex; justify-content:center; align-items:flex-start; }
        .card { width:min(100%,480px); background:#fff; border:1px solid #dbe4ef; border-radius:22px; padding:26px 22px; box-shadow:0 14px 36px rgba(16,35,63,.09); }
        .top { display:flex; justify-content:space-between; gap:14px; align-items:flex-start; }
        .eyebrow { margin:0 0 5px; color:#0877bd; font-size:.78rem; font-weight:800; letter-spacing:.06em; }
        h1 { margin:0; font-size:1.55rem; letter-spacing:-.03em; }
        .site { margin:8px 0 22px; color:#607188; line-height:1.45; }
        .langs { display:flex; gap:4px; flex-shrink:0; }
        .langs button { border:1px solid #d7e0ea; background:#fff; border-radius:999px; padding:7px 9px; color:#607188; font-weight:700; }
        .langs button.on { color:#fff; border-color:#0877bd; background:#0877bd; }
        label { display:block; margin:16px 0 7px; color:#334a67; font-size:.9rem; font-weight:800; }
        input { width:100%; min-height:54px; padding:14px; border:1px solid #cbd7e5; border-radius:12px; background:#f8fafc; font:inherit; font-size:1rem; }
        input:focus { outline:3px solid rgba(8,119,189,.18); border-color:#0877bd; background:#fff; }
        .hint { margin:7px 0 0; color:#718198; font-size:.78rem; line-height:1.5; }
        .error { margin:0 0 16px; padding:12px 14px; border-radius:12px; background:#fff0f0; color:#a12828; font-size:.86rem; }
        .error ul { margin:5px 0 0; padding-left:18px; }
        .submit, .gate { display:block; width:100%; min-height:56px; margin-top:22px; padding:16px; border:0; border-radius:13px; background:#0877bd; color:#fff; font:inherit; font-size:1.04rem; font-weight:900; text-align:center; text-decoration:none; cursor:pointer; }
        .privacy { margin:14px 0 0; color:#718198; font-size:.76rem; line-height:1.55; text-align:center; }
        .done { text-align:center; padding:12px 0 4px; }
        .check { width:66px; height:66px; margin:0 auto 16px; display:grid; place-items:center; border-radius:50%; background:#dcfce7; color:#16814b; font-size:34px; font-weight:900; }
        .done h1 { margin-bottom:10px; }
        .done p { color:#607188; line-height:1.6; word-break:keep-all; }
        .moving { margin-top:16px; padding:12px; border-radius:12px; background:#edf7ff; color:#075985; font-weight:800; }
    </style>
</head>
<body>
<main class="card">
    @if ($done)
        <section class="done">
            <div class="check">✓</div>
            <h1 id="done-title">등록되었습니다</h1>
            <p><strong>{{ $workerName }}</strong><br><span id="done-copy">이 휴대폰을 출퇴근용으로 연결했습니다.</span></p>
            <div class="moving" id="moving">출근 화면으로 이동합니다…</div>
            <a class="gate" id="gate-link" href="{{ $gateUrl }}">출근 화면 열기</a>
        </section>
        <script>
            (function () {
                var myId = String(@json($employee->id));
                var shared = false;
                try {
                    var previous = localStorage.getItem('workerJoinLastPerson');
                    shared = !!previous && previous !== myId;
                    if (shared) {
                        localStorage.removeItem('dasolWorkerDevice');
                    } else {
                        localStorage.setItem('dasolWorkerDevice', @json($deviceToken));
                    }
                    localStorage.setItem('workerJoinLastPerson', myId);
                    localStorage.setItem('dasolWorkerLang', @json($lang));
                } catch (e) {}

                var words = {
                    ko: shared
                        ? ['등록되었습니다', '공용 휴대폰으로 판단되어 자동 본인 연결은 하지 않았습니다. 출근 화면에서 본인을 확인해 주세요.', '출근 화면으로 이동합니다…', '출근 화면 열기']
                        : ['등록되었습니다', '이 휴대폰을 출퇴근용으로 연결했습니다.', '바로 출근할 수 있도록 이동합니다…', '출근 화면 열기'],
                    en: shared
                        ? ['Registration complete', 'This appears to be a shared phone. Confirm your identity on the attendance screen.', 'Opening attendance…', 'Open attendance']
                        : ['Registration complete', 'This phone is now linked for attendance.', 'Opening attendance so you can clock in…', 'Open attendance'],
                    es: shared
                        ? ['Registro completo', 'Parece ser un teléfono compartido. Confirme su identidad en la pantalla de asistencia.', 'Abriendo asistencia…', 'Abrir asistencia']
                        : ['Registro completo', 'Este teléfono quedó vinculado para la asistencia.', 'Abriendo asistencia para marcar entrada…', 'Abrir asistencia']
                }[@json($lang)];
                document.getElementById('done-title').textContent = words[0];
                document.getElementById('done-copy').textContent = words[1];
                document.getElementById('moving').textContent = words[2];
                document.getElementById('gate-link').textContent = words[3];

                window.setTimeout(function () { window.location.replace(@json($gateUrl)); }, 900);
            })();
        </script>
    @else
        <div class="top">
            <div>
                <p class="eyebrow">{{ \App\Support\Org::name() }} · {{ $site->code }}</p>
                <h1 id="title">새 작업자 등록</h1>
            </div>
            <div class="langs">
                @foreach ($langOptions as $code => $label)
                    <button type="button" data-lang="{{ $code }}" @class(['on' => $lang === $code])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <p class="site">{{ $site->name }}<br><span id="intro">이름과 전화번호만 입력하면 바로 출퇴근을 시작할 수 있습니다.</span></p>

        @if ($errors->any())
            <div class="error"><strong id="error-title">입력을 확인해 주세요.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ route('worker-join.store', $site) }}">
            @csrf
            <input type="hidden" name="preferred_language" id="language" value="{{ old('preferred_language', $lang) }}">
            <label for="full_name" id="name-label">이름</label>
            <input id="full_name" name="full_name" value="{{ old('full_name') }}" autocomplete="name" required autofocus placeholder="홍길동">

            <label for="phone" id="phone-label">전화번호</label>
            <input id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel" inputmode="tel" required placeholder="480-555-0100">
            <p class="hint" id="phone-hint">이 번호와 이 휴대폰을 연결해 다음 QR 스캔 때 바로 본인을 찾습니다.</p>

            <button class="submit" type="submit" id="submit">등록하고 출근하기</button>
            <p class="privacy" id="privacy">추가 인사정보와 W-9은 인사담당자가 보내는 개인 보안 링크에서 나중에 작성합니다.</p>
        </form>

        <script>
            (function () {
                var copy = {
                    ko: ['새 작업자 등록','이름과 전화번호만 입력하면 바로 출퇴근을 시작할 수 있습니다.','이름','홍길동','전화번호','이 번호와 이 휴대폰을 연결해 다음 QR 스캔 때 바로 본인을 찾습니다.','등록하고 출근하기','추가 인사정보와 W-9은 인사담당자가 보내는 개인 보안 링크에서 나중에 작성합니다.','입력을 확인해 주세요.'],
                    en: ['New worker registration','Enter your name and phone number to start attendance now.','Full name','John Smith','Phone number','We link this number and phone so the next QR scan recognizes you.','Register and clock in','Complete W-9 and other HR information later through a private secure link from HR.','Please check your entries.'],
                    es: ['Registro de trabajador nuevo','Ingrese su nombre y teléfono para comenzar la asistencia ahora.','Nombre completo','Juan Pérez','Teléfono','Vinculamos este número y teléfono para reconocerlo en el próximo QR.','Registrarme y marcar entrada','Complete el W-9 y otros datos después mediante un enlace privado de Recursos Humanos.','Revise los datos.']
                };
                function setLanguage(lang) {
                    var t = copy[lang] || copy.ko;
                    document.documentElement.lang = lang;
                    document.getElementById('language').value = lang;
                    document.getElementById('title').textContent = t[0];
                    document.getElementById('intro').textContent = t[1];
                    document.getElementById('name-label').textContent = t[2];
                    document.getElementById('full_name').placeholder = t[3];
                    document.getElementById('phone-label').textContent = t[4];
                    document.getElementById('phone-hint').textContent = t[5];
                    document.getElementById('submit').textContent = t[6];
                    document.getElementById('privacy').textContent = t[7];
                    var e = document.getElementById('error-title'); if (e) e.textContent = t[8];
                    document.querySelectorAll('[data-lang]').forEach(function (b) { b.classList.toggle('on', b.dataset.lang === lang); });
                }
                document.querySelectorAll('[data-lang]').forEach(function (b) { b.addEventListener('click', function () { setLanguage(b.dataset.lang); }); });
                setLanguage(@json($lang));
            })();
        </script>
    @endif
</main>
</body>
</html>

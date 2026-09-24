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
        /* 제목과 언어칩이 한 줄을 나눠 쓰면 좁은 폰에서 제목이 「새 / 작업자 / 등록」 으로
           세 줄이 된다. 언어칩은 보조 도구이니 제목에 한 줄을 통째로 내준다. */
        .top { display:block; }
        .langs { margin-top:12px; }
        .eyebrow { margin:0 0 5px; color:#0877bd; font-size:.78rem; font-weight:800; letter-spacing:.06em; }
        /* 한글은 기본값이 글자 단위로 끊겨 좁은 폰에서 「새 작업 / 자 등록」 처럼 낱말 한가운데가
           갈라진다. 낱말 단위로 넘긴다(worker-join/form.blade.php 와 같은 이유, 같은 규칙). */
        h1, .eyebrow, .site, label, .hint, .privacy { word-break:keep-all; }
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
            {{-- PIN 은 권유지 관문이 아니다. 첫 출근을 막지 않는 자리에, 이유와 함께 둔다. --}}
            @if ($pinSetupUrl)
                <a class="pin" id="pin-link" href="{{ $pinSetupUrl }}">PIN 만들기 — 다른 휴대폰에서도 쓰려면</a>
            @endif
        </section>
        <script src="{{ asset('js/worker-device-remember.js') }}?v={{ filemtime(public_path('js/worker-device-remember.js')) }}"></script>
        <script>
            (function () {
                // 이 폰을 기억할지 말지는 worker-device-remember.js 한 곳이 정한다
                // (반장 폰으로 팀원을 여럿 등록했을 때 남의 출근이 찍히는 것을 막는 규칙).
                // 공용 휴대폰인지는 서버가 정한다 — 토큰을 안 준 것이 그 답이다.
                // 브라우저의 판단은 그대로 두되(이미 저장된 남의 토큰을 지우는 일을 한다),
                // 결론은 서버 쪽을 따른다. 판단이 두 벌이면 한쪽만 고쳐진다.
                var shared = window.rememberWorkerDevice(@json($employee->id), @json($deviceToken), @json($lang))
                    || @json($deviceToken === '');

                var words = {
                    ko: shared
                        ? ['등록되었습니다', '공용 휴대폰으로 보여 이 휴대폰을 본인 것으로 연결하지 않았습니다. 본인 휴대폰에서 같은 QR 을 찍으면 바로 출근할 수 있습니다.', '출근 화면으로 이동합니다…', '출근 화면 열기', 'PIN 만들기 — 다른 휴대폰에서도 쓰려면']
                        : ['등록되었습니다', '이 휴대폰을 출퇴근용으로 연결했습니다. 바로 출근을 찍을 수 있습니다.', '출근 화면으로 이동합니다…', '출근 화면 열기', 'PIN 만들기 — 다른 휴대폰에서도 쓰려면'],
                    en: shared
                        ? ['Registration complete', 'This looks like a shared phone, so it was not linked to you. Scan the same QR on your own phone and you can clock in right away.', 'Opening attendance…', 'Open attendance', 'Create a PIN — to use another phone']
                        : ['Registration complete', 'This phone is linked for attendance. You can clock in right now.', 'Opening attendance…', 'Open attendance', 'Create a PIN — to use another phone'],
                    es: shared
                        ? ['Registro completo', 'Parece un teléfono compartido, así que no se vinculó a usted. Escanee el mismo QR en su propio teléfono y podrá marcar entrada de inmediato.', 'Abriendo asistencia…', 'Abrir asistencia', 'Cree un PIN — para usar otro teléfono']
                        : ['Registro completo', 'Este teléfono quedó vinculado para la asistencia. Ya puede marcar entrada.', 'Abriendo asistencia…', 'Abrir asistencia', 'Cree un PIN — para usar otro teléfono']
                }[@json($lang)];
                document.getElementById('done-title').textContent = words[0];
                document.getElementById('done-copy').textContent = words[1];
                document.getElementById('moving').textContent = words[2];
                document.getElementById('gate-link').textContent = words[3];
                var pinLink = document.getElementById('pin-link');
                if (pinLink) { pinLink.textContent = words[4]; }

                // 공용 휴대폰이면 데려가지 않는다 — 여기서 읽어야 할 안내가 있고,
                // 그 화면에서 이 사람이 찍을 수 있는 것도 없다.
                if (!shared) {
                    window.setTimeout(function () { window.location.replace(@json($gateUrl)); }, 900);
                } else {
                    document.getElementById('moving').textContent = '';
                }
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

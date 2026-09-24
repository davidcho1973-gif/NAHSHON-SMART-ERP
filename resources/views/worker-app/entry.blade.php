{{--
    작업자 앱 문 앞 — 이 화면은 보통 <b>눈에 띄지 않는다.</b>

    휴대폰에 기기 토큰이 있으면 JS 가 바로 건네고 앱으로 넘어간다. 토큰이 없을 때만
    사람이 이 화면을 읽게 되는데, 그때 보여 줄 것은 ERP 로그인 화면이 아니라
    «무엇을 하면 되는지» 다 — 작업자에게 이메일과 비밀번호를 물으면 거기가 끝이다.
--}}
@php($t = [
    'ko' => [
        'wait' => '잠시만요…',
        'findTitle' => '전화번호 뒷 4자리를 넣어 주세요',
        'findBody' => '등록하신 전화번호의 마지막 네 자리입니다. 비밀번호도 PIN 도 없습니다.',
        'last4' => '전화번호 뒷 4자리',
        'noMatch' => '찾지 못했습니다. 번호를 다시 확인하시거나 현장 QR 로 등록해 주세요.',
        'title' => '휴대폰을 찾을 수 없습니다',
        'body' => '이 휴대폰은 아직 현장에 등록되어 있지 않습니다. 현장에 붙은 파란 「새 작업자 등록」 QR 을 휴대폰 카메라로 찍어 이름과 전화번호를 넣어 주세요. 등록을 마치면 이 화면을 거치지 않고 바로 열립니다.',
        'gate' => '이미 등록했는데 안 열리면, 출입구 QR 을 한 번 찍은 뒤 다시 시도해 주세요.',
        'retry' => '다시 시도',
    ],
    'en' => [
        'wait' => 'One moment…',
        'findTitle' => 'Enter the last 4 digits of your phone',
        'findBody' => 'The last four digits of your registered phone number. No password, no PIN.',
        'last4' => 'Last 4 digits',
        'noMatch' => 'Not found. Check the digits, or register with the site QR.',
        'title' => 'This phone is not registered yet',
        'body' => 'Scan the blue “New Worker Sign-Up” QR posted on site with your phone camera and enter your name and phone number. After that this screen is skipped.',
        'gate' => 'Already registered? Scan the gate QR once, then try again.',
        'retry' => 'Try again',
    ],
    'es' => [
        'wait' => 'Un momento…',
        'findTitle' => 'Escriba los últimos 4 dígitos de su teléfono',
        'findBody' => 'Los últimos cuatro dígitos de su teléfono registrado. Sin contraseña y sin PIN.',
        'last4' => 'Últimos 4 dígitos',
        'noMatch' => 'No encontrado. Revise los dígitos o regístrese con el QR de la obra.',
        'title' => 'Este teléfono aún no está registrado',
        'body' => 'Escanee con la cámara el QR azul de “Registro de trabajador nuevo” que está en la obra y escriba su nombre y teléfono. Después esta pantalla no aparece más.',
        'gate' => '¿Ya se registró? Escanee el QR de la entrada una vez y vuelva a intentar.',
        'retry' => 'Intentar de nuevo',
    ],
][$lang] ?? [])
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ \App\Support\Org::name() }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <style>
        :root {
            color-scheme: light;
            font-family: 'Pretendard Variable', Pretendard, -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', Arial, sans-serif;
            --blue: #0877BD; --ink: #10233F; --ink-2: #5A6270; --paper: #F3F6FA;
            background: var(--paper); color: var(--ink);
        }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px; box-sizing: border-box; }
        .card { width: min(100%, 440px); background: #fff; border-radius: 20px; padding: 28px 22px; text-align: center; box-shadow: 0 12px 30px rgba(16,35,63,.08); }
        h1, p { word-break: keep-all; }
        h1 { margin: 0 0 12px; font-size: 1.4rem; font-weight: 800; }
        p { margin: 0 0 10px; color: var(--ink-2); font-size: 1rem; line-height: 1.65; }
        .spin { width: 42px; height: 42px; margin: 6px auto 16px; border: 4px solid #E3E9F1; border-top-color: var(--blue); border-radius: 50%; animation: t 0.9s linear infinite; }
        @keyframes t { to { transform: rotate(360deg); } }
        .go { display: block; margin-top: 20px; padding: 18px; border-radius: 14px; background: var(--blue); color: #fff; font-size: 1.05rem; font-weight: 800; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <div id="waiting">
            <div class="spin"></div>
            <p>{{ $t['wait'] }}</p>
        </div>

        {{-- 휴대폰이 기억돼 있지 않을 때 — 외울 것을 주지 않는다. 자기 전화번호 뒷 4자리다.
             작업자·반장·관리자가 같은 문을 쓴다. 이 문으로 열리는 것은 작업자 앱뿐이다. --}}
        <div id="stuck" hidden>
            <h1>{{ $t['findTitle'] }}</h1>
            <p>{{ $t['findBody'] }}</p>
            <label for="last4">{{ $t['last4'] }}</label>
            <input id="last4" type="tel" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off"
                   style="width:100%;padding:14px;font-size:20px;letter-spacing:.3em;text-align:center;border:1px solid #d8d5cd;border-radius:10px;margin:10px 0">
            <p id="app-notice" role="status" aria-live="polite"></p>
            <div id="matches"></div>
            <p style="font-size:13px;opacity:.75">{{ $t['body'] }}</p>
            <a class="go" href="{{ route('worker-app.entry') }}">{{ $t['retry'] }}</a>
        </div>

        {{-- 토큰은 localStorage 에 있다. 서버는 그걸 못 읽으므로 여기서 한 번 건넨다.
             건네받은 서버가 쿠키로도 심어 두기 때문에, 이 왕복은 폰마다 딱 한 번이다. --}}
        <form id="hand" method="POST" action="{{ route('worker-app.device') }}" hidden>
            @csrf
            <input type="hidden" name="device_token" id="device_token">
        </form>
    </div>

    <script>
        (function () {
            var failed = @json($failed);
            var token = null;
            try { token = localStorage.getItem('dasolWorkerDevice'); } catch (e) {}

            // 방금 건넸는데 또 여기로 왔다면 그 토큰은 서버가 모르는 것이다.
            // 다시 보내면 끝없이 돈다 — 그때는 사람에게 무엇을 하면 되는지 말한다.
            if (!token || failed) {
                document.getElementById('waiting').hidden = true;
                document.getElementById('stuck').hidden = false;
                return;
            }

            document.getElementById('device_token').value = token;
            document.getElementById('hand').submit();
        })();

        // 뒷 4자리로 본인 찾기 — 네 자리가 차면 스스로 찾고, 이름을 누르면 들어간다.
        (function () {
            var box = document.getElementById('last4');
            var list = document.getElementById('matches');
            var note = document.getElementById('app-notice');
            var csrf = document.querySelector('meta[name="csrf-token"]');
            var urls = { find: @json(route('worker-app.find')), enter: @json(route('worker-app.enter')) };
            var busy = false;

            function post(url, body) {
                return fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json', 'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf ? csrf.content : '',
                    },
                    body: JSON.stringify(body),
                }).then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || ''); return d; }); });
            }

            box.addEventListener('input', function () {
                var v = box.value.replace(/\D/g, '').slice(0, 4);
                if (v !== box.value) { box.value = v; }
                list.innerHTML = ''; note.textContent = '';
                if (v.length !== 4 || busy) { return; }
                busy = true;
                post(urls.find, { last4: v }).then(function (d) {
                    var ws = (d && d.workers) || [];
                    if (!ws.length) { note.textContent = @json($t['noMatch']); return; }
                    list.innerHTML = ws.map(function (w) {
                        return '<button type="button" class="go pick" data-id="' + w.id + '">' + w.name +
                            (w.site ? ' · ' + w.site : '') + '</button>';
                    }).join('');
                    Array.prototype.forEach.call(list.querySelectorAll('.pick'), function (b) {
                        b.onclick = function () { enter(b.dataset.id); };
                    });
                }).catch(function (e) { note.textContent = e.message || @json($t['noMatch']); })
                  .then(function () { busy = false; });
            });

            function enter(id) {
                if (busy) { return; }
                busy = true;
                post(urls.enter, { employee_id: Number(id) }).then(function (d) {
                    try { localStorage.setItem('dasolWorkerDevice', d.device_token); } catch (e) {}
                    window.location.replace(d.redirect);
                }).catch(function (e) { note.textContent = e.message || ''; busy = false; });
            }
        })();
    </script>
</body>
</html>

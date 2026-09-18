@php
    /**
     * 장비 사용 점검 — 장비 앞에 서서, 장갑 낀 손으로, 1분 안에.
     *
     * ── 화면을 이렇게 짠 이유 ──────────────────────────────────────────
     *  · 항목은 모두 «그래야 하는 상태» 를 적은 평서문이고, 버튼은 「맞다 / 아니다」
     *    둘뿐이다. 「예/아니오」 로 두면 부정문 항목에서 뜻이 뒤집힌다.
     *  · 버튼을 손가락 크기(56px)로 둔다. 작으면 장갑 낀 손이 옆을 누르고, 옆을
     *    누른 줄 모르고 제출한다 — 틀린 점검표가 남는 것이 없는 것보다 나쁘다.
     *  · 「아니다」 를 누르면 그 자리에서 사진과 메모 칸이 열린다. 나중에 올리라고
     *    하면 아무도 안 올린다.
     *  · 남은 항목 수를 바닥에 계속 띄운다. 스무 줄짜리 화면에서 한 줄 빠뜨린 것을
     *    사람이 찾게 하면 그 사람은 찾지 않고 대충 누른다.
     */
    $t = [
        'ko' => [
            'title' => '장비 사용 점검',
            'pre' => '사용 전 점검', 'post' => '사용 종료 점검',
            'preIntro' => '이 장비를 쓰기 전에 아래를 확인해 주세요.',
            'postIntro' => '장비를 돌려놓기 전에 아래를 확인해 주세요.',
            'yes' => '맞다', 'no' => '아니다',
            'notePlaceholder' => '무엇이 문제인지 적어 주세요',
            'photo' => '사진 찍기', 'photoAdded' => '사진 있음',
            'submit' => '제출', 'remaining' => '남은 항목 :n 개',
            'submitting' => '보내는 중…', 'sendFail' => '보내지 못했습니다. 인터넷을 확인하고 다시 눌러 주세요.',
            'blocked' => '이 장비는 사용 금지 상태입니다',
            'blockedBody' => '앞선 점검에서 치명 항목에 이상이 있었습니다. 반장이 확인하고 풀어 주기 전에는 쓸 수 없습니다.',
            'heldBy' => ':name 님이 사용 중입니다',
            'heldByBody' => '그대로 진행하면 이 장비는 당신 앞으로 넘어옵니다. 먼저 그분께 말씀해 주세요.',
            'noTemplate' => '이 장비에 맞는 점검표가 아직 없습니다. 반장에게 알려 주세요.',
            'lastChecked' => '마지막 점검', 'never' => '기록 없음',
            'site' => '현장', 'code' => '장비 번호', 'model' => '모델',
            'done' => '완료', 'backToApp' => '앱으로 돌아가기', 'again' => '이 장비 다시 열기',
            'criticalTag' => '필수',
        ],
        'en' => [
            'title' => 'Equipment Check',
            'pre' => 'Pre-Use Check', 'post' => 'Return Check',
            'preIntro' => 'Confirm the following before using this equipment.',
            'postIntro' => 'Confirm the following before putting it back.',
            'yes' => 'Yes', 'no' => 'No',
            'notePlaceholder' => 'Tell us what is wrong',
            'photo' => 'Take a photo', 'photoAdded' => 'Photo added',
            'submit' => 'Submit', 'remaining' => ':n item(s) left',
            'submitting' => 'Sending…', 'sendFail' => 'Could not send. Check your internet and try again.',
            'blocked' => 'This equipment is out of service',
            'blockedBody' => 'A critical item failed on an earlier check. It cannot be used until your foreman clears it.',
            'heldBy' => ':name has it out',
            'heldByBody' => 'If you continue, this equipment moves to you. Talk to them first.',
            'noTemplate' => 'There is no checklist for this equipment yet. Please tell your foreman.',
            'lastChecked' => 'Last checked', 'never' => 'never',
            'site' => 'Site', 'code' => 'Asset no.', 'model' => 'Model',
            'done' => 'Done', 'backToApp' => 'Back to the app', 'again' => 'Open this equipment again',
            'criticalTag' => 'Required',
        ],
        'es' => [
            'title' => 'Revisión de equipo',
            'pre' => 'Revisión previa', 'post' => 'Revisión de devolución',
            'preIntro' => 'Confirme lo siguiente antes de usar este equipo.',
            'postIntro' => 'Confirme lo siguiente antes de devolverlo.',
            'yes' => 'Sí', 'no' => 'No',
            'notePlaceholder' => 'Diga cuál es el problema',
            'photo' => 'Tomar foto', 'photoAdded' => 'Foto agregada',
            'submit' => 'Enviar', 'remaining' => 'Faltan :n punto(s)',
            'submitting' => 'Enviando…', 'sendFail' => 'No se pudo enviar. Revise su internet e intente de nuevo.',
            'blocked' => 'Este equipo está fuera de servicio',
            'blockedBody' => 'Un punto crítico falló en una revisión anterior. No se puede usar hasta que su capataz lo libere.',
            'heldBy' => ':name lo tiene',
            'heldByBody' => 'Si continúa, este equipo pasa a su nombre. Hable con esa persona primero.',
            'noTemplate' => 'Todavía no hay lista de revisión para este equipo. Avise a su capataz.',
            'lastChecked' => 'Última revisión', 'never' => 'sin registro',
            'site' => 'Obra', 'code' => 'N.º de activo', 'model' => 'Modelo',
            'done' => 'Listo', 'backToApp' => 'Volver a la app', 'again' => 'Abrir este equipo otra vez',
            'criticalTag' => 'Obligatorio',
        ],
    ][$lang] ?? [];

    $eq = $screen['equipment'];
    $accent = \App\Support\Org::color();
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>{{ $t['title'] }} — {{ $eq['code'] }}</title>
    <style>
        :root { --accent: {{ $accent }}; --line: #EDEEF0; --muted: #6b7280; --bad: #dc2626; --warn: #b45309; --good: #047857; }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { margin: 0; padding: 0 0 116px; background: #f5f6f8; color: #191919;
               font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans KR", sans-serif;
               font-size: 16px; line-height: 1.55; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 0 16px; }

        header { background: #fff; border-bottom: 1px solid var(--line); padding: 14px 0; }
        .head { display: flex; gap: 12px; align-items: center; }
        .head img { width: 64px; height: 64px; border-radius: 10px; object-fit: cover; background: #e5e7eb; flex: none; }
        .head .ph { width: 64px; height: 64px; border-radius: 10px; background: #e5e7eb; flex: none;
                    display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 26px; }
        .head h1 { margin: 0; font-size: 19px; font-weight: 700; line-height: 1.3; }
        .head .sub { color: var(--muted); font-size: 13.5px; margin-top: 2px; }
        .langs { display: flex; gap: 6px; margin-top: 12px; }
        .langs button { flex: 1; min-height: 40px; border: 1px solid var(--line); background: #fff; border-radius: 8px;
                        font-size: 14px; color: #374151; }
        .langs button[aria-pressed="true"] { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 700; }

        .stage { margin: 16px 0 6px; font-size: 20px; font-weight: 800; }
        .intro { color: var(--muted); margin: 0 0 14px; font-size: 14.5px; }

        .panel { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px; margin-bottom: 12px; }
        .panel.bad { background: #fef2f2; border-color: #fecaca; }
        .panel.warn { background: #fffbeb; border-color: #fde68a; }
        .panel h2 { margin: 0 0 6px; font-size: 16.5px; }
        .panel.bad h2 { color: var(--bad); }
        .panel.warn h2 { color: var(--warn); }
        .panel p { margin: 0; font-size: 14.5px; }
        .warnlist { margin: 0; padding-left: 18px; font-size: 14.5px; color: var(--warn); }
        .warnlist li + li { margin-top: 4px; }

        .item { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px; margin-bottom: 10px; }
        .item.answered-no { border-color: #fecaca; background: #fff7f7; }
        .item .q { font-size: 16.5px; font-weight: 600; line-height: 1.45; }
        .item .help { color: var(--muted); font-size: 13.5px; margin-top: 4px; }
        .tag { display: inline-block; font-size: 11.5px; font-weight: 700; color: var(--bad);
               background: #fee2e2; border-radius: 999px; padding: 2px 8px; margin-left: 6px; vertical-align: 2px; }
        .choices { display: flex; gap: 10px; margin-top: 12px; }
        .choices button { flex: 1; min-height: 56px; border-radius: 10px; border: 2px solid var(--line);
                          background: #fff; font-size: 17px; font-weight: 700; color: #374151; }
        .choices button[aria-pressed="true"].ok { background: #ecfdf5; border-color: #10b981; color: var(--good); }
        .choices button[aria-pressed="true"].ng { background: #fee2e2; border-color: #ef4444; color: var(--bad); }
        .detail { margin-top: 12px; display: none; }
        .detail.on { display: block; }
        .detail textarea { width: 100%; min-height: 72px; border: 1px solid var(--line); border-radius: 8px;
                           padding: 10px; font: inherit; font-size: 15px; resize: vertical; }
        .photo-btn { margin-top: 8px; width: 100%; min-height: 48px; border: 1px dashed #9ca3af; background: #fff;
                     border-radius: 8px; font-size: 15px; color: #374151; }
        .photo-btn.has { border-style: solid; border-color: #10b981; color: var(--good); font-weight: 700; }
        .detail input[type=file] { display: none; }

        .bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; border-top: 1px solid var(--line);
               padding: 12px 16px calc(12px + env(safe-area-inset-bottom)); }
        .bar .inner { max-width: 640px; margin: 0 auto; }
        .bar .left { font-size: 13.5px; color: var(--muted); margin-bottom: 8px; text-align: center; }
        .bar button { width: 100%; min-height: 56px; border: none; border-radius: 12px; background: var(--accent);
                      color: #fff; font-size: 18px; font-weight: 800; }
        .bar button[disabled] { background: #cbd5e1; }

        .result { text-align: center; padding: 40px 0; }
        .result .mark { font-size: 56px; line-height: 1; }
        .result h2 { margin: 14px 0 8px; font-size: 22px; }
        .result p { margin: 0 auto; max-width: 420px; font-size: 16px; }
        .result .acts { margin-top: 26px; display: flex; flex-direction: column; gap: 10px; }
        .result .acts a { min-height: 52px; display: flex; align-items: center; justify-content: center;
                          border-radius: 10px; text-decoration: none; font-weight: 700; font-size: 16px; }
        .result .acts .go { background: var(--accent); color: #fff; }
        .result .acts .quiet { background: #fff; color: #374151; border: 1px solid var(--line); }
        .hidden { display: none !important; }
    </style>
</head>
<body>
<header>
    <div class="wrap">
        <div class="head">
            @if ($eq['photo'])
                <img src="{{ \Illuminate\Support\Str::startsWith($eq['photo'], ['http', '/']) ? $eq['photo'] : '/storage/'.$eq['photo'] }}" alt="">
            @else
                <div class="ph">🛠</div>
            @endif
            <div>
                <h1>{{ $eq['name'] ?: $eq['code'] }}</h1>
                <div class="sub">
                    {{ $t['code'] }} {{ $eq['code'] }}
                    @if ($eq['model']) · {{ $t['model'] }} {{ $eq['model'] }} @endif
                    @if ($eq['site']) · {{ $t['site'] }} {{ $eq['site'] }} @endif
                </div>
                <div class="sub">
                    {{ $t['lastChecked'] }}: {{ $eq['lastCheckedAt'] ?: $t['never'] }}
                </div>
            </div>
        </div>
        <div class="langs" id="langs">
            @foreach (['ko' => '한국어', 'en' => 'English', 'es' => 'Español'] as $code => $label)
                <button type="button" data-lang="{{ $code }}" aria-pressed="{{ $lang === $code ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>
</header>

<main class="wrap" id="form">
    @if ($screen['blocked'])
        <div class="panel bad" style="margin-top:16px">
            <h2>⛔ {{ $t['blocked'] }}</h2>
            <p>{{ $t['blockedBody'] }}</p>
        </div>
    @else
        <div class="stage">{{ $screen['stage'] === 'post_use' ? $t['post'] : $t['pre'] }}</div>
        <p class="intro">{{ $screen['stage'] === 'post_use' ? $t['postIntro'] : $t['preIntro'] }}</p>

        @if ($screen['heldBy'])
            <div class="panel warn">
                <h2>{{ str_replace(':name', $screen['heldBy'], $t['heldBy']) }}</h2>
                <p>{{ $t['heldByBody'] }}</p>
            </div>
        @endif

        @if ($screen['warnings'])
            <div class="panel warn">
                <ul class="warnlist">
                    @foreach ($screen['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (! $screen['template'])
            <div class="panel"><p>{{ $t['noTemplate'] }}</p></div>
        @else
            @foreach ($screen['template']['items'] as $item)
                <section class="item" data-item="{{ $item['id'] }}">
                    <div class="q">{{ $item['label'] }}@if ($item['critical'])<span class="tag">{{ $t['criticalTag'] }}</span>@endif</div>
                    @if ($item['help'])<div class="help">{{ $item['help'] }}</div>@endif
                    <div class="choices">
                        <button type="button" class="ok" data-ok="1" aria-pressed="false">✓ {{ $t['yes'] }}</button>
                        <button type="button" class="ng" data-ok="0" aria-pressed="false">✕ {{ $t['no'] }}</button>
                    </div>
                    <div class="detail">
                        <textarea placeholder="{{ $t['notePlaceholder'] }}" maxlength="500"></textarea>
                        <button type="button" class="photo-btn">📷 {{ $t['photo'] }}</button>
                        <input type="file" accept="image/*" capture="environment">
                    </div>
                </section>
            @endforeach
        @endif
    @endif
</main>

@if (! $screen['blocked'] && $screen['template'])
    <div class="bar" id="bar">
        <div class="inner">
            <div class="left" id="left"></div>
            <button type="button" id="send" disabled>{{ $t['submit'] }}</button>
        </div>
    </div>
@endif

<div class="wrap result hidden" id="result">
    <div class="mark" id="result-mark"></div>
    <h2 id="result-title"></h2>
    <p id="result-body"></p>
    <div class="acts">
        <a class="go" href="{{ route('attendance-app.index') }}">{{ $t['backToApp'] }}</a>
        <a class="quiet" href="{{ route('equipment-checklist.show', ['token' => $token]) }}">{{ $t['again'] }}</a>
    </div>
</div>

<script>
(function () {
    'use strict';

    var T = @json($t);
    var STAGE = @json($screen['stage']);
    var SUBMIT_URL = @json(route('equipment-checklist.submit', ['token' => $token]));
    var LANG_URL = @json(route('attendance-app.language'));
    var CSRF = @json(csrf_token());
    var LANG = @json($lang);
    var TOTAL = document.querySelectorAll('.item').length;

    // ── 언어 ────────────────────────────────────────────────────────
    // 고른 언어를 서버도 알아야 한다. 화면에만 두면 이 화면만 바뀌고, 여기서
    // 들어가는 다른 화면은 계속 옛 언어로 뜬다.
    var langs = document.getElementById('langs');
    if (langs) {
        langs.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-lang]');
            if (!b || b.dataset.lang === LANG) return;
            fetch(LANG_URL, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                body: JSON.stringify({ lang: b.dataset.lang })
            }).then(function () { window.location.reload(); })
              .catch(function () { window.location.reload(); });
        });
    }

    // ── 답 ──────────────────────────────────────────────────────────
    var answers = {};     // itemId -> { ok, note, file }

    document.querySelectorAll('.item').forEach(function (item) {
        var id = item.dataset.item;
        var detail = item.querySelector('.detail');
        var note = item.querySelector('textarea');
        var photoBtn = item.querySelector('.photo-btn');
        var file = item.querySelector('input[type=file]');

        item.querySelectorAll('.choices button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var ok = btn.dataset.ok === '1';
                item.querySelectorAll('.choices button').forEach(function (b) {
                    b.setAttribute('aria-pressed', String(b === btn));
                });
                answers[id] = answers[id] || {};
                answers[id].ok = ok;
                // 「아니다」 면 그 자리에서 사진·메모를 받는다. 나중에 올리라고 하면 아무도 안 올린다.
                detail.classList.toggle('on', !ok);
                item.classList.toggle('answered-no', !ok);
                refresh();
            });
        });

        note.addEventListener('input', function () {
            answers[id] = answers[id] || {};
            answers[id].note = note.value;
        });

        photoBtn.addEventListener('click', function () { file.click(); });
        file.addEventListener('change', function () {
            if (!file.files || !file.files[0]) return;
            answers[id] = answers[id] || {};
            answers[id].file = file.files[0];
            photoBtn.textContent = '✓ ' + T.photoAdded;
            photoBtn.classList.add('has');
        });
    });

    var send = document.getElementById('send');
    var left = document.getElementById('left');

    function answeredCount() {
        return Object.keys(answers).filter(function (k) { return typeof answers[k].ok === 'boolean'; }).length;
    }

    function refresh() {
        if (!send) return;
        var remaining = TOTAL - answeredCount();
        left.textContent = remaining > 0 ? T.remaining.replace(':n', String(remaining)) : '';
        send.disabled = remaining > 0;
    }
    refresh();

    // ── 위치 ────────────────────────────────────────────────────────
    // 창고에 세워 둔 장비를 집에서 «점검했다» 고 찍는 것을 막지는 못하지만,
    // 나중에 물어볼 수는 있어야 한다. 못 잡아도 제출은 막지 않는다.
    var here = null;
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function (p) {
            here = { lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy };
        }, function () {}, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
    }

    if (send) {
        send.addEventListener('click', async function () {
            send.disabled = true;
            var label = send.textContent;
            send.textContent = T.submitting;

            var body = new FormData();
            body.append('stage', STAGE);
            body.append('lang', LANG);
            if (here) {
                body.append('lat', here.lat);
                body.append('lng', here.lng);
                body.append('accuracy', Math.round(here.accuracy));
            }
            Object.keys(answers).forEach(function (id) {
                var a = answers[id];
                if (typeof a.ok !== 'boolean') return;
                body.append('answers[' + id + '][ok]', a.ok ? '1' : '0');
                if (a.note) body.append('answers[' + id + '][note]', a.note);
                if (a.file) body.append('photo_' + id, a.file);
            });

            try {
                var r = await fetch(SUBMIT_URL, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                    body: body
                });
                var j = await r.json();
                if (j.success) return showResult(j.result, j.message);
                send.disabled = false;
                send.textContent = label;
                alert(j.error || T.sendFail);
            } catch (err) {
                send.disabled = false;
                send.textContent = label;
                alert(T.sendFail);
            }
        });
    }

    function showResult(result, message) {
        document.getElementById('form').classList.add('hidden');
        var bar = document.getElementById('bar');
        if (bar) bar.classList.add('hidden');
        document.querySelector('header').classList.add('hidden');

        var marks = { pass: '✅', fail: '⚠️', blocked: '⛔' };
        document.getElementById('result-mark').textContent = marks[result] || '✅';
        document.getElementById('result-title').textContent = result === 'blocked' ? T.blocked : T.done;
        document.getElementById('result-body').textContent = message || '';
        document.getElementById('result').classList.remove('hidden');
        window.scrollTo(0, 0);
    }
})();
</script>
</body>
</html>

@php
    // 현장에서 처음 여는 화면이다 — 글이 많으면 아무도 안 읽는다. 문장 셋으로 끝낸다.
    $T = [
        'ko' => [
            'title' => '내 번호 정하기',
            'lead' => '휴대폰에서 쓸 번호 :n자리를 정하세요. 이 번호는 나만 압니다.',
            'again' => '한 번 더',
            'save' => '저장하고 시작하기',
            'expired' => '링크가 만료되었거나 이미 사용되었습니다.',
            'ask' => '관리자에게 새 링크를 요청하세요.',
            'mismatch' => '두 번 넣은 번호가 다릅니다.',
            'hint' => '1111, 1234 처럼 쉬운 번호는 쓸 수 없습니다.',
            'saving' => '저장 중…',
        ],
        'en' => [
            'title' => 'Set your PIN',
            'lead' => 'Choose a :n-digit PIN for this phone. Only you will know it.',
            'again' => 'Enter again',
            'save' => 'Save and start',
            'expired' => 'This link has expired or was already used.',
            'ask' => 'Ask your manager for a new link.',
            'mismatch' => 'The two PINs do not match.',
            'hint' => 'Easy PINs like 1111 or 1234 are not allowed.',
            'saving' => 'Saving…',
        ],
        'es' => [
            'title' => 'Cree su PIN',
            'lead' => 'Elija un PIN de :n dígitos para este teléfono. Solo usted lo sabrá.',
            'again' => 'Ingrese de nuevo',
            'save' => 'Guardar y comenzar',
            'expired' => 'Este enlace expiró o ya fue usado.',
            'ask' => 'Pida un enlace nuevo a su supervisor.',
            'mismatch' => 'Los dos PIN no coinciden.',
            'hint' => 'No se permiten PIN fáciles como 1111 o 1234.',
            'saving' => 'Guardando…',
        ],
    ];
    $t = $T[$lang] ?? $T['ko'];
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>{{ $t['title'] }}</title>
<style>
  :root { --navy:#22303c; --blue:#2b5d9e; --line:#d8dee4; --bg:#f4f6f8; --red:#b3372e; }
  * { box-sizing:border-box; -webkit-tap-highlight-color:transparent }
  body { margin:0; font-family:-apple-system,"Malgun Gothic","맑은 고딕",system-ui,sans-serif;
         background:var(--bg); color:#222; display:flex; justify-content:center; padding:24px 16px 40px }
  .card { width:100%; max-width:420px; background:#fff; border:1px solid var(--line);
          border-radius:14px; padding:26px 22px 30px }
  h1 { font-size:24px; margin:0 0 8px; color:var(--navy) }
  p.lead { font-size:15px; line-height:1.55; color:#444; margin:0 0 22px }
  .who { font-size:14px; color:var(--blue); font-weight:700; margin-bottom:4px }
  label { display:block; font-size:13px; font-weight:700; color:var(--navy); margin:16px 0 6px }
  input[type=tel] { width:100%; font-size:30px; letter-spacing:14px; text-align:center; padding:14px 10px;
                    border:2px solid var(--line); border-radius:10px; background:#fff; font-family:monospace }
  input[type=tel]:focus { outline:none; border-color:var(--blue) }
  button { width:100%; margin-top:22px; padding:16px; font-size:17px; font-weight:700; color:#fff;
           background:var(--blue); border:0; border-radius:10px }
  button:disabled { background:#9fb2c4 }
  .hint { font-size:12.5px; color:#5b6b7a; margin-top:12px; line-height:1.5 }
  .err { display:none; margin-top:14px; padding:11px 13px; border-radius:9px;
         background:#fdf2f1; border:1px solid #eac8c4; color:var(--red); font-size:13.5px; line-height:1.5 }
  .langs { text-align:center; margin-top:18px; font-size:13px }
  .langs a { color:#5b6b7a; text-decoration:none; margin:0 7px }
  .langs a.on { color:var(--navy); font-weight:700; text-decoration:underline }
</style>
</head>
<body>
<div class="card">
@if (! $valid)
  <h1>{{ $t['title'] }}</h1>
  <div class="err" style="display:block">{{ $t['expired'] }}<br>{{ $t['ask'] }}</div>
@else
  @if ($userName)<div class="who">{{ $userName }}</div>@endif
  <h1>{{ $t['title'] }}</h1>
  <p class="lead">{{ str_replace(':n', (string) $pinLength, $t['lead']) }}</p>

  <label for="pin1">PIN</label>
  <input id="pin1" type="tel" inputmode="numeric" maxlength="{{ $pinLength }}" autocomplete="one-time-code">

  <label for="pin2">{{ $t['again'] }}</label>
  <input id="pin2" type="tel" inputmode="numeric" maxlength="{{ $pinLength }}" autocomplete="one-time-code">

  <button id="go">{{ $t['save'] }}</button>
  <div class="hint">{{ $t['hint'] }}</div>
  <div class="err" id="err"></div>

  <div class="langs">
    @foreach (['ko' => '한국어', 'en' => 'English', 'es' => 'Español'] as $code => $name)
      <a href="?lang={{ $code }}" class="{{ $lang === $code ? 'on' : '' }}">{{ $name }}</a>
    @endforeach
  </div>
@endif
</div>

<script>
(function () {
  var b = document.getElementById('go');
  if (!b) return;
  var p1 = document.getElementById('pin1'), p2 = document.getElementById('pin2'), err = document.getElementById('err');
  var LEN = {{ $pinLength }};

  function show(msg) { err.textContent = msg; err.style.display = 'block'; }

  // 숫자만, 길이 채우면 자동으로 다음 칸 — 장갑 낀 손을 배려한다.
  [p1, p2].forEach(function (el, i) {
    el.addEventListener('input', function () {
      el.value = el.value.replace(/\D/g, '').slice(0, LEN);
      if (el.value.length === LEN && i === 0) p2.focus();
    });
  });
  p1.focus();

  b.addEventListener('click', async function () {
    err.style.display = 'none';
    if (p1.value.length !== LEN) { show('{{ str_replace(':n', (string) $pinLength, $t['lead']) }}'); return; }
    if (p1.value !== p2.value) { show('{{ $t['mismatch'] }}'); p2.value = ''; p2.focus(); return; }

    b.disabled = true; b.textContent = '{{ $t['saving'] }}';
    try {
      var res = await fetch(@json(route('pin.setup.store', ['token' => $token])), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
        body: JSON.stringify({ pin: p1.value })
      });
      var data = await res.json();
      if (!data.success) { show(data.error || 'Error'); b.disabled = false; b.textContent = '{{ $t['save'] }}'; return; }
      // 기기 토큰은 이 폰에만 남는다 — 서버는 해시만 갖는다.
      try { localStorage.setItem('erp_login_device', data.device_token); } catch (e) {}
      location.href = data.redirect || '/';
    } catch (e) {
      show('Network error'); b.disabled = false; b.textContent = '{{ $t['save'] }}';
    }
  });
})();
</script>
</body>
</html>

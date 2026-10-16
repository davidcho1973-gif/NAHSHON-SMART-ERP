@php
    $lang = $lang ?? \App\Support\WorkerLang::resolve(request()->cookie('app_locale'));
    $t = [
        'ko' => ['title' => '출퇴근은 현장 QR로', 'body' => '일반 작업자는 앱 설치 없이 현장 QR로 출퇴근합니다.', 'gate' => '출퇴근 화면 열기', 'scan' => '출입구의 현장 QR을 휴대폰 카메라로 찍어 주세요.', 'existing' => '이미 등록했다면 다시 등록하지 마세요.', 'staff' => '관리직원 · 반장 로그인'],
        'en' => ['title' => 'Use the site QR for attendance', 'body' => 'Workers clock in and out with the site QR. No app installation is needed.', 'gate' => 'Open attendance', 'scan' => 'Scan the site QR at the entrance with your phone camera.', 'existing' => 'Already registered? Do not register again.', 'staff' => 'Manager · Foreman sign in'],
        'es' => ['title' => 'Use el QR de la obra', 'body' => 'Los trabajadores registran entrada y salida con el QR de la obra, sin instalar una app.', 'gate' => 'Abrir asistencia', 'scan' => 'Escanee el QR de la entrada con la cámara del teléfono.', 'existing' => 'Si ya se registró, no vuelva a registrarse.', 'staff' => 'Acceso de gerente · Capataz'],
    ][$lang];
@endphp
<!doctype html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f3f6fa">
    <title>{{ $t['title'] }} · {{ \App\Support\Org::name() }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:24px 16px;background:#f3f6fa;color:#10233f;font-family:system-ui,-apple-system,"Malgun Gothic",sans-serif}main{max-width:440px;margin:8vh auto;background:#fff;border:1px solid #dbe4ef;border-radius:22px;padding:28px 24px}header{font-size:14px;color:#587087;margin-bottom:24px}h1{font-size:25px;line-height:1.35;word-break:keep-all}p{line-height:1.65;color:#5a6270;word-break:keep-all}.action{display:block;border-radius:13px;padding:17px;text-align:center;text-decoration:none;background:#0877bd;color:#fff;font-weight:700;margin-top:22px}.secondary{background:#edf3fa;color:#234b6f;font-size:15px}small{display:block;color:#718198;line-height:1.6;margin-top:16px}footer{border-top:1px solid #e5ebf1;margin-top:26px;padding-top:4px}
    </style>
</head>
<body>
<main>
    <header>{{ \App\Support\Org::name() }}</header>
    @include('partials.lang-switch')
    <h1>{{ $t['title'] }}</h1>
    <p>{{ $t['body'] }}</p>
    @if ($gateUrl ?? null)
        <a class="action" id="attendance-link" href="{{ $gateUrl }}">{{ $t['gate'] }}</a>
    @else
        <p>{{ $t['scan'] }}</p>
    @endif
    <small>{{ $t['existing'] }}</small>
    <footer><a class="action secondary" href="{{ $loginUrl ?? route('login', ['app' => 1]) }}">{{ $t['staff'] }}</a></footer>
</main>
</body>
</html>

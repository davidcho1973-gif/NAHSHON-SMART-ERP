@php
    $status = $status ?? 'denied';
    $connected = $connected ?? false;
    $accountSwitch = $accountSwitch ?? false;
    $ready = $status === 'ready' && ! empty($token) && ! $connected;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ __('개인앱 연결') }} · {{ \App\Support\Org::name() }}</title>
    @include('partials.field-app-theme')
    <link rel="stylesheet" href="{{ asset('css/personal-app-access.css') }}?v={{ filemtime(public_path('css/personal-app-access.css')) }}">
</head>
<body class="field-app personal-access-page">
<main class="pa-connect">
    <p class="pa-brand">{{ \App\Support\Org::name() }}</p>
    <section class="field-card">
    @if($connected)
        <h1>{{ __('연결 완료') }}</h1>
        <p>{{ $name ?? '' }}{{ __('님의 개인앱입니다.') }}</p>
        <a class="pa-primary" href="{{ route('attendance-app.index') }}">{{ __('개인앱 열기') }}</a>
    @elseif($ready)
        <h1>{{ $name ?? '' }}{{ __('님 개인앱') }}</h1>
        <p id="connect-status" role="status">{{ $accountSwitch ? __('현재 계정을 나가고 이 관리자의 개인앱에 연결합니다.') : __('이 휴대폰에 연결하고 있습니다…') }}</p>
        <form id="personal-connect-form" method="POST" action="{{ url('/app/connect/'.$token) }}" data-auto-connect="{{ $accountSwitch ? '0' : '1' }}">
            @csrf
            @if($accountSwitch)<input type="hidden" name="confirm_switch" value="1">@endif
            <button class="pa-primary" type="submit">{{ $accountSwitch ? __('계정 전환 후 연결') : __('개인앱 연결') }}</button>
        </form>
        @if($accountSwitch)<a class="pa-secondary" href="{{ route('attendance-app.index') }}">{{ __('현재 계정 유지') }}</a>@endif
        <p class="pa-muted">{{ __('연결 후에는 같은 휴대폰에서 개인앱을 바로 열 수 있습니다.') }}</p>
    @else
        <h1>{{ $status === 'expired' ? __('QR 유효시간이 지났습니다') : ($status === 'used' ? __('이미 사용된 QR입니다') : __('연결할 수 없습니다')) }}</h1>
        <p>{{ $status === 'used' ? __('이미 연결한 휴대폰이면 개인앱을 여세요. 새 휴대폰은 새 QR이 필요합니다.') : __('수퍼관리자에게 새 개인 QR을 요청하세요.') }}</p>
        <a class="pa-primary" href="{{ route('worker-app.entry') }}">{{ __('개인앱 열기') }}</a>
    @endif
    @if($errors->any())<p class="pa-error" role="alert">{{ $errors->first() }}</p>@endif
    </section>
    <a class="pa-help" href="{{ route('user-manual') }}#personal-app-qr">{{ __('사용 방법') }}</a>
</main>
@if($ready)
<script src="{{ asset('js/personal-app-connect.js') }}?v={{ filemtime(public_path('js/personal-app-connect.js')) }}" defer></script>
@endif
</body>
</html>

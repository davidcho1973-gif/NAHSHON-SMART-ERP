<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('개인앱 연결 QR') }} · {{ \App\Support\Org::name() }}</title>
    @include('partials.field-app-theme')
    <link rel="stylesheet" href="{{ asset('css/personal-app-access.css') }}?v={{ filemtime(public_path('css/personal-app-access.css')) }}">
</head>
<body class="field-app personal-access-page">
<div class="field-shell">
    <header class="field-header">
        <a class="field-back" href="{{ route('attendance-app.index') }}">{{ __('← 홈') }}</a>
        <a class="pa-help" href="{{ route('user-manual') }}#personal-app-qr">{{ __('사용 방법') }}</a>
    </header>
    <main class="field-content" id="manager-access-page"><p role="status">{{ __('불러오는 중…') }}</p></main>
    @include('partials.field-app-nav')
</div>
<script src="{{ asset('js/admin-shell.js') }}?v={{ filemtime(public_path('js/admin-shell.js')) }}" defer></script>
<script src="{{ asset('js/personal-app-access.js') }}?v={{ filemtime(public_path('js/personal-app-access.js')) }}" defer></script>
</body>
</html>

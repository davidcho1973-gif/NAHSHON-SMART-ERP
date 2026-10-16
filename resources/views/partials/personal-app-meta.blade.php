{{-- A saved app always starts at the permanent personal-app entry, never an enrollment token. --}}
<link rel="manifest" href="{{ route('worker-app.manifest') }}">
<link rel="apple-touch-icon" href="{{ asset('images/attendance-apple-touch.png') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="내 출퇴근">
<meta name="apple-mobile-web-app-status-bar-style" content="default">

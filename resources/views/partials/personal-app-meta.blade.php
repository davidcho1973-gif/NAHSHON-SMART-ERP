{{-- A saved app always starts at the permanent personal-app entry, never an enrollment token. --}}
@if (\App\Support\PersonalAppAccess::eligible(auth()->user()))
<link rel="manifest" href="{{ route('worker-app.manifest') }}">
<link rel="apple-touch-icon" href="{{ asset('images/attendance-apple-touch.png') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="업무 개인앱">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
@endif

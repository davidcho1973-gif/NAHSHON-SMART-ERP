{{-- Shared palette, typography, shell and navigation; versioned for deployed CSS updates. --}}
<meta name="theme-color" content="#F5F7FA">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
<link rel="stylesheet" href="{{ asset('css/field-app.css') }}?v={{ filemtime(public_path('css/field-app.css')) }}">

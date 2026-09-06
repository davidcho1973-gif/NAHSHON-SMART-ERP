<nav class="field-nav" aria-label="{{ __('화면 이동') }}">
    <a class="field-nav-item" href="{{ route('attendance-app.index') }}">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        {{ __('출퇴근') }}
    </a>
    <a class="field-nav-item" href="{{ route('attendance-app.index', ['tab' => 'work']) }}">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="8" width="18" height="12" rx="2"/><path d="M8 8V5h8v3M3 13h18"/></svg>
        {{ __('근무') }}
    </a>
    <a class="field-nav-item" href="{{ route('attendance-app.index', ['tab' => 'pay']) }}">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M12 3v18M17 7c-1-4-10-3-10 1 0 4 10 3 10 7 0 4-9 5-10 1"/></svg>
        {{ __('급여') }}
    </a>
    <a class="field-nav-item" href="{{ route('attendance-app.index', ['tab' => 'me']) }}">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/></svg>
        {{ __('나') }}
    </a>
</nav>

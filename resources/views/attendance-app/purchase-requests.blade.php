<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('구매신청') }}</title>
    @include('partials.field-app-theme')
    <link rel="stylesheet" href="{{ asset('css/purchase-requests.css') }}?v={{ filemtime(public_path('css/purchase-requests.css')) }}">
</head>
<body class="field-app">
<div class="field-shell">
    <header class="field-header">
        <a class="field-back" href="{{ route('attendance-app.index') }}">{{ __('← 홈') }}</a>
        @include('partials.lang-switch')
        <h1>{{ __('구매신청') }}</h1>
    </header>
    <main class="field-content pr-mobile" id="purchase-mobile">
        <section class="field-card" id="pr-new">
            <div class="pr-toolbar"><h2>{{ __('새 요청') }}</h2></div>
            <form id="pr-request-form" class="pr-intake">
                <fieldset id="pr-fields" class="pr-intake">
                    <div class="pr-pair">
                        <label>{{ __('현장') }}<select id="pr-request-site" required><option value="">{{ __('선택하세요') }}</option></select></label>
                        <label>{{ __('필요일') }}<input id="pr-need-by" type="date"></label>
                    </div>
                    <label>{{ __('무엇이 필요한가요?') }}<textarea id="pr-input" placeholder="예: 1/2인치 배관용 엘보 20개, 화장실 배관 연결용"></textarea></label>
                    <div class="pr-actions">
                        <button id="pr-file-button" type="button">{{ __('사진 · 도면') }}</button>
                        <button id="pr-record" type="button">{{ __('말로 입력') }}</button>
                        <button id="pr-link-button" type="button">{{ __('제품 링크') }}</button>
                    </div>
                    <input id="pr-source-files" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,.mp3,.m4a,.wav,.webm,.ogg" multiple hidden>
                    <label id="pr-link-field" hidden>{{ __('제품 링크') }}<input id="pr-product-link" type="url" placeholder="https://"></label>
                    <div id="pr-file-list" class="pr-help"></div>
                    <div class="pr-actions">
                        <button id="pr-analyze" type="button" class="pr-primary">{{ __('정리하기') }}</button>
                        <button id="pr-search" type="button">{{ __('제품 찾기') }}</button>
                        <button id="pr-manual" type="button">{{ __('직접 입력') }}</button>
                    </div>
                    <p id="pr-analysis-message" role="status" class="pr-status-message"></p>
                    <div id="pr-candidates" hidden></div>
                    <div id="pr-draft" hidden>
                        <h3>{{ __('요청 품목 확인') }}</h3>
                        <p id="pr-questions" class="pr-help"></p>
                        <div id="pr-edit-lines"></div>
                        <button id="pr-add-line" type="button">{{ __('+ 품목 추가') }}</button>
                        <details><summary>{{ __('메모') }}</summary><label><textarea id="pr-note" maxlength="5000"></textarea></label></details>
                        <button id="pr-submit" type="submit" class="pr-primary" style="width:100%;margin-top:18px">{{ __('요청 보내기') }}</button>
                    </div>
                </fieldset>
            </form>
            <p id="pr-save-message" role="status" class="pr-status-message"></p>
        </section>
        <section class="field-card">
            <div class="pr-toolbar"><h2>{{ __('내 요청') }}</h2><button type="button" id="pr-refresh">{{ __('새로고침') }}</button></div>
            <div id="pr-my-requests" aria-live="polite"></div>
        </section>
        <section id="pr-mobile-detail" class="field-card" hidden aria-live="polite"></section>
    </main>
    @include('partials.field-app-nav')
</div>
<script>window.purchaseRequestConfig = {userId: @json(auth()->id()), initialId: @json(request()->integer('request') ?: null)};</script>
<script src="{{ asset('js/purchase-common.js') }}?v={{ filemtime(public_path('js/purchase-common.js')) }}" defer></script>
<script src="{{ asset('js/purchase-requests-mobile.js') }}?v={{ filemtime(public_path('js/purchase-requests-mobile.js')) }}" defer></script>
</body>
</html>

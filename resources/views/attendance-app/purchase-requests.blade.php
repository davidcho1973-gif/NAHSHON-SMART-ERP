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
        <a href="{{ route('user-manual') }}#purchase-requests">{{ __('사용 방법') }}</a>
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
                    <label>{{ __('필요한 물건이나 하려는 작업을 알려주세요') }}<textarea id="pr-input" placeholder="예: 여기 배관을 연결해야 해요. 또는 레미콘 4500psi 27CY 월요일 7시 납품해주세요."></textarea></label>
                    <p class="pr-help">{{ __('제품 이름과 규격을 몰라도 괜찮아요. 사무실에서 확인해 구매합니다.') }}</p>
                    <div class="pr-actions">
                        <button id="pr-file-button" type="button">{{ __('사진 · 도면') }}</button>
                        <button id="pr-record" type="button">{{ __('말로 입력') }}</button>
                        <button id="pr-link-button" type="button">{{ __('제품 링크') }}</button>
                    </div>
                    <input id="pr-source-files" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,.mp3,.m4a,.wav,.webm,.ogg" multiple hidden>
                    <label id="pr-link-field" hidden>{{ __('제품 링크') }}<input id="pr-product-link" type="url" placeholder="https://"></label>
                    <div id="pr-image-editor" hidden></div>
                    <div id="pr-file-list" class="pr-help"></div>
                    <div class="pr-actions">
                        <button id="pr-analyze" type="button" class="pr-primary">{{ __('정리하기') }}</button>
                        <button id="pr-search" type="button">{{ __('제품을 직접 고르기 · 선택') }}</button>
                        <button id="pr-manual" type="button">{{ __('직접 입력') }}</button>
                    </div>
                    <p id="pr-analysis-message" role="status" class="pr-status-message"></p>
                    <div id="pr-candidates" hidden></div>
                    <div id="pr-draft" hidden>
                        <h3>{{ __('요청 내용 확인') }}</h3>
                        <div id="pr-questions" class="pr-help"></div><div id="pr-conversation" hidden><label>답변 · 추가 설명<textarea id="pr-answer" placeholder="모르겠어요라고 답해도 됩니다."></textarea></label><button id="pr-answer-send" type="button">답변 반영하기</button><button id="pr-defer" type="button">사무실에서 확인해주세요</button></div>
                        <p class="pr-help">수량·단위를 모르면 비워두세요. 확인된 내용만 보내면 됩니다.</p><div id="pr-edit-lines"></div>
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
<script src="{{ asset('js/purchase-image-marker.js') }}?v={{ filemtime(public_path('js/purchase-image-marker.js')) }}" defer></script>
<script src="{{ asset('js/purchase-requests-mobile.js') }}?v={{ filemtime(public_path('js/purchase-requests-mobile.js')) }}" defer></script>
</body>
</html>

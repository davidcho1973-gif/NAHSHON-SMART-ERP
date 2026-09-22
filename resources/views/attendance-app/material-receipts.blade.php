<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('자재 입고') }}</title>
    @include('partials.field-app-theme')
    <style>
        .receiving-grid { display: grid; gap: 24px; align-items: start; }
        .receiving-grid h2 { margin: 0 0 14px; font-size: 18px; }
        .receiving-grid fieldset { margin: 0; padding: 0; border: 0; min-width: 0; }
        .receiving-grid label { display: grid; gap: 6px; margin-bottom: 14px; font-size: 13px; font-weight: 600; }
        .receiving-grid input, .receiving-grid select, .receiving-grid textarea { width: 100%; min-height: 46px; padding: 10px; border: 1px solid var(--rule); border-radius: 10px; font-size: 16px; background: var(--card); color: var(--ink); }
        .receiving-grid textarea { min-height: 76px; resize: vertical; }
        .receiving-grid button { min-height: 46px; padding: 10px 14px; border: 1px solid var(--rule); border-radius: 12px; background: var(--accent-bg); color: var(--accent); font-size: 14px; font-weight: 700; cursor: pointer; }
        .receiving-grid button:disabled { opacity: .5; cursor: wait; }
        .receiving-grid .primary { width: 100%; background: var(--accent); color: white; margin-top: 16px; }
        .receiving-grid .pair { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .receiving-grid .check { display: flex; align-items: center; gap: 10px; margin-top: 14px; }
        .receiving-grid .check input { width: 20px; min-height: 20px; margin: 0; }
        .receiving-grid .evidence { margin: 16px 0; padding: 16px; border: 1px dashed var(--ink-3); border-radius: 14px; }
        .receiving-grid .evidence img { display: block; max-width: 100%; max-height: 200px; border-radius: 10px; margin-top: 12px; }
        .receiving-grid .line { border-top: 1px solid var(--rule); padding-top: 14px; margin-top: 14px; }
        .receiving-grid .line-head, .receiving-grid .history-head { display: flex; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .receiving-grid .line-head button { min-height: 40px; padding: 5px 12px; }
        .receiving-grid .message { white-space: pre-line; overflow-wrap: anywhere; font-size: 14px; line-height: 1.65; }
        .receiving-grid .message.error { color: var(--bad); }
        .receiving-grid .message.success { color: var(--ok); }
        .receiving-grid .receipt { border-top: 1px solid var(--rule); padding: 16px 0; overflow-wrap: anywhere; }
        .receiving-grid .receipt:first-child { border-top: 0; }
        .receiving-grid .receipt h3 { font-size: 15px; margin: 0; }
        .receiving-grid .receipt ul { padding-left: 20px; margin: 8px 0; font-size: 14px; }
        .receiving-grid .badge { display: inline-block; border-radius: 20px; padding: 3px 9px; font-size: 12px; background: var(--warn-bg); color: var(--warn); }
        .receiving-grid .badge.confirmed { background: var(--ok-bg); color: var(--ok); }
        .receiving-grid .links { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-top: 10px; }
        .receiving-grid a { color: var(--accent); overflow-wrap: anywhere; }
        @media(min-width: 820px) { .receiving-grid { grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); } }
        @media(max-width: 420px) { .receiving-grid .field-card { padding: 16px; } .field-content { padding-left: 14px; padding-right: 14px; } }
    </style>
</head>
<body class="field-app field-material-receipts">
@include('partials.erp-home')
<div class="field-shell">
    <header class="field-header">
        <a class="field-back" href="{{ route('attendance-app.index') }}">{{ __('← 홈') }}</a>
        <h1>{{ __('자재 입고') }}</h1>
        <p class="field-subtle">{{ __('사진이나 파일을 첨부하고 실제 입고 수량을 확인해 주세요.') }}</p>
    </header>
    <main class="field-content receiving-grid">
        <section class="field-card">
            <h2 id="form-title">{{ __('새 입고 등록') }}</h2>
            @if(count($sites) === 0)
                <p role="alert">{{ __('입고 가능한 현장이 없습니다. 관리자에게 현장 배정을 요청하세요.') }}</p>
            @endif
            <form id="receipt-form">
                <fieldset id="receipt-fields" @disabled(count($sites) === 0)>
                    <label>{{ __('현장') }}
                        <select id="receipt-site" required>
                            <option value="">{{ __('현장을 선택하세요') }}</option>
                            @foreach($sites as $site)
                                <option value="{{ $site['value'] }}" @selected((string) $initialSiteId === (string) $site['value'])>{{ $site['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ __('입고일') }}<input id="received-on" type="date" value="{{ $receivedOn }}" required></label>
                    <div class="evidence">
                        <div class="pair">
                            <button type="button" id="take-photo">{{ __('사진 촬영') }}</button>
                            <button type="button" id="choose-file">{{ __('사진 · 파일 선택') }}</button>
                        </div>
                        <input id="camera-file" type="file" accept="image/*" capture="environment" hidden>
                        <input id="proof-file" type="file" accept=".jpg,.jpeg,.png,.webp,.heic,.heif,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" hidden>
                        <label class="check"><input type="checkbox" id="use-ai" checked>{{ __('사진·PDF의 품목과 수량 자동 읽기') }}</label>
                        <p class="field-subtle">{{ __('한 건에 증빙 파일 1개 · 최대 32MB. 여러 장은 PDF로 묶어 첨부하세요.') }}</p>
                        <div id="proof-name" class="field-subtle"></div>
                        <img id="proof-preview" alt="{{ __('선택한 입고 사진') }}" hidden>
                        <p id="upload-message" class="message" role="status" aria-live="polite"></p>
                        <button type="button" id="retry-upload" hidden>{{ __('첨부 다시 시도') }}</button>
                        <button type="button" id="remove-upload" hidden>{{ __('선택한 첨부 취소') }}</button>
                    </div>
                    <label>{{ __('납품업체') }}<input id="vendor" maxlength="160" autocomplete="organization"></label>
                    <div class="pair">
                        <label>{{ __('납품서 번호') }}<input id="delivery-no" maxlength="80"></label>
                        <label>{{ __('발주 번호') }}<input id="po-no" maxlength="80"></label>
                    </div>
                    <h2>{{ __('실제 입고 품목') }}</h2>
                    <p class="field-subtle">{{ __('자동 판독 결과는 초안입니다. 포장 단위와 실제 받은 수량을 확인하세요.') }}</p>
                    <div id="receipt-lines"></div>
                    <button type="button" id="add-line">{{ __('+ 품목 추가') }}</button>
                    <label style="margin-top:16px">{{ __('메모') }}<textarea id="receipt-note" maxlength="5000"></textarea></label>
                    <button class="primary" type="submit" id="save-receipt">{{ __('입고 기록 저장') }}</button>
                    <button type="button" id="new-receipt" style="margin-top:12px" hidden>{{ __('새 입고 작성') }}</button>
                </fieldset>
            </form>
            <p id="save-message" class="message" role="status" aria-live="polite"></p>
            <div id="saved-actions" class="links"></div>
        </section>
        <section class="field-card" aria-label="{{ __('최근 입고 기록') }}">
            <div class="history-head"><h2>{{ __('최근 입고 기록') }}</h2><button type="button" id="refresh-receipts">{{ __('새로고침') }}</button></div>
            <p class="field-subtle">{{ __('저장 후 품목·수량을 검토하고 입고를 확정하세요. ERP 자재 입고 대장에도 함께 표시됩니다.') }}</p>
            <div id="receipt-history" aria-live="polite"></div>
        </section>
    </main>
    @include('partials.field-app-nav')
</div>
<script>
    window.materialReceivingConfig = {
        baseUrl: @json(route('attendance-app.material-receipts')),
        today: @json($receivedOn),
        hasSites: @json(count($sites) > 0),
        translations: @json(\App\Support\AppLocale::dictionary()),
    };
</script>
<script src="{{ asset('js/material-receiving-mobile.js') }}?v={{ filemtime(public_path('js/material-receiving-mobile.js')) }}" defer></script>
</body>
</html>

<p class="hint" id="assistant-budget">
    @if (isset($budget['requests']['user_day']))
        {{ __('오늘 AI 질문 잔여') }}: {{ $budget['requests']['user_day']['remaining'] }}/{{ $budget['requests']['user_day']['limit'] }} · {{ __('UTC 기준') }}
    @else
        {{ $budget['message'] ?? '' }}
    @endif
</p>
@if ($workspaceReady)
<section id="assistant-workspace" class="askbox" style="margin-top:18px"
    data-base="{{ url('/ask-api/workspace') }}" data-options="{{ json_encode($workspace, JSON_UNESCAPED_UNICODE) }}">
    <h2 style="font-size:17px;margin:0 0 10px">{{ __('ERP 도우미') }}</h2>
    <p class="hint">{{ __('현재 로그인 권한으로 조회합니다. 보고서는 나만 보고, 승인한 현장 할 일은 기존 상황실에 반영됩니다.') }}</p>
    <div class="assistant-fields">
        <label>{{ __('회사') }}<select id="assistant-company"></select></label>
        <label>{{ __('현장') }}<select id="assistant-site"></select></label>
    </div>
    <p id="assistant-status" class="hint" role="status" aria-live="polite"></p>
    <details>
        <summary>{{ __('보고서·엑셀') }}</summary>
        <div class="assistant-fields">
            <label>{{ __('자료 종류') }}<select id="assistant-dataset"></select></label>
            <label>{{ __('검색어') }}<input id="assistant-search" maxlength="150" type="search"></label>
        </div>
        <div class="chips">
            <button type="button" data-action="report">{{ __('조회') }}</button>
            <a id="assistant-export" href="#">{{ __('엑셀 다운로드') }}</a>
        </div>
        <p class="hint">{{ __('조회 100건, 엑셀 최대 1,000건. 전체 집계 여부와 자료 기준시각을 확인하세요.') }}</p>
        <div id="assistant-report"></div>
        <button type="button" data-action="next" hidden>{{ __('다음 100건') }}</button>
    </details>
    <details id="assistant-change-panel">
        <summary>{{ __('변경 미리보기') }}</summary>
        <p class="hint">{{ __('지원 작업: 할 일, 일일 계획·보고 초안, 검토 대기 경비, 기술 문서 분류. 지급·급여 승인·계정 권한 변경은 지원하지 않습니다.') }}</p>
        <p id="assistant-mutations-off" class="hint warn" hidden>{{ __('변경 기능은 서버에서 아직 활성화되지 않았습니다.') }}</p>
        <fieldset id="assistant-write-fields" disabled class="assistant-fields">
            <label>{{ __('작업') }}<select id="assistant-operation"><option value="ops.todo.create">{{ __('할 일 등록') }}</option><option value="ops.todo.update">{{ __('할 일 수정') }}</option><option value="daily_plan.draft.update">{{ __('일일 계획 초안 수정') }}</option><option value="daily_report.draft.create">{{ __('일일 보고 초안 등록') }}</option><option value="expense.pending.create">{{ __('검토 대기 경비 등록') }}</option><option value="document.category.update">{{ __('기술 문서 분류 수정') }}</option></select></label>
            <label data-operations="ops.todo.update daily_plan.draft.update document.category.update">{{ __('수정할 기록 ID') }}<input id="assistant-record" type="number" min="1"></label>
            <label>{{ __('입력 내용 제안 요청') }}<textarea id="assistant-request-text" maxlength="2000" rows="3" aria-describedby="assistant-suggestion-help"></textarea></label>
            <p id="assistant-suggestion-help" class="hint">{{ __('직접 쓴 요청만 AI에 보냅니다. 영수증·문서를 읽거나 OCR하지 않습니다. USD 총액과 YYYY-MM-DD 날짜를 명시하세요. 제안은 빈 항목만 채우며 저장에는 별도 미리보기와 승인이 필요합니다.') }}</p>
            <p id="assistant-suggestions-off" class="hint">{{ __('AI 제안을 사용할 수 없습니다. 아래 항목을 직접 입력할 수 있습니다.') }}</p>
            <button type="button" data-action="suggest" disabled>{{ __('입력 내용 제안') }}</button>
            <div id="assistant-suggestion-result" class="hint" role="status" aria-live="polite"></div>
            <p class="hint warn" data-operations="ops.todo.update daily_plan.draft.update">{{ __('수정 작업은 입력 내용으로 기존 값을 교체합니다. 빈 항목이 유지된다고 가정하지 말고 미리보기에서 지워지는 값도 확인하세요.') }}</p>
            <label data-operations="ops.todo.create ops.todo.update daily_plan.draft.update daily_report.draft.create">{{ __('제목 / 계획 작업 내용') }}<textarea id="assistant-title" maxlength="8000" rows="2"></textarea></label>
            <label data-operations="ops.todo.create ops.todo.update daily_plan.draft.update">{{ __('내용') }}<textarea id="assistant-detail" maxlength="4000" rows="2"></textarea></label>
            <label data-operations="ops.todo.create ops.todo.update">{{ __('기한') }}<input id="assistant-due" type="date"></label>
            <label data-operations="daily_report.draft.create">{{ __('보고 날짜') }}<input id="assistant-report-date" type="date"></label>
            <label data-operations="daily_report.draft.create">{{ __('오늘 작업') }}<textarea id="assistant-work-today" maxlength="8000" rows="3"></textarea></label>
            <label data-operations="daily_report.draft.create">{{ __('내일 작업') }}<textarea id="assistant-work-tomorrow" maxlength="8000" rows="3"></textarea></label>
            <label data-operations="expense.pending.create">{{ __('경비 설명') }}<textarea id="assistant-expense-description" maxlength="4000" rows="3"></textarea></label>
            <label data-operations="expense.pending.create">{{ __('총액 (USD · 세금 포함)') }}<input id="assistant-amount" inputmode="decimal" type="text" placeholder="123.45"></label>
            <label data-operations="expense.pending.create">{{ __('경비 날짜') }}<input id="assistant-expense-date" type="date"></label>
            <label data-operations="expense.pending.create">{{ __('계정과목') }}<select id="assistant-account"></select></label>
            <label data-operations="expense.pending.create">{{ __('결제 구분') }}<select id="assistant-payment"><option value="">{{ __('선택하세요') }}</option><option value="corporate">{{ __('회사 결제') }}</option><option value="personal">{{ __('개인 결제 · 환급 검토') }}</option></select></label>
            <label data-operations="expense.pending.create">{{ __('기존 영수증 문서 ID (선택)') }}<input id="assistant-source-document" type="number" min="1"></label>
            <p class="hint warn" data-operations="expense.pending.create">{{ __('입력한 USD 총액 그대로 검토 대기로 등록합니다. 세금을 자동 추가하지 않습니다. 본인이 올린 동일 현장의 공유 영수증만 연결하며, OCR·지급·급여 반영은 실행하지 않습니다.') }}</p>
            <label data-operations="document.category.update">{{ __('문서 분류') }}<select id="assistant-category"></select></label>
            <p class="hint" data-operations="document.category.update">{{ __('기술 문서의 분류만 바꿉니다. 문서 유형·공개 범위·소유자·회사·현장은 변경하지 않습니다.') }}</p>
            <button type="button" data-action="propose">{{ __('변경안 만들기') }}</button>
        </fieldset>
        <div id="assistant-preview" hidden>
            <p class="hint warn">{{ __('아래 회사·현장·작업·금액·자료 공개 범위를 확인하세요. 승인한 내용은 해당 ERP 대장에 기록됩니다.') }}</p>
            <div id="assistant-preview-body"></div>
            <label><input id="assistant-approve" type="checkbox"> {{ __('변경 내용을 확인했고 ERP 반영을 승인합니다.') }}</label>
            <div class="chips"><button type="button" data-action="confirm" disabled>{{ __('승인 후 반영') }}</button><button type="button" data-action="recover">{{ __('처리 상태 확인') }}</button><button type="button" data-action="cancel">{{ __('취소') }}</button></div>
        </div>
    </details>
    <details>
        <summary>{{ __('정기 확인') }}</summary>
        <p class="hint">{{ __('저장 시 꺼짐 상태입니다. 서버 활성화와 시작 승인이 있어야 실행됩니다. 결과는 여기서만 확인하며 이메일·푸시는 발송하지 않습니다.') }}</p>
        <div class="assistant-fields">
            <label>{{ __('확인 항목') }}<select id="assistant-check-kind">@foreach (\App\Services\Assistant\AssistantCheckService::KINDS as $kind => $label)<option value="{{ $kind }}" data-description="{{ __(\App\Services\Assistant\AssistantCheckService::DESCRIPTIONS[$kind] ?? '') }}">{{ __($label) }}</option>@endforeach</select></label>
            <label>{{ __('간격') }}<select id="assistant-check-interval"><option value="24">24h</option><option value="6">6h</option><option value="1">1h</option></select></label>
            <button type="button" data-action="save-check">{{ __('꺼짐 상태로 저장') }}</button>
        </div>
        <p id="assistant-check-meaning" class="hint"></p>
        <div id="assistant-checks"></div>
    </details>
</section>
<style>
#assistant-workspace details { margin-top:14px; border-top:1px solid var(--rule); padding-top:12px; }
#assistant-workspace summary { font-weight:800; cursor:pointer; }
.assistant-fields { display:grid; gap:10px; margin:12px 0; border:0; padding:0; }
.assistant-fields label { display:grid; gap:5px; font-size:13px; }
.assistant-fields input,.assistant-fields select { width:100%; padding:10px; border:1px solid var(--rule); border-radius:8px; font:inherit; background:var(--card); color:var(--ink); }
.assistant-fields textarea { border:1px solid var(--rule); border-radius:8px; }
#assistant-workspace button { min-height:40px; cursor:pointer; }
#assistant-workspace button:disabled { opacity:.5; cursor:default; }
#assistant-export { color:var(--info); padding:8px; }
.assistant-table { overflow:auto; max-height:420px; margin-top:10px; }
.assistant-table table { border-collapse:collapse; font-size:12px; }
.assistant-table td,.assistant-table th { border:1px solid var(--rule); padding:6px; max-width:260px; min-width:80px; white-space:pre-wrap; overflow-wrap:anywhere; }
.assistant-preview-value { white-space:pre-wrap; overflow-wrap:anywhere; font-size:13px; margin:8px 0; }
</style>
<script src="{{ asset('js/assistant-workspace.js') }}?v={{ @filemtime(public_path('js/assistant-workspace.js')) }}" defer></script>
@endif

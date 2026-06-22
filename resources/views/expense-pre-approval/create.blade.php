<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>예산 품의서 작성 - SMART COMPANY</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <link rel="stylesheet" href="{{ asset('css/smart-company.css') }}">
  <style>
    body {
      background-color: var(--bg-base);
      color: var(--text-primary);
      font-family: var(--font-base);
      overflow-y: auto;
    }
    .mobile-container {
      max-width: 480px;
      margin: 0 auto;
      padding: 16px;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      gap: 16px;
      padding-bottom: 24px;
    }
    .mobile-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
    }
    .mobile-title {
      font-size: 18px;
      font-weight: 700;
      letter-spacing: -0.5px;
    }
    .btn-back {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      color: var(--text-secondary);
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      text-decoration: none;
    }
    .form-section {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 18px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .section-title {
      font-size: 13px;
      font-weight: 700;
      color: var(--brand-primary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border-bottom: 1px solid var(--border-subtle);
      padding-bottom: 6px;
    }
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .form-label {
      font-size: 11px;
      font-weight: 700;
      color: var(--text-tertiary);
      text-transform: uppercase;
    }
    .input-text {
      background: var(--bg-base);
      border: 1px solid var(--border-subtle);
      color: var(--text-primary);
      padding: 14px;
      border-radius: var(--radius-md);
      font-size: 13px;
      outline: none;
      font-family: inherit;
    }
    .input-text:focus {
      border-color: var(--brand-primary);
    }
    .action-row {
      display: flex;
      gap: 8px;
      margin-top: 8px;
    }
    .btn-draft {
      flex: 1;
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      color: var(--text-primary);
      padding: 14px;
      border-radius: var(--radius-lg);
      font-weight: 600;
      font-size: 13px;
    }
    .btn-submit {
      flex: 2;
      background: var(--brand-primary);
      color: white;
      padding: 14px;
      border-radius: var(--radius-lg);
      font-weight: 700;
      font-size: 13px;
    }
  </style>
</head>
<body>
  <div class="mobile-container">
    
    <div class="mobile-header">
      <a href="{{ route('expense-pre-approval.index') }}" class="btn-back">
        <i class="ph ph-caret-left"></i>
      </a>
      <h1 class="mobile-title">새 예산 승인 작성</h1>
      <div style="width: 36px"></div>
    </div>

    @if ($errors->any())
      <div style="background: var(--status-danger-dim); border: 1px solid var(--status-danger); padding: 12px; border-radius: var(--radius-md); font-size: 12px; color: var(--status-danger);">
        <ul style="list-style: disc; padding-left: 16px;">
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <form action="{{ route('expense-pre-approval.store') }}" method="POST" style="display:flex; flex-direction:column; gap:16px;">
      @csrf
      
      <input type="hidden" name="action" id="formAction" value="save">

      <!-- Request Details Section -->
      <div class="form-section">
        <span class="section-title">요청 기본 정보</span>
        
        <div class="form-group">
          <label class="form-label" for="titleInput">품의서 제목 (Title)</label>
          <input type="text" name="title" id="titleInput" class="input-text" placeholder="예: 현장 숙소 자재 구매 승인의 건" required value="{{ old('title') }}">
        </div>

        <div class="form-group">
          <label class="form-label" for="descInput">상세 계획 (Description)</label>
          <textarea name="description" id="descInput" class="input-text" style="height: 100px; resize:none" placeholder="비용 지출의 상세 계획을 작성해 주세요.">{{ old('description') }}</textarea>
        </div>

        <div class="form-group">
          <label class="form-label" for="justInput">지출 사유 및 타당성 (Justification)</label>
          <textarea name="justification" id="justInput" class="input-text" style="height: 100px; resize:none" placeholder="이 지출이 왜 필요한지 타당성을 설명해 주세요." required>{{ old('justification') }}</textarea>
        </div>
      </div>

      <!-- Budget & Settings Section -->
      <div class="form-section">
        <span class="section-title">예산 및 결제 설정</span>

        <div class="form-group">
          <label class="form-label" for="amountInput">예상 총 금액 (Estimated Amount)</label>
          <input type="number" step="0.01" min="0.01" name="estimated_amount" id="amountInput" class="input-text" placeholder="0.00" required value="{{ old('estimated_amount') }}">
        </div>

        <div class="form-group">
          <label class="form-label" for="dateInput">지출 예정 일자 (Planned Date)</label>
          <input type="date" name="planned_date" id="dateInput" class="input-text" style="background-color: var(--bg-base);" required value="{{ old('planned_date') ?: date('Y-m-d') }}">
        </div>

        <div class="form-group">
          <label class="form-label" for="methodSelect">예정 결제 수단</label>
          <select name="payment_method" id="methodSelect" class="input-text" style="background-color: var(--bg-base);">
            <option value="personal" {{ old('payment_method') === 'personal' ? 'selected' : '' }}>개인 비용 (환급 대상)</option>
            <option value="corporate" {{ old('payment_method') === 'corporate' ? 'selected' : '' }}>회사 법인카드</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="siteSelect">귀속될 현장 코드 (Site)</label>
          <select name="site_id" id="siteSelect" class="input-text" style="background-color: var(--bg-base);">
            <option value="">지정 안함 (Global / Office)</option>
            @foreach($sites as $site)
              <option value="{{ $site->id }}" {{ old('site_id') == $site->id ? 'selected' : '' }}>{{ $site->code }} - {{ $site->name }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="action-row">
        <button type="button" class="btn-draft" onclick="submitForm('draft')">임시 저장</button>
        <button type="button" class="btn-submit" onclick="submitForm('save')">결재 올리기</button>
      </div>

    </form>
  </div>

  <script>
    function submitForm(action) {
      document.getElementById('formAction').value = action;
      
      // Simple validation for required fields
      if (action === 'save') {
        const title = document.getElementById('titleInput').value.trim();
        const justification = document.getElementById('justInput').value.trim();
        const amount = parseFloat(document.getElementById('amountInput').value) || 0;

        if (title === '' || justification === '' || amount <= 0) {
          alert('제목, 지출 사유, 예상 금액을 올바르게 입력해 주세요.');
          return;
        }
      }

      document.querySelector('form').submit();
    }
  </script>
</body>
</html>

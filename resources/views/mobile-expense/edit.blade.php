<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>영수증 처리 수정 - SMART COMPANY</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <link rel="stylesheet" href="{{ asset('css/smart-company.css') }}">
  <style>
    body { background: var(--bg-base); color: var(--text-primary); font-family: var(--font-base); }
    .mobile-container { max-width: 480px; margin: 0 auto; padding: 16px 16px 92px; min-height: 100vh; display: flex; flex-direction: column; gap: 16px; }
    .mobile-header { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; }
    .mobile-title { font-size: 20px; font-weight: 700; }
    .btn-back { background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle); color: var(--text-secondary); width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; }
    .form-card { background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 16px; display: flex; flex-direction: column; gap: 14px; }
    .field { display: flex; flex-direction: column; gap: 6px; }
    .field label { font-size: 12px; font-weight: 700; color: var(--text-secondary); }
    .field input, .field select, .field textarea { width: 100%; background: var(--bg-base); border: 1px solid var(--border-subtle); color: var(--text-primary); border-radius: var(--radius-md); padding: 11px 12px; font: inherit; }
    .field textarea { min-height: 92px; resize: vertical; }
    .receipt-preview { width: 100%; max-height: 260px; object-fit: contain; border: 1px solid var(--border-subtle); border-radius: var(--radius-md); background: var(--bg-base); }
    .hint { font-size: 11px; color: var(--text-tertiary); line-height: 1.5; }
    .btn-save { width: 100%; border: 0; border-radius: var(--radius-lg); padding: 14px; background: linear-gradient(135deg, var(--brand-primary), #1d4ed8); color: #fff; font-size: 14px; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .alert { border: 1px solid var(--status-danger); color: var(--status-danger); background: var(--status-danger-dim); border-radius: var(--radius-md); padding: 10px 12px; font-size: 12px; }
  </style>
</head>
<body>
  <div class="mobile-container">
    <div class="mobile-header">
      <a href="{{ route('mobile-expense.index') }}" class="btn-back"><i class="ph ph-caret-left"></i></a>
      <h1 class="mobile-title">영수증 처리 수정</h1>
      <div style="width:36px"></div>
    </div>

    @if ($errors->any())
      <div class="alert">{{ $errors->first() }}</div>
    @endif

    <form class="form-card" action="{{ route('mobile-expense.update', $expense) }}" method="POST" enctype="multipart/form-data">
      @csrf
      @method('PUT')

      <div class="field">
        <label for="payment_type">결제 방법</label>
        <select id="payment_type" name="payment_type" required>
          <option value="personal" @selected(old('payment_type', $expense->payment_type) === 'personal')>개인 지출</option>
          <option value="corporate" @selected(old('payment_type', $expense->payment_type) === 'corporate')>회사 법인카드</option>
        </select>
      </div>

      <div class="field">
        <label for="category">카테고리</label>
        <input id="category" name="category" value="{{ old('category', $expense->category) }}" required maxlength="80">
      </div>

      <div class="field">
        <label for="class">부서 / 클래스</label>
        <input id="class" name="class" value="{{ old('class', $expense->class) }}" maxlength="80">
      </div>

      <div class="field">
        <label for="amount">금액</label>
        <input id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount', $expense->amount) }}" required>
      </div>

      <div class="field">
        <label for="expense_date">지출 날짜</label>
        <input id="expense_date" name="expense_date" type="date" value="{{ old('expense_date', optional($expense->expense_date)->toDateString()) }}" required>
      </div>

      <div class="field">
        <label for="site_id">현장</label>
        <select id="site_id" name="site_id">
          <option value="">현재 현장 유지</option>
          @foreach ($sites as $site)
            <option value="{{ $site->id }}" @selected((string) old('site_id', $expense->site_id) === (string) $site->id)>{{ $site->code }} - {{ $site->name }}</option>
          @endforeach
        </select>
      </div>

      <div class="field">
        <label for="expense_pre_approval_id">승인된 사전 예산과 연결</label>
        <select id="expense_pre_approval_id" name="expense_pre_approval_id">
          <option value="">연결 안함</option>
          @foreach ($preApprovals as $preApproval)
            <option value="{{ $preApproval->id }}" @selected((string) old('expense_pre_approval_id', $expense->expense_pre_approval_id) === (string) $preApproval->id)>
              {{ $preApproval->title }} - ${{ number_format($preApproval->estimated_amount, 2) }}
            </option>
          @endforeach
        </select>
      </div>

      @if ($canManageAllExpenses)
        <div class="field">
          <label for="status">상태</label>
          <select id="status" name="status">
            @foreach (['draft' => 'Draft', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'paid' => 'Paid'] as $value => $label)
              <option value="{{ $value }}" @selected(old('status', $expense->status) === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      @endif

      <div class="field">
        <label for="description">내역 / 설명</label>
        <textarea id="description" name="description" required>{{ old('description', $expense->description) }}</textarea>
      </div>

      @if ($expense->receipt_path || $expense->receipt_file)
        <div class="field">
          <label>현재 영수증</label>
          <img class="receipt-preview" src="{{ route('mobile-expense.receipt', $expense) }}" alt="영수증 미리보기">
        </div>
      @endif

      <div class="field">
        <label for="receipt">영수증 이미지 교체</label>
        <input id="receipt" name="receipt" type="file" accept="image/*">
        <span class="hint">이미지가 깨진 기존 항목은 여기서 원본 영수증을 다시 업로드하면 복구됩니다.</span>
      </div>

      <button type="submit" class="btn-save">
        <i class="ph ph-check-circle"></i>
        <span>수정 저장</span>
      </button>
    </form>
  </div>
</body>
</html>

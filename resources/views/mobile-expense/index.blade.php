<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>내 경비 목록 - SMART COMPANY</title>
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
      padding-bottom: 90px;
    }
    .mobile-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
    }
    .mobile-title {
      font-size: 20px;
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
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
    }
    .kpi-box {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .kpi-title {
      font-size: 10px;
      font-weight: 600;
      color: var(--text-tertiary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .kpi-num {
      font-size: 16px;
      font-weight: 700;
      font-family: var(--font-mono);
    }
    .kpi-num.highlight {
      color: var(--brand-primary);
    }
    .kpi-num.success {
      color: var(--status-success);
    }
    .tab-row {
      display: flex;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      padding: 4px;
      border-radius: var(--radius-lg);
      gap: 4px;
    }
    .tab-btn {
      flex: 1;
      padding: 8px;
      text-align: center;
      font-size: 12px;
      font-weight: 600;
      color: var(--text-secondary);
      background: transparent;
      border-radius: var(--radius-md);
      transition: all 0.2s ease;
    }
    .tab-btn.active {
      background: var(--bg-surface-elevated);
      color: var(--text-primary);
      border: 1px solid var(--border-subtle);
    }
    .expense-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .expense-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      cursor: pointer;
      transition: border-color 0.2s ease;
    }
    .expense-card:hover {
      border-color: var(--brand-primary);
    }
    .card-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .card-category {
      font-size: 11px;
      font-weight: 700;
      color: var(--text-tertiary);
      background: var(--bg-surface-elevated);
      padding: 2px 6px;
      border-radius: 4px;
    }
    .card-amount {
      font-size: 15px;
      font-weight: 700;
      font-family: var(--font-mono);
    }
    .card-mid {
      font-size: 13px;
      color: var(--text-primary);
      font-weight: 500;
    }
    .card-bottom {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 11px;
      color: var(--text-tertiary);
    }
    .card-date {
      font-family: var(--font-mono);
    }
    .status-badge {
      padding: 2px 8px;
      border-radius: 12px;
      font-weight: 600;
      font-size: 10px;
    }
    .status-badge.pending {
      background: var(--status-warning-dim);
      color: var(--status-warning);
    }
    .status-badge.approved {
      background: var(--status-success-dim);
      color: var(--status-success);
    }
    .status-badge.rejected {
      background: var(--status-danger-dim);
      color: var(--status-danger);
    }
    .btn-create {
      position: fixed;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%);
      width: calc(100% - 32px);
      max-width: 448px;
      background: linear-gradient(135deg, var(--brand-primary), #1d4ed8);
      color: white;
      padding: 14px;
      border-radius: var(--radius-lg);
      font-size: 14px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 20px rgba(37, 99, 235, 0.4);
      text-decoration: none;
      z-index: 100;
    }
    .empty-state {
      text-align: center;
      padding: 48px 16px;
      color: var(--text-tertiary);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }
    .empty-icon {
      font-size: 48px;
    }
    /* Modal styles */
    .modal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.75);
      z-index: 1000;
      display: none;
      align-items: flex-end;
      justify-content: center;
    }
    .modal-content {
      width: 100%;
      max-width: 480px;
      background: var(--bg-surface);
      border-top: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg) var(--radius-lg) 0 0;
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      max-height: 85vh;
      overflow-y: auto;
    }
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid var(--border-subtle);
      padding-bottom: 12px;
    }
    .modal-title {
      font-size: 16px;
      font-weight: 700;
    }
    .modal-close {
      background: transparent;
      color: var(--text-secondary);
      font-size: 20px;
    }
    .detail-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .detail-item {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .detail-label {
      font-size: 10px;
      font-weight: 600;
      color: var(--text-tertiary);
      text-transform: uppercase;
    }
    .detail-value {
      font-size: 13px;
      color: var(--text-primary);
      font-weight: 500;
    }
    .receipt-preview {
      width: 100%;
      border-radius: var(--radius-md);
      border: 1px solid var(--border-subtle);
      max-height: 250px;
      object-fit: contain;
      background: var(--bg-base);
    }
    .modal-actions {
      display: flex;
      gap: 8px;
      border-top: 1px solid var(--border-subtle);
      padding-top: 12px;
    }
    .btn-edit,
    .btn-delete {
      flex: 1;
      border-radius: var(--radius-md);
      padding: 10px 12px;
      font-size: 13px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      text-decoration: none;
      cursor: pointer;
    }
    .btn-edit {
      border: 1px solid var(--brand-primary);
      color: var(--brand-primary);
      background: rgba(37, 99, 235, 0.1);
    }
    .btn-delete {
      border: 1px solid var(--status-danger);
      color: var(--status-danger);
      background: var(--status-danger-dim);
    }
  </style>
</head>
<body>
  <div class="mobile-container">
    
    <div class="mobile-header">
      <a href="/" class="btn-back">
        <i class="ph ph-caret-left"></i>
      </a>
      <h1 class="mobile-title">내 경비 지출 목록</h1>
      <div style="width: 36px"></div>
    </div>

    <!-- KPI Summary Grid -->
    <div class="kpi-grid">
      <div class="kpi-box">
        <span class="kpi-title">이번달 승인</span>
        <span class="kpi-num highlight">${{ number_format($approvedMtd, 2) }}</span>
      </div>
      <div class="kpi-box">
        <span class="kpi-title">승인대기</span>
        <span class="kpi-num">${{ $pendingCount }}</span>
      </div>
      <div class="kpi-box">
        <span class="kpi-title">개인환급 완료</span>
        <span class="kpi-num success">${{ number_format($totalReimbursement, 2) }}</span>
      </div>
    </div>

    <!-- Status Tabs -->
    <div class="tab-row">
      <button class="tab-btn active" onclick="filterStatus('all', this)">전체</button>
      <button class="tab-btn" onclick="filterStatus('pending', this)">대기</button>
      <button class="tab-btn" onclick="filterStatus('approved', this)">승인</button>
      <button class="tab-btn" onclick="filterStatus('rejected', this)">반려</button>
    </div>

    <!-- Expenses List -->
    <div class="expense-list" id="expenseList">
      @forelse($expenses as $expense)
        @php
          $expensePayload = $expense->toArray();
          $expensePayload['receipt_view_url'] = $expense->receipt_path ? route('mobile-expense.receipt', $expense) : null;
          $canModifyExpense = $canManageAllExpenses || ((int) $expense->employee_id === (int) auth()->user()?->employee_id && in_array($expense->status, ['draft', 'pending', 'rejected'], true));
          $expensePayload['edit_url'] = $canModifyExpense ? route('mobile-expense.edit', $expense) : null;
          $expensePayload['delete_url'] = $canModifyExpense ? route('mobile-expense.destroy', $expense) : null;
        @endphp
        <div class="expense-card" data-status="{{ $expense->status }}" onclick="openDetailModal({{ Illuminate\Support\Js::from($expensePayload) }})">
          <div class="card-top">
            <span class="card-category">{{ $expense->category }}</span>
            <span class="card-amount">${{ number_format($expense->amount, 2) }}</span>
          </div>
          <div class="card-mid">
            {{ $expense->description }}
          </div>
          <div class="card-bottom">
            <span class="card-date">{{ $expense->expense_date->format('Y-m-d') }}</span>
            <span class="status-badge {{ $expense->status }}">
              {{ $expense->status === 'pending' ? '승인대기' : ($expense->status === 'approved' ? '승인완료' : ($expense->status === 'rejected' ? '반려됨' : $expense->status)) }}
            </span>
          </div>
        </div>
      @empty
        <div class="empty-state">
          <i class="ph ph-receipt empty-icon"></i>
          <span>등록된 경비 내역이 없습니다.</span>
        </div>
      @endforelse
    </div>

    <!-- Floating Action Button -->
    <a href="{{ route('mobile-expense.wizard') }}" class="btn-create">
      <i class="ph ph-plus-circle"></i>
      <span>새 비용 영수증 제출 (AI)</span>
    </a>

  </div>

  <!-- Detail Modal -->
  <div class="modal-overlay" id="detailModal" onclick="closeDetailModal(event)">
    <div class="modal-content" onclick="event.stopPropagation()">
      <div class="modal-header">
        <h2 class="modal-title">지출 상세 정보</h2>
        <button class="modal-close" onclick="document.getElementById('detailModal').style.display='none'">
          <i class="ph ph-x"></i>
        </button>
      </div>
      <div class="detail-grid">
        <div class="detail-item">
          <span class="detail-label">결제 방법</span>
          <span class="detail-value" id="modalPaymentType">-</span>
        </div>
        <div class="detail-item">
          <span class="detail-label">지출 금액</span>
          <span class="detail-value" id="modalAmount" style="font-family:var(--font-mono);font-weight:700">-</span>
        </div>
        <div class="detail-item">
          <span class="detail-label">지출 카테고리</span>
          <span class="detail-value" id="modalCategory">-</span>
        </div>
        <div class="detail-item">
          <span class="detail-label">지출 날짜</span>
          <span class="detail-value" id="modalDate" style="font-family:var(--font-mono)">-</span>
        </div>
        <div class="detail-item" style="grid-column: span 2">
          <span class="detail-label">내역/설명</span>
          <span class="detail-value" id="modalDescription">-</span>
        </div>
        <div class="detail-item" id="modalClassItem">
          <span class="detail-label">부서/클래스</span>
          <span class="detail-value" id="modalClass">-</span>
        </div>
        <div class="detail-item">
          <span class="detail-label">승인 상태</span>
          <span class="detail-value" id="modalStatus">-</span>
        </div>
      </div>
      <div class="detail-item" id="receiptPreviewWrap" style="display:none">
        <span class="detail-label">영수증 첨부</span>
        <img class="receipt-preview" id="modalReceiptImg" src="" alt="영수증 미리보기">
      </div>
      <div class="modal-actions" id="modalActions" style="display:none">
        <a href="#" class="btn-edit" id="editExpenseLink">
          <i class="ph ph-pencil-simple"></i>
          <span>수정</span>
        </a>
        <form id="deleteExpenseForm" method="POST" style="flex:1" onsubmit="return confirm('이 영수증 처리를 삭제할까요?')">
          @csrf
          @method('DELETE')
          <button type="submit" class="btn-delete" style="width:100%">
            <i class="ph ph-trash"></i>
            <span>삭제</span>
          </button>
        </form>
      </div>
    </div>
  </div>

  <script>
    function filterStatus(status, btn) {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      const cards = document.querySelectorAll('.expense-card');
      let visibleCount = 0;
      cards.forEach(card => {
        if (status === 'all' || card.getAttribute('data-status') === status) {
          card.style.display = 'flex';
          visibleCount++;
        } else {
          card.style.display = 'none';
        }
      });

      const empty = document.querySelector('.empty-state');
      if (empty) {
        empty.style.display = (visibleCount === 0) ? 'flex' : 'none';
      }
    }

    function openDetailModal(expense) {
      document.getElementById('modalPaymentType').textContent = expense.payment_type === 'personal' ? '개인 지출 (환급 대상)' : '회사 법인카드';
      document.getElementById('modalAmount').textContent = '$' + Number(expense.amount).toLocaleString('en-US', {minimumFractionDigits: 2});
      document.getElementById('modalCategory').textContent = expense.category;
      document.getElementById('modalDate').textContent = expense.expense_date.split('T')[0];
      document.getElementById('modalDescription').textContent = expense.description;
      
      if (expense.class) {
        document.getElementById('modalClassItem').style.display = 'flex';
        document.getElementById('modalClass').textContent = expense.class;
      } else {
        document.getElementById('modalClassItem').style.display = 'none';
      }

      const statusLabels = {
        'draft': '임시저장',
        'pending': '승인대기중',
        'approved': '승인완료',
        'rejected': '반려됨'
      };
      document.getElementById('modalStatus').textContent = statusLabels[expense.status] || expense.status;

      const img = document.getElementById('modalReceiptImg');
      const wrap = document.getElementById('receiptPreviewWrap');
      if (expense.receipt_view_url) {
        img.src = expense.receipt_view_url;
        wrap.style.display = 'flex';
      } else {
        img.src = '';
        wrap.style.display = 'none';
      }

      const actions = document.getElementById('modalActions');
      const editLink = document.getElementById('editExpenseLink');
      const deleteForm = document.getElementById('deleteExpenseForm');
      if (expense.edit_url || expense.delete_url) {
        actions.style.display = 'flex';
        editLink.href = expense.edit_url || '#';
        editLink.style.display = expense.edit_url ? 'flex' : 'none';
        deleteForm.action = expense.delete_url || '';
        deleteForm.style.display = expense.delete_url ? 'block' : 'none';
      } else {
        actions.style.display = 'none';
        editLink.href = '#';
        deleteForm.action = '';
      }

      document.getElementById('detailModal').style.display = 'flex';
    }

    function closeDetailModal(e) {
      document.getElementById('detailModal').style.display = 'none';
    }
  </script>
</body>
</html>

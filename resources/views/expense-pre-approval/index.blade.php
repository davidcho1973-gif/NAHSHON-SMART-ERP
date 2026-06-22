<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>사전 예산 승인 요청 - SMART COMPANY</title>
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
      grid-template-columns: repeat(4, 1fr);
      gap: 6px;
    }
    .kpi-box {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 10px 8px;
      display: flex;
      flex-direction: column;
      gap: 2px;
      align-items: center;
      text-align: center;
    }
    .kpi-title {
      font-size: 9px;
      font-weight: 600;
      color: var(--text-tertiary);
      text-transform: uppercase;
    }
    .kpi-num {
      font-size: 14px;
      font-weight: 700;
      font-family: var(--font-mono);
    }
    .kpi-num.draft { color: var(--text-secondary); }
    .kpi-num.pending { color: var(--status-warning); }
    .kpi-num.approved { color: var(--status-success); }
    .kpi-num.rejected { color: var(--status-danger); }

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
      padding: 8px 4px;
      text-align: center;
      font-size: 11px;
      font-weight: 700;
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
    .request-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .request-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      cursor: pointer;
    }
    .card-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .card-title {
      font-size: 14px;
      font-weight: 700;
      color: var(--text-primary);
    }
    .card-amount {
      font-size: 14px;
      font-weight: 700;
      font-family: var(--font-mono);
      color: var(--brand-primary);
    }
    .card-mid {
      font-size: 12px;
      color: var(--text-secondary);
      line-height: 1.4;
    }
    .card-bottom {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 11px;
      color: var(--text-tertiary);
    }
    .status-badge {
      padding: 2px 8px;
      border-radius: 12px;
      font-weight: 600;
      font-size: 10px;
    }
    .status-badge.draft {
      background: var(--bg-surface-elevated);
      color: var(--text-secondary);
      border: 1px solid var(--border-subtle);
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
  </style>
</head>
<body>
  <div class="mobile-container">
    
    <div class="mobile-header">
      <a href="/" class="btn-back">
        <i class="ph ph-caret-left"></i>
      </a>
      <h1 class="mobile-title">사전 예산 승인 요청</h1>
      <div style="width: 36px"></div>
    </div>

    <!-- KPI Summary Grid -->
    <div class="kpi-grid">
      <div class="kpi-box">
        <span class="kpi-title">임시저장</span>
        <span class="kpi-num draft">{{ $draftCount }}</span>
      </div>
      <div class="kpi-box">
        <span class="kpi-title">결재대기</span>
        <span class="kpi-num pending">{{ $pendingCount }}</span>
      </div>
      <div class="kpi-box">
        <span class="kpi-title">승인완료</span>
        <span class="kpi-num approved">{{ $approvedCount }}</span>
      </div>
      <div class="kpi-box">
        <span class="kpi-title">반려됨</span>
        <span class="kpi-num rejected">{{ $rejectedCount }}</span>
      </div>
    </div>

    <!-- Status Tabs -->
    <div class="tab-row">
      <button class="tab-btn active" onclick="filterStatus('all', this)">전체</button>
      <button class="tab-btn" onclick="filterStatus('draft', this)">임시저장</button>
      <button class="tab-btn" onclick="filterStatus('pending', this)">결재중</button>
      <button class="tab-btn" onclick="filterStatus('approved', this)">승인</button>
      <button class="tab-btn" onclick="filterStatus('rejected', this)">반려</button>
    </div>

    <!-- Requests List -->
    <div class="request-list" id="requestList">
      @forelse($requests as $req)
        <div class="request-card" data-status="{{ $req->status }}" onclick="openDetailModal({{ json_encode($req) }})">
          <div class="card-top">
            <span class="card-title">{{ $req->title }}</span>
            <span class="card-amount">${{ number_format($req->estimated_amount, 2) }}</span>
          </div>
          <div class="card-mid">
            {{ Str::limit($req->description, 60) ?: '(설명 없음)' }}
          </div>
          <div class="card-bottom">
            <span style="font-family:var(--font-mono)">예정일: {{ $req->planned_date->format('Y-m-d') }}</span>
            <span class="status-badge {{ $req->status }}">
              {{ $req->status === 'draft' ? '임시저장' : ($req->status === 'pending' ? '결재대기' : ($req->status === 'approved' ? '승인완료' : ($req->status === 'rejected' ? '반려됨' : $req->status))) }}
            </span>
          </div>
        </div>
      @empty
        <div class="empty-state">
          <i class="ph ph-hand-coins empty-icon"></i>
          <span>상향 예산 결재 건이 없습니다.</span>
        </div>
      @endforelse
    </div>

    <!-- Floating Action Button -->
    <a href="{{ route('expense-pre-approval.create') }}" class="btn-create">
      <i class="ph ph-plus-circle"></i>
      <span>새 예산 승인 올리기</span>
    </a>

  </div>

  <!-- Detail Modal -->
  <div class="modal-overlay" id="detailModal" onclick="closeDetailModal(event)">
    <div class="modal-content" onclick="event.stopPropagation()">
      <div class="modal-header">
        <h2 class="modal-title">예산 승인 상세 정보</h2>
        <button class="modal-close" onclick="document.getElementById('detailModal').style.display='none'">
          <i class="ph ph-x"></i>
        </button>
      </div>
      <div class="detail-grid">
        <div class="detail-item" style="grid-column: span 2">
          <span class="detail-label">제목</span>
          <span class="detail-value" id="modalTitle" style="font-size:15px; font-weight:700">-</span>
        </div>
        <div class="detail-item">
          <span class="detail-label">예상 금액</span>
          <span class="detail-value" id="modalAmount" style="font-family:var(--font-mono); font-weight:700; color:var(--brand-primary)">-</span>
        </div>
        <div class="detail-item">
          <span class="detail-label">결제 방법</span>
          <span class="detail-value" id="modalPaymentMethod">-</span>
        </div>
        <div class="detail-item">
          <span class="detail-label">사용 예정일</span>
          <span class="detail-value" id="modalPlannedDate" style="font-family:var(--font-mono)">-</span>
        </div>
        <div class="detail-item">
          <span class="detail-label">결재 상태</span>
          <span class="detail-value" id="modalStatus">-</span>
        </div>
        <div class="detail-item" style="grid-column: span 2">
          <span class="detail-label">설명</span>
          <span class="detail-value" id="modalDescription">-</span>
        </div>
        <div class="detail-item" style="grid-column: span 2">
          <span class="detail-label">품의서 타당성 (Justification)</span>
          <span class="detail-value" id="modalJustification" style="background:var(--bg-surface-elevated); padding:10px; border-radius:6px; border:1px solid var(--border-subtle)">-</span>
        </div>
      </div>
    </div>
  </div>

  <script>
    function filterStatus(status, btn) {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      const cards = document.querySelectorAll('.request-card');
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

    function openDetailModal(req) {
      document.getElementById('modalTitle').textContent = req.title;
      document.getElementById('modalAmount').textContent = '$' + Number(req.estimated_amount).toLocaleString('en-US', {minimumFractionDigits: 2});
      document.getElementById('modalPaymentMethod').textContent = req.payment_method === 'personal' ? '개인 지출 (환급)' : '회사 법인카드';
      document.getElementById('modalPlannedDate').textContent = req.planned_date.split('T')[0];
      document.getElementById('modalDescription').textContent = req.description || '(내용 없음)';
      document.getElementById('modalJustification').textContent = req.justification;

      const statusLabels = {
        'draft': '임시저장',
        'pending': '결재대기중',
        'approved': '승인완료',
        'rejected': '반려됨'
      };
      document.getElementById('modalStatus').textContent = statusLabels[req.status] || req.status;

      document.getElementById('detailModal').style.display = 'flex';
    }

    function closeDetailModal(e) {
      document.getElementById('detailModal').style.display = 'none';
    }
  </script>
</body>
</html>

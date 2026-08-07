<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>장비 목록 - SMART COMPANY</title>
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
    .equipment-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .equipment-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 14px;
      display: flex;
      gap: 12px;
      cursor: pointer;
      transition: border-color 0.2s ease;
    }
    .equipment-card:hover {
      border-color: var(--brand-primary);
    }
    .card-img-wrap {
      width: 80px;
      height: 80px;
      border-radius: var(--radius-md);
      overflow: hidden;
      background: var(--bg-base);
      border: 1px solid var(--border-subtle);
      flex-shrink: 0;
    }
    .card-img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .card-placeholder-img {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-tertiary);
      font-size: 24px;
    }
    .card-content {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      flex-grow: 1;
      min-width: 0;
    }
    .card-top {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 8px;
    }
    .card-code {
      font-family: var(--font-mono);
      font-size: 11px;
      font-weight: 700;
      color: var(--brand-primary);
    }
    .card-status {
      padding: 2px 6px;
      border-radius: 4px;
      font-weight: 700;
      font-size: 9px;
    }
    .card-status.대기중 {
      background: var(--status-success-dim);
      color: var(--status-success);
    }
    .card-status.사용중 {
      background: var(--status-warning-dim);
      color: var(--status-warning);
    }
    .card-status.정비중 {
      background: var(--status-danger-dim);
      color: var(--status-danger);
    }
    .card-mid {
      font-size: 14px;
      color: var(--text-primary);
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin: 2px 0;
    }
    .card-sub {
      font-size: 11px;
      color: var(--text-secondary);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .card-bottom {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 10px;
      color: var(--text-tertiary);
      margin-top: 4px;
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
    .photo-preview {
      width: 100%;
      border-radius: var(--radius-md);
      border: 1px solid var(--border-subtle);
      max-height: 250px;
      object-fit: contain;
      background: var(--bg-base);
    }
    .btn-batch-action {
      padding: 10px 12px;
      border-radius: var(--radius-md);
      font-size: 12px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      cursor: pointer;
      border: none;
      text-decoration: none;
    }
    .btn-batch-action.secondary {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      color: var(--text-secondary);
    }
    .btn-batch-action.success {
      background: linear-gradient(135deg, var(--status-success), #15803d);
      color: white;
    }
  </style>
</head>
<body>
  <div class="mobile-container">
    
    <div class="mobile-header">
      <a href="/" class="btn-back">
        <i class="ph ph-caret-left"></i>
      </a>
      <h1 class="mobile-title">현장 장비 목록</h1>
      <div style="width: 36px"></div>
    </div>

    <!-- KPI Summary Grid -->
    <div class="kpi-grid">
      <div class="kpi-box">
        <span class="kpi-title">총 보유 장비</span>
        <span class="kpi-num highlight">{{ $totalCount }}대</span>
      </div>
      <div class="kpi-box">
        <span class="kpi-title">대기중 (배정가능)</span>
        <span class="kpi-num success">{{ $availableCount }}대</span>
      </div>
      <div class="kpi-box">
        <span class="kpi-title">현장 사용중</span>
        <span class="kpi-num">{{ $operableCount }}대</span>
      </div>
    </div>

    <!-- Status Tabs -->
    <div class="tab-row">
      <button class="tab-btn active" onclick="filterStatus('all', this)">전체</button>
      <button class="tab-btn" onclick="filterStatus('대기중', this)">대기중</button>
      <button class="tab-btn" onclick="filterStatus('사용중', this)">사용중</button>
      <button class="tab-btn" onclick="filterStatus('정비중', this)">정비중</button>
    </div>

    <!-- Equipments List -->
    <div class="equipment-list" id="equipmentList">
      @forelse($equipments as $equipment)
        @php
          $photoUrl = null;
          if ($equipment->photo_front) {
              $relativePath = ltrim(str_replace('/storage/', '', $equipment->photo_front), '/');
              $photoUrl = route('equipment.file', ['path' => $relativePath]);
          }
          
          $equipmentPayload = [
              'id' => $equipment->id,
              'code' => $equipment->equipment_code,
              'type' => $equipment->equipment_type,
              'model' => $equipment->model,
              'vendor' => $equipment->vendor ?: '-',
              'vendor_raw' => $equipment->vendor ?: '',
              'site' => $equipment->site ? $equipment->site->name : '-',
              'site_id' => $equipment->site_id,
              'team' => $equipment->team ? $equipment->team->name : '-',
              'team_id' => $equipment->team_id,
              'operator' => $equipment->employee ? $equipment->employee->name : '-',
              'employee_id' => $equipment->employee_id,
              'status' => $equipment->status,
              'photo' => $photoUrl
          ];
        @endphp
        <div class="equipment-card" data-status="{{ $equipment->status }}" onclick="openDetailModal({{ Illuminate\Support\Js::from($equipmentPayload) }})">
          <div class="card-img-wrap">
            @if($photoUrl)
              <img class="card-img" src="{{ $photoUrl }}" alt="{{ $equipment->model }}">
            @else
              <div class="card-placeholder-img">
                <i class="ph ph-wrench"></i>
              </div>
            @endif
          </div>
          <div class="card-content">
            <div class="card-top">
              <span class="card-code">{{ $equipment->equipment_code }}</span>
              <span class="card-status {{ $equipment->status }}">
                {{ $equipment->status }}
              </span>
            </div>
            <div class="card-mid">
              {{ $equipment->model }}
            </div>
            <div class="card-sub">
              분류: {{ $equipment->equipment_type }} | 제조사: {{ $equipment->vendor ?: '-' }}
            </div>
            <div class="card-bottom">
              <span>현장: {{ $equipment->site ? $equipment->site->code : 'Global' }}</span>
              <span>등록: {{ $equipment->registration_method === 'AI자동분석' ? 'AI스캔' : '수동' }}</span>
            </div>
          </div>
        </div>
      @empty
        <div class="empty-state">
          <i class="ph ph-wrench empty-icon"></i>
          <span>등록된 현장 장비가 없습니다.</span>
        </div>
      @endforelse
    </div>

    <!-- Floating Action Button -->
    <a href="{{ route('mobile-equipment.wizard') }}" class="btn-create">
      <i class="ph ph-camera"></i>
      <span>장비 카메라 스캔등록 (AI)</span>
    </a>

  </div>

  <!-- Detail Modal -->
  <div class="modal-overlay" id="detailModal" onclick="closeDetailModal(event)">
    <div class="modal-content" onclick="event.stopPropagation()">
      
      <!-- Read-Only View -->
      <div id="detailView" style="display: flex; flex-direction: column; gap: 16px;">
        <div class="modal-header">
          <h2 class="modal-title">장비 상세 정보</h2>
          <button class="modal-close" onclick="closeDetailModal()">
            <i class="ph ph-x"></i>
          </button>
        </div>
        <div class="detail-grid">
          <div class="detail-item">
            <span class="detail-label">장비 코드</span>
            <span class="detail-value" id="modalCode" style="font-family:var(--font-mono);font-weight:700">-</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">장비 상태</span>
            <span class="detail-value" id="modalStatus">-</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">장비 분류</span>
            <span class="detail-value" id="modalType">-</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">제조사/브랜드</span>
            <span class="detail-value" id="modalVendor">-</span>
          </div>
          <div class="detail-item" style="grid-column: span 2">
            <span class="detail-label">모델명</span>
            <span class="detail-value" id="modalModel" style="font-weight:700">-</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">배정 현장</span>
            <span class="detail-value" id="modalSite">-</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">배정 팀</span>
            <span class="detail-value" id="modalTeam">-</span>
          </div>
          <div class="detail-item" style="grid-column: span 2">
            <span class="detail-label">담당자 (운영자)</span>
            <span class="detail-value" id="modalOperator">-</span>
          </div>
        </div>
        <div class="detail-item" id="photoPreviewWrap" style="display:none">
          <span class="detail-label">장비 실물 사진</span>
          <img class="photo-preview" id="modalPhotoImg" src="" alt="장비 사진">
        </div>
        <div style="margin-top:12px; display:grid; grid-template-columns: 1fr 1fr; gap: 8px;">
          <button class="btn-batch-action secondary" onclick="switchToEditMode()" style="padding:10px; font-size:12px;">
            <i class="ph ph-pencil-simple"></i> 수정하기
          </button>
          <button class="btn-batch-action secondary" onclick="deleteEquipment()" style="padding:10px; font-size:12px; color:var(--status-danger); border-color:rgba(239, 68, 68, 0.2);">
            <i class="ph ph-trash"></i> 삭제하기
          </button>
        </div>
        <div style="margin-top:4px;">
          <button class="btn-batch-action secondary" style="width:100%; padding:10px; font-size:12px;" onclick="closeDetailModal()">닫기</button>
        </div>
      </div>

      <!-- Edit View Form -->
      <form id="editEquipmentForm" method="POST" enctype="multipart/form-data" style="display: none; flex-direction: column; gap: 14px;">
        @csrf
        @method('PUT')
        <div class="modal-header">
          <h2 class="modal-title">장비 정보 수정</h2>
          <button type="button" class="modal-close" onclick="closeDetailModal()">
            <i class="ph ph-x"></i>
          </button>
        </div>

        <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 8px;">
          <div class="form-group-sm">
            <label class="form-label-sm">분류</label>
            <select name="equipment_type" id="editType" class="input-text-sm" style="background-color: var(--bg-surface-elevated);">
              <option value="Power Tool (전동공구)">Power Tool (전동공구)</option>
              <option value="Hand Tool (수공구)">Hand Tool (수공구)</option>
              <option value="Pipes & Fittings (배관 자재)">Pipes & Fittings (배관 자재)</option>
              <option value="Conduit & Electrical (전선관/전기 자재)">Conduit & Electrical (전선관/전기 자재)</option>
              <option value="Wires & Cables (전선/케이블)">Wires & Cables (전선/케이블)</option>
              <option value="Valves & Controls (밸브/계측기)">Valves & Controls (밸브/계측기)</option>
              <option value="Fasteners & Anchors (체결류/피스)">Fasteners & Anchors (체결류/피스)</option>
              <option value="Generator & Power (발전기/동력원)">Generator & Power (발전기/동력원)</option>
              <option value="Welding Machine (용접기)">Welding Machine (용접기)</option>
              <option value="Heavy Equipment (중장비)">Heavy Equipment (중장비)</option>
              <option value="Safety & PPE (안전 용품)">Safety & PPE (안전 용품)</option>
              <option value="Other Materials (기타 자재/공구)">Other Materials (기타 자재/공구)</option>
            </select>
          </div>
          <div class="form-group-sm">
            <label class="form-label-sm">제조사</label>
            <input type="text" name="vendor" id="editVendor" class="input-text-sm" placeholder="예: Honda">
          </div>
        </div>

        <div class="form-group-sm">
          <label class="form-label-sm">모델명/설명 <span style="color:var(--status-danger)">*</span></label>
          <input type="text" name="model" id="editModel" class="input-text-sm" required>
        </div>

        <div style="display: grid; grid-template-columns: 1fr; gap: 8px;">
          <div class="form-group-sm">
            <label class="form-label-sm">상태</label>
            <select name="status" id="editStatus" class="input-text-sm" style="background-color: var(--bg-surface-elevated);">
              <option value="대기중">대기중</option>
              <option value="사용중">사용중</option>
              <option value="정비중">정비중</option>
            </select>
          </div>
        </div>

        <div class="form-group-sm">
          <label class="form-label-sm">배정 현장 (Site)</label>
          <select name="site_id" id="editSite" class="input-text-sm" style="background-color: var(--bg-surface-elevated);">
            <option value="">지정 안함 (Global / Office)</option>
            @foreach($sites as $site)
              <option value="{{ $site->id }}">{{ $site->code }} - {{ $site->name }}</option>
            @endforeach
          </select>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
          <div class="form-group-sm">
            <label class="form-label-sm">배정 팀 (Team)</label>
            <select name="team_id" id="editTeam" class="input-text-sm" style="background-color: var(--bg-surface-elevated); padding: 8px;">
              <option value="">지정 안함</option>
              @foreach($teams as $team)
                <option value="{{ $team->id }}">{{ $team->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="form-group-sm">
            <label class="form-label-sm">담당 운영자 (Operator)</label>
            <select name="employee_id" id="editOperator" class="input-text-sm" style="background-color: var(--bg-surface-elevated); padding: 8px;">
              <option value="">지정 안함</option>
              @foreach($employees as $emp)
                <option value="{{ $emp->id }}">{{ $emp->name }}</option>
              @endforeach
            </select>
          </div>
        </div>

        <div class="form-group-sm">
          <label class="form-label-sm">실물 사진 변경</label>
          <div style="display: flex; align-items: center; gap: 10px;">
            <img id="editPhotoPreview" src="" style="width: 48px; height: 48px; object-fit: cover; border-radius: var(--radius-md); border: 1px solid var(--border-subtle); display: none;">
            <input type="file" name="photo" accept="image/*" capture="environment" class="input-text-sm" style="flex-grow: 1;">
          </div>
        </div>

        <div style="margin-top:12px; display:grid; grid-template-columns: 1fr 1fr; gap: 8px;">
          <button type="button" class="btn-batch-action secondary" onclick="switchToDetailMode()" style="padding:10px; font-size:12px;">취소</button>
          <button type="submit" class="btn-batch-action success" style="padding:10px; font-size:12px;">저장하기</button>
        </div>
      </form>

    </div>
  </div>

  <!-- Hidden Delete Form -->
  <form id="deleteEquipmentForm" method="POST" style="display:none">
    @csrf
    @method('DELETE')
  </form>

  <script>
    let currentSelectedEquipment = null;

    function filterStatus(status, btn) {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      const cards = document.querySelectorAll('.equipment-card');
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

    function openDetailModal(eq) {
      currentSelectedEquipment = eq;
      switchToDetailMode();

      document.getElementById('modalCode').textContent = eq.code;
      document.getElementById('modalStatus').textContent = eq.status;
      document.getElementById('modalType').textContent = eq.type;
      document.getElementById('modalVendor').textContent = eq.vendor;
      document.getElementById('modalModel').textContent = eq.model;
      document.getElementById('modalSite').textContent = eq.site;
      document.getElementById('modalTeam').textContent = eq.team;
      document.getElementById('modalOperator').textContent = eq.operator;

      const img = document.getElementById('modalPhotoImg');
      const wrap = document.getElementById('photoPreviewWrap');
      if (eq.photo) {
        img.src = eq.photo;
        wrap.style.display = 'flex';
      } else {
        img.src = '';
        wrap.style.display = 'none';
      }

      document.getElementById('detailModal').style.display = 'flex';
    }

    function closeDetailModal(e) {
      if (!e || e.target === document.getElementById('detailModal') || e.target.classList.contains('modal-close') || e.target.closest('.modal-close')) {
        document.getElementById('detailModal').style.display = 'none';
      }
    }

    function switchToEditMode() {
      if (!currentSelectedEquipment) return;
      const eq = currentSelectedEquipment;

      document.getElementById('editModel').value = eq.model;
      document.getElementById('editVendor').value = eq.vendor_raw;
      document.getElementById('editType').value = eq.type;
      document.getElementById('editStatus').value = eq.status;
      document.getElementById('editSite').value = eq.site_id || '';
      document.getElementById('editTeam').value = eq.team_id || '';
      document.getElementById('editOperator').value = eq.employee_id || '';

      const editPreview = document.getElementById('editPhotoPreview');
      if (eq.photo) {
        editPreview.src = eq.photo;
        editPreview.style.display = 'block';
      } else {
        editPreview.src = '';
        editPreview.style.display = 'none';
      }

      const updateUrl = "/mobile-equipment/" + eq.id;
      document.getElementById('editEquipmentForm').action = updateUrl;

      document.getElementById('detailView').style.display = 'none';
      document.getElementById('editEquipmentForm').style.display = 'flex';
    }

    function switchToDetailMode() {
      document.getElementById('editEquipmentForm').style.display = 'none';
      document.getElementById('detailView').style.display = 'flex';
    }

    function deleteEquipment() {
      if (!currentSelectedEquipment) return;
      if (confirm('정말로 이 장비/자재를 삭제하시겠습니까?\n이 작업은 되돌릴 수 없습니다.')) {
        const deleteForm = document.getElementById('deleteEquipmentForm');
        deleteForm.action = "/mobile-equipment/" + currentSelectedEquipment.id;
        deleteForm.submit();
      }
    }
  </script>
</body>
</html>

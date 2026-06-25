<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>장비 신속 스캔등록 - SMART COMPANY</title>
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
      position: relative;
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
    .shared-info-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .shared-info-title {
      font-size: 13px;
      font-weight: 700;
      color: var(--text-secondary);
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .form-group-sm {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .form-label {
      font-size: 11px;
      font-weight: 700;
      color: var(--text-tertiary);
      text-transform: uppercase;
    }
    .form-label-sm {
      font-size: 9px;
      font-weight: 700;
      color: var(--text-tertiary);
      text-transform: uppercase;
    }
    .input-text {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      color: var(--text-primary);
      padding: 10px 12px;
      border-radius: var(--radius-md);
      font-size: 13px;
      outline: none;
      font-family: inherit;
    }
    .input-text:focus {
      border-color: var(--brand-primary);
    }
    .input-text-sm {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      color: var(--text-primary);
      padding: 6px 8px;
      border-radius: var(--radius-md);
      font-size: 12px;
      outline: none;
      font-family: inherit;
    }
    .input-text-sm:focus {
      border-color: var(--brand-primary);
    }
    .batch-items-list {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-bottom: 24px;
    }
    .batch-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 12px;
      position: relative;
      display: flex;
      flex-direction: column;
      gap: 8px;
      animation: fadeIn 0.3s ease-in-out;
    }
    .batch-card-body {
      display: flex;
      gap: 12px;
    }
    .batch-card-img-wrap {
      width: 90px;
      height: 120px;
      border-radius: var(--radius-md);
      border: 1px solid var(--border-subtle);
      overflow: hidden;
      position: relative;
      background: var(--bg-base);
      flex-shrink: 0;
    }
    .batch-card-img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .batch-card-spinner-overlay {
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.65);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: white;
      gap: 4px;
    }
    .batch-card-fields {
      flex-grow: 1;
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-width: 0;
    }
    .btn-remove-card {
      position: absolute;
      top: 8px;
      right: 8px;
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.2);
      color: var(--status-danger);
      width: 24px;
      height: 24px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      z-index: 10;
    }
    .btn-remove-card:hover {
      background: var(--status-danger);
      color: white;
    }
    .empty-state {
      text-align: center;
      padding: 48px 16px;
      color: var(--text-tertiary);
      border: 2px dashed var(--border-subtle);
      border-radius: var(--radius-lg);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      background: var(--bg-surface);
    }
    .empty-icon {
      font-size: 40px;
    }
    .floating-footer {
      position: fixed;
      bottom: 0;
      left: 50%;
      transform: translateX(-50%);
      width: 100%;
      max-width: 480px;
      background: var(--bg-surface);
      border-top: 1px solid var(--border-subtle);
      padding: 12px 16px;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      z-index: 100;
      box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
    }
    .btn-batch-action {
      padding: 12px;
      border-radius: var(--radius-lg);
      font-size: 13px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      cursor: pointer;
      border: none;
      text-decoration: none;
    }
    .btn-batch-action.camera {
      background: linear-gradient(135deg, var(--brand-primary), #1d4ed8);
      color: white;
    }
    .btn-batch-action.save {
      background: linear-gradient(135deg, var(--status-success), #15803d);
      color: white;
    }
    .btn-batch-action:disabled {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      color: var(--text-tertiary);
      cursor: not-allowed;
      background-image: none;
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .spin {
      animation: spin 1s linear infinite;
    }
  </style>
</head>
<body>
  <div class="mobile-container">
    
    <div class="mobile-header">
      <a href="{{ route('mobile-equipment.index') }}" class="btn-back">
        <i class="ph ph-caret-left"></i>
      </a>
      <h1 class="mobile-title">AI 신속 스캔등록 (연속)</h1>
      <div style="width: 36px"></div>
    </div>

    <form id="batchForm" action="{{ route('mobile-equipment.store-batch') }}" method="POST" style="display:flex; flex-direction:column; flex:1; gap:16px;">
      @csrf

      <!-- Shared/Batch Assignment Card -->
      <div class="shared-info-card">
        <div class="shared-info-title">
          <i class="ph ph-sliders"></i>
          <span>배치 공통 정보 (기본 배정지 지정)</span>
        </div>
        
        <div class="form-group">
          <label class="form-label" for="siteSelect">배정 현장 (Site)</label>
          <select name="site_id" id="siteSelect" class="input-text" style="background-color: var(--bg-surface-elevated);">
            <option value="">지정 안함 (Global / Office)</option>
            @foreach($sites as $site)
              <option value="{{ $site->id }}" @selected((int) ($selectedSiteId ?? 0) === (int) $site->id)>{{ $site->code }} - {{ $site->name }}</option>
            @endforeach
          </select>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
          <div class="form-group-sm">
            <label class="form-label" for="teamSelect">배정 팀 (Team)</label>
            <select name="team_id" id="teamSelect" class="input-text" style="background-color: var(--bg-surface-elevated); padding: 10px;">
              <option value="">지정 안함</option>
              @foreach($teams as $team)
                <option value="{{ $team->id }}">{{ $team->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="form-group-sm">
            <label class="form-label" for="employeeSelect">담당 운영자 (Operator)</label>
            <select name="employee_id" id="employeeSelect" class="input-text" style="background-color: var(--bg-surface-elevated); padding: 10px;">
              <option value="">지정 안함</option>
              @foreach($employees as $employee)
                <option value="{{ $employee->id }}">{{ $employee->name }}</option>
              @endforeach
            </select>
          </div>
        </div>
      </div>

      <!-- Batch Items List Container -->
      <div class="batch-items-list" id="batchItemsList">
        <!-- Cards will be appended here dynamically -->
      </div>

      <!-- Empty State View -->
      <div class="empty-state" id="emptyState">
        <i class="ph ph-camera-rotate empty-icon"></i>
        <span style="font-weight:600;">아직 촬영된 장비 사진이 없습니다.</span>
        <span style="font-size:11px; color:var(--text-secondary);">하단의 카메라 버튼을 눌러 자재 및 툴을 연속으로 찍어보세요!</span>
      </div>

      <!-- Native File Picker (Supports multiple files and environment/back camera) -->
      <input type="file" id="photoFileInput" accept="image/*" capture="environment" style="display:none" onchange="handlePhotoUpload(event)" multiple>

      <!-- Fixed Bottom Action Footer -->
      <div class="floating-footer">
        <button type="button" class="btn-batch-action camera" onclick="document.getElementById('photoFileInput').click()">
          <i class="ph ph-camera"></i>
          <span>추가 촬영/업로드</span>
        </button>
        <button type="button" class="btn-batch-action save" id="btnSaveBatch" onclick="submitBatchForm()" disabled>
          <i class="ph ph-floppy-disk"></i>
          <span id="saveBtnText">등록할 장비 없음</span>
        </button>
      </div>

    </form>
  </div>

  <script>
    let cardCounter = 0;

    function createCardHTML(cardId, imageUrl) {
      return `
        <div class="batch-card" id="card-${cardId}">
          <button type="button" class="btn-remove-card" onclick="removeCard('${cardId}')">
            <i class="ph ph-trash"></i>
          </button>
          
          <div class="batch-card-body">
            <div class="batch-card-img-wrap">
              <img src="${imageUrl}" class="batch-card-img">
              <div class="batch-card-spinner-overlay" id="spinner-${cardId}">
                <i class="ph ph-circle-notch spin" style="font-size: 24px;"></i>
                <span style="font-size: 9px; font-weight:600; text-align:center;">AI 분석중...</span>
              </div>
            </div>
            
            <div class="batch-card-fields">
              <input type="hidden" name="items[${cardId}][photo_front]" id="photo-${cardId}">
              <input type="hidden" name="items[${cardId}][ocr_data]" id="ocr-${cardId}">
              
              <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 8px;">
                <div class="form-group-sm">
                  <label class="form-label-sm">분류</label>
                  <select name="items[${cardId}][equipment_type]" id="type-${cardId}" class="input-text-sm" style="background-color: var(--bg-surface-elevated);">
                    <option value="Generator (발전기)">Generator (발전기)</option>
                    <option value="Welding Machine (용접기)">Welding Machine (용접기)</option>
                    <option value="Power Tool (전동공구)">Power Tool (전동공구)</option>
                    <option value="Hand Tool (수공구)">Hand Tool (수공구)</option>
                    <option value="Forklift (지게차)">Forklift (지게차)</option>
                    <option value="Boom Lift (고소작업대)">Boom Lift (고소작업대)</option>
                    <option value="Excavator (굴착기)">Excavator (굴착기)</option>
                    <option value="Skid Steer (스키드로더)">Skid Steer (스키드로더)</option>
                    <option value="Compressor (콤프레샤)">Compressor (콤프레샤)</option>
                    <option value="Other (기타)" selected>Other (기타)</option>
                  </select>
                </div>
                <div class="form-group-sm">
                  <label class="form-label-sm">제조사</label>
                  <input type="text" name="items[${cardId}][vendor]" id="vendor-${cardId}" class="input-text-sm" placeholder="예: Honda">
                </div>
              </div>
              
              <div class="form-group-sm" style="margin-top: 2px;">
                <label class="form-label-sm">모델명/설명 <span style="color:var(--status-danger)">*</span></label>
                <input type="text" name="items[${cardId}][model]" id="model-${cardId}" class="input-text-sm" placeholder="모델명 또는 상세설명" required>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; align-items: center; margin-top: 2px;">
                <div class="form-group-sm">
                  <label class="form-label-sm">수량</label>
                  <input type="number" name="items[${cardId}][quantity]" id="quantity-${cardId}" class="input-text-sm" value="1" min="1" required>
                </div>
                <div style="display: flex; align-items: center; gap: 4px; margin-top: 14px; cursor: pointer;">
                  <input type="checkbox" name="items[${cardId}][is_bulk]" id="bulk-${cardId}" value="on" style="width: 15px; height: 15px; accent-color: var(--brand-primary); cursor: pointer;">
                  <label for="bulk-${cardId}" style="font-size: 10px; font-weight:600; color:var(--text-secondary); cursor: pointer; user-select:none;">대량 자재</label>
                </div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr; gap: 8px; margin-top: 2px;">
                <div class="form-group-sm">
                  <label class="form-label-sm">상태</label>
                  <select name="items[${cardId}][status]" id="status-${cardId}" class="input-text-sm" style="background-color: var(--bg-surface-elevated);">
                    <option value="대기중" selected>대기중</option>
                    <option value="사용중">사용중</option>
                    <option value="정비중">정비중</option>
                  </select>
                </div>
              </div>

            </div>
          </div>
        </div>
      `;
    }

    async function handlePhotoUpload(event) {
      const files = event.target.files;
      if (!files || files.length === 0) return;

      const container = document.getElementById('batchItemsList');
      const empty = document.getElementById('emptyState');
      if (empty) empty.style.display = 'none';

      for (let i = 0; i < files.length; i++) {
        const file = files[i];
        cardCounter++;
        const cardId = cardCounter;
        
        // Show local preview immediately using object URL
        const imageUrl = URL.createObjectURL(file);
        
        // Create card HTML and append to list
        const cardHtml = createCardHTML(cardId, imageUrl);
        container.insertAdjacentHTML('beforeend', cardHtml);
        
        // Start uploading file to scan API asynchronously
        uploadAndAnalyzeCard(cardId, file);
      }
      
      // Update counts
      updateBatchCounts();
      
      // Reset file input
      event.target.value = '';
    }

    async function uploadAndAnalyzeCard(cardId, file) {
      const formData = new FormData();
      formData.append('photo', file);

      try {
        const response = await fetch("{{ route('mobile-equipment.scan-photo') }}", {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
          },
          body: formData
        });

        const result = await response.json();

        if (result.success) {
          const data = result.data;
          document.getElementById('photo-' + cardId).value = result.photo_path;
          document.getElementById('ocr-' + cardId).value = JSON.stringify(data);

          // Populate inputs
          if (data.model) {
            document.getElementById('model-' + cardId).value = data.model;
          }
          if (data.vendor) {
            document.getElementById('vendor-' + cardId).value = data.vendor;
          }
          if (data.equipment_type) {
            const select = document.getElementById('type-' + cardId);
            let found = false;
            for (let i = 0; i < select.options.length; i++) {
              if (select.options[i].value.toLowerCase().includes(data.equipment_type.toLowerCase()) || 
                  data.equipment_type.toLowerCase().includes(select.options[i].value.toLowerCase())) {
                select.selectedIndex = i;
                found = true;
                break;
              }
            }
            if (!found) {
              select.value = "Other (기타)";
            }
          }

          // Hide loader overlay
          document.getElementById('spinner-' + cardId).style.display = 'none';
        } else {
          // If fail, keep card but let user type manually
          document.getElementById('spinner-' + cardId).style.display = 'none';
          document.getElementById('model-' + cardId).placeholder = 'AI 판독 실패 - 수동 입력 필요';
          // Save the photo path returned from upload if available, so it retains photo even on failed AI
          if (result.photo_path) {
            document.getElementById('photo-' + cardId).value = result.photo_path;
          }
        }
      } catch (err) {
        document.getElementById('spinner-' + cardId).style.display = 'none';
        document.getElementById('model-' + cardId).placeholder = '스캔 오류 - 수동 입력 필요';
      } finally {
        updateBatchCounts();
      }
    }

    function removeCard(cardId) {
      const card = document.getElementById('card-' + cardId);
      if (card) {
        card.remove();
      }
      
      const container = document.getElementById('batchItemsList');
      if (container.children.length === 0) {
        const empty = document.getElementById('emptyState');
        if (empty) empty.style.display = 'flex';
      }
      
      updateBatchCounts();
    }

    function updateBatchCounts() {
      const container = document.getElementById('batchItemsList');
      const count = container.children.length;
      const btnSave = document.getElementById('btnSaveBatch');
      const saveText = document.getElementById('saveBtnText');
      
      if (count > 0) {
        btnSave.disabled = false;
        saveText.textContent = `총 ${count}건 일괄 등록`;
      } else {
        btnSave.disabled = true;
        saveText.textContent = '등록할 장비 없음';
      }
    }

    function submitBatchForm() {
      // Validate all models are filled
      const models = document.querySelectorAll('input[id^="model-"]');
      let valid = true;
      models.forEach(m => {
        if (!m.value.trim()) {
          m.style.borderColor = 'var(--status-danger)';
          valid = false;
        } else {
          m.style.borderColor = 'var(--border-subtle)';
        }
      });
      
      if (!valid) {
        alert('모델명이 빈칸인 품목이 있습니다. 모델명 또는 간략한 설명을 기입해 주세요.');
        return;
      }
      
      document.getElementById('batchForm').submit();
    }
  </script>
</body>
</html>

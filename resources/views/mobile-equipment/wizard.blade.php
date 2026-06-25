<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>AI 장비/자재 종합 스캐너 - SMART COMPANY</title>
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
      margin: 0;
    }
    .mobile-container {
      max-width: 480px;
      margin: 0 auto;
      padding: 16px;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      gap: 16px;
      padding-bottom: 100px;
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
    .step-indicator {
      display: flex;
      justify-content: space-between;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 10px 16px;
      font-size: 11px;
      font-weight: 700;
      color: var(--text-tertiary);
    }
    .step-item {
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .step-item.active {
      color: var(--brand-primary);
    }
    .step-item.completed {
      color: var(--status-success);
    }
    .step-circle {
      width: 16px;
      height: 16px;
      border-radius: 50%;
      border: 1px solid currentColor;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 9px;
    }

    /* Step 1: Photo Queue Panel */
    .queue-panel {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .queue-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
    }
    .queue-card {
      aspect-ratio: 3/4;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      overflow: hidden;
      position: relative;
      animation: fadeIn 0.25s ease-in-out;
    }
    .queue-img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .btn-delete-photo {
      position: absolute;
      top: 4px;
      right: 4px;
      background: rgba(239, 68, 68, 0.85);
      border: none;
      color: white;
      width: 20px;
      height: 20px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 11px;
    }
    .btn-delete-photo:hover {
      background: #dc2626;
    }
    .tip-box {
      background: rgba(14, 165, 233, 0.08);
      border: 1px solid rgba(14, 165, 233, 0.2);
      border-radius: var(--radius-lg);
      padding: 12px 14px;
      font-size: 12px;
      line-height: 1.5;
      color: #7dd3fc;
      display: flex;
      gap: 10px;
    }
    .tip-icon {
      font-size: 18px;
      color: #38bdf8;
      flex-shrink: 0;
      margin-top: 2px;
    }

    /* Shared Common Fields */
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

    /* Batch Items Card List */
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
    .btn-manual-add {
      background: var(--bg-surface);
      border: 1px dashed var(--brand-primary);
      color: var(--brand-primary);
      padding: 12px;
      border-radius: var(--radius-lg);
      font-size: 13px;
      font-weight: 600;
      text-align: center;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }

    /* Fixed Bottom Footer */
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
      grid-template-columns: 1fr 1.2fr;
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
    .btn-batch-action.secondary {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      color: var(--text-secondary);
    }
    .btn-batch-action.primary {
      background: linear-gradient(135deg, var(--brand-primary), #1d4ed8);
      color: white;
    }
    .btn-batch-action.success {
      background: linear-gradient(135deg, var(--status-success), #15803d);
      color: white;
    }
    .btn-batch-action.pulse {
      box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7);
      animation: pulseGlow 1.6s infinite;
    }
    .btn-batch-action:disabled {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      color: var(--text-tertiary);
      cursor: not-allowed;
      background-image: none;
      animation: none;
    }

    /* Fullscreen Loading Overlay */
    .loading-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(15, 23, 42, 0.95);
      z-index: 1000;
      display: none;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: white;
      padding: 24px;
      text-align: center;
      backdrop-filter: blur(10px);
    }
    .scanner-animation-wrap {
      width: 100px;
      height: 100px;
      border: 2px solid var(--brand-primary);
      border-radius: var(--radius-lg);
      position: relative;
      overflow: hidden;
      margin-bottom: 24px;
      background: rgba(14, 165, 233, 0.05);
    }
    .scanner-line {
      position: absolute;
      width: 100%;
      height: 2px;
      background: linear-gradient(90deg, transparent, var(--brand-primary), transparent);
      top: 0;
      animation: scanEffect 2s linear infinite;
      box-shadow: 0 0 8px var(--brand-primary);
    }
    .scanner-grid {
      position: absolute;
      top: 0; left: 0; width: 100%; height: 100%;
      background-image: linear-gradient(rgba(14, 165, 233, 0.1) 1px, transparent 1px),
                        linear-gradient(90deg, rgba(14, 165, 233, 0.1) 1px, transparent 1px);
      background-size: 10px 10px;
    }
    .loading-title {
      font-size: 18px;
      font-weight: 700;
      margin-bottom: 8px;
      background: linear-gradient(135deg, #f8fafc, #94a3b8);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    .loading-sub {
      font-size: 13px;
      color: var(--text-secondary);
      line-height: 1.5;
    }

    @keyframes scanEffect {
      0% { top: 0%; }
      50% { top: 100%; }
      100% { top: 0%; }
    }
    @keyframes pulseGlow {
      0% {
        transform: scale(0.98);
        box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.5);
      }
      70% {
        transform: scale(1);
        box-shadow: 0 0 0 10px rgba(37, 99, 235, 0);
      }
      100% {
        transform: scale(0.98);
        box-shadow: 0 0 0 0 rgba(37, 99, 235, 0);
      }
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
    
    <!-- Header -->
    <div class="mobile-header">
      <a href="{{ route('mobile-equipment.index') }}" class="btn-back" id="btnHeaderBack">
        <i class="ph ph-caret-left"></i>
      </a>
      <h1 class="mobile-title">AI 장비/자재 종합 분석</h1>
      <div style="width: 36px"></div>
    </div>

    <!-- Steps status bar -->
    <div class="step-indicator">
      <div class="step-item active" id="step1Indicator">
        <div class="step-circle">1</div>
        <span>사진 등록</span>
      </div>
      <div class="step-item" id="step2Indicator">
        <div class="step-circle">2</div>
        <span>장비 분석</span>
      </div>
      <div class="step-item" id="step3Indicator">
        <div class="step-circle">3</div>
        <span>일괄 저장</span>
      </div>
    </div>

    <!-- 1단계: 사진 촬영 / 대기열 영역 -->
    <div class="queue-panel" id="photoQueueSection">
      <div class="tip-box">
        <i class="ph ph-lightbulb tip-icon"></i>
        <span>
          <strong>종합 스캔 팁:</strong> 컨테이너 자재창고의 서로 다른 공간과 도구 묶음을 여러 각도에서 찍어주세요. AI가 여러 장의 사진 데이터를 취합하여 중복을 거르고 현장 도구 및 자재들을 개별 자산으로 똑똑하게 추출해 줍니다.
        </span>
      </div>

      <div class="queue-grid" id="queuePhotoGrid">
        <!-- Thumbnail previews will go here -->
      </div>

      <div class="empty-state" id="queueEmptyState">
        <i class="ph ph-camera-rotate empty-icon" style="color:var(--brand-primary)"></i>
        <span style="font-weight:600;">등록된 사진이 없습니다.</span>
        <span style="font-size:11px; color:var(--text-secondary);">아래 카메라 촬영 버튼을 눌러 현장 사진을 여러 장 추가해 주세요.</span>
      </div>

      <input type="file" id="queueFileInput" accept="image/*" capture="environment" style="display:none" onchange="handleAddPhoto(event)" multiple>
      
      <div class="floating-footer" id="step1Footer">
        <button type="button" class="btn-batch-action secondary" onclick="document.getElementById('queueFileInput').click()">
          <i class="ph ph-camera"></i>
          <span>📷 사진 촬영/추가</span>
        </button>
        <button type="button" class="btn-batch-action primary pulse" id="btnStartAnalysis" onclick="startJointAnalysis()" disabled>
          <i class="ph ph-sparkle"></i>
          <span>AI 종합 분석 시작</span>
        </button>
      </div>
    </div>

    <!-- 2단계: 분석 결과 편집 및 일괄 등록 (초기 숨김) -->
    <form id="batchForm" action="{{ route('mobile-equipment.store-batch') }}" method="POST" style="display:none; flex-direction:column; gap:16px;">
      @csrf

      <!-- Shared Common Fields -->
      <div class="shared-info-card">
        <div class="shared-info-title">
          <i class="ph ph-sliders"></i>
          <span>배치 기본 배정 정보</span>
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
        <!-- AI detected equipment cards will be generated here -->
      </div>

      <!-- Manual Item Add Button -->
      <button type="button" class="btn-manual-add" onclick="addNewManualItem()">
        <i class="ph ph-plus-bold"></i>
        <span>수동으로 항목 추가하기</span>
      </button>

      <!-- Fixed Bottom Action Footer for Step 2 -->
      <div class="floating-footer" id="step2Footer">
        <button type="button" class="btn-batch-action secondary" onclick="goBackToStep1()">
          <i class="ph ph-arrow-left"></i>
          <span>사진 재추가</span>
        </button>
        <button type="button" class="btn-batch-action success" id="btnSaveBatch" onclick="submitBatchForm()">
          <i class="ph ph-floppy-disk"></i>
          <span id="saveBtnText">총 0건 일괄 등록</span>
        </button>
      </div>

    </form>
  </div>

  <!-- Fullscreen Loading Overlay -->
  <div class="loading-overlay" id="loadingOverlay">
    <div class="scanner-animation-wrap">
      <div class="scanner-grid"></div>
      <div class="scanner-line"></div>
    </div>
    <div class="loading-title" id="loadingStatusText">이미지 전송 중...</div>
    <div class="loading-sub">AI가 사진에 찍힌 모든 장비와 자재를 식별하고 고유 특성을 일괄 리스트로 분석 중입니다.<br>잠시만 기다려 주세요 (약 10~20초 소요).</div>
  </div>

  <script>
    let pendingFiles = [];
    let cardCounter = 0;

    function handleAddPhoto(event) {
      const files = event.target.files;
      if (!files || files.length === 0) return;

      const grid = document.getElementById('queuePhotoGrid');
      const empty = document.getElementById('queueEmptyState');
      empty.style.display = 'none';

      for (let i = 0; i < files.length; i++) {
        const file = files[i];
        pendingFiles.push(file);
        const fileIndex = pendingFiles.length - 1;

        const imageUrl = URL.createObjectURL(file);
        
        // Add card template to queue grid
        const photoHtml = `
          <div class="queue-card" id="photo-card-${fileIndex}">
            <button type="button" class="btn-delete-photo" onclick="deletePhotoFromQueue(${fileIndex})">
              <i class="ph ph-x-bold"></i>
            </button>
            <img src="${imageUrl}" class="queue-img">
          </div>
        `;
        grid.insertAdjacentHTML('beforeend', photoHtml);
      }

      updateAnalysisButtonState();
      event.target.value = '';
    }

    function deletePhotoFromQueue(index) {
      const card = document.getElementById(`photo-card-${index}`);
      if (card) {
        card.remove();
      }
      
      // Mark file as null to preserve indexes
      pendingFiles[index] = null;

      // Check if all files are null
      const activeFiles = pendingFiles.filter(f => f !== null);
      if (activeFiles.length === 0) {
        document.getElementById('queueEmptyState').style.display = 'flex';
        pendingFiles = [];
      }

      updateAnalysisButtonState();
    }

    function updateAnalysisButtonState() {
      const activeFiles = pendingFiles.filter(f => f !== null);
      const btn = document.getElementById('btnStartAnalysis');
      if (activeFiles.length > 0) {
        btn.disabled = false;
        btn.classList.add('pulse');
      } else {
        btn.disabled = true;
        btn.classList.remove('pulse');
      }
    }

    async function startJointAnalysis() {
      const activeFiles = pendingFiles.filter(f => f !== null);
      if (activeFiles.length === 0) return;

      const overlay = document.getElementById('loadingOverlay');
      const statusText = document.getElementById('loadingStatusText');
      overlay.style.display = 'flex';
      statusText.textContent = "이미지 데이터를 압축 및 전송 중...";

      const formData = new FormData();
      activeFiles.forEach(file => {
        formData.append('photos[]', file);
      });

      try {
        statusText.textContent = "AI가 자재 및 장비를 일괄 감지 분석 중...";
        
        const response = await fetch("{{ route('mobile-equipment.scan-photos-batch') }}", {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
          },
          body: formData
        });

        if (!response.ok) {
          throw new Error('서버 분석 처리 실패 (상태 코드: ' + response.status + ')');
        }

        const result = await response.json();

        if (result.success) {
          // Process and render items
          const items = result.items || [];
          renderAnalysisResultBoard(items);
        } else {
          alert('AI 장비 분석 실패: ' + (result.error || '알 수 없는 이유'));
        }
      } catch (err) {
        alert('서버 분석 중 오류가 발생했습니다: ' + err.message);
      } finally {
        overlay.style.display = 'none';
      }
    }

    function renderAnalysisResultBoard(items) {
      // Step state updates
      document.getElementById('step1Indicator').className = 'step-item completed';
      document.getElementById('step2Indicator').className = 'step-item active';
      
      // Hide Step 1 panel, Show Step 2 Form
      document.getElementById('photoQueueSection').style.display = 'none';
      const form = document.getElementById('batchForm');
      form.style.display = 'flex';
      
      // Reset and build list
      const container = document.getElementById('batchItemsList');
      container.innerHTML = '';
      cardCounter = 0;

      if (items.length === 0) {
        // Generate a single blank manual card if AI returned empty
        addNewManualItem();
      } else {
        items.forEach(item => {
          addNewItemCard(item);
        });
      }

      updateBatchCounts();
      window.scrollTo(0, 0);
    }

    function addNewItemCard(data) {
      cardCounter++;
      const cardId = cardCounter;
      const container = document.getElementById('batchItemsList');

      const imageUrl = data.photo_path || '/images/nahshon-app-icon.svg';
      const type = data.equipment_type || 'Other (기타)';
      const vendor = data.vendor || '';
      const model = data.model || '';
      const quantity = data.quantity || 1;
      const isBulk = data.is_bulk === true;

      const cardHtml = `
        <div class="batch-card" id="card-${cardId}">
          <button type="button" class="btn-remove-card" onclick="removeCard('${cardId}')">
            <i class="ph ph-trash"></i>
          </button>
          
          <div class="batch-card-body">
            <div class="batch-card-img-wrap">
              <img src="${imageUrl}" class="batch-card-img">
            </div>
            
            <div class="batch-card-fields">
              <input type="hidden" name="items[${cardId}][photo_front]" value="${imageUrl}">
              
              <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 8px;">
                <div class="form-group-sm">
                  <label class="form-label-sm">분류</label>
                  <select name="items[${cardId}][equipment_type]" id="type-${cardId}" class="input-text-sm" style="background-color: var(--bg-surface-elevated);">
                    <option value="Generator (발전기)" ${type.includes('발전기') || type.toLowerCase().includes('generator') ? 'selected' : ''}>Generator (발전기)</option>
                    <option value="Welding Machine (용접기)" ${type.includes('용접기') || type.toLowerCase().includes('welding') ? 'selected' : ''}>Welding Machine (용접기)</option>
                    <option value="Power Tool (전동공구)" ${type.includes('전동공구') || type.toLowerCase().includes('power') ? 'selected' : ''}>Power Tool (전동공구)</option>
                    <option value="Hand Tool (수공구)" ${type.includes('수공구') || type.toLowerCase().includes('hand') ? 'selected' : ''}>Hand Tool (수공구)</option>
                    <option value="Forklift (지게차)" ${type.includes('지게차') || type.toLowerCase().includes('forklift') ? 'selected' : ''}>Forklift (지게차)</option>
                    <option value="Boom Lift (고소작업대)" ${type.includes('고소작업대') || type.toLowerCase().includes('boom') ? 'selected' : ''}>Boom Lift (고소작업대)</option>
                    <option value="Excavator (굴착기)" ${type.includes('굴착기') || type.toLowerCase().includes('excavator') ? 'selected' : ''}>Excavator (굴착기)</option>
                    <option value="Skid Steer (스키드로더)" ${type.includes('스키드로더') || type.toLowerCase().includes('skid') ? 'selected' : ''}>Skid Steer (스키드로더)</option>
                    <option value="Compressor (콤프레샤)" ${type.includes('콤프레샤') || type.toLowerCase().includes('compressor') ? 'selected' : ''}>Compressor (콤프레샤)</option>
                    <option value="Other (기타)" ${type.includes('기타') || type.toLowerCase().includes('other') ? 'selected' : ''}>Other (기타)</option>
                  </select>
                </div>
                <div class="form-group-sm">
                  <label class="form-label-sm">제조사</label>
                  <input type="text" name="items[${cardId}][vendor]" id="vendor-${cardId}" class="input-text-sm" value="${vendor}" placeholder="예: Honda">
                </div>
              </div>
              
              <div class="form-group-sm" style="margin-top: 2px;">
                <label class="form-label-sm">모델명/설명 <span style="color:var(--status-danger)">*</span></label>
                <input type="text" name="items[${cardId}][model]" id="model-${cardId}" class="input-text-sm" value="${model}" placeholder="모델명 또는 상세설명" required>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; align-items: center; margin-top: 2px;">
                <div class="form-group-sm">
                  <label class="form-label-sm">수량</label>
                  <input type="number" name="items[${cardId}][quantity]" id="quantity-${cardId}" class="input-text-sm" value="${quantity}" min="1" required>
                </div>
                <div style="display: flex; align-items: center; gap: 4px; margin-top: 14px; cursor: pointer;">
                  <input type="checkbox" name="items[${cardId}][is_bulk]" id="bulk-${cardId}" value="on" ${isBulk ? 'checked' : ''} style="width: 15px; height: 15px; accent-color: var(--brand-primary); cursor: pointer;">
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

      container.insertAdjacentHTML('beforeend', cardHtml);

      // Verify category select is fallback correctly if not selected above
      const selectEl = document.getElementById(`type-${cardId}`);
      if (selectEl && selectEl.selectedIndex === -1) {
        selectEl.value = "Other (기타)";
      }
    }

    function addNewManualItem() {
      addNewItemCard({
        equipment_type: 'Other (기타)',
        vendor: '',
        model: '',
        quantity: 1,
        is_bulk: false,
        photo_path: '/images/nahshon-app-icon.svg'
      });
      updateBatchCounts();
    }

    function removeCard(cardId) {
      const card = document.getElementById('card-' + cardId);
      if (card) {
        card.remove();
      }
      updateBatchCounts();
    }

    function goBackToStep1() {
      // Step state updates
      document.getElementById('step1Indicator').className = 'step-item active';
      document.getElementById('step2Indicator').className = 'step-item';
      
      // Hide Step 2 Form, Show Step 1 Panel
      document.getElementById('batchForm').style.display = 'none';
      document.getElementById('photoQueueSection').style.display = 'flex';
      
      window.scrollTo(0, 0);
    }

    function updateBatchCounts() {
      const container = document.getElementById('batchItemsList');
      const count = container.children.length;
      const btnSave = document.getElementById('btnSaveBatch');
      const saveText = document.getElementById('saveBtnText');
      
      if (count > 0) {
        btnSave.disabled = false;
        saveText.textContent = `총 ${count}건 일괄 등록`;
        document.getElementById('step3Indicator').className = 'step-item active';
      } else {
        btnSave.disabled = true;
        saveText.textContent = '등록할 장비 없음';
        document.getElementById('step3Indicator').className = 'step-item';
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
        alert('모델명이 빈칸인 품목이 있습니다. 모델명 또는 간략한 설명(예: 드라이월 나사)을 기입해 주세요.');
        return;
      }
      
      document.getElementById('batchForm').submit();
    }
  </script>
</body>
</html>

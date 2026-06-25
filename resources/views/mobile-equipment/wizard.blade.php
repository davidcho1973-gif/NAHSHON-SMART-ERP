<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>장비 등록 - SMART COMPANY</title>
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
    .step-bar {
      display: flex;
      gap: 4px;
      background: var(--bg-surface);
      padding: 6px;
      border-radius: 20px;
      border: 1px solid var(--border-subtle);
    }
    .step-dot {
      flex: 1;
      height: 4px;
      background: var(--border-subtle);
      border-radius: 2px;
      transition: background-color 0.3s ease;
    }
    .step-dot.active {
      background: var(--brand-primary);
    }
    .wizard-step {
      display: none;
      flex-direction: column;
      gap: 16px;
      animation: fadeIn 0.3s ease-in-out;
    }
    .wizard-step.active {
      display: flex;
    }
    .step-header {
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin-bottom: 8px;
    }
    .step-title {
      font-size: 20px;
      font-weight: 700;
    }
    .step-subtitle {
      font-size: 13px;
      color: var(--text-secondary);
    }
    .upload-area {
      border: 2px dashed var(--border-strong);
      border-radius: var(--radius-lg);
      padding: 32px 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      cursor: pointer;
      background: var(--bg-surface);
      text-align: center;
      transition: border-color 0.2s ease;
      position: relative;
      overflow: hidden;
    }
    .upload-area:hover {
      border-color: var(--brand-primary);
    }
    .upload-icon {
      font-size: 40px;
      color: var(--text-secondary);
    }
    .upload-label {
      font-size: 14px;
      font-weight: 600;
    }
    .upload-sub {
      font-size: 11px;
      color: var(--text-tertiary);
    }
    .upload-preview {
      display: none;
      width: 100%;
      max-height: 320px;
      object-fit: contain;
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      background: var(--bg-base);
    }
    .upload-preview.visible {
      display: block;
    }
    .upload-area.has-preview {
      padding: 14px;
      border-style: solid;
      align-items: stretch;
    }
    .upload-area.has-preview .upload-icon {
      display: none;
    }
    .scanner-bar {
      position: absolute;
      left: 0; right: 0;
      height: 4px;
      background: linear-gradient(90deg, transparent, var(--brand-primary), transparent);
      box-shadow: 0 0 10px var(--brand-primary);
      animation: scan 2s linear infinite;
      display: none;
    }
    @keyframes scan {
      0% { top: 0%; }
      50% { top: 100%; }
      100% { top: 0%; }
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
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
      background: var(--bg-surface);
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
    .wizard-footer {
      display: flex;
      gap: 8px;
      margin-top: auto;
      padding: 16px 0;
    }
    .btn-wz-prev {
      flex: 1;
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      color: var(--text-primary);
      padding: 14px;
      border-radius: var(--radius-lg);
      font-weight: 600;
    }
    .btn-wz-next {
      flex: 2;
      background: var(--brand-primary);
      color: white;
      padding: 14px;
      border-radius: var(--radius-lg);
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .btn-manual-skip {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      color: var(--text-primary);
      padding: 14px;
      border-radius: var(--radius-lg);
      font-size: 13px;
      font-weight: 600;
      text-align: center;
      cursor: pointer;
      margin-top: 8px;
    }
  </style>
</head>
<body>
  <div class="mobile-container">
    
    <div class="mobile-header">
      <a href="{{ route('mobile-equipment.index') }}" class="btn-back">
        <i class="ph ph-caret-left"></i>
      </a>
      <h1 class="mobile-title">AI 장비 스캔등록</h1>
      <div style="width: 36px"></div>
    </div>

    <!-- Multi-step Indicator Bar -->
    <div class="step-bar">
      <div class="step-dot active" id="dot-1"></div>
      <div class="step-dot" id="dot-2"></div>
    </div>

    <form id="wizardForm" action="{{ route('mobile-equipment.store') }}" method="POST" style="display:flex; flex-direction:column; flex:1; gap:16px;">
      @csrf
      
      <!-- Hidden AI outputs -->
      <input type="hidden" name="photo_front" id="photoFront">
      <input type="hidden" name="ocr_data" id="ocrData">

      <!-- STEP 1: Capture & AI Scan -->
      <div class="wizard-step active" id="step-1">
        <div class="step-header">
          <span class="step-title">장비 촬영 (Camera)</span>
          <span class="step-subtitle">장비 사진을 찍으면 AI가 분류와 모델명을 분석합니다.</span>
        </div>
        <div class="upload-area" onclick="document.getElementById('photoFileInput').click()" id="uploadContainer">
          <div class="scanner-bar" id="scannerBar"></div>
          <img class="upload-preview" id="photoUploadPreview" src="" alt="Equipment preview">
          <i class="ph ph-camera-bold upload-icon" id="uploadAreaIcon"></i>
          <span class="upload-label" id="uploadAreaLabel">여기를 눌러 카메라 촬영 / 업로드</span>
          <span class="upload-sub" id="uploadAreaSub">핸드폰 카메라로 직접 찍어올리세요</span>
          <!-- capture="environment" launches the back camera on mobile directly -->
          <input type="file" id="photoFileInput" accept="image/*" capture="environment" style="display:none" onchange="handlePhotoUpload(event)">
        </div>
        <div class="btn-manual-skip" onclick="goNextStep()">사진 없이 직접 기입하기</div>
      </div>

      <!-- STEP 2: Verify details & Assignment -->
      <div class="wizard-step" id="step-2">
        <div class="step-header">
          <span class="step-title">장비 정보 확인</span>
          <span class="step-subtitle">AI가 판독한 내용입니다. 필요 시 수정하세요.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="typeSelect">장비 분류 (Type)</label>
          <select name="equipment_type" id="typeSelect" class="input-text" style="background-color: var(--bg-surface);">
            <option value="Generator (발전기)">Generator (발전기)</option>
            <option value="Welding Machine (용접기)">Welding Machine (용접기)</option>
            <option value="Power Tool (전동공구)">Power Tool (전동공구)</option>
            <option value="Hand Tool (수공구)">Hand Tool (수공구)</option>
            <option value="Forklift (지게차)">Forklift (지게차)</option>
            <option value="Boom Lift (고소작업대)">Boom Lift (고소작업대)</option>
            <option value="Excavator (굴착기)">Excavator (굴착기)</option>
            <option value="Skid Steer (스키드로더)">Skid Steer (스키드로더)</option>
            <option value="Compressor (콤프레샤)">Compressor (콤프레샤)</option>
            <option value="Other (기타)">Other (기타)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="modelInput">모델명 (Model) <span style="color:var(--status-danger)">*</span></label>
          <input type="text" name="model" id="modelInput" class="input-text" placeholder="예: Honda EU2200i" required>
        </div>

        <div class="form-group">
          <label class="form-label" for="vendorInput">제조사 / 브랜드 (Brand)</label>
          <input type="text" name="vendor" id="vendorInput" class="input-text" placeholder="예: Honda, Makita, Bosch">
        </div>

        <div class="form-group">
          <label class="form-label" for="quantityInput">등록 수량 (Quantity) <span style="color:var(--status-danger)">*</span></label>
          <input type="number" name="quantity" id="quantityInput" class="input-text" value="1" min="1" required>
        </div>

        <div class="form-group" style="flex-direction:row; align-items:center; gap:8px; margin: 4px 0;">
          <input type="checkbox" name="is_bulk" id="isBulkInput" style="width:20px; height:20px; accent-color:var(--brand-primary); cursor:pointer;">
          <label class="form-label" for="isBulkInput" style="text-transform:none; font-size:12px; color:var(--text-primary); cursor:pointer; font-weight:600; user-select:none;">
            대량 자재/소모품 등록 (체크 시 단일 항목에 수량 합산 저장)
          </label>
        </div>

        <div class="form-group">
          <label class="form-label" for="statusSelect">장비 상태 (Status) <span style="color:var(--status-danger)">*</span></label>
          <select name="status" id="statusSelect" class="input-text" style="background-color: var(--bg-surface);">
            <option value="대기중" selected>대기중 (배정가능)</option>
            <option value="사용중">사용중 (현장가동)</option>
            <option value="정비중">정비중 (수리필요)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="siteSelect">배정 현장 (Site)</label>
          <select name="site_id" id="siteSelect" class="input-text" style="background-color: var(--bg-surface);">
            <option value="">지정 안함 (Global / Office)</option>
            @foreach($sites as $site)
              <option value="{{ $site->id }}" @selected((int) ($selectedSiteId ?? 0) === (int) $site->id)>{{ $site->code }} - {{ $site->name }}</option>
            @endforeach
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="teamSelect">배정 팀 (Team)</label>
          <select name="team_id" id="teamSelect" class="input-text" style="background-color: var(--bg-surface);">
            <option value="">지정 안함</option>
            @foreach($teams as $team)
              <option value="{{ $team->id }}">{{ $team->name }} ({{ $team->site ? $team->site->code : 'Global' }})</option>
            @endforeach
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="employeeSelect">담당자 / 운영자 (Operator)</label>
          <select name="employee_id" id="employeeSelect" class="input-text" style="background-color: var(--bg-surface);">
            <option value="">지정 안함</option>
            @foreach($employees as $employee)
              <option value="{{ $employee->id }}">{{ $employee->name }} ({{ $employee->company ? $employee->company->name : '-' }})</option>
            @endforeach
          </select>
        </div>
      </div>

      <!-- Wizard Navigation Footer -->
      <div class="wizard-footer">
        <button type="button" class="btn-wz-prev" id="btnPrev" style="display:none" onclick="goPrevStep()">이전</button>
        <button type="button" class="btn-wz-next" id="btnNext" onclick="handleNextClick()">다음</button>
      </div>

    </form>
  </div>

  <script>
    let currentStep = 1;
    const totalSteps = 2;
    let photoPreviewObjectUrl = null;

    function goNextStep() {
      if (currentStep < totalSteps) {
        document.getElementById('step-' + currentStep).classList.remove('active');
        document.getElementById('dot-' + currentStep).classList.remove('active');
        currentStep++;
        document.getElementById('step-' + currentStep).classList.add('active');
        document.getElementById('dot-' + currentStep).classList.add('active');
        
        // Show/hide buttons
        document.getElementById('btnPrev').style.display = 'block';
        document.getElementById('btnNext').textContent = '최종 등록하기';
      }
    }

    function goPrevStep() {
      if (currentStep > 1) {
        document.getElementById('step-' + currentStep).classList.remove('active');
        document.getElementById('dot-' + currentStep).classList.remove('active');
        currentStep--;
        document.getElementById('step-' + currentStep).classList.add('active');
        document.getElementById('dot-' + currentStep).classList.add('active');
        
        // Show/hide buttons
        document.getElementById('btnPrev').style.display = 'none';
        document.getElementById('btnNext').textContent = '다음';
      }
    }

    function handleNextClick() {
      if (currentStep === 1) {
        // Must upload a photo first if not skipped manually via skip button
        const photoPath = document.getElementById('photoFront').value;
        if (!photoPath) {
          alert('장비 사진 촬영이 완료되지 않았습니다.\n사진을 찍으시거나 "사진 없이 직접 기입하기"를 누르세요.');
          return;
        }
        goNextStep();
      } else {
        // Validate required fields
        const model = document.getElementById('modelInput').value.trim();
        if (!model) {
          alert('모델명을 입력해 주세요.');
          return;
        }
        // Submit form
        document.getElementById('wizardForm').submit();
      }
    }

    function showPhotoUploadPreview(file) {
      const preview = document.getElementById('photoUploadPreview');
      const container = document.getElementById('uploadContainer');

      if (!file || !file.type.startsWith('image/')) {
        preview.removeAttribute('src');
        preview.classList.remove('visible');
        container.classList.remove('has-preview');
        return;
      }

      if (photoPreviewObjectUrl) {
        URL.revokeObjectURL(photoPreviewObjectUrl);
      }

      photoPreviewObjectUrl = URL.createObjectURL(file);
      preview.src = photoPreviewObjectUrl;
      preview.classList.add('visible');
      container.classList.add('has-preview');
    }

    async function handlePhotoUpload(event) {
      const file = event.target.files[0];
      if (!file) return;

      const container = document.getElementById('uploadContainer');
      const scanner = document.getElementById('scannerBar');
      const icon = document.getElementById('uploadAreaIcon');
      const label = document.getElementById('uploadAreaLabel');
      const sub = document.getElementById('uploadAreaSub');

      showPhotoUploadPreview(file);

      // Start scanner animation
      scanner.style.display = 'block';
      icon.className = 'ph ph-circle-notch upload-icon';
      icon.style.animation = 'spin 1s linear infinite';
      label.textContent = 'AI 장비 분석중...';
      sub.textContent = 'Gemini가 장비 실물 모델명과 종류를 추출하고 있습니다.';

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
          document.getElementById('photoFront').value = result.photo_path;
          document.getElementById('ocrData').value = JSON.stringify(data);

          // Populate fields
          if (data.model) {
            document.getElementById('modelInput').value = data.model;
          }
          if (data.vendor) {
            document.getElementById('vendorInput').value = data.vendor;
          }
          if (data.equipment_type) {
            // Find closest option in select
            const typeSelect = document.getElementById('typeSelect');
            let found = false;
            for (let i = 0; i < typeSelect.options.length; i++) {
              if (typeSelect.options[i].value.toLowerCase().includes(data.equipment_type.toLowerCase()) || 
                  data.equipment_type.toLowerCase().includes(typeSelect.options[i].value.toLowerCase())) {
                typeSelect.selectedIndex = i;
                found = true;
                break;
              }
            }
            if (!found) {
              typeSelect.value = "Other (기타)";
            }
          }

          label.textContent = 'AI 판독 완료';
          sub.textContent = '아래 "다음" 버튼을 눌러 확인하세요.';
          
          // Auto advance step
          setTimeout(goNextStep, 800);
        } else {
          alert('AI 장비 분석 실패: ' + result.error);
        }
      } catch (err) {
        alert('서버 오류: ' + err.message);
      } finally {
        // Reset upload UI
        scanner.style.display = 'none';
        icon.className = 'ph ph-camera-bold upload-icon';
        icon.style.animation = 'none';
        if (!document.getElementById('photoFront').value) {
          label.textContent = '여기를 눌러 카메라 촬영 / 업로드';
          sub.textContent = '핸드폰 카메라로 직접 찍어올리세요';
        }
      }
    }
  </script>
</body>
</html>

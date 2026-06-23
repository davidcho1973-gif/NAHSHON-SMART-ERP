<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>경비 등록 위저드 - SMART COMPANY</title>
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
    .option-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 12px;
    }
    .option-card {
      background: var(--bg-surface);
      border: 2px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 20px;
      display: flex;
      align-items: center;
      gap: 16px;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .option-card:hover, .option-card.selected {
      border-color: var(--brand-primary);
      background: var(--bg-surface-elevated);
    }
    .option-icon {
      font-size: 32px;
      color: var(--brand-primary);
    }
    .option-text {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .option-title {
      font-size: 15px;
      font-weight: 700;
    }
    .option-desc {
      font-size: 12px;
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
    /* Keypad styles */
    .keypad-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      max-width: 320px;
      margin: 0 auto;
    }
    .keypad-btn {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      color: var(--text-primary);
      height: 60px;
      border-radius: var(--radius-lg);
      font-size: 20px;
      font-weight: 600;
      font-family: var(--font-base);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background-color 0.1s ease;
    }
    .keypad-btn:active {
      background: var(--bg-surface-elevated);
    }
    .keypad-btn.action {
      background: var(--bg-surface-elevated);
      font-size: 16px;
    }
    .amount-display {
      font-size: 32px;
      font-weight: 700;
      font-family: var(--font-mono);
      text-align: right;
      padding: 16px;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      margin-bottom: 8px;
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
    .category-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
    }
    .cat-btn {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      color: var(--text-secondary);
      padding: 14px 8px;
      border-radius: var(--radius-md);
      font-size: 12px;
      font-weight: 600;
      text-align: center;
    }
    .cat-btn.selected {
      border-color: var(--brand-primary);
      background: var(--brand-primary-dim);
      color: var(--text-primary);
    }
    .quick-adjust-row {
      display: flex;
      gap: 6px;
      margin-bottom: 12px;
      justify-content: flex-end;
    }
    .quick-adj-btn {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-subtle);
      color: var(--text-secondary);
      padding: 6px 12px;
      font-size: 11px;
      font-weight: 700;
      border-radius: 4px;
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
    .success-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 24px;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 16px;
    }
    .success-icon {
      font-size: 56px;
      color: var(--status-success);
    }
  </style>
</head>
<body>
  <div class="mobile-container">
    
    <div class="mobile-header">
      <a href="{{ route('mobile-expense.index') }}" class="btn-back">
        <i class="ph ph-caret-left"></i>
      </a>
      <h1 class="mobile-title">AI 경비 등록 위저드</h1>
      <div style="width: 36px"></div>
    </div>

    <!-- Multi-step Indicator Bar -->
    <div class="step-bar">
      <div class="step-dot active" id="dot-1"></div>
      <div class="step-dot" id="dot-2"></div>
      <div class="step-dot" id="dot-3"></div>
      <div class="step-dot" id="dot-4"></div>
      <div class="step-dot" id="dot-5"></div>
      <div class="step-dot" id="dot-6"></div>
      <div class="step-dot" id="dot-7"></div>
    </div>

    <form id="wizardForm" action="{{ route('mobile-expense.store') }}" method="POST" style="display:flex; flex-direction:column; flex:1; gap:16px;">
      @csrf
      
      <!-- Hidden OCR inputs and parameters -->
      <input type="hidden" name="receipt_path" id="receiptPath">
      <input type="hidden" name="ocr_data" id="ocrData">
      <input type="hidden" name="payment_type" id="paymentType" value="personal">

      <!-- STEP 1: Payment Type -->
      <div class="wizard-step active" id="step-1">
        <div class="step-header">
          <span class="step-title">결제 방법 선택</span>
          <span class="step-subtitle">어떻게 경비를 결제하셨나요?</span>
        </div>
        <div class="option-grid">
          <div class="option-card selected" onclick="selectPaymentType('personal', this)">
            <i class="ph ph-user option-icon"></i>
            <div class="option-text">
              <span class="option-title">개인 지출 (환급)</span>
              <span class="option-desc">개인 자금으로 우선 결제하고 차후 환급받습니다.</span>
            </div>
          </div>
          <div class="option-card" onclick="selectPaymentType('corporate', this)">
            <i class="ph ph-credit-card option-icon"></i>
            <div class="option-text">
              <span class="option-title">법인카드 지출</span>
              <span class="option-desc">회사 명의의 법인카드로 결제했습니다.</span>
            </div>
          </div>
        </div>
      </div>

      <!-- STEP 2: Receipt Upload & OCR -->
      <div class="wizard-step" id="step-2">
        <div class="step-header">
          <span class="step-title">영수증 업로드</span>
          <span class="step-subtitle">AI가 영수증을 분석해 자동으로 항목을 채워줍니다.</span>
        </div>
        <div class="upload-area" onclick="document.getElementById('receiptFileInput').click()" id="uploadContainer">
          <div class="scanner-bar" id="scannerBar"></div>
          <i class="ph ph-receipt-bold upload-icon" id="uploadAreaIcon"></i>
          <span class="upload-label" id="uploadAreaLabel">사진 촬영 또는 파일 올리기</span>
          <span class="upload-sub" id="uploadAreaSub">JPG, PNG, PDF 최대 10MB</span>
          <input type="file" id="receiptFileInput" accept="image/*,application/pdf" style="display:none" onchange="handleReceiptUpload(event)">
        </div>
        <div class="btn-manual-skip" onclick="goNextStep()">영수증 없이 직접 입력하기</div>
      </div>

      <!-- STEP 3: Category -->
      <div class="wizard-step" id="step-3">
        <div class="step-header">
          <span class="step-title">지출 카테고리</span>
          <span class="step-subtitle">경비 항목에 맞는 분류를 선택해 주세요.</span>
        </div>
        <input type="hidden" name="category" id="categoryInput" value="Other">
        <div class="category-grid">
          <button type="button" class="cat-btn" onclick="selectCategory('Computer & Software', this)">Computer & Software</button>
          <button type="button" class="cat-btn" onclick="selectCategory('Automobile Expense', this)">Automobile Expense</button>
          <button type="button" class="cat-btn" onclick="selectCategory('Utilities', this)">Utilities</button>
          <button type="button" class="cat-btn" onclick="selectCategory('Travel & Lodging', this)">Travel & Lodging</button>
          <button type="button" class="cat-btn" onclick="selectCategory('Office Supplies', this)">Office Supplies</button>
          <button type="button" class="cat-btn" onclick="selectCategory('Meals & Entertainment', this)">Meals & Entertainment</button>
          <button type="button" class="cat-btn selected" onclick="selectCategory('Other', this)">Other</button>
        </div>
      </div>

      <!-- STEP 4: Class & Site -->
      <div class="wizard-step" id="step-4">
        <div class="step-header">
          <span class="step-title">현장 및 부서 지정</span>
          <span class="step-subtitle">경비가 발생한 현장 또는 부서를 지정해 주세요.</span>
        </div>
        <div class="form-group">
          <label class="form-label" for="siteSelect">현장 코드 (Site)</label>
          <select name="site_id" id="siteSelect" class="input-text" style="background-color: var(--bg-surface);">
            <option value="">지정 안함 (Global / Office)</option>
            @foreach($sites as $site)
              <option value="{{ $site->id }}" @selected((int) ($selectedSiteId ?? 0) === (int) $site->id)>{{ $site->code }} - {{ $site->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="preApprovalSelect">승인된 사전 예산과 연결</label>
          <select name="expense_pre_approval_id" id="preApprovalSelect" class="input-text" style="background-color: var(--bg-surface);">
            <option value="">연결 안함</option>
            @foreach($preApprovals as $preApproval)
              <option value="{{ $preApproval->id }}">
                {{ $preApproval->title }} - ${{ number_format($preApproval->estimated_amount, 2) }}
              </option>
            @endforeach
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="classInput">부서 / 클래스 (Class)</label>
          <input type="text" name="class" id="classInput" class="input-text" placeholder="예: HR, SALES, WBS-01">
        </div>
      </div>

      <!-- STEP 5: Description -->
      <div class="wizard-step" id="step-5">
        <div class="step-header">
          <span class="step-title">경비 세부 내용</span>
          <span class="step-subtitle">지출 명목과 세부 내역을 적어주세요.</span>
        </div>
        <div class="form-group">
          <label class="form-label" for="descInput">지출 사유 / 상세 설명</label>
          <textarea name="description" id="descInput" class="input-text" style="height: 120px; resize:none" placeholder="예: 현장 사무용품 구매 (용지 및 볼펜)"></textarea>
        </div>
      </div>

      <!-- STEP 6: Amount with Keypad -->
      <div class="wizard-step" id="step-6">
        <div class="step-header">
          <span class="step-title">지출 금액 입력</span>
          <span class="step-subtitle">실제 영수증의 최종 청구 금액을 입력해 주세요.</span>
        </div>
        <input type="hidden" name="amount" id="amountInput" value="0.00">
        <div class="amount-display" id="displayAmount">$0.00</div>
        
        <div class="quick-adjust-row">
          <button type="button" class="quick-adj-btn" onclick="adjustAmount(1)">+$1</button>
          <button type="button" class="quick-adj-btn" onclick="adjustAmount(5)">+$5</button>
          <button type="button" class="quick-adj-btn" onclick="adjustAmount(10)">+$10</button>
          <button type="button" class="quick-adj-btn" onclick="adjustAmount(100)">+$100</button>
        </div>

        <div class="keypad-grid">
          <button type="button" class="keypad-btn" onclick="keypadPress('1')">1</button>
          <button type="button" class="keypad-btn" onclick="keypadPress('2')">2</button>
          <button type="button" class="keypad-btn" onclick="keypadPress('3')">3</button>
          <button type="button" class="keypad-btn" onclick="keypadPress('4')">4</button>
          <button type="button" class="keypad-btn" onclick="keypadPress('5')">5</button>
          <button type="button" class="keypad-btn" onclick="keypadPress('6')">6</button>
          <button type="button" class="keypad-btn" onclick="keypadPress('7')">7</button>
          <button type="button" class="keypad-btn" onclick="keypadPress('8')">8</button>
          <button type="button" class="keypad-btn" onclick="keypadPress('9')">9</button>
          <button type="button" class="keypad-btn action" onclick="keypadPress('.')">.</button>
          <button type="button" class="keypad-btn" onclick="keypadPress('0')">0</button>
          <button type="button" class="keypad-btn action" onclick="keypadPress('back')">
            <i class="ph ph-backspace"></i>
          </button>
        </div>
      </div>

      <!-- STEP 7: Expense Date & Submit -->
      <div class="wizard-step" id="step-7">
        <div class="step-header">
          <span class="step-title">지출 날짜 선택</span>
          <span class="step-subtitle">경비를 지출한 날짜를 확인하고 제출해 주세요.</span>
        </div>
        <div class="form-group" style="margin-bottom: 24px">
          <label class="form-label" for="dateInput">지출 일자</label>
          <input type="date" name="expense_date" id="dateInput" class="input-text" style="background-color: var(--bg-surface);" value="{{ date('Y-m-d') }}">
        </div>
        
        <div class="success-card">
          <i class="ph ph-check-circle success-icon"></i>
          <div>
            <h3 style="font-size:15px; font-weight:700; margin-bottom:4px">경비 등록 완료 단계</h3>
            <p style="font-size:12px; color:var(--text-secondary)">입력된 정보가 올바른지 확인 후 아래의 '최종 제출' 버튼을 눌러주세요.</p>
          </div>
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
    const totalSteps = 7;
    let rawAmountString = "0";

    function selectPaymentType(type, element) {
      document.querySelectorAll('#step-1 .option-card').forEach(c => c.classList.remove('selected'));
      element.classList.add('selected');
      document.getElementById('paymentType').value = type;
    }

    function selectCategory(category, element) {
      document.querySelectorAll('#step-3 .cat-btn').forEach(b => b.classList.remove('selected'));
      element.classList.add('selected');
      document.getElementById('categoryInput').value = category;
    }

    function keypadPress(val) {
      if (val === 'back') {
        rawAmountString = rawAmountString.slice(0, -1);
        if (rawAmountString === '' || rawAmountString === '-') rawAmountString = '0';
      } else if (val === '.') {
        if (!rawAmountString.includes('.')) {
          rawAmountString += '.';
        }
      } else {
        if (rawAmountString === '0') {
          rawAmountString = val;
        } else {
          // Limit decimals to 2 places
          const parts = rawAmountString.split('.');
          if (parts[1] && parts[1].length >= 2) {
            return;
          }
          rawAmountString += val;
        }
      }

      updateAmountDisplay();
    }

    function adjustAmount(val) {
      let currentVal = parseFloat(rawAmountString) || 0;
      currentVal += val;
      rawAmountString = currentVal.toFixed(2);
      updateAmountDisplay();
    }

    function updateAmountDisplay() {
      const parsed = parseFloat(rawAmountString) || 0;
      document.getElementById('displayAmount').textContent = '$' + parsed.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
      document.getElementById('amountInput').value = parsed.toFixed(2);
    }

    async function handleReceiptUpload(event) {
      const file = event.target.files[0];
      if (!file) return;

      const container = document.getElementById('uploadContainer');
      const scanner = document.getElementById('scannerBar');
      const icon = document.getElementById('uploadAreaIcon');
      const label = document.getElementById('uploadAreaLabel');
      const sub = document.getElementById('uploadAreaSub');

      // Start scanner animation
      scanner.style.display = 'block';
      icon.className = 'ph ph-circle-notch upload-icon';
      icon.style.animation = 'spin 1s linear infinite';
      label.textContent = 'AI 영수증 분석중...';
      sub.textContent = 'Gemini가 영수증 정보를 추출하고 있습니다.';

      const formData = new FormData();
      formData.append('receipt', file);

      try {
        const response = await fetch("{{ route('mobile-expense.upload-receipt') }}", {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
          },
          body: formData
        });

        const result = await response.json();

        if (result.success) {
          // Auto fill fields
          const data = result.data;
          document.getElementById('receiptPath').value = result.receipt_path;
          document.getElementById('ocrData').value = JSON.stringify(data);

          // Populate fields
          if (data.vendor_name) {
            document.getElementById('descInput').value = data.vendor_name + (data.description ? ' - ' + data.description : '');
          }
          if (data.amount) {
            rawAmountString = Number(data.amount).toFixed(2);
            updateAmountDisplay();
          }
          if (data.date) {
            document.getElementById('dateInput').value = data.date;
          }
          if (data.category) {
            const cleanCat = normalizeCategory(data.category);
            document.getElementById('categoryInput').value = cleanCat;
            document.querySelectorAll('#step-3 .cat-btn').forEach(btn => {
              if (btn.textContent.trim().toLowerCase() === cleanCat.toLowerCase()) {
                btn.classList.add('selected');
              } else {
                btn.classList.remove('selected');
              }
            });
          }

          // Move to next step automatically
          goNextStep();
        } else {
          alert('영수증 분석 실패: ' + result.error);
        }
      } catch (err) {
        alert('서버 오류: ' + err.message);
      } finally {
        // Reset upload UI
        scanner.style.display = 'none';
        icon.className = 'ph ph-receipt-bold upload-icon';
        icon.style.animation = 'none';
        label.textContent = '사진 촬영 또는 파일 올리기';
        sub.textContent = 'JPG, PNG, PDF 최대 10MB';
      }
    }

    function normalizeCategory(cat) {
      const allowed = ['Computer & Software', 'Automobile Expense', 'Utilities', 'Travel & Lodging', 'Office Supplies', 'Meals & Entertainment', 'Other'];
      for (let item of allowed) {
        if (item.toLowerCase() === cat.trim().toLowerCase()) return item;
      }
      return 'Other';
    }

    function updateStepUI() {
      // Manage views
      for (let i = 1; i <= totalSteps; i++) {
        const stepView = document.getElementById('step-' + i);
        const dot = document.getElementById('dot-' + i);
        if (i === currentStep) {
          stepView.classList.add('active');
          dot.classList.add('active');
        } else {
          stepView.classList.remove('active');
          dot.classList.remove('active');
        }
      }

      // Footer buttons
      document.getElementById('btnPrev').style.display = currentStep === 1 ? 'none' : 'block';
      const nextBtn = document.getElementById('btnNext');
      if (currentStep === totalSteps) {
        nextBtn.textContent = '최종 제출하기';
        nextBtn.style.background = 'linear-gradient(135deg, var(--status-success), #047857)';
        nextBtn.style.boxShadow = '0 4px 15px rgba(16,185,129,0.3)';
      } else {
        nextBtn.textContent = '다음';
        nextBtn.style.background = 'var(--brand-primary)';
        nextBtn.style.boxShadow = 'none';
      }
    }

    function goNextStep() {
      if (currentStep < totalSteps) {
        currentStep++;
        updateStepUI();
      }
    }

    function goPrevStep() {
      if (currentStep > 1) {
        currentStep--;
        updateStepUI();
      }
    }

    function handleNextClick() {
      if (currentStep === totalSteps) {
        // Form Validation check
        const amountVal = parseFloat(document.getElementById('amountInput').value) || 0;
        if (amountVal <= 0) {
          alert('지출 금액을 0보다 크게 입력해 주세요.');
          currentStep = 6;
          updateStepUI();
          return;
        }

        const descVal = document.getElementById('descInput').value.trim();
        if (descVal === '') {
          alert('지출 내역/설명을 입력해 주세요.');
          currentStep = 5;
          updateStepUI();
          return;
        }

        // Submit form
        document.getElementById('wizardForm').submit();
      } else {
        goNextStep();
      }
    }
  </script>
  <style>
    @keyframes spin {
      100% { transform: rotate(360deg); }
    }
  </style>
</body>
</html>

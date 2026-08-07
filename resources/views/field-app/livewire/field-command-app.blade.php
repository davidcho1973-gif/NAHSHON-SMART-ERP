<div class="min-h-screen bg-slate-950 text-slate-100 font-sans pb-12 selection:bg-slate-700 selection:text-white">
    <!-- Top Professional Corporate Header -->
    <header class="sticky top-0 z-50 bg-slate-900/95 backdrop-blur-md border-b border-slate-800 px-4 py-3 sm:px-6 shadow-md">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-200">
                    <i class="fa-solid fa-clipboard-check text-slate-300 text-base"></i>
                </div>
                <div>
                    <h1 class="font-bold text-base sm:text-lg text-slate-100 tracking-tight flex items-center gap-2">
                        건설현장 일일 현장 관리 보고서
                        <span class="text-[11px] font-medium px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">Official Field Report</span>
                    </h1>
                    <p class="text-xs text-slate-400">NAHSHON SMART ERP · Daily Field Construction & Operations Command System</p>
                </div>
            </div>

            <!-- Total Headcount Badge -->
            <div class="hidden sm:flex items-center space-x-2 bg-slate-900 border border-slate-800 px-3.5 py-1.5 rounded-lg text-xs">
                <span class="text-slate-400 font-medium">금일 현장 총원:</span>
                <span class="text-sm font-bold text-slate-100">{{ $this->sumWorkers }}</span>
                <span class="text-slate-400">명</span>
            </div>
        </div>

        <!-- Professional Tab Bar -->
        <nav class="max-w-7xl mx-auto grid grid-cols-4 gap-1.5 sm:gap-2 mt-3 pt-2 border-t border-slate-800">
            <button wire:click="setTab('report')" class="py-2 px-2 rounded-lg text-xs sm:text-sm font-semibold flex items-center justify-center space-x-1.5 transition-all {{ $activeTab === 'report' ? 'bg-slate-800 text-white border border-slate-700 shadow-sm' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                <i class="fa-regular fa-file-lines text-slate-400"></i>
                <span class="truncate">1. 일일 작업 보고서</span>
            </button>

            <button wire:click="setTab('qr')" class="py-2 px-2 rounded-lg text-xs sm:text-sm font-semibold flex items-center justify-center space-x-1.5 transition-all {{ $activeTab === 'qr' ? 'bg-slate-800 text-white border border-slate-700 shadow-sm' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                <i class="fa-solid fa-qrcode text-slate-400"></i>
                <span class="truncate">2. QR 출퇴근</span>
            </button>

            <button wire:click="setTab('safety')" class="py-2 px-2 rounded-lg text-xs sm:text-sm font-semibold flex items-center justify-center space-x-1.5 transition-all {{ $activeTab === 'safety' ? 'bg-slate-800 text-white border border-slate-700 shadow-sm' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                <i class="fa-solid fa-shield-halved text-slate-400"></i>
                <span class="truncate">3. 안전 점검 TBM</span>
            </button>

            <button wire:click="setTab('equipment')" class="py-2 px-2 rounded-lg text-xs sm:text-sm font-semibold flex items-center justify-center space-x-1.5 transition-all {{ $activeTab === 'equipment' ? 'bg-slate-800 text-white border border-slate-700 shadow-sm' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                <i class="fa-solid fa-truck-ramp-box text-slate-400"></i>
                <span class="truncate">4. 장비 수불</span>
            </button>
        </nav>
    </header>

    <!-- Toast Notification Banner -->
    @if($toastMessage)
        <div class="max-w-7xl mx-auto px-4 mt-4">
            <div class="bg-slate-900 border border-slate-700 text-slate-200 px-4 py-3 rounded-lg flex items-center justify-between text-xs font-medium shadow-sm">
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-circle-check text-slate-300"></i>
                    {{ $toastMessage }}
                </span>
                <button wire:click="$set('toastMessage', null)" class="text-slate-400 hover:text-white font-bold">✕</button>
            </div>
        </div>
    @endif

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 pt-6">

        <!-- TAB 1: 일일 작업 보고서 (Professional Report Style) -->
        @if($activeTab === 'report')
            <div class="space-y-6">
                <!-- Section 1: Site & Weather Summary Sheet -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3 mb-4">
                        <h2 class="text-sm font-bold text-slate-100 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span> Ⅰ. 현장 개요 및 일기 현황
                        </h2>
                        <button wire:click="$toggle('showSiteModal')" class="text-xs font-medium text-slate-300 hover:text-white bg-slate-800 border border-slate-700 px-2.5 py-1 rounded-md transition-colors">
                            <i class="fa-solid fa-gear mr-1 text-slate-400"></i>현장 관리
                        </button>
                    </div>

                    <!-- Site Management Drawer -->
                    @if($showSiteModal)
                        <div class="bg-slate-950 p-4 rounded-lg border border-slate-800 mb-4 space-y-3">
                            <h3 class="text-xs font-bold text-slate-200">현장 신규 등록 및 수정</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <input type="text" wire:model.live="new_site_name" placeholder="현장명 (예: 평택 P5 현장)" class="bg-slate-900 border border-slate-700 rounded px-3 py-1.5 text-xs text-slate-100">
                                <input type="text" wire:model.live="new_site_code" placeholder="현장 코드 (예: PT-05)" class="bg-slate-900 border border-slate-700 rounded px-3 py-1.5 text-xs text-slate-100">
                                <button wire:click="createSite" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-100 font-semibold text-xs rounded py-1.5">
                                    + 현장 추가
                                </button>
                            </div>

                            @if($editing_site_id)
                                <div class="flex items-center space-x-2 pt-2 border-t border-slate-800">
                                    <input type="text" wire:model.live="editing_site_name" class="flex-1 bg-slate-900 border border-slate-700 rounded px-3 py-1.5 text-xs text-slate-100">
                                    <button wire:click="updateSite" class="bg-slate-700 text-white font-semibold text-xs px-3 py-1.5 rounded">저장</button>
                                    <button wire:click="$set('editing_site_id', null)" class="bg-slate-800 text-slate-400 font-semibold text-xs px-3 py-1.5 rounded">취소</button>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-xs font-semibold text-slate-400">작업 현장명</label>
                                @if($site_id)
                                    <div class="space-x-1">
                                        <button wire:click="editSite({{ $site_id }})" class="text-[10px] text-slate-400 hover:underline">수정</button>
                                        <button wire:click="deleteSite({{ $site_id }})" onclick="confirm('이 현장을 삭제하시겠습니까?') || event.stopImmediatePropagation()" class="text-[10px] text-slate-400 hover:underline">삭제</button>
                                    </div>
                                @endif
                            </div>
                            <select wire:model.live="site_id" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-xs font-semibold text-slate-100 focus:border-slate-600 focus:outline-none">
                                @foreach($sites as $site)
                                    <option value="{{ $site->id }}">{{ $site->name }} ({{ $site->code }})</option>
                                @endforeach
                                @if($sites->isEmpty())
                                    <option value="1">텍사스 킬린 신축현장 (TX-01)</option>
                                @endif
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">작업 일자</label>
                            <input type="date" wire:model.live="work_date" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-xs font-semibold text-slate-100">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">기상 상태</label>
                            <select wire:model.live="weather" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-xs font-semibold text-slate-100">
                                <option value="☀️ 맑음">☀️ 맑음 (Clear)</option>
                                <option value="⛅ 구름조금">⛅ 구름조금 (Partly Cloudy)</option>
                                <option value="🌧️ 우천">🌧️ 우천 (Rain)</option>
                                <option value="❄️ 강설">❄️ 강설 (Snow)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">기온 범위</label>
                            <input type="text" wire:model.live="temperature" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-xs font-semibold text-slate-100">
                        </div>
                    </div>
                </div>

                <!-- Section 2: Trade Labor Table -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3 mb-4">
                        <h2 class="text-sm font-bold text-slate-100 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span> Ⅱ. 공종별 인력 투입 현황
                        </h2>
                        <div class="flex items-center space-x-3">
                            <button wire:click="$toggle('showTradeModal')" class="text-xs font-medium text-slate-300 hover:text-white bg-slate-800 border border-slate-700 px-2.5 py-1 rounded-md transition-colors">
                                <i class="fa-solid fa-gear mr-1 text-slate-400"></i>공종 관리
                            </button>
                            <span class="text-xs font-semibold text-slate-300 bg-slate-800 px-2.5 py-1 rounded border border-slate-700">
                                총 투입인원: <strong class="text-slate-100">{{ $this->sumWorkers }}</strong> 명
                            </span>
                        </div>
                    </div>

                    <!-- Dynamic Trade Modal -->
                    @if($showTradeModal)
                        <div class="bg-slate-950 p-4 rounded-lg border border-slate-800 mb-4 space-y-3">
                            <h3 class="text-xs font-bold text-slate-200">공종 항목 신규 추가 및 수정</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <input type="text" wire:model.live="new_trade_name" placeholder="공종명 (예: 폼목수, 철근, 도장)" class="bg-slate-900 border border-slate-700 rounded px-3 py-1.5 text-xs text-slate-100">
                                <input type="text" wire:model.live="new_trade_icon" placeholder="기호 (예: 🔨, 🪵)" class="bg-slate-900 border border-slate-700 rounded px-3 py-1.5 text-xs text-slate-100">
                                <button wire:click="addTrade" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-100 font-semibold text-xs rounded py-1.5">
                                    + 공종 추가
                                </button>
                            </div>

                            @if($editing_trade_id)
                                <div class="flex items-center space-x-2 pt-2 border-t border-slate-800">
                                    <span class="text-xs text-slate-400">공종명 수정:</span>
                                    <input type="text" wire:model.live="editing_trade_name" class="flex-1 bg-slate-900 border border-slate-700 rounded px-3 py-1.5 text-xs text-slate-100">
                                    <button wire:click="updateTrade" class="bg-slate-700 text-white font-semibold text-xs px-3 py-1.5 rounded">저장</button>
                                    <button wire:click="$set('editing_trade_id', null)" class="bg-slate-800 text-slate-400 font-semibold text-xs px-3 py-1.5 rounded">취소</button>
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Professional Trade Table Grid -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                        @foreach($trades as $t)
                            <div class="bg-slate-950 border border-slate-800 rounded-lg p-3 text-center relative group">
                                <div class="absolute top-1.5 right-1.5 opacity-0 group-hover:opacity-100 transition-opacity space-x-1">
                                    <button wire:click="editTrade('{{ $t['id'] }}')" class="text-[10px] text-slate-400 hover:text-white"><i class="fa-solid fa-pen"></i></button>
                                    <button wire:click="removeTrade('{{ $t['id'] }}')" class="text-[10px] text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
                                </div>

                                <span class="text-xs text-slate-400 block font-medium mb-1 truncate px-2">{{ $t['icon'] }} {{ $t['name'] }}</span>
                                <div class="flex items-center justify-center space-x-2 my-1">
                                    <button wire:click="decrementTrade('{{ $t['id'] }}')" class="w-7 h-7 rounded bg-slate-800 text-slate-300 hover:bg-slate-700 font-bold text-xs">-</button>
                                    <span class="text-base font-bold text-slate-100 w-8">{{ $t['count'] }}</span>
                                    <button wire:click="incrementTrade('{{ $t['id'] }}')" class="w-7 h-7 rounded bg-slate-800 text-slate-300 hover:bg-slate-700 font-bold text-xs">+</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Section 3: Work Log & Schedule Sheet -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm space-y-4">
                        <h2 class="text-sm font-bold text-slate-100 border-b border-slate-800 pb-3 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span> Ⅲ. 금일 작업 실적 보고 (Today's Progress)
                        </h2>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">공종 대표 작업명</label>
                            <input type="text" wire:model.live="work_title" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-xs font-semibold text-slate-100">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">금일 세부 작업 내역</label>
                            <textarea wire:model.live="work_today" rows="4" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-xs text-slate-200 leading-relaxed font-mono"></textarea>
                        </div>
                        <div class="bg-slate-950 p-3 rounded-lg border border-slate-800 flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-400">금일 공정 진척도</span>
                            <div class="flex items-center space-x-3">
                                <input type="range" wire:model.live="progress_rate" min="0" max="100" class="w-32 accent-slate-400">
                                <span class="text-sm font-bold text-slate-100 w-12 text-right">{{ $progress_rate }}%</span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm space-y-4">
                        <h2 class="text-sm font-bold text-slate-100 border-b border-slate-800 pb-3 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span> Ⅳ. 익일 작업 예정 및 승인
                        </h2>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">익일 주요 시공 예정 사항</label>
                            <textarea wire:model.live="work_tomorrow" rows="5" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-xs text-slate-200 leading-relaxed font-mono"></textarea>
                        </div>
                        <button wire:click="saveDailyReport" class="w-full py-2.5 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 text-white font-bold text-xs shadow-sm transition-all flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-floppy-disk text-slate-300"></i>
                            <span>일일 보고서 서버 전송 및 제출</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <!-- TAB 2: QR 출퇴근 (Clean Corporate QR Sheet) -->
        @if($activeTab === 'qr')
            <div class="max-w-2xl mx-auto space-y-6">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm text-center space-y-6">
                    <h2 class="text-sm font-bold text-slate-100 border-b border-slate-800 pb-3">현장 출퇴근 전자 태깅</h2>
                    
                    <div class="inline-block p-4 rounded-xl bg-white shadow-md">
                        <div class="w-44 h-44 bg-slate-950 rounded-lg p-3 flex flex-col items-center justify-center space-y-2">
                            <i class="fa-solid fa-qrcode text-5xl text-slate-200"></i>
                            <span class="text-[10px] font-mono text-slate-400 tracking-wider">{{ $qr_code_token }}</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 max-w-sm mx-auto">
                        <button wire:click="recordCommute('in')" class="py-2.5 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 text-white font-bold text-xs transition-all">
                            <i class="fa-solid fa-right-to-bracket mr-1.5 text-slate-400"></i>출근 기록
                        </button>
                        <button wire:click="recordCommute('out')" class="py-2.5 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 text-white font-bold text-xs transition-all">
                            <i class="fa-solid fa-right-from-bracket mr-1.5 text-slate-400"></i>퇴근 기록
                        </button>
                    </div>

                    <div class="bg-slate-950 p-3 rounded-lg border border-slate-800 flex items-center justify-between text-xs max-w-sm mx-auto">
                        <span class="text-slate-400">최근 스캔 기록:</span>
                        <span class="font-semibold text-slate-200">{{ $last_scan_status }} {{ $last_scan_time ? '('.$last_scan_time.')' : '' }}</span>
                    </div>
                </div>
            </div>
        @endif

        <!-- TAB 3: 안전 점검 TBM (Official Safety Inspection Sheet) -->
        @if($activeTab === 'safety')
            <div class="space-y-6">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm space-y-6">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h2 class="text-sm font-bold text-slate-100 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span> 작업 개시 전 안전점검 (TBM Checklist)
                        </h2>
                        <label class="flex items-center space-x-2 bg-slate-950 border border-slate-800 px-3 py-1 rounded cursor-pointer text-xs">
                            <input type="checkbox" wire:model.live="tbm_completed" class="rounded border-slate-700 text-slate-400 focus:ring-0">
                            <span class="font-semibold text-slate-300">TBM 실시 확인</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <label class="flex items-center justify-between p-3 bg-slate-950 rounded-lg border border-slate-800 cursor-pointer">
                            <span class="text-slate-300 font-medium">1. 개인 보호구 착용 점검</span>
                            <input type="checkbox" wire:model.live="safety_checks.ppe" class="rounded border-slate-700 text-slate-400">
                        </label>
                        <label class="flex items-center justify-between p-3 bg-slate-950 rounded-lg border border-slate-800 cursor-pointer">
                            <span class="text-slate-300 font-medium">2. 고소작업대 추락방지 벨트 확인</span>
                            <input type="checkbox" wire:model.live="safety_checks.fall_prevention" class="rounded border-slate-700 text-slate-400">
                        </label>
                        <label class="flex items-center justify-between p-3 bg-slate-950 rounded-lg border border-slate-800 cursor-pointer">
                            <span class="text-slate-300 font-medium">3. 가설 차단기 및 LOTO 검측</span>
                            <input type="checkbox" wire:model.live="safety_checks.electrical_hazard" class="rounded border-slate-700 text-slate-400">
                        </label>
                        <label class="flex items-center justify-between p-3 bg-slate-950 rounded-lg border border-slate-800 cursor-pointer">
                            <span class="text-slate-300 font-medium">4. 화기작업 허가서 및 소화기 배치</span>
                            <input type="checkbox" wire:model.live="safety_checks.fire_permit" class="rounded border-slate-700 text-slate-400">
                        </label>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">안전 지시사항</label>
                        <textarea wire:model.live="safety_notes" rows="3" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-xs text-slate-200 font-mono"></textarea>
                    </div>
                </div>
            </div>
        @endif

        <!-- TAB 4: 장비 수불 (Equipment Log Table) -->
        @if($activeTab === 'equipment')
            <div class="space-y-6">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-100 mb-3 border-b border-slate-800 pb-3">신규 중장비 등록</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        <input type="text" wire:model.live="new_eq_name" placeholder="장비명 (예: 스카이 03호)" class="bg-slate-950 border border-slate-800 rounded-lg px-3 py-1.5 text-xs text-slate-100">
                        <select wire:model.live="new_eq_type" class="bg-slate-950 border border-slate-800 rounded-lg px-3 py-1.5 text-xs text-slate-100">
                            <option value="스카이">고소작업대 (Sky)</option>
                            <option value="크레인">25톤 크레인</option>
                            <option value="지게차">지게차 (Forklift)</option>
                            <option value="굴착기">굴착기 (Excavator)</option>
                        </select>
                        <input type="text" wire:model.live="new_eq_operator" placeholder="조종원 성명" class="bg-slate-950 border border-slate-800 rounded-lg px-3 py-1.5 text-xs text-slate-100">
                    </div>
                    <button wire:click="addEquipment" class="mt-3 w-full py-2 bg-slate-800 border border-slate-700 hover:bg-slate-700 text-slate-100 font-bold text-xs rounded-lg transition-all">
                        + 장비 수불 등록
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-100 mb-4 border-b border-slate-800 pb-3">현장 장비 운용 현황 ({{ count($equipments) }}대)</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach($equipments as $idx => $eq)
                            <div class="bg-slate-950 border border-slate-800 rounded-lg p-3.5 flex items-center justify-between">
                                <div>
                                    <h3 class="text-xs font-bold text-slate-100">{{ $eq['name'] }}</h3>
                                    <p class="text-[11px] text-slate-400 mt-0.5">구분: {{ $eq['type'] }} | 조종원: {{ $eq['operator'] }}</p>
                                </div>
                                <button wire:click="toggleEquipmentStatus({{ $idx }})" class="px-2.5 py-1 rounded text-xs font-semibold border transition-all {{ $eq['status'] === '가동중' ? 'bg-slate-800 text-slate-100 border-slate-700' : 'bg-slate-950 text-slate-500 border-slate-800' }}">
                                    {{ $eq['status'] }}
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

    </main>
</div>

<div class="min-h-screen bg-slate-950 text-slate-100 font-sans pb-12">
    <!-- Top Fixed App Bar -->
    <header class="sticky top-0 z-50 bg-slate-900/90 backdrop-blur-md border-b border-slate-800 px-4 py-3 sm:px-6">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-500 via-purple-500 to-amber-500 flex items-center justify-center shadow-lg shadow-indigo-500/25">
                    <i class="fa-solid fa-bolt-lightning text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="font-extrabold text-base sm:text-lg text-white flex items-center gap-2">
                        SMART 현장 커맨드 
                        <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">Livewire 4 + Tailwind v4</span>
                    </h1>
                    <p class="text-xs text-slate-400">일일보고서 · QR 출퇴근 · 안전점검 TBM · 장비 수불 실시간 통합 앱</p>
                </div>
            </div>

            <!-- Total Headcount Badge -->
            <div class="hidden sm:flex items-center space-x-2 bg-indigo-950/60 border border-indigo-500/30 px-3 py-1.5 rounded-xl">
                <i class="fa-solid fa-users text-amber-400 text-sm"></i>
                <span class="text-xs text-slate-300 font-semibold">금일 현장 총원:</span>
                <span class="text-sm font-black text-amber-400">{{ $this->sumWorkers }}명</span>
            </div>
        </div>

        <!-- 4-Tab Navigation Bar -->
        <nav class="max-w-7xl mx-auto grid grid-cols-4 gap-1 sm:gap-2 mt-3 pt-2 border-t border-slate-800/80">
            <button wire:click="setTab('report')" class="py-2 px-1 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center space-x-1.5 transition-all {{ $activeTab === 'report' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-slate-900/60 text-slate-400 hover:bg-slate-800' }}">
                <i class="fa-solid fa-file-lines"></i>
                <span class="truncate">1. 일일 보고서</span>
            </button>

            <button wire:click="setTab('qr')" class="py-2 px-1 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center space-x-1.5 transition-all {{ $activeTab === 'qr' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-slate-900/60 text-slate-400 hover:bg-slate-800' }}">
                <i class="fa-solid fa-qrcode"></i>
                <span class="truncate">2. QR 출퇴근</span>
            </button>

            <button wire:click="setTab('safety')" class="py-2 px-1 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center space-x-1.5 transition-all {{ $activeTab === 'safety' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-slate-900/60 text-slate-400 hover:bg-slate-800' }}">
                <i class="fa-solid fa-shield-halved"></i>
                <span class="truncate">3. 안전 점검 TBM</span>
            </button>

            <button wire:click="setTab('equipment')" class="py-2 px-1 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center space-x-1.5 transition-all {{ $activeTab === 'equipment' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-slate-900/60 text-slate-400 hover:bg-slate-800' }}">
                <i class="fa-solid fa-truck-monster"></i>
                <span class="truncate">4. 장비 수불</span>
            </button>
        </nav>
    </header>

    <!-- Toast Notification Banner -->
    @if($toastMessage)
        <div class="max-w-7xl mx-auto px-4 mt-4">
            <div class="bg-emerald-500/20 border border-emerald-500/40 text-emerald-300 px-4 py-3 rounded-xl flex items-center justify-between text-sm font-semibold shadow-lg shadow-emerald-500/10">
                <span>{{ $toastMessage }}</span>
                <button wire:click="$set('toastMessage', null)" class="text-emerald-400 hover:text-white font-bold text-xs">✕ 닫기</button>
            </div>
        </div>
    @endif

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 pt-6">

        <!-- TAB 1: 일일 작업 보고서 (Daily Work Report) -->
        @if($activeTab === 'report')
            <div class="space-y-6">
                <!-- Site & Weather Row -->
                <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-5 shadow-xl">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-extrabold text-white flex items-center gap-2">
                            <i class="fa-solid fa-location-dot text-indigo-400"></i> 현장 선택 및 관리
                        </h2>
                        <button wire:click="$toggle('showSiteModal')" class="text-xs font-bold text-indigo-400 hover:text-indigo-300 bg-indigo-500/10 px-2.5 py-1 rounded-lg border border-indigo-500/20">
                            <i class="fa-solid fa-gear mr-1"></i>현장명 추가·수정·삭제
                        </button>
                    </div>

                    <!-- Site Management Drawer/Modal -->
                    @if($showSiteModal)
                        <div class="bg-slate-950 p-4 rounded-xl border border-indigo-500/30 mb-4 space-y-3">
                            <h3 class="text-xs font-bold text-amber-400 flex items-center gap-1.5">
                                🏗️ 현장 관리 (신규 등록 및 이름 변경/삭제)
                            </h3>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <input type="text" wire:model.live="new_site_name" placeholder="신규 현장명 (예: 평택 P5 현장)" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white">
                                <input type="text" wire:model.live="new_site_code" placeholder="현장 코드 (예: PT-05)" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white">
                                <button wire:click="createSite" class="bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs rounded-lg py-1.5">
                                    + 현장 신규 추가
                                </button>
                            </div>

                            @if($editing_site_id)
                                <div class="flex items-center space-x-2 pt-2 border-t border-slate-800">
                                    <input type="text" wire:model.live="editing_site_name" class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white">
                                    <button wire:click="updateSite" class="bg-emerald-600 text-white font-bold text-xs px-3 py-1.5 rounded-lg">저장</button>
                                    <button wire:click="$set('editing_site_id', null)" class="bg-slate-800 text-slate-400 font-bold text-xs px-3 py-1.5 rounded-lg">취소</button>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-xs font-semibold text-slate-400">작업 현장</label>
                                @if($site_id)
                                    <div class="space-x-1">
                                        <button wire:click="editSite({{ $site_id }})" class="text-[10px] text-indigo-400 hover:underline">수정</button>
                                        <button wire:click="deleteSite({{ $site_id }})" onclick="confirm('이 현장을 삭제하시겠습니까?') || event.stopImmediatePropagation()" class="text-[10px] text-rose-400 hover:underline">삭제</button>
                                    </div>
                                @endif
                            </div>
                            <select wire:model.live="site_id" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2 text-sm text-white font-medium focus:ring-2 focus:ring-indigo-500">
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
                            <input type="date" wire:model.live="work_date" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2 text-sm text-white font-medium">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">날씨 상태</label>
                            <select wire:model.live="weather" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2 text-sm text-white font-medium">
                                <option value="☀️ 맑음">☀️ 맑음 (Clear)</option>
                                <option value="⛅ 구름조금">⛅ 구름조금 (Partly Cloudy)</option>
                                <option value="🌧️ 우천">🌧️ 우천 (Rain)</option>
                                <option value="❄️ 강설">❄️ 강설 (Snow)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">기온 범위</label>
                            <input type="text" wire:model.live="temperature" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2 text-sm text-white font-medium">
                        </div>
                    </div>
                </div>

                <!-- Dynamic Trade Counter Grid -->
                <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-5 shadow-xl">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-extrabold text-white flex items-center gap-2">
                            <i class="fa-solid fa-users-viewfinder text-amber-400"></i> 공종별 인력 투입 실시간 카운터
                        </h2>
                        <div class="flex items-center space-x-2">
                            <button wire:click="$toggle('showTradeModal')" class="text-xs font-bold text-indigo-400 hover:text-indigo-300 bg-indigo-500/10 px-2.5 py-1 rounded-lg border border-indigo-500/20">
                                <i class="fa-solid fa-plus-minus mr-1"></i>공종명 추가·수정·삭제
                            </button>
                            <span class="text-xs font-bold text-amber-400 bg-amber-500/10 px-3 py-1 rounded-full border border-amber-500/20">
                                합계: {{ $this->sumWorkers }}명
                            </span>
                        </div>
                    </div>

                    <!-- Dynamic Trade Modal / Panel -->
                    @if($showTradeModal)
                        <div class="bg-slate-950 p-4 rounded-xl border border-indigo-500/30 mb-4 space-y-3">
                            <h3 class="text-xs font-bold text-amber-400 flex items-center gap-1.5">
                                🛠️ 커스텀 공종 관리 (신규 공정명 추가/수정/삭제)
                            </h3>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <input type="text" wire:model.live="new_trade_name" placeholder="신규 공종명 (예: 폼목수, 철근, 도장)" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white">
                                <input type="text" wire:model.live="new_trade_icon" placeholder="아이콘 (예: 🔨, 🪵, 🎨)" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white">
                                <button wire:click="addTrade" class="bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs rounded-lg py-1.5">
                                    + 공종 신규 추가
                                </button>
                            </div>

                            @if($editing_trade_id)
                                <div class="flex items-center space-x-2 pt-2 border-t border-slate-800">
                                    <span class="text-xs text-slate-400">공종명 변경:</span>
                                    <input type="text" wire:model.live="editing_trade_name" class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white">
                                    <button wire:click="updateTrade" class="bg-emerald-600 text-white font-bold text-xs px-3 py-1.5 rounded-lg">저장</button>
                                    <button wire:click="$set('editing_trade_id', null)" class="bg-slate-800 text-slate-400 font-bold text-xs px-3 py-1.5 rounded-lg">취소</button>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                        @foreach($trades as $t)
                            <div class="bg-slate-950/80 border border-slate-800 rounded-xl p-3 text-center relative group">
                                <!-- Trade Edit/Delete Action Icons -->
                                <div class="absolute top-1.5 right-1.5 opacity-0 group-hover:opacity-100 transition-opacity flex items-center space-x-1">
                                    <button wire:click="editTrade('{{ $t['id'] }}')" class="text-[10px] text-indigo-400 hover:text-white"><i class="fa-solid fa-pen"></i></button>
                                    <button wire:click="removeTrade('{{ $t['id'] }}')" class="text-[10px] text-rose-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
                                </div>

                                <span class="text-xs text-slate-400 block mb-1 truncate px-2">{{ $t['icon'] }} {{ $t['name'] }}</span>
                                <div class="flex items-center justify-center space-x-2 my-1">
                                    <button wire:click="decrementTrade('{{ $t['id'] }}')" class="w-8 h-8 rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700 font-bold active:scale-95 text-xs">-</button>
                                    <span class="text-lg font-black text-white w-8">{{ $t['count'] }}</span>
                                    <button wire:click="incrementTrade('{{ $t['id'] }}')" class="w-8 h-8 rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 font-bold active:scale-95 text-xs">+</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Daily Work Log & Progress Slider -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
                        <h2 class="text-sm font-extrabold text-white flex items-center gap-2">
                            <i class="fa-solid fa-pen-nib text-emerald-400"></i> 금일 실시 작업 내용
                        </h2>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">공종 대표 제목</label>
                            <input type="text" wire:model.live="work_title" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white font-semibold">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">세부 작업실시 기록</label>
                            <textarea wire:model.live="work_today" rows="4" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-sm text-slate-200 leading-relaxed"></textarea>
                        </div>
                        <div class="bg-slate-950 p-3 rounded-xl border border-slate-800 flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-300">금일 전체 공정률</span>
                            <div class="flex items-center space-x-3">
                                <input type="range" wire:model.live="progress_rate" min="0" max="100" class="w-32 accent-indigo-500">
                                <span class="text-base font-black text-indigo-400 w-12 text-right">{{ $progress_rate }}%</span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
                        <h2 class="text-sm font-extrabold text-white flex items-center gap-2">
                            <i class="fa-solid fa-calendar-week text-sky-400"></i> 내일 작업 계획
                        </h2>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">익일 세부 작업 예정</label>
                            <textarea wire:model.live="work_tomorrow" rows="5" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-sm text-slate-200 leading-relaxed"></textarea>
                        </div>
                        <button wire:click="saveDailyReport" class="w-full py-3 rounded-xl bg-gradient-to-r from-indigo-600 to-amber-500 text-white font-bold text-sm shadow-lg shadow-indigo-500/25 hover:opacity-95 active:scale-[0.99] transition-all">
                            <i class="fa-solid fa-floppy-disk mr-2"></i>일일 보고서 실시간 저장
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <!-- TAB 2: QR 출퇴근 (Attendance QR) -->
        @if($activeTab === 'qr')
            <div class="max-w-2xl mx-auto space-y-6">
                <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 shadow-xl text-center space-y-6">
                    <div class="inline-block p-4 rounded-2xl bg-white shadow-xl">
                        <div class="w-48 h-48 bg-slate-950 rounded-xl p-3 flex flex-col items-center justify-center space-y-2 relative overflow-hidden">
                            <div class="absolute inset-0 bg-gradient-to-tr from-indigo-500/20 to-transparent"></div>
                            <i class="fa-solid fa-qrcode text-6xl text-indigo-400"></i>
                            <span class="text-[10px] font-mono text-slate-400 tracking-widest">{{ $qr_code_token }}</span>
                        </div>
                    </div>

                    <div>
                        <h2 class="text-lg font-black text-white">현장 QR 근태 출퇴근 찍기</h2>
                        <p class="text-xs text-slate-400 mt-1">현장 QR 코드를 스캔하거나 아래 원터치 태깅 버튼을 누르세요.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <button wire:click="recordCommute('in')" class="py-3.5 rounded-xl bg-emerald-600 text-white font-extrabold text-sm shadow-lg shadow-emerald-600/30 hover:bg-emerald-500 active:scale-95 transition-all flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-right-to-bracket text-base"></i>
                            <span>출근 기록하기</span>
                        </button>
                        <button wire:click="recordCommute('out')" class="py-3.5 rounded-xl bg-rose-600 text-white font-extrabold text-sm shadow-lg shadow-rose-600/30 hover:bg-rose-500 active:scale-95 transition-all flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-right-from-bracket text-base"></i>
                            <span>퇴근 기록하기</span>
                        </button>
                    </div>

                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 flex items-center justify-between text-xs">
                        <span class="text-slate-400 font-medium">최근 태깅 상태:</span>
                        <div class="flex items-center space-x-2 font-bold">
                            <span class="text-emerald-400">{{ $last_scan_status }}</span>
                            @if($last_scan_time)
                                <span class="text-slate-500">({{ $last_scan_time }})</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- TAB 3: 안전 점검 TBM (Safety Inspection) -->
        @if($activeTab === 'safety')
            <div class="space-y-6">
                <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-6">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                        <div>
                            <h2 class="text-base font-extrabold text-white flex items-center gap-2">
                                <i class="fa-solid fa-shield-cat text-amber-400"></i> 작업 개시 전 안전점검 (TBM & Checklist)
                            </h2>
                            <p class="text-xs text-slate-400">Toolbox Meeting 및 필수 위험 예방 점검 항목</p>
                        </div>
                        <label class="flex items-center space-x-2 bg-emerald-500/10 border border-emerald-500/30 px-3 py-1.5 rounded-xl cursor-pointer">
                            <input type="checkbox" wire:model.live="tbm_completed" class="rounded border-slate-700 text-emerald-600 focus:ring-0">
                            <span class="text-xs font-bold text-emerald-300">TBM 실시 완료</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <label class="flex items-center justify-between p-3.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer">
                            <span class="text-xs font-semibold text-slate-200">1. 개인 보호구 (안전모/안전화/안전대) 착용 확인</span>
                            <input type="checkbox" wire:model.live="safety_checks.ppe" class="rounded border-slate-700 text-indigo-600">
                        </label>
                        <label class="flex items-center justify-between p-3.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer">
                            <span class="text-xs font-semibold text-slate-200">2. 고소작업대 추락 방지 안전고리 체결</span>
                            <input type="checkbox" wire:model.live="safety_checks.fall_prevention" class="rounded border-slate-700 text-indigo-600">
                        </label>
                        <label class="flex items-center justify-between p-3.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer">
                            <span class="text-xs font-semibold text-slate-200">3. 전기 가설 분전반 차단기 및 LOTO 검측</span>
                            <input type="checkbox" wire:model.live="safety_checks.electrical_hazard" class="rounded border-slate-700 text-indigo-600">
                        </label>
                        <label class="flex items-center justify-between p-3.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer">
                            <span class="text-xs font-semibold text-slate-200">4. 용접 화기작업 소화기 배치 및 화기허가서</span>
                            <input type="checkbox" wire:model.live="safety_checks.fire_permit" class="rounded border-slate-700 text-indigo-600">
                        </label>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">안전 특이사항 및 지시사항</label>
                        <textarea wire:model.live="safety_notes" rows="3" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-sm text-slate-200"></textarea>
                    </div>
                </div>
            </div>
        @endif

        <!-- TAB 4: 장비 수불 (Equipment Management) -->
        @if($activeTab === 'equipment')
            <div class="space-y-6">
                <!-- Add Equipment Box -->
                <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-5 shadow-xl">
                    <h2 class="text-sm font-extrabold text-white mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-plus text-amber-400"></i> 신규 중장비 수불/입고 등록
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <input type="text" wire:model.live="new_eq_name" placeholder="장비명 (예: 스카이 03호)" class="bg-slate-800 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                        <select wire:model.live="new_eq_type" class="bg-slate-800 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                            <option value="스카이">고소작업대 (Sky)</option>
                            <option value="크레인">25톤 크레인</option>
                            <option value="지게차">지게차 (Forklift)</option>
                            <option value="굴착기">굴착기 (Excavator)</option>
                        </select>
                        <input type="text" wire:model.live="new_eq_operator" placeholder="조종원/기사 성명" class="bg-slate-800 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                    </div>
                    <button wire:click="addEquipment" class="mt-3 w-full py-2.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-bold text-xs shadow-md transition-all">
                        <i class="fa-solid fa-truck-ramp-box mr-1"></i>장비 수불 추가하기
                    </button>
                </div>

                <!-- Equipment List Grid -->
                <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-5 shadow-xl">
                    <h2 class="text-sm font-extrabold text-white mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-list text-indigo-400"></i> 현장 보유 장비 가동 리스트 ({{ count($equipments) }}대)
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach($equipments as $idx => $eq)
                            <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-bold text-white">{{ $eq['name'] }}</h3>
                                    <p class="text-xs text-slate-400">구분: {{ $eq['type'] }} | 조종원: {{ $eq['operator'] }}</p>
                                </div>
                                <button wire:click="toggleEquipmentStatus({{ $idx }})" class="px-3 py-1.5 rounded-lg text-xs font-extrabold border transition-all {{ $eq['status'] === '가동중' ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40' : 'bg-slate-800 text-slate-400 border-slate-700' }}">
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

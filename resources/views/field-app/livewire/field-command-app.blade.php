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
                        건설현장 일일 현장 관리 보고서 & AI 도면 지식
                        <span class="text-[11px] font-medium px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">Official Field & Knowledge App</span>
                    </h1>
                    <p class="text-xs text-slate-400">NAHSHON SMART ERP · Daily Field Construction, Daily Closing & AI Blueprint Q&A Hub</p>
                </div>
            </div>

            <!-- Total Headcount Badge -->
            <div class="hidden sm:flex items-center space-x-2 bg-slate-900 border border-slate-800 px-3.5 py-1.5 rounded-lg text-xs">
                <span class="text-slate-400 font-medium">금일 현장 총원:</span>
                <span class="text-sm font-bold text-slate-100">{{ $this->sumWorkers }}</span>
                <span class="text-slate-400">명</span>
            </div>
        </div>

        <!-- 5-Tab Navigation Bar -->
        <nav class="max-w-7xl mx-auto grid grid-cols-5 gap-1 sm:gap-1.5 mt-3 pt-2 border-t border-slate-800">
            <button wire:click="setTab('report')" class="py-2 px-1 rounded-lg text-xs font-semibold flex items-center justify-center space-x-1 transition-all {{ $activeTab === 'report' ? 'bg-slate-800 text-white border border-slate-700 shadow-sm' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                <i class="fa-regular fa-file-lines text-slate-400"></i>
                <span class="truncate">1. 일일 보고서</span>
            </button>

            <button wire:click="setTab('qr')" class="py-2 px-1 rounded-lg text-xs font-semibold flex items-center justify-center space-x-1 transition-all {{ $activeTab === 'qr' ? 'bg-slate-800 text-white border border-slate-700 shadow-sm' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                <i class="fa-solid fa-qrcode text-slate-400"></i>
                <span class="truncate">2. QR 출퇴근</span>
            </button>

            <button wire:click="setTab('safety')" class="py-2 px-1 rounded-lg text-xs font-semibold flex items-center justify-center space-x-1 transition-all {{ $activeTab === 'safety' ? 'bg-slate-800 text-white border border-slate-700 shadow-sm' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                <i class="fa-solid fa-shield-halved text-slate-400"></i>
                <span class="truncate">3. 안전 점검 TBM</span>
            </button>

            <button wire:click="setTab('equipment')" class="py-2 px-1 rounded-lg text-xs font-semibold flex items-center justify-center space-x-1 transition-all {{ $activeTab === 'equipment' ? 'bg-slate-800 text-white border border-slate-700 shadow-sm' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                <i class="fa-solid fa-truck-ramp-box text-slate-400"></i>
                <span class="truncate">4. 장비 수불</span>
            </button>

            <button wire:click="setTab('knowledge')" class="py-2 px-1 rounded-lg text-xs font-semibold flex items-center justify-center space-x-1 transition-all {{ $activeTab === 'knowledge' ? 'bg-slate-800 text-white border border-slate-700 shadow-sm' : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200' }}">
                <i class="fa-solid fa-brain text-slate-400"></i>
                <span class="truncate">5. 도면 판독 & Q&A</span>
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
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3 mb-4">
                        <h2 class="text-sm font-bold text-slate-100 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span> Ⅰ. 현장 개요 및 일기 현황
                            @if($report_status === 'submitted')
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-slate-800 text-slate-200 border border-slate-600">제출 완료</span>
                            @else
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-slate-950 text-slate-400 border border-slate-800">작성중 (자동저장)</span>
                            @endif
                        </h2>
                        <button wire:click="$toggle('showSiteModal')" class="text-xs font-medium text-slate-300 hover:text-white bg-slate-800 border border-slate-700 px-2.5 py-1 rounded-md transition-colors">
                            <i class="fa-solid fa-gear mr-1 text-slate-400"></i>현장 관리
                        </button>
                    </div>

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
                                        <button wire:click="deleteSite({{ $site_id }})" wire:confirm="이 현장을 삭제하시겠습니까?" class="text-[10px] text-slate-400 hover:underline">삭제</button>
                                    </div>
                                @endif
                            </div>
                            <select wire:model.live="site_id" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-xs font-semibold text-slate-100 focus:border-slate-600 focus:outline-none">
                                @forelse($sites as $site)
                                    <option value="{{ $site->id }}">{{ $site->name }} ({{ $site->code }})</option>
                                @empty
                                    <option value="">— [현장 관리]에서 현장을 등록하세요 —</option>
                                @endforelse
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
                            <input type="text" wire:model.blur="temperature" placeholder="예: 18°C ~ 29°C" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-xs font-semibold text-slate-100">
                        </div>
                    </div>
                </div>

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

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm space-y-4">
                        <h2 class="text-sm font-bold text-slate-100 border-b border-slate-800 pb-3 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span> Ⅲ. 금일 작업 실적 보고 (Today's Progress)
                        </h2>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">공종 대표 작업명</label>
                            <input type="text" wire:model.blur="work_title" placeholder="예: A동 2층 메인 배관 서포트 용접 및 전기 트레이 설치" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-xs font-semibold text-slate-100">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 mb-1">금일 세부 작업 내역</label>
                            <textarea wire:model.blur="work_today" rows="4" placeholder="1. A동 2층 배관 서포트 용접 35포인트 완료&#10;2. 메인 케이블 트레이 설치 120m 완료" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-xs text-slate-200 leading-relaxed font-mono"></textarea>
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
                            <textarea wire:model.blur="work_tomorrow" rows="5" placeholder="1. A동 3층 메인 배관 입상관 용접 및 수압 테스트&#10;2. 고소작업대 이용 외벽 덕트 마감" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-xs text-slate-200 leading-relaxed font-mono"></textarea>
                        </div>
                        <button wire:click="saveDailyReport" wire:loading.attr="disabled" class="w-full py-2.5 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 text-white font-bold text-xs shadow-sm transition-all flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-floppy-disk text-slate-300"></i>
                            <span wire:loading.remove wire:target="saveDailyReport">일일 보고서 서버 전송 및 제출</span>
                            <span wire:loading wire:target="saveDailyReport">제출 중...</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <!-- TAB 2: QR 출퇴근 -->
        @if($activeTab === 'qr')
            <div class="max-w-2xl mx-auto space-y-6">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm text-center space-y-6">
                    <h2 class="text-sm font-bold text-slate-100 border-b border-slate-800 pb-3">현장 출퇴근 전자 태깅</h2>

                    @if($this->qrDataUri)
                        <div class="inline-block p-4 rounded-xl bg-white shadow-md">
                            <img src="{{ $this->qrDataUri }}" alt="현장 출퇴근 QR" class="w-44 h-44">
                        </div>
                        <p class="text-[10px] font-mono text-slate-500 tracking-wider">FIELD-QR · {{ $work_date }}</p>
                    @else
                        <div class="inline-block p-4 rounded-xl bg-white shadow-md">
                            <div class="w-44 h-44 bg-slate-950 rounded-lg p-3 flex flex-col items-center justify-center space-y-2">
                                <i class="fa-solid fa-qrcode text-5xl text-slate-200"></i>
                                <span class="text-[10px] font-mono text-slate-400 tracking-wider">현장 선택 후 QR 생성</span>
                            </div>
                        </div>
                    @endif

                    <div class="max-w-sm mx-auto">
                        <input type="text" wire:model.blur="commute_worker_name" placeholder="성명 입력 (예: 김반장)" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3.5 py-2.5 text-xs text-slate-100 text-center focus:border-slate-600 focus:outline-none">
                    </div>

                    <div class="grid grid-cols-2 gap-3 max-w-sm mx-auto">
                        <button wire:click="recordCommute('in')" wire:loading.attr="disabled" class="py-2.5 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 text-white font-bold text-xs transition-all">
                            <i class="fa-solid fa-right-to-bracket mr-1.5 text-slate-400"></i>출근 기록
                        </button>
                        <button wire:click="recordCommute('out')" wire:loading.attr="disabled" class="py-2.5 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 text-white font-bold text-xs transition-all">
                            <i class="fa-solid fa-right-from-bracket mr-1.5 text-slate-400"></i>퇴근 기록
                        </button>
                    </div>

                    <div class="bg-slate-950 rounded-lg border border-slate-800 max-w-sm mx-auto divide-y divide-slate-800 text-xs">
                        <div class="px-3 py-2 flex items-center justify-between text-slate-400 font-semibold">
                            <span>금일 태깅 기록</span>
                            <span>{{ $commuteLogs->count() }}건</span>
                        </div>
                        @forelse($commuteLogs as $log)
                            <div class="px-3 py-2 flex items-center justify-between">
                                <span class="text-slate-300 font-medium">
                                    <i class="fa-solid {{ $log->type === 'in' ? 'fa-right-to-bracket' : 'fa-right-from-bracket' }} mr-1.5 text-slate-500"></i>
                                    {{ $log->worker_name ?? '무기명' }}
                                </span>
                                <span class="text-slate-400">{{ $log->type === 'in' ? '출근' : '퇴근' }} · {{ $log->scanned_at->format('H:i:s') }}</span>
                            </div>
                        @empty
                            <div class="px-3 py-3 text-slate-500">아직 태깅 기록이 없습니다.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <!-- TAB 3: 안전 점검 TBM -->
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
                        <textarea wire:model.blur="safety_notes" rows="3" placeholder="예: 작업 전 보호구 및 고소작업대 벨트 안전 고리 100% 체결 확인" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-xs text-slate-200 font-mono"></textarea>
                    </div>
                    <button wire:click="saveSafetyCheck" wire:loading.attr="disabled" class="w-full py-2.5 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 text-white font-bold text-xs shadow-sm transition-all flex items-center justify-center space-x-2">
                        <i class="fa-solid fa-shield-halved text-slate-300"></i>
                        <span>안전점검 TBM 기록 저장</span>
                    </button>
                </div>
            </div>
        @endif

        <!-- TAB 4: 장비 수불 -->
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
                    <button wire:click="addEquipment" wire:loading.attr="disabled" class="mt-3 w-full py-2 bg-slate-800 border border-slate-700 hover:bg-slate-700 text-slate-100 font-bold text-xs rounded-lg transition-all">
                        + 장비 수불 등록
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-100 mb-4 border-b border-slate-800 pb-3">현장 장비 운용 현황 ({{ $equipments->count() }}대)</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @forelse($equipments as $eq)
                            <div class="bg-slate-950 border border-slate-800 rounded-lg p-3.5 flex items-center justify-between">
                                <div>
                                    <h3 class="text-xs font-bold text-slate-100">{{ $eq->name }}</h3>
                                    <p class="text-[11px] text-slate-400 mt-0.5">구분: {{ $eq->type }} | 조종원: {{ $eq->operator ?? '미정' }}</p>
                                </div>
                                <div class="flex items-center space-x-1.5">
                                    <button wire:click="toggleEquipmentStatus({{ $eq->id }})" class="px-2.5 py-1 rounded text-xs font-semibold border transition-all {{ $eq->status === 'running' ? 'bg-slate-800 text-slate-100 border-slate-700' : 'bg-slate-950 text-slate-500 border-slate-800' }}">
                                        {{ $eq->status === 'running' ? '가동중' : '대기중' }}
                                    </button>
                                    <button wire:click="removeEquipment({{ $eq->id }})" wire:confirm="이 장비 수불 기록을 삭제하시겠습니까?" class="text-[11px] text-slate-500 hover:text-white px-1"><i class="fa-solid fa-xmark"></i></button>
                                </div>
                            </div>
                        @empty
                            <div class="col-span-full text-xs text-slate-500 py-4 text-center">금일 등록된 장비 수불 기록이 없습니다.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <!-- TAB 5: AI 도면 판독 & 지식 Q&A (AI Drawing Knowledge Hub) -->
        @if($activeTab === 'knowledge')
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column: Blueprint Library & Upload -->
                <div class="space-y-6">
                    <!-- Upload Blueprint Box -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm">
                        <h2 class="text-sm font-bold text-slate-100 mb-3 border-b border-slate-800 pb-3 flex items-center justify-between">
                            <span class="flex items-center gap-2"><i class="fa-solid fa-cloud-arrow-up text-slate-400"></i> 신규 도면 업로드 & AI 스캔</span>
                            @if($aiLive)
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-slate-800 text-slate-200 border border-slate-600">AI Vision 연결됨</span>
                            @else
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-slate-950 text-slate-400 border border-slate-800">오프라인 모드</span>
                            @endif
                        </h2>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 mb-1">도면/도서 명칭</label>
                                <input type="text" wire:model.live="new_drawing_title" placeholder="예: C동 3층 기계실 주배관 평면도" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-1.5 text-xs text-slate-100">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 mb-1">도면 분류</label>
                                <select wire:model.live="new_drawing_category" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-1.5 text-xs text-slate-100">
                                    <option value="MEP 배관/전기 도면">MEP 배관/전기 도면</option>
                                    <option value="건축 구조 도면">건축 구조 도면</option>
                                    <option value="안전 시방서">안전 시방서 및 공정도</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 mb-1">도면 파일 (이미지/PDF, 최대 20MB)</label>
                                <input type="file" wire:model="drawing_file" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-1.5 text-xs text-slate-300 file:mr-2 file:rounded file:border-0 file:bg-slate-800 file:px-2 file:py-1 file:text-[11px] file:text-slate-200">
                                <div wire:loading wire:target="drawing_file" class="text-[11px] text-slate-400 mt-1"><i class="fa-solid fa-spinner fa-spin mr-1"></i>파일 업로드 중...</div>
                                @error('drawing_file') <span class="text-[11px] text-red-400 mt-1 block">{{ $message }}</span> @enderror
                            </div>
                            <button wire:click="uploadAndAnalyzeDrawing" wire:loading.attr="disabled" wire:target="uploadAndAnalyzeDrawing, drawing_file" class="w-full py-2 bg-slate-800 border border-slate-700 hover:bg-slate-700 disabled:opacity-50 text-slate-100 font-bold text-xs rounded-lg transition-all flex items-center justify-center space-x-1.5">
                                <span wire:loading.remove wire:target="uploadAndAnalyzeDrawing" class="flex items-center gap-1.5">
                                    <i class="fa-solid fa-brain text-slate-300"></i>
                                    <span>AI Vision 도면 판독 및 지식등록</span>
                                </span>
                                <span wire:loading wire:target="uploadAndAnalyzeDrawing" class="flex items-center gap-1.5">
                                    <i class="fa-solid fa-spinner fa-spin text-slate-300"></i>
                                    <span>AI 정밀 판독 중... (최대 1~2분)</span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Analyzed Drawing Library List -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm space-y-3">
                        <h2 class="text-sm font-bold text-slate-100 border-b border-slate-800 pb-3 flex items-center justify-between">
                            <span>등록된 도면 지식베이스 ({{ $drawings->count() }})</span>
                            <span class="text-[10px] text-slate-400">AI Vision Indexed</span>
                        </h2>

                        <div class="space-y-2">
                            @forelse($drawings as $dwg)
                                <div wire:click="selectDrawing({{ $dwg->id }})" class="p-3 rounded-lg border transition-all cursor-pointer relative group {{ $selected_drawing_id === $dwg->id ? 'bg-slate-800 border-slate-600' : 'bg-slate-950 border-slate-800 hover:border-slate-700' }}">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-slate-100 truncate max-w-[180px]">{{ $dwg->title }}</span>
                                        <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-900 text-slate-400 border border-slate-800">{{ $dwg->version }}</span>
                                    </div>
                                    <p class="text-[11px] text-slate-400 mt-1 line-clamp-2">{{ $dwg->summary ?? '판독 대기 중...' }}</p>
                                    <div class="mt-2 flex items-center justify-between text-[10px] text-slate-500">
                                        <span>{{ $dwg->category }}</span>
                                        <span class="flex items-center gap-1.5">
                                            @if($dwg->status === 'failed')
                                                <span class="text-red-400">판독 실패</span>
                                            @endif
                                            {{ $dwg->analyzed_at?->format('Y-m-d H:i') ?? '대기' }}
                                        </span>
                                    </div>
                                    <button wire:click.stop="removeDrawing({{ $dwg->id }})" wire:confirm="이 도면을 지식베이스에서 삭제하시겠습니까?" class="absolute top-1.5 right-1.5 opacity-0 group-hover:opacity-100 text-[10px] text-slate-500 hover:text-white transition-opacity"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            @empty
                                <div class="text-xs text-slate-500 py-4 text-center">등록된 도면이 없습니다.<br>위에서 도면을 업로드하면 AI가 판독해 드립니다.</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <!-- Right Column: Interactive AI Drawing Q&A Panel -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-sm space-y-4">
                        @if($selectedDrawing)
                            <!-- Drawing Specs Summary Header -->
                            <div class="bg-slate-950 p-4 rounded-lg border border-slate-800 space-y-2">
                                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                                    <span class="text-xs font-bold text-slate-200 flex items-center gap-1.5">
                                        <i class="fa-solid fa-file-pdf text-slate-400"></i> {{ $selectedDrawing->title }}
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-mono">{{ $selectedDrawing->drawing_no }}</span>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-[11px]">
                                    @forelse($selectedDrawing->specs ?? [] as $spec)
                                        <div class="bg-slate-900 px-2.5 py-1.5 rounded border border-slate-800 text-slate-300 font-medium truncate" title="{{ $spec }}">
                                            ✓ {{ $spec }}
                                        </div>
                                    @empty
                                        <div class="col-span-full text-slate-500">추출된 스펙이 없습니다.</div>
                                    @endforelse
                                </div>
                            </div>

                            <!-- Chat Messages Container -->
                            <div class="bg-slate-950 p-4 rounded-lg border border-slate-800 h-96 overflow-y-auto space-y-4">
                                @foreach($chatMessages as $msg)
                                    @if($msg->role === 'assistant')
                                        <div class="flex space-x-3">
                                            <div class="w-7 h-7 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-300 text-xs shrink-0">
                                                <i class="fa-solid fa-robot"></i>
                                            </div>
                                            <div class="space-y-1 max-w-xl">
                                                <div class="bg-slate-900 border border-slate-800 text-xs text-slate-200 p-3 rounded-lg leading-relaxed whitespace-pre-line font-mono">
                                                    {!! nl2br(e($msg->content)) !!}
                                                </div>
                                                @if($msg->sources)
                                                    <div class="flex items-center flex-wrap gap-2 text-[10px] text-slate-500">
                                                        <span>판독 근거:</span>
                                                        @foreach($msg->sources as $src)
                                                            <span class="bg-slate-900 px-1.5 py-0.5 rounded border border-slate-800">{{ $src }}</span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @else
                                        <div class="flex justify-end space-x-3">
                                            <div class="bg-slate-800 text-xs text-white p-3 rounded-lg max-w-lg font-medium border border-slate-700">
                                                {{ $msg->content }}
                                            </div>
                                            <div class="w-7 h-7 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-300 text-xs shrink-0">
                                                <i class="fa-solid fa-user"></i>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach

                                <div wire:loading wire:target="askDrawingQuestion" class="flex space-x-3">
                                    <div class="w-7 h-7 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-300 text-xs shrink-0">
                                        <i class="fa-solid fa-robot"></i>
                                    </div>
                                    <div class="bg-slate-900 border border-slate-800 text-xs text-slate-400 p-3 rounded-lg">
                                        <i class="fa-solid fa-spinner fa-spin mr-1.5"></i>AI가 도면을 분석해 답변을 작성 중입니다...
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Recommended Prompts -->
                            <div class="flex flex-wrap gap-2 text-xs">
                                <span class="text-slate-400 text-[11px] self-center">추천 질문:</span>
                                <button wire:click="$set('qa_question', '이 도면의 배관 서포트 간격 및 용접 주의사항 알려줘.')" class="bg-slate-950 hover:bg-slate-800 text-slate-300 px-2.5 py-1 rounded border border-slate-800 text-[11px]">
                                    💡 배관 서포트 간격 & 용접 규격
                                </button>
                                <button wire:click="$set('qa_question', '이 도면에서 고소작업 시 필수 안전 지침은?')" class="bg-slate-950 hover:bg-slate-800 text-slate-300 px-2.5 py-1 rounded border border-slate-800 text-[11px]">
                                    💡 고소작업 안전 지침 & LOTO
                                </button>
                            </div>

                            <!-- Q&A Question Input Box -->
                            <div class="flex space-x-2">
                                <input type="text" wire:model.live="qa_question" wire:keydown.enter="askDrawingQuestion" placeholder="도면에 대해 궁금한 점을 질문하세요 (예: 관경 규격, 용접 수칙, 자재 요구사항)..." class="flex-1 bg-slate-950 border border-slate-800 rounded-lg px-3.5 py-2.5 text-xs text-slate-100 focus:border-slate-600 focus:outline-none">
                                <button wire:click="askDrawingQuestion" wire:loading.attr="disabled" wire:target="askDrawingQuestion" class="bg-slate-800 hover:bg-slate-700 disabled:opacity-50 text-white font-bold text-xs px-4 py-2.5 rounded-lg border border-slate-700 transition-all">
                                    <i class="fa-solid fa-paper-plane mr-1 text-slate-300"></i>질문 전송
                                </button>
                            </div>
                        @else
                            <div class="h-96 flex flex-col items-center justify-center text-center space-y-3 text-slate-500">
                                <i class="fa-solid fa-compass-drafting text-4xl"></i>
                                <p class="text-xs leading-relaxed">아직 선택된 도면이 없습니다.<br>왼쪽에서 도면을 업로드하면 AI Vision이 판독 후<br>이곳에서 도면 기반 Q&A를 시작할 수 있습니다.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

    </main>
</div>

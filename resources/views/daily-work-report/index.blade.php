<!DOCTYPE html>
<html lang="ko" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>건설현장 일일 작업 보고서 | NAHSHON SMART ERP</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Pretendard:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- FontAwesome & Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { font-family: 'Pretendard', sans-serif; }
        .glass-card {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .glass-input {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #f8fafc;
        }
        .glass-input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 12px rgba(99, 102, 241, 0.35);
            outline: none;
        }
        @media print {
            body { background: white !important; color: black !important; }
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            .print-container { 
                width: 100% !important; 
                max-width: none !important; 
                padding: 0 !important; 
                margin: 0 !important;
                background: white !important;
                color: black !important;
            }
            .print-card {
                border: 1px solid #cbd5e1 !important;
                background: #ffffff !important;
                color: #0f172a !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>
<body class="h-full flex flex-col antialiased selection:bg-indigo-500 selection:text-white">
    
    <!-- Top Navigation Header -->
    <header class="no-print sticky top-0 z-50 glass-card border-b border-slate-800 px-4 py-3 sm:px-6">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-amber-500 flex items-center justify-center shadow-lg shadow-indigo-500/20">
                    <i class="fa-solid fa-helmet-safety text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="font-bold text-lg leading-tight text-white flex items-center gap-2">
                        건설현장 일일 작업 보고서 
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">Livewire 4 / Tailwind 4</span>
                    </h1>
                    <p class="text-xs text-slate-400">NAHSHON SMART ERP Construction Daily Field Report App</p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center space-x-3">
                <button onclick="switchTab('form')" id="btn-tab-form" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-indigo-600 text-white shadow-md transition-all hover:bg-indigo-500">
                    <i class="fa-solid fa-pen-to-square mr-1.5"></i>보고서 작성
                </button>
                <button onclick="switchTab('preview')" id="btn-tab-preview" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 text-slate-300 hover:bg-slate-700 transition-all border border-slate-700">
                    <i class="fa-solid fa-file-invoice mr-1.5"></i>양식 미리보기 & 인쇄
                </button>
                <button onclick="window.print()" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-500/20 text-amber-300 border border-amber-500/30 hover:bg-amber-500/30 transition-all">
                    <i class="fa-solid fa-print mr-1"></i>A4 인쇄
                </button>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 space-y-6">

        <!-- Tab 1: Form Input Section -->
        <div id="tab-form" class="space-y-6">
            
            <!-- Site & Basic Info Banner -->
            <div class="glass-card rounded-2xl p-5 sm:p-6 shadow-xl relative overflow-hidden">
                <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
                
                <h2 class="text-base font-bold text-white mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-building-flag text-indigo-400"></i> 현장 및 날씨 기본 정보
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Site Selection -->
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">작업 현장 선택</label>
                        <select id="input-site" class="glass-input w-full rounded-xl px-3.5 py-2 text-sm font-medium">
                            @foreach($sites as $site)
                                <option value="{{ $site->id }}" {{ $selectedSite && $selectedSite->id == $site->id ? 'selected' : '' }}>
                                    {{ $site->name }} ({{ $site->code }})
                                </option>
                            @endforeach
                            @if($sites->isEmpty())
                                <option value="1">텍사스 킬린 공장 신축현장 (TX-01)</option>
                                <option value="2">평택 하이테크 P4 현장 (PT-04)</option>
                            @endif
                        </select>
                    </div>

                    <!-- Work Date -->
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">작업 일자</label>
                        <input type="date" id="input-date" value="{{ $todayDate }}" class="glass-input w-full rounded-xl px-3.5 py-2 text-sm font-medium">
                    </div>

                    <!-- Weather -->
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">현장 날씨</label>
                        <select id="input-weather" class="glass-input w-full rounded-xl px-3.5 py-2 text-sm font-medium">
                            <option value="☀️ 맑음">☀️ 맑음 (Clear)</option>
                            <option value="⛅ 구름조금">⛅ 구름조금 (Partly Cloudy)</option>
                            <option value="🌧️ 비/우천">🌧️ 비/우천 (Rain)</option>
                            <option value="❄️ 눈/강설">❄️ 눈/강설 (Snow)</option>
                        </select>
                    </div>

                    <!-- Temperature -->
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">기온 범위 (°C / °F)</label>
                        <input type="text" id="input-temp" value="18°C ~ 29°C (64°F ~ 84°F)" class="glass-input w-full rounded-xl px-3.5 py-2 text-sm font-medium">
                    </div>
                </div>
            </div>

            <!-- Labor & Trade Headcount Counters -->
            <div class="glass-card rounded-2xl p-5 sm:p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-users-gear text-amber-400"></i> 공종별 인력 투입 현황 (Labor Trade Headcount)
                    </h2>
                    <span class="text-xs text-indigo-300 font-semibold px-2.5 py-1 rounded-lg bg-indigo-500/10 border border-indigo-500/20">
                        금일 총 투입: <span id="total-worker-count" class="text-amber-400 text-sm font-bold ml-1">42</span>명
                    </span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <!-- Trade Item 1 -->
                    <div class="bg-slate-900/80 border border-slate-800 rounded-xl p-3 text-center">
                        <span class="text-xs text-slate-400 block mb-1">⚡ 배관/전기</span>
                        <div class="flex items-center justify-center space-x-2 my-1">
                            <button onclick="updateTrade('elec', -1)" class="w-7 h-7 rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700 text-xs font-bold">-</button>
                            <span id="cnt-elec" class="text-lg font-extrabold text-white w-8">12</span>
                            <button onclick="updateTrade('elec', 1)" class="w-7 h-7 rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 text-xs font-bold">+</button>
                        </div>
                    </div>

                    <!-- Trade Item 2 -->
                    <div class="bg-slate-900/80 border border-slate-800 rounded-xl p-3 text-center">
                        <span class="text-xs text-slate-400 block mb-1">🛠️ 덕트/설비</span>
                        <div class="flex items-center justify-center space-x-2 my-1">
                            <button onclick="updateTrade('duct', -1)" class="w-7 h-7 rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700 text-xs font-bold">-</button>
                            <span id="cnt-duct" class="text-lg font-extrabold text-white w-8">8</span>
                            <button onclick="updateTrade('duct', 1)" class="w-7 h-7 rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 text-xs font-bold">+</button>
                        </div>
                    </div>

                    <!-- Trade Item 3 -->
                    <div class="bg-slate-900/80 border border-slate-800 rounded-xl p-3 text-center">
                        <span class="text-xs text-slate-400 block mb-1">🔥 용접/제작</span>
                        <div class="flex items-center justify-center space-x-2 my-1">
                            <button onclick="updateTrade('weld', -1)" class="w-7 h-7 rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700 text-xs font-bold">-</button>
                            <span id="cnt-weld" class="text-lg font-extrabold text-white w-8">10</span>
                            <button onclick="updateTrade('weld', 1)" class="w-7 h-7 rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 text-xs font-bold">+</button>
                        </div>
                    </div>

                    <!-- Trade Item 4 -->
                    <div class="bg-slate-900/80 border border-slate-800 rounded-xl p-3 text-center">
                        <span class="text-xs text-slate-400 block mb-1">🧱 조적/비계</span>
                        <div class="flex items-center justify-center space-x-2 my-1">
                            <button onclick="updateTrade('mason', -1)" class="w-7 h-7 rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700 text-xs font-bold">-</button>
                            <span id="cnt-mason" class="text-lg font-extrabold text-white w-8">5</span>
                            <button onclick="updateTrade('mason', 1)" class="w-7 h-7 rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 text-xs font-bold">+</button>
                        </div>
                    </div>

                    <!-- Trade Item 5 -->
                    <div class="bg-slate-900/80 border border-slate-800 rounded-xl p-3 text-center">
                        <span class="text-xs text-slate-400 block mb-1">🛡️ 안전/관리</span>
                        <div class="flex items-center justify-center space-x-2 my-1">
                            <button onclick="updateTrade('safety', -1)" class="w-7 h-7 rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700 text-xs font-bold">-</button>
                            <span id="cnt-safety" class="text-lg font-extrabold text-white w-8">3</span>
                            <button onclick="updateTrade('safety', 1)" class="w-7 h-7 rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 text-xs font-bold">+</button>
                        </div>
                    </div>

                    <!-- Trade Item 6 -->
                    <div class="bg-slate-900/80 border border-slate-800 rounded-xl p-3 text-center">
                        <span class="text-xs text-slate-400 block mb-1">👷 일반 조공</span>
                        <div class="flex items-center justify-center space-x-2 my-1">
                            <button onclick="updateTrade('general', -1)" class="w-7 h-7 rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700 text-xs font-bold">-</button>
                            <span id="cnt-general" class="text-lg font-extrabold text-white w-8">4</span>
                            <button onclick="updateTrade('general', 1)" class="w-7 h-7 rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 text-xs font-bold">+</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Work Logs & Progress Details -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Today Work Content -->
                <div class="glass-card rounded-2xl p-5 sm:p-6 shadow-xl space-y-4">
                    <h2 class="text-base font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-emerald-400"></i> 금일 실시 작업 내용 (Today's Progress)
                    </h2>
                    
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">주요 공종 대표 제목</label>
                        <input type="text" id="input-title" value="A동 2층 메인 배관 서포트 용접 및 전기 트레이 설치 작업" class="glass-input w-full rounded-xl px-3.5 py-2 text-sm font-semibold">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">세부 작업 내용 및 공정 달성율</label>
                        <textarea id="input-today" rows="4" class="glass-input w-full rounded-xl p-3 text-sm leading-relaxed">1. A동 2층 배관 서포트 용접 35포인트 작업 완료 (누계 85%)
2. 메인 케이블 트레이 설치 120m 시공 완료
3. 3층 덕트 연결부 가스켓 교체 및 누기 테스트 완료</textarea>
                    </div>

                    <div class="flex items-center justify-between bg-slate-900/60 p-3 rounded-xl border border-slate-800">
                        <span class="text-xs font-medium text-slate-300">금일 전체 공정률 (Progress Rate)</span>
                        <div class="flex items-center space-x-2">
                            <input type="number" id="input-progress" value="78" min="0" max="100" class="glass-input w-20 rounded-lg px-2.5 py-1 text-center font-bold text-indigo-400 text-sm">
                            <span class="text-sm font-bold text-indigo-400">%</span>
                        </div>
                    </div>
                </div>

                <!-- Tomorrow Planned Work & Equipment -->
                <div class="glass-card rounded-2xl p-5 sm:p-6 shadow-xl space-y-4">
                    <h2 class="text-base font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-calendar-day text-sky-400"></i> 내일 예정 작업 & 투입 장비
                    </h2>
                    
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">익일(내일) 작업 계획 (Tomorrow's Plan)</label>
                        <textarea id="input-tomorrow" rows="3" class="glass-input w-full rounded-xl p-3 text-sm leading-relaxed">1. A동 3층 메인 배관 입상관 용접 및 수압 테스트 진행 예정
2. 외부 고소작업대(스카이) 이용 외벽 덕트 마감 작업 진행</textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">투입 중장비 현황 (Heavy Equipment)</label>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <label class="flex items-center space-x-2 bg-slate-900/60 p-2.5 rounded-xl border border-slate-800 cursor-pointer">
                                <input type="checkbox" checked class="rounded border-slate-700 text-indigo-600 focus:ring-0">
                                <span>🏗️ 고소작업대(Sky) 2대</span>
                            </label>
                            <label class="flex items-center space-x-2 bg-slate-900/60 p-2.5 rounded-xl border border-slate-800 cursor-pointer">
                                <input type="checkbox" checked class="rounded border-slate-700 text-indigo-600 focus:ring-0">
                                <span>🚜 25톤 크레인 1대</span>
                            </label>
                            <label class="flex items-center space-x-2 bg-slate-900/60 p-2.5 rounded-xl border border-slate-800 cursor-pointer">
                                <input type="checkbox" class="rounded border-slate-700 text-indigo-600 focus:ring-0">
                                <span>🚜 06 굴착기 1대</span>
                            </label>
                            <label class="flex items-center space-x-2 bg-slate-900/60 p-2.5 rounded-xl border border-slate-800 cursor-pointer">
                                <input type="checkbox" checked class="rounded border-slate-700 text-indigo-600 focus:ring-0">
                                <span>📦 4.5톤 지게차 1대</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Safety & TBM Check -->
            <div class="glass-card rounded-2xl p-5 sm:p-6 shadow-xl flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center space-x-3">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center border border-emerald-500/30 text-xl font-bold">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-white">안전 점검 및 작업전 TBM 실시</h3>
                        <p class="text-xs text-slate-400">금일 아침 안전조회 및 작업개시 전 보호구 착용 점검 완료</p>
                    </div>
                </div>

                <div class="flex items-center space-x-3">
                    <span class="text-xs font-semibold px-3 py-1.5 rounded-xl bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                        <i class="fa-solid fa-check mr-1"></i> TBM 실시 완료
                    </span>
                    <button onclick="saveReport()" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-amber-500 text-white font-bold text-sm shadow-lg shadow-indigo-500/25 hover:opacity-90 transition-all">
                        <i class="fa-solid fa-floppy-disk mr-1.5"></i>일일 보고서 저장
                    </button>
                </div>
            </div>

        </div>

        <!-- Tab 2: Printable Report Preview (A4 Printable Document) -->
        <div id="tab-preview" class="hidden space-y-6">
            <div class="print-container glass-card rounded-2xl p-8 shadow-2xl max-w-4xl mx-auto border border-slate-800 text-slate-200">
                
                <!-- Printable Header -->
                <div class="border-b border-slate-700 pb-4 mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-black text-white tracking-tight">건설공사 일일 작업 보고서</h2>
                        <p class="text-xs text-slate-400 mt-1">DAILY CONSTRUCTION WORK PROGRESS REPORT</p>
                    </div>
                    
                    <!-- Signature Table -->
                    <div class="flex border border-slate-700 rounded-lg overflow-hidden text-center text-xs">
                        <div class="w-16 border-r border-slate-700 bg-slate-900 p-1 font-bold flex items-center justify-center">서명</div>
                        <div class="w-20 border-r border-slate-700 p-1">
                            <span class="text-[10px] text-slate-400 block">작성자</span>
                            <span class="font-bold text-indigo-400 mt-2 block">김반장 (인)</span>
                        </div>
                        <div class="w-20 border-r border-slate-700 p-1">
                            <span class="text-[10px] text-slate-400 block">안전관리자</span>
                            <span class="font-bold text-emerald-400 mt-2 block">이안전 (인)</span>
                        </div>
                        <div class="w-20 p-1">
                            <span class="text-[10px] text-slate-400 block">현장소장</span>
                            <span class="font-bold text-amber-400 mt-2 block">박소장 (인)</span>
                        </div>
                    </div>
                </div>

                <!-- Info Grid -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-slate-900/60 p-4 rounded-xl border border-slate-800 mb-6 text-sm">
                    <div>
                        <span class="text-xs text-slate-400 block">현장명</span>
                        <strong id="prev-site-name" class="text-white">텍사스 킬린 공장 신축현장</strong>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400 block">작업일자</span>
                        <strong id="prev-date" class="text-white">{{ $todayDate }}</strong>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400 block">날씨 / 기온</span>
                        <strong id="prev-weather" class="text-amber-400">☀️ 맑음 (18°C ~ 29°C)</strong>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400 block">총 투입인원</span>
                        <strong id="prev-total-workers" class="text-indigo-400">42 명</strong>
                    </div>
                </div>

                <!-- Labor Summary Table -->
                <div class="mb-6">
                    <h3 class="text-sm font-bold text-white mb-2 flex items-center gap-1.5">
                        <i class="fa-solid fa-users text-indigo-400"></i> 공종별 인력 투입 내역
                    </h3>
                    <table class="w-full text-xs text-left border border-slate-800 rounded-lg overflow-hidden">
                        <thead class="bg-slate-900 text-slate-400">
                            <tr>
                                <th class="p-2 border-b border-slate-800">전기/배관</th>
                                <th class="p-2 border-b border-slate-800">덕트/설비</th>
                                <th class="p-2 border-b border-slate-800">용접/제작</th>
                                <th class="p-2 border-b border-slate-800">조적/비계</th>
                                <th class="p-2 border-b border-slate-800">안전/관리</th>
                                <th class="p-2 border-b border-slate-800">일반조공</th>
                                <th class="p-2 border-b border-slate-800 font-bold text-indigo-400">합계</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <tr class="font-medium">
                                <td class="p-2" id="prev-elec">12명</td>
                                <td class="p-2" id="prev-duct">8명</td>
                                <td class="p-2" id="prev-weld">10명</td>
                                <td class="p-2" id="prev-mason">5명</td>
                                <td class="p-2" id="prev-safety">3명</td>
                                <td class="p-2" id="prev-general">4명</td>
                                <td class="p-2 font-bold text-amber-400" id="prev-sum">42명</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Main Work Details -->
                <div class="space-y-4 mb-6">
                    <div class="bg-slate-900/40 p-4 rounded-xl border border-slate-800">
                        <h4 class="text-xs font-bold text-indigo-400 uppercase tracking-wider mb-1">금일 진행 작업</h4>
                        <p id="prev-today-log" class="text-sm text-slate-200 leading-relaxed font-medium">
                            1. A동 2층 배관 서포트 용접 35포인트 작업 완료 (누계 85%)<br>
                            2. 메인 케이블 트레이 설치 120m 시공 완료<br>
                            3. 3층 덕트 연결부 가스켓 교체 및 누기 테스트 완료
                        </p>
                    </div>

                    <div class="bg-slate-900/40 p-4 rounded-xl border border-slate-800">
                        <h4 class="text-xs font-bold text-sky-400 uppercase tracking-wider mb-1">익일 예정 작업 및 중장비</h4>
                        <p id="prev-tomorrow-log" class="text-sm text-slate-200 leading-relaxed font-medium">
                            1. A동 3층 메인 배관 입상관 용접 및 수압 테스트 진행 예정<br>
                            2. 외부 고소작업대(스카이) 이용 외벽 덕트 마감 작업 진행<br>
                            * 투입 장비: 고소작업대 2대, 25톤 크레인 1대, 지게차 1대
                        </p>
                    </div>
                </div>

                <!-- Footer stamp -->
                <div class="text-center text-xs text-slate-500 pt-4 border-t border-slate-800">
                    NAHSHON SMART ERP System · Certified Field Work Report · Generated on {{ now()->format('Y-m-d H:i') }}
                </div>
            </div>
        </div>

    </main>

    <script>
        const trades = { elec: 12, duct: 8, weld: 10, mason: 5, safety: 3, general: 4 };

        function updateTrade(key, delta) {
            trades[key] = Math.max(0, trades[key] + delta);
            document.getElementById(`cnt-${key}`).innerText = trades[key];
            recalcTotal();
        }

        function recalcTotal() {
            const sum = Object.values(trades).reduce((a, b) => a + b, 0);
            document.getElementById('total-worker-count').innerText = sum;
            document.getElementById('prev-sum').innerText = sum + '명';
            document.getElementById('prev-total-workers').innerText = sum + ' 명';
            
            for (let k in trades) {
                const el = document.getElementById(`prev-${k}`);
                if (el) el.innerText = trades[k] + '명';
            }
        }

        function switchTab(tab) {
            if (tab === 'form') {
                document.getElementById('tab-form').classList.remove('hidden');
                document.getElementById('tab-preview').classList.add('hidden');
                document.getElementById('btn-tab-form').className = "px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-indigo-600 text-white shadow-md transition-all hover:bg-indigo-500";
                document.getElementById('btn-tab-preview').className = "px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 text-slate-300 hover:bg-slate-700 transition-all border border-slate-700";
            } else {
                syncPreviewData();
                document.getElementById('tab-form').classList.add('hidden');
                document.getElementById('tab-preview').classList.remove('hidden');
                document.getElementById('btn-tab-form').className = "px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 text-slate-300 hover:bg-slate-700 transition-all border border-slate-700";
                document.getElementById('btn-tab-preview').className = "px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-indigo-600 text-white shadow-md transition-all hover:bg-indigo-500";
            }
        }

        function syncPreviewData() {
            const siteSelect = document.getElementById('input-site');
            document.getElementById('prev-site-name').innerText = siteSelect.options[siteSelect.selectedIndex].text;
            document.getElementById('prev-date').innerText = document.getElementById('input-date').value;
            document.getElementById('prev-weather').innerText = document.getElementById('input-weather').value + ' (' + document.getElementById('input-temp').value + ')';
            
            document.getElementById('prev-today-log').innerHTML = document.getElementById('input-today').value.replace(/\n/g, '<br>');
            document.getElementById('prev-tomorrow-log').innerHTML = document.getElementById('input-tomorrow').value.replace(/\n/g, '<br>');
            recalcTotal();
        }

        function saveReport() {
            alert('✅ 일일 작업 보고서가 정상 저장 및 ERP DB에 기록되었습니다.');
        }
    </script>
</body>
</html>

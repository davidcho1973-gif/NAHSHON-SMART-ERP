<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI 문서 인텔리전스 | {{ \App\Support\Org::name() }} SMART ERP</title>
    <style>
        :root {
            --navy:#0b1220; --navy-2:#111c30; --panel:#fff; --canvas:#f3f6fb; --line:#dfe6f0;
            --text:#14213d; --muted:#67748c; --blue:#2563eb; --blue-soft:#eaf1ff; --cyan:#0891b2;
            --red:#dc2626; --red-soft:#fff0f0; --amber:#d97706; --amber-soft:#fff7e8;
            --green:#059669; --green-soft:#e9fbf5; --violet:#7c3aed; --shadow:0 16px 40px rgba(15,23,42,.08);
        }
        *{box-sizing:border-box} body{margin:0;background:var(--canvas);color:var(--text);font:14px/1.55 Inter,"Noto Sans KR",system-ui,-apple-system,sans-serif}
        button,input,select{font:inherit} button{cursor:pointer}.app{min-height:100vh;display:grid;grid-template-columns:248px 1fr}
        .sidebar{background:linear-gradient(180deg,var(--navy),#111827);color:#dbe7ff;padding:22px 17px;position:sticky;top:0;height:100vh}
        .brand{display:flex;align-items:center;gap:11px;padding:2px 8px 24px;border-bottom:1px solid rgba(255,255,255,.1)}
        .brand-mark{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#3b82f6,#7c3aed);display:grid;place-items:center;font-weight:900;color:white}
        .brand strong{display:block;font-size:14px}.brand small{color:#8fa4c4}.nav-label{font-size:10px;letter-spacing:.16em;color:#7184a3;font-weight:800;margin:23px 10px 9px}
        .nav-link{display:flex;gap:10px;align-items:center;padding:11px 12px;border-radius:10px;color:#c5d3e8;text-decoration:none;margin:4px 0}
        .nav-link:hover,.nav-link.active{background:rgba(59,130,246,.18);color:white}.nav-link.active{box-shadow:inset 3px 0 #60a5fa}
        .sidebar-note{position:absolute;left:17px;right:17px;bottom:20px;padding:13px;border:1px solid rgba(96,165,250,.25);border-radius:12px;background:rgba(37,99,235,.1);font-size:11px;color:#a9bdd9}
        main{min-width:0}.topbar{height:70px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:20}
        .crumb{font-size:12px;color:var(--muted)}.crumb b{color:var(--text)}.top-actions{display:flex;gap:9px}.btn{border:1px solid var(--line);background:#fff;color:var(--text);padding:9px 13px;border-radius:9px;font-weight:700;display:inline-flex;align-items:center;gap:7px;text-decoration:none}
        .btn:hover{border-color:#aab8ce}.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}.btn.dark{background:var(--navy);border-color:var(--navy);color:#fff}.btn.small{padding:6px 9px;font-size:12px}
        .content{padding:28px;max-width:1700px;margin:0 auto}.hero{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;margin-bottom:21px}
        h1{font-size:27px;margin:0 0 5px;letter-spacing:-.03em}.hero p{margin:0;color:var(--muted)}.live-pill{padding:7px 11px;border-radius:999px;background:var(--green-soft);color:var(--green);font-size:11px;font-weight:800}
        .stats{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:12px;margin-bottom:18px}.stat{background:var(--panel);border:1px solid var(--line);border-radius:13px;padding:15px 16px;box-shadow:0 3px 12px rgba(15,23,42,.035)}
        .stat span{font-size:11px;color:var(--muted);font-weight:700}.stat strong{font-size:25px;display:block;margin-top:3px}.stat.danger{border-left:3px solid var(--red)}.stat.warn{border-left:3px solid var(--amber)}
        .workspace{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:17px}.panel{background:var(--panel);border:1px solid var(--line);border-radius:14px;box-shadow:0 4px 20px rgba(15,23,42,.035);overflow:hidden}
        .panel-head{display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between;align-items:center;padding:15px 18px;border-bottom:1px solid var(--line)}.panel-head .btn{white-space:nowrap}.panel-head h2{font-size:15px;margin:0}.panel-head p{font-size:11px;color:var(--muted);margin:2px 0 0}
        .drop-panel{padding:16px}.scope-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-bottom:11px}.field label{display:block;font-size:10px;font-weight:800;color:var(--muted);margin:0 0 5px}
        .field select,.field input{width:100%;border:1px solid var(--line);border-radius:8px;background:#fff;padding:9px 10px;color:var(--text);outline:none}.field select:focus,.field input:focus{border-color:#7ca5fb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
        .dropzone{min-height:180px;border:2px dashed #a8bce0;border-radius:14px;background:linear-gradient(135deg,#f8fbff,#f6f3ff);display:grid;place-items:center;text-align:center;padding:25px;transition:.2s}
        .dropzone.drag{border-color:var(--blue);background:var(--blue-soft);transform:scale(1.005)}.drop-icon{width:55px;height:55px;border-radius:17px;background:#fff;box-shadow:var(--shadow);display:grid;place-items:center;font-size:27px;margin:0 auto 11px}
        .dropzone strong{font-size:15px}.dropzone p{margin:5px 0 12px;color:var(--muted);font-size:12px}.dropzone small{display:block;color:#8b98ad;margin-top:9px}.queue{display:none;margin-top:11px;border:1px solid var(--line);border-radius:10px;padding:9px;background:#fafcff}
        .queue.show{display:block}.queue-row{display:flex;justify-content:space-between;gap:8px;padding:5px 3px;font-size:11px}.progress{height:5px;background:#e8edf5;border-radius:10px;overflow:hidden;margin-top:7px}.progress i{display:block;width:0;height:100%;background:linear-gradient(90deg,var(--blue),var(--violet));transition:.25s}
        .memory-list{padding:8px 16px 16px}.memory-item{border:1px solid var(--line);border-radius:10px;padding:11px 12px;margin-top:8px}.memory-item.critical{background:var(--red-soft);border-color:#fecaca}.memory-item.warning{background:var(--amber-soft);border-color:#fde0a7}
        .memory-item strong{display:block;font-size:12px}.memory-item p{font-size:11px;color:var(--muted);margin:4px 0}.memory-meta{display:flex;gap:7px;align-items:center;font-size:10px;color:var(--muted)}
        {{-- 칸이 넷에서 다섯(현장)으로 늘면서 고정 폭 그리드로는 좁은 화면에서 검색 버튼이 잘렸다. 남는 폭을 나눠 갖고 모자라면 줄을 바꾼다. --}}
        .searchbar{display:flex;flex-wrap:wrap;gap:8px;padding:13px 16px;border-bottom:1px solid var(--line);background:#fbfcfe}.searchbar input,.searchbar select{border:1px solid var(--line);border-radius:8px;padding:9px 10px;background:#fff;color:var(--text);min-width:0}.searchbar #search{flex:2 1 190px}.searchbar select{flex:1 1 118px}.searchbar .btn{flex:0 0 auto}
        .doc-table{width:100%;border-collapse:collapse}.doc-table th{text-align:left;padding:10px 12px;font-size:10px;letter-spacing:.03em;color:var(--muted);background:#f8fafc;border-bottom:1px solid var(--line)}.doc-table td{padding:11px 12px;border-bottom:1px solid #edf1f6;vertical-align:middle}.doc-table tr:hover td{background:#fafcff}
        .doc-title{font-weight:750;font-size:12px;max-width:360px}.doc-sub{color:var(--muted);font-size:10px;margin-top:2px}.badge{display:inline-flex;align-items:center;padding:3px 7px;border-radius:999px;font-size:10px;font-weight:800;background:#eef2f7;color:#53637a}.badge.ready{background:var(--green-soft);color:var(--green)}.badge.analyzing,.badge.queued{background:var(--blue-soft);color:var(--blue)}.badge.review_required{background:var(--amber-soft);color:var(--amber)}.badge.failed{background:var(--red-soft);color:var(--red)}
        .action-count{color:var(--red);font-weight:800}.empty{padding:55px 20px;text-align:center;color:var(--muted)}.loading{opacity:.55;pointer-events:none}
        .drawer-bg{display:none;position:fixed;inset:0;background:rgba(2,8,23,.58);z-index:100}.drawer-bg.open{display:block}.drawer{position:absolute;right:0;top:0;bottom:0;width:min(780px,94vw);background:#f7f9fc;overflow:auto;box-shadow:-18px 0 50px rgba(0,0,0,.18)}
        .drawer-head{position:sticky;top:0;z-index:3;background:#fff;border-bottom:1px solid var(--line);padding:16px 19px;display:flex;justify-content:space-between;gap:15px}.drawer-head h2{font-size:17px;margin:0}.drawer-body{padding:16px}.detail-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:13px}.detail-chip{background:#fff;border:1px solid var(--line);border-radius:9px;padding:9px}.detail-chip span{display:block;font-size:9px;color:var(--muted);font-weight:800}.detail-chip b{font-size:11px}
        .section{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px 15px;margin-bottom:12px}.section h3{font-size:13px;margin:0 0 8px}.section p{font-size:12px;color:#46556d;white-space:pre-wrap}.fact{padding:8px 10px;margin:6px 0;border-left:3px solid var(--blue);background:#f5f8ff;border-radius:0 8px 8px 0;font-size:11px}.tag{display:inline-block;padding:3px 7px;border-radius:7px;background:#edf2fa;margin:2px;font-size:10px;color:#45546a}
        .action-card{border:1px solid var(--line);border-radius:9px;padding:10px;margin-top:8px}.action-card.critical,.action-card.high{border-left:3px solid var(--red)}.action-card.warning{border-left:3px solid var(--amber)}.action-card strong{font-size:12px}.action-card p{margin:4px 0}.action-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;color:var(--muted);font-size:10px}
        .toast{position:fixed;right:22px;bottom:22px;z-index:200;background:var(--navy);color:#fff;border-radius:10px;padding:11px 15px;box-shadow:var(--shadow);display:none}.toast.show{display:block}.toast.error{background:#991b1b}
        .viewer-bg{display:none;position:fixed;inset:0;background:rgba(2,8,23,.72);z-index:300;padding:22px}.viewer-bg.open{display:grid;place-items:center}
        .viewer{width:min(1240px,97vw);height:92vh;background:#fff;border-radius:14px;box-shadow:0 30px 80px rgba(0,0,0,.45);display:flex;flex-direction:column;overflow:hidden}
        .viewer-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line);background:#fbfcfe}.viewer-head .vh-title{font-weight:800;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.viewer-head .vh-actions{display:flex;gap:8px;flex-shrink:0}
        .viewer-body{flex:1;overflow:auto;background:#f3f6fb}.viewer-body iframe{width:100%;height:100%;border:0;background:#fff}.viewer-body img{max-width:100%;display:block;margin:0 auto}
        .viewer-doc{background:#fff;max-width:900px;margin:20px auto;padding:38px 46px;box-shadow:0 2px 12px rgba(0,0,0,.08);font-size:14px;line-height:1.7;color:#1f2937}.viewer-doc h1,.viewer-doc h2,.viewer-doc h3{line-height:1.3}.viewer-doc img{max-width:100%}.viewer-doc table{border-collapse:collapse;margin:10px 0}.viewer-doc td,.viewer-doc th{border:1px solid #cbd5e1;padding:5px 9px}
        .viewer-pre{white-space:pre-wrap;word-break:break-word;padding:24px;font:13px/1.6 ui-monospace,Menlo,Consolas,monospace;color:#1f2937}
        .xls-wrap{padding:14px}.xls-wrap table{border-collapse:collapse;font-size:12px;background:#fff}.xls-wrap td,.xls-wrap th{border:1px solid #d0d7e2;padding:4px 8px;white-space:nowrap;max-width:360px;overflow:hidden;text-overflow:ellipsis;vertical-align:top}.xls-wrap tr:first-child td{background:#f1f5fb;font-weight:700}
        .viewer-msg,.viewer-spin{padding:56px 24px;text-align:center;color:var(--muted);line-height:1.7}
        .tidy-bg{display:none;position:fixed;inset:0;background:rgba(2,8,23,.62);z-index:250;padding:22px}.tidy-bg.open{display:grid;place-items:center}
        .tidy{width:min(1020px,96vw);max-height:90vh;background:#fff;border-radius:14px;box-shadow:0 30px 80px rgba(0,0,0,.4);display:flex;flex-direction:column;overflow:hidden}
        .tidy-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;padding:15px 18px;border-bottom:1px solid var(--line)}.tidy-head h2{font-size:16px;margin:0}.tidy-head p{font-size:11px;color:var(--muted);margin:5px 0 0;line-height:1.65;max-width:640px;word-break:keep-all}
        .tidy-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:11px 18px;border-bottom:1px solid var(--line);background:#fbfcfe}.tidy-bar select{border:1px solid var(--line);border-radius:8px;padding:8px 10px;background:#fff;color:var(--text);min-width:190px}.tidy-bar .grow{flex:1}
        .tidy-body{overflow:auto;flex:1}.tidy-note{font-size:11px;color:var(--muted)}
        .src{display:inline-block;margin-left:6px;font-size:9px;font-weight:800;color:var(--muted)}
        @media(max-width:1100px){.app{grid-template-columns:78px 1fr}.brand div,.nav-link span,.nav-label,.sidebar-note{display:none}.brand{padding-left:3px}.sidebar{padding:18px 12px}.nav-link{justify-content:center}.workspace{grid-template-columns:1fr}.stats{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:700px){.app{display:block}.sidebar{display:none}.topbar{padding:0 14px}.content{padding:17px 12px}.hero{align-items:flex-start}.stats{grid-template-columns:repeat(2,1fr)}.scope-grid,.searchbar{grid-template-columns:1fr}.doc-table th:nth-child(3),.doc-table td:nth-child(3),.doc-table th:nth-child(4),.doc-table td:nth-child(4){display:none}.detail-grid{grid-template-columns:repeat(2,1fr)}}
    </style>
    @if(request()->boolean('embed'))
    {{-- ERP(SPA) 안에 iframe 으로 얹힐 때: 이 페이지 자체의 사이드바를 숨긴다.
         ERP 사이드바가 이미 왼쪽에 있는데 여기 것까지 보이면 사이드바가 두 개가 된다. --}}
    <style>
        .sidebar{display:none!important}
        .app{display:block}
    </style>
    <script>
        // ERP 로 돌아가는 링크(ERP 홈·알림센터·/admin)는 iframe 안이 아니라 바깥(전체 창)에서 열려야 한다.
        // 안 그러면 ERP 속 iframe 속에 또 ERP 가 뜬다.
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('a[href^="/"]').forEach(function (a) {
                var h = a.getAttribute('href') || '';
                if (h.indexOf('/document-hub') === 0) return; // 문서함 내부 이동은 iframe 안에서
                a.setAttribute('target', '_top');
            });
        });
    </script>
    @endif
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">@if (\App\Support\Org::hasLogo())<img class="brand-mark" style="background:none;object-fit:contain" src="{{ route('org.logo') }}?v={{ \App\Support\Org::logoVersion() }}" alt="">@else<div class="brand-mark">{{ \App\Support\Org::initials() }}</div>@endif<div><strong>{{ \App\Support\Org::name() }}</strong><small>DOCUMENT CONTROL</small></div></div>
        <div class="nav-label">WORKSPACE</div>
        <a class="nav-link" href="/"><b>⌂</b><span>ERP 홈</span></a>
        <a class="nav-link active" href="/document-hub"><b>✦</b><span>AI 통합 문서함</span></a>
        <a class="nav-link" href="/?view=alerts"><b>♢</b><span>통합 알림센터</span></a>
        <a class="nav-link" href="/admin/project-contracts"><b>▣</b><span>원청 계약·서류</span></a>
        <div class="nav-label">PRINCIPLES</div>
        <div class="nav-link"><b>✓</b><span>원본 불변</span></div>
        <div class="nav-link"><b>⌕</b><span>본문·키워드 검색</span></div>
        <div class="nav-link"><b>!</b><span>선제 위험 알림</span></div>
        <div class="sidebar-note">파일은 비공개 Object Storage에 보관하고, AI 판단과 사람의 검토 이력을 함께 남깁니다.</div>
    </aside>
    <main>
        <header class="topbar">
            <div class="crumb">{{ \App\Support\Org::name() }} 통합관리　›　<b>AI 문서 인텔리전스</b></div>
            <div class="top-actions">
                <a class="btn" href="{{ route('document-intelligence.export-index') }}">⇩ 인덱스 CSV</a>
                <a class="btn dark" href="/?view=alerts">통합 알림센터</a>
            </div>
        </header>
        <div class="content">
            <div class="hero">
                <div><h1>AI 공사 문서 인텔리전스</h1><p>파일을 넣으면 AI가 읽고, 분류하고, 기억하고, 위험과 기한을 먼저 알려줍니다.</p></div>
                <div class="live-pill">● PRIVATE STORAGE · AI INDEX ACTIVE</div>
            </div>
            <div class="stats">
                <div class="stat"><span>전체 문서</span><strong id="stat-total">0</strong></div>
                <div class="stat"><span>AI 분석 중</span><strong id="stat-analyzing">0</strong></div>
                <div class="stat warn"><span>사람 검토 필요</span><strong id="stat-review">0</strong></div>
                <div class="stat"><span>미완료 후속조치</span><strong id="stat-actions">0</strong></div>
                <div class="stat danger"><span>긴급·고위험</span><strong id="stat-critical">0</strong></div>
            </div>

            <div class="workspace">
                <section class="panel">
                    <div class="panel-head"><div><h2>통합 문서 검색·인덱스</h2><p>파일명, 본문, 문서번호, Revision, 키워드와 AI 요약을 한 번에 검색합니다.</p></div><div style="display:flex;gap:6px"><button class="btn small" id="tidy-btn" style="display:none" title="현장이 비어 있는 문서에 현장을 한 번에 붙입니다">⚑ 현장 정리</button><button class="btn small" id="unstick-btn" title="AI 분석 중에서 멈춘 문서를 다시 분석합니다">⟳ 멈춘 분석 재시도</button><button class="btn small" id="refresh-btn">↻ 새로고침</button></div></div>
                    <div class="searchbar">
                        <input id="search" placeholder="예: RFI-023, backcharge, cable tray, 30일 notice…">
                        <select id="site-filter"><option value="">전체 현장</option><option value="none">현장 미지정</option>@foreach($sites as $id => $label)<option value="{{ $id }}" @selected((int) ($defaultSiteId ?? 0) === (int) $id)>{{ $label }}</option>@endforeach</select>
                        <select id="category-filter"><option value="">전체 분류</option>@foreach(\App\Models\IntelligentDocument::CATEGORY_OPTIONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                        <select id="project-filter"><option value="">전체 PROJECT</option>@foreach($projects as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                        <button class="btn primary" id="search-btn">검색</button>
                    </div>
                    <div style="overflow:auto"><table class="doc-table"><thead><tr><th>문서</th><th>AI 분류</th><th>PROJECT / 폴더</th><th>문서일·Revision</th><th>기억·조치</th><th></th></tr></thead><tbody id="doc-list"><tr><td colspan="6" class="empty">문서 인덱스를 불러오는 중입니다.</td></tr></tbody></table></div>
                </section>

                <aside>
                    @if($canManage)
                    <section class="panel" style="margin-bottom:17px">
                        <div class="panel-head"><div><h2>AI 문서 드롭존</h2><p>최대 50개 파일을 한 번에 올릴 수 있습니다.</p></div></div>
                        <div class="drop-panel">
                            <div class="scope-grid">
                                {{-- 기본 소속: ERP 에서 고른 현장 > 본인 소속 현장 > Global(수퍼관리자·고위관리자·회계). 바꾸고 싶으면 바꾸면 된다 — 시작점만 맞춰 둔다. --}}
                                <div class="field"><label>회사</label><select id="upload-company"><option value="">자동/Global</option>@foreach($companies as $id => $label)<option value="{{ $id }}" @selected((int) ($defaultCompanyId ?? 0) === (int) $id)>{{ $label }}</option>@endforeach</select></div>
                                <div class="field"><label>현장</label><select id="upload-site"><option value="">자동/공통</option>@foreach($sites as $id => $label)<option value="{{ $id }}" @selected((int) ($defaultSiteId ?? 0) === (int) $id)>{{ $label }}</option>@endforeach</select></div>
                                <div class="field"><label>PROJECT</label><select id="upload-project"><option value="">AI 확인</option>@foreach($projects as $id => $label)<option value="{{ $id }}" @selected((int) ($defaultProjectId ?? 0) === (int) $id)>{{ $label }}</option>@endforeach</select></div>
                            </div>
                            <div class="dropzone" id="dropzone">
                                <div><div class="drop-icon">⇧</div><strong>문서를 여기에 끌어다 놓으세요</strong><p>또는 버튼을 눌러 여러 파일을 선택하세요.</p><button class="btn primary" id="pick-files">파일 선택</button><small>PDF · Word · Excel · CSV · TXT · 이미지 · EML / 파일당 최대 {{ $maxUploadMb }}MB</small></div>
                            </div>
                            <input id="file-input" type="file" multiple hidden accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.rtf,.jpg,.jpeg,.png,.webp,.tif,.tiff,.eml">
                            <div class="queue" id="upload-queue"><div id="queue-files"></div><div class="progress"><i id="upload-progress"></i></div></div>
                        </div>
                    </section>
                    @endif
                    <section class="panel">
                        <div class="panel-head"><div><h2>기억·예방 큐</h2><p>문서에서 발견한 중요한 기한과 위험입니다.</p></div></div>
                        <div class="memory-list" id="memory-list"><div class="empty" style="padding:28px 10px">문서를 선택하면 후속조치가 표시됩니다.</div></div>
                    </section>
                </aside>
            </div>
        </div>
    </main>
</div>

<div class="drawer-bg" id="drawer-bg"><div class="drawer"><div class="drawer-head"><div><h2 id="detail-title">문서 상세</h2><div class="doc-sub" id="detail-file"></div></div><button class="btn" id="drawer-close">닫기</button></div><div class="drawer-body" id="drawer-body"></div></div></div>
<div class="toast" id="toast"></div>

{{-- 현장 미지정 문서 일괄 정리 --}}
<div class="tidy-bg" id="tidy-bg">
  <div class="tidy">
    <div class="tidy-head">
      <div>
        <h2>현장 미지정 문서 정리 <span class="tidy-note" id="tidy-total"></span></h2>
        <p>현장이 비어 있는 문서는 현장 화면 어디에도 뜨지 않습니다. PROJECT가 붙어 있으면 그 PROJECT의 현장을, 없으면 제목·파일명에서 현장 코드를 찾아 제안합니다. 확실하지 않으면 제안하지 않습니다 — 현장을 직접 골라 주세요.</p>
      </div>
      <button class="btn" id="tidy-close">닫기</button>
    </div>
    <div class="tidy-bar">
      <select id="tidy-site"><option value="">제안대로 (문서마다 다름)</option></select>
      <button class="btn primary" id="tidy-apply">선택한 문서에 적용</button>
      <span class="grow"></span>
      <button class="btn small" id="tidy-all">전체 선택</button>
      <button class="btn small" id="tidy-suggested">제안 있는 것만</button>
      <button class="btn small" id="tidy-none">선택 해제</button>
    </div>
    <div class="tidy-body"><table class="doc-table"><thead><tr><th style="width:38px"></th><th>문서</th><th style="width:110px">접수일</th><th style="width:180px">제안 현장</th></tr></thead><tbody id="tidy-list"><tr><td colspan="4" class="empty">불러오는 중…</td></tr></tbody></table></div>
  </div>
</div>

<div class="viewer-bg" id="viewer-bg">
  <div class="viewer">
    <div class="viewer-head">
      <div class="vh-title" id="viewer-title">문서</div>
      <div class="vh-actions">
        <a class="btn small" id="viewer-dl" href="#" download>원본 다운로드</a>
        <button class="btn small" id="viewer-close">닫기 ✕</button>
      </div>
    </div>
    <div class="viewer-body" id="viewer-body"></div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const canManage = @json($canManage);
const endpoints = {
    list: @json(route('document-intelligence.documents')),
    upload: @json(route('document-intelligence.upload')),
    reanalyzeStuck: @json(route('document-intelligence.reanalyze-stuck')),
    show: @json(url('/document-hub/api/documents')),
    actions: @json(url('/document-hub/api/actions')),
    unassigned: @json(route('document-intelligence.unassigned')),
    assignSite: @json(route('document-intelligence.assign-site')),
    aiJob: @json(url('/document-hub/api/ai-jobs')),
};
const CATEGORY_OPTIONS = @json(collect(\App\Models\IntelligentDocument::CATEGORY_OPTIONS)->map(fn($l,$v)=>['value'=>$v,'label'=>$l])->values());
const TYPE_OPTIONS = @json(collect(\App\Models\IntelligentDocument::TYPE_OPTIONS)->map(fn($l,$v)=>['value'=>$v,'label'=>$l])->values());
let currentDocuments = [];
let currentDoc = null;
let pollTimer = null;
const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const fmtBytes = bytes => !bytes ? '-' : bytes >= 1048576 ? (bytes/1048576).toFixed(1)+' MB' : (bytes/1024).toFixed(1)+' KB';
const statusLabel = status => ({queued:'접수됨',analyzing:'AI 분석 중',ready:'정리 완료',review_required:'검토 필요',failed:'분석 실패'}[status] || status);
function toast(message, error=false){const el=document.getElementById('toast');el.textContent=message;el.className='toast show'+(error?' error':'');setTimeout(()=>el.className='toast',3500)}
async function jsonFetch(url, options={}){const response=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf,...(options.headers||{})},...options});const data=await response.json().catch(()=>({success:false,error:'응답을 읽을 수 없습니다.'}));if(!response.ok||data.success===false)throw new Error(data.error||data.message||'요청 실패');return data}

async function loadDocuments(){
    // ERP 안에 얹혀 열릴 때 상단 전환기의 현장이 주소로 실려 온다. 그 현장 문서만 본다.
    // 그때는 이 화면의 현장 선택기를 숨긴다 — 현장을 고르는 곳이 두 군데면 어느 쪽이
    // 이겼는지 화면만 봐서는 알 수 없다.
    const embeddedSite=new URLSearchParams(location.search).get('site_id')||'';
    const siteSel=document.getElementById('site-filter');
    if(embeddedSite)siteSel.style.display='none';
    const site=embeddedSite||siteSel.value;
    const params=new URLSearchParams({q:document.getElementById('search').value,category:document.getElementById('category-filter').value,project_id:document.getElementById('project-filter').value});
    if(site)params.set('site_id',site);
    const list=document.getElementById('doc-list'); list.classList.add('loading');
    try{
        const data=await jsonFetch(endpoints.list+'?'+params.toString()); currentDocuments=data.documents||[]; renderRows();
        const s=data.stats||{};document.getElementById('stat-total').textContent=s.total||0;document.getElementById('stat-analyzing').textContent=s.analyzing||0;document.getElementById('stat-review').textContent=s.review_required||0;document.getElementById('stat-actions').textContent=s.open_actions||0;document.getElementById('stat-critical').textContent=s.critical_actions||0;
        // 정리할 문서가 있을 때만 정리 버튼이 나온다 — 할 일이 없으면 버튼도 없다.
        const tidyBtn=document.getElementById('tidy-btn');
        if(tidyBtn&&canManage){const n=s.unassigned||0;tidyBtn.style.display=n>0?'':'none';tidyBtn.textContent='⚑ 현장 미지정 '+n+'건 정리'}
        clearTimeout(pollTimer); if((s.analyzing||0)>0)pollTimer=setTimeout(loadDocuments,5000);
    }catch(e){list.innerHTML='<tr><td colspan="6" class="empty">'+esc(e.message)+'</td></tr>'}finally{list.classList.remove('loading')}
}

let tidyRows=[];
async function openTidy(){
    document.getElementById('tidy-bg').classList.add('open');
    const list=document.getElementById('tidy-list');
    list.innerHTML='<tr><td colspan="4" class="empty">불러오는 중…</td></tr>';
    try{
        const data=await jsonFetch(endpoints.unassigned);
        tidyRows=data.rows||[];
        const sel=document.getElementById('tidy-site');
        sel.innerHTML='<option value="">제안대로 (문서마다 다름)</option>'+(data.sites||[]).map(s=>`<option value="${s.id}">${esc(s.label)}</option>`).join('');
        document.getElementById('tidy-total').textContent='· 전체 '+(data.total||0)+'건 중 '+tidyRows.length+'건 표시 · 제안 '+(data.suggested||0)+'건';
        renderTidy();
    }catch(e){list.innerHTML='<tr><td colspan="4" class="empty">'+esc(e.message)+'</td></tr>'}
}
function renderTidy(){
    const list=document.getElementById('tidy-list');
    if(!tidyRows.length){list.innerHTML='<tr><td colspan="4" class="empty">현장이 비어 있는 문서가 없습니다.</td></tr>';return}
    list.innerHTML=tidyRows.map(r=>`<tr>
      <td><input type="checkbox" class="tidy-pick" value="${r.id}"${r.suggestedSiteId?' checked':''}></td>
      <td><div class="doc-title">${esc(r.title)}</div><div class="doc-sub">${esc(r.fileName||'')}${r.project?' · '+esc(r.project):''}${r.documentNumber?' · No. '+esc(r.documentNumber):''}</div></td>
      <td><div class="doc-sub">${esc(r.receivedAt||'-')}</div></td>
      <td>${r.suggestedSite?`<span class="badge ready">${esc(r.suggestedSite)}</span><span class="src">${r.suggestedFrom==='project'?'PROJECT 근거':'이름에서 찾음'}</span>`:'<span class="badge">제안 없음</span>'}</td></tr>`).join('');
}
function tidyPicked(){return Array.from(document.querySelectorAll('.tidy-pick:checked')).map(c=>parseInt(c.value,10))}
function tidySelect(mode){
    document.querySelectorAll('.tidy-pick').forEach(c=>{
        const row=tidyRows.find(r=>String(r.id)===c.value);
        c.checked=mode==='all'?true:mode==='none'?false:!!(row&&row.suggestedSiteId);
    });
}
async function applyTidy(){
    const ids=tidyPicked();
    if(!ids.length){toast('문서를 먼저 선택하세요.',true);return}
    const siteId=document.getElementById('tidy-site').value;
    const btn=document.getElementById('tidy-apply');btn.disabled=true;
    try{
        const data=await jsonFetch(endpoints.assignSite,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ids:ids,site_id:siteId?parseInt(siteId,10):null})});
        toast(data.message);
        await openTidy(); loadDocuments();
    }catch(e){toast(e.message,true)}finally{btn.disabled=false}
}
function renderRows(){
    const list=document.getElementById('doc-list');
    if(!currentDocuments.length){list.innerHTML='<tr><td colspan="6" class="empty">검색된 문서가 없습니다. 오른쪽 드롭존에 첫 문서를 넣어보세요.</td></tr>';return}
    list.innerHTML=currentDocuments.map(d=>`<tr>
      <td><div class="doc-title">${esc(d.title)}</div><div class="doc-sub">${esc(d.fileName)} · ${fmtBytes(d.fileSize)}${d.fileMissing?' · <span class="badge failed" title="서버 배포로 저장소가 초기화된 문서입니다. 같은 파일을 다시 올리면 복원됩니다.">원본 없음</span>':''}</div></td>
      <td><span class="badge ${esc(d.aiStatus)}">${esc(statusLabel(d.aiStatus))}</span><div class="doc-sub">${esc(d.categoryLabel)}<br>${esc(d.documentTypeLabel)}</div></td>
      <td><b style="font-size:11px">${esc(d.project||d.site||'Global')}</b><div class="doc-sub">${esc(d.virtualPath||'AI 분류 대기')}</div></td>
      <td><div style="font-size:11px">${esc(d.documentDate||'-')}</div><div class="doc-sub">${d.documentNumber?'No. '+esc(d.documentNumber):''} ${d.revision?'· Rev '+esc(d.revision):''}</div></td>
      <td>${d.openActions?`<span class="action-count">미처리 ${d.openActions}건</span>`:'<span class="badge ready">조치 없음</span>'}${d.responseDueOn?`<div class="doc-sub">회신 ${esc(d.responseDueOn)}</div>`:''}</td>
      <td><button class="btn small" onclick="openDocument(${d.id})">열기</button></td></tr>`).join('');
}
async function openDocument(id){
    document.getElementById('drawer-bg').classList.add('open');document.getElementById('drawer-body').innerHTML='<div class="empty">AI 문서 인덱스를 불러오는 중…</div>';
    try{const {document:d}=await jsonFetch(endpoints.show+'/'+id);renderDetail(d);renderMemory(d.actions||[])}catch(e){document.getElementById('drawer-body').innerHTML='<div class="empty">'+esc(e.message)+'</div>'}
}
function renderMemory(actions){
    const box=document.getElementById('memory-list');const open=actions.filter(a=>!['completed','ignored'].includes(a.status));
    if(!open.length){box.innerHTML='<div class="empty" style="padding:28px 10px">현재 미완료 후속조치가 없습니다.</div>';return}
    box.innerHTML=open.slice(0,8).map(a=>`<div class="memory-item ${esc(a.severity)}"><strong>${esc(a.title)}</strong><p>${esc(a.recommendedAction||a.details||'')}</p><div class="memory-meta"><span>${esc(a.severity)}</span><span>${a.dueAt?'기한 '+esc(a.dueAt.slice(0,10)):'기한 확인 필요'}</span></div></div>`).join('');
}
function renderDetail(d){
    currentDoc={id:d.id,fileName:d.fileName,extension:(d.extension||'').toLowerCase(),previewUrl:d.previewUrl,downloadUrl:d.downloadUrl,mimeType:d.mimeType};
    document.getElementById('detail-title').textContent=d.title;document.getElementById('detail-file').textContent=d.fileName+' · '+fmtBytes(d.fileSize);
    const facts=(d.keyFacts||[]).map(f=>`<div class="fact">${esc(f)}</div>`).join('')||'<p>추출된 핵심 사실이 없습니다.</p>';
    const tags=[...(d.keywords||[]),...(d.tags||[])].slice(0,50).map(t=>`<span class="tag">${esc(t)}</span>`).join('');
    const actions=(d.actions||[]).map(a=>`<div class="action-card ${esc(a.severity)}"><strong>${esc(a.title)}</strong><p>${esc(a.details||'')}</p>${a.recommendedAction?`<p><b>권고:</b> ${esc(a.recommendedAction)}</p>`:''}${a.sourceExcerpt?`<div class="doc-sub">근거: “${esc(a.sourceExcerpt)}”</div>`:''}<div class="action-foot"><span>${a.dueAt?'기한 '+esc(a.dueAt.slice(0,10)):'명시 기한 없음'} · 신뢰도 ${esc(a.confidence||0)}%</span>${canManage&&!['completed','ignored'].includes(a.status)?`<button class="btn small" onclick="completeAction(${a.id},${d.id})">처리완료</button>`:`<span class="badge ${a.status==='completed'?'ready':''}">${esc(a.status)}</span>`}</div></div>`).join('')||'<p>AI가 발견한 필수 후속조치가 없습니다.</p>';
    document.getElementById('drawer-body').innerHTML=`
      <div class="detail-grid"><div class="detail-chip"><span>분류</span><b>${esc(d.categoryLabel)}</b></div><div class="detail-chip"><span>문서유형</span><b>${esc(d.documentTypeLabel)}</b></div><div class="detail-chip"><span>문서번호 / Revision</span><b>${esc(d.documentNumber||'-')} / ${esc(d.revision||'-')}</b></div><div class="detail-chip"><span>AI 신뢰도</span><b>${esc(d.aiConfidence||0)}%</b></div></div>
      <div class="section"><h3>원본 문서</h3><p>${esc(d.virtualPath||'분류 대기')}</p>${d.fileMissing?`<p style="color:#b91c1c;background:#fff4f4;border:1px solid #fecaca;border-radius:8px;padding:9px 11px;margin:0 0 9px">원본 파일이 서버에 없습니다(서버 배포로 저장소가 초기화된 문서). <b>같은 파일을 오른쪽 드롭존에 다시 올리면</b> 이 문서에 그대로 복원되고 분석도 다시 돕니다.</p>`:''}<div style="display:flex;gap:8px;flex-wrap:wrap">${d.fileMissing?'':`<button class="btn primary" onclick="openViewer()">바로 보기</button><a class="btn" href="${esc(d.downloadUrl)}">다운로드</a>`}${canManage?`<button class="btn" onclick="reanalyze(${d.id})">AI 재분석</button><button class="btn" style="border-color:#c7d2fe;color:#3730a3" onclick="runExtract(${d.id},'takeoff',this)" title="이 도면에서 수량을 뽑아 물량대장에 넣습니다">📐 물량 뽑기</button><button class="btn" style="border-color:#c7d2fe;color:#3730a3" onclick="runExtract(${d.id},'submittals',this)" title="이 시방서에서 제출물 요구를 뽑아 제출물대장에 넣습니다">📋 제출물 뽑기</button><button class="btn" onclick="openEdit(${d.id})">✎ 정보 수정</button><button class="btn" style="border-color:#fecaca;color:#b91c1c" onclick="removeDocument(${d.id})">🗑 삭제</button>`:''}</div></div>
      ${canManage?`<div class="section" id="edit-form" style="display:none"><h3>문서 정보 수정</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px">
          <div class="field" style="grid-column:1/-1"><label>제목</label><input id="ed-title" value="${esc(d.title||'')}"></div>
          <div class="field"><label>분류</label><select id="ed-category">${CATEGORY_OPTIONS.map(o=>`<option value="${esc(o.value)}"${o.value===d.category?' selected':''}>${esc(o.label)}</option>`).join('')}</select></div>
          <div class="field"><label>문서유형</label><select id="ed-type">${TYPE_OPTIONS.map(o=>`<option value="${esc(o.value)}"${o.value===d.documentType?' selected':''}>${esc(o.label)}</option>`).join('')}</select></div>
          <div class="field"><label>공종/부문</label><input id="ed-discipline" value="${esc(d.discipline||'')}"></div>
          <div class="field"><label>문서번호</label><input id="ed-number" value="${esc(d.documentNumber||'')}"></div>
          <div class="field"><label>Revision</label><input id="ed-revision" value="${esc(d.revision||'')}"></div>
          <div class="field"><label>문서일</label><input type="date" id="ed-date" value="${esc(d.documentDate||'')}"></div>
          <div class="field"><label>회신기한</label><input type="date" id="ed-due" value="${esc(d.responseDueOn||'')}"></div>
          <div class="field"><label>만료일</label><input type="date" id="ed-expires" value="${esc(d.expiresOn||'')}"></div>
        </div>
        <div style="display:flex;gap:8px;margin-top:10px"><button class="btn primary" onclick="saveEdit(${d.id})">저장</button><button class="btn" onclick="document.getElementById('edit-form').style.display='none'">취소</button></div>
        <p style="font-size:11px;color:var(--muted);margin:9px 0 0">저장하면 이 문서는 "정리 완료(사람 검수)"로 표시됩니다 — AI 추정이 아니라 사람이 확정한 값이라는 뜻입니다.</p>
      </div>`:''}
      ${d.aiError?`<div class="section" style="border-color:#fecaca;background:#fff4f4"><h3>분석 오류</h3><p>${esc(d.aiError)}</p></div>`:''}
      <div class="section"><h3>AI 요약</h3><p>${esc(d.summary||'AI 분석 대기 중입니다.')}</p></div>
      <div class="section"><h3>반드시 기억할 사실</h3>${facts}</div>
      <div class="section"><h3>위험·기한·후속조치</h3>${actions}</div>
      <div class="section"><h3>검색 키워드</h3>${tags||'<p>키워드 생성 대기 중입니다.</p>'}</div>
      <details class="section"><summary style="font-weight:800;cursor:pointer">OCR/추출 본문 보기</summary><p style="max-height:420px;overflow:auto">${esc(d.extractedText||'추출 가능한 본문이 없습니다. 이미지/PDF 원본을 확인하세요.')}</p></details>`;
}
async function completeAction(actionId,documentId){try{await jsonFetch(endpoints.actions+'/'+actionId,{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({status:'completed'})});toast('후속조치를 완료했습니다.');openDocument(documentId);loadDocuments()}catch(e){toast(e.message,true)}}
async function reanalyze(id){try{await jsonFetch(endpoints.show+'/'+id+'/reanalyze',{method:'POST'});toast('AI 재분석을 시작했습니다.');document.getElementById('drawer-bg').classList.remove('open');loadDocuments()}catch(e){toast(e.message,true)}}

/* 도면 → 물량, 시방 → 제출물.
   결과는 승인 대기줄이 아니라 대장으로 바로 간다 — 확신이 서는 줄은 그냥 들어가고
   애매한 줄만 표시된다. 그래서 끝나고 나서 "몇 줄 넣었고 몇 줄을 봐야 하는지" 를 알린다.
   판독은 수십 초가 걸리므로 버튼을 잠그고 진행 중임을 보여 준다(두 번 눌러 두 배로
   들어가는 사고를 막는다). */
async function runExtract(id, kind, btn){
  const isSpec = kind === 'submittals';
  const label = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = isSpec ? '시방 읽는 중…' : '도면 읽는 중…'; }
  try{
    // 접수만 하고 번호표를 받는다. 판독을 요청 안에서 기다리면 게이트웨이가 먼저
    // 끊어 504 가 뜬다 — 화면에는 무엇이 잘못됐는지도 남지 않는다.
    const job = await jsonFetch(endpoints.show+'/'+id+'/'+(isSpec?'submittals':'takeoff'),{method:'POST'});
    if (!job.jobId) { toast(job.message || '완료했습니다.'); return; }
    if (job.done) {                                  // 조금 전에 끝난 작업이면 결과가 바로 온다
      if (job.status === 'failed') throw new Error(job.error || 'AI 작업이 실패했습니다.');
      const prev = job.result || {};
      toast(prev.message || '이미 뽑아 둔 결과입니다.');
      return;
    }

    toast(isSpec ? '시방을 읽기 시작했습니다. 끝나면 알려 드립니다.' : '도면을 읽기 시작했습니다. 끝나면 알려 드립니다.');

    const r = await pollAiJob(job.jobId, function (sec) {
      if (btn) btn.textContent = (isSpec ? '시방 읽는 중… ' : '도면 읽는 중… ') + sec + '초';
    });

    toast(r.message || (isSpec ? '제출물을 뽑았습니다.' : '물량을 뽑았습니다.'));
    if (r.review) {
      setTimeout(function(){
        toast((isSpec?'제출물':'물량')+' 대장에서 "확인 필요"를 눌러 '+r.review+'건을 봐 주세요.');
      }, 2600);
    }
  }catch(e){ toast(e.message, true); }
  finally { if (btn) { btn.disabled = false; btn.textContent = label; } }
}

/* 번호표로 진행 상태를 묻는다 — 2초마다, 최대 10분.
   창을 닫아도 서버 쪽 작업은 계속되고, 결과는 대장에 남는다. */
async function pollAiJob(jobId, onTick){
  let waited = 0;
  for(;;){
    await new Promise(function(r){ setTimeout(r, 2000); });
    waited += 2;
    const st = await jsonFetch(endpoints.aiJob + '/' + jobId, {});
    if (onTick) onTick(waited);
    if (st.done) {
      if (st.status === 'failed') throw new Error(st.error || 'AI 작업이 실패했습니다.');
      return st.result || {};
    }
    if (waited > 600) throw new Error('시간이 너무 오래 걸립니다. 잠시 뒤 대장을 확인해 주세요.');
  }
}
function openEdit(){const f=document.getElementById('edit-form');if(f)f.style.display=f.style.display==='none'?'block':'none'}
async function saveEdit(id){
    const v=x=>{const el=document.getElementById(x);return el?el.value.trim():''};
    const title=v('ed-title');
    if(!title){toast('제목은 비울 수 없습니다.',true);return}
    try{
        await jsonFetch(endpoints.show+'/'+id+'/review',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({
            title:title,category:v('ed-category'),document_type:v('ed-type'),
            discipline:v('ed-discipline')||null,document_number:v('ed-number')||null,revision:v('ed-revision')||null,
            document_date:v('ed-date')||null,response_due_on:v('ed-due')||null,expires_on:v('ed-expires')||null
        })});
        toast('문서 정보를 저장했습니다.');openDocument(id);loadDocuments()
    }catch(e){toast(e.message,true)}
}
async function removeDocument(id){
    const d=currentDocuments.find(x=>x.id===id);
    const name=d?(d.fileName||d.title):'이 문서';
    if(!confirm(`'${name}' 을(를) 삭제할까요?\n\n원본 파일과 이 문서에서 나온 후속조치·알림도 함께 지워집니다. 되돌릴 수 없습니다.`))return;
    try{
        await jsonFetch(endpoints.show+'/'+id,{method:'DELETE'});
        toast('삭제했습니다.');document.getElementById('drawer-bg').classList.remove('open');loadDocuments()
    }catch(e){toast(e.message,true)}
}
async function unstick(){
    const btn=document.getElementById('unstick-btn');if(!btn)return;
    btn.disabled=true;const old=btn.textContent;btn.textContent='확인 중...';
    try{const d=await jsonFetch(endpoints.reanalyzeStuck,{method:'POST'});toast(d.message);loadDocuments()}
    catch(e){toast(e.message,true)}
    finally{btn.disabled=false;btn.textContent=old}
}
async function uploadFiles(files){
    if(!files.length)return;const queue=document.getElementById('upload-queue');queue.classList.add('show');document.getElementById('queue-files').innerHTML=[...files].map(f=>`<div class="queue-row"><span>${esc(f.name)}</span><span>${fmtBytes(f.size)}</span></div>`).join('');document.getElementById('upload-progress').style.width='25%';
    const form=new FormData();[...files].forEach(f=>form.append('files[]',f));['company','site','project'].forEach(k=>{const v=document.getElementById('upload-'+k).value;if(v)form.append(k+'_id',v)});
    try{const data=await jsonFetch(endpoints.upload,{method:'POST',body:form});document.getElementById('upload-progress').style.width='100%';const dup=(data.duplicates||[]),fail=(data.failed||[]);let msg=data.message;if(dup.length)msg+=' · '+dup.map(d=>`${d.file}: ${d.reason||'이미 등록된 문서'}`).join(' / ');if(fail.length)msg+=' · 실패 '+fail.map(f=>`${f.file}: ${f.reason}`).join(' / ');toast(msg,fail.length>0);setTimeout(()=>{queue.classList.remove('show');document.getElementById('upload-progress').style.width='0';loadDocuments()},800)}catch(e){toast(e.message,true);document.getElementById('upload-progress').style.width='0'}
}
if(canManage){const dz=document.getElementById('dropzone'),input=document.getElementById('file-input');document.getElementById('pick-files').onclick=()=>input.click();input.onchange=()=>uploadFiles(input.files);['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.add('drag')}));['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.remove('drag')}));dz.addEventListener('drop',e=>uploadFiles(e.dataTransfer.files))}
document.getElementById('drawer-close').onclick=()=>document.getElementById('drawer-bg').classList.remove('open');document.getElementById('drawer-bg').addEventListener('click',e=>{if(e.target.id==='drawer-bg')e.currentTarget.classList.remove('open')});
document.getElementById('search-btn').onclick=loadDocuments;document.getElementById('refresh-btn').onclick=loadDocuments;
if(canManage){const ub=document.getElementById('unstick-btn');if(ub)ub.onclick=unstick;}else{const ub=document.getElementById('unstick-btn');if(ub)ub.style.display='none';}document.getElementById('search').addEventListener('keydown',e=>{if(e.key==='Enter')loadDocuments()});document.getElementById('category-filter').onchange=loadDocuments;document.getElementById('project-filter').onchange=loadDocuments;document.getElementById('site-filter').onchange=loadDocuments;
if(canManage){
    document.getElementById('tidy-btn').onclick=openTidy;
    document.getElementById('tidy-close').onclick=()=>document.getElementById('tidy-bg').classList.remove('open');
    document.getElementById('tidy-bg').addEventListener('click',e=>{if(e.target.id==='tidy-bg')e.currentTarget.classList.remove('open')});
    document.getElementById('tidy-apply').onclick=applyTidy;
    document.getElementById('tidy-all').onclick=()=>tidySelect('all');
    document.getElementById('tidy-suggested').onclick=()=>tidySelect('suggested');
    document.getElementById('tidy-none').onclick=()=>tidySelect('none');
}
/* ===== 원본 뷰어 — 올린 형식 그대로 화면에서 보기 =====
   변환은 전부 서버(OfficePreview)가 한다: 엑셀→표(색·병합 유지), 워드→문서, PPT→슬라이드,
   PDF/이미지/텍스트는 그대로. 화면은 preview URL 을 iframe 으로 띄우기만 한다.
   처음에는 CDN 라이브러리(SheetJS·mammoth)를 브라우저에서 내려받아 그렸지만 걷어냈다 —
   현장 인터넷에서 CDN 이 막히면 매번 다운로드로 후퇴했고, 변환기가 서버·브라우저 두 벌이
   되면 같은 파일이 화면마다 다르게 보인다. 변환 규칙은 한 곳에만 둔다. */
const VIEWER_INLINE=['pdf','jpg','jpeg','png','webp','tif','tiff','txt','csv','xlsx','xls','docx','pptx'];
function closeViewer(){const bg=document.getElementById('viewer-bg');bg.classList.remove('open');document.getElementById('viewer-body').innerHTML=''}
function openViewer(){
    if(!currentDoc)return;
    const {fileName,extension:ext,previewUrl,downloadUrl}=currentDoc;
    const bg=document.getElementById('viewer-bg'),body=document.getElementById('viewer-body');
    bg.classList.add('open');document.getElementById('viewer-title').textContent=fileName||'문서';document.getElementById('viewer-dl').href=downloadUrl;
    if(!VIEWER_INLINE.includes(ext)){body.innerHTML=viewerFallback(ext,downloadUrl);return}
    // sandbox: 업로드된 내용은 남이 만든 것 — 스크립트로 살아나면 안 된다(서버 CSP 와 이중 잠금).
    // iframe 에 sandbox 를 걸지 않는다 — 크롬 내장 PDF 뷰어는 샌드박스 프레임에서
    // 실행을 거부해 "This page has been blocked by Chrome" 만 떴다. 업로드 내용이
    // 스크립트로 살아날 수 있는 건 오피스→HTML 변환뿐이고, 그 방어는 서버 응답의
    // CSP(OfficePreview::safeHeaders — default-src 'none' + sandbox)가 이미 한다.
    // 보호는 위험이 있는 곳(서버 변환 응답)에 두지, 모든 미리보기를 깨는 곳에 두지 않는다.
    body.innerHTML=`<iframe src="${esc(previewUrl)}" title="${esc(fileName||'문서')}"></iframe>`;
}
function viewerFallback(ext,downloadUrl){return `<div class="viewer-msg">이 형식(.${esc(ext||'?')})은 화면 미리보기를 지원하지 않습니다.<br>원본을 내려받아 확인해 주세요.<br><br><a class="btn primary" href="${esc(downloadUrl)}">원본 다운로드</a></div>`}
document.getElementById('viewer-close').onclick=closeViewer;
document.getElementById('viewer-bg').addEventListener('click',e=>{if(e.target.id==='viewer-bg')closeViewer()});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&document.getElementById('viewer-bg').classList.contains('open'))closeViewer()});

loadDocuments();const requested=new URLSearchParams(location.search).get('document');if(requested)setTimeout(()=>openDocument(Number(requested)),400);
</script>
</body>
</html>

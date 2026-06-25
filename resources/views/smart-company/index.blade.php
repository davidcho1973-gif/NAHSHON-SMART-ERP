<!DOCTYPE html>
<html lang="ko">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>SMART COMPANY ERP | NAHSHON MEP</title>
  <meta name="description" content="NAHSHON MEP í˜„ìž¥ í†µí•© ê´€ë¦¬ ì‹œìŠ¤í…œ">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap"
    rel="stylesheet">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <script src="{{ asset('js/smart-language.js') }}?v={{ filemtime(public_path('js/smart-language.js')) }}" defer></script>
  <link rel="stylesheet" href="{{ asset('css/smart-company.css') }}">
  <style>

    .context-switcher {
      background: var(--surface);
      border: 1px solid var(--border-color);
      color: var(--text-primary);
      padding: 6px 12px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      outline: none;
      transition: all 0.2s ease;
      font-family: inherit;
    }
    .context-switcher:hover {
      border-color: var(--primary-color);
    }


    .language-switcher-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
      background: var(--bg-base);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 4px 8px;
      flex-shrink: 0;
    }
    .language-switcher-label {
      color: var(--text-tertiary);
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .language-switcher {
      background: transparent;
      border: none;
      color: var(--text-primary);
      font-size: 12px;
      font-weight: 700;
      outline: none;
      cursor: pointer;
    }
    .language-switcher option {
      background: #1e2030;
      color: #e2e8f0;
    }
  </style>
</head>

<body>
  @php
    $authUser = $authUser ?? [
      'name' => 'ERP User',
      'email' => '',
      'role' => 'ERP User',
      'initials' => 'ER',
    ];
  @endphp
  <div class="erp-layout">
    <aside class="sidebar">
      <div class="sidebar-brand">
        <div class="brand-logo">NS</div>
        <div class="brand-name">
          <span class="title">SMART COMPANY</span>
          <span class="subtitle">v2.0 // ENTERPRISE</span>
        </div>
      </div>
      <div class="sidebar-scroll">
        <nav class="sidebar-nav">
          <div class="nav-section">
            <div class="nav-section-title">CORE</div>
            <ul class="nav-list">
              <li class="nav-item active" data-view="dashboard" id="nav-dashboard">
                <i class="ph ph-squares-four"></i><span>ëŒ€ì‹œë³´ë“œ (Overview)</span>
              </li>
              <li class="nav-item" data-view="command" id="nav-command">
                <i class="ph ph-command" style="color:#38bdf8"></i><span>AI í˜„ìž¥ ì§€íœ˜ì‹¤</span>
                <span class="nav-badge" style="background:rgba(56,189,248,.14);color:#38bdf8">NEW</span>
              </li>
              <li class="nav-item" data-view="analytics" id="nav-analytics">
                <i class="ph ph-chart-line-up"></i><span>í†µí•© ë¶„ì„ (Analytics)</span>
              </li>
              <li class="nav-item" data-view="alerts" id="nav-alerts">
                <i class="ph ph-bell-ringing" style="color:#f97316"></i><span>ðŸ”” í†µí•© ì•Œë¦¼ ì„¼í„°</span>
                <span class="nav-badge alert" id="alert-unread-badge" style="background:#ef4444">0</span>
              </li>
            </ul>
          </div>
          <div class="nav-section">
            <div class="nav-section-title">MODULES</div>
            <ul class="nav-list">
              <li class="nav-item" data-view="safety" id="nav-safety">
                <i class="ph ph-shield-check"></i><span>AI ìž‘ì—…ì•ˆì „ê´€ë¦¬</span>
                <span class="nav-badge alert" id="alert-badge">5</span>
              </li>
              <li class="nav-item" data-view="hr" id="nav-hr">
                <i class="ph ph-users"></i><span>ì¸ì›ê´€ë¦¬</span>
              </li>
              <li class="nav-item" data-view="payroll" id="nav-payroll">
                <i class="ph ph-coins"></i><span>ê¸‰ì—¬/ì •ì‚° (Payroll)</span>
              </li>
              <li class="nav-item" data-view="wbs" id="nav-wbs">
                <i class="ph ph-tree-structure" style="color:#7c3aed"></i><span>ê³µì • ê´€ë¦¬ (WBS)</span>
                <span class="nav-badge alert" id="wbs-ai-badge" style="background:#7c3aed;display:none">AI</span>
              </li>
              <li class="nav-item" data-view="finance" id="nav-finance">
                <i class="ph ph-currency-dollar"></i><span>ìž¬ë¬´ (Finance)</span>
              </li>
              <li class="nav-item" data-view="inventory" id="nav-inventory">
                <i class="ph ph-package"></i><span>ìžìž¬/ìž¥ë¹„ (Inventory)</span>
              </li>
            </ul>
          </div>
          <div class="nav-section">
            <div class="nav-section-title">NASON í†µí•©ê´€ë¦¬</div>
            <ul class="nav-list">
              <li class="nav-item" data-view="vehicle" id="nav-vehicle">
                <i class="ph ph-car"></i><span>ì°¨ëŸ‰ ê´€ë¦¬</span>
              </li>
              <li class="nav-item" data-view="rental" id="nav-rental">
                <i class="ph ph-bulldozer"></i><span>ìž¥ë¹„ ë Œíƒˆ ê´€ë¦¬</span>
              </li>
              <li class="nav-item" data-view="housing" id="nav-housing">
                <i class="ph ph-house-line"></i><span>ìˆ™ì†Œ ê´€ë¦¬</span>
              </li>
                            <li class="nav-item" data-view="vendors" id="nav-vendors">
                                <i class="ph ph-storefront"></i><span>êµ¬ë§¤/ë ŒíŠ¸ ê´€ë¦¬</span>
                            </li>
                            
              <li class="nav-item" data-view="flights" id="nav-flights">
                <i class="ph ph-airplane"></i><span>í•­ê³µê¶Œ ê´€ë¦¬</span>
              </li>
              <li class="nav-item" data-view="office" id="nav-office">
                <i class="ph ph-archive"></i><span>í˜„ìž¥ì‚¬ë¬´ì‹¤ ë¹„í’ˆ</span>
              </li>
              <li class="nav-item" style="border-top: 1px solid var(--border-color); margin-top: 5px; padding-top: 5px;"
                onclick="openUniversalScanner()">
                <i class="ph ph-magic-wand" style="color:var(--brand-primary)"></i><span
                  style="color:var(--brand-primary); font-weight:600;">AI ìŠ¤ìº” í†µí•© ë“±ë¡</span>
              </li>
            </ul>
          </div>
        </nav>
      </div>
      <div class="sidebar-footer">
        <div class="user-block">
          <div class="user-avatar">{{ $authUser['initials'] }}</div>
          <div class="user-details">
            <div class="user-name">{{ $authUser['name'] }}</div>
            <div class="user-role">{{ $authUser['role'] }}</div>
          </div>
          <i class="ph ph-caret-right" style="color:var(--text-tertiary)"></i>
        </div>
      </div>
    </aside>

    <nav class="mobile-tabbar" aria-label="Mobile primary navigation">
      <button class="mobile-tabbar-item" type="button" data-mobile-view="attendance" aria-label="출석관리">
        <i class="ph ph-clock"></i>
        <span>출석관리</span>
      </button>
      <button class="mobile-tabbar-item" type="button" data-mobile-view="messages" aria-label="메세지">
        <i class="ph ph-chat-circle-text"></i>
        <span>메세지</span>
      </button>
      <button class="mobile-tabbar-item mobile-tabbar-more" id="mobile-more-button" type="button" aria-label="More" aria-expanded="false">
        <span class="mobile-more-icon">
          <span class="mobile-more-mark" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></span>
        </span>
        <span>More</span>
      </button>
      <button class="mobile-tabbar-item" type="button" data-mobile-view="schedule" aria-label="일정관리">
        <i class="ph ph-calendar"></i>
        <span>일정관리</span>
      </button>
      <button class="mobile-tabbar-item" type="button" data-mobile-view="receipts" aria-label="영수증처리">
        <i class="ph ph-receipt"></i>
        <span>영수증처리</span>
      </button>
    </nav>

    <div class="mobile-more-backdrop" id="mobile-more-backdrop" hidden>
      <section class="mobile-more-sheet" role="dialog" aria-modal="true" aria-labelledby="mobile-more-title">
        <div class="mobile-more-handle" aria-hidden="true"></div>
        <div class="mobile-more-header">
          <h2 id="mobile-more-title">More</h2>
          <button class="mobile-more-close" type="button" data-mobile-more-close aria-label="Close More">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="mobile-more-grid">
          <button class="mobile-more-tile" type="button" data-mobile-view="dashboard"><i class="ph ph-squares-four"></i><span>대시보드</span></button>
          <button class="mobile-more-tile" type="button" data-mobile-view="command"><i class="ph ph-command"></i><span>AI 지휘실</span></button>
          <button class="mobile-more-tile" type="button" data-mobile-view="safety"><i class="ph ph-shield-check"></i><span>AI 작업안전</span></button>
          <button class="mobile-more-tile" type="button" data-mobile-view="vehicle"><i class="ph ph-car"></i><span>차량관리</span></button>
          <button class="mobile-more-tile" type="button" data-mobile-view="personnel"><i class="ph ph-users"></i><span>인원관리</span></button>
          <button class="mobile-more-tile" type="button" data-mobile-view="analytics"><i class="ph ph-chart-line-up"></i><span>통합분석</span></button>
          <button class="mobile-more-tile" type="button" data-mobile-view="payroll"><i class="ph ph-coins"></i><span>급여정산</span></button>
          <button class="mobile-more-tile" type="button" data-mobile-view="inventory"><i class="ph ph-package"></i><span>자재장비</span></button>
          <button class="mobile-more-tile" type="button" data-mobile-view="rental"><i class="ph ph-bulldozer"></i><span>장비렌탈</span></button>
          <a class="mobile-more-tile" href="/mobile-equipment/index"><i class="ph ph-wrench"></i><span>장비스캔(AI)</span></a>
          <button class="mobile-more-tile" type="button" data-mobile-view="housing"><i class="ph ph-house-line"></i><span>숙소관리</span></button>
          <button class="mobile-more-tile" type="button" data-mobile-view="vendors"><i class="ph ph-storefront"></i><span>구매/렌트</span></button>
          <button class="mobile-more-tile" type="button" data-mobile-view="flights"><i class="ph ph-airplane"></i><span>항공권</span></button>
          <button class="mobile-more-tile" type="button" data-mobile-view="office"><i class="ph ph-archive"></i><span>사무실비품</span></button>
          <button class="mobile-more-tile mobile-more-tile-accent" type="button" data-mobile-action="scanner"><i class="ph ph-magic-wand"></i><span>AI 스캔등록</span></button>
        </div>
      </section>
    </div>

    <main class="main-content">
      <header class="topbar">
        <div class="topbar-left" style="display:flex;align-items:center;gap:0;">
          <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.15);border-radius:10px;padding:5px 10px;">
            <span style="font-size:12px;color:var(--text-tertiary);font-weight:500;white-space:nowrap;">ðŸ“ í˜„ìž¥</span>
            <select id="project-context-switcher"
              onchange="window.setProjectContext(this.value)"
              style="background:transparent;border:none;color:var(--text-primary);font-size:13px;font-weight:700;cursor:pointer;outline:none;font-family:inherit;padding:0 4px;max-width:200px;">
               <option value="ALL" selected style="background:#1e2030;">Global</option>
               @foreach (($siteOptions ?? []) as $site)
                 <option value="{{ $site['code'] }}" style="background:#1e2030;">{{ $site['label'] }}</option>
               @endforeach
            </select>
          </div>
          <div class="breadcrumbs" style="margin-left: 14px; border-left: 1px solid var(--border-color); padding-left: 14px;">
            <span>NAHSHON MEP</span>
            <i class="ph ph-caret-right"></i>
            <span class="active-crumb" id="breadcrumb-current">Overview</span>
          </div>
        </div>
        <div class="topbar-right">
          <div class="language-switcher-wrap">
            <span class="language-switcher-label">Language</span>
            <select id="language-switcher" class="language-switcher" aria-label="Language selector" data-no-i18n>
              <option value="ko">한국어</option>
              <option value="en">English</option>
              <option value="es">Español</option>
            </select>
          </div>
          <div class="search-container">
            <i class="ph ph-magnifying-glass"></i>
            <input type="text" placeholder="ì¸ì›, ìž¥ë¹„, ê±°ëž˜ID ê²€ìƒ‰..." class="global-search" id="global-search-input">
            <span class="shortcut">âŒ˜K</span>
          </div>
          <div class="topbar-actions">
            <button class="btn-primary" id="btn-global-commute" title="내 출퇴근 등록" onclick="window.openMyCommuteModal()" style="display:inline-flex; align-items:center; gap:6px; padding:6px 12px; font-size:12px; font-weight:600; border-radius:8px; border:none; background:var(--brand-primary); color:white; cursor:pointer; margin-right:8px;">
              <i class="ph ph-clock" style="font-size:14px;"></i>
              <span>내 출퇴근</span>
            </button>
            <button class="icon-btn" id="btn-notifications" title="ì•Œë¦¼">
              <i class="ph ph-bell"></i>
              <span class="status-dot"></span>
            </button>
            <button class="icon-btn" id="btn-settings" title="ì„¤ì •">
              <i class="ph ph-gear"></i>
            </button>
          </div>
          <div class="account-menu-wrap">
            <button class="account-button" id="account-menu-button" type="button" aria-expanded="false">
              <span class="account-avatar">{{ $authUser['initials'] }}</span>
              <span>{{ $authUser['name'] }}</span>
              <i class="ph ph-caret-down"></i>
            </button>
            <div class="account-dropdown" id="account-dropdown" hidden>
              <div class="account-dropdown-section">
                <div class="account-dropdown-label">Current Company</div>
                <div class="account-company">
                  <span class="account-company-icon"><i class="ph ph-buildings"></i></span>
                  <div>
                    <div class="account-company-name">NAHSHON MEP</div>
                    <div class="account-company-sub">Your Company</div>
                  </div>
                </div>
              </div>
              <div class="account-menu-group">
                <div class="account-menu-heading">My Profile</div>
                <button class="account-menu-item" type="button" data-account-view="profile">
                  <i class="ph ph-user-circle"></i><span>View Profile</span>
                </button>
                <button class="account-menu-item" type="button" data-account-view="profile-update">
                  <i class="ph ph-identification-card"></i><span>Update Profile</span>
                </button>
                <button class="account-menu-item" type="button" data-account-view="ui-settings">
                  <i class="ph ph-sliders-horizontal"></i><span>UI Settings</span>
                </button>
                <button class="account-menu-item" type="button" data-account-view="password">
                  <i class="ph ph-lock-key"></i><span>Change Password</span>
                </button>
              </div>
              <div class="account-menu-group">
                <button class="account-menu-item" type="button" data-account-logout>
                  <i class="ph ph-sign-out"></i><span>Logout ({{ $authUser['name'] }})</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </header>
      <div class="page-container" id="page-container"></div>
    </main>
  </div>

  <script>

    // ============================================================
    // GLOBAL PROJECT CONTEXT
    // ============================================================
    window.currentSiteId = 'ALL';

    window.SITE_NAMES = @json($siteNames ?? ['ALL' => 'Global']);

    function _siteId() {
      return (window.currentSiteId && window.currentSiteId !== '') ? window.currentSiteId : 'ALL';
    }

    window.setProjectContext = function(siteId) {
      window.currentSiteId = siteId;
      console.log("Switched Global Context to:", siteId);

      // 1. apiCache flush so stale data does not appear
      if (typeof apiCache !== 'undefined') {
        Object.keys(apiCache).forEach(function(k) { delete apiCache[k]; });
      }

      // 2. Loading toast
      var toast = document.createElement('div');
      toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#2563eb;color:white;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,0.4);transition:opacity 0.3s;';
      toast.textContent = (window.SITE_NAMES[siteId] || siteId) + ' ë°ì´í„° ë¡œë“œ ì¤‘...';
      document.body.appendChild(toast);
      setTimeout(function() { toast.style.opacity = '0'; setTimeout(function() { toast.remove(); }, 300); }, 2500);

      // 3. Re-render current view using loadView
      if (typeof loadView === 'function') {
        loadView(window._currentView || 'hr');
      }
    };

        // ============================================================
    // MOCK API DATA LAYER
    // ============================================================
    const MockAPI = {
      getHRData: async () => ({
        stats: await MockAPI.getPersonnelStats(),
        list: await MockAPI.getPersonnelList(),
        attendance: await MockAPI.getAttendanceLive()
      }),
      getLgesProcessData: async () => ({
        projectName: "LGES Battery Plant AZ - NFF 46-Series ì„¤ë¹„ ì„¤ì¹˜",
        manager: "PM Team",
        progress: 45,
        stages: [
          {
            id: "stage1",
            title: "STAGE 1. Winder (ê¶Œì·¨ ê³µì •)",
            status: "in-progress",
            progress: 80,
            tasks: [
              { name: "ìˆ˜ë ¹ ë° ëª©ìž¬ íŒ¨í‚¹ í•´ì²´ (Lay-down Area)", status: "done", detail: "ì—ì–´ìºìŠ¤í„° ë° ì§€ê²Œì°¨ íˆ¬ìž… ì¤€ë¹„" },
              { name: "Air Caster ë¨¸ì‹  í¬ì§€ì…˜ ì´ë™ (Move-in)", status: "done", detail: "Turn Table, Winder ë‹¨í’ˆ ì´ë™" },
              { name: "ê¸°ê³„ì  ë„í‚¹ (Docking)", status: "done", detail: "ë‹¨í’ˆê°„ 1ì°¨ ì¡°ë¦½ ê²°í•©" },
              { name: "Rough / Final ë ˆë²¨ë§", status: "in-progress", detail: "Rough Â±5mm / Final Â±0.5mm ê¸°ì¤€ ì •ë ¬" },
              { name: "Shaft Pin & Coupling ê²°í•©", status: "pending", detail: "Winder ë³¸ì²´ ë° Eject Conveyor ë™ë ¥ì¶• ì¼ì¹˜í™”" }
            ]
          },
          {
            id: "stage2",
            title: "STAGE 2. Assembly (ì¡°ë¦½ ê³µì •)",
            status: "pending",
            progress: 15,
            tasks: [
              { name: "Zone 1 ì…‹ì—… (CAN LOADER ê¸°ì¤€)", status: "done", detail: "Datum ë¨¸ì‹  ì¶• ì¢Œìš° ì •ë ¬" },
              { name: "Zone 2 ì…‹ì—… (IOU ê¸°ì¤€)", status: "in-progress", detail: "ì¢ì€ êµ¬ì—­ ì „ë™ ìŠ¤íƒœì»¤ í™œìš© ë°°ì¹˜" },
              { name: "ìœ í‹¸ë¦¬í‹° ë°°ê´€ ì„  ì—°ê²°", status: "pending", detail: "CDA, Vacuum, ë°°ê¸°ê´€ ë„í‚¹" },
              { name: "ê¸°ë°€(Leak) í…ŒìŠ¤íŠ¸", status: "pending", detail: "ì§„ê³µ ë° ì••ì¶•ê³µê¸° ë¼ì¸ ëˆ„ì„¤ ê²€ì‚¬" }
            ]
          },
          {
            id: "stage3",
            title: "STAGE 3. ì „ê¸°/í™˜ê²½ ì—°ë™ (Interconnection)",
            status: "pending",
            progress: 0,
            tasks: [
              { name: "Scrap & Fume Collector ë•íŠ¸ ì—°ê²°", status: "pending", detail: "í”Œëžœì§€ ê°œìŠ¤í‚· ì‚½ìž… ë° ë³¼íŒ…" },
              { name: "ìž¥ë¹„ ì ‘ì§€ ë° LOTO ì ê²€", status: "pending", detail: "ì „ì› ì¸ê°€ ì „ í•„ìˆ˜ EHS ì²´í¬" },
              { name: "í†µì‹  ë° ë™ë ¥ì„  (EtherCAT) ì²´ê²°", status: "pending", detail: "ì„¼ì„œ ë°Ž ì¸í„°ë½ ì „ê¸° ë°°ì„ " },
              { name: "ìµœì¢… ì´ê´€ (Punch-list í•´ì†Œ)", status: "pending", detail: "LGES ë° ë²¤ë” í•©ë™ ì‹œìš´ì „" }
            ]
          }
        ]
      }),
      getKPIs: async () => [
        { label: 'í˜„ìž¥ ì¸ì›', value: '142', unit: 'ëª…', trend: 'ì „ì¼ ëŒ€ë¹„ +3', trendType: 'up', icon: 'ph-users' },
        { label: 'ì¤‘ìž¥ë¹„ ìš´ìš©', value: '11/14', unit: 'ëŒ€', trend: 'ìš´í–‰ë¶ˆê°€ 3ëŒ€', trendType: 'down', icon: 'ph-truck' },
        { label: 'MTD ì§€ì¶œ', value: '$452.4K', unit: 'USD', trend: 'ì˜ˆì‚° ëŒ€ë¹„ -1.2%', trendType: 'up', icon: 'ph-currency-dollar' },
        { label: 'ë¯¸ì²˜ë¦¬ ì•Œë¦¼', value: '5', unit: 'ê±´', trend: 'ê¸´ê¸‰ 2ê±´ í¬í•¨', trendType: 'down', icon: 'ph-warning-circle' },
        { label: 'í™œì„± í˜„ìž¥', value: '4', unit: 'ê³³', trend: 'ì˜ˆì •ëŒ€ë¡œ ì§„í–‰ì¤‘', trendType: 'neutral', icon: 'ph-buildings' },
      ],
      getActionItems: async () => [
        { id: 'ACT-0192', type: 'ì•ˆì „', summary: 'FPP ì—…ë°ì´íŠ¸ â€” LGES AZ ì§€ë¶• ê³µì‚¬ êµ¬ê°„', assignee: 'David H.', status: 'critical', date: '2026-04-06' },
        { id: 'ACT-0193', type: 'HR', summary: 'OSHA 30 ê°±ì‹  í•„ìš” â€” ìµœë™í˜ (4ì¼ í›„ ë§Œë£Œ)', assignee: 'SYSTEM', status: 'critical', date: '2026-04-06' },
        { id: 'ACT-0194', type: 'ìž¬ë¬´', summary: 'ì¸ë³´ì´ìŠ¤ #IV-8821 ìŠ¹ì¸ ìš”ì²­ $12,400', assignee: 'Accounting', status: 'warning', date: '2026-04-05' },
        { id: 'ACT-0195', type: 'HR', summary: 'íƒ€ìž„ì‹œíŠ¸ ì´ìƒ ê°ì§€: 3ëª… 12ì‹œê°„ ì´ˆê³¼ ê·¼ë¬´', assignee: 'PM Team', status: 'warning', date: '2026-04-05' },
        { id: 'ACT-0196', type: 'ìžìž¬', summary: 'ìž¬ê³ ë¶€ì¡±: ì „ì„ ê´€ 3/4" â€” ìµœì†Œ ìˆ˜ëŸ‰ ë¯¸ë‹¬', assignee: 'Warehouse', status: 'pending', date: '2026-04-04' },
      ],
      getProjectStatus: async () => [
        { code: 'PRJ-LGS-01', name: 'LGES Battery Plant AZ', manager: 'S. Connor', progress: 68, color: '#2563eb', endDate: '2026-11-30' },
        { code: 'PRJ-HFF-02', name: 'Hoffman Logistics Hub', manager: 'D. Wright', progress: 42, color: '#f59e0b', endDate: '2026-08-15' },
        { code: 'PRJ-SST-03', name: 'Samsung Taylor Fab', manager: 'M. Lee', progress: 12, color: '#10b981', endDate: '2027-03-01' },
        { code: 'PRJ-HWH-04', name: 'Hanwha Solar Site', manager: 'A. Chen', progress: 89, color: '#8b5cf6', endDate: '2026-05-20' },
      ],
      getEquipmentList: async () => [
        { id: 'EQ-001', name: 'êµ´ì°©ê¸° CAT 320', type: 'êµ´ì°©ê¸°', site: 'LGES-AZ', status: 'ìš´í–‰ê°€ëŠ¥', inspector: 'ê¹€ì² ìˆ˜', lastCheck: '2026-04-09', checkStatus: 'ì™„ë£Œ' },
        { id: 'EQ-002', name: 'í¬ë ˆì¸ Liebherr', type: 'í¬ë ˆì¸', site: 'LGES-AZ', status: 'ìš´í–‰ë¶ˆê°€', inspector: 'ì´ë¯¼ì¤€', lastCheck: '2026-04-09', checkStatus: 'ì™„ë£Œ' },
        { id: 'EQ-003', name: 'ì§€ê²Œì°¨ 5T', type: 'ì§€ê²Œì°¨', site: 'HFF-02', status: 'ìš´í–‰ê°€ëŠ¥', inspector: 'ë°•ì§€í˜¸', lastCheck: '2026-04-09', checkStatus: 'ì™„ë£Œ' },
        { id: 'EQ-004', name: 'ì»´í”„ë ˆì„œ Doosan', type: 'ì»´í”„ë ˆì„œ', site: 'HFF-02', status: 'ìš´í–‰ê°€ëŠ¥', inspector: 'ìµœë™í˜', lastCheck: '2026-04-08', checkStatus: 'ì™„ë£Œ' },
        { id: 'EQ-005', name: 'ë¤í”„íŠ¸ëŸ­ 10T', type: 'íŠ¸ëŸ­', site: 'SST-03', status: 'ìš´í–‰ë¶ˆê°€', inspector: 'ê°•ìŠ¹ìš°', lastCheck: '2026-04-09', checkStatus: 'ì™„ë£Œ' },
        { id: 'EQ-006', name: 'êµ´ì°©ê¸° Komatsu', type: 'êµ´ì°©ê¸°', site: 'SST-03', status: 'ìš´í–‰ê°€ëŠ¥', inspector: 'ìœ¤ìž¬ì›', lastCheck: '2026-04-09', checkStatus: 'ì™„ë£Œ' },
      ],
      getEquipmentStats: async () => ({ total: 14, operable: 11, inoperable: 3, todayInspections: 8 }),
      getToolList: async () => [
        { id: 'TL-001', name: 'ì „ë™ë“œë¦´ Makita', category: 'ì „ë™ê³µêµ¬', status: 'ë¶ˆì¶œì¤‘', holder: 'ì´ë¯¼ì¤€', checkoutDate: '2026-04-08', condition: 'ì •ìƒ' },
        { id: 'TL-002', name: 'ê°ë„ê¸°-200mm', category: 'ì¸¡ì •', status: 'ë³´ê´€ì¤‘', holder: '', checkoutDate: '', condition: 'ì •ìƒ' },
        { id: 'TL-003', name: 'í† í¬ë Œì¹˜ 300Nm', category: 'ìˆ˜ê³µêµ¬', status: 'ë¶ˆì¶œì¤‘', holder: 'ë°•ì§€í˜¸', checkoutDate: '2026-04-07', condition: 'ì •ìƒ' },
        { id: 'TL-004', name: 'ì—´í™”ìƒ ì¹´ë©”ë¼ FLIR', category: 'ê²€ì‚¬ìž¥ë¹„', status: 'ë¶ˆì¶œì¤‘', holder: 'ìµœë™í˜', checkoutDate: '2026-04-09', condition: 'ìˆ˜ë¦¬í•„ìš”' },
        { id: 'TL-006', name: 'íŒŒì´í”„ë Œì¹˜ 18"', category: 'ìˆ˜ê³µêµ¬', status: 'ë³´ê´€ì¤‘', holder: '', checkoutDate: '', condition: 'ì†ìƒ' },
      ],
      getToolStats: async () => ({ total: 87, checkedOut: 31, inStorage: 52, damaged: 4 }),
      getToolTransactions: async () => [
        { time: '09:42', action: 'ë¶ˆì¶œ', toolId: 'TL-007', toolName: 'ë ˆì´ì € ìˆ˜í‰ê¸°', userId: 'ê°•ìŠ¹ìš°', condition: 'ì •ìƒ' },
        { time: '09:15', action: 'ë°˜ë‚©', toolId: 'TL-004', toolName: 'ì—´í™”ìƒ ì¹´ë©”ë¼', userId: 'ì •ëŒ€ê±´', condition: 'ìˆ˜ë¦¬í•„ìš”' },
        { time: '08:51', action: 'ë¶ˆì¶œ', toolId: 'TL-001', toolName: 'ì „ë™ë“œë¦´ Makita', userId: 'ì´ë¯¼ì¤€', condition: 'ì •ìƒ' },
      ],
      getPersonnelList: async () => [
        { id: 'P-2604-0001', nameKr: 'ê¹€ì² ìˆ˜', nameEn: 'Chulsoo Kim', company: 'NAHSHON', role: 'ë°°ê´€ê³µ', visa: 'H-2B', visaExpiry: '2026-10-15', site: 'LGES-AZ', safety: 'ì™„ë£Œ' },
        { id: 'P-2604-0002', nameKr: 'ì´ë¯¼ì¤€', nameEn: 'Minjun Lee', company: 'NAHSHON', role: 'ì „ê¸°ê³µ', visa: 'H-2B', visaExpiry: '2026-09-30', site: 'LGES-AZ', safety: 'ì™„ë£Œ' },
        { id: 'P-2604-0003', nameKr: 'ë°•ì§€í˜¸', nameEn: 'Jiho Park', company: 'SUBO', role: 'ìš©ì ‘ê³µ', visa: 'H-2B', visaExpiry: '2026-08-20', site: 'HFF-02', safety: 'ì™„ë£Œ' },
        { id: 'P-2604-0004', nameKr: 'ìµœë™í˜', nameEn: 'Donghyuk Choi', company: 'SUBO', role: 'ë°°ê´€ê³µ', visa: 'H-2B', visaExpiry: '2026-07-11', site: 'HFF-02', safety: 'ë§Œë£Œìž„ë°•' },
        { id: 'P-2604-0005', nameKr: 'ê°•ìŠ¹ìš°', nameEn: 'Seungwoo Kang', company: 'ETC', role: 'ì¤‘ìž¥ë¹„ê¸°ì‚¬', visa: 'H-2B', visaExpiry: '2026-12-01', site: 'SST-03', safety: 'ì™„ë£Œ' },
        { id: 'P-2604-0007', nameKr: 'ìž„ì„±í›ˆ', nameEn: 'Sunghoon Lim', company: 'NAHSHON', role: 'ì „ê¸°ê³µ', visa: 'H-2B', visaExpiry: '2026-10-22', site: 'HWH-04', safety: 'ë¯¸ì´ìˆ˜' },
      ],
      getPersonnelStats: async () => ({
        total: 142, active: 138, onLeave: 4, visaExpiringSoon: 3, safetyExpiring: 2,
        byCompany: [{ name: 'NAHSHON', count: 68 }, { name: 'SUBO', count: 44 }, { name: 'ETC', count: 30 }],
      }),
      getFinanceStats: async () => ({
        mtdTotal: 452400, mtdBudget: 460000, pendingApproval: 3, pendingAmount: 17140, claimable: 38200,
        byCategory: [
          { name: 'ìžìž¬', amount: 142000, color: '#2563eb' },
          { name: 'ì¸ê±´ë¹„', amount: 168000, color: '#10b981' },
          { name: 'ìž¥ë¹„ìž„ëŒ€', amount: 88400, color: '#f59e0b' },
          { name: 'ì‹ë¹„/ìˆ™ì†Œ', amount: 38200, color: '#8b5cf6' },
          { name: 'ê¸°íƒ€', amount: 15800, color: '#64748b' },
        ],
      }),
      getExpenses: async () => [
        { id: 'TX-2604-0021', date: '2026-04-09', category: 'ì‹ë¹„', site: 'LGES-AZ', detail: 'í˜„ìž¥ ì¤‘ì‹ (142ëª…)', amount: 2840, method: 'ë²•ì¸ì¹´ë“œ', claimable: true, status: 'ë¯¸ì²­êµ¬', receiptUrl: '#' },
        { id: 'TX-2604-0020', date: '2026-04-09', category: 'ìžìž¬', site: 'HFF-02', detail: 'ë°°ê´€ í”¼íŒ… ì„¸íŠ¸ #B-48', amount: 4210, method: 'êµ¬ë§¤ì£¼ë¬¸', claimable: true, status: 'ì²­êµ¬ì™„ë£Œ', receiptUrl: '#' },
        { id: 'TX-2604-0019', date: '2026-04-08', category: 'ìž¥ë¹„ìž„ëŒ€', site: 'SST-03', detail: 'í¬ë ˆì¸ 1ì¼ ìž„ëŒ€', amount: 12400, method: 'ì¸ë³´ì´ìŠ¤', claimable: true, status: 'ìŠ¹ì¸ëŒ€ê¸°', receiptUrl: '#' },
        { id: 'TX-2604-0018', date: '2026-04-08', category: 'ìˆ™ì†Œ', site: 'HWH-04', detail: '4ì›” ì›”ì„¸ â€” Unit 14', amount: 3600, method: 'ìˆ˜í‘œ', claimable: false, status: 'ì²­êµ¬ì™„ë£Œ', receiptUrl: '' },
        { id: 'TX-2604-0016', date: '2026-04-07', category: 'ì•ˆì „', site: 'LGES-AZ', detail: 'PPE ì†Œëª¨í’ˆ ë³´ì¶©', amount: 890, method: 'ë²•ì¸ì¹´ë“œ', claimable: true, status: 'ë¯¸ì²­êµ¬', receiptUrl: '#' },
      ],
      getAlerts: async () => [
        { id: 'AL-2604-0008', time: '09:42', type: 'ì¤‘ìž¥ë¹„', target: 'EQ-002', summary: 'Liebherr í¬ë ˆì¸ ìš´í–‰ë¶ˆê°€ íŒì •', level: 'ê¸´ê¸‰', status: 'ë¯¸ì²˜ë¦¬', reporter: 'ì´ë¯¼ì¤€' },
        { id: 'AL-2604-0007', time: '09:15', type: 'ê³µêµ¬ì†ìƒ', target: 'TL-004', summary: 'ì—´í™”ìƒ ì¹´ë©”ë¼ ìˆ˜ë¦¬í•„ìš”', level: 'ì£¼ì˜', status: 'ë¯¸ì²˜ë¦¬', reporter: 'ì •ëŒ€ê±´' },
        { id: 'AL-2604-0006', time: '08:33', type: 'ì¤‘ìž¥ë¹„', target: 'EQ-005', summary: 'ë¤í”„íŠ¸ëŸ­ 10T ì—”ì§„ì˜¤ì¼ ëˆ„ì¶œ', level: 'ê¸´ê¸‰', status: 'ì²˜ë¦¬ì¤‘', reporter: 'ê°•ìŠ¹ìš°' },
        { id: 'AL-2604-0005', time: '2026-04-08', type: 'ìˆ™ì†Œ', target: 'HSG-07', summary: 'í™”ìž¥ì‹¤ ë°°ê´€ ëˆ„ìˆ˜ â€” Unit 7', level: 'ì£¼ì˜', status: 'ì²˜ë¦¬ì™„ë£Œ', reporter: 'ë°•ì§€í˜¸' },
        { id: 'AL-2604-0004', time: '2026-04-08', type: 'ì¸ì¦', target: 'P-2604-0004', summary: 'ë¹„ìž ë§Œë£Œ 60ì¼ ì´ë‚´ â€” ìµœë™í˜', level: 'ì£¼ì˜', status: 'ë¯¸ì²˜ë¦¬', reporter: 'SYSTEM' },
        { id: 'AL-2604-0003', time: '2026-04-07', type: 'ì•ˆì „', target: 'P-2604-0007', summary: 'ì•ˆì „êµìœ¡ ë¯¸ì´ìˆ˜ â€” ìž„ì„±í›ˆ', level: 'ì£¼ì˜', status: 'ë¯¸ì²˜ë¦¬', reporter: 'SYSTEM' },
      ],
      getSafetyStats: async () => ({
        daysNoIncident: 32, lastIncidentDate: '2026-03-12',
        urgent: 1, warning: 3, normal: 2, unresolved: 4, inProgress: 1, resolved: 2,
        todayPtwActive: 2, todayPtwPending: 1,
        inspectionCompletionRate: 78, openDefects: 5,
        trainingExpiringSoon: 2
      }),
      getPtwList: async () => [
        { id: 'PTW-2604-001', type: 'ê³ ì†Œìž‘ì—…', typeColor: '#f97316', title: 'Aêµ¬ì—­ ì§€ë¶• íŒ¨ë„ ì„¤ì¹˜', zone: 'Aêµ¬ì—­', date: '2026-04-13', timeStart: '07:00', timeEnd: '17:00', applicant: 'ê¹€ì² ìˆ˜', company: 'NAHSHON', workers: 4, risks: 'ì¶”ë½, ë‚™í•˜ë¬¼', measures: 'ì•ˆì „ë‚œê°„ ì„¤ì¹˜, ì•ˆì „ë§ ì„¤ì¹˜, ì•ˆì „ë²¨íŠ¸ ì°©ìš©', tbmDone: true, status: 'ì§„í–‰ì¤‘' },
        { id: 'PTW-2604-002', type: 'í™”ê¸°ìž‘ì—…', typeColor: '#ef4444', title: 'Bêµ¬ì—­ ë°°ê´€ ìš©ì ‘', zone: 'Bêµ¬ì—­', date: '2026-04-13', timeStart: '09:00', timeEnd: '15:00', applicant: 'ì´ë¯¼ì¤€', company: 'SUBO', workers: 2, risks: 'í™”ìž¬, í™”ìƒ, ìœ í•´ê°€ìŠ¤', measures: 'ì†Œí™”ê¸° ë¹„ì¹˜, í™”ê¸°ê°ì‹œìž ë°°ì¹˜, ë°©ì—´ë³µ ì°©ìš©', tbmDone: false, status: 'ìŠ¹ì¸ëŒ€ê¸°' },
        { id: 'PTW-2604-003', type: 'ë°€íê³µê°„', typeColor: '#8b5cf6', title: 'Cêµ¬ì—­ ì§€í•˜ íƒ±í¬ ì²­ì†Œ', zone: 'Cêµ¬ì—­', date: '2026-04-14', timeStart: '08:00', timeEnd: '12:00', applicant: 'ë°•ì§€í˜¸', company: 'ETC', workers: 3, risks: 'ì‚°ì†Œê²°í•, ìœ í•´ê°€ìŠ¤ ì¤‘ë…', measures: 'í™˜ê¸°ìž¥ì¹˜ ê°€ë™, ì‚°ì†Œë†ë„ ì¸¡ì •, êµ¬ì¡°ì› ëŒ€ê¸°', tbmDone: false, status: 'ìŠ¹ì¸ëŒ€ê¸°' },
        { id: 'PTW-2604-004', type: 'ì¤‘ëŸ‰ë¬¼', typeColor: '#eab308', title: 'Dêµ¬ì—­ ì¹ ëŸ¬ ì–‘ì¤‘', zone: 'Dêµ¬ì—­', date: '2026-04-12', timeStart: '06:00', timeEnd: '10:00', applicant: 'ìµœë™í˜', company: 'NAHSHON', workers: 6, risks: 'ë‚™í•˜, ì „ë„, ì¶©ëŒ', measures: 'í¬ë ˆì¸ ìž‘ë™ë°˜ê²½ í†µì œ, ì‹ í˜¸ìˆ˜ ë°°ì¹˜', tbmDone: true, status: 'ì™„ë£Œ' },
        { id: 'PTW-2604-005', type: 'êµ´ì°©ìž‘ì—…', typeColor: '#3b82f6', title: 'Eêµ¬ì—­ ì§€ì¤‘ë°°ê´€ íŠ¸ë Œì¹˜', zone: 'Eêµ¬ì—­', date: '2026-04-11', timeStart: '07:00', timeEnd: '16:00', applicant: 'ê°•ìŠ¹ìš°', company: 'ETC', workers: 5, risks: 'ë§¤ëª°, ì§€ì¤‘ë§¤ì„¤ë¬¼ ì†ìƒ', measures: 'ì§€í•˜ë§¤ì„¤ë¬¼ í™•ì¸, ê²½ì‚¬ë©´ ë³´ê°•, ì¶œìž…í†µì œ', tbmDone: true, status: 'ì™„ë£Œ' }
      ],
      getPtwStats: async () => ({ todayActive: 2, pending: 1, completed: 2, rejected: 0 }),
      getInspections: async () => [
        { id: 'INS-001', date: '2026-04-13', inspector: 'ê¹€ì•ˆì „', zone: 'ì „ì²´í˜„ìž¥', category: 'ì¶”ë½ë°©ì§€',
          items: [
            { name: 'ì•ˆì „ë‚œê°„ ì„¤ì¹˜ ìƒíƒœ', result: 'pass' },
            { name: 'ê°œêµ¬ë¶€ ë®ê°œ ì„¤ì¹˜', result: 'fail', note: 'Bêµ¬ì—­ 2ê°œì†Œ ë®ê°œ íŒŒì†' },
            { name: 'ì•ˆì „ë§ ì„¤ì¹˜ ì™„ë£Œ', result: 'pass' },
            { name: 'ì‚¬ë‹¤ë¦¬ ê³ ì • ìƒíƒœ', result: 'pass' }
          ]
        },
        { id: 'INS-002', date: '2026-04-13', inspector: 'ì´ê°ë…', zone: 'Aêµ¬ì—­', category: 'ì¤‘ìž¥ë¹„',
          items: [
            { name: 'ì¼ì¼ì ê²€í‘œ ìž‘ì„± ì—¬ë¶€', result: 'pass' },
            { name: 'ê²½ì  ë° í›„ë°©ê²½ë³´ê¸°', result: 'pass' },
            { name: 'ì§€ê²Œì°¨ í¬í¬ ìƒíƒœ', result: 'fail', note: 'í•€ ë§ˆëª¨ â€” ì •ë¹„ í•„ìš”' },
            { name: 'ì•ˆì „ë²¨íŠ¸ ìž¥ì°© í™•ì¸', result: 'pass' }
          ]
        },
        { id: 'INS-003', date: '2026-04-13', inspector: 'ë°•ì†Œìž¥', zone: 'ì‚¬ë¬´ì‹¤/ì°½ê³ ', category: 'ì „ê¸°/í™”ìž¬',
          items: [
            { name: 'ìž„ì‹œë°°ì „ë°˜ ì»¤ë²„ ì²´ê²°', result: 'pass' },
            { name: 'ì ‘ì§€ì„  ì—°ê²° ìƒíƒœ', result: 'pass' },
            { name: 'ì†Œí™”ê¸° ìœ„ì¹˜ ë° ìƒíƒœ', result: 'fail', note: 'Cêµ¬ì—­ ì†Œí™”ê¸° ì••ë ¥ë¶€ì¡± â€” êµì²´ ìš”ì²­' },
            { name: 'ê°€ì—°ì„± ë¬¼ì§ˆ ì´ê²© ê´€ë¦¬', result: 'pass' }
          ]
        }
      ],
      getInspectionStats: async () => ({ totalItems: 12, passed: 9, failed: 3, completionRate: 78 }),
      getTrainingRecords: async () => [
        { id: 'P-2604-0001', name: 'ê¹€ì² ìˆ˜', role: 'ë°°ê´€ê³µ', company: 'NAHSHON',
          trainings: [
            { name: 'OSHA 30-Hour', completedDate: '2024-10-15', expiryDate: '2026-10-15', status: 'ìœ íš¨' },
            { name: 'ê³ ì†Œìž‘ì—… ì•ˆì „êµìœ¡', completedDate: '2025-04-01', expiryDate: '2026-04-01', status: 'ë§Œë£Œ' },
            { name: 'ì•ˆì „ë³´ê±´êµìœ¡ (ê¸°ë³¸)', completedDate: '2025-01-10', expiryDate: '2027-01-10', status: 'ìœ íš¨' }
          ]
        },
        { id: 'P-2604-0002', name: 'ì´ë¯¼ì¤€', role: 'ì „ê¸°ê³µ', company: 'NAHSHON',
          trainings: [
            { name: 'OSHA 10-Hour', completedDate: '2025-03-20', expiryDate: '2027-03-20', status: 'ìœ íš¨' },
            { name: 'í™”ê¸°ìž‘ì—… ì•ˆì „êµìœ¡', completedDate: '2025-02-14', expiryDate: '2026-05-14', status: 'ë§Œë£Œìž„ë°•' },
            { name: 'ì „ê¸°ì•ˆì „ íŠ¹ë³„êµìœ¡', completedDate: '2025-06-01', expiryDate: '2027-06-01', status: 'ìœ íš¨' }
          ]
        },
        { id: 'P-2604-0003', name: 'ë°•ì§€í˜¸', role: 'ìš©ì ‘ê³µ', company: 'SUBO',
          trainings: [
            { name: 'OSHA 10-Hour', completedDate: '2025-01-05', expiryDate: '2027-01-05', status: 'ìœ íš¨' },
            { name: 'ë°€íê³µê°„ ì•ˆì „êµìœ¡', completedDate: '2025-08-20', expiryDate: '2026-08-20', status: 'ìœ íš¨' }
          ]
        },
        { id: 'P-2604-0004', name: 'ìµœë™í˜', role: 'ë°°ê´€ê³µ', company: 'SUBO',
          trainings: [
            { name: 'OSHA 30-Hour', completedDate: '2023-09-01', expiryDate: '2025-09-01', status: 'ë§Œë£Œ' },
            { name: 'ì¤‘ëŸ‰ë¬¼ ì·¨ê¸‰ êµìœ¡', completedDate: '2025-04-10', expiryDate: '2026-04-10', status: 'ë§Œë£Œìž„ë°•' }
          ]
        },
        { id: 'P-2604-0005', name: 'ê°•ìŠ¹ìš°', role: 'ì¤‘ìž¥ë¹„ê¸°ì‚¬', company: 'ETC',
          trainings: [
            { name: 'êµ´ì°©ê¸° ìš´ì „êµìœ¡', completedDate: '2024-07-15', expiryDate: '2027-07-15', status: 'ìœ íš¨' },
            { name: 'OSHA 10-Hour', completedDate: '2025-05-20', expiryDate: '2027-05-20', status: 'ìœ íš¨' }
          ]
        }
      ],
      getSafetyDocs: async () => [
        { id: 'DOC-001', category: 'ë§¤ë‰´ì–¼', title: 'ê³ ì†Œìž‘ì—…ëŒ€ ì•ˆì „ìˆ˜ì¹™ ê°€ì´ë“œ', size: '2.4 MB', date: '2026-01-15', uploader: 'Admin', url: '#' },
        { id: 'DOC-002', category: 'ì ˆì°¨ì„œ', title: 'ë°€íê³µê°„ êµ¬ì¡° ì ˆì°¨ì„œ (v2)', size: '1.8 MB', date: '2026-02-20', uploader: 'Safety Team', url: '#' },
        { id: 'DOC-003', category: 'ì–‘ì‹', title: 'ì¼ì¼ TBM ì ê²€ì¼ì§€ (ì—‘ì…€)', size: '145 KB', date: '2026-03-05', uploader: 'Admin', url: '#' },
        { id: 'DOC-004', category: 'MSDS', title: 'ìš°ë ˆíƒ„ í¼ ì½”íŒ…ì œ í™”í•™ë¬¼ì§ˆì •ë³´', size: '3.1 MB', date: '2026-04-01', uploader: 'Safety Team', url: '#' },
        { id: 'DOC-005', category: 'ë²•ì •ì§€ì¹¨', title: 'ì¤‘ëŒ€ìž¬í•´ì²˜ë²Œë²• ëŒ€ì‘ ê°€ì´ë“œ', size: '5.6 MB', date: '2025-12-10', uploader: 'HQ', url: '#' },
        { id: 'DOC-006', category: 'ì–‘ì‹', title: 'ìž‘ì—…í—ˆê°€ì„œ (PTW) í‘œì¤€ ì–‘ì‹', size: '88 KB', date: '2026-03-01', uploader: 'Admin', url: '#' },
        { id: 'DOC-007', category: 'MSDS', title: 'ì—í­ì‹œ ë„ë£Œ í™”í•™ë¬¼ì§ˆì •ë³´', size: '2.2 MB', date: '2026-02-10', uploader: 'Safety Team', url: '#' }
      ],
      getOshaForm300: async () => [
        { caseNo: 'INC-2603-001', name: 'Kim Chulsoo', title: 'Pipefitter', dateOfInjury: '2026-03-12', zone: 'WINDER', description: 'Laceration to left hand â€” angle grinder slip', classification: 'other_recordable', daysAway: 0, restricted: 2, injuryCode: 'CUT', form301Id: 'F301-2603-001' },
        { caseNo: 'INC-2601-001', name: 'Park Jiho', title: 'Welder', dateOfInjury: '2026-01-08', zone: 'ASSEMBLY', description: 'Strain to lower back â€” heavy lift without team lift', classification: 'restricted', daysAway: 0, restricted: 5, injuryCode: 'STR', form301Id: 'F301-2601-001' }
      ],
      getOsha300AStats: async () => ({
        year: 2026,
        totalCases: 2, deathCases: 0, daysAwayCases: 0, restrictedCases: 1, otherRecordable: 1,
        totalDaysAway: 0, totalRestrictedDays: 7,
        injuryCount: 2, illnessCount: 0,
        averageEmployees: 142, totalHoursWorked: 284000,
        dartRate: (1 * 200000 / 284000).toFixed(2),
        trir: (2 * 200000 / 284000).toFixed(2),
        postingRequired: true, postingStart: '2026-02-01', postingEnd: '2026-04-30'
      }),
      getCertMatrix: async () => [
        { id: 'P-2604-0001', name: 'Kim Chulsoo', nameKr: 'ê¹€ì² ìˆ˜', role: 'Pipefitter', company: 'NAHSHON',
          certs: [
            { type: 'OSHA 30-Hour', issued: '2024-10-15', expiry: '2029-10-15', status: 'ìœ íš¨', hoffmanReq: true },
            { type: 'Fall Protection', issued: '2025-04-01', expiry: '2026-04-01', status: 'ë§Œë£Œ', hoffmanReq: true },
            { type: 'First Aid/CPR', issued: '2025-01-10', expiry: '2027-01-10', status: 'ìœ íš¨', hoffmanReq: true }
          ]
        },
        { id: 'P-2604-0002', name: 'Lee Minjun', nameKr: 'ì´ë¯¼ì¤€', role: 'Electrician', company: 'NAHSHON',
          certs: [
            { type: 'OSHA 10-Hour', issued: '2025-03-20', expiry: '2030-03-20', status: 'ìœ íš¨', hoffmanReq: true },
            { type: 'LOTO', issued: '2025-02-14', expiry: '2026-05-14', status: 'ë§Œë£Œìž„ë°•', hoffmanReq: true },
            { type: 'Electrical Safety', issued: '2025-06-01', expiry: '2027-06-01', status: 'ìœ íš¨', hoffmanReq: false }
          ]
        },
        { id: 'P-2604-0003', name: 'Park Jiho', nameKr: 'ë°•ì§€í˜¸', role: 'Welder', company: 'SUBO',
          certs: [
            { type: 'OSHA 10-Hour', issued: '2025-01-05', expiry: '2030-01-05', status: 'ìœ íš¨', hoffmanReq: true },
            { type: 'Hot Work Permit', issued: '2025-08-20', expiry: '2026-08-20', status: 'ìœ íš¨', hoffmanReq: true },
            { type: 'Confined Space', issued: '2024-06-01', expiry: '2025-06-01', status: 'ë§Œë£Œ', hoffmanReq: true }
          ]
        },
        { id: 'P-2604-0004', name: 'Choi Donghyuk', nameKr: 'ìµœë™í˜', role: 'Pipefitter', company: 'SUBO',
          certs: [
            { type: 'OSHA 30-Hour', issued: '2023-09-01', expiry: '2028-09-01', status: 'ìœ íš¨', hoffmanReq: true },
            { type: 'Rigging/Signal', issued: '2025-04-10', expiry: '2026-04-10', status: 'ë§Œë£Œìž„ë°•', hoffmanReq: true }
          ]
        },
        { id: 'P-2604-0005', name: 'Kang Seungwoo', nameKr: 'ê°•ìŠ¹ìš°', role: 'Equipment Operator', company: 'ETC',
          certs: [
            { type: 'Forklift/Telehandler', issued: '2024-07-15', expiry: '2027-07-15', status: 'ìœ íš¨', hoffmanReq: true },
            { type: 'OSHA 10-Hour', issued: '2025-05-20', expiry: '2030-05-20', status: 'ìœ íš¨', hoffmanReq: true },
            { type: 'Crane Operator (NCCCO)', issued: '2022-03-10', expiry: '2027-03-10', status: 'ìœ íš¨', hoffmanReq: false }
          ]
        }
      ],
      getViolations: async () => [
        { id: 'VIO-2604-001', company: 'SUBO', oshaRef: 'OSHA 1926.502(d)', description: 'ê³ ì†Œ ìž‘ì—… ì‹œ ì „ì‹  ì•ˆì „ë²¨íŠ¸ ë¯¸ì°©ìš© (Bêµ¬ì—­ 2ì¸µ)', discoveredAt: '2026-04-10 09:30', discoveredBy: 'ê¹€ì•ˆì „', zone: 'Bêµ¬ì—­', photo: '#', dueDate: '2026-04-13', completedDate: '', points: 10, cumulativePoints: 10, letterSent: false, letterUrl: '' },
        { id: 'VIO-2604-002', company: 'ETC', oshaRef: 'OSHA 1926.602(a)', description: 'ì§€ê²Œì°¨ ìš´ì „ ì¤‘ ì•ˆì „ë²¨íŠ¸ ë¯¸ì°©ìš©', discoveredAt: '2026-04-08 14:15', discoveredBy: 'ì´ê°ë…', zone: 'Aêµ¬ì—­', photo: '#', dueDate: '2026-04-10', completedDate: '2026-04-10', points: 5, cumulativePoints: 5, letterSent: true, letterUrl: '#' },
        { id: 'VIO-2603-001', company: 'SUBO', oshaRef: 'OSHA 1926.451(e)', description: 'ë¹„ê³„ ë°œíŒ ê°„ê²© ê¸°ì¤€ ì´ˆê³¼ (9ì¸ì¹˜ ì´ìƒ)', discoveredAt: '2026-03-25 11:00', discoveredBy: 'ë°•ì†Œìž¥', zone: 'Cêµ¬ì—­', photo: '#', dueDate: '2026-03-27', completedDate: '2026-03-27', points: 10, cumulativePoints: 15, letterSent: true, letterUrl: '#' }
      ],
      getAlerts: async (filter) => {
        var all = [
          { id:'AL-2604-0001', ts:'2026-04-13 09:32', module:'SAFETY', type:'INC',  severity:'ê¸´ê¸‰', title:'[ì‚¬ê³ ] WINDER Aêµ¬ì—­ â€” ê¹€ì² ìˆ˜ ì ˆë‹¨ ìƒí•´', content:'ì™¼ì† 2ì§€ ì ˆë‹¨ / ë³‘ì› ì´ì†¡ ì™„ë£Œ / Form 301 ìž‘ì„± í•„ìš”', relatedId:'INC-2604-001', assignee:'ë°•ì†Œìž¥', status:'ë¯¸ì²˜ë¦¬', formUrl:'' },
          { id:'AL-2604-0002', ts:'2026-04-13 08:10', module:'FLT',    type:'VISA', severity:'ê¸´ê¸‰', title:'[ë¹„ìž ë§Œë£Œ] ë°•ì§€í˜¸ H-2B â€” D-28', content:'ë§Œë£Œì¼: 2026-05-11 / ê°±ì‹  ì¦‰ì‹œ ì°©ìˆ˜ í•„ìš” / ë‹´ë‹¹: HRíŒ€', relatedId:'HR-00023', assignee:'ì¸ì‚¬íŒ€', status:'ì²˜ë¦¬ì¤‘', formUrl:'' },
          { id:'AL-2604-0003', ts:'2026-04-13 07:00', module:'SAFETY', type:'CERT', severity:'ê¸´ê¸‰', title:'[ìžê²©ì¦ ë§Œë£Œ] ê¹€ì² ìˆ˜ â€” Fall Protection ë§Œë£Œ D+12', content:'OSHA 1926.502 ìœ„ë°˜ ìœ„í—˜ / í˜„ìž¥ íˆ¬ìž… ì¦‰ì‹œ ì¤‘ë‹¨ ê¶Œê³ ', relatedId:'CERT-0019', assignee:'ê¹€ì•ˆì „', status:'ë¯¸ì²˜ë¦¬', formUrl:'' },
          { id:'AL-2604-0004', ts:'2026-04-12 16:45', module:'VEH',    type:'RTN',  severity:'ì£¼ì˜', title:'[ë Œíƒˆ ë°˜ë‚©] Toyota Tacoma AZ-2241 â€” D-3', content:'ë°˜ë‚© ì˜ˆì •: 2026-04-16 / Enterprise Rent-A-Car / ì›” $1,850', relatedId:'VEH-0041', assignee:'ì°¨ëŸ‰ë‹´ë‹¹', status:'ë¯¸ì²˜ë¦¬', formUrl:'' },
          { id:'AL-2604-0005', ts:'2026-04-12 14:20', module:'HSG',    type:'RPR',  severity:'ì£¼ì˜', title:'[ìˆ˜ë¦¬ìš”ì²­] 202í˜¸ ì—ì–´ì»¨ ëƒ‰ë§¤ ë¶€ì¡±', content:'ìš”ì²­ìž: ì´ë¯¼ì¤€ / ì¦ìƒ: ëƒ‰ë°© ë¶ˆëŸ‰, ì‹¤ì™¸ê¸° ì´ìƒìŒ / ì—…ì²´ ì—°ë½ í•„ìš”', relatedId:'HSG-RPR-0088', assignee:'ìˆ™ì†Œë‹´ë‹¹', status:'ë¯¸ì²˜ë¦¬', formUrl:'' },
          { id:'AL-2604-0006', ts:'2026-04-12 11:00', module:'PUR',    type:'APR',  severity:'ì£¼ì˜', title:'[êµ¬ë§¤ ê²°ìž¬] ì•ˆì „ìž¥ê°‘ ì™¸ 3ì¢… â€” PO#2604-019', content:'ì‹ ì²­: ìµœë™í˜ / ê¸ˆì•¡: $1,240 / ê²°ìž¬ ëŒ€ê¸° 3ì¼ ê²½ê³¼', relatedId:'PO-2604-019', assignee:'êµ¬ë§¤ë‹´ë‹¹', status:'ë¯¸ì²˜ë¦¬', formUrl:'' },
          { id:'AL-2604-0007', ts:'2026-04-12 09:15', module:'SAFETY', type:'VIO',  severity:'ì£¼ì˜', title:'[ìœ„ë°˜] SUBO â€” ê³ ì†Œìž‘ì—… ì•ˆì „ë²¨íŠ¸ ë¯¸ì°©ìš© Bêµ¬ì—­', content:'OSHA 1926.502(d) / ë°œê²¬ìž: ê¹€ì•ˆì „ / ë²Œì  10ì  (ëˆ„ì  10ì )', relatedId:'VIO-2604-001', assignee:'ë°•ì†Œìž¥', status:'ë¯¸ì²˜ë¦¬', formUrl:'' },
          { id:'AL-2604-0008', ts:'2026-04-11 17:30', module:'SAFETY', type:'CERT', severity:'ì£¼ì˜', title:'[ìžê²©ì¦ ë§Œë£Œìž„ë°•] ì´ë¯¼ì¤€ â€” LOTO D-30', content:'ë§Œë£Œì¼: 2026-05-14 / ê°±ì‹  êµìœ¡ ì¼ì • ìˆ˜ë¦½ í•„ìš”', relatedId:'CERT-0022', assignee:'ê¹€ì•ˆì „', status:'ì²˜ë¦¬ì¤‘', formUrl:'' },
          { id:'AL-2604-0009', ts:'2026-04-11 15:00', module:'HR',     type:'VISA', severity:'ì£¼ì˜', title:'[ì·¨ì—…ë¹„ìž] ê°•ìŠ¹ìš° H-2B ê°±ì‹  D-45', content:'ë§Œë£Œ: 2026-05-26 / ë³€í˜¸ì‚¬ ì—°ë½ í•„ìš”', relatedId:'HR-00031', assignee:'ì¸ì‚¬íŒ€', status:'ë¯¸ì²˜ë¦¬', formUrl:'' },
          { id:'AL-2604-0010', ts:'2026-04-11 10:30', module:'VEH',    type:'INS',  severity:'ì£¼ì˜', title:'[ë³´í—˜ ë§Œë£Œ] Ford F-150 TX-9901 â€” D-22', content:'ë§Œë£Œ: 2026-05-03 / Progressive ê°±ì‹  í•„ìš” / ë‹´ë‹¹: ì°¨ëŸ‰íŒ€', relatedId:'VEH-0028', assignee:'ì°¨ëŸ‰ë‹´ë‹¹', status:'ë¯¸ì²˜ë¦¬', formUrl:'' },
          { id:'AL-2604-0011', ts:'2026-04-10 09:00', module:'HSG',    type:'EXP',  severity:'ì¼ë°˜', title:'[ìž„ëŒ€ ê³„ì•½] Sunridge Apt 301í˜¸ ë§Œë£Œ D-45', content:'ë§Œë£Œ: 2026-05-25 / ì›” $2,100 / ê°±ì‹  í˜‘ì˜ í•„ìš”', relatedId:'HSG-0014', assignee:'ìˆ™ì†Œë‹´ë‹¹', status:'ì¼ë°˜', formUrl:'' },
          { id:'AL-2604-0012', ts:'2026-04-10 08:30', module:'PUR',    type:'DLV',  severity:'ì¼ë°˜', title:'[ë‚©í’ˆ ë„ì°©] ì•ˆì „ í•˜ë„¤ìŠ¤ 20ê°œ â€” PO#2603-044', content:'ë„ì°©: 2026-04-10 / ì°½ê³  ìž…ê³  í™•ì¸ ì™„ë£Œ', relatedId:'PO-2603-044', assignee:'êµ¬ë§¤ë‹´ë‹¹', status:'ì™„ë£Œ', formUrl:'' },
          { id:'AL-2603-0031', ts:'2026-03-28 14:00', module:'FLT',    type:'BOOK', severity:'ì¼ë°˜', title:'[í•­ê³µê¶Œ ë°œê¶Œ] ê¹€ì² ìˆ˜ PHXâ†’ICN â€” 2026-06-15', content:'íŽ¸ëª…: KE018 / PNR: KEXUZ1 / ì¶œë°œ 12ì£¼ ì „ í™•ì¸', relatedId:'FLT-0055', assignee:'', status:'ì™„ë£Œ', formUrl:'' }
        ];
        if (!filter || filter === 'all') return all;
        return all.filter(function(a){ return a.module === filter || a.severity === filter || a.status === filter; });
      },
      updateAlertStatus: async (id, status) => ({ success: true, id, status }),
      getTbmRecords: async () => [
        { id: 'TBM-2604-013', date: '2026-04-13', zone: 'WINDER', facilitator: 'ê¹€ì² ìˆ˜', topic: 'ê³ ì†Œìž‘ì—… ì•ˆì „ìˆ˜ì¹™ ë° Fall Protection ì°©ìš© ì˜ë¬´í™”', attendees: ['ê¹€ì² ìˆ˜','ì´ë¯¼ì¤€','ë°•ì§€í˜¸','ìµœë™í˜','ê°•ìŠ¹ìš°'], attendeeCount: 5 },
        { id: 'TBM-2604-012', date: '2026-04-12', zone: 'ASSEMBLY', facilitator: 'ì´ë¯¼ì¤€', topic: 'í™”ê¸°ìž‘ì—… êµ¬ì—­ Hot Work Permit í”„ë¡œì„¸ìŠ¤ ìž¬ê³µìœ ', attendees: ['ì´ë¯¼ì¤€','ë°•ì§€í˜¸','ê°•ìŠ¹ìš°'], attendeeCount: 3 },
        { id: 'TBM-2604-011', date: '2026-04-11', zone: 'ALL', facilitator: 'ë°•ì†Œìž¥', topic: 'ì£¼ê°„ ì•ˆì „ì ê²€ ê²°ê³¼ ê³µìœ  â€” ì§€ì ì‚¬í•­ 3ê±´ ìž¬ë°œë°©ì§€', attendees: ['ê¹€ì² ìˆ˜','ì´ë¯¼ì¤€','ë°•ì§€í˜¸','ìµœë™í˜','ê°•ìŠ¹ìš°', 'ìž„ì„±í›ˆ'], attendeeCount: 6 }
      ],
      getAttendanceLive: async () => ({
        date: new Date().toISOString().substring(0, 10),
        presentCount: 38, totalActive: 42, absentCount: 4,
        checkedIn: [
          { name: 'Kim Chulsoo', company: 'NAHSHON', team: 'Plumbing A', checkIn: '06:32', checkOut: '' },
          { name: 'Lee Minjun', company: 'NAHSHON', team: 'Electrical', checkIn: '06:35', checkOut: '' },
          { name: 'Park Jiho', company: 'NAHSHON', team: 'Plumbing B', checkIn: '06:40', checkOut: '' },
          { name: 'Choi Donghyuk', company: 'NAHSHON', team: 'HVAC', checkIn: '06:41', checkOut: '' },
          { name: 'Kang Seungwoo', company: 'KOREA', team: 'Welding', checkIn: '06:45', checkOut: '' },
        ],
        notCheckedIn: [
          { name: 'Choi Dongsoo', company: 'NAHSHON', nfcUid: '04AA1B2C3D' },
          { name: 'Kim Youngsik', company: 'KOREA', nfcUid: '04BB2C3D4E' },
          { name: 'Park Hyunwoo', company: 'NAHSHON', nfcUid: '04CC3D4E5F' },
          { name: 'Lee Jihoon', company: 'NAHSHON', nfcUid: '04DD4E5F6G' },
        ],
        noCheckout: [{ name: 'Kim Chulsoo' }, { name: 'Lee Minjun' }],
        teamSummary: [
          { team: 'Plumbing A', count: 9 }, { team: 'Plumbing B', count: 8 },
          { team: 'Electrical', count: 10 }, { team: 'HVAC', count: 7 }, { team: 'Welding', count: 4 },
        ],
      }),
      getVehicleList: async () => [
        { id: 'VH-0001', type: 'í”½ì—…íŠ¸ëŸ­', plate: 'AZÂ·HNF-221', model: 'Ford F-150 2023', company: 'Enterprise', rentEnd: '2026-07-14', insuranceExp: '2026-09-30', assignee: 'Kim Chulsoo', mileage: 14200, nextOil: 15000, status: 'ì‚¬ìš©ì¤‘' },
        { id: 'VH-0002', type: 'SUV', plate: 'AZÂ·KLP-884', model: 'Chevy Tahoe 2022', company: 'Hertz', rentEnd: '2026-08-31', insuranceExp: '2026-08-01', assignee: 'Lee Minjun', mileage: 9800, nextOil: 10000, status: 'ì‚¬ìš©ì¤‘' },
        { id: 'VH-0003', type: 'ë°´', plate: 'AZÂ·RQT-556', model: 'Ford Transit 2023', company: 'Enterprise', rentEnd: '2026-05-20', insuranceExp: '2026-11-01', assignee: '', mileage: 22100, nextOil: 25000, status: 'ë°˜ë‚©ì˜ˆì •' },
        { id: 'VH-0005', type: 'ìŠ¹ìš©', plate: 'AZÂ·EBP-779', model: 'Honda CR-V 2022', company: 'Hertz', rentEnd: '2026-06-30', insuranceExp: '2026-07-01', assignee: 'Choi Admin', mileage: 18900, nextOil: 20000, status: 'ì •ë¹„ì¤‘' },
      ],
      getVehicleStats: async () => ({ total: 5, active: 3, maintenance: 1, returning: 1, rentExpiringSoon: 2, insuranceExpiring: 1, oilChangeDue: 2 }),
      getRentalList: async () => [
        { id:'RENT-2605-001', siteId:'HFF-02', equipType:'Excavator', model:'CAT 320GC', vendor:'United Rentals', startDate:'2026-04-15', endDate:'2026-05-15', returnedDate:'', dailyRate:850, deliveryFee:450, operator:'Kim Chulsoo', task:'ê¸°ì´ˆê³µì‚¬ êµ´ì°©', status:'ì‚¬ìš©ì¤‘', daysRemaining:12, totalCost:16150, notes:'' },
        { id:'RENT-2605-002', siteId:'HFF-02', equipType:'Boom Lift', model:'JLG 1932R', vendor:'Sunbelt', startDate:'2026-04-20', endDate:'2026-05-05', returnedDate:'', dailyRate:185, deliveryFee:200, operator:'Lee Minjun', task:'ì²œìž¥ ë°°ê´€ì„¤ì¹˜', status:'ì‚¬ìš©ì¤‘', daysRemaining:2, totalCost:2585, notes:'ë°˜ë‚© ìž„ë°•' },
        { id:'RENT-2605-003', siteId:'HFF-02', equipType:'Forklift', model:'Toyota 8FGU25', vendor:'Herc Rentals', startDate:'2026-04-01', endDate:'2026-04-30', returnedDate:'', dailyRate:120, deliveryFee:180, operator:'Park Jiho', task:'ìžìž¬ í•˜ì—­', status:'ì—°ì²´', daysRemaining:-3, totalCost:4140, notes:'ì—°ìž¥ í˜‘ì˜ í•„ìš”' },
        { id:'RENT-2605-004', siteId:'HFF-02', equipType:'Generator', model:'CAT XQ60', vendor:'United Rentals', startDate:'2026-03-20', endDate:'2026-04-25', returnedDate:'2026-04-24', dailyRate:95, deliveryFee:150, operator:'', task:'ìž„ì‹œ ì „ì›ê³µê¸‰', status:'ë°˜ë‚©ì™„ë£Œ', daysRemaining:0, totalCost:3475, notes:'' },
        { id:'RENT-2605-005', siteId:'HFF-02', equipType:'Skid Steer', model:'Bobcat S70', vendor:'Local AZ', startDate:'2026-04-25', endDate:'2026-05-25', returnedDate:'', dailyRate:280, deliveryFee:300, operator:'Choi Donghyuk', task:'ì™¸ë¶€ í† ëª©ìž‘ì—…', status:'ì‚¬ìš©ì¤‘', daysRemaining:22, totalCost:2540, notes:'' }
      ],
      getRentalStats: async () => ({ total:5, active:3, overdue:1, returned:1, returningSoon:1, mtdCost:8540 }),
      getHousingList: async () => [
        { id: 'HS-0001', building: 'Sunrise Apts', address: '4821 W Camelback Rd, Phoenix AZ', unit: 'A-204', maxOcc: 4, currentOcc: 4, rent: 2400, elecDue: 15, elecAmt: 210, waterAmt: 65, gasAmt: 42, internet: 80, residents: ['Kim C.', 'Lee M.', 'Park J.', 'Choi D.'] },
        { id: 'HS-0002', building: 'Sunrise Apts', address: '4821 W Camelback Rd, Phoenix AZ', unit: 'B-107', maxOcc: 4, currentOcc: 3, rent: 2400, elecDue: 15, elecAmt: 188, waterAmt: 58, gasAmt: 39, internet: 80, residents: ['Kang S.', 'Yun J.', 'Lim S.'] },
        { id: 'HS-0003', building: 'Mesa Palms', address: '1200 S Dobson Rd, Mesa AZ', unit: 'C-312', maxOcc: 2, currentOcc: 2, rent: 1800, elecDue: 10, elecAmt: 155, waterAmt: 44, gasAmt: 0, internet: 65, residents: ['Jeong D.', 'Oh S.'] },
      ],
      getHousingStats: async () => ({ totalUnits: 4, totalCapacity: 14, currentOcc: 10, occupancyRate: 71, monthlyRentTotal: 8800, monthlyUtilTotal: 1078, utilPayingDueSoon: 3, pendingIssues: 2 }),
      getFlightList: async () => [
        { id: 'FL-0011', name: 'Han Gildong', direction: 'ìž…êµ­', from: 'ICN', to: 'PHX', depDateTime: '2026-04-13 10:30', airline: 'Korean Air', pnr: 'KXNV7T', price: 1240, status: 'ë°œê¶Œ', needPickup: true, pickupBy: 'Lee Minjun', housingReady: true },
        { id: 'FL-0012', name: 'Jo Subin', direction: 'ìž…êµ­', from: 'GMP', to: 'LAX', depDateTime: '2026-04-15 08:00', airline: 'Asiana', pnr: 'APZM3R', price: 1180, status: 'ë°œê¶Œ', needPickup: false, pickupBy: '', housingReady: true },
        { id: 'FL-0013', name: 'Kim Chulsoo', direction: 'ê·€êµ­', from: 'PHX', to: 'ICN', depDateTime: '2026-04-20 14:00', airline: 'Delta', pnr: 'DQWE9K', price: 1350, status: 'ì˜ˆì•½ì™„ë£Œ', needPickup: false, pickupBy: '', housingReady: false },
        { id: 'FL-0014', name: 'Park Sungmin', direction: 'ìž…êµ­', from: 'ICN', to: 'PHX', depDateTime: '2026-04-28 11:00', airline: 'United', pnr: 'URNB4L', price: 1290, status: 'ì˜ˆì•½ì™„ë£Œ', needPickup: true, pickupBy: '', housingReady: false },
      ],
      getOfficeSupplies: async () => [
        { id: 'OF-001', category: 'ì†Œëª¨í’ˆ', name: 'ë³µì‚¬ìš©ì§€ A4', qty: 3, minQty: 5, location: 'ì‚¬ë¬´ì‹¤ ìºë¹„ë„·', lastRestock: '2026-03-28', unitPrice: 45, reorder: true },
        { id: 'OF-002', category: 'ì†Œëª¨í’ˆ', name: 'í† ë„ˆ (í‘ë°±)', qty: 2, minQty: 2, location: 'í”„ë¦°í„°ì‹¤', lastRestock: '2026-03-15', unitPrice: 120, reorder: false },
        { id: 'OF-003', category: 'ì†Œëª¨í’ˆ', name: 'ìƒìˆ˜ (24íŒ©)', qty: 8, minQty: 6, location: 'ëƒ‰ìž¥ê³  ì˜†', lastRestock: '2026-04-08', unitPrice: 18, reorder: false },
        { id: 'OF-004', category: 'ìœ„ìƒ', name: 'í™”ìž¥ì§€ (12ë¡¤)', qty: 4, minQty: 10, location: 'í™”ìž¥ì‹¤ ì°½ê³ ', lastRestock: '2026-04-01', unitPrice: 22, reorder: true },
        { id: 'OF-005', category: 'ìœ„ìƒ', name: 'ì†ì†Œë…ì œ', qty: 3, minQty: 4, location: 'ìž…êµ¬/í™”ìž¥ì‹¤', lastRestock: '2026-03-20', unitPrice: 12, reorder: true },
        { id: 'OF-008', category: 'ì•ˆì „', name: 'ì¼íšŒìš© ìž¥ê°‘ (L)', qty: 1, minQty: 5, location: 'ì•ˆì „ìš©í’ˆí•¨', lastRestock: '2026-03-10', unitPrice: 28, reorder: true },
        { id: 'OF-009', category: 'ì•ˆì „', name: 'ì•ˆì „ ì¡°ë¼', qty: 8, minQty: 10, location: 'ì•ˆì „ìš©í’ˆí•¨', lastRestock: '2026-01-15', unitPrice: 35, reorder: true },
        { id: 'OF-010', category: 'ì‹í’ˆ', name: 'ì¸ìŠ¤í„´íŠ¸ ë¼ë©´', qty: 24, minQty: 12, location: 'ì£¼ë°© ì„ ë°˜', lastRestock: '2026-04-08', unitPrice: 3, reorder: false },
      ],
    };

    // ============================================================
    // ðŸ”´ LiveAPI (Actual Google Sheets Fetching) - Timing Issue Fixed
    // ============================================================
    window.apiCache = {}; // Global Response Cache (window scope for cross-function access)
    const CACHE_TTL = 60000; // 60 seconds

    function gsRun(fnName, args, defaultVal) {
      return new Promise(async function (resolve, reject) {
        const cacheKey = fnName + JSON.stringify(args || []);
        const now = Date.now();

        if (window.apiCache[cacheKey] && (now - window.apiCache[cacheKey].timestamp < CACHE_TTL)) {
          console.log('[Cache Hit]', fnName);
          resolve(JSON.parse(JSON.stringify(window.apiCache[cacheKey].data)));
          return;
        }

        try {
          const tokenEl = document.querySelector('meta[name="csrf-token"]');
          const response = await fetch('/smart-company-api/' + encodeURIComponent(fnName), {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
            },
            body: JSON.stringify({ args: args || [], siteId: _siteId() })
          });

          if (!response.ok) throw new Error('HTTP ' + response.status);
          const res = await response.json();
          const isFailed = (res && (res.success === false || res.error));
          if (!isFailed) {
            window.apiCache[cacheKey] = { data: JSON.parse(JSON.stringify(res)), timestamp: Date.now() };
          }
          resolve(res != null ? res : defaultVal);
        } catch (e) {
          console.warn('[API] ' + fnName + ':', e);
          reject(e);
        }
      });
    }


    // Laravel compatibility shim for legacy google.script.run calls.
    // It keeps the converted SPA stable while the backend moves from GAS to Laravel.
    if (typeof window.google === 'undefined') {
      window.google = { script: {} };
    }
    if (!window.google.script) window.google.script = {};
    window.google.script.run = (function () {
      function makeRunner(successHandler, failureHandler) {
        var runner;
        runner = new Proxy({}, {
          get: function (_, prop) {
            if (prop === 'withSuccessHandler') {
              return function (handler) { return makeRunner(handler, failureHandler); };
            }
            if (prop === 'withFailureHandler') {
              return function (handler) { return makeRunner(successHandler, handler); };
            }
            return function () {
              var args = Array.prototype.slice.call(arguments);
              gsRun(String(prop), args, null)
                .then(function (res) { if (successHandler) successHandler(res); })
                .catch(function (err) { if (failureHandler) failureHandler(err); });
              return runner;
            };
          }
        });
        return runner;
      }
      return makeRunner(null, null);
    })();
    MockAPI.getPersonnelList = async () => [];
    MockAPI.getPersonnelStats = async () => ({
      total: 0,
      active: 0,
      onLeave: 0,
      visaExpiringSoon: 0,
      safetyExpiring: 0,
      byCompany: [],
    });
    MockAPI.getAttendanceLive = async () => ({
      success: true,
      checkedIn: [],
      notCheckedIn: [],
      teamSummary: [],
      totalActive: 0,
      presentCount: 0,
      absentCount: 0,
    });

    window.MockAPI = MockAPI;

    window.API = {
      getHRData: () => gsRun('api_getHRData', [_siteId()], null),
      getDailyTeamMatrix: () => gsRun('api_getDailyTeamMatrix', [_siteId()], { success: false, teams: [], matrix: {}, foremen: {}, subtotals: {}, totals: {} }),
      autoFillTeamDivide: () => gsRun('autoFillTeamDivide', [_siteId()], { success: false }),
      getDailyAttendanceDetail: (date) => gsRun('api_getDailyAttendanceDetail', [_siteId(), date || ''], { success: false, companies: [], teamStats: [], availableDates: [] }),
      // í”„ë¡ íŠ¸ í˜¸í™˜ ì–´ëŒ‘í„°: companies[].teams[].members â†’ .employees + í•„ë“œ ë³„ì¹­
      getAttendanceDetailed: function(date) {
        return gsRun('api_getDailyAttendanceDetail', [_siteId(), date || ''], { success: false, companies: [], teamStats: [] })
          .then(function(r) {
            if (!r || !r.success || !r.companies) return r || { success: false, companies: [] };
            r.companies.forEach(function(c) {
              (c.teams || []).forEach(function(t) {
                t.name = t.team;
                t.employees = (t.members || []).map(function(m) {
                  return Object.assign({}, m, {
                    isWorking: !!m.isOpen,
                    currentRole: m.role
                  });
                });
              });
            });
            r.totalCount = r.totalAttended;
            return r;
          });
      },
      // í†µê³„ ì–´ëŒ‘í„°: companies/teamStats â†’ byCompany/byTeam í˜•ì‹
      getCompanyTeamStats: function(date) {
        return gsRun('api_getDailyAttendanceDetail', [_siteId(), date || ''], { success: false })
          .then(function(r) {
            if (!r || !r.success) return { success: false, byCompany: [], byTeam: [] };
            return {
              success: true,
              date: r.date,
              byCompany: (r.companies || []).map(function(c) {
                return {
                  name: c.name,
                  total: c.total,
                  manager: (c.divide && c.divide.manager) || 0,
                  korean:  (c.divide && c.divide.korean)  || 0,
                  local:   (c.divide && c.divide.local)   || 0
                };
              }),
              byTeam: (r.teamStats || []).map(function(t) {
                return { name: t.team, count: t.count };
              })
            };
          });
      },
      uploadEmployeePhoto: (badgeId, base64, mimeType) => gsRun('api_uploadEmployeePhoto', [_siteId(), badgeId, base64, mimeType || 'image/jpeg'], { success: false }),
      setupEmployeePhotosFolder: () => gsRun('setupEmployeePhotosFolder', [_siteId()], { success: false }),
      getPayrollDashboard: (periodStart) => gsRun('api_getPayrollDashboard', [_siteId(), periodStart || ''], { success: false, companies: [], anomalies: [], employees: [], totals: {}, period: {} }),
      getInventoryDashboard: () => gsRun('api_getInventoryDashboard', [], { success: false, totals: {}, matrix: { categories: [], sites: [], cells: {} }, recent: [], upcomingInspections: [] }),
      getInventoryAssetDetail: (assetId) => gsRun('api_getInventoryAssetDetail', [assetId], { success: false }),
      processInventoryPhotos: () => gsRun('api_processInventoryPhotos', [], { success: false, processed: 0, saved: 0, errors: 0, results: [] }),
      setupInventorySheets: () => gsRun('setupInventorySheets', [], { success: false }),
      setupInventoryFolders: () => gsRun('setupInventoryFolders', [], { success: false }),
      getEmployeeDetail: function(badgeId, date) {
        // ìºì‹œ ë¬´ë ¥í™” â€” ì§ì› í´ë¦­ ì‹œ í•­ìƒ ìµœì‹  ë°ì´í„° ê°€ì ¸ì˜´
        const cacheKey = 'api_getEmployeeDetail' + JSON.stringify([_siteId(), badgeId, date || '']);
        if (window.apiCache && window.apiCache[cacheKey]) delete window.apiCache[cacheKey];
        return gsRun('api_getEmployeeDetail', [_siteId(), badgeId, date || ''], { success: false })
          .then(function(r) {
            if (r && r.employee) {
              r.employee.todayInTime  = r.employee.todayIn || '';
              r.employee.todayOutTime = (r.employee.todayOut && r.employee.todayOut !== 'ë¯¸ë§ˆê°') ? r.employee.todayOut : '';
              r.employee.todayWorking = !!r.employee.isOpen;
            }
            return r;
          });
      },
      getAttendanceDetailed: (date) => gsRun('api_getAttendanceDetailed', [_siteId(), date || null], { success: false, companies: [], totalCount: 0 }),
      clockInWithTeamQr: (teamCode, eventType) => gsRun('api_clockInWithTeamQr', [teamCode, eventType || null], { success: false }),
      getEmployeeDetail: (badgeId) => gsRun('api_getEmployeeDetail', [badgeId, _siteId()], { success: false }),
      getCompanyTeamStats: (date) => gsRun('api_getCompanyTeamStats', [_siteId(), date || null], { success: false, byCompany: [], byTeam: [] }),
      getAvailableDates: () => gsRun('api_getAvailableDates', [_siteId()], { success: false, dates: [] }),
      getLgesProcessData: async () => MockAPI.getLgesProcessData ? await MockAPI.getLgesProcessData() : [],
      getPersonnelList: () => gsRun('api_getPersonnelList', [_siteId()], []),
      getPersonnelStats: () => gsRun('api_getPersonnelStats', [_siteId()], { total: 0, active: 0, onLeave: 0, visaExpiringSoon: 0, safetyExpiring: 0, byCompany: [] }),
      getAttendanceLive: () => {
        if (_siteId() === 'ALL') {
          return gsRun('api_getGlobalAttendance', [], { success: false, mode: 'global', checkedIn: [], notCheckedIn: [], siteStats: {}, totalPresent: 0, totalWorkers: 0 });
        }
        return gsRun('api_getAttendanceLive', [_siteId()], { success: false, checkedIn: [], notCheckedIn: [], teamSummary: [], totalActive: 0, presentCount: 0, absentCount: 0 });
      },
      getGlobalAttendance: () => gsRun('api_getGlobalAttendance', [], { success: false, mode: 'global', checkedIn: [], notCheckedIn: [], siteStats: {}, totalPresent: 0, totalWorkers: 0 }),
      getExpenses: () => gsRun('api_getExpenses', [], []),
      getFinanceStats: () => gsRun('api_getFinanceStats', [], { mtdTotal: 0, mtdBudget: 50000, pendingApproval: 0, pendingAmount: 0, claimable: 0, byCategory: [] }),
      getEquipmentList: () => gsRun('api_getEquipmentList', [], []),
      getEquipmentStats: () => gsRun('api_getEquipmentStats', [], { total: 0, operable: 0, inoperable: 0, todayInspections: 0 }),
      getToolList: () => gsRun('api_getToolList', [], []),
      getToolStats: () => gsRun('api_getToolStats', [], { total: 0, available: 0, checkedOut: 0, damaged: 0 }),
      getAlerts: () => gsRun('api_getAlerts', [], []),
      getSafetyStats: () => gsRun('api_getSafetyStats', [], { daysNoIncident: 0, unresolved: 0, resolved: 0, urgent: 0, warning: 0, normal: 0 }),
      getPtwList: () => gsRun('api_getPtwList', [], []),
      getPtwStats: () => gsRun('api_getPtwStats', [], {todayActive:0, pending:0, completed:0, rejected:0}),
      getInspections: () => gsRun('api_getInspections', [], []),
      getInspectionStats: () => gsRun('api_getInspectionStats', [], {totalItems:0, passed:0, failed:0, completionRate:0}),
      getTrainingRecords: () => gsRun('api_getTrainingRecords', [], []),
      getSafetyDocs: () => gsRun('api_getSafetyDocs', [], []),
      getOshaForm300: () => gsRun('api_getOshaForm300', [], []),
      getOsha300AStats: (yr) => gsRun('api_getOsha300AStats', [yr||2026], {year:2026,totalCases:0,dartRate:'0.00',trir:'0.00'}),
      getCertMatrix: () => gsRun('api_getCertMatrix', [], []),
      getViolations: () => gsRun('api_getViolations', [], []),
      getAlerts: (f) => gsRun('api_getAlerts', [f||'all'], []),
      updateAlertStatus: (id, status) => gsRun('api_updateAlertStatus', [id, status], {success:false}),
      getTbmRecords: () => gsRun('api_getTbmRecords', [], []),
      getVehicleList: () => gsRun('api_getVehicleList', [], []),
      getVehicleStats: () => gsRun('api_getVehicleStats', [], { total: 0, active: 0, available: 0, maintenance: 0 }),
      processRentalContracts: () => gsRun('api_processRentalContracts', [], { success: false, processed: 0, saved: 0, errors: 0, results: [] }),
      getRentalList: () => gsRun('api_getRentalList', [], []),
      getRentalStats: () => gsRun('api_getRentalStats', [], { total: 0, active: 0, overdue: 0, returned: 0, returningSoon: 0, mtdCost: 0 }),
      createRental: (payload) => gsRun('api_createRental', [payload], { success: false }),
      returnRental: (id, date) => gsRun('api_returnRental', [id, date], { success: false }),
      processEquipmentRentalContracts: () => gsRun('api_processEquipmentRentalContracts', [], { success: false, processed: 0, saved: 0, errors: 0, results: [] }),
      setupRentalSheet: () => gsRun('setupRentalSheet', [], { success: false }),
      generateSampleRentalContracts: () => gsRun('generateSampleRentalContracts', [], { success: false, count: 0, results: [] }),
      cleanEmptyRentalRows: () => gsRun('api_cleanEmptyRentalRows', [], { success: false, deleted: 0 }),
      getHousingList: () => gsRun('api_getHousingList', [], []),
      getHousingStats: () => gsRun('api_getHousingStats', [], { total: 0, occupied: 0, available: 0, maintenance: 0 }),
      getFlightList: () => gsRun('api_getFlightList', [], []),
      getOfficeSupplies: () => gsRun('api_getOfficeSupplies', [], []),
      getCommandCenter: (siteId) => gsRun('api_getConstructionCommandCenter', [siteId || _siteId()], null),
      getKPIs: async () => {
        try {
          const [pStats, fStats, eStats, sStats, hStats] = await Promise.all([
            window.API.getPersonnelStats(),
            window.API.getFinanceStats(),
            window.API.getEquipmentStats(),
            window.API.getSafetyStats(),
            window.API.getHousingStats()
          ]);
          return [
            { label: 'í˜„ìž¥ ì¸ì›', value: String(pStats.active || 0), unit: 'ëª…', trend: 'ì´ì› ' + (pStats.total || 0) + 'ëª…', trendType: 'up', icon: 'ph-users' },
            { label: 'ì¤‘ìž¥ë¹„ ê°€ë™', value: (eStats.operable || 0) + '/' + (eStats.total || 0), unit: 'ëŒ€', trend: 'ìˆ˜ë¦¬ëŒ€ê¸° ' + (eStats.inoperable || 0) + 'ëŒ€', trendType: eStats.inoperable > 0 ? 'down' : 'up', icon: 'ph-truck' },
            { label: 'MTD ì§€ì¶œ', value: '$' + (fStats.mtdTotal ? (fStats.mtdTotal / 1000).toFixed(1) + 'K' : '0'), unit: 'USD', trend: 'ìŠ¹ì¸ëŒ€ê¸° $' + (fStats.pendingAmount || 0), trendType: 'neutral', icon: 'ph-currency-dollar' },
            { label: 'ë¯¸ì²˜ë¦¬ ì•ˆì „ì´ìŠˆ', value: String(sStats.unresolved || 0), unit: 'ê±´', trend: 'ë¬´ì‚¬ê³  ' + (sStats.daysNoIncident || 0) + 'ì¼', trendType: sStats.unresolved > 0 ? 'down' : 'up', icon: 'ph-warning-circle' },
            { label: 'ìˆ™ì†Œ ê°€ë™', value: String(hStats.occupancyRate || 0), unit: '%', trend: 'ìž”ì—¬ ' + (hStats.available || 0) + 'ê°œ', trendType: 'neutral', icon: 'ph-buildings' }
          ];
        } catch (e) { return MockAPI.getKPIs ? await MockAPI.getKPIs() : []; }
      },
      getDailyAlertScan: async () => {
        try {
          const alerts = await window.API.getAlerts();
          const unresolved = alerts.filter(function (a) { return a.status === 'ë¯¸ì²˜ë¦¬' || !a.status; }).length;
          return [
            "ì¼ì¼ í†µí•© ì ê²€ ìŠ¤ìº” ì™„ë£Œ",
            "ì „ì¼ ì•ˆì „ì´ìŠˆ: ë¯¸ì²˜ë¦¬ ê±´ìˆ˜ (" + unresolved + ")",
            "ë¹„ìž ë§Œë£Œ ì˜ˆì •ìž ë°ì´í„° ì—°ë™ ì™„ë£Œ"
          ];
        } catch (e) { return ["ì‹œìŠ¤í…œ ì •ìƒë™ìž‘ì¤‘"]; }
      },
      getProjectStatus: () => gsRun('api_getProjectStatus', [], []),
      getActionItems: () => gsRun('api_getActionItems', [], []),
      // WBS ê³µì •ê´€ë¦¬ APIs
      getProjectWbsTree: (projectId) => gsRun('api_getProjectWbsTree', [projectId], { success: false, stages: [] }),
      updateWbsRow: (wbsId, updates) => gsRun('api_updateWbsRow', [wbsId, updates], { success: false }),
      getProjectProgressSummary: (projectId) => gsRun('api_getProjectProgressSummary', [projectId], { success: false }),
      processWbsManual: (projectId) => gsRun('api_processWbsManual', [projectId], { success: false }),
      markWbsStatus: (wbsId, status) => gsRun('api_markWbsStatus', [wbsId, status], { success: false }),
      getToolTransactions: () => gsRun('api_getToolTransactions', [], []),
      getVendorList:      () => gsRun('api_getVendorList',     [], []),
      createVendor:       (data) => gsRun('api_createVendor',  [data], {success:false}),
      generateVendorEmailPrompt: (prompt, email, name) => gsRun('api_generateVendorEmailPrompt', [prompt, email, name], {success:false}),
      translateToEnglish: (draft) => gsRun('api_translateToEnglish', [draft], {success:false}),
      sendVendorEmail:    (email, subject, body, name) => gsRun('api_sendVendorEmail', [email, subject, body, name], {success:false}),
      getVendorReplies:   (email) => gsRun('api_getVendorReplies', [email], {success:true, replies:[]})
    };

    window.LiveAPI = window.API;

    const SYSTEM_CONFIG = {
      // ì‚¬ìš©ìžëŠ” ì‹œìŠ¤í…œ ì…‹ì—… ì‹œ ì¶œë ¥ëœ Google Forms/Sheet URLì„ ì—¬ê¸°ì— ìž…ë ¥í•˜ì„¸ìš”.
      forms: {
        expense: 'https://docs.google.com/forms/d/e/1FAIpQLSfHXwLyGZsB0fAtg2grcIA6ew6LObxiRMfvMyj5A9iOaav_jw/viewform', // ë¹„ìš©ì²­êµ¬ í¼ ê³µìœ  ë§í¬
        equipment: 'https://docs.google.com/forms/d/e/1FAIpQLSe2u46nVxdRZ0_Iom_FCUanVBVG86uqRYa05x43HYCCYt2xHg/viewform', // ìž¥ë¹„ì ê²€ í¼ ê³µìœ  ë§í¬
        hr: 'https://docs.google.com/forms/d/e/1FAIpQLScjmUfYk-4w_97XTZgIF-z0MTULEEqblPXJEY3LrBKyijmWQw/viewform', // ì¸ì›ë“±ë¡ í¼ ê³µìœ  ë§í¬
        housing: 'https://docs.google.com/forms/d/e/1FAIpQLSfwwgS8RP8Rj3VqlQPHTFqUDeStrxFUOdZkHK9kMiZAVix5ng/viewform' // ìˆ™ì†Œì°¨ëŸ‰ì‹ ê³  í¼ ê³µìœ  ë§í¬
      },
      sheetUrl: 'https://docs.google.com/spreadsheets/d/1FhIjAaBuk0A2m72ywI5wH3gGmQEYxwozLaWBrWE88ss/edit' // ì‹œíŠ¸ ë§ˆìŠ¤í„° (ì˜ˆ: https://docs.google.com/spreadsheets/d/ID/edit)
    };

    window.openGoogleForm = function (type) {
      if (SYSTEM_CONFIG.forms[type]) {
        window.open(SYSTEM_CONFIG.forms[type], '_blank');
      } else {
        alert('êµ¬ê¸€ í¼ URLì´ ì„¤ì •ë˜ì§€ ì•Šì•˜ìŠµë‹ˆë‹¤. Setup.gs ì‹¤í–‰ í›„ ì¶œë ¥ëœ ë§í¬ë¥¼ ì½”ë“œì˜ SYSTEM_CONFIG êµ¬ì—­ì— ìž…ë ¥í•´ì£¼ì„¸ìš”.');
      }
    };
    window.openMasterSheet = function () {
      if (SYSTEM_CONFIG.sheetUrl) {
        window.open(SYSTEM_CONFIG.sheetUrl, '_blank');
      } else {
        alert('êµ¬ê¸€ ì‹œíŠ¸ ë§ˆìŠ¤í„° URLì´ ì„¤ì •ë˜ì§€ ì•Šì•˜ìŠµë‹ˆë‹¤. ì½”ë“œ ë‚´ë¶€ì˜ SYSTEM_CONFIG.sheetUrlì„ ìž…ë ¥í•´ ì£¼ì„¸ìš”.');
      }
    };

    // ============================================================
    // ERP SPA APPLICATION
    // ============================================================
    document.addEventListener('DOMContentLoaded', function () {
      const navItems = document.querySelectorAll('.nav-item');
      const pageContainer = document.getElementById('page-container');
      const breadcrumbCurrent = document.getElementById('breadcrumb-current');
      const alertBadge = document.getElementById('alert-badge');
      const accountStorageKey = 'nahshonAccountProfile';
      const authenticatedAccount = @json($authUser);
      const accountDefaults = {
        company: 'NAHSHON MEP',
        name: authenticatedAccount.name || 'ERP User',
        firstName: (authenticatedAccount.name || 'ERP').split(' ')[0] || 'ERP',
        lastName: (authenticatedAccount.name || 'User').split(' ').slice(1).join(' ') || 'User',
        preferredName: '',
        employeeCode: authenticatedAccount.email || 'ERP',
        jobTitle: authenticatedAccount.role || 'ERP User',
        department: 'Operations',
        location: 'HFF-02',
        manager: 'NAHSHON MEP',
        email: authenticatedAccount.email || '',
        personalEmail: authenticatedAccount.email || '',
        mobile: '+1 (602) 435-6787',
        direct: '+1 (555) 987-6543',
        timezone: 'America/Phoenix'
      };
      function loadAccountProfileData() {
        try {
          return Object.assign({}, accountDefaults, JSON.parse(localStorage.getItem(accountStorageKey) || '{}'));
        } catch (e) {
          return Object.assign({}, accountDefaults);
        }
      }
      let accountProfile = loadAccountProfileData();

      const routes = {
        'dashboard': { title: 'Overview', render: renderDashboard },
        'attendance': { title: '출석관리', render: function () { window._pendingHrTab = 'attendance'; return renderHR(); } },
        'receipts': { title: '영수증처리', render: renderFinance },
        'messages': { title: '메세지', render: renderAlerts },
        'schedule': { title: '일정관리', render: renderWbs },
        'personnel': { title: '인원관리', render: function () { window._pendingHrTab = 'personnel'; return renderHR(); } },
        'profile': { title: 'My Profile', render: renderAccountProfile },
        'profile-update': { title: 'Update Profile', render: renderAccountUpdateProfile },
        'ui-settings': { title: 'UI Settings', render: renderAccountUiSettings },
        'password': { title: 'Change Password', render: renderAccountPassword },
        'command': { title: 'AI í˜„ìž¥ ì§€íœ˜ì‹¤', render: renderCommandCenter },
        'analytics': { title: 'ë¶„ì„ ë°ì´í„°', render: renderAnalytics },
        'alerts': { title: 'í†µí•© ì•Œë¦¼ ì„¼í„°', render: renderAlerts },
        'safety': { title: 'AI ìž‘ì—…ì•ˆì „ê´€ë¦¬', render: renderSafety },
        'hr': { title: 'ì¸ì›ê´€ë¦¬', render: renderHR },
        'payroll': { title: '급여 / 정산', render: renderPayroll },
        'wbs': { title: 'ê³µì • ê´€ë¦¬ (WBS)', render: renderWbs },
        'finance': { title: 'ìž¬ë¬´ / ë¹„ìš©', render: renderFinance },
        'inventory': { title: 'ìžìž¬ / ìž¥ë¹„', render: renderInventory },
        'vehicle': { title: 'ì°¨ëŸ‰ ê´€ë¦¬', render: renderVehicle },
        'rental': { title: 'ìž¥ë¹„ ë Œíƒˆ ê´€ë¦¬', render: renderRental },
        'housing': { title: 'ìˆ™ì†Œ ê´€ë¦¬', render: renderHousing },
        'vendors': { title: 'êµ¬ë§¤/ë ŒíŠ¸ ê´€ë¦¬', render: renderVendors },
    
        'flights': { title: 'í•­ê³µê¶Œ ê´€ë¦¬', render: renderFlights },
        'office': { title: 'í˜„ìž¥ì‚¬ë¬´ì‹¤ ë¹„í’ˆ', render: renderOffice },
      };

      const mobileMoreButton = document.getElementById('mobile-more-button');
      const mobileMoreBackdrop = document.getElementById('mobile-more-backdrop');
      const mobilePrimaryButtons = document.querySelectorAll('.mobile-tabbar-item[data-mobile-view]');
      const mobileMoreTiles = document.querySelectorAll('.mobile-more-tile[data-mobile-view]');
      const mobileRouteAliases = {
        hr: 'attendance',
        finance: 'receipts',
        alerts: 'messages',
        wbs: 'schedule',
      };

      function normalizeMobileView(viewKey) {
        return mobileRouteAliases[viewKey] || viewKey;
      }

      function closeMobileMore() {
        if (!mobileMoreButton || !mobileMoreBackdrop) return;
        mobileMoreButton.setAttribute('aria-expanded', 'false');
        mobileMoreBackdrop.hidden = true;
        mobileMoreBackdrop.classList.remove('is-open');
        document.body.classList.remove('mobile-more-open');
      }

      function openMobileMore() {
        if (!mobileMoreButton || !mobileMoreBackdrop) return;
        mobileMoreButton.setAttribute('aria-expanded', 'true');
        mobileMoreBackdrop.hidden = false;
        mobileMoreBackdrop.classList.add('is-open');
        document.body.classList.add('mobile-more-open');
      }

      function syncMobileNavigation(viewKey) {
        var normalizedView = normalizeMobileView(viewKey);
        var primaryActive = false;
        mobilePrimaryButtons.forEach(function (button) {
          var isActive = button.getAttribute('data-mobile-view') === normalizedView;
          button.classList.toggle('active', isActive);
          if (isActive) primaryActive = true;
        });
        mobileMoreTiles.forEach(function (tile) {
          tile.classList.toggle('active', tile.getAttribute('data-mobile-view') === viewKey);
        });
        if (mobileMoreButton) {
          mobileMoreButton.classList.toggle('active', !primaryActive);
        }
      }

      function prepareViewNavigation(view) {
        if ((view === 'hr' || view === 'attendance' || view === 'personnel') && window.currentSiteId !== 'ALL') {
          var sel = document.getElementById('project-context-switcher');
          if (sel) sel.value = 'ALL';
          window.currentSiteId = 'ALL';
          if (window.apiCache) Object.keys(window.apiCache).forEach(function(k) { delete window.apiCache[k]; });
        }
      }

      if (mobileMoreButton) {
        mobileMoreButton.addEventListener('click', function () {
          if (mobileMoreButton.getAttribute('aria-expanded') === 'true') closeMobileMore();
          else openMobileMore();
        });
      }
      if (mobileMoreBackdrop) {
        mobileMoreBackdrop.addEventListener('click', function (event) {
          if (event.target === mobileMoreBackdrop || event.target.closest('[data-mobile-more-close]')) {
            closeMobileMore();
          }
        });
      }
      document.querySelectorAll('[data-mobile-view]').forEach(function (button) {
        button.addEventListener('click', function () {
          var view = button.getAttribute('data-mobile-view');
          if (!view) return;
          closeMobileMore();
          window.goToView(view);
        });
      });
      document.querySelectorAll('[data-mobile-action="scanner"]').forEach(function (button) {
        button.addEventListener('click', function () {
          closeMobileMore();
          if (typeof window.openUniversalScanner === 'function') window.openUniversalScanner();
        });
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeMobileMore();
      });

      navItems.forEach(function (item) {
        item.addEventListener('click', function () {
          navItems.forEach(function (n) { n.classList.remove('active'); });
          item.classList.add('active');
          var view = item.getAttribute('data-view');
          prepareViewNavigation(view);
          closeMobileMore();
          loadView(view);
        });
      });

      var accountButton = document.getElementById('account-menu-button');
      var accountDropdown = document.getElementById('account-dropdown');
      var sidebarUserBlock = document.querySelector('.user-block');
      function closeAccountDropdown() {
        if (!accountButton || !accountDropdown) return;
        accountButton.setAttribute('aria-expanded', 'false');
        accountDropdown.hidden = true;
      }
      function openAccountView(viewKey) {
        navItems.forEach(function (n) { n.classList.remove('active'); });
        closeAccountDropdown();
        window.loadView(viewKey);
      }
      function submitErpLogout() {
        var form = document.createElement('form');
        var token = document.querySelector('meta[name="csrf-token"]');
        var field = document.createElement('input');
        form.method = 'POST';
        form.action = @json(route('logout'));
        field.type = 'hidden';
        field.name = '_token';
        field.value = token ? token.getAttribute('content') : '';
        form.appendChild(field);
        document.body.appendChild(form);
        form.submit();
      }
      if (accountButton && accountDropdown) {
        accountButton.addEventListener('click', function (event) {
          event.stopPropagation();
          var isOpen = accountButton.getAttribute('aria-expanded') === 'true';
          accountButton.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
          accountDropdown.hidden = isOpen;
        });
        accountDropdown.addEventListener('click', function (event) {
          if (event.target.closest('[data-account-logout]')) {
            event.preventDefault();
            event.stopPropagation();
            closeAccountDropdown();
            submitErpLogout();
            return;
          }
          var item = event.target.closest('[data-account-view]');
          if (!item) return;
          openAccountView(item.getAttribute('data-account-view'));
        });
        accountDropdown.querySelectorAll('[data-account-view]').forEach(function (item) {
          item.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            openAccountView(item.getAttribute('data-account-view'));
          });
        });
        var logoutButton = accountDropdown.querySelector('[data-account-logout]');
        if (logoutButton) {
          logoutButton.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            closeAccountDropdown();
            submitErpLogout();
          });
        }
        document.addEventListener('click', function (event) {
          if (!event.target.closest('.account-menu-wrap')) closeAccountDropdown();
        });
      }
      if (sidebarUserBlock) sidebarUserBlock.addEventListener('click', function () { openAccountView('profile'); });
      var settingsButton = document.getElementById('btn-settings');
      if (settingsButton) settingsButton.addEventListener('click', function () { openAccountView('ui-settings'); });

      window.loadView = function loadView(viewKey) {
        var route = routes[viewKey];
        if (!route) return;
        window._currentView = viewKey;
        syncMobileNavigation(viewKey);
        breadcrumbCurrent.textContent = route.title;
        pageContainer.style.opacity = '0.3';
        setTimeout(function () {
          pageContainer.innerHTML = '';
          route.render();
          pageContainer.style.opacity = '1';
        }, 120);
      }

      window.goToView = function(viewKey) {
        var target = document.querySelector('.nav-item[data-view="' + viewKey + '"]');
        prepareViewNavigation(viewKey);
        if (target) {
          navItems.forEach(function(n) { n.classList.remove('active'); });
          target.classList.add('active');
        }
        window.loadView(viewKey);
      };

      function statusPill(text) {
        var map = {
          'ìš´í–‰ê°€ëŠ¥': 'ok', 'ì™„ë£Œ': 'ok', 'ë³´ê´€ì¤‘': 'ok', 'ì •ìƒ': 'ok', 'ì²­êµ¬ì™„ë£Œ': 'ok', 'ì²˜ë¦¬ì™„ë£Œ': 'ok', 'ë°œê¶Œ': 'ok',
          'ìš´í–‰ë¶ˆê°€': 'critical', 'ê¸´ê¸‰': 'critical', 'ìˆ˜ë¦¬í•„ìš”': 'critical', 'ì†ìƒ': 'critical', 'ë¯¸ì´ìˆ˜': 'critical',
          'ì ê²€ì¤‘': 'warning', 'ë¶ˆì¶œì¤‘': 'warning', 'ì£¼ì˜': 'warning', 'ìŠ¹ì¸ëŒ€ê¸°': 'warning', 'ì²˜ë¦¬ì¤‘': 'warning', 'ë§Œë£Œìž„ë°•': 'warning', 'ë°˜ë‚©ì˜ˆì •': 'warning', 'ì •ë¹„ì¤‘': 'warning', 'ì˜ˆì•½ì™„ë£Œ': 'warning',
          'ë¯¸ì²˜ë¦¬': 'pending', 'ë¯¸ì²­êµ¬': 'pending', 'ì¼ë°˜': 'pending'
        };
        var cls = map[text] || 'pending';
        return '<span class="status-pill ' + cls + '">' + text + '</span>';
      }

      function fmtUSD(n) {
        return '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
      }

      function skeleton() {
        return '<div class="skeleton-loader"><div class="sk-line sk-w60"></div><div class="sk-line sk-w80"></div><div class="sk-line sk-w40"></div></div>';
      }

      function renderError(msg) {
        pageContainer.innerHTML = '<div class="empty-state"><i class="ph ph-warning-circle" style="font-size:48px;color:var(--status-danger)"></i><p style="color:var(--status-danger)">' + msg + '</p></div>';
      }

      function safeHtml(value) {
        return String(value == null ? '' : value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      }

      function toNumber(value) {
        if (typeof value === 'number') return isFinite(value) ? value : 0;
        return Number(String(value || '0').replace(/[^\d.-]/g, '')) || 0;
      }

      function daysUntil(dateText) {
        if (!dateText) return null;
        var d = new Date(String(dateText).replace(/\./g, '-'));
        if (isNaN(d.getTime())) return null;
        return Math.ceil((d.getTime() - Date.now()) / 86400000);
      }

      function currentSiteLabel() {
        var sel = document.getElementById('project-context-switcher');
        if (sel && sel.selectedIndex >= 0) return sel.options[sel.selectedIndex].text;
        return window.currentSiteId || 'ì „ì²´ í˜„ìž¥';
      }

      function accountValue(value, fallback) {
        var text = value == null || value === '' ? (fallback || 'Not set') : value;
        return safeHtml(text);
      }

      function accountInfoRows(rows) {
        return '<div class="account-info-grid">' + rows.map(function (row) {
          var badge = row.readonly ? '<span class="readonly-badge">Read-only</span>' : '';
          return '<div class="account-info-row">' +
            '<div class="account-info-label">' + safeHtml(row.label) + badge + '</div>' +
            '<div class="account-info-value">' + accountValue(row.value, row.fallback) + '</div>' +
            '</div>';
        }).join('') + '</div>';
      }

      function accountShell(viewKey, title, subtitle, body) {
        pageContainer.innerHTML =
          '<div class="account-layout" data-account-view="' + safeHtml(viewKey) + '">' +
            '<div class="account-main">' +
              '<div class="account-crumbs"><button class="btn-secondary" type="button" data-account-back="profile"><i class="ph ph-arrow-left"></i> Back to Profile</button><span>Home</span><span>/</span><span class="active">' + safeHtml(title) + '</span></div>' +
              '<section class="account-hero">' +
                '<h1>' + safeHtml(title) + '</h1>' +
                '<p>' + safeHtml(subtitle) + '</p>' +
              '</section>' +
              body +
            '</div>' +
          '</div>';

        pageContainer.querySelectorAll('[data-account-back]').forEach(function (btn) {
          btn.addEventListener('click', function () { window.loadView(btn.getAttribute('data-account-back')); });
        });
      }

      function renderAccountProfile() {
        var body =
          '<section class="account-card">' +
            '<div class="account-card-title"><i class="ph ph-user-circle"></i> Personal Information</div>' +
            accountInfoRows([
              { label: 'Employee Code', value: accountProfile.employeeCode },
              { label: 'First Name', value: accountProfile.firstName },
              { label: 'Last Name', value: accountProfile.lastName },
              { label: 'Preferred Name', value: accountProfile.preferredName, fallback: 'Not set' }
            ]) +
          '</section>' +
          '<section class="account-card">' +
            '<div class="account-card-title"><i class="ph ph-briefcase"></i> Employment Information</div>' +
            accountInfoRows([
              { label: 'Job Title', value: accountProfile.jobTitle, readonly: true },
              { label: 'Department', value: accountProfile.department, readonly: true },
              { label: 'Location', value: accountProfile.location, readonly: true },
              { label: 'Company', value: accountProfile.company, readonly: true },
              { label: 'Manager', value: accountProfile.manager, readonly: true }
            ]) +
          '</section>' +
          '<section class="account-card">' +
            '<div class="account-card-title"><i class="ph ph-address-book"></i> Contact Information</div>' +
            accountInfoRows([
              { label: 'Login Email', value: accountProfile.email, readonly: true },
              { label: 'Personal Email', value: accountProfile.personalEmail },
              { label: 'Mobile Number', value: accountProfile.mobile },
              { label: 'Direct Number', value: accountProfile.direct }
            ]) +
            '<div class="account-actions-row" style="margin-top:16px">' +
              '<button class="btn-primary" type="button" data-account-action="edit"><i class="ph ph-pencil-simple"></i> Update Profile</button>' +
              '<button class="btn-secondary" type="button" data-account-action="settings"><i class="ph ph-sliders-horizontal"></i> UI Settings</button>' +
            '</div>' +
          '</section>';
        accountShell('profile', accountProfile.name, accountProfile.jobTitle + ' • ' + accountProfile.department, body);
        pageContainer.querySelector('[data-account-action="edit"]').addEventListener('click', function () { window.loadView('profile-update'); });
        pageContainer.querySelector('[data-account-action="settings"]').addEventListener('click', function () { window.loadView('ui-settings'); });
      }

      function renderAccountUpdateProfile() {
        var body =
          '<section class="account-card light">' +
            '<div class="account-note"><strong>What you can update:</strong> You can update your preferred name, personal email, mobile number, and direct number.</div>' +
            '<form class="account-form" id="account-profile-form" style="margin-top:24px">' +
              '<div class="account-card-title">Personal Information</div>' +
              '<div class="account-field"><label for="account-preferred-name">Preferred Name</label><input class="account-input" id="account-preferred-name" name="preferredName" value="' + accountValue(accountProfile.preferredName, '') + '" placeholder="Preferred Name"></div>' +
              '<div class="account-card-title" style="margin-top:8px">Contact Information</div>' +
              '<div class="account-field"><label for="account-personal-email">Personal Email</label><input class="account-input" id="account-personal-email" name="personalEmail" value="' + accountValue(accountProfile.personalEmail, '') + '" placeholder="your.email@example.com"></div>' +
              '<div class="account-field"><label for="account-mobile">Mobile Number</label><input class="account-input" id="account-mobile" name="mobile" value="' + accountValue(accountProfile.mobile, '') + '" placeholder="+1 (555) 000-0000"></div>' +
              '<div class="account-field"><label for="account-direct">Direct Number</label><input class="account-input" id="account-direct" name="direct" value="' + accountValue(accountProfile.direct, '') + '" placeholder="+1 (555) 000-0000"></div>' +
              '<div class="account-actions-row"><button class="btn-primary" type="submit"><i class="ph ph-floppy-disk"></i> Save Changes</button><button class="btn-secondary" type="button" data-account-cancel>Cancel</button></div>' +
            '</form>' +
          '</section>';
        accountShell('profile-update', 'Update Profile', 'Update your personal contact information', body);
        var form = document.getElementById('account-profile-form');
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          var data = new FormData(form);
          accountProfile = Object.assign({}, accountProfile, {
            preferredName: String(data.get('preferredName') || '').trim(),
            personalEmail: String(data.get('personalEmail') || '').trim(),
            mobile: String(data.get('mobile') || '').trim(),
            direct: String(data.get('direct') || '').trim()
          });
          localStorage.setItem(accountStorageKey, JSON.stringify(accountProfile));
          window.showToast ? window.showToast('Profile updated.', false) : alert('Profile updated.');
          window.loadView('profile');
        });
        pageContainer.querySelector('[data-account-cancel]').addEventListener('click', function () { window.loadView('profile'); });
      }

      function renderAccountUiSettings() {
        var lang = localStorage.getItem('smartCompanyLanguage') || 'ko';
        var body =
          '<section class="account-card">' +
            '<div class="account-card-title"><i class="ph ph-monitor"></i> Display Settings</div>' +
            '<div class="settings-row"><div><div class="settings-title">Interface Style <span class="readonly-badge">Beta</span></div><div class="settings-desc">Choose between Classic and the new left sidebar interface.</div></div><select class="account-select"><option>New Interface (Left Sidebar)</option><option>Classic (Top Navbar)</option></select></div>' +
            '<div class="settings-row"><div><div class="settings-title">Theme</div><div class="settings-desc">Choose light, dark, or automatic based on system settings.</div></div><select class="account-select"><option>Auto (System)</option><option>Dark</option><option>Light</option></select></div>' +
            '<div class="settings-row"><div><div class="settings-title">Language</div><div class="settings-desc">Select the language displayed in the interface.</div></div><select class="account-select" id="account-language-select" data-no-i18n><option value="ko">한국어</option><option value="en">English</option><option value="es">Español</option></select></div>' +
            '<div class="settings-row"><div><div class="settings-title">Timezone</div><div class="settings-desc">Select the timezone used for displaying dates and times.</div></div><select class="account-select"><option value="America/Phoenix">(UTC-07:00) Arizona Time</option><option value="America/Los_Angeles">(UTC-08:00) Pacific Time</option><option value="America/New_York">(UTC-05:00) Eastern Time</option></select></div>' +
          '</section>' +
          '<section class="account-card">' +
            '<div class="account-card-title"><i class="ph ph-folder"></i> Document/Folder Settings</div>' +
            '<div class="settings-row"><div><div class="settings-title">Default View Mode</div><div class="settings-desc">Select the default view for document and folder lists.</div></div><select class="account-select"><option>Grid</option><option>List</option></select></div>' +
            '<div class="settings-row"><div><div class="settings-title">Default Sort By</div><div class="settings-desc">Select the default sorting criteria for document lists.</div></div><select class="account-select"><option>Upload Date</option><option>Name</option><option>Project</option></select></div>' +
            '<div class="settings-row"><div><div class="settings-title">Default Sort Order</div><div class="settings-desc">Choose ascending or descending order.</div></div><select class="account-select"><option>Descending</option><option>Ascending</option></select></div>' +
          '</section>';
        accountShell('ui-settings', 'UI Settings', 'Configure your user interface defaults', body);
        var langSelect = document.getElementById('account-language-select');
        if (langSelect) {
          langSelect.value = lang;
          langSelect.addEventListener('change', function () {
            if (window.smartCompanySetLanguage) window.smartCompanySetLanguage(langSelect.value);
          });
        }
      }

      function renderAccountPassword() {
        var body =
          '<section class="account-card light">' +
            '<div class="account-warning"><strong>Security Notice:</strong> Choose a strong password that you do not use elsewhere. We recommend using a combination of letters, numbers, and special characters.</div>' +
            '<div class="account-note" style="margin-top:18px"><strong>Password Requirements:</strong><ul style="margin:8px 0 0 18px"><li>At least 8 characters</li><li>At least one uppercase letter</li><li>At least one lowercase letter</li><li>At least one number</li></ul></div>' +
            '<form class="account-form" id="account-password-form" style="margin-top:24px">' +
              '<div class="account-card-title">Password Information</div>' +
              '<div class="account-field"><label for="account-current-password">Current Password</label><input class="account-input" id="account-current-password" type="password" placeholder="Enter your current password"></div>' +
              '<div class="account-field"><label for="account-new-password">New Password</label><input class="account-input" id="account-new-password" type="password" placeholder="Enter your new password"></div>' +
              '<div class="account-field"><label for="account-confirm-password">Confirm New Password</label><input class="account-input" id="account-confirm-password" type="password" placeholder="Re-enter your new password"></div>' +
              '<div class="account-actions-row"><button class="btn-primary" type="submit"><i class="ph ph-lock-key"></i> Change Password</button><button class="btn-secondary" type="button" data-account-cancel>Cancel</button></div>' +
            '</form>' +
          '</section>';
        accountShell('password', 'Change Password', 'Secure your account with a strong password', body);
        var form = document.getElementById('account-password-form');
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          var current = document.getElementById('account-current-password').value;
          var next = document.getElementById('account-new-password').value;
          var confirm = document.getElementById('account-confirm-password').value;
          if (!current || !next || !confirm) {
            window.showToast('Please fill in all password fields.', true);
            return;
          }
          if (next !== confirm) {
            window.showToast('New password and confirmation do not match.', true);
            return;
          }
          if (next.length < 8 || !/[A-Z]/.test(next) || !/[a-z]/.test(next) || !/[0-9]/.test(next)) {
            window.showToast('Password does not meet the requirements.', true);
            return;
          }
          form.reset();
          window.showToast('Password change request is ready for backend connection.', false);
        });
        pageContainer.querySelector('[data-account-cancel]').addEventListener('click', function () { window.loadView('profile'); });
      }

      function levelClass(level) {
        level = String(level || '').toLowerCase();
        if (level === 'critical' || level === 'ê¸´ê¸‰') return 'critical';
        if (level === 'warning' || level === 'ì£¼ì˜') return 'warning';
        return 'ok';
      }

      function metricCard(label, value, hint, icon, level) {
        return '<div class="command-metric">' +
          '<div class="label">' + safeHtml(label) + '<i class="ph ' + icon + '"></i></div>' +
          '<div class="value ' + (level === 'critical' ? 'text-danger' : level === 'warning' ? 'text-warning' : '') + '">' + value + '</div>' +
          '<div class="hint">' + safeHtml(hint) + '</div>' +
          '</div>';
      }

      function commandChip(label, level) {
        return '<span class="command-chip ' + levelClass(level) + '">' + safeHtml(label) + '</span>';
      }

      async function buildCommandCenterSnapshot() {
        var results = await Promise.allSettled([
          window.API.getPersonnelStats(),
          window.API.getAttendanceLive(),
          window.API.getFinanceStats(),
          window.API.getSafetyStats(),
          window.API.getAlerts('all'),
          window.API.getProjectStatus(),
          window.API.getRentalStats(),
          window.API.getInventoryDashboard()
        ]);
        function val(i, fallback) {
          return results[i].status === 'fulfilled' && results[i].value ? results[i].value : fallback;
        }

        var personnel = val(0, {});
        var attendance = val(1, {});
        var finance = val(2, {});
        var safety = val(3, {});
        var alerts = val(4, []);
        var projects = val(5, []);
        var rental = val(6, {});
        var inventory = val(7, {});
        var openAlerts = (alerts || []).filter(function(a) {
          var st = String(a.status || '');
          return st !== 'ì™„ë£Œ' && st !== 'ì²˜ë¦¬ì™„ë£Œ' && st !== 'ë¬´ì‹œ';
        });
        var present = toNumber(attendance.totalPresent || attendance.presentCount);
        var totalWorkers = toNumber(attendance.totalWorkers || attendance.totalActive || personnel.active || personnel.total);
        var absent = toNumber(attendance.absentCount || Math.max(0, totalWorkers - present));
        var revenueAtRisk = toNumber(finance.claimable) + toNumber(finance.pendingAmount);
        var safetyBlockers = toNumber(safety.urgent) + toNumber(safety.warning || safety.unresolved);
        var rentalRisk = toNumber(rental.overdue) + toNumber(rental.returningSoon);

        var decisions = [];
        function addDecision(level, type, title, detail, why, nextAction, view, icon) {
          decisions.push({ level: level, type: type, title: title, detail: detail, why: why, nextAction: nextAction, view: view, icon: icon });
        }
        if (safetyBlockers > 0 || openAlerts.length > 0) {
          addDecision('critical', 'Safety', 'ì•ˆì „/ì»´í”Œë¼ì´ì–¸ìŠ¤ ë¨¼ì € í™•ì¸', 'ë¯¸ì²˜ë¦¬ ì•Œë¦¼ ' + openAlerts.length + 'ê±´, ì•ˆì „ ë¸”ë¡œì»¤ ' + safetyBlockers + 'ê±´ì´ ìžˆìŠµë‹ˆë‹¤.', 'ë¯¸êµ­ í˜„ìž¥ì€ ìž‘ì—… ì „ PTW, êµìœ¡, ë³´í—˜/ë¼ì´ì„ ìŠ¤ ëˆ„ë½ì´ ë°”ë¡œ ë¦¬ìŠ¤í¬ê°€ ë©ë‹ˆë‹¤.', 'ì•ˆì „ ì´ìŠˆ ë³´ê¸°', 'safety', 'ph-shield-warning');
        }
        if (revenueAtRisk > 0) {
          addDecision('warning', 'Billing', 'ë¯¸ì²­êµ¬/ìŠ¹ì¸ëŒ€ê¸° ë¹„ìš© íšŒìˆ˜', 'ì²­êµ¬ ê°€ëŠ¥ ë˜ëŠ” ìŠ¹ì¸ ëŒ€ê¸° ê¸ˆì•¡ì´ ' + fmtUSD(revenueAtRisk) + 'ìž…ë‹ˆë‹¤.', 'Change Orderì™€ reimbursable costê°€ ëŠ¦ì–´ì§€ë©´ ê³µì§œ ì¼ì´ ë©ë‹ˆë‹¤.', 'ìž¬ë¬´ í™•ì¸', 'finance', 'ph-file-invoice');
        }
        if (absent > 0) {
          addDecision('warning', 'Crew', 'ì˜¤ëŠ˜ ì¸ë ¥ ê³µë°± í™•ì¸', 'í˜„ìž¬ ë¯¸ì¶œê·¼ ë˜ëŠ” ë¯¸í™•ì¸ ì¸ì› ' + absent + 'ëª…ì´ ê°ì§€ë˜ì—ˆìŠµë‹ˆë‹¤.', 'ìž‘ì—… ì „ crew gapì„ ìž¡ì•„ì•¼ ì¼ì • ì§€ì—°ê³¼ OT ì¦ê°€ë¥¼ ë§‰ì„ ìˆ˜ ìžˆìŠµë‹ˆë‹¤.', 'ì¶œí‡´ê·¼ ë³´ê¸°', 'hr', 'ph-users-three');
        }
        if (rentalRisk > 0) {
          addDecision('warning', 'Equipment', 'ë Œíƒˆ ìž¥ë¹„ ë°˜ë‚©/ì—°ìž¥ ê²°ì •', 'ì—°ì²´ ë˜ëŠ” ë°˜ë‚© ìž„ë°• ìž¥ë¹„ ' + rentalRisk + 'ê±´ì´ ìžˆìŠµë‹ˆë‹¤.', 'ìž¥ë¹„ ë Œíƒˆë¹„ëŠ” í•˜ë£¨ ë‹¨ìœ„ë¡œ ìƒˆê¸° ë•Œë¬¸ì— ë‹¹ì¼ ê²°ì •ì´ ì¤‘ìš”í•©ë‹ˆë‹¤.', 'ë Œíƒˆ í™•ì¸', 'rental', 'ph-truck');
        }
        if (!decisions.length) {
          addDecision('ok', 'Closeout', 'ì˜¤ëŠ˜ ë§ˆê° ë³´ê³  ì¤€ë¹„', 'í° ìœ„í—˜ ì‹ í˜¸ëŠ” ì—†ìŠµë‹ˆë‹¤. Daily Log, ì‚¬ì§„, ìž‘ì—…ëŸ‰, ì„œëª…ë§Œ ë‹«ìœ¼ë©´ ë©ë‹ˆë‹¤.', 'ì •ìƒì¼ìˆ˜ë¡ ê¸°ë¡ì„ ê¹”ë”ížˆ ë‚¨ê²¨ì•¼ ì¶”í›„ ì²­êµ¬ì™€ ë¶„ìŸì— ê°•í•©ë‹ˆë‹¤.', 'WBS í™•ì¸', 'wbs', 'ph-clipboard-text');
        }

        var projectRows = (projects || []).map(function(p) {
          var progress = toNumber(p.progress);
          var left = daysUntil(p.endDate);
          var risk = 'ok';
          var signal = 'ì •ìƒ ì§„í–‰';
          var next = 'ì¼ì¼ ê³µì • ì‚¬ì§„ê³¼ ìž‘ì—…ëŸ‰ë§Œ ì—…ë°ì´íŠ¸';
          if ((left != null && left < 0 && progress < 100) || progress < 35) {
            risk = 'critical';
            signal = left != null && left < 0 ? 'ì™„ë£Œì¼ ì´ˆê³¼' : 'ê³µì •ë¥  ë‚®ìŒ';
            next = 'PMì´ ì¼ì • íšŒë³µì•ˆê³¼ ì¶”ê°€ ì¸ë ¥/ìž¥ë¹„ í•„ìš” ì—¬ë¶€ ê²°ì •';
          } else if ((left != null && left <= 14 && progress < 85) || progress < 65) {
            risk = 'warning';
            signal = left != null && left <= 14 ? '2ì£¼ ë‚´ ë§ˆê° ì••ë°•' : 'ì¶”ì  í•„ìš”';
            next = 'ì´ë²ˆ ì£¼ critical pathì™€ ìžìž¬ ë‚©ê¸° ìž¬í™•ì¸';
          }
          return Object.assign({}, p, { risk: risk, signal: signal, nextAction: next, daysLeft: left });
        });

        return {
          success: true,
          siteId: window.currentSiteId || 'ALL',
          generatedAt: new Date().toLocaleString('ko-KR'),
          health: {
            decisionQueue: decisions.length,
            revenueAtRisk: revenueAtRisk,
            safetyBlockers: safetyBlockers,
            scheduleRisk: projectRows.filter(function(p) { return p.risk !== 'ok'; }).length
          },
          decisions: decisions.slice(0, 6),
          projects: projectRows.slice(0, 6),
          billing: [
            { label: 'ì²­êµ¬ ê°€ëŠ¥ ë¹„ìš©', amount: toNumber(finance.claimable), status: 'ë¯¸ì²­êµ¬', action: 'ì˜ìˆ˜ì¦/PO ê·¼ê±° í™•ì¸' },
            { label: 'ìŠ¹ì¸ ëŒ€ê¸° ë¹„ìš©', amount: toNumber(finance.pendingAmount), status: 'ìŠ¹ì¸ëŒ€ê¸°', action: 'PM ìŠ¹ì¸ ìš”ì²­' },
            { label: 'ë Œíƒˆ ì›”ëˆ„ì ', amount: toNumber(rental.mtdCost), status: rentalRisk > 0 ? 'ì£¼ì˜' : 'ì •ìƒ', action: 'ì—°ìž¥/ë°˜ë‚© ê²°ì •' }
          ],
          brief: [
            currentSiteLabel() + ' ê¸°ì¤€ìœ¼ë¡œ ì¸ë ¥, ì•ˆì „, ë¹„ìš©, ìž¥ë¹„ ì‹ í˜¸ë¥¼ í•œ í™”ë©´ì— ëª¨ì•˜ìŠµë‹ˆë‹¤.',
            'í˜„ìž¥ ë©”ëª¨ëŠ” ì•„ëž˜ 1ë¶„ ìž…ë ¥ì°½ì—ì„œ Change Order, RFI, Daily Log ì´ˆì•ˆìœ¼ë¡œ ë°”ë¡œ ì •ë¦¬í•  ìˆ˜ ìžˆìŠµë‹ˆë‹¤.',
            'ì¤‘ìš” ì‹¤í–‰ì€ ìžë™ ì²˜ë¦¬í•˜ì§€ ì•Šê³  ì¶”ì²œ, ê·¼ê±°, ë‹´ë‹¹ìž í™•ì¸ íë¦„ìœ¼ë¡œ ë‚¨ê¹ë‹ˆë‹¤.'
          ]
        };
      }

      function renderDecisionCard(d) {
        var level = levelClass(d.level);
        return '<div class="decision-card">' +
          '<div class="decision-icon"><i class="ph ' + (d.icon || 'ph-lightning') + '"></i></div>' +
          '<div>' +
          '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:3px">' +
          commandChip(d.type || 'Decision', level) +
          '<div class="decision-title">' + safeHtml(d.title) + '</div></div>' +
          '<div class="decision-detail">' + safeHtml(d.detail) + '</div>' +
          '<div class="decision-why">' + safeHtml(d.why || '') + '</div>' +
          '</div>' +
          '<button class="btn-secondary" onclick="window.goToView(\'' + safeHtml(d.view || 'alerts') + '\')"><i class="ph ph-arrow-right"></i>' + safeHtml(d.nextAction || 'ì—´ê¸°') + '</button>' +
          '</div>';
      }

      function renderProjectRiskRow(p) {
        return '<tr>' +
          '<td class="cell-mono">' + safeHtml(p.code || '-') + '</td>' +
          '<td class="cell-primary">' + safeHtml(p.name || '-') + '<div style="color:var(--text-tertiary);font-size:11px;margin-top:2px">PM: ' + safeHtml(p.manager || '-') + '</div></td>' +
          '<td>' + commandChip(p.signal || 'ì •ìƒ', p.risk) + '</td>' +
          '<td><div class="progress-wrapper"><div class="progress-bar"><div class="progress-fill" style="width:' + Math.min(100, toNumber(p.progress)) + '%;background:' + (p.color || '#2563eb') + '"></div></div><div class="progress-text">' + toNumber(p.progress) + '%</div></div></td>' +
          '<td class="cell-primary">' + safeHtml(p.nextAction || '-') + '</td>' +
          '</tr>';
      }

      async function renderCommandCenter() {
        pageContainer.innerHTML = skeleton();
        try {
          var snapshot = null;
          if (window.API.getCommandCenter) snapshot = await window.API.getCommandCenter(window.currentSiteId || 'ALL');
          if (!snapshot || !snapshot.success) snapshot = await buildCommandCenterSnapshot();

          var health = snapshot.health || {};
          var decisions = snapshot.decisions || [];
          var projects = snapshot.projects || [];
          var billing = snapshot.billing || [];
          var brief = snapshot.brief || [];

          var projectOptions = projects.map(function(p) {
            return '<option value="' + safeHtml(p.code || p.name) + '">' + safeHtml((p.code || '-') + ' - ' + (p.name || 'Project')) + '</option>';
          }).join('');
          if (!projectOptions) projectOptions = '<option value="' + safeHtml(window.currentSiteId || 'ALL') + '">' + safeHtml(currentSiteLabel()) + '</option>';

          pageContainer.innerHTML =
            '<div class="header-section"><div>' +
            '<h1 class="page-title">AI í˜„ìž¥ ì§€íœ˜ì‹¤</h1>' +
            '<p class="page-subtitle">' + safeHtml(currentSiteLabel()) + ' Â· ì˜¤ëŠ˜ ê²°ì •í•  ì¼, ê³µì§œ ìž‘ì—… ë°©ì§€, í˜„ìž¥ ë©”ëª¨ ì´ˆì•ˆí™” Â· ' + safeHtml(snapshot.generatedAt || '') + '</p>' +
            '</div><div class="action-row">' +
            '<button class="btn-secondary" onclick="openUniversalScanner()"><i class="ph ph-scan"></i> ë¬¸ì„œ ìŠ¤ìº”</button>' +
            '<button class="btn-primary" onclick="var el=document.getElementById(\'command-capture-text\');if(el)el.focus()"><i class="ph ph-magic-wand"></i> 1ë¶„ í˜„ìž¥ ìž…ë ¥</button>' +
            '</div></div>' +
            '<div class="command-radar">' +
            metricCard('ê²°ì • ëŒ€ê¸°', String(health.decisionQueue || decisions.length || 0), 'ì˜¤ëŠ˜ PM/Ownerê°€ í™•ì¸í•  í•­ëª©', 'ph-list-checks', health.decisionQueue > 3 ? 'warning' : '') +
            metricCard('íšŒìˆ˜ í•„ìš” ê¸ˆì•¡', fmtUSD(health.revenueAtRisk || 0), 'ë¯¸ì²­êµ¬, ìŠ¹ì¸ëŒ€ê¸°, CO í›„ë³´', 'ph-currency-dollar', health.revenueAtRisk > 0 ? 'warning' : '') +
            metricCard('ì•ˆì „ ë¸”ë¡œì»¤', String(health.safetyBlockers || 0), 'ìž‘ì—… ì „ í•´ê²° í•„ìš” ì‹ í˜¸', 'ph-shield-warning', health.safetyBlockers > 0 ? 'critical' : '') +
            metricCard('ì¼ì • ìœ„í—˜ Job', String(health.scheduleRisk || 0), 'ë§ˆê°/ê³µì •ë¥  ê¸°ì¤€ ìœ„í—˜ í˜„ìž¥', 'ph-calendar-warning', health.scheduleRisk > 0 ? 'warning' : '') +
            '</div>' +
            '<div class="command-grid">' +
            '<div>' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-lightning"></i> ì˜¤ëŠ˜ì˜ ê²°ì • í</div><span class="command-chip warning">Recommendation</span></div>' +
            '<div class="panel-body">' + decisions.map(renderDecisionCard).join('') + '</div></div>' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-radar"></i> Job Risk Radar</div><button class="btn-secondary" onclick="window.goToView(\'wbs\')"><i class="ph ph-tree-structure"></i> WBS</button></div>' +
            '<div class="panel-body"><table class="data-table"><thead><tr><th>Job</th><th>Project</th><th>Signal</th><th>Progress</th><th>Next Action</th></tr></thead><tbody>' +
            (projects.length ? projects.map(renderProjectRiskRow).join('') : '<tr><td colspan="5" style="text-align:center;color:var(--text-tertiary);padding:24px">í”„ë¡œì íŠ¸ ë°ì´í„°ê°€ ì—†ìŠµë‹ˆë‹¤.</td></tr>') +
            '</tbody></table></div></div>' +
            '</div>' +
            '<div class="command-stack">' +
            '<div class="panel quick-capture"><div class="panel-header"><div class="panel-title"><i class="ph ph-note-pencil"></i> 1ë¶„ í˜„ìž¥ ìž…ë ¥</div></div>' +
            '<div class="panel-body padded">' +
            '<select id="command-capture-project" class="context-switcher" style="width:100%;margin-bottom:10px">' + projectOptions + '</select>' +
            '<textarea id="command-capture-text" placeholder="ì˜ˆ: Owner asked to add 12 recessed lights in living room. Material needed by Friday. Photos attached."></textarea>' +
            '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px">' +
            '<button class="btn-secondary" onclick="document.getElementById(\'command-capture-text\').value=\'\'"><i class="ph ph-eraser"></i> ì§€ìš°ê¸°</button>' +
            '<button class="btn-primary" onclick="window.generateCommandDraft()"><i class="ph ph-sparkle"></i> ì´ˆì•ˆ ë§Œë“¤ê¸°</button>' +
            '</div><div id="command-draft-output"></div></div></div>' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-file-invoice"></i> ë¯¸ì²­êµ¬ ë°©ì§€ ì²´í¬</div></div>' +
            '<div class="panel-body"><table class="data-table"><tbody>' + billing.map(function(b) {
              return '<tr><td class="cell-primary">' + safeHtml(b.label) + '</td><td class="cell-mono" style="text-align:right">' + fmtUSD(b.amount || 0) + '</td><td>' + statusPill(b.status || 'ì •ìƒ') + '</td><td>' + safeHtml(b.action || '-') + '</td></tr>';
            }).join('') + '</tbody></table></div></div>' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-brain"></i> AI ìš´ì˜ ë¸Œë¦¬í•‘</div></div>' +
            '<div class="panel-body padded"><div class="brief-list">' + brief.map(function(line) {
              return '<div class="brief-row"><i class="ph ph-check-circle"></i><div>' + safeHtml(line) + '</div></div>';
            }).join('') + '</div></div></div>' +
            '</div></div>';
        } catch (err) { renderError('AI í˜„ìž¥ ì§€íœ˜ì‹¤ ë¡œë”© ì‹¤íŒ¨: ' + err.message); console.error(err); }
      }

      window.generateCommandDraft = function() {
        var input = document.getElementById('command-capture-text');
        var out = document.getElementById('command-draft-output');
        var project = document.getElementById('command-capture-project');
        if (!input || !out) return;
        var text = input.value.trim();
        if (!text) {
          out.innerHTML = '<div class="ai-draft-list"><div class="ai-draft-item"><strong>ìž…ë ¥ì´ í•„ìš”í•©ë‹ˆë‹¤</strong><span>í˜„ìž¥ ë©”ëª¨, ê³ ê° ìš”ì²­, ì§€ì—° ì‚¬ìœ , ì‚¬ì§„ ì„¤ëª… ì¤‘ í•˜ë‚˜ë§Œ ì ì–´ë„ ì´ˆì•ˆì„ ë§Œë“¤ ìˆ˜ ìžˆìŠµë‹ˆë‹¤.</span></div></div>';
          return;
        }
        var lower = text.toLowerCase();
        var kind = 'Daily Log';
        var next = 'ì˜¤ëŠ˜ ìž‘ì—…ëŸ‰, íˆ¬ìž… ì¸ì›, ì‚¬ì§„, ë¯¸ì™„ë£Œ í•­ëª©ìœ¼ë¡œ Daily Reportì— ë¶™ìž…ë‹ˆë‹¤.';
        var checklist = ['ìž‘ì—… ìœ„ì¹˜ì™€ ìˆ˜ëŸ‰ í™•ì¸', 'í˜„ìž¥ ì‚¬ì§„/ë‹´ë‹¹ìž ì—°ê²°', 'íŒ€ìž¥ ì„œëª… ë˜ëŠ” PM í™•ì¸'];
        if (/change|extra|add|added|owner|client|customer|ì¶”ê°€|ë³€ê²½|ê³ ê°/.test(lower)) {
          kind = 'Change Order';
          next = 'ì¶”ê°€ ìž‘ì—… ë²”ìœ„, ìžìž¬, ì¸ê±´ë¹„, ì¼ì • ì˜í–¥ì„ ì •ë¦¬í•˜ê³  ìŠ¹ì¸ ì „ ìž‘ì—… ê²½ê³ ë¥¼ ë„ì›ë‹ˆë‹¤.';
          checklist = ['ì› ìš”ì²­ìžì™€ ìŠ¹ì¸ê¶Œìž í™•ì¸', 'ìžìž¬/ì¸ê±´ë¹„/ìž¥ë¹„ ë¹„ìš© ì‚°ì¶œ', 'ìŠ¹ì¸ ì „ ì„ ìž‘ì—… ì—¬ë¶€ í‘œì‹œ'];
        } else if (/rfi|question|clarif|drawing|spec|ë„ë©´|ì§ˆë¬¸|í™•ì¸/.test(lower)) {
          kind = 'RFI';
          next = 'ë„ë©´/ìŠ¤íŽ™ ë¶ˆëª…í™• í•­ëª©ì„ ì§ˆë¬¸ í˜•ì‹ìœ¼ë¡œ ë°”ê¾¸ê³ , ë‹µë³€ ì „ hold ì§€ì ì„ í‘œì‹œí•©ë‹ˆë‹¤.';
          checklist = ['ê´€ë ¨ ë„ë©´ ë²ˆí˜¸ ìž…ë ¥', 'ê²°ì • í•„ìš”í•œ ë‚ ì§œ ì§€ì •', 'ìž‘ì—… ì˜í–¥ ë²”ìœ„ ì„ íƒ'];
        } else if (/delay|late|blocked|ë‚©ê¸°|ì§€ì—°|ë§‰íž˜|ëŒ€ê¸°/.test(lower)) {
          kind = 'Delay Notice';
          next = 'ì§€ì—° ì›ì¸, ì±…ìž„ ì£¼ì²´, schedule impact, íšŒë³µì•ˆì„ PMì—ê²Œ ë³´ëƒ…ë‹ˆë‹¤.';
          checklist = ['ì§€ì—° ì‹œìž‘ì¼ ê¸°ë¡', 'critical path ì˜í–¥ í™•ì¸', 'ëŒ€ì²´ ìž‘ì—… ë˜ëŠ” ì—°ìž¥ ìš”ì²­'];
        } else if (/safe|incident|osha|hazard|ì•ˆì „|ì‚¬ê³ |ìœ„í—˜/.test(lower)) {
          kind = 'Safety Issue';
          next = 'ìž‘ì—… ì¤‘ì§€ í•„ìš” ì—¬ë¶€, OSHA/í˜„ìž¥ ê·œì •, corrective actionì„ ì•ˆì „ ëª¨ë“ˆë¡œ ë„˜ê¹ë‹ˆë‹¤.';
          checklist = ['ìœ„í—˜ êµ¬ì—­ ê²©ë¦¬', 'ì‚¬ì§„ê³¼ ë°œê²¬ìž ê¸°ë¡', 'ì‹œì •ì¡°ì¹˜ ë‹´ë‹¹ìž ì§€ì •'];
        }
        out.innerHTML = '<div class="ai-draft-list">' +
          '<div class="ai-draft-item"><strong>' + safeHtml(kind) + ' ì´ˆì•ˆ</strong><span>Project: ' + safeHtml(project ? project.value : currentSiteLabel()) + '<br>' + safeHtml(next) + '</span></div>' +
          '<div class="ai-draft-item"><strong>ìš”ì•½</strong><span>' + safeHtml(text.length > 220 ? text.substring(0, 220) + '...' : text) + '</span></div>' +
          '<div class="ai-draft-item"><strong>ë‹¤ìŒ í™•ì¸</strong><span>' + checklist.map(safeHtml).join(' Â· ') + '</span></div>' +
          '</div>';
      };

      // â”€â”€ DASHBOARD â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      async function renderDashboard() {
        pageContainer.innerHTML = skeleton();
        try {
          var [kpis, actionItems, projects] = await Promise.all([
            window.API.getKPIs(),
            window.API.getActionItems(),
            window.API.getProjectStatus()
          ]);

          var kpisHtml = kpis.map(function (k) {
            var trendIcon = k.trendType === 'up' ? 'ph-arrow-up-right' : k.trendType === 'down' ? 'ph-arrow-down-right' : 'ph-arrows-left-right';
            return '<div class="kpi-card">' +
              '<div class="kpi-label">' + k.label + '<i class="ph ' + k.icon + '" style="font-size:15px;color:var(--text-tertiary)"></i></div>' +
              '<div class="kpi-value">' + k.value + '<span style="font-size:11px;color:var(--text-tertiary);font-weight:500"> ' + k.unit + '</span></div>' +
              '<div class="kpi-meta"><span class="trend-' + k.trendType + '"><i class="ph ' + trendIcon + '"></i></span><span style="color:var(--text-secondary)">' + k.trend + '</span></div>' +
              '</div>';
          }).join('');

          var actionItemsHtml = actionItems.map(function (a) {
            var statusText = a.status === 'critical' ? 'ê¸´ê¸‰' : a.status === 'warning' ? 'ì£¼ì˜' : a.status === 'pending' ? 'ë¯¸ì²˜ë¦¬' : 'ì™„ë£Œ';
            return '<tr><td class="cell-mono">' + a.id + '</td><td>' + a.type + '</td><td class="cell-primary">' + a.summary + '</td><td>' + a.assignee + '</td><td>' + statusPill(statusText) + '</td><td class="cell-mono" style="text-align:right">' + a.date + '</td></tr>';
          }).join('');

          var projectsHtml = projects.map(function (p) {
            return '<div><div style="display:flex;justify-content:space-between;margin-bottom:6px">' +
              '<div><div style="font-weight:600;font-size:13px;color:var(--text-primary)">' + p.name + '</div>' +
              '<div class="cell-mono" style="color:var(--text-tertiary);margin-top:2px">' + p.code + ' | PM: ' + p.manager + '</div></div></div>' +
              '<div class="progress-wrapper"><div class="progress-bar"><div class="progress-fill" style="width:' + p.progress + '%;background:' + p.color + '"></div></div>' +
              '<div class="progress-text cell-primary">' + p.progress + '%</div></div>' +
              '<div style="font-size:11px;color:var(--text-tertiary);margin-top:5px;text-align:right">ì™„ë£Œ ì˜ˆì •: ' + p.endDate + '</div></div>';
          }).join('');

          pageContainer.innerHTML =
            '<div class="header-section"><div>' +
            '<h1 class="page-title">Executive Dashboard</h1>' +
            '<p class="page-subtitle">NAHSHON MEP Â· ì‹¤ì‹œê°„ í˜„ìž¥ ìš´ì˜ í˜„í™© Â· ' + new Date().toLocaleDateString('ko-KR') + '</p>' +
            '</div><div class="action-row">' +
            '<button class="btn-secondary" onclick="window.goToView(\'command\')"><i class="ph ph-command"></i> AI í˜„ìž¥ ì§€íœ˜ì‹¤</button>' +
            '<button class="btn-primary" onclick="openQuickActions()"><i class="ph ph-lightning"></i> í€µ ì•¡ì…˜ ì„¼í„°</button>' +
            '</div></div>' +
            '<div class="kpi-row">' + kpisHtml + '</div>' +
            '<div class="dashboard-grid-main">' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-warning-circle"></i> ê¸´ê¸‰ ì²˜ë¦¬ í•„ìš”</div></div>' +
            '<div class="panel-body"><table class="data-table"><thead><tr>' +
            '<th style="width:100px">ID</th><th style="width:70px">êµ¬ë¶„</th><th>ë‚´ìš©</th><th style="width:90px">ë‹´ë‹¹</th><th style="width:80px">ìƒíƒœ</th><th style="width:90px;text-align:right">ë‚ ì§œ</th>' +
            '</tr></thead><tbody>' + actionItemsHtml + '</tbody></table></div></div>' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-kanban"></i> í”„ë¡œì íŠ¸ í˜„í™©</div></div>' +
            '<div class="panel-body padded" style="display:flex;flex-direction:column;gap:20px">' + projectsHtml + '</div></div>' +
            '</div>';
        } catch (err) { renderError('ëŒ€ì‹œë³´ë“œ ë¡œë”© ì‹¤íŒ¨'); console.error(err); }
      }

      // â”€â”€ ANALYTICS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      async function renderAnalytics() {
        pageContainer.innerHTML = skeleton();
        try {
          // Promise.allSettled: í•˜ë‚˜ê°€ ì‹¤íŒ¨í•´ë„ ë‚˜ë¨¸ì§€ëŠ” ì •ìƒ í‘œì‹œ
          var results = await Promise.allSettled([
            window.API.getFinanceStats(),
            window.API.getEquipmentStats(),
            window.API.getPersonnelStats(),
            window.API.getSafetyStats()
          ]);

          // ì‹¤íŒ¨ ì‹œ ê¸°ë³¸ê°’ìœ¼ë¡œ í´ë°±
          var finStats = results[0].status === 'fulfilled' ? results[0].value : {
            mtdTotal: 0, mtdBudget: 1, pendingAmount: 0,
            byCategory: [{ name: 'ë°ì´í„° ì—†ìŒ', amount: 0, color: 'var(--text-tertiary)' }]
          };
          var equipStats = results[1].status === 'fulfilled' ? results[1].value : {
            total: 0, operable: 0, underMaintenance: 0, notInspected: 0
          };
          var personnelStats = results[2].status === 'fulfilled' ? results[2].value : {
            total: 0, active: 0, visaExpiringSoon: 0, safetyExpiring: 0,
            byCompany: []
          };
          var safetyStats = results[3].status === 'fulfilled' ? results[3].value : {
            daysNoIncident: 0, unresolved: 0, resolved: 0, urgent: 0, warning: 0, normal: 0
          };

          // ì‹¤íŒ¨í•œ íŒ¨ë„ ì¶”ì  (ê²½ê³  í‘œì‹œìš©)
          var failedPanels = [];
          if (results[0].status === 'rejected') failedPanels.push('ìž¬ë¬´');
          if (results[1].status === 'rejected') failedPanels.push('ìž¥ë¹„');
          if (results[2].status === 'rejected') failedPanels.push('ì¸ì›');
          if (results[3].status === 'rejected') failedPanels.push('ì•ˆì „');

          var equipRate = equipStats.total > 0 ? Math.round(equipStats.operable / equipStats.total * 100) : 0;
          var budgetPct = finStats.mtdBudget > 0 ? Math.round(finStats.mtdTotal / finStats.mtdBudget * 100) : 0;
          var equipRateColor = equipRate >= 80 ? 'var(--status-success)' : equipRate >= 60 ? 'var(--status-warning)' : 'var(--status-danger)';

          var personnelBarchartHtml = (personnelStats.byCompany || []).length > 0
            ? personnelStats.byCompany.map(function (c) {
              var pct = personnelStats.total > 0 ? Math.round(c.count / personnelStats.total * 100) : 0;
              return '<div style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px"><span>' + c.name + '</span><span class="cell-mono">' + c.count + 'ëª…</span></div>' +
                '<div class="progress-bar"><div class="progress-fill" style="width:' + pct + '%;background:var(--brand-primary)"></div></div></div>';
            }).join('')
            : '<div style="color:var(--text-tertiary);font-size:12px;padding:8px 0">ì¸ì› ë°ì´í„°ë¥¼ ë¶ˆëŸ¬ì˜¤ëŠ” ì¤‘...</div>';

          var finBarchartHtml = (finStats.byCategory || []).map(function (c) {
            var pct = finStats.mtdTotal > 0 ? Math.round(c.amount / finStats.mtdTotal * 100) : 0;
            return '<div style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px"><span>' + c.name + '</span><span class="cell-mono">' + fmtUSD(c.amount) + '</span></div>' +
              '<div class="progress-bar"><div class="progress-fill" style="width:' + pct + '%;background:' + (c.color || 'var(--brand-primary)') + '"></div></div></div>';
          }).join('') || '<div style="color:var(--text-tertiary);font-size:12px;padding:8px 0">ë¹„ìš© ë°ì´í„°ë¥¼ ë¶ˆëŸ¬ì˜¤ëŠ” ì¤‘...</div>';

          var warnBanner = failedPanels.length > 0
            ? '<div style="background:rgba(255,165,0,0.1);border:1px solid rgba(255,165,0,0.3);border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:12px;color:var(--status-warning)"><i class="ph ph-warning"></i> <b>' + failedPanels.join(', ') + '</b> ë°ì´í„° ì¡°íšŒ ì¤‘ ì˜¤ë¥˜ê°€ ë°œìƒí•˜ì—¬ ê¸°ë³¸ê°’ìœ¼ë¡œ í‘œì‹œë©ë‹ˆë‹¤.</div>'
            : '';

          pageContainer.innerHTML =
            '<div class="header-section"><div><h1 class="page-title">í†µí•© ë¶„ì„ ë°ì´í„°</h1><p class="page-subtitle">ëª¨ë“  ëª¨ë“ˆì˜ KPI ìš”ì•½ Â· ' + new Date().toLocaleDateString('ko-KR') + ' ê¸°ì¤€</p></div></div>' +
            warnBanner +
            '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:24px;margin-bottom:24px">' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-users"></i> ì¸ì› í˜„í™©</div></div>' +
            '<div class="panel-body padded"><div class="analytics-stat-row">' +
            '<div class="analytics-stat"><div class="as-label">ì´ í˜„ìž¥ì¸ì›</div><div class="as-value">' + personnelStats.total + 'ëª…</div></div>' +
            '<div class="analytics-stat"><div class="as-label">í˜„ì§€ì¸ ì¶œì„</div><div class="as-value" style="color:var(--status-success)">' + personnelStats.active + '</div></div>' +
            '<div class="analytics-stat"><div class="as-label">í•œêµ­ ìž‘ì—…ìž</div><div class="as-value" style="color:var(--brand-primary)">' + personnelStats.visaExpiringSoon + '</div></div>' +
            '</div><div style="margin-top:14px">' + personnelBarchartHtml + '</div></div></div>' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-chart-bar"></i> ìž¬ë¬´ í˜„í™© (MTD)</div></div>' +
            '<div class="panel-body padded"><div class="analytics-stat-row">' +
            '<div class="analytics-stat"><div class="as-label">ì´ ì§€ì¶œ</div><div class="as-value">' + fmtUSD(finStats.mtdTotal) + '</div></div>' +
            '<div class="analytics-stat"><div class="as-label">ì˜ˆì‚° ëŒ€ë¹„</div><div class="as-value" style="color:var(--status-success)">' + budgetPct + '%</div></div>' +
            '<div class="analytics-stat"><div class="as-label">ìŠ¹ì¸ ëŒ€ê¸°</div><div class="as-value" style="color:var(--status-warning)">' + fmtUSD(finStats.pendingAmount) + '</div></div>' +
            '</div><div style="margin-top:14px">' + finBarchartHtml + '</div></div></div>' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-gauge"></i> ìž¥ë¹„ ê°€ë™ë¥ </div></div>' +
            '<div class="panel-body padded"><div style="text-align:center;padding:20px 0">' +
            '<div style="font-size:56px;font-weight:700;font-family:var(--font-mono);color:' + equipRateColor + '">' + equipRate + '%</div>' +
            '<div style="font-size:12px;color:var(--text-tertiary);margin-top:4px">ì „ì²´ ' + equipStats.total + 'ëŒ€ ì¤‘ ' + equipStats.operable + 'ëŒ€ ìš´í–‰ê°€ëŠ¥</div>' +
            '<div class="progress-bar" style="margin-top:16px;height:10px;border-radius:5px"><div class="progress-fill" style="width:' + equipRate + '%;background:' + equipRateColor + ';border-radius:5px"></div></div>' +
            '</div></div></div>' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-shield-check"></i> ì•ˆì „ í˜„í™©</div></div>' +
            '<div class="panel-body padded"><div class="analytics-stat-row">' +
            '<div class="analytics-stat"><div class="as-label">ë¬´ì‚¬ê³  ì¼ìˆ˜</div><div class="as-value" style="color:var(--status-success)">' + safetyStats.daysNoIncident + 'ì¼</div></div>' +
            '<div class="analytics-stat"><div class="as-label">ë¯¸ì²˜ë¦¬ ì•Œë¦¼</div><div class="as-value" style="color:var(--status-danger)">' + safetyStats.unresolved + '</div></div>' +
            '<div class="analytics-stat"><div class="as-label">ì²˜ë¦¬ì™„ë£Œ</div><div class="as-value" style="color:var(--status-success)">' + safetyStats.resolved + '</div></div>' +
            '</div><div style="margin-top:16px;display:flex;gap:12px">' +
            '<div class="alert-summary-pill urgent"><i class="ph ph-warning-octagon"></i> ê¸´ê¸‰ ' + safetyStats.urgent + 'ê±´</div>' +
            '<div class="alert-summary-pill warning"><i class="ph ph-warning"></i> ì£¼ì˜ ' + safetyStats.warning + 'ê±´</div>' +
            '<div class="alert-summary-pill normal"><i class="ph ph-info"></i> ì¼ë°˜ ' + safetyStats.normal + 'ê±´</div>' +
            '</div></div></div>' +
            '</div>';
        } catch (err) {
          renderError('ë¶„ì„ ë°ì´í„° ë¡œë”© ì‹¤íŒ¨ â€” ' + err.message);
          console.error('[renderAnalytics]', err);
        }
      }


      // â”€â”€ ALERTS (í†µí•© ì•Œë¦¼ ì„¼í„°) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      async function renderAlerts() {
        pageContainer.innerHTML = skeleton();
        try {
          var alerts = await window.API.getAlerts('all');

          // â”€â”€ module meta
          var modMeta = {
            SAFETY: { label:'ðŸ›¡ï¸ ì•ˆì „ê´€ë¦¬', color:'#ef4444', bg:'rgba(239,68,68,.1)' },
            VEH:    { label:'ðŸš— ì°¨ëŸ‰ê´€ë¦¬', color:'#3b82f6', bg:'rgba(59,130,246,.1)' },
            HSG:    { label:'ðŸ  ìˆ™ì†Œê´€ë¦¬', color:'#22c55e', bg:'rgba(34,197,94,.1)' },
            FLT:    { label:'âœˆï¸ í•­ê³µ/ë¹„ìž', color:'#8b5cf6', bg:'rgba(139,92,246,.1)' },
            PUR:    { label:'ðŸ›’ êµ¬ë§¤ê´€ë¦¬', color:'#f59e0b', bg:'rgba(245,158,11,.1)' },
            HR:     { label:'ðŸ‘· ì¸ì‚¬ê´€ë¦¬', color:'#64748b', bg:'rgba(100,116,139,.1)' }
          };
          var sevMeta = {
            'ê¸´ê¸‰': { icon:'ðŸ”´', color:'var(--status-danger)',  bg:'rgba(239,68,68,.08)',  border:'rgba(239,68,68,.25)' },
            'ì£¼ì˜': { icon:'ðŸŸ ', color:'var(--status-warning)', bg:'rgba(245,158,11,.06)', border:'rgba(245,158,11,.2)' },
            'ì¼ë°˜': { icon:'ðŸ”µ', color:'#3b82f6',               bg:'rgba(59,130,246,.05)', border:'rgba(59,130,246,.15)' }
          };
          var statMeta = {
            'ë¯¸ì²˜ë¦¬': { color:'var(--status-danger)',  bg:'rgba(239,68,68,.1)' },
            'ì²˜ë¦¬ì¤‘': { color:'var(--status-warning)', bg:'rgba(245,158,11,.1)' },
            'ì™„ë£Œ':   { color:'var(--status-success)', bg:'rgba(16,185,129,.1)' },
            'ë¬´ì‹œ':   { color:'var(--text-tertiary)',   bg:'rgba(100,116,139,.1)' }
          };

          // â”€â”€ KPIs
          var urgent    = alerts.filter(function(a){ return a.severity==='ê¸´ê¸‰' && a.status==='ë¯¸ì²˜ë¦¬'; }).length;
          var caution   = alerts.filter(function(a){ return a.severity==='ì£¼ì˜' && a.status==='ë¯¸ì²˜ë¦¬'; }).length;
          var todayNew  = alerts.filter(function(a){ return a.ts && a.ts.startsWith('2026-04-13'); }).length;
          var done      = alerts.filter(function(a){ return a.status==='ì™„ë£Œ'; }).length;
          var total     = alerts.length;

          // update badge
          var badge = document.getElementById('alert-unread-badge');
          if (badge) badge.textContent = urgent + caution;

          // â”€â”€ time formatter
          function elapsed(tsStr) {
            if (!tsStr) return '';
            var d = new Date(tsStr.replace(' ', 'T'));
            var mins = Math.floor((Date.now() - d) / 60000);
            if (mins < 60) return mins + 'ë¶„ ì „';
            if (mins < 1440) return Math.floor(mins/60) + 'ì‹œê°„ ì „';
            return Math.floor(mins/1440) + 'ì¼ ì „';
          }

          // â”€â”€ active filters (state)
          window._alertFilters = window._alertFilters || { severity: 'all', module: 'all', onlyPending: false };

          function getFiltered() {
            return alerts.filter(function(a) {
              if (window._alertFilters.severity !== 'all' && a.severity !== window._alertFilters.severity) return false;
              if (window._alertFilters.module !== 'all' && a.module !== window._alertFilters.module) return false;
              if (window._alertFilters.onlyPending && a.status === 'ì™„ë£Œ') return false;
              return true;
            });
          }

          function renderList() {
            var filtered = getFiltered();
            var listEl = document.getElementById('alert-list');
            if (!listEl) return;
            if (filtered.length === 0) {
              listEl.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-tertiary)"><i class="ph ph-bell-slash" style="font-size:40px;display:block;margin-bottom:10px"></i>í•´ë‹¹ ì¡°ê±´ì˜ ì•Œë¦¼ì´ ì—†ìŠµë‹ˆë‹¤</div>';
              return;
            }
            listEl.innerHTML = filtered.map(function(a) {
              var sev = sevMeta[a.severity] || sevMeta['ì¼ë°˜'];
              var mod = modMeta[a.module] || { label: a.module, color:'#64748b', bg:'rgba(100,116,139,.1)' };
              var st  = statMeta[a.status] || statMeta['ë¯¸ì²˜ë¦¬'];
              var isDone = a.status === 'ì™„ë£Œ' || a.status === 'ë¬´ì‹œ';
              return '<div class="alert-card" id="ac-'+a.id+'" style="border:1px solid '+sev.border+';background:'+sev.bg+';border-radius:10px;padding:14px 16px;margin-bottom:10px;'+(isDone?'opacity:0.55':'')+'">'
                +'<div style="display:flex;align-items:flex-start;gap:12px">'
                +'<div style="font-size:20px;flex-shrink:0;margin-top:1px">'+sev.icon+'</div>'
                +'<div style="flex:1;min-width:0">'
                  // header row
                  +'<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:5px">'
                  +'<span style="font-size:10px;font-weight:700;font-family:monospace;color:var(--text-tertiary)">'+a.id+'</span>'
                  +'<span style="background:'+mod.bg+';color:'+mod.color+';padding:1px 8px;border-radius:8px;font-size:10px;font-weight:700">'+mod.label+'</span>'
                  +'<span style="background:'+st.bg+';color:'+st.color+';padding:1px 8px;border-radius:8px;font-size:10px;font-weight:700">'+a.status+'</span>'
                  +'<span style="margin-left:auto;font-size:10px;color:var(--text-tertiary)">'+elapsed(a.ts)+'</span>'
                  +'</div>'
                  // title
                  +'<div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:4px">'+a.title+'</div>'
                  // content
                  +'<div style="font-size:11px;color:var(--text-secondary);line-height:1.5;margin-bottom:8px">'+a.content+'</div>'
                  // actions
                  +(isDone ? '' :
                    '<div style="display:flex;gap:8px;flex-wrap:wrap">'
                    +(a.relatedId ? '<button onclick="window._alertViewDetail(\'' + a.id + '\')" style="background:var(--bg-subtle);border:1px solid var(--border-color);color:var(--text-secondary);padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer"><i class="ph ph-magnifying-glass" style="margin-right:3px"></i>ìƒì„¸ë³´ê¸°</button>' : '')
                    +'<button onclick="window._alertComplete(\'' + a.id + '\')" style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:var(--status-success);padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer;font-weight:600"><i class="ph ph-check" style="margin-right:3px"></i>ì²˜ë¦¬ì™„ë£Œ</button>'
                    +'<button onclick="window._alertIgnore(\'' + a.id + '\')" style="background:var(--bg-subtle);border:1px solid var(--border-color);color:var(--text-tertiary);padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer"><i class="ph ph-x" style="margin-right:3px"></i>ë¬´ì‹œ</button>'
                    +(a.assignee ? '<span style="font-size:10px;color:var(--text-tertiary);margin-left:auto;align-self:center"><i class="ph ph-user"></i> '+a.assignee+'</span>' : '')
                    +'</div>')
                +'</div>'
                +'</div></div>';
            }).join('');
          }

          // â”€â”€ build page
          var modOptions = Object.keys(modMeta).map(function(k){
            return '<option value="'+k+'">'+modMeta[k].label+'</option>';
          }).join('');

          pageContainer.innerHTML =
            '<div class="header-section"><div>'
            +'<h1 class="page-title">ðŸ”” í†µí•© ì•Œë¦¼ ì„¼í„°</h1>'
            +'<p class="page-subtitle">ëª¨ë“  ëª¨ë“ˆ(ì•ˆì „Â·ì°¨ëŸ‰Â·ìˆ™ì†ŒÂ·í•­ê³µÂ·êµ¬ë§¤Â·ì¸ì‚¬)ì˜ ì´ë²¤íŠ¸ë¥¼ í•œê³³ì—ì„œ í™•ì¸Â·ì²˜ë¦¬í•©ë‹ˆë‹¤</p>'
            +'</div></div>'
            // KPI
            +'<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);margin-bottom:16px">'
            +'<div class="kpi-card" style="border-left:3px solid var(--status-danger)"><div class="kpi-label"><i class="ph ph-warning-octagon" style="color:var(--status-danger)"></i> ë¯¸ì²˜ë¦¬ ê¸´ê¸‰</div><div class="kpi-value" style="color:var(--status-danger)">'+urgent+'</div><div class="kpi-meta">ì¦‰ì‹œ ì¡°ì¹˜ í•„ìš”</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid var(--status-warning)"><div class="kpi-label"><i class="ph ph-warning" style="color:var(--status-warning)"></i> ë¯¸ì²˜ë¦¬ ì£¼ì˜</div><div class="kpi-value" style="color:var(--status-warning)">'+caution+'</div><div class="kpi-meta">3ì¼ ì´ë‚´ ì²˜ë¦¬</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid #3b82f6"><div class="kpi-label"><i class="ph ph-bell" style="color:#3b82f6"></i> ì˜¤ëŠ˜ ì‹ ê·œ</div><div class="kpi-value" style="color:#3b82f6">'+todayNew+'</div><div class="kpi-meta">ì˜¤ëŠ˜ ë°œìƒí•œ ì•Œë¦¼</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid var(--status-success)"><div class="kpi-label"><i class="ph ph-check-circle" style="color:var(--status-success)"></i> ì²˜ë¦¬ ì™„ë£Œ</div><div class="kpi-value" style="color:var(--status-success)">'+done+'</div><div class="kpi-meta">ì „ì²´ ì¤‘ ì™„ë£Œ</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid var(--text-tertiary)"><div class="kpi-label"><i class="ph ph-list-bullets"></i> ì „ì²´ ì•Œë¦¼</div><div class="kpi-value">'+total+'</div><div class="kpi-meta">ì „ì²´ ëˆ„ì  ê±´ìˆ˜</div></div>'
            +'</div>'
            // filter bar
            +'<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;padding:10px 14px;background:var(--bg-elevated);border:1px solid var(--border-color);border-radius:10px">'
            +'<span style="font-size:11px;font-weight:700;color:var(--text-tertiary);white-space:nowrap">ê¸´ê¸‰ë„</span>'
            +'<button class="alert-sev-btn active" data-sev="all" style="padding:4px 12px;border-radius:16px;font-size:11px;font-weight:700;border:1px solid var(--border-color);background:var(--brand-primary);color:#fff;cursor:pointer">ì „ì²´</button>'
            +'<button class="alert-sev-btn" data-sev="ê¸´ê¸‰" style="padding:4px 12px;border-radius:16px;font-size:11px;font-weight:700;border:1px solid rgba(239,68,68,.3);background:rgba(239,68,68,.08);color:var(--status-danger);cursor:pointer">ðŸ”´ ê¸´ê¸‰</button>'
            +'<button class="alert-sev-btn" data-sev="ì£¼ì˜" style="padding:4px 12px;border-radius:16px;font-size:11px;font-weight:700;border:1px solid rgba(245,158,11,.3);background:rgba(245,158,11,.08);color:var(--status-warning);cursor:pointer">ðŸŸ  ì£¼ì˜</button>'
            +'<button class="alert-sev-btn" data-sev="ì¼ë°˜" style="padding:4px 12px;border-radius:16px;font-size:11px;font-weight:700;border:1px solid rgba(59,130,246,.3);background:rgba(59,130,246,.08);color:#3b82f6;cursor:pointer">ðŸ”µ ì¼ë°˜</button>'
            +'<span style="color:var(--border-color);margin:0 4px">|</span>'
            +'<span style="font-size:11px;font-weight:700;color:var(--text-tertiary)">ëª¨ë“ˆ</span>'
            +'<select id="alert-mod-filter" style="background:var(--bg-subtle);border:1px solid var(--border-color);color:var(--text-primary);padding:4px 8px;border-radius:6px;font-size:11px">'
            +'<option value="all">ì „ì²´ ëª¨ë“ˆ</option>'+modOptions
            +'</select>'
            +'<label style="display:flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:var(--text-secondary);cursor:pointer;margin-left:6px">'
            +'<input type="checkbox" id="alert-pending-only" style="width:14px;height:14px"> ë¯¸ì²˜ë¦¬ë§Œ ë³´ê¸°'
            +'</label>'
            +'<button onclick="renderAlerts()" style="margin-left:auto;background:var(--bg-subtle);border:1px solid var(--border-color);color:var(--text-secondary);padding:4px 10px;border-radius:6px;font-size:11px;cursor:pointer"><i class="ph ph-arrows-clockwise"></i> ìƒˆë¡œê³ ì¹¨</button>'
            +'</div>'
            // alert list
            +'<div id="alert-list"></div>';

          // render initial list
          renderList();

          // â”€â”€ filter events
          document.querySelectorAll('.alert-sev-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
              document.querySelectorAll('.alert-sev-btn').forEach(function(b){
                b.style.background = '';
                b.style.color = b.getAttribute('data-sev')==='ê¸´ê¸‰'?'var(--status-danger)':b.getAttribute('data-sev')==='ì£¼ì˜'?'var(--status-warning)':b.getAttribute('data-sev')==='ì¼ë°˜'?'#3b82f6':'var(--text-secondary)';
              });
              this.style.background = 'var(--brand-primary)';
              this.style.color = '#fff';
              window._alertFilters.severity = this.getAttribute('data-sev');
              renderList();
            });
          });
          document.getElementById('alert-mod-filter').addEventListener('change', function(){
            window._alertFilters.module = this.value;
            renderList();
          });
          document.getElementById('alert-pending-only').addEventListener('change', function(){
            window._alertFilters.onlyPending = this.checked;
            renderList();
          });

          // â”€â”€ actions
          window._alertComplete = async function(id) {
            var card = document.getElementById('ac-'+id);
            if (card) { card.style.opacity='0.5'; card.querySelector('div[style*="display:flex;gap:8px"]') && (card.querySelector('div[style*="display:flex;gap:8px"]').innerHTML='<span style="color:var(--status-success);font-size:11px;font-weight:700">âœ“ ì²˜ë¦¬ ì™„ë£Œë¨</span>'); }
            try { await window.API.updateAlertStatus(id, 'ì™„ë£Œ'); } catch(e){}
            var a = alerts.find(function(x){ return x.id===id; });
            if (a) a.status = 'ì™„ë£Œ';
            renderList();
            var badge = document.getElementById('alert-unread-badge');
            var urg = alerts.filter(function(a){ return a.severity==='ê¸´ê¸‰' && a.status==='ë¯¸ì²˜ë¦¬'; }).length;
            var cau = alerts.filter(function(a){ return a.severity==='ì£¼ì˜' && a.status==='ë¯¸ì²˜ë¦¬'; }).length;
            if (badge) badge.textContent = urg + cau;
          };
          window._alertIgnore = async function(id) {
            try { await window.API.updateAlertStatus(id, 'ë¬´ì‹œ'); } catch(e){}
            var a = alerts.find(function(x){ return x.id===id; });
            if (a) a.status = 'ë¬´ì‹œ';
            renderList();
          };
          window._alertViewDetail = function(id) {
            var a = alerts.find(function(x){ return x.id===id; });
            if (!a) return;
            alert('['+a.id+'] '+a.title+'\n\n'+a.content+'\n\nê´€ë ¨ID: '+a.relatedId+'\në‹´ë‹¹ìž: '+(a.assignee||'ë¯¸ì§€ì •'));
          };

        } catch(err) { renderError('ì•Œë¦¼ ì„¼í„° ë¡œë”© ì‹¤íŒ¨: '+err.message); console.error(err); }
      }

      // â”€â”€ SAFETY â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      async function renderSafety() {
        pageContainer.innerHTML = skeleton();
        try {
          var safetyStorageKey = 'smart_ai_safety_work_items_v2';
          var selectedWorkId = window._safetySelectedWorkId || 'WRK-2605-001';
          var currentFilter = window._safetyFilter || 'all';

          var CP1252_MAP = {
            '€': 0x80, '‚': 0x82, 'ƒ': 0x83, '„': 0x84, '…': 0x85, '†': 0x86, '‡': 0x87,
            'ˆ': 0x88, '‰': 0x89, 'Š': 0x8A, '‹': 0x8B, 'Œ': 0x8C, 'Ž': 0x8E,
            '‘': 0x91, '’': 0x92, '“': 0x93, '”': 0x94, '•': 0x95, '–': 0x96, '—': 0x97,
            '˜': 0x98, '™': 0x99, 'š': 0x9A, '›': 0x9B, 'œ': 0x9C, 'ž': 0x9E, 'Ÿ': 0x9F
          };

          function repairMojibake(text) {
            if (!text || typeof text !== 'string') return text;
            if (!/[ÃÂâ€ðŸìíëêÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞß]/.test(text)) return text;
            try {
              var bytes = [];
              for (var i = 0; i < text.length; i++) {
                var ch = text[i];
                var code = ch.charCodeAt(0);
                if (CP1252_MAP[ch] !== undefined) {
                  bytes.push(CP1252_MAP[ch]);
                } else if (code <= 255) {
                  bytes.push(code);
                } else {
                  return text;
                }
              }
              var decoded = new TextDecoder('utf-8', { fatal: false }).decode(new Uint8Array(bytes));
              if (!decoded || decoded.indexOf('\uFFFD') !== -1) return text;
              return decoded;
            } catch (e) {
              return text;
            }
          }

          function repairObject(obj) {
            if (obj === null || obj === undefined) return obj;
            if (typeof obj === 'string') {
              return repairMojibake(obj);
            }
            if (Array.isArray(obj)) {
              return obj.map(function(item) { return repairObject(item); });
            }
            if (typeof obj === 'object') {
              var newObj = {};
              for (var key in obj) {
                if (obj.hasOwnProperty(key)) {
                  newObj[key] = repairObject(obj[key]);
                }
              }
              return newObj;
            }
            return obj;
          }

          function defaultSafetyItems() {
            return [
              {
                id:'WRK-2605-001', project:'LGES-AZ 오피스 전기', site:'2층 사무실',
                title:'천장 전기 배선 정리 및 신규 케이블 포설', crew:3, qty:30, unit:'m',
                due:'오늘 17:00', planStatus:'승인완료', tbmStatus:'완료', closeStatus:'마감대기',
                progressStatus:'미분석', progress:60, doneQty:18, totalQty:30,
                workText:'천장 전기 배선 정리 및 신규 케이블 포설. 작업자는 3명이고 사다리를 사용합니다. 예정 작업량은 30m입니다.',
                closeText:'천장 배선 18m 포설 완료. 자재 부족으로 나머지 12m는 내일 진행 예정. 천장 내부 장애물로 작업 속도 지연.',
                signatures:[{name:'김철수', role:'전기공', signed:true, time:'07:42'}, {name:'이민준', role:'보조', signed:true, time:'07:43'}, {name:'임성훈', role:'감시자', signed:false, time:'-'}],
                issues:[{type:'미조치', text:'자재 부족으로 잔여 12m 대기', owner:'구매팀', status:'조치중'}]
              },
              {
                id:'WRK-2605-002', project:'HFF-02 장비 설치', site:'Production Bay B',
                title:'컨트롤 패널 앵커 설치 및 케이블 트레이 보강', crew:4, qty:10, unit:'ea',
                due:'오늘 13:00', planStatus:'검토중', tbmStatus:'대기', closeStatus:'시작전',
                progressStatus:'미분석', progress:35, doneQty:0, totalQty:10,
                workText:'컨트롤 패널 앵커 설치 및 케이블 트레이 보강. 해머드릴, 앵커볼트, 사다리 사용. 예정 작업량은 10개소입니다.',
                closeText:'', signatures:[{name:'박지호', role:'팀리더', signed:false, time:'-'}, {name:'최동혁', role:'설치', signed:false, time:'-'}, {name:'강승우', role:'장비', signed:false, time:'-'}, {name:'임성훈', role:'보조', signed:false, time:'-'}],
                issues:[{type:'위험상황', text:'케이블 트레이 모서리 날카로움', owner:'박소장', status:'조치중'}]
              },
              {
                id:'WRK-2605-003', project:'SST-03 배관 수정', site:'Utility Room',
                title:'기존 배관 철거 후 신규 라인 12m 설치', crew:5, qty:12, unit:'m',
                due:'내일', planStatus:'초안', tbmStatus:'대기', closeStatus:'시작전',
                progressStatus:'미분석', progress:15, doneQty:0, totalQty:12,
                workText:'기존 배관 철거 후 신규 라인 12m 설치. 절단 공구와 리프트를 사용합니다.',
                closeText:'', signatures:[{name:'김철수', role:'배관공', signed:false, time:'-'}, {name:'이민준', role:'보조', signed:false, time:'-'}],
                issues:[{type:'아차사고', text:'배관 자재 이동 중 통로 협소', owner:'현장팀', status:'완료'}]
              }
            ];
          }

          function loadSafetyItemsOffline() {
            try {
              var raw = localStorage.getItem(safetyStorageKey);
              if (raw) {
                return JSON.parse(raw);
              }
              var legacyRaw = localStorage.getItem('smart_ai_safety_work_items_v1');
              if (legacyRaw) {
                var legacyData = JSON.parse(legacyRaw);
                if (Array.isArray(legacyData)) {
                  var repairedData = repairObject(legacyData);
                  localStorage.setItem(safetyStorageKey, JSON.stringify(repairedData));
                  return repairedData;
                }
              }
            } catch (e) {}
            return defaultSafetyItems();
          }

          // Server is the source of truth (TBM signatures / close reports are legal records).
          // localStorage is kept only as an offline cache + fallback.
          async function loadSafetyItems() {
            if (window.apiCache) { delete window.apiCache['api_getSafetyWorkItems[]']; }
            try {
              var res = await gsRun('api_getSafetyWorkItems', [], null);
              if (res && res.success && Array.isArray(res.items)) {
                if (res.items.length === 0) {
                  // First run with an empty server: migrate any local-only items up.
                  var local = loadSafetyItemsOffline();
                  if (Array.isArray(local) && local.length) {
                    gsRun('api_saveSafetyWorkItems', [local]).catch(function(){});
                    return local;
                  }
                }
                localStorage.setItem(safetyStorageKey, JSON.stringify(res.items));
                return res.items;
              }
            } catch (e) {}
            return loadSafetyItemsOffline();
          }

          var safetyItems = await loadSafetyItems();

          function saveSafetyItems() {
            localStorage.setItem(safetyStorageKey, JSON.stringify(safetyItems));
            if (window.apiCache) { delete window.apiCache['api_getSafetyWorkItems[]']; }
            try { gsRun('api_saveSafetyWorkItems', [safetyItems]).catch(function(){}); } catch (e) {}
          }

          function esc(v) {
            return String(v == null ? '' : v).replace(/[&<>"']/g, function(ch) {
              return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
            });
          }

          function selectedItem() {
            return safetyItems.find(function(w){ return w.id === selectedWorkId; }) || safetyItems[0];
          }

          function badge(text) {
            var ok = ['승인완료','완료','확정완료','조치완료'].indexOf(text) !== -1;
            var warn = ['검토중','마감대기','추천완료','조치중','서명중','진행중'].indexOf(text) !== -1;
            var color = ok ? 'var(--status-success)' : warn ? 'var(--status-warning)' : 'var(--text-tertiary)';
            var bg = ok ? 'rgba(16,185,129,.12)' : warn ? 'rgba(245,158,11,.12)' : 'var(--bg-surface-elevated)';
            return '<span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;background:'+bg+';color:'+color+';font-size:10px;font-weight:700">'+esc(text)+'</span>';
          }

          function bar(v) {
            v = Math.max(0, Math.min(100, Number(v) || 0));
            return '<div class="progress-wrapper"><div class="progress-bar"><div class="progress-fill" style="width:'+v+'%;background:var(--brand-primary)"></div></div><div class="progress-text">'+v+'%</div></div>';
          }

          function nextAction(w) {
            if (w.planStatus === '미생성' || w.planStatus === '초안' || w.planStatus === '수정필요') return 'AI 계획서 생성';
            if (w.planStatus === '검토중') return '승인';
            if (w.tbmStatus === '대기') return 'TBM 시작';
            if (w.tbmStatus === '진행중' || w.tbmStatus === '서명중') return '서명/완료';
            if (w.closeStatus !== '저장완료' && w.closeStatus !== '완료') return '작업 마감';
            if (w.progressStatus !== '확정완료') return '공정 확정';
            return '기록 보기';
          }

          function filterItems() {
            return safetyItems.filter(function(w) {
              if (currentFilter === 'all') return true;
              if (currentFilter === 'plan') return w.planStatus !== '승인완료';
              if (currentFilter === 'tbm') return w.planStatus === '승인완료' && w.tbmStatus !== '완료';
              if (currentFilter === 'close') return w.tbmStatus === '완료' && w.closeStatus !== '완료' && w.closeStatus !== '저장완료';
              if (currentFilter === 'progress') return w.closeStatus === '저장완료' && w.progressStatus !== '확정완료';
              if (currentFilter === 'issue') return (w.issues || []).some(function(i){ return i.status !== '완료' && i.status !== '조치완료'; });
              if (currentFilter === 'done') return w.progressStatus === '확정완료';
              return true;
            });
          }

          function counts() {
            var total = safetyItems.length;
            var plans = safetyItems.filter(function(w){ return w.planStatus === '승인완료'; }).length;
            var tbm = safetyItems.filter(function(w){ return w.tbmStatus === '완료'; }).length;
            var progressWait = safetyItems.filter(function(w){ return w.progressStatus === '추천완료'; }).length;
            var issues = safetyItems.reduce(function(sum,w){ return sum + (w.issues || []).filter(function(i){ return i.status !== '완료' && i.status !== '조치완료'; }).length; }, 0);
            return {total:total, plans:plans, tbm:tbm, progressWait:progressWait, issues:issues};
          }

          function renderSafetyShell() {
            var c = counts();
            if (alertBadge) alertBadge.textContent = c.issues;
            pageContainer.innerHTML =
              '<div class="header-section"><div>'
              +'<h1 class="page-title">AI 작업안전관리</h1>'
              +'<p class="page-subtitle">작업내용 입력 → 안전 작업 계획서 생성 → TBM/서명 → 작업 마감 → AI 공정율 추천</p>'
              +'</div><div class="action-row">'
              +'<button class="btn-secondary" onclick="openMasterSheet()"><i class="ph ph-table"></i> 마스터 시트</button>'
              +'<button class="btn-primary" id="safety-new-work-btn"><i class="ph ph-plus"></i> 오늘 작업 등록</button>'
              +'</div></div>'
              +'<div class="kpi-row" style="grid-template-columns:repeat(5,1fr)" id="safety-kpis"></div>'
              +'<div class="tab-nav" id="safety-tabs">'
              +'<button class="tab-btn active" data-tab="s-today"><i class="ph ph-calendar-check"></i> 오늘의 작업</button>'
              +'<button class="tab-btn" data-tab="s-ai-plan"><i class="ph ph-sparkle"></i> AI 계획서</button>'
              +'<button class="tab-btn" data-tab="s-tbm"><i class="ph ph-signature"></i> TBM / 서명</button>'
              +'<button class="tab-btn" data-tab="s-close"><i class="ph ph-chart-pie-slice"></i> 작업 마감 / 공정</button>'
              +'<button class="tab-btn" data-tab="s-issues"><i class="ph ph-warning-circle"></i> 이슈</button>'
              +'<button class="tab-btn" data-tab="s-records"><i class="ph ph-folder-open"></i> 프로젝트 기록</button>'
              +'</div>'
              +'<div id="s-today" class="tab-content" style="display:block"></div>'
              +'<div id="s-ai-plan" class="tab-content" style="display:none"></div>'
              +'<div id="s-tbm" class="tab-content" style="display:none"></div>'
              +'<div id="s-close" class="tab-content" style="display:none"></div>'
              +'<div id="s-issues" class="tab-content" style="display:none"></div>'
              +'<div id="s-records" class="tab-content" style="display:none"></div>'
              +'<div id="safety-modal-root"></div>';

            document.querySelectorAll('#safety-tabs .tab-btn').forEach(function(btn){
              btn.addEventListener('click', function(){
                document.querySelectorAll('#safety-tabs .tab-btn').forEach(function(b){ b.classList.remove('active'); });
                btn.classList.add('active');
                document.querySelectorAll('#page-container .tab-content').forEach(function(el){ el.style.display = 'none'; });
                document.getElementById(btn.getAttribute('data-tab')).style.display = 'block';
                renderAllSafetyTabs();
              });
            });
            document.getElementById('safety-new-work-btn').addEventListener('click', openNewWorkModal);
            renderAllSafetyTabs();
          }

          function renderKpis() {
            var c = counts();
            document.getElementById('safety-kpis').innerHTML =
              '<div class="kpi-card" style="border-left:3px solid var(--brand-primary)"><div class="kpi-label"><i class="ph ph-briefcase"></i> 오늘 진행 작업</div><div class="kpi-value" style="color:var(--brand-primary)">'+c.total+'</div><div class="kpi-meta">단기 프로젝트 작업 카드</div></div>'
              +'<div class="kpi-card" style="border-left:3px solid var(--status-success)"><div class="kpi-label"><i class="ph ph-shield-check"></i> 안전계획 승인</div><div class="kpi-value" style="color:var(--status-success)">'+c.plans+' / '+c.total+'</div><div class="kpi-meta">PHA · PTP · TBM 초안 포함</div></div>'
              +'<div class="kpi-card" style="border-left:3px solid #8b5cf6"><div class="kpi-label"><i class="ph ph-users-three"></i> TBM 완료</div><div class="kpi-value" style="color:#8b5cf6">'+c.tbm+' / '+c.total+'</div><div class="kpi-meta">작업자 서명 기준</div></div>'
              +'<div class="kpi-card" style="border-left:3px solid var(--status-warning)"><div class="kpi-label"><i class="ph ph-chart-line-up"></i> 공정 반영 대기</div><div class="kpi-value" style="color:var(--status-warning)">'+c.progressWait+'</div><div class="kpi-meta">AI 추천 후 관리자 확정</div></div>'
              +'<div class="kpi-card" style="border-left:3px solid var(--status-danger)"><div class="kpi-label"><i class="ph ph-warning"></i> 미조치 이슈</div><div class="kpi-value" style="color:var(--status-danger)">'+c.issues+'</div><div class="kpi-meta">마감 전 조치 필요</div></div>';
          }

          function renderTodayTab() {
            var rows = filterItems().map(function(w){
              var selected = w.id === selectedWorkId ? 'background:rgba(37,99,235,.06)' : '';
              return '<tr style="cursor:pointer;'+selected+'" onclick="window._safetySelectWork(\''+w.id+'\')">'
                +'<td class="cell-mono">'+esc(w.id)+'</td>'
                +'<td><div class="cell-primary">'+esc(w.title)+'</div><div style="font-size:10px;color:var(--text-tertiary);margin-top:3px">'+esc(w.project)+' · '+esc(w.site)+'</div></td>'
                +'<td style="text-align:center">'+esc(w.crew)+'명</td><td>'+badge(w.planStatus)+'</td><td>'+badge(w.tbmStatus)+'</td><td>'+badge(w.closeStatus)+'</td>'
                +'<td style="min-width:150px">'+bar(w.progress)+'</td><td class="cell-mono">'+esc(w.due)+'</td><td><button class="btn-secondary safety-next-btn" data-id="'+esc(w.id)+'" style="padding:4px 8px;font-size:11px">'+esc(nextAction(w))+'</button></td></tr>';
            }).join('');
            document.getElementById('s-today').innerHTML =
              '<div style="display:grid;grid-template-columns:1.45fr .9fr;gap:16px">'
              +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-list-checks"></i> 오늘 작업 흐름</div>'
              +'<select id="safety-filter" class="search-inline" style="width:180px"><option value="all">전체</option><option value="plan">계획서 미완료</option><option value="tbm">TBM 대기</option><option value="close">마감 대기</option><option value="progress">공정 반영 대기</option><option value="issue">미조치 있음</option><option value="done">완료</option></select></div>'
              +'<div class="panel-body"><table class="data-table"><thead><tr><th>ID</th><th>작업내용</th><th>인원</th><th>계획서</th><th>TBM</th><th>마감</th><th>공정율</th><th>기한</th><th>다음</th></tr></thead><tbody>'+(rows || '<tr><td colspan="9" style="text-align:center;padding:28px;color:var(--text-tertiary)">해당 조건의 작업이 없습니다.</td></tr>')+'</tbody></table></div></div>'
              +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-clock-countdown"></i> 오늘 해야 할 일</div></div><div class="panel-body padded" id="safety-next-list"></div></div></div>';
            document.getElementById('safety-filter').value = currentFilter;
            document.getElementById('safety-filter').addEventListener('change', function(){ currentFilter = this.value; window._safetyFilter = currentFilter; renderAllSafetyTabs(); });
            document.querySelectorAll('.safety-next-btn').forEach(function(btn){
              btn.addEventListener('click', function(e){ e.stopPropagation(); window._safetySelectWork(btn.getAttribute('data-id')); goNextStep(selectedItem()); });
            });
            renderNextList();
          }

          function renderNextList() {
            var items = safetyItems.map(function(w){
              return '<div style="padding:10px 0;border-bottom:1px solid var(--border-subtle);cursor:pointer" onclick="window._safetySelectWork(\''+w.id+'\'); window._safetyGoNext(\''+w.id+'\')">'
                +'<div style="font-weight:700;font-size:12px;color:var(--brand-primary)">'+esc(w.project)+' · '+esc(nextAction(w))+'</div>'
                +'<div style="font-size:11px;color:var(--text-tertiary);margin-top:3px">'+esc(w.title)+'</div></div>';
            }).join('');
            var el = document.getElementById('safety-next-list');
            if (el) el.innerHTML = items;
          }

          function renderPlanTab() {
            var w = selectedItem();
            document.getElementById('s-ai-plan').innerHTML =
              '<div style="display:grid;grid-template-columns:420px 1fr;gap:16px">'
              +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-pencil-simple-line"></i> 작업내용 입력</div>'+badge(w.planStatus)+'</div><div class="panel-body padded">'
              +'<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:6px">프로젝트 / 장소</label><input id="ai-project-input" class="search-inline" style="width:100%;margin-bottom:10px" value="'+esc(w.project+' / '+w.site)+'">'
              +'<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:6px">작업내용</label><textarea id="ai-work-input" style="width:100%;height:138px;background:var(--bg-base);border:1px solid var(--border-subtle);border-radius:6px;color:var(--text-primary);font-family:var(--font-base);font-size:12px;padding:10px;resize:vertical">'+esc(w.workText)+'</textarea>'
              +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px"><input id="ai-qty-input" class="search-inline" style="width:100%" value="'+esc(w.qty)+'"><select id="ai-unit-input" class="search-inline" style="width:100%"><option '+(w.unit==='m'?'selected':'')+'>m</option><option '+(w.unit==='ea'?'selected':'')+'>ea</option><option '+(w.unit==='%'?'selected':'')+'>%</option><option '+(w.unit==='단계'?'selected':'')+'>단계</option></select></div>'
              +'<button class="btn-primary" id="ai-generate-plan" style="width:100%;margin-top:14px"><i class="ph ph-sparkle"></i> AI 안전계획 생성</button></div></div>'
              +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-file-text"></i> PHA · PTP · TBM 초안</div><span style="font-size:10px;color:var(--status-warning);font-weight:700">현장 책임자 확인 필요</span></div><div class="panel-body padded" id="ai-plan-preview"></div></div></div>';
            document.getElementById('ai-generate-plan').addEventListener('click', function(){ generatePlan(w.id); });
            renderPlanPreview(w);
          }

          function safetyPlanBody(w) {
            var p = w.aiPlan;
            if (!p) {
              return '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div><div style="font-size:12px;font-weight:700;margin-bottom:6px;color:var(--brand-primary)">PHA 위험성평가</div><div style="font-size:12px;color:var(--text-tertiary);line-height:1.8">「AI 안전계획 생성」을 누르면 작업내용 기반으로 위험성평가·PTP·보호구·TBM 주제가 생성됩니다.</div></div><div><div style="font-size:12px;font-weight:700;margin-bottom:6px;color:var(--status-success)">PTP 작업 전 계획</div><div style="font-size:12px;color:var(--text-tertiary)">생성 대기 중</div></div></div>';
            }
            function chips(arr, color){ return (arr||[]).map(function(x){ return '<span style="display:inline-block;padding:3px 8px;margin:2px;border-radius:12px;background:'+color+'22;color:'+color+';font-size:11px">'+esc(x)+'</span>'; }).join(''); }
            function riskColor(lv){ return lv==='상'?'var(--status-danger)':lv==='중'?'var(--status-warning)':'var(--status-success)'; }
            var hazardRows = (p.hazards||[]).map(function(h){ return '<tr><td style="font-size:12px">'+esc(h.hazard)+'</td><td><span style="color:'+riskColor(h.risk_level)+';font-weight:700;font-size:11px">'+esc(h.risk_level)+'</span></td><td style="font-size:12px;color:var(--text-secondary)">'+esc(h.control)+'</td></tr>'; }).join('');
            var ptp = (p.ptp_steps||[]).map(function(s){ return '<li>'+esc(s)+'</li>'; }).join('');
            var tbm = (p.tbm_topics||[]).map(function(s){ return '<li>'+esc(s)+'</li>'; }).join('');
            return ''
              + (p.summary ? '<div style="padding:10px 12px;background:var(--bg-subtle);border-radius:8px;font-size:12px;color:var(--text-secondary);margin-bottom:12px"><b style="color:var(--text-primary)">요약:</b> '+esc(p.summary)+'</div>' : '')
              + '<div style="font-size:12px;font-weight:700;margin-bottom:6px;color:var(--brand-primary)">PHA 위험성평가</div><table class="data-table" style="margin-bottom:14px"><thead><tr><th>위험요인</th><th>위험도</th><th>대책</th></tr></thead><tbody>'+(hazardRows||'<tr><td colspan="3" style="color:var(--text-tertiary)">-</td></tr>')+'</tbody></table>'
              + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div><div style="font-size:12px;font-weight:700;margin-bottom:6px;color:var(--status-success)">PTP 작업 전 단계</div><ol style="margin:0;padding-left:18px;font-size:12px;line-height:1.8;color:var(--text-secondary)">'+ptp+'</ol></div><div><div style="font-size:12px;font-weight:700;margin-bottom:6px;color:#a78bfa">TBM 토의 주제</div><ul style="margin:0;padding-left:18px;font-size:12px;line-height:1.8;color:var(--text-secondary)">'+tbm+'</ul></div></div>'
              + '<div style="margin-top:12px;font-size:12px;font-weight:700;color:var(--text-secondary)">필수 보호구</div><div style="margin:4px 0">'+chips(p.required_ppe, '#3b82f6')+'</div>'
              + ((p.permits&&p.permits.length) ? '<div style="margin-top:8px;font-size:12px;font-weight:700;color:var(--text-secondary)">필요 허가(PTW)</div><div style="margin:4px 0">'+chips(p.permits, '#f59e0b')+'</div>' : '')
              + (p.key_risk ? '<div style="margin-top:12px;padding:10px 12px;background:rgba(239,68,68,.08);border-left:3px solid var(--status-danger);border-radius:6px;font-size:12px;color:var(--text-primary)"><b>핵심 위험:</b> '+esc(p.key_risk)+'</div>' : '');
          }

          function renderPlanPreview(w) {
            var el = document.getElementById('ai-plan-preview');
            if (!el) return;
            el.innerHTML =
              '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px"><div style="padding:10px;background:var(--bg-subtle);border-radius:8px"><div style="font-size:10px;color:var(--text-tertiary)">작업명</div><div style="font-weight:700;font-size:12px;margin-top:4px">'+esc(w.title)+'</div></div><div style="padding:10px;background:var(--bg-subtle);border-radius:8px"><div style="font-size:10px;color:var(--text-tertiary)">예정 작업량</div><div style="font-weight:700;font-size:12px;margin-top:4px">'+esc(w.qty)+' '+esc(w.unit)+'</div></div><div style="padding:10px;background:rgba(245,158,11,.08);border-radius:8px"><div style="font-size:10px;color:var(--status-warning)">승인 상태</div><div style="font-weight:700;font-size:12px;margin-top:4px;color:var(--status-warning)">'+esc(w.planStatus)+'</div></div></div>'
              + safetyPlanBody(w)
              +'<div style="display:flex;gap:8px;margin-top:14px"><button class="btn-primary" id="approve-plan-btn" '+(w.planStatus === '승인완료' ? 'disabled' : '')+'><i class="ph ph-check-circle"></i> 승인</button><button class="btn-secondary" id="reject-plan-btn"><i class="ph ph-x-circle"></i> 반려</button><button class="btn-secondary" id="save-plan-draft-btn"><i class="ph ph-floppy-disk"></i> 초안 저장</button></div>';
            document.getElementById('approve-plan-btn').addEventListener('click', function(){ updateWork(w.id, {planStatus:'승인완료', tbmStatus: w.tbmStatus === '대기' ? '대기' : w.tbmStatus}); switchTab('s-tbm'); });
            document.getElementById('reject-plan-btn').addEventListener('click', function(){ updateWork(w.id, {planStatus:'수정필요'}); });
            document.getElementById('save-plan-draft-btn').addEventListener('click', function(){ updateWork(w.id, {planStatus:'초안'}); });
          }

          function renderTbmTab() {
            var w = selectedItem();
            var canStart = w.planStatus === '승인완료';
            var signRows = (w.signatures || []).map(function(s, idx){
              return '<tr><td class="cell-primary">'+esc(s.name)+'</td><td>'+esc(s.role)+'</td><td class="cell-mono">'+esc(s.time)+'</td><td>'+badge(s.signed ? '완료' : '대기')+'</td><td><button class="btn-secondary sign-worker-btn" data-idx="'+idx+'" style="padding:4px 8px;font-size:11px" '+(!canStart || s.signed ? 'disabled' : '')+'>서명</button></td></tr>';
            }).join('');
            document.getElementById('s-tbm').innerHTML =
              '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px"><div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-megaphone"></i> TBM 진행 내용</div>'+badge(w.tbmStatus)+'</div><div class="panel-body padded"><div style="font-size:13px;font-weight:700;margin-bottom:8px">'+esc(w.title)+'</div><div style="font-size:12px;color:var(--text-secondary);line-height:1.7">승인된 안전계획서를 기준으로 주요 위험, 작업순서, 보호구, 작업 중지 기준을 공유합니다. 작업자는 내용을 확인한 뒤 서명합니다.</div><div style="display:flex;gap:8px;margin-top:14px"><button class="btn-primary" id="tbm-start-btn" '+(!canStart || w.tbmStatus !== '대기' ? 'disabled' : '')+'>TBM 시작</button><button class="btn-secondary" id="tbm-sign-all-btn" '+(!canStart || w.tbmStatus === '완료' ? 'disabled' : '')+'>전체 서명 처리</button><button class="btn-primary" id="tbm-complete-btn" '+(!canStart || w.signatures.some(function(s){return !s.signed;}) || w.tbmStatus === '완료' ? 'disabled' : '')+'>TBM 완료</button></div></div></div><div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-signature"></i> 작업자 서명</div><button class="btn-secondary" id="add-worker-btn" style="padding:4px 10px;font-size:12px"><i class="ph ph-plus"></i> 작업자 추가</button></div><div class="panel-body"><table class="data-table"><thead><tr><th>작업자</th><th>역할</th><th>확인시간</th><th>서명</th><th></th></tr></thead><tbody>'+signRows+'</tbody></table></div></div></div>';
            document.getElementById('tbm-start-btn').addEventListener('click', function(){ updateWork(w.id, {tbmStatus:'서명중'}); });
            document.getElementById('tbm-sign-all-btn').addEventListener('click', function(){ w.signatures.forEach(function(s){ s.signed = true; s.time = new Date().toTimeString().slice(0,5); }); updateWork(w.id, {tbmStatus:'서명중'}); });
            document.getElementById('tbm-complete-btn').addEventListener('click', function(){ updateWork(w.id, {tbmStatus:'완료', closeStatus:'마감대기'}); switchTab('s-close'); });
            document.getElementById('add-worker-btn').addEventListener('click', function(){ var name = prompt('작업자 이름'); if (!name) return; w.signatures.push({name:name, role:'작업자', signed:false, time:'-'}); updateWork(w.id, {tbmStatus:w.tbmStatus}); });
            document.querySelectorAll('.sign-worker-btn').forEach(function(btn){ btn.addEventListener('click', function(){ var idx = Number(btn.getAttribute('data-idx')); w.signatures[idx].signed = true; w.signatures[idx].time = new Date().toTimeString().slice(0,5); updateWork(w.id, {tbmStatus:'서명중'}); }); });
          }

          function renderCloseTab() {
            var w = selectedItem();
            var canClose = w.tbmStatus === '완료';
            document.getElementById('s-close').innerHTML =
              '<div style="display:grid;grid-template-columns:420px 1fr;gap:16px"><div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-flag-checkered"></i> 작업 마감 입력</div>'+badge(w.closeStatus)+'</div><div class="panel-body padded"><label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:6px">실제 완료내용</label><textarea id="close-work-input" style="width:100%;height:118px;background:var(--bg-base);border:1px solid var(--border-subtle);border-radius:6px;color:var(--text-primary);font-family:var(--font-base);font-size:12px;padding:10px;resize:vertical">'+esc(w.closeText)+'</textarea><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px"><input id="done-qty-input" class="search-inline" style="width:100%" value="'+esc(w.doneQty || 0)+'"><input id="total-qty-input" class="search-inline" style="width:100%" value="'+esc(w.totalQty || w.qty || 0)+'"></div><select id="work-state-input" class="search-inline" style="width:100%;margin-top:10px"><option>일부 완료</option><option>완료</option><option>지연</option><option>중단</option><option>재작업 필요</option></select><div style="display:flex;gap:8px;margin-top:14px"><button class="btn-secondary" id="save-close-btn" '+(!canClose ? 'disabled' : '')+'>마감 저장</button><button class="btn-primary" id="ai-progress-btn" '+(!canClose ? 'disabled' : '')+'>AI 공정율 분석</button></div></div></div><div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-chart-donut"></i> 공정율 추천 및 확정</div>'+badge(w.progressStatus)+'</div><div class="panel-body padded" id="progress-result"></div></div></div>';
            document.getElementById('save-close-btn').addEventListener('click', function(){ saveClose(w.id, false); });
            document.getElementById('ai-progress-btn').addEventListener('click', function(){ saveClose(w.id, true); });
            renderProgressResult(w);
          }

          function renderProgressResult(w) {
            var el = document.getElementById('progress-result');
            if (!el) return;
            var total = Number(w.totalQty || w.qty || 0);
            var done = Number(w.doneQty || 0);
            var ai = w.aiProgress;
            var rate = ai ? Math.max(0, Math.min(100, Number(ai.recommended_progress || 0))) : (total > 0 ? Math.round(done / total * 100) : Number(w.progress || 0));
            var remain = Math.max(total - done, 0);
            el.innerHTML =
              '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px"><div style="padding:10px;background:var(--bg-subtle);border-radius:8px"><div style="font-size:10px;color:var(--text-tertiary)">예정</div><div style="font-size:20px;font-weight:700">'+total+'</div></div><div style="padding:10px;background:rgba(16,185,129,.08);border-radius:8px"><div style="font-size:10px;color:var(--status-success)">완료</div><div style="font-size:20px;font-weight:700;color:var(--status-success)">'+done+'</div></div><div style="padding:10px;background:rgba(245,158,11,.08);border-radius:8px"><div style="font-size:10px;color:var(--status-warning)">잔여</div><div style="font-size:20px;font-weight:700;color:var(--status-warning)">'+remain+'</div></div><div style="padding:10px;background:rgba(37,99,235,.08);border-radius:8px"><div style="font-size:10px;color:var(--brand-primary)">AI 추천</div><div style="font-size:20px;font-weight:700;color:var(--brand-primary)">'+rate+'%</div></div></div>'+bar(rate)
              + (ai
                  ? '<div style="margin-top:14px;padding:12px;background:rgba(37,99,235,.06);border-left:3px solid var(--brand-primary);border-radius:6px;font-size:12px;color:var(--text-secondary);line-height:1.7"><b style="color:var(--brand-primary)">AI 추천 ('+esc(ai.status || '')+')</b><br>'+esc(ai.rationale || '')+(ai.follow_up ? '<br><span style="color:var(--text-tertiary)">후속: '+esc(ai.follow_up)+'</span>' : '')+'</div>'
                  : '<div style="margin-top:14px;padding:12px;background:var(--bg-subtle);border-radius:8px;font-size:12px;color:var(--text-secondary);line-height:1.7">「AI 공정율 분석」을 누르면 마감 보고를 바탕으로 AI가 공정율을 추천합니다. 관리자가 확정하면 프로젝트 기록에 반영됩니다.</div>')
              +'<div style="display:flex;align-items:center;gap:8px;margin-top:14px"><input id="confirm-progress-input" class="search-inline" style="width:90px" value="'+rate+'%"><button class="btn-primary" id="confirm-progress-btn" '+(w.progressStatus !== '추천완료' ? 'disabled' : '')+'>공정율 확정 반영</button><button class="btn-secondary" id="next-work-btn">다음 작업 생성</button></div>';
            document.getElementById('confirm-progress-btn').addEventListener('click', function(){ var v = parseInt(document.getElementById('confirm-progress-input').value, 10); updateWork(w.id, {progress:isNaN(v)?rate:v, progressStatus:'확정완료', closeStatus:'완료'}); switchTab('s-records'); });
            document.getElementById('next-work-btn').addEventListener('click', function(){ createNextWork(w); });
          }

          function renderIssuesTab() {
            var allIssues = [];
            safetyItems.forEach(function(w){ (w.issues || []).forEach(function(i, idx){ allIssues.push({work:w, issue:i, idx:idx}); }); });
            document.getElementById('s-issues').innerHTML =
              '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-warning-circle"></i> 사고 · 아차사고 · 미조치 사항</div><button class="btn-primary" id="add-issue-btn" style="padding:4px 10px;font-size:12px"><i class="ph ph-plus"></i> 등록</button></div><div class="panel-body"><table class="data-table"><thead><tr><th>유형</th><th>작업</th><th>내용</th><th>담당</th><th>상태</th><th></th></tr></thead><tbody>'+allIssues.map(function(x){ return '<tr><td>'+esc(x.issue.type)+'</td><td>'+esc(x.work.project)+'</td><td class="cell-primary">'+esc(x.issue.text)+'</td><td>'+esc(x.issue.owner || '-')+'</td><td>'+badge(x.issue.status)+'</td><td><button class="btn-secondary issue-done-btn" data-work="'+esc(x.work.id)+'" data-idx="'+x.idx+'" style="padding:4px 8px;font-size:11px">조치 완료</button></td></tr>'; }).join('')+'</tbody></table></div></div>';
            document.getElementById('add-issue-btn').addEventListener('click', function(){ var w = selectedItem(); var text = prompt('이슈 내용을 입력하세요'); if (!text) return; w.issues = w.issues || []; w.issues.push({type:'미조치', text:text, owner:'담당자 미정', status:'대기'}); updateWork(w.id, {}); });
            document.querySelectorAll('.issue-done-btn').forEach(function(btn){ btn.addEventListener('click', function(){ var w = safetyItems.find(function(x){ return x.id === btn.getAttribute('data-work'); }); if (!w) return; w.issues[Number(btn.getAttribute('data-idx'))].status = '조치완료'; updateWork(w.id, {}); }); });
          }

          function renderRecordsTab() {
            document.getElementById('s-records').innerHTML =
              '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-archive"></i> 프로젝트 안전·작업 기록</div><button class="btn-secondary" id="pdf-report-btn" style="padding:4px 10px;font-size:12px"><i class="ph ph-file-pdf"></i> PDF 출력</button></div><div class="panel-body"><table class="data-table"><thead><tr><th>프로젝트</th><th>작업</th><th>안전계획</th><th>TBM</th><th>마감</th><th>공정</th><th>이슈</th></tr></thead><tbody>'+safetyItems.map(function(w){ return '<tr><td class="cell-primary">'+esc(w.project)+'</td><td>'+esc(w.title)+'</td><td>'+badge(w.planStatus)+'</td><td>'+badge(w.tbmStatus)+'</td><td>'+badge(w.closeStatus)+'</td><td>'+bar(w.progress)+'</td><td>'+((w.issues || []).length)+'건</td></tr>'; }).join('')+'</tbody></table></div></div>';
            document.getElementById('pdf-report-btn').addEventListener('click', function(){ alert('PDF 리포트 생성 준비 완료: 실제 PDF 출력 API 연결 단계입니다.'); });
          }

          function renderAllSafetyTabs() {
            renderKpis();
            renderTodayTab();
            renderPlanTab();
            renderTbmTab();
            renderCloseTab();
            renderIssuesTab();
            renderRecordsTab();
          }

          function updateWork(id, patch) {
            var w = safetyItems.find(function(x){ return x.id === id; });
            if (!w) return;
            Object.keys(patch || {}).forEach(function(k){ w[k] = patch[k]; });
            saveSafetyItems();
            renderAllSafetyTabs();
          }

          function applyServerItem(id, item) {
            var i = safetyItems.findIndex(function(x){ return x.id === id; });
            if (i >= 0 && item) { safetyItems[i] = item; }
            localStorage.setItem(safetyStorageKey, JSON.stringify(safetyItems));
            renderAllSafetyTabs();
          }

          async function generatePlan(id) {
            var w = safetyItems.find(function(x){ return x.id === id; });
            if (!w) return;
            var project = document.getElementById('ai-project-input').value.split('/');
            w.project = (project[0] || w.project).trim();
            w.site = (project[1] || w.site).trim();
            w.workText = document.getElementById('ai-work-input').value;
            w.qty = Number(document.getElementById('ai-qty-input').value || w.qty || 0);
            w.totalQty = w.qty;
            w.unit = document.getElementById('ai-unit-input').value;
            var btn = document.getElementById('ai-generate-plan');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner-gap"></i> AI 안전계획 분석 중...'; }
            try {
              var res = await gsRun('api_generateSafetyPlan', [w], null);
              if (res && res.success && res.item) { applyServerItem(id, res.item); return; }
              alert((res && res.error) || 'AI 계획 생성에 실패했습니다. (GEMINI_API_KEY 설정 확인)');
            } catch (e) { alert('AI 계획 생성 오류: ' + e.message); }
            w.planStatus = '검토중'; saveSafetyItems(); renderAllSafetyTabs();
          }

          async function saveClose(id, analyze) {
            var w = safetyItems.find(function(x){ return x.id === id; });
            if (!w) return;
            w.closeText = document.getElementById('close-work-input').value;
            w.doneQty = Number(document.getElementById('done-qty-input').value || 0);
            w.totalQty = Number(document.getElementById('total-qty-input').value || w.qty || 0);
            w.closeStatus = '저장완료';
            if (!analyze) { saveSafetyItems(); renderAllSafetyTabs(); return; }
            var btn = document.getElementById('ai-progress-btn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner-gap"></i> AI 공정율 분석 중...'; }
            try {
              var res = await gsRun('api_recommendSafetyProgress', [w], null);
              if (res && res.success && res.item) { applyServerItem(id, res.item); return; }
              alert((res && res.error) || 'AI 공정율 분석에 실패했습니다. (GEMINI_API_KEY 설정 확인)');
            } catch (e) { alert('AI 공정율 분석 오류: ' + e.message); }
            w.progress = w.totalQty > 0 ? Math.round(w.doneQty / w.totalQty * 100) : w.progress;
            w.progressStatus = '추천완료'; saveSafetyItems(); renderAllSafetyTabs();
          }

          function createNextWork(w) {
            var remain = Math.max(Number(w.totalQty || 0) - Number(w.doneQty || 0), 0);
            if (!remain) { alert('잔여 수량이 없습니다.'); return; }
            var id = 'WRK-' + new Date().getTime().toString().slice(-8);
            safetyItems.push({
              id:id, project:w.project, site:w.site, title:'잔여 작업: '+w.title, crew:w.crew, qty:remain, unit:w.unit, due:'다음 작업일',
              planStatus:'미생성', tbmStatus:'대기', closeStatus:'시작전', progressStatus:'미분석', progress:0, doneQty:0, totalQty:remain,
              workText:'이전 작업 잔여 '+remain+w.unit+' 진행. '+w.title, closeText:'', signatures:[], issues:[]
            });
            selectedWorkId = id;
            window._safetySelectedWorkId = id;
            saveSafetyItems();
            switchTab('s-ai-plan');
          }

          function goNextStep(w) {
            var action = nextAction(w);
            if (action === 'AI 계획서 생성' || action === '승인') switchTab('s-ai-plan');
            else if (action === 'TBM 시작' || action === '서명/완료') switchTab('s-tbm');
            else if (action === '작업 마감' || action === '공정 확정') switchTab('s-close');
            else switchTab('s-records');
          }

          function switchTab(tabId) {
            var btn = document.querySelector('#safety-tabs [data-tab="'+tabId+'"]');
            if (btn) btn.click();
          }

          function openNewWorkModal() {
            document.getElementById('safety-modal-root').innerHTML =
              '<div style="position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:5000;display:flex;align-items:center;justify-content:center;padding:24px"><div class="panel" style="width:560px;max-width:96vw;margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-plus-circle"></i> 오늘 작업 등록</div><button id="close-new-work-modal" class="icon-btn"><i class="ph ph-x"></i></button></div><div class="panel-body padded"><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><input id="new-project" class="search-inline" style="width:100%" placeholder="프로젝트명"><input id="new-site" class="search-inline" style="width:100%" placeholder="작업장소"><input id="new-crew" class="search-inline" style="width:100%" placeholder="작업인원" value="3"><input id="new-due" class="search-inline" style="width:100%" placeholder="기한" value="오늘"></div><textarea id="new-work-text" style="width:100%;height:120px;margin-top:10px;background:var(--bg-base);border:1px solid var(--border-subtle);border-radius:6px;color:var(--text-primary);font-family:var(--font-base);font-size:12px;padding:10px;resize:vertical" placeholder="작업내용을 입력하세요"></textarea><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px"><input id="new-qty" class="search-inline" style="width:100%" placeholder="예정 수량" value="1"><select id="new-unit" class="search-inline" style="width:100%"><option>ea</option><option>m</option><option>%</option><option>단계</option></select></div><button id="save-new-work" class="btn-primary" style="width:100%;margin-top:14px"><i class="ph ph-check-circle"></i> 작업 등록 후 AI 계획서 작성</button></div></div></div>';
            document.getElementById('close-new-work-modal').addEventListener('click', function(){ document.getElementById('safety-modal-root').innerHTML=''; });
            document.getElementById('save-new-work').addEventListener('click', function(){
              var text = document.getElementById('new-work-text').value.trim();
              if (!text) { alert('작업내용을 입력하세요.'); return; }
              var id = 'WRK-' + new Date().getTime().toString().slice(-8);
              var project = document.getElementById('new-project').value || '신규 프로젝트';
              var site = document.getElementById('new-site').value || '작업장소 미정';
              safetyItems.push({ id:id, project:project, site:site, title:text.slice(0,42), crew:Number(document.getElementById('new-crew').value || 1), qty:Number(document.getElementById('new-qty').value || 1), unit:document.getElementById('new-unit').value, due:document.getElementById('new-due').value || '오늘', planStatus:'미생성', tbmStatus:'대기', closeStatus:'시작전', progressStatus:'미분석', progress:0, doneQty:0, totalQty:Number(document.getElementById('new-qty').value || 1), workText:text, closeText:'', signatures:[], issues:[] });
              selectedWorkId = id;
              window._safetySelectedWorkId = id;
              saveSafetyItems();
              document.getElementById('safety-modal-root').innerHTML='';
              renderAllSafetyTabs();
              switchTab('s-ai-plan');
            });
          }

          window._safetySelectWork = function(id) {
            selectedWorkId = id;
            window._safetySelectedWorkId = id;
            renderAllSafetyTabs();
          };
          window._safetyGoNext = function(id) {
            selectedWorkId = id;
            window._safetySelectedWorkId = id;
            goNextStep(selectedItem());
          };

          renderSafetyShell();
          return;

          if (alertBadge) alertBadge.textContent = '0';

          var aiSafetyWorkItems = [
            { id:'WRK-2605-001', project:'LGES-AZ ì˜¤í”¼ìŠ¤ ì „ê¸°', site:'2ì¸µ ì‚¬ë¬´ì‹¤', title:'ì²œìž¥ ì „ê¸° ë°°ì„  ì •ë¦¬ ë° ì‹ ê·œ ì¼€ì´ë¸” í¬ì„¤', crew:3, plan:'ìŠ¹ì¸ì™„ë£Œ', tbm:'ì™„ë£Œ', close:'ë§ˆê°ëŒ€ê¸°', progress:60, due:'ì˜¤ëŠ˜ 17:00' },
            { id:'WRK-2605-002', project:'HFF-02 ìž¥ë¹„ ì„¤ì¹˜', site:'Production Bay B', title:'ì»¨íŠ¸ë¡¤ íŒ¨ë„ ì•µì»¤ ì„¤ì¹˜ ë° ì¼€ì´ë¸” íŠ¸ë ˆì´ ë³´ê°•', crew:4, plan:'ê²€í† ì¤‘', tbm:'ëŒ€ê¸°', close:'ì‹œìž‘ì „', progress:35, due:'ì˜¤ëŠ˜ 13:00' },
            { id:'WRK-2605-003', project:'SST-03 ë°°ê´€ ìˆ˜ì •', site:'Utility Room', title:'ê¸°ì¡´ ë°°ê´€ ì² ê±° í›„ ì‹ ê·œ ë¼ì¸ 12m ì„¤ì¹˜', crew:5, plan:'ì´ˆì•ˆ', tbm:'ëŒ€ê¸°', close:'ì‹œìž‘ì „', progress:15, due:'ë‚´ì¼' }
          ];

          function safetyStatus(text) {
            var color = text === 'ì™„ë£Œ' || text === 'ìŠ¹ì¸ì™„ë£Œ' ? 'var(--status-success)' : text === 'ê²€í† ì¤‘' || text === 'ë§ˆê°ëŒ€ê¸°' ? 'var(--status-warning)' : 'var(--text-tertiary)';
            var bg = text === 'ì™„ë£Œ' || text === 'ìŠ¹ì¸ì™„ë£Œ' ? 'rgba(16,185,129,.12)' : text === 'ê²€í† ì¤‘' || text === 'ë§ˆê°ëŒ€ê¸°' ? 'rgba(245,158,11,.12)' : 'var(--bg-surface-elevated)';
            return '<span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;background:'+bg+';color:'+color+';font-size:10px;font-weight:700">'+text+'</span>';
          }

          function progressBar(value) {
            return '<div class="progress-wrapper"><div class="progress-bar"><div class="progress-fill" style="width:'+value+'%;background:var(--brand-primary)"></div></div><div class="progress-text">'+value+'%</div></div>';
          }

          var workRows = aiSafetyWorkItems.map(function(w) {
            return '<tr>'
              +'<td class="cell-mono">'+w.id+'</td>'
              +'<td><div class="cell-primary">'+w.title+'</div><div style="font-size:10px;color:var(--text-tertiary);margin-top:3px">'+w.project+' Â· '+w.site+'</div></td>'
              +'<td style="text-align:center">'+w.crew+'ëª…</td>'
              +'<td>'+safetyStatus(w.plan)+'</td>'
              +'<td>'+safetyStatus(w.tbm)+'</td>'
              +'<td>'+safetyStatus(w.close)+'</td>'
              +'<td style="min-width:150px">'+progressBar(w.progress)+'</td>'
              +'<td class="cell-mono">'+w.due+'</td>'
              +'</tr>';
          }).join('');

          pageContainer.innerHTML =
            '<div class="header-section"><div>'
            +'<h1 class="page-title">AI ìž‘ì—…ì•ˆì „ê´€ë¦¬</h1>'
            +'<p class="page-subtitle">ìž‘ì—…ë‚´ìš© ìž…ë ¥ â†’ ì•ˆì „ ìž‘ì—… ê³„íšì„œ ìƒì„± â†’ TBM/ì„œëª… â†’ ìž‘ì—… ë§ˆê° â†’ AI ê³µì •ìœ¨ ì¶”ì²œ</p>'
            +'</div><div class="action-row">'
            +'<button class="btn-secondary" onclick="openMasterSheet()"><i class="ph ph-table"></i> ë§ˆìŠ¤í„° ì‹œíŠ¸</button>'
            +'<button class="btn-primary" id="safety-new-work-btn"><i class="ph ph-plus"></i> ì˜¤ëŠ˜ ìž‘ì—… ë“±ë¡</button>'
            +'</div></div>'

            +'<div class="kpi-row" style="grid-template-columns:repeat(5,1fr)">'
            +'<div class="kpi-card" style="border-left:3px solid var(--brand-primary)"><div class="kpi-label"><i class="ph ph-briefcase"></i> ì˜¤ëŠ˜ ì§„í–‰ ìž‘ì—…</div><div class="kpi-value" style="color:var(--brand-primary)">3</div><div class="kpi-meta">ë‹¨ê¸° í”„ë¡œì íŠ¸ ìž‘ì—… ì¹´ë“œ</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid var(--status-success)"><div class="kpi-label"><i class="ph ph-shield-check"></i> ì•ˆì „ê³„íš ìŠ¹ì¸</div><div class="kpi-value" style="color:var(--status-success)">1 / 3</div><div class="kpi-meta">PHA Â· PTP Â· TBM ì´ˆì•ˆ í¬í•¨</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid #8b5cf6"><div class="kpi-label"><i class="ph ph-users-three"></i> TBM ì™„ë£Œ</div><div class="kpi-value" style="color:#8b5cf6">1 / 3</div><div class="kpi-meta">ìž‘ì—…ìž ì„œëª… ê¸°ì¤€</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid var(--status-warning)"><div class="kpi-label"><i class="ph ph-chart-line-up"></i> ê³µì • ë°˜ì˜ ëŒ€ê¸°</div><div class="kpi-value" style="color:var(--status-warning)">1</div><div class="kpi-meta">AI ì¶”ì²œ í›„ 관리자 í™•ì •</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid var(--status-danger)"><div class="kpi-label"><i class="ph ph-warning"></i> ë¯¸ì¡°ì¹˜ ì´ìŠˆ</div><div class="kpi-value" style="color:var(--status-danger)">2</div><div class="kpi-meta">ë§ˆê° ì „ ì¡°ì¹˜ í•„ìš”</div></div>'
            +'</div>'

            +'<div class="tab-nav" id="safety-tabs">'
            +'<button class="tab-btn active" data-tab="s-today"><i class="ph ph-calendar-check"></i> ì˜¤ëŠ˜ì˜ ìž‘ì—…</button>'
            +'<button class="tab-btn" data-tab="s-ai-plan"><i class="ph ph-sparkle"></i> AI ê³„íšì„œ</button>'
            +'<button class="tab-btn" data-tab="s-tbm"><i class="ph ph-signature"></i> TBM / ì„œëª…</button>'
            +'<button class="tab-btn" data-tab="s-close"><i class="ph ph-chart-pie-slice"></i> ìž‘ì—… ë§ˆê° / ê³µì •</button>'
            +'<button class="tab-btn" data-tab="s-issues"><i class="ph ph-warning-circle"></i> ì´ìŠˆ</button>'
            +'<button class="tab-btn" data-tab="s-records"><i class="ph ph-folder-open"></i> í”„ë¡œì íŠ¸ ê¸°ë¡</button>'
            +'</div>'

            +'<div id="s-today" class="tab-content" style="display:block">'
            +'<div style="display:grid;grid-template-columns:1.45fr .9fr;gap:16px">'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-list-checks"></i> ì˜¤ëŠ˜ ìž‘ì—… íë¦„</div><button class="btn-secondary" style="padding:4px 10px;font-size:12px"><i class="ph ph-funnel"></i> í•„í„°</button></div>'
            +'<div class="panel-body"><table class="data-table"><thead><tr><th>ID</th><th>ìž‘ì—…ë‚´ìš©</th><th>ì¸ì›</th><th>ê³„íšì„œ</th><th>TBM</th><th>ë§ˆê°</th><th>ê³µì •ìœ¨</th><th>ê¸°í•œ</th></tr></thead><tbody>'+workRows+'</tbody></table></div></div>'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-clock-countdown"></i> ì˜¤ëŠ˜ í•´ì•¼ í•  ì¼</div></div>'
            +'<div class="panel-body padded">'
            +'<div style="padding:10px 0;border-bottom:1px solid var(--border-subtle)"><div style="font-weight:700;font-size:12px;color:var(--status-warning)">HFF-02 ì•ˆì „ê³„íš ê²€í† </div><div style="font-size:11px;color:var(--text-tertiary);margin-top:3px">ì»¨íŠ¸ë¡¤ íŒ¨ë„ ì•µì»¤ ì„¤ì¹˜ Â· TBM ì „ ìŠ¹ì¸ í•„ìš”</div></div>'
            +'<div style="padding:10px 0;border-bottom:1px solid var(--border-subtle)"><div style="font-weight:700;font-size:12px;color:var(--brand-primary)">LGES-AZ ìž‘ì—… ë§ˆê° ìž…ë ¥</div><div style="font-size:11px;color:var(--text-tertiary);margin-top:3px">ì™„ë£Œ ìˆ˜ëŸ‰ ìž…ë ¥ í›„ ê³µì •ìœ¨ í™•ì • ëŒ€ê¸°</div></div>'
            +'<div style="padding:10px 0"><div style="font-weight:700;font-size:12px;color:var(--status-danger)">ë¯¸ì¡°ì¹˜ 2ê±´ í™•ì¸</div><div style="font-size:11px;color:var(--text-tertiary);margin-top:3px">ìžìž¬ ë¶€ì¡± Â· ì‚¬ë‹¤ë¦¬ ê³ ì •ìƒíƒœ ìž¬ì ê²€</div></div>'
            +'</div></div></div></div>'

            +'<div id="s-ai-plan" class="tab-content" style="display:none">'
            +'<div style="display:grid;grid-template-columns:420px 1fr;gap:16px">'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-pencil-simple-line"></i> ìž‘ì—…ë‚´ìš© ìž…ë ¥</div></div><div class="panel-body padded">'
            +'<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:6px">í”„ë¡œì íŠ¸ / ìž¥ì†Œ</label><input id="ai-project-input" class="search-inline" style="width:100%;margin-bottom:10px" value="LGES-AZ ì˜¤í”¼ìŠ¤ ì „ê¸° / 2ì¸µ ì‚¬ë¬´ì‹¤">'
            +'<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:6px">ìž‘ì—…ë‚´ìš©</label><textarea id="ai-work-input" style="width:100%;height:138px;background:var(--bg-base);border:1px solid var(--border-subtle);border-radius:6px;color:var(--text-primary);font-family:var(--font-base);font-size:12px;padding:10px;resize:vertical">ì²œìž¥ ì „ê¸° ë°°ì„  ì •ë¦¬ ë° ì‹ ê·œ ì¼€ì´ë¸” í¬ì„¤. ìž‘ì—…ìžëŠ” 3ëª…ì´ê³  ì‚¬ë‹¤ë¦¬ë¥¼ ì‚¬ìš©í•©ë‹ˆë‹¤. ì˜ˆì • ìž‘ì—…ëŸ‰ì€ 30mìž…ë‹ˆë‹¤.</textarea>'
            +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px"><input id="ai-qty-input" class="search-inline" style="width:100%" value="30"><select id="ai-unit-input" class="search-inline" style="width:100%"><option>m</option><option>ea</option><option>%</option><option>ë‹¨ê³„</option></select></div>'
            +'<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px"><span class="tag">ê³ ì†Œìž‘ì—…</span><span class="tag">ì „ê¸°ìž‘ì—…</span><span class="tag">ì‚¬ë‹¤ë¦¬</span><span class="tag">PPE</span></div>'
            +'<button class="btn-primary" id="ai-generate-plan" style="width:100%;margin-top:14px"><i class="ph ph-sparkle"></i> ì•ˆì „ ìž‘ì—… ê³„íšì„œ ìƒì„±</button>'
            +'</div></div>'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-file-text"></i> AI ìƒì„± ì´ˆì•ˆ</div><span style="font-size:10px;color:var(--status-warning);font-weight:700">í˜„ìž¥ ì±…ìž„ìž í™•ì¸ í•„ìš”</span></div>'
            +'<div class="panel-body padded" id="ai-plan-preview"></div></div>'
            +'</div></div>'

            +'<div id="s-tbm" class="tab-content" style="display:none">'
            +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-megaphone"></i> TBM ì§„í–‰ ë‚´ìš©</div><span>'+safetyStatus('ì™„ë£Œ')+'</span></div><div class="panel-body padded">'
            +'<div style="font-size:13px;font-weight:700;margin-bottom:8px">ì˜¤ëŠ˜ ìž‘ì—…ì€ ì²œìž¥ ì „ê¸° ë°°ì„  ì •ë¦¬ ë° ì‹ ê·œ ì¼€ì´ë¸” í¬ì„¤ìž…ë‹ˆë‹¤.</div>'
            +'<div style="font-size:12px;color:var(--text-secondary);line-height:1.7">ì£¼ìš” ìœ„í—˜ì€ ê°ì „, ì‚¬ë‹¤ë¦¬ ì¶”ë½, ì²œìž¥ ë‚´ë¶€ ì´ë¬¼ì§ˆ ë‚™í•˜ìž…ë‹ˆë‹¤. ìž‘ì—… ì „ ì „ì› ì°¨ë‹¨ê³¼ ë¬´ì „ì•• í™•ì¸ì„ ë¨¼ì € ì§„í–‰í•˜ê³ , ì‚¬ë‹¤ë¦¬ëŠ” í”ë“¤ë¦¼ ì—†ì´ ì„¤ì¹˜í•©ë‹ˆë‹¤. ì´ìƒ ìƒí™© ë°œìƒ ì‹œ ì¦‰ì‹œ ìž‘ì—…ì„ ì¤‘ì§€í•˜ê³  í˜„ìž¥ ì±…ìž„ìžì—ê²Œ ë³´ê³ í•©ë‹ˆë‹¤.</div>'
            +'<div style="display:flex;gap:8px;margin-top:14px"><span class="tag">ì•ˆì „ëª¨</span><span class="tag">ë³´ì•ˆê²½</span><span class="tag">ì ˆì—°ìž¥ê°‘</span><span class="tag">2ì¸ 1ì¡°</span></div>'
            +'</div></div>'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-signature"></i> ìž‘ì—…ìž ì„œëª…</div><button class="btn-secondary" style="padding:4px 10px;font-size:12px"><i class="ph ph-plus"></i> ìž‘ì—…ìž ì¶”ê°€</button></div><div class="panel-body"><table class="data-table"><thead><tr><th>ìž‘ì—…ìž</th><th>ì—­í• </th><th>í™•ì¸ì‹œê°„</th><th>ì„œëª…</th></tr></thead><tbody><tr><td class="cell-primary">ê¹€ì² ìˆ˜</td><td>ì „ê¸°ê³µ</td><td class="cell-mono">07:42</td><td>'+safetyStatus('ì™„ë£Œ')+'</td></tr><tr><td class="cell-primary">ì´ë¯¼ì¤€</td><td>ë³´ì¡°</td><td class="cell-mono">07:43</td><td>'+safetyStatus('ì™„ë£Œ')+'</td></tr><tr><td class="cell-primary">ìž„ì„±í›ˆ</td><td>ê°ì‹œìž</td><td class="cell-mono">-</td><td>'+safetyStatus('ëŒ€ê¸°')+'</td></tr></tbody></table></div></div>'
            +'</div></div>'

            +'<div id="s-close" class="tab-content" style="display:none">'
            +'<div style="display:grid;grid-template-columns:420px 1fr;gap:16px">'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-flag-checkered"></i> ìž‘ì—… ë§ˆê° ìž…ë ¥</div></div><div class="panel-body padded">'
            +'<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:6px">ì‹¤ì œ ì™„ë£Œë‚´ìš©</label><textarea id="close-work-input" style="width:100%;height:118px;background:var(--bg-base);border:1px solid var(--border-subtle);border-radius:6px;color:var(--text-primary);font-family:var(--font-base);font-size:12px;padding:10px;resize:vertical">ì²œìž¥ ë°°ì„  18m í¬ì„¤ ì™„ë£Œ. ìžìž¬ ë¶€ì¡±ìœ¼ë¡œ ë‚˜ë¨¸ì§€ 12mëŠ” ë‚´ì¼ ì§„í–‰ ì˜ˆì •. ì²œìž¥ ë‚´ë¶€ ìž¥ì• ë¬¼ë¡œ ìž‘ì—… ì†ë„ ì§€ì—°.</textarea>'
            +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px"><input id="done-qty-input" class="search-inline" style="width:100%" value="18"><input id="total-qty-input" class="search-inline" style="width:100%" value="30"></div>'
            +'<select id="work-state-input" class="search-inline" style="width:100%;margin-top:10px"><option>ì¼ë¶€ ì™„ë£Œ</option><option>ì™„ë£Œ</option><option>ì§€ì—°</option><option>ì¤‘ë‹¨</option><option>ìž¬ìž‘ì—… í•„ìš”</option></select>'
            +'<button class="btn-primary" id="ai-progress-btn" style="width:100%;margin-top:14px"><i class="ph ph-chart-line-up"></i> AI ê³µì •ìœ¨ ë¶„ì„</button>'
            +'</div></div>'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-chart-donut"></i> ê³µì •ìœ¨ ì¶”ì²œ ë° í™•ì •</div><span style="font-size:10px;color:var(--text-tertiary)">AI ì¶”ì²œ + 관리자 í™•ì •</span></div><div class="panel-body padded" id="progress-result"></div></div>'
            +'</div></div>'

            +'<div id="s-issues" class="tab-content" style="display:none">'
            +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px"><div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-first-aid-kit"></i> ì‚¬ê³  Â· ì•„ì°¨ì‚¬ê³ </div><button class="btn-primary" style="padding:4px 10px;font-size:12px"><i class="ph ph-plus"></i> ë“±ë¡</button></div><div class="panel-body"><table class="data-table"><thead><tr><th>ìœ í˜•</th><th>í”„ë¡œì íŠ¸</th><th>ë‚´ìš©</th><th>ìƒíƒœ</th></tr></thead><tbody><tr><td>ìœ„í—˜ìƒí™©</td><td>HFF-02</td><td class="cell-primary">ì¼€ì´ë¸” íŠ¸ë ˆì´ ëª¨ì„œë¦¬ ë‚ ì¹´ë¡œì›€</td><td>'+safetyStatus('ì¡°ì¹˜ì¤‘')+'</td></tr><tr><td>ì•„ì°¨ì‚¬ê³ </td><td>SST-03</td><td class="cell-primary">ë°°ê´€ ìžìž¬ ì´ë™ ì¤‘ í†µë¡œ í˜‘ì†Œ</td><td>'+safetyStatus('ì™„ë£Œ')+'</td></tr></tbody></table></div></div><div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-wrench"></i> ë¯¸ì¡°ì¹˜ ì‚¬í•­</div></div><div class="panel-body"><table class="data-table"><thead><tr><th>ì´ìŠˆ</th><th>ë‹´ë‹¹</th><th>ë§ˆê°</th><th>ìƒíƒœ</th></tr></thead><tbody><tr><td class="cell-primary">ìžìž¬ ë¶€ì¡±ìœ¼ë¡œ ìž”ì—¬ 12m ëŒ€ê¸°</td><td>êµ¬ë§¤íŒ€</td><td>ì˜¤ëŠ˜</td><td>'+safetyStatus('ì¡°ì¹˜ì¤‘')+'</td></tr><tr><td class="cell-primary">ì‚¬ë‹¤ë¦¬ í•˜ë‹¨ ë¯¸ë„ëŸ¼ ë°©ì§€íŒ¨ë“œ êµì²´</td><td>ë°•ì†Œìž¥</td><td>ë‚´ì¼</td><td>'+safetyStatus('ëŒ€ê¸°')+'</td></tr></tbody></table></div></div></div>'
            +'</div>'

            +'<div id="s-records" class="tab-content" style="display:none">'
            +'<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-archive"></i> í”„ë¡œì íŠ¸ ì•ˆì „Â·ìž‘ì—… ê¸°ë¡</div><button class="btn-secondary" style="padding:4px 10px;font-size:12px"><i class="ph ph-file-pdf"></i> PDF ì¶œë ¥</button></div><div class="panel-body"><table class="data-table"><thead><tr><th>í”„ë¡œì íŠ¸</th><th>ì•ˆì „ê³„íš</th><th>TBM</th><th>ìž‘ì—…ë§ˆê°</th><th>ê³µì • ì´ë ¥</th><th>ë¦¬í¬íŠ¸</th></tr></thead><tbody><tr><td class="cell-primary">LGES-AZ ì˜¤í”¼ìŠ¤ ì „ê¸°</td><td>3ê±´</td><td>3íšŒ / ì„œëª… 8ëª…</td><td>2ê±´</td><td>35% â†’ 60%</td><td><button class="icon-btn"><i class="ph ph-download-simple"></i></button></td></tr><tr><td class="cell-primary">HFF-02 ìž¥ë¹„ ì„¤ì¹˜</td><td>1ê±´</td><td>ëŒ€ê¸°</td><td>-</td><td>35%</td><td><button class="icon-btn"><i class="ph ph-download-simple"></i></button></td></tr></tbody></table></div></div>'
            +'</div>';

          function buildPlanPreview() {
            var project = document.getElementById('ai-project-input').value || 'ë¯¸ì§€ì • í”„ë¡œì íŠ¸';
            var work = document.getElementById('ai-work-input').value || 'ìž‘ì—…ë‚´ìš© ë¯¸ìž…ë ¥';
            var qty = document.getElementById('ai-qty-input').value || '0';
            var unit = document.getElementById('ai-unit-input').value || '';
            document.getElementById('ai-plan-preview').innerHTML =
              '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px">'
              +'<div style="padding:10px;background:var(--bg-subtle);border-radius:8px"><div style="font-size:10px;color:var(--text-tertiary)">ìž‘ì—…ëª…</div><div style="font-weight:700;font-size:12px;margin-top:4px">'+project+'</div></div>'
              +'<div style="padding:10px;background:var(--bg-subtle);border-radius:8px"><div style="font-size:10px;color:var(--text-tertiary)">ì˜ˆì • ìž‘ì—…ëŸ‰</div><div style="font-weight:700;font-size:12px;margin-top:4px">'+qty+' '+unit+'</div></div>'
              +'<div style="padding:10px;background:rgba(245,158,11,.08);border-radius:8px"><div style="font-size:10px;color:var(--status-warning)">ìŠ¹ì¸ ìƒíƒœ</div><div style="font-weight:700;font-size:12px;margin-top:4px;color:var(--status-warning)">ì´ˆì•ˆ</div></div>'
              +'</div>'
              +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
              +'<div><div style="font-size:12px;font-weight:700;margin-bottom:6px;color:var(--brand-primary)">PHA ìœ„í—˜ì„± ë¶„ì„</div><ul style="margin:0;padding-left:18px;font-size:12px;line-height:1.8;color:var(--text-secondary)"><li>ê°ì „ ë° ì „ê¸° ì ‘ì´‰ ìœ„í—˜</li><li>ì‚¬ë‹¤ë¦¬ ì‚¬ìš© ì¤‘ ì¶”ë½ ìœ„í—˜</li><li>ì²œìž¥ ë‚´ë¶€ ì´ë¬¼ì§ˆ ë‚™í•˜ ìœ„í—˜</li><li>ë¬´ë¦¬í•œ ìžì„¸ë¡œ ì¸í•œ ê·¼ê³¨ê²©ê³„ ë¶€ë‹´</li></ul></div>'
              +'<div><div style="font-size:12px;font-weight:700;margin-bottom:6px;color:var(--status-success)">PTP ìž‘ì—… ì „ ê³„íš</div><ol style="margin:0;padding-left:18px;font-size:12px;line-height:1.8;color:var(--text-secondary)"><li>ìž‘ì—…êµ¬ì—­ ì„¤ì • ë° ì¶œìž… í†µì œ</li><li>ì „ì› ì°¨ë‹¨ ë° ë¬´ì „ì•• í™•ì¸</li><li>ì‚¬ë‹¤ë¦¬ ì ê²€ ë° 2ì¸ 1ì¡° ë°°ì¹˜</li><li>ë°°ì„  ì •ë¦¬, ì‹ ê·œ ì¼€ì´ë¸” í¬ì„¤</li><li>ì •ë¦¬ì •ëˆ ë° ì™„ë£Œ ì‚¬ì§„ ê¸°ë¡</li></ol></div>'
              +'</div><div style="margin-top:14px;padding:12px;background:var(--bg-subtle);border-radius:8px;font-size:12px;color:var(--text-secondary);line-height:1.7"><b style="color:var(--text-primary)">TBM ë©˜íŠ¸:</b> ì˜¤ëŠ˜ ìž‘ì—…ì€ '+work+' ì£¼ìš” ìœ„í—˜ì€ ê°ì „ê³¼ ì¶”ë½ìž…ë‹ˆë‹¤. ì „ì› ì°¨ë‹¨ í™•ì¸ ì „ ìž‘ì—…í•˜ì§€ ì•Šê³ , ì´ìƒ ìƒí™© ë°œìƒ ì‹œ ì¦‰ì‹œ ìž‘ì—…ì„ ì¤‘ì§€í•©ë‹ˆë‹¤.</div>'
              +'<div style="display:flex;gap:8px;margin-top:14px"><button class="btn-primary"><i class="ph ph-check-circle"></i> í˜„ìž¥ ì±…ìž„ìž ìŠ¹ì¸</button><button class="btn-secondary"><i class="ph ph-pencil"></i> ìˆ˜ì •</button></div>';
          }

          function buildProgressResult() {
            var done = parseFloat(document.getElementById('done-qty-input').value || '0');
            var total = parseFloat(document.getElementById('total-qty-input').value || '0');
            var rate = total > 0 ? Math.round(done / total * 100) : 0;
            var remain = Math.max(total - done, 0);
            document.getElementById('progress-result').innerHTML =
              '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">'
              +'<div style="padding:10px;background:var(--bg-subtle);border-radius:8px"><div style="font-size:10px;color:var(--text-tertiary)">ì˜ˆì •</div><div style="font-size:20px;font-weight:700">'+total+'</div></div>'
              +'<div style="padding:10px;background:rgba(16,185,129,.08);border-radius:8px"><div style="font-size:10px;color:var(--status-success)">ì™„ë£Œ</div><div style="font-size:20px;font-weight:700;color:var(--status-success)">'+done+'</div></div>'
              +'<div style="padding:10px;background:rgba(245,158,11,.08);border-radius:8px"><div style="font-size:10px;color:var(--status-warning)">ìž”ì—¬</div><div style="font-size:20px;font-weight:700;color:var(--status-warning)">'+remain+'</div></div>'
              +'<div style="padding:10px;background:rgba(37,99,235,.08);border-radius:8px"><div style="font-size:10px;color:var(--brand-primary)">AI ì¶”ì²œ</div><div style="font-size:20px;font-weight:700;color:var(--brand-primary)">'+rate+'%</div></div>'
              +'</div>'+progressBar(rate)
              +'<div style="margin-top:14px;padding:12px;background:var(--bg-subtle);border-radius:8px;font-size:12px;color:var(--text-secondary);line-height:1.7">AI ë¶„ì„: ì‹¤ì œ ì™„ë£Œë‚´ìš© ê¸°ì¤€ìœ¼ë¡œ '+rate+'% ë°˜ì˜ì„ ì¶”ì²œí•©ë‹ˆë‹¤. ì§€ì—° ì‚¬ìœ ëŠ” ìžìž¬ ë¶€ì¡±ê³¼ ì²œìž¥ ë‚´ë¶€ ìž¥ì• ë¬¼ë¡œ ìš”ì•½ë©ë‹ˆë‹¤. ìž”ì—¬ '+remain+'ì€ ë‹¤ìŒ ìž‘ì—…ìœ¼ë¡œ ìžë™ ìƒì„±í•  ìˆ˜ ìžˆìŠµë‹ˆë‹¤.</div>'
              +'<div style="display:flex;align-items:center;gap:8px;margin-top:14px"><input class="search-inline" style="width:90px" value="'+rate+'%"><button class="btn-primary"><i class="ph ph-check-circle"></i> ê³µì •ìœ¨ í™•ì • ë°˜ì˜</button><button class="btn-secondary"><i class="ph ph-arrow-clockwise"></i> ë‹¤ìŒ ìž‘ì—… ìƒì„±</button></div>';
          }

          document.querySelectorAll('#safety-tabs .tab-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
              document.querySelectorAll('#safety-tabs .tab-btn').forEach(function(b){ b.classList.remove('active'); });
              btn.classList.add('active');
              document.querySelectorAll('#page-container .tab-content').forEach(function(c){ c.style.display='none'; });
              document.getElementById(btn.getAttribute('data-tab')).style.display='block';
            });
          });
          document.getElementById('ai-generate-plan').addEventListener('click', buildPlanPreview);
          document.getElementById('ai-progress-btn').addEventListener('click', buildProgressResult);
          document.getElementById('safety-new-work-btn').addEventListener('click', function(){
            document.querySelector('#safety-tabs [data-tab="s-ai-plan"]').click();
            document.getElementById('ai-work-input').focus();
          });
          buildPlanPreview();
          buildProgressResult();
          return;

          var [stats, ptwList, ptwStats, inspections, inspStats, trainings, safetyDocs,
               oshaLog, osha300A, certMatrix, violations, tbmRecords] = await Promise.all([
            window.API.getSafetyStats(),
            window.API.getPtwList(),
            window.API.getPtwStats(),
            window.API.getInspections(),
            window.API.getInspectionStats(),
            window.API.getTrainingRecords(),
            window.API.getSafetyDocs(),
            window.API.getOshaForm300(),
            window.API.getOsha300AStats(),
            window.API.getCertMatrix(),
            window.API.getViolations(),
            window.API.getTbmRecords()
          ]);

          if (alertBadge) alertBadge.textContent = stats.unresolved || 0;

          var ptwColorMap = {'í™”ê¸°ìž‘ì—…':'#ef4444','ê³ ì†Œìž‘ì—…':'#f97316','ë°€íê³µê°„':'#8b5cf6','ì¤‘ëŸ‰ë¬¼':'#eab308','êµ´ì°©ìž‘ì—…':'#3b82f6'};
          var ptwIconMap  = {'í™”ê¸°ìž‘ì—…':'ph-fire','ê³ ì†Œìž‘ì—…':'ph-ladder','ë°€íê³µê°„':'ph-shield-warning','ì¤‘ëŸ‰ë¬¼':'ph-crane-tower','êµ´ì°©ìž‘ì—…':'ph-shovel'};
          var certStatusColor = {'ìœ íš¨':'var(--status-success)','ë§Œë£Œìž„ë°•':'var(--status-warning)','ë§Œë£Œ':'var(--status-danger)'};
          var certStatusBg    = {'ìœ íš¨':'rgba(16,185,129,.12)','ë§Œë£Œìž„ë°•':'rgba(245,158,11,.12)','ë§Œë£Œ':'rgba(239,68,68,.12)'};

          function daysUntil(d){ return d ? Math.ceil((new Date(d)-new Date())/86400000) : 9999; }

          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          // TAB 1 â€” OVERVIEW
          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          var expiredCerts  = (certMatrix||[]).flatMap(function(p){ return p.certs.filter(function(c){ return c.status==='ë§Œë£Œ'; }); }).length;
          var expiringSoon  = (certMatrix||[]).flatMap(function(p){ return p.certs.filter(function(c){ return c.status==='ë§Œë£Œìž„ë°•'; }); }).length;
          var openViolations= (violations||[]).filter(function(v){ return !v.completedDate; }).length;
          var trir = osha300A.trir || '0.00';
          var dart = osha300A.dartRate || '0.00';

          var todayPtwCards = (ptwList||[]).filter(function(p){ return p.status==='ì§„í–‰ì¤‘'||p.status==='ìŠ¹ì¸ëŒ€ê¸°'; });
          var todayPtwHtml = todayPtwCards.length===0
            ? '<div style="padding:20px;text-align:center;color:var(--text-tertiary)"><i class="ph ph-check-circle" style="font-size:28px;display:block;margin-bottom:6px;color:var(--status-success)"></i>ì˜¤ëŠ˜ í™œì„± ê³ ìœ„í—˜ ìž‘ì—… ì—†ìŒ</div>'
            : todayPtwCards.map(function(p){
                var c=ptwColorMap[p.type]||'#64748b';
                return '<div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border-subtle)">'
                  +'<div style="width:34px;height:34px;border-radius:8px;background:'+c+'20;display:flex;align-items:center;justify-content:center;flex-shrink:0">'
                  +'<i class="ph '+(ptwIconMap[p.type]||'ph-clipboard-text')+'" style="font-size:16px;color:'+c+'"></i></div>'
                  +'<div style="flex:1;min-width:0"><div style="font-weight:600;font-size:12px">'+p.title+'</div>'
                  +'<div style="font-size:10px;color:var(--text-tertiary);margin-top:2px">'
                  +'<span style="background:'+c+'20;color:'+c+';padding:1px 6px;border-radius:6px;font-weight:700;font-size:10px;margin-right:5px">'+p.type+'</span>'
                  +p.zone+' | '+p.date+'</div></div>'
                  +(p.tbmDone?'<span style="color:var(--status-success);font-size:10px;font-weight:700">TBM âœ“</span>':'<span style="background:rgba(239,68,68,.1);color:var(--status-danger);font-size:10px;font-weight:700;padding:2px 6px;border-radius:6px">TBM ë¯¸ì™„</span>')
                  +'</div>';
              }).join('');

          var overviewHtml =
            '<div class="kpi-row" style="grid-template-columns:repeat(5,1fr)">'
            +'<div class="kpi-card" style="border-left:3px solid var(--status-success)"><div class="kpi-label"><i class="ph ph-trophy" style="color:var(--status-success)"></i> ë¬´ì‚¬ê³  ì¼ìˆ˜</div><div class="kpi-value" style="color:var(--status-success)">'+(stats.daysNoIncident||0)+'<small style="font-size:11px;font-weight:400;color:var(--text-tertiary)"> ì¼</small></div><div class="kpi-meta">ë§ˆì§€ë§‰ ì‚¬ê³ : '+(stats.lastIncidentDate||'-')+'</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid #3b82f6"><div class="kpi-label"><i class="ph ph-chart-line-up" style="color:#3b82f6"></i> TRIR</div><div class="kpi-value" style="color:#3b82f6">'+trir+'</div><div class="kpi-meta">ì´ ì‚¬ê³ ìœ¨ (Ã—200k hrs)</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid #8b5cf6"><div class="kpi-label"><i class="ph ph-chart-bar" style="color:#8b5cf6"></i> DART Rate</div><div class="kpi-value" style="color:#8b5cf6">'+dart+'</div><div class="kpi-meta">ê²°ê·¼+ì œí•œ ì‚¬ê³ ìœ¨</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid var(--status-danger)"><div class="kpi-label"><i class="ph ph-certificate" style="color:var(--status-danger)"></i> ìžê²©ì¦ ì´ìŠˆ</div><div class="kpi-value" style="color:var(--status-danger)">'+(expiredCerts+expiringSoon)+'</div><div class="kpi-meta">ë§Œë£Œ '+expiredCerts+' / ìž„ë°• '+expiringSoon+'</div></div>'
            +'<div class="kpi-card" style="border-left:3px solid #f97316"><div class="kpi-label"><i class="ph ph-warning" style="color:#f97316"></i> ë¯¸ê²° ìœ„ë°˜</div><div class="kpi-value" style="color:#f97316">'+openViolations+'</div><div class="kpi-meta">ì‹œì • ì¡°ì¹˜ í•„ìš”</div></div>'
            +'</div>'
            +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-hard-hat"></i> ì˜¤ëŠ˜ í™œì„± ê³ ìœ„í—˜ ìž‘ì—… (PTW)</div></div>'
            +'<div class="panel-body padded">'+todayPtwHtml+'</div></div>'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-file-text"></i> OSHA 300A í˜„í™© ('+osha300A.year+')</div>'
            +'<span style="font-size:10px;padding:2px 8px;border-radius:8px;background:rgba(59,130,246,.12);color:#3b82f6;font-weight:700">ê²Œì‹œ: '+(osha300A.postingStart||'2/1')+' ~ '+(osha300A.postingEnd||'4/30')+'</span>'
            +'</div><div class="panel-body padded">'
            +'<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px">'
            +'<div style="text-align:center;padding:10px;background:var(--bg-subtle);border-radius:8px"><div style="font-size:10px;color:var(--text-tertiary)">ì´ ê¸°ë¡</div><div style="font-size:22px;font-weight:700">'+(osha300A.totalCases||0)+'</div></div>'
            +'<div style="text-align:center;padding:10px;background:rgba(239,68,68,.07);border-radius:8px"><div style="font-size:10px;color:var(--status-danger)">ì‚¬ë§</div><div style="font-size:22px;font-weight:700;color:var(--status-danger)">'+(osha300A.deathCases||0)+'</div></div>'
            +'<div style="text-align:center;padding:10px;background:rgba(245,158,11,.07);border-radius:8px"><div style="font-size:10px;color:var(--status-warning)">ê²°ê·¼/ì œí•œ</div><div style="font-size:22px;font-weight:700;color:var(--status-warning)">'+(parseInt(osha300A.daysAwayCases||0)+parseInt(osha300A.restrictedCases||0))+'</div></div>'
            +'</div>'
            +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">'
            +'<div style="padding:8px 12px;background:var(--bg-subtle);border-radius:6px;font-size:12px"><span style="color:var(--text-tertiary)">TRIR</span><span style="float:right;font-weight:700;color:#3b82f6">'+trir+'</span></div>'
            +'<div style="padding:8px 12px;background:var(--bg-subtle);border-radius:6px;font-size:12px"><span style="color:var(--text-tertiary)">DART</span><span style="float:right;font-weight:700;color:#8b5cf6">'+dart+'</span></div>'
            +'<div style="padding:8px 12px;background:var(--bg-subtle);border-radius:6px;font-size:12px"><span style="color:var(--text-tertiary)">ì´ ê·¼ë¡œì‹œê°„</span><span style="float:right;font-weight:700">'+(osha300A.totalHoursWorked||0).toLocaleString()+'</span></div>'
            +'<div style="padding:8px 12px;background:var(--bg-subtle);border-radius:6px;font-size:12px"><span style="color:var(--text-tertiary)">í‰ê·  ê³ ìš©ì¸ì›</span><span style="float:right;font-weight:700">'+(osha300A.averageEmployees||0)+'ëª…</span></div>'
            +'</div></div></div>'
            +'</div>';

          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          // TAB 2 â€” OSHA ê¸°ë¡
          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          var clsMap = {death:'ì‚¬ë§',days_away:'ê²°ê·¼',restricted:'ì—…ë¬´ì œí•œ',other_recordable:'ê¸°íƒ€ê¸°ë¡'};
          var clsColor= {death:'var(--status-danger)',days_away:'#f97316',restricted:'var(--status-warning)',other_recordable:'var(--text-secondary)'};
          var form300Rows = (oshaLog||[]).map(function(r){
            var cls = r.classification||'other_recordable';
            return '<tr><td class="cell-mono">'+r.caseNo+'</td><td>'+r.name+'</td>'
              +'<td style="font-size:11px;color:var(--text-tertiary)">'+r.title+'</td>'
              +'<td class="cell-mono">'+r.dateOfInjury+'</td>'
              +'<td><span style="background:rgba(59,130,246,.1);color:#3b82f6;padding:1px 7px;border-radius:6px;font-size:11px">'+r.zone+'</span></td>'
              +'<td style="font-size:12px;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="'+r.description+'">'+r.description+'</td>'
              +'<td><span style="color:'+clsColor[cls]+';font-weight:700;font-size:11px">'+(clsMap[cls]||cls)+'</span></td>'
              +'<td class="cell-mono" style="text-align:center">'+(r.daysAway||0)+'</td>'
              +'<td class="cell-mono" style="text-align:center">'+(r.restricted||0)+'</td>'
              +'<td><button class="icon-btn"><i class="ph ph-file-magnifying-glass"></i></button></td></tr>';
          }).join('');

          var oshaHtml =
            '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px">'
            +[['Form 301','ph-file-plus','ì‚¬ê³ ë³„ ìƒì„¸ ë³´ê³ ì„œ','êµ¬ê¸€í¼ìœ¼ë¡œ ìž‘ì„± â†’'],
              ['Form 300','ph-file-text','ì—°ê°„ ì‚¬ê³  ëŒ€ìž¥',oshaLog.length+'ê±´ ê¸°ë¡ë¨'],
              ['Form 300A','ph-file-arrow-up','ì—°ê°„ ìš”ì•½í‘œ','TRIR: '+trir+' / DART: '+dart]].map(function(p){
                return '<div class="panel" style="margin:0"><div class="panel-header" style="padding:10px 14px"><div class="panel-title" style="font-size:13px"><i class="ph '+p[1]+'"></i> '+p[0]+'</div></div>'
                  +'<div class="panel-body padded" style="font-size:12px;color:var(--text-secondary)">'+p[2]+'<br><span style="font-size:11px;color:var(--brand-primary)">'+p[3]+'</span></div></div>';
              }).join('')+'</div>'
            +'<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-list-bullets"></i> OSHA Form 300 â€” ì—°ê°„ ì‚¬ê³  ëŒ€ìž¥</div>'
            +'<button class="btn-primary" style="padding:4px 12px;font-size:12px"><i class="ph ph-plus"></i> Form 301 ì‹ ê·œ ìž‘ì„±</button></div>'
            +'<div class="panel-body"><table class="data-table"><thead><tr>'
            +'<th>Case No.</th><th>ì´ë¦„</th><th>ì§ì¢…</th><th>ì‚¬ê³ ì¼</th><th>Zone</th><th>ì‚¬ê³  ë‚´ìš©</th><th>ë¶„ë¥˜</th><th>ê²°ê·¼</th><th>ì œí•œ</th><th>ìƒì„¸</th>'
            +'</tr></thead><tbody>'+form300Rows
            +(oshaLog.length===0?'<tr><td colspan="10" style="text-align:center;padding:24px;color:var(--text-tertiary)"><i class="ph ph-shield-check" style="font-size:24px;color:var(--status-success);display:block;margin-bottom:6px"></i>ì˜¬í•´ ê¸°ë¡ëœ OSHA ì‚¬ê³  ì—†ìŒ</td></tr>':'')
            +'</tbody></table></div></div>';

          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          // TAB 3 â€” CERT MATRIX
          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          var allCertTypes = [];
          (certMatrix||[]).forEach(function(p){ p.certs.forEach(function(c){ if(allCertTypes.indexOf(c.type)===-1) allCertTypes.push(c.type); }); });
          var matrixHeader = '<thead><tr><th style="min-width:130px;position:sticky;left:0;background:var(--bg-elevated)">ì´ë¦„ / ì†Œì†</th>'
            +allCertTypes.map(function(t){ return '<th style="min-width:80px;font-size:10px;white-space:normal;text-align:center;line-height:1.3">'+t+'</th>'; }).join('')+'</tr></thead>';
          var matrixRows = (certMatrix||[]).map(function(p){
            var hasIssue = p.certs.some(function(c){ return c.status!=='ìœ íš¨'; });
            var certMap  = {}; p.certs.forEach(function(c){ certMap[c.type]=c; });
            return '<tr style="'+(hasIssue?'background:rgba(239,68,68,.025)':'')+'">'
              +'<td style="position:sticky;left:0;background:var(--bg-elevated)"><div style="font-weight:600;font-size:12px">'+p.nameKr+'</div><div style="font-size:10px;color:var(--text-tertiary)">'+p.role+' Â· '+p.company+'</div></td>'
              +allCertTypes.map(function(ct){
                  var cert=certMap[ct];
                  if(!cert) return '<td style="text-align:center;color:var(--border-color)">â€”</td>';
                  var d=daysUntil(cert.expiry);
                  return '<td style="text-align:center;padding:8px 4px">'
                    +'<div style="font-size:15px">'+(cert.status==='ìœ íš¨'?'<span style="color:var(--status-success)">âœ“</span>':cert.status==='ë§Œë£Œìž„ë°•'?'<span style="color:var(--status-warning)">âš </span>':'<span style="color:var(--status-danger)">âœ—</span>')+'</div>'
                    +'<div style="font-size:9px;color:var(--text-tertiary);margin-top:1px">'+(cert.status==='ë§Œë£Œ'?'ë§Œë£Œ':d>0?'D-'+d:'ë§Œë£Œ')+'</div>'
                    +(cert.hoffmanReq?'<div style="font-size:8px;color:#3b82f6;font-weight:700">í•„ìˆ˜</div>':'')
                    +'</td>';
                }).join('')+'</tr>';
          }).join('');

          var certHtml =
            '<div style="margin-bottom:12px;padding:10px 14px;background:rgba(37,99,235,.07);border:1px solid rgba(37,99,235,.15);border-radius:8px;font-size:12px;display:flex;gap:20px;align-items:center">'
            +'<span style="font-weight:600">ë²”ë¡€:</span>'
            +'<span><span style="color:var(--status-success);font-size:15px">âœ“</span> ìœ íš¨</span>'
            +'<span><span style="color:var(--status-warning);font-size:15px">âš </span> ë§Œë£Œìž„ë°• (30ì¼ ì´ë‚´)</span>'
            +'<span><span style="color:var(--status-danger);font-size:15px">âœ—</span> ë§Œë£Œ</span>'
            +'<span style="margin-left:auto;color:#3b82f6;font-weight:700;border:1px solid #3b82f6;padding:2px 8px;border-radius:6px;font-size:11px">í•„ìˆ˜ = Hoffman ìš”êµ¬ ìžê²©</span>'
            +'</div>'
            +'<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-certificate"></i> ìžê²©ì¦ ë§¤íŠ¸ë¦­ìŠ¤ (Certification Matrix)</div>'
            +'<div style="display:flex;gap:8px">'
            +'<span style="padding:4px 10px;background:rgba(239,68,68,.1);color:var(--status-danger);border-radius:8px;font-size:11px;font-weight:700">ë§Œë£Œ '+expiredCerts+'ê±´</span>'
            +'<span style="padding:4px 10px;background:rgba(245,158,11,.1);color:var(--status-warning);border-radius:8px;font-size:11px;font-weight:700">ìž„ë°• '+expiringSoon+'ê±´</span>'
            +'</div></div>'
            +'<div class="panel-body" style="overflow-x:auto"><table class="data-table" style="min-width:900px">'+matrixHeader+'<tbody>'+matrixRows+'</tbody></table></div></div>';

          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          // TAB 4 â€” PTW (ìž‘ì—…í—ˆê°€ì„œë§Œ)
          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          var ptwRows = (ptwList||[]).map(function(p){
            var col=ptwColorMap[p.type]||'#64748b';
            var ico=ptwIconMap[p.type]||'ph-clipboard-text';
            var tbm=p.tbmDone?'<span style="color:var(--status-success);font-weight:700">âœ“</span>':'<span style="color:var(--status-danger);font-weight:700">âœ—</span>';
            return '<tr style="cursor:pointer" onclick="window._openPtwDetail(\''+p.id+'\',window.ptwCache)">'
              +'<td class="cell-mono">'+p.id+'</td>'
              +'<td><span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:'+col+'20;color:'+col+'">'
              +'<i class="ph '+ico+'"></i>'+p.type+'</span></td>'
              +'<td class="cell-primary">'+p.title+'</td>'
              +'<td>'+p.zone+'</td>'
              +'<td class="cell-mono">'+p.date+'</td>'
              +'<td>'+p.applicant+' <span style="font-size:10px;color:var(--text-tertiary)">('+p.company+')</span></td>'
              +'<td style="text-align:center">'+tbm+'</td>'
              +'<td>'+statusPill(p.status)+'</td>'
              +'</tr>';
          }).join('');
          window.ptwCache = ptwList;

          var ptwTabHtml =
            '<div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">'
            +'<div class="kpi-card"><div class="kpi-label">ì§„í–‰ì¤‘</div><div class="kpi-value" style="color:var(--status-success)">'+(ptwStats.todayActive||0)+'</div></div>'
            +'<div class="kpi-card"><div class="kpi-label">ìŠ¹ì¸ ëŒ€ê¸°</div><div class="kpi-value" style="color:var(--status-warning)">'+(ptwStats.pending||0)+'</div></div>'
            +'<div class="kpi-card"><div class="kpi-label">ë°˜ë ¤</div><div class="kpi-value" style="color:var(--status-danger)">'+(ptwStats.rejected||0)+'</div></div>'
            +'<div class="kpi-card"><div class="kpi-label">ì™„ë£Œ</div><div class="kpi-value" style="color:var(--brand-primary)">'+(ptwStats.completed||0)+'</div></div>'
            +'</div>'
            +'<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-clipboard-text"></i> ìž‘ì—…í—ˆê°€ì„œ (PTW) ëª©ë¡</div>'
            +'<div style="display:flex;gap:6px;align-items:center">'
            +'<select id="ptw-filter" style="background:var(--bg-elevated);border:1px solid var(--border-color);color:var(--text-primary);padding:4px 8px;border-radius:6px;font-size:12px">'
            +'<option value="all">ì „ì²´ ìœ í˜•</option><option value="í™”ê¸°ìž‘ì—…">ðŸ”´ í™”ê¸°ìž‘ì—…</option><option value="ê³ ì†Œìž‘ì—…">ðŸŸ  ê³ ì†Œìž‘ì—…</option><option value="ë°€íê³µê°„">ðŸŸ£ ë°€íê³µê°„</option><option value="ì¤‘ëŸ‰ë¬¼">ðŸŸ¡ ì¤‘ëŸ‰ë¬¼</option><option value="êµ´ì°©ìž‘ì—…">ðŸ”µ êµ´ì°©ìž‘ì—…</option>'
            +'</select><button class="btn-primary" style="padding:4px 12px;font-size:12px"><i class="ph ph-plus"></i> ì‹ ê·œ PTW</button>'
            +'</div></div>'
            +'<div class="panel-body"><table class="data-table" id="ptw-table"><thead><tr>'
            +'<th>ë¬¸ì„œID</th><th>ìœ í˜•</th><th>ìž‘ì—…ëª…</th><th>êµ¬ì—­</th><th>ë‚ ì§œ</th><th>ì‹ ì²­ìž</th><th>TBM</th><th>ìƒíƒœ</th>'
            +'</tr></thead><tbody>'+ptwRows+'</tbody></table></div></div>'
            +'<div id="ptw-detail-panel" style="display:none;position:fixed;right:0;top:0;width:420px;height:100vh;background:var(--bg-elevated);border-left:1px solid var(--border-color);z-index:1000;overflow-y:auto;padding:24px;box-shadow:-8px 0 32px rgba(0,0,0,.35)">'
            +'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">'
            +'<div style="font-size:15px;font-weight:700">PTW ìƒì„¸</div>'
            +'<button onclick="document.getElementById(\'ptw-detail-panel\').style.display=\'none\'" style="background:none;border:none;cursor:pointer;color:var(--text-tertiary);font-size:24px;line-height:1">&times;</button>'
            +'</div><div id="ptw-detail-content"></div></div>';

          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          // TAB 5 â€” INSPECTION / TBM
          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          var catIconMap={'ì¶”ë½ë°©ì§€':'ph-person-simple-fall','ì¤‘ìž¥ë¹„':'ph-truck','ì „ê¸°/í™”ìž¬':'ph-lightning','PPE':'ph-hard-hat'};
          var inspHtml=(inspections||[]).map(function(ins){
            var failCount=ins.items.filter(function(it){ return it.result==='fail'; }).length;
            return '<div class="panel" style="margin-bottom:12px">'
              +'<div class="panel-header"><div class="panel-title"><i class="ph '+(catIconMap[ins.category]||'ph-clipboard')+'"></i> '+ins.category+' â€” '+ins.zone+'</div>'
              +'<div style="display:flex;align-items:center;gap:10px"><span style="font-size:11px;color:var(--text-tertiary)">'+ins.date+' | '+ins.inspector+'</span>'
              +(failCount>0?'<span style="background:rgba(239,68,68,.12);color:var(--status-danger);padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700">ë¶ˆí•©ê²© '+failCount+'ê±´</span>':'<span style="background:rgba(16,185,129,.12);color:var(--status-success);padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700">ì „ì²´ í•©ê²©</span>')
              +'</div></div><div class="panel-body padded">'
              +ins.items.map(function(item){
                  var ok=item.result==='pass';
                  return '<div style="display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px solid var(--border-subtle)">'
                    +'<div style="width:20px;height:20px;border-radius:50%;background:'+(ok?'rgba(16,185,129,.12)':'rgba(239,68,68,.12)')+';display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px">'
                    +'<i class="ph '+(ok?'ph-check':'ph-x')+'" style="font-size:11px;color:'+(ok?'var(--status-success)':'var(--status-danger)')+'"></i></div>'
                    +'<div style="flex:1"><div style="font-size:12px;font-weight:500">'+item.name+'</div>'
                    +(item.note?'<div style="font-size:11px;color:var(--status-danger);margin-top:2px"><i class="ph ph-warning" style="margin-right:3px"></i>'+item.note+'</div>':'')
                    +'</div><div style="font-size:11px;font-weight:700;color:'+(ok?'var(--status-success)':'var(--status-danger)')+'">'+( ok?'í•©ê²©':'ë¶ˆí•©ê²©')+'</div></div>';
                }).join('')+'</div></div>';
          }).join('');

          var tbmRows=(tbmRecords||[]).map(function(t){
            return '<tr><td class="cell-mono">'+t.id+'</td><td class="cell-mono">'+t.date+'</td>'
              +'<td><span style="background:rgba(59,130,246,.1);color:#3b82f6;padding:1px 7px;border-radius:6px;font-size:11px">'+t.zone+'</span></td>'
              +'<td>'+t.facilitator+'</td><td class="cell-primary">'+t.topic+'</td>'
              +'<td style="text-align:center;font-weight:700;color:var(--brand-primary)">'+t.attendeeCount+'ëª…</td></tr>';
          }).join('');

          var inspTabHtml =
            '<div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">'
            +'<div class="kpi-card"><div class="kpi-label">ì´ ì ê²€ í•­ëª©</div><div class="kpi-value">'+(inspStats.totalItems||0)+'</div></div>'
            +'<div class="kpi-card"><div class="kpi-label">í•©ê²©</div><div class="kpi-value" style="color:var(--status-success)">'+(inspStats.passed||0)+'</div></div>'
            +'<div class="kpi-card"><div class="kpi-label">ë¶ˆí•©ê²©</div><div class="kpi-value" style="color:var(--status-danger)">'+(inspStats.failed||0)+'</div></div>'
            +'<div class="kpi-card"><div class="kpi-label">ì™„ë£Œìœ¨</div><div class="kpi-value" style="color:var(--brand-primary)">'+(inspStats.completionRate||0)+'%</div></div>'
            +'</div>'
            +'<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:16px">'
            +'<div>'+inspHtml+'</div>'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-users"></i> Toolbox Talk (TBM) ê¸°ë¡</div>'
            +'<button class="btn-primary" style="padding:4px 12px;font-size:12px"><i class="ph ph-plus"></i> TBM ê¸°ë¡</button></div>'
            +'<div class="panel-body"><table class="data-table"><thead><tr><th>ID</th><th>ë‚ ì§œ</th><th>Zone</th><th>ì§„í–‰ìž</th><th>ì£¼ì œ</th><th>ì°¸ì„</th></tr></thead><tbody>'+tbmRows+'</tbody></table></div></div>'
            +'</div>';

          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          // TAB 6 â€” VIOLATION TRACKER
          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          var vioRows=(violations||[]).map(function(v){
            var isOpen=!v.completedDate;
            return '<tr><td class="cell-mono">'+v.id+'</td>'
              +'<td><span style="font-weight:600">'+v.company+'</span></td>'
              +'<td style="font-size:11px;color:var(--text-tertiary)">'+v.oshaRef+'</td>'
              +'<td class="cell-primary">'+v.description+'</td>'
              +'<td class="cell-mono">'+v.discoveredAt.substring(0,10)+'</td>'
              +'<td>'+v.discoveredBy+'</td>'
              +'<td style="text-align:center;font-weight:700;color:'+(isOpen?'var(--status-danger)':'var(--text-secondary)')+'">'+v.points+'pt</td>'
              +'<td style="text-align:center;font-weight:700;color:var(--status-warning)">'+v.cumulativePoints+'pt</td>'
              +'<td>'+(isOpen?'<span style="background:rgba(239,68,68,.1);color:var(--status-danger);padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700">ë¯¸ì‹œì •</span>':'<span style="background:rgba(16,185,129,.1);color:var(--status-success);padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700">ì™„ë£Œ</span>')+'</td>'
              +'<td>'+(v.letterSent?'<a href="'+v.letterUrl+'" target="_blank" style="color:var(--brand-primary);font-size:11px"><i class="ph ph-file-pdf"></i> ê³µë¬¸</a>':'<button class="icon-btn"><i class="ph ph-file-arrow-up"></i></button>')+'</td></tr>';
          }).join('');

          var totalPts={};
          (violations||[]).forEach(function(v){ totalPts[v.company]=(totalPts[v.company]||0)+v.points; });
          var ptSummary=Object.keys(totalPts).map(function(co){
            var pt=totalPts[co]; var color=pt>=20?'var(--status-danger)':pt>=10?'var(--status-warning)':'var(--text-primary)';
            return '<div style="display:flex;justify-content:space-between;align-items:center;padding:9px 12px;background:var(--bg-subtle);border-radius:6px;margin-bottom:6px">'
              +'<span style="font-weight:600">'+co+'</span><span style="font-weight:700;color:'+color+'">'+pt+'ì </span></div>';
          }).join('');

          var vioTabHtml =
            '<div style="display:grid;grid-template-columns:1fr 200px;gap:16px">'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title"><i class="ph ph-warning-octagon"></i> ìœ„ë°˜ ì´ë ¥</div>'
            +'<button class="btn-primary" style="padding:4px 12px;font-size:12px"><i class="ph ph-plus"></i> ìœ„ë°˜ ê¸°ë¡</button></div>'
            +'<div class="panel-body"><table class="data-table"><thead><tr><th>ìœ„ë°˜ID</th><th>ì—…ì²´</th><th>OSHAì¡°í•­</th><th>ìœ„ë°˜ ë‚´ìš©</th><th>ë°œê²¬ì¼</th><th>ë°œê²¬ìž</th><th>ë²Œì </th><th>ëˆ„ì </th><th>ìƒíƒœ</th><th>ê³µë¬¸</th></tr></thead><tbody>'+vioRows+'</tbody></table></div></div>'
            +'<div class="panel" style="margin:0"><div class="panel-header"><div class="panel-title" style="font-size:13px"><i class="ph ph-ranking"></i> ì—…ì²´ ëˆ„ì  ë²Œì </div></div>'
            +'<div class="panel-body padded">'+ptSummary
            +'<div style="margin-top:8px;padding:8px;background:rgba(239,68,68,.06);border-radius:6px;font-size:10px;color:var(--text-tertiary)">20ì â†‘ ê³µë¬¸ ë°œì†¡ / 30ì â†‘ í‡´ì¶œ ê²€í† </div>'
            +'</div></div></div>';

          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          // TAB 7 â€” DOCS
          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          var docIconMap={'ë§¤ë‰´ì–¼':'ph-book','ì ˆì°¨ì„œ':'ph-file-text','ì–‘ì‹':'ph-note-pencil','MSDS':'ph-flask','ë²•ì •ì§€ì¹¨':'ph-gavel'};
          var docsRows=(safetyDocs||[]).map(function(d){
            return '<tr><td class="cell-mono">'+d.id+'</td>'
              +'<td><span style="display:inline-flex;align-items:center;gap:4px"><i class="ph '+(docIconMap[d.category]||'ph-file')+'" style="color:var(--text-secondary)"></i>'+d.category+'</span></td>'
              +'<td class="cell-primary" style="color:var(--brand-primary)"><i class="ph ph-file-pdf" style="margin-right:3px"></i>'+d.title+'</td>'
              +'<td class="cell-mono">'+d.size+'</td><td class="cell-mono">'+d.date+'</td><td>'+d.uploader+'</td>'
              +'<td style="text-align:right"><button class="icon-btn"><i class="ph ph-download-simple"></i></button></td></tr>';
          }).join('');
          var docsTabHtml='<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-folder-open"></i> í†µí•© ì•ˆì „ ë¬¸ì„œ ì•„ì¹´ì´ë¸Œ</div>'
            +'<button class="btn-primary" style="padding:4px 12px;font-size:12px"><i class="ph ph-upload-simple"></i> ì—…ë¡œë“œ</button></div>'
            +'<div class="panel-body"><table class="data-table"><thead><tr><th>ID</th><th>ë¶„ë¥˜</th><th>ë¬¸ì„œëª…</th><th>ìš©ëŸ‰</th><th>ë“±ë¡ì¼</th><th>ë“±ë¡ìž</th><th>ë°›ê¸°</th></tr></thead><tbody>'+docsRows+'</tbody></table></div></div>';

          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          // ASSEMBLE PAGE
          // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
          pageContainer.innerHTML =
            '<div class="header-section"><div>'
            +'<h1 class="page-title">ì•ˆì „ê´€ë¦¬ (Safety)</h1>'
            +'<p class="page-subtitle">OSHA ê¸°ë¡ë³´ê´€ Â· PTW ì „ìžê²°ìž¬ Â· ìžê²©ì¦ ë§¤íŠ¸ë¦­ìŠ¤ Â· ìœ„ë°˜ ì¶”ì  Â· TBM ê¸°ë¡</p>'
            +'</div><div class="action-row"><button class="btn-secondary" onclick="openMasterSheet()"><i class="ph ph-table"></i> ë§ˆìŠ¤í„° ì‹œíŠ¸</button></div></div>'
            +'<div class="tab-nav" id="safety-tabs">'
            +'<button class="tab-btn active" data-tab="s-overview">ðŸŸ¢ í˜„í™©</button>'
            +'<button class="tab-btn" data-tab="s-osha">ðŸ“‹ OSHA ê¸°ë¡</button>'
            +'<button class="tab-btn" data-tab="s-cert">ðŸŽ“ ìžê²©ì¦</button>'
            +'<button class="tab-btn" data-tab="s-ptw">ðŸ“ ìž‘ì—…í—ˆê°€ì„œ(PTW)</button>'
            +'<button class="tab-btn" data-tab="s-inspect">ðŸ” ì ê²€ / TBM</button>'
            +'<button class="tab-btn" data-tab="s-vio">âš ï¸ ìœ„ë°˜ ì¶”ì </button>'
            +'<button class="tab-btn" data-tab="s-docs">ðŸ“ ë¬¸ì„œ</button>'
            +'</div>'
            +'<div id="s-overview" class="tab-content" style="display:block">'+overviewHtml+'</div>'
            +'<div id="s-osha" class="tab-content" style="display:none">'+oshaHtml+'</div>'
            +'<div id="s-cert" class="tab-content" style="display:none">'+certHtml+'</div>'
            +'<div id="s-ptw" class="tab-content" style="display:none">'+ptwTabHtml+'</div>'
            +'<div id="s-inspect" class="tab-content" style="display:none">'+inspTabHtml+'</div>'
            +'<div id="s-vio" class="tab-content" style="display:none">'+vioTabHtml+'</div>'
            +'<div id="s-docs" class="tab-content" style="display:none">'+docsTabHtml+'</div>';

          // â”€â”€ íƒ­ ì „í™˜
          document.querySelectorAll('#safety-tabs .tab-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
              document.querySelectorAll('#safety-tabs .tab-btn').forEach(function(b){ b.classList.remove('active'); });
              btn.classList.add('active');
              document.querySelectorAll('#page-container .tab-content').forEach(function(c){ c.style.display='none'; });
              document.getElementById(btn.getAttribute('data-tab')).style.display='block';
            });
          });

          // â”€â”€ PTW í•„í„°
          var ptwF=document.getElementById('ptw-filter');
          if(ptwF) ptwF.addEventListener('change',function(){ var v=this.value; document.querySelectorAll('#ptw-table tbody tr').forEach(function(row){ row.style.display=(v==='all'||row.cells[1].textContent.indexOf(v)!==-1)?'':'none'; }); });

          // â”€â”€ PTW ìƒì„¸ íŒ¨ë„
          window._openPtwDetail = function(id, list){
            var p=(list||[]).find(function(x){ return x.id===id; });
            if(!p) return;
            var c=ptwColorMap[p.type]||'#64748b';
            var html='<div style="background:'+c+'12;border-radius:10px;padding:14px;margin-bottom:14px">'
              +'<div style="font-size:16px;font-weight:700;margin-bottom:6px">'+p.title+'</div>'
              +'<span style="background:'+c+'25;color:'+c+';padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700">'+p.type+'</span>'
              +'</div>'
              +'<table style="width:100%;font-size:12px;border-collapse:collapse">'
              +[['êµ¬ì—­',p.zone],['ìž‘ì—…ì¼ì‹œ',p.date+' '+(p.timeStart||'')+'~'+(p.timeEnd||'')],
                ['ì‹ ì²­ìž',p.applicant+' ('+p.company+')'],['íˆ¬ìž…',p.workers+'ëª…'],
                ['ìœ„í—˜ìš”ì¸','<span style="color:var(--status-danger)">'+p.risks+'</span>'],
                ['ì•ˆì „ëŒ€ì±…',p.measures],
                ['TBM',p.tbmDone?'<b style="color:var(--status-success)">âœ“ ì™„ë£Œ</b>':'<b style="color:var(--status-danger)">âœ— ë¯¸ì™„ë£Œ</b>'],
                ['ìƒíƒœ',statusPill(p.status)]].map(function(r){
                  return '<tr style="border-bottom:1px solid var(--border-subtle)"><td style="color:var(--text-tertiary);padding:8px 0;width:80px">'+r[0]+'</td><td style="padding:8px 0">'+r[1]+'</td></tr>';
                }).join('')+'</table>'
              +'<div style="margin-top:16px;display:flex;gap:8px">'
              +'<button class="btn-primary" style="flex:1"><i class="ph ph-check-circle"></i> ìŠ¹ì¸</button>'
              +'<button class="btn-secondary" style="flex:1"><i class="ph ph-x-circle"></i> ë°˜ë ¤</button>'
              +'</div>';
            document.getElementById('ptw-detail-content').innerHTML=html;
            document.getElementById('ptw-detail-panel').style.display='block';
          };

        } catch(err){ renderError('ì•ˆì „ê´€ë¦¬ ë¡œë”© ì‹¤íŒ¨: '+err.message); console.error(err); }
      }

      // â”€â”€ HR â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      async function renderHR() {
        pageContainer.innerHTML = skeleton();
        try {
          var userRole = (authenticatedAccount.raw_role || '').toLowerCase();
          var isAdmin = ['super_admin', 'admin', 'hr_manager', 'site_manager'].includes(userRole);
          var isGlobal = (_siteId() === 'ALL');

          if (!isAdmin) {
            pageContainer.innerHTML =
              '<div class="header-section"><div><h1 class="page-title">📍 내 출퇴근 등록 (GPS)</h1>' +
              '<p class="page-subtitle">현장 반경 내에서 출근 및 퇴근을 기록합니다.</p></div></div>' +
              '<div id="tab-my-commute" style="display:block;">' + (typeof window._myCommuteHtmlTemplate === 'function' ? window._myCommuteHtmlTemplate() : '') + '</div>';

            if (typeof window.loadMyCommuteLogs === 'function') window.loadMyCommuteLogs();
            if (typeof window.initMyCommuteGps === 'function') window.initMyCommuteGps();
            return;
          }

          // í†µí•©ë·°: getHRData(stats/personnel) + getAttendanceLive(global merged)
          // ë‹¨ì¼ë·°: getHRData + getDailyTeamMatrix + getDailyAttendanceDetail ë³‘ë ¬
          var hrData, attendance, teamMatrix, attendDetail;
          var queryDate = window._hrSelectedDate || '';  // ë‚ ì§œ ì„ íƒê¸° ìƒíƒœ
          if (isGlobal) {
            [hrData, attendance] = await Promise.all([
              window.API.getHRData(),
              window.API.getAttendanceLive()
            ]);
          } else {
            [hrData, teamMatrix, attendDetail] = await Promise.all([
              window.API.getHRData(),
              window.API.getDailyTeamMatrix(),
              window.API.getDailyAttendanceDetail(queryDate)
            ]);
            attendance = hrData ? (hrData.attendance || {}) : {};
          }

          var stats     = hrData ? (hrData.stats || { total: 0, active: 0, onLeave: 0, visaExpiringSoon: 0, safetyExpiring: 0 }) : { total: 0, active: 0, onLeave: 0, visaExpiringSoon: 0, safetyExpiring: 0 };
          var personnel = hrData ? (hrData.list || []) : [];

          // â”€â”€ í˜„ìž¥ë³„ ì‚¬ì´íŠ¸ ë°°ì§€ ìƒ‰ìƒ ë§µ â”€â”€
          var siteColors = {
            'HFF-02':  '#f59e0b',
            'LGES-AZ': '#2563eb',
            'NV-05':   '#10b981',
            'SST-03':  '#8b5cf6',
            'HWH-04':  '#ef4444',
          };
          function siteBadge(siteId) {
            var c = siteColors[siteId] || '#64748b';
            return '<span style="display:inline-block;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:700;color:white;background:' + c + ';margin-left:4px">' + (siteId || '') + '</span>';
          }

          // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
          // ðŸŒ í†µí•© ë·° ëª¨ë“œ
          // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
          if (isGlobal && attendance && attendance.mode === 'global') {
            var ss = attendance.siteStats || {};
            var siteKeys = Object.keys(ss);

            // í˜„ìž¥ë³„ ìš”ì•½ ì¹´ë“œ
            var siteCardsHtml = siteKeys.map(function(sid) {
              var s = ss[sid];
              var pct = s.totalActive > 0 ? Math.round(s.presentCount / s.totalActive * 100) : 0;
              var c = siteColors[sid] || '#64748b';
              return '<div class="kpi-card" style="border-top:3px solid ' + c + '">' +
                '<div class="kpi-label">' + siteBadge(sid) + ' ' + (s.siteName || sid) + '</div>' +
                '<div class="kpi-value" style="color:' + c + '">' + s.presentCount +
                '<span style="font-size:12px;color:var(--text-tertiary);font-weight:400"> / ' + s.totalActive + '</span></div>' +
                '<div style="background:var(--border-subtle);border-radius:4px;height:6px;margin-top:8px">' +
                '<div style="background:' + c + ';height:6px;border-radius:4px;width:' + pct + '%"></div></div>' +
                '<div class="kpi-meta" style="margin-top:6px"><span style="color:var(--text-secondary)">ì¶œê·¼ìœ¨ ' + pct + '%</span></div>' +
                '</div>';
            }).join('');

            // í†µí•© ì¶œê·¼ìž ëª©ë¡ (í˜„ìž¥ ë°°ì§€ í¬í•¨)
            var globalCheckedInHtml = (attendance.checkedIn || []).map(function(e) {
              var checkoutCell = e.checkOut ? e.checkOut : '<span style="color:var(--text-tertiary)">ê·¼ë¬´ì¤‘</span>';
              return '<tr><td class="cell-primary">' + (e.name || '-') + '</td>' +
                '<td><span class="tag">' + (e.company || '-') + '</span></td>' +
                '<td>' + (e.team || 'None') + '</td>' +
                '<td>' + siteBadge(e.site) + '</td>' +
                '<td class="cell-mono" style="color:var(--status-success)">' + (e.checkIn || '-') + '</td>' +
                '<td class="cell-mono">' + checkoutCell + '</td></tr>';
            }).join('');

            var globalAbsentHtml = (attendance.notCheckedIn || []).length === 0
              ? '<div style="padding:32px;text-align:center;color:var(--status-success)"><i class="ph ph-check-circle" style="font-size:36px;display:block;margin-bottom:8px"></i>ì „ì› ì¶œê·¼ ì™„ë£Œ</div>'
              : '<table class="data-table"><thead><tr><th>ì„±ëª…</th><th>ì†Œì†</th><th>í˜„ìž¥</th><th>NFC UID</th></tr></thead><tbody>' +
                (attendance.notCheckedIn || []).map(function(e) {
                  return '<tr><td class="cell-primary" style="color:var(--status-warning)">' + (e.name || '-') + '</td>' +
                    '<td><span class="tag">' + (e.company || '-') + '</span></td>' +
                    '<td>' + siteBadge(e.site) + '</td>' +
                    '<td class="cell-mono" style="font-size:10px">' + (e.nfcUid || '-') + '</td></tr>';
                }).join('') + '</tbody></table>';

            pageContainer.innerHTML =
              '<div class="header-section"><div>' +
              '<h1 class="page-title">ðŸŒ í†µí•© í˜„í™© â€” ì „ì²´ í˜„ìž¥</h1>' +
              '<p class="page-subtitle">ëª¨ë“  ì—°ë™ í˜„ìž¥ì˜ ì¶œí‡´ê·¼ ë°ì´í„°ë¥¼ í†µí•© ì§‘ê³„í•©ë‹ˆë‹¤ (' + attendance.date + ')</p></div>' +
              '<div class="action-row"><button class="btn-secondary" onclick="openMasterSheet()"><i class="ph ph-table"></i> ë§ˆìŠ¤í„° ì‹œíŠ¸</button></div></div>' +
              // ì „ì²´ KPI
              '<div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">' +
              '<div class="kpi-card"><div class="kpi-label">ì „ì²´ ì¶œê·¼ ì¸ì› <i class="ph ph-users" style="font-size:14px;color:var(--text-tertiary)"></i></div>' +
              '<div class="kpi-value" style="color:var(--status-success)">' + attendance.totalPresent + '<span style="font-size:12px;color:var(--text-tertiary);font-weight:400"> / ' + attendance.totalWorkers + '</span></div>' +
              '<div class="kpi-meta"><span style="color:var(--text-secondary)">ì „ì²´ í•©ì‚° ì¶œê·¼</span></div></div>' +
              '<div class="kpi-card"><div class="kpi-label">ë¯¸ì¶œê·¼ <i class="ph ph-user-minus" style="font-size:14px;color:var(--status-warning)"></i></div>' +
              '<div class="kpi-value" style="color:var(--status-warning)">' + attendance.absentCount + '</div>' +
              '<div class="kpi-meta"><span style="color:var(--text-secondary)">ì „ì²´ ë¯¸ì²´í¬ì¸</span></div></div>' +
              '<div class="kpi-card"><div class="kpi-label">ì—°ë™ í˜„ìž¥ ìˆ˜ <i class="ph ph-buildings" style="font-size:14px;color:var(--brand-primary)"></i></div>' +
              '<div class="kpi-value" style="color:var(--brand-primary)">' + attendance.activeSiteCount + '</div>' +
              '<div class="kpi-meta"><span style="color:var(--text-secondary)">í™œì„± í˜„ìž¥</span></div></div>' +
              '<div class="kpi-card"><div class="kpi-label">ì „ì²´ ì¸ì› <i class="ph ph-identification-badge" style="font-size:14px;color:var(--text-tertiary)"></i></div>' +
              '<div class="kpi-value">' + stats.total + '</div>' +
              '<div class="kpi-meta"><span style="color:var(--text-secondary)">ERP ë“±ë¡ ì¸ì›</span></div></div>' +
              '</div>' +
              // í˜„ìž¥ë³„ ì¹´ë“œ
              '<h3 style="font-size:13px;font-weight:700;color:var(--text-secondary);margin:8px 0 12px;text-transform:uppercase;letter-spacing:.06em">í˜„ìž¥ë³„ ì¶œê·¼ í˜„í™©</h3>' +
              '<div class="kpi-row" style="grid-template-columns:repeat(' + Math.min(siteKeys.length, 4) + ',1fr);margin-bottom:16px">' + siteCardsHtml + '</div>' +
              // ì¶œí‡´ê·¼ íƒ­
              '<div class="tab-nav" id="hr-tabs"><button class="tab-btn active" data-tab="attendance">ðŸŒ í†µí•© ì¶œí‡´ê·¼ ëª©ë¡</button><button class="tab-btn" data-tab="personnel">ðŸ‘¤ ì¸ì› ë§ˆìŠ¤í„°</button></div>' +
              '<div id="tab-attendance">' +
              '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">' +
              '<div class="panel"><div class="panel-header"><div class="panel-title" style="color:var(--status-success)"><i class="ph ph-check-circle"></i> ì¶œê·¼ ì™„ë£Œ (' + attendance.totalPresent + 'ëª…)</div></div>' +
              '<div class="panel-body"><table class="data-table"><thead><tr><th>ì„±ëª…</th><th>ì†Œì†</th><th>íŒ€</th><th>í˜„ìž¥</th><th>ì²´í¬ì¸</th><th>í‡´ê·¼</th></tr></thead><tbody>' + (globalCheckedInHtml || '<tr><td colspan="6" style="text-align:center;color:var(--text-tertiary)">ì¶œê·¼ ê¸°ë¡ ì—†ìŒ</td></tr>') + '</tbody></table></div></div>' +
              '<div class="panel"><div class="panel-header"><div class="panel-title" style="color:var(--status-warning)"><i class="ph ph-warning"></i> ë¯¸ì¶œê·¼ (' + attendance.absentCount + 'ëª…)</div></div>' +
              '<div class="panel-body">' + globalAbsentHtml + '</div></div></div></div>' +
              '<div id="tab-personnel" style="display:none">' +
              '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-identification-card"></i> ì¸ì› ë§ˆìŠ¤í„° (ì „ì²´)</div>' +
              '<input type="text" class="search-inline" id="hr-search" placeholder="ì´ë¦„, ID, ì†Œì† ê²€ìƒ‰..."></div>' +
              '<div class="panel-body"><table class="data-table" id="hr-table"><thead><tr><th>ì¸ì›ID</th><th>ì„±ëª…</th><th>ì†Œì†</th><th>ì§ì¢…</th><th>í˜„ìž¥</th><th>ë¹„ìžë§Œë£Œ</th><th>ì•ˆì „êµìœ¡</th></tr></thead><tbody>' +
              personnel.map(function(p) {
                return '<tr><td class="cell-mono">' + p.id + '</td><td class="cell-primary">' + p.nameEn + '</td>' +
                  '<td><span class="tag">' + p.company + '</span></td><td>' + p.role + '</td>' +
                  '<td>' + siteBadge(p.site) + '</td><td class="cell-mono">' + (p.visaExpiry || '-') + '</td>' +
                  '<td>' + statusPill(p.safety) + '</td></tr>';
              }).join('') +
              '</tbody></table></div></div></div>';

          } else {
            // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            // ðŸ—ï¸ ë‹¨ì¼ í˜„ìž¥ ëª¨ë“œ (ê¸°ì¡´ ë¡œì§ ìœ ì§€)
            // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            var teamSummaryHtml = (attendance.teamSummary || []).map(function (t) {
              return '<div style="background:var(--bg-base);border:1px solid var(--border-subtle);border-radius:6px;padding:10px 14px;text-align:center">' +
                '<div style="font-size:10px;color:var(--text-tertiary);font-weight:700;text-transform:uppercase;margin-bottom:4px">' + t.team + '</div>' +
                '<div style="font-size:22px;font-weight:700;font-family:var(--font-mono)">' + t.count + '</div>' +
                '<div style="font-size:10px;color:var(--text-tertiary)">ëª…</div></div>';
            }).join('');

            var checkedInHtml = (attendance.checkedIn || []).map(function (e) {
              var checkoutCell = e.checkOut ? e.checkOut : '<span style="color:var(--text-tertiary)">ê·¼ë¬´ì¤‘</span>';
              return '<tr><td class="cell-primary">' + (e.name||'-') + '</td><td><span class="tag">' + (e.company||'-') + '</span></td><td>' + (e.team||'None') + '</td><td class="cell-mono" style="color:var(--status-success)">' + (e.checkIn||'-') + '</td><td class="cell-mono">' + checkoutCell + '</td></tr>';
            }).join('');

            var absentHtml = (attendance.absentCount || 0) === 0
              ? '<div style="padding:40px;text-align:center;color:var(--status-success)"><i class="ph ph-check-circle" style="font-size:40px;display:block;margin-bottom:8px"></i>ì „ì› ì¶œê·¼ ì™„ë£Œ</div>'
              : '<table class="data-table"><thead><tr><th>ì„±ëª…</th><th>ì†Œì†</th><th>NFC UID</th></tr></thead><tbody>' +
                (attendance.notCheckedIn || []).map(function (e) {
                  return '<tr><td class="cell-primary" style="color:var(--status-warning)">' + (e.name||'-') + '</td><td><span class="tag">' + (e.company||'-') + '</span></td><td class="cell-mono" style="font-size:10px">' + (e.nfcUid||'-') + '</td></tr>';
                }).join('') + '</tbody></table>';

            var personnelHtml = personnel.map(function (p) {
              var visaExpDate = p.visaExpiry && p.visaExpiry !== '-' ? new Date(p.visaExpiry) : null;
              var today2 = new Date(); var thirtyDays2 = new Date(today2.getTime() + 30*24*60*60*1000);
              var visaClass = '';
              if (visaExpDate) { if (visaExpDate < today2) visaClass = ' style="color:var(--status-danger);font-weight:700"'; else if (visaExpDate < thirtyDays2) visaClass = ' style="color:var(--status-warning);font-weight:600"'; }
              var krName = p.nameKr ? '<br><span style="font-size:10px;color:var(--text-tertiary)">' + p.nameKr + '</span>' : '';
              var wsColor = p.workerStatus === 'ê·€êµ­' ? 'color:var(--text-tertiary)' : p.workerStatus === 'í‡´ì‚¬' ? 'color:var(--status-danger)' : 'color:var(--status-success)';
              return '<tr><td class="cell-mono">' + p.id + '</td><td class="cell-primary">' + p.nameEn + krName + '</td>' +
                '<td><span class="tag">' + p.company + '</span></td><td>' + p.role + '</td>' +
                '<td class="cell-mono">' + (p.visa||'-') + '</td><td class="cell-mono"' + visaClass + '>' + (p.visaExpiry||'-') + '</td>' +
                '<td>' + (p.site||'-') + '</td><td><span style="font-size:11px;' + wsColor + '">' + (p.workerStatus||'íŒŒê²¬ì¤‘') + '</span></td>' +
                '<td>' + statusPill(p.safety) + '</td></tr>';
            }).join('');

            var siteLabel = (window.SITE_NAMES && window.SITE_NAMES[_siteId()]) ? window.SITE_NAMES[_siteId()] : _siteId();

            // â”€â”€ ì¼ì¼ íŒ€ë³„ êµ¬ì„±ì¸ì› ë§¤íŠ¸ë¦­ìŠ¤ (ERP ë‹¤í¬ í…Œë§ˆ í†µí•© ë””ìžì¸) â”€â”€
            var matrixHtml = '';
            if (teamMatrix && teamMatrix.success && teamMatrix.teams && teamMatrix.teams.length > 0) {
              var tm = teamMatrix;
              var dateParts = (tm.date || '').split('-');
              var yy = dateParts[0] || '----', mm = dateParts[1] || '--', dd = dateParts[2] || '--';

              // ìƒ‰ìƒ í† í° â€” ì˜ë¯¸ì  ë§¤í•‘
              var COLOR_MGR = '#f59e0b';   // 관리자 = ì£¼í™© (ì±…ìž„ìž)
              var COLOR_KOR = '#3b82f6';   // 한국인 = íŒŒëž‘
              var COLOR_LOC = '#10b981';   // 외국인 = ë…¹ìƒ‰
              var COLOR_TOTAL = '#a78bfa'; // í•©ê³„ = ë³´ë¼ (brand)

              // â”€â”€ ìš°ì¸¡: ì¸í„°ëž™í‹°ë¸Œ ë§¤íŠ¸ë¦­ìŠ¤ â”€â”€
              // Header 1: íŒ€ëª…
              var thTeams = tm.teams.map(function(t) {
                var icon = (t === 'MAIN') ? 'ph-buildings' :
                           (t.indexOf('CDC') !== -1) ? 'ph-stack' :
                           (t.indexOf('ASSEMBLY') !== -1) ? 'ph-wrench' :
                           (t.indexOf('CLEAN') !== -1) ? 'ph-broom' :
                           (t === 'ë¬¼ë¥˜' || t.indexOf('LOGI') !== -1) ? 'ph-truck' :
                           (t.indexOf('í˜„ìž¥ì§€ì›') !== -1 || t.indexOf('LAYDOWN') !== -1) ? 'ph-package' :
                           'ph-users-three';
                return '<th style="padding:10px 8px;background:linear-gradient(180deg,rgba(167,139,250,0.18),rgba(167,139,250,0.08));border-bottom:1px solid var(--border-default);color:var(--text-primary);font-size:11px;font-weight:700;letter-spacing:0.3px;text-align:center;text-transform:uppercase;white-space:nowrap">' +
                  '<i class="ph ' + icon + '" style="font-size:13px;color:' + COLOR_TOTAL + ';margin-right:4px"></i>' + t + '</th>';
              }).join('');

              // Header 2: íŒ€ìž¥ ì´ë¦„
              var thForemen = tm.teams.map(function(t) {
                var foreman = (t === 'MAIN') ? 'ì‚¬ë¬´ì‹¤' : (tm.foremen[t] || 'â€”');
                var isOffice = (t === 'MAIN');
                return '<th style="padding:6px 8px;background:var(--bg-base);border-bottom:1px solid var(--border-subtle);color:' +
                  (isOffice ? 'var(--text-tertiary)' : 'var(--text-secondary)') +
                  ';font-size:10px;font-weight:500;text-align:center;font-style:' + (isOffice ? 'italic' : 'normal') + '">' +
                  (isOffice ? '' : '<i class="ph ph-user-circle" style="font-size:11px;margin-right:3px;opacity:0.6"></i>') +
                  foreman + '</th>';
              }).join('');

              // â”€â”€ íšŒì‚¬ë³„ ì¢Œ(í†µê³„ ì¹´ë“œ) + ìš°(ìžê¸° íŒ€ ë§¤íŠ¸ë¦­ìŠ¤) í–‰ ë¹Œë” â”€â”€
              function getTeamIcon(t) {
                return (t === 'MAIN') ? 'ph-buildings' :
                       (t.indexOf('CDC') !== -1) ? 'ph-stack' :
                       (t.indexOf('ASSEMBLY') !== -1 || t.indexOf('ASSY') !== -1) ? 'ph-wrench' :
                       (t.indexOf('CLEAN') !== -1) ? 'ph-broom' :
                       (t === 'ë¬¼ë¥˜' || t.indexOf('LOGI') !== -1) ? 'ph-truck' :
                       (t.indexOf('í˜„ìž¥ì§€ì›') !== -1 || t.indexOf('LAYDOWN') !== -1) ? 'ph-package' :
                       (t.indexOf('ë°°ê´€') !== -1) ? 'ph-pipe' :
                       'ph-users-three';
              }

              // 1) íšŒì‚¬ê°€ ì‚¬ìš©í•œ íŒ€ ì¶”ì¶œ (matrixì— ì¹´ìš´íŠ¸ê°€ ìžˆëŠ” íŒ€)
              function extractCompanyTeams(companyData) {
                var s = new Set();
                ['관리자','한국인','외국인'].forEach(function(div) {
                  Object.keys((companyData.matrix && companyData.matrix[div]) || {}).forEach(function(t) {
                    if ((companyData.matrix[div][t] || 0) > 0) s.add(t);
                  });
                });
                return [...s].sort(function(a, b) {
                  if (a === 'MAIN') return -1;
                  if (b === 'MAIN') return 1;
                  return 0;
                });
              }

              // 2) ì¢Œì¸¡ í†µê³„ ë°•ìŠ¤ (íšŒì‚¬ ë‹¨ìœ„)
              function buildLeftStatBox(company) {
                var compColor = window.getCompanyColor ? window.getCompanyColor(company.name) : COLOR_TOTAL;
                var t = company.totals || { manager:0, korean:0, local:0, total:0 };
                var pctMgr = t.total > 0 ? Math.round(t.manager/t.total*100) : 0;
                var pctKor = t.total > 0 ? Math.round(t.korean /t.total*100) : 0;
                var pctLoc = t.total > 0 ? Math.round(t.local  /t.total*100) : 0;
                function row(label, count, pct, color, icon) {
                  return '<div style="display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--bg-base);border-radius:6px;border-left:2px solid ' + color + '">' +
                    '<i class="ph ' + icon + '" style="font-size:13px;color:' + color + ';flex-shrink:0"></i>' +
                    '<span style="font-size:10px;color:var(--text-tertiary);flex:1">' + label + '</span>' +
                    '<span class="cell-mono" style="font-size:14px;font-weight:800;color:' + color + '">' + count + '</span>' +
                    '<span style="font-size:9px;color:var(--text-tertiary);width:28px;text-align:right">' + (count > 0 ? pct + '%' : '-') + '</span>' +
                    '</div>';
                }
                return '<div style="display:flex;flex-direction:column;gap:6px;padding:12px;background:linear-gradient(135deg,' + compColor + '15,transparent);border:1px solid ' + compColor + '44;border-radius:10px;height:fit-content">' +
                  '<div style="display:flex;align-items:center;justify-content:space-between;padding-bottom:8px;border-bottom:1px solid ' + compColor + '33;margin-bottom:4px">' +
                    '<div style="display:flex;align-items:center;gap:6px">' +
                      '<i class="ph ph-buildings" style="font-size:16px;color:' + compColor + '"></i>' +
                      '<span style="font-size:13px;font-weight:800;color:var(--text-primary);letter-spacing:0.3px">' + company.name + '</span>' +
                    '</div>' +
                    '<span class="cell-mono" style="font-size:18px;font-weight:800;color:' + compColor + ';line-height:1">' + (t.total || 0) + '</span>' +
                  '</div>' +
                  row('관리자', t.manager || 0, pctMgr, COLOR_MGR, 'ph-crown') +
                  row('한국인', t.korean  || 0, pctKor, COLOR_KOR, 'ph-flag') +
                  row('외국인', t.local   || 0, pctLoc, COLOR_LOC, 'ph-globe') +
                  '<div style="margin-top:4px;padding:6px 10px;background:' + compColor + '22;border-radius:6px;display:flex;justify-content:space-between;align-items:center">' +
                    '<span style="font-size:10px;color:var(--text-tertiary);font-weight:700;letter-spacing:0.5px;text-transform:uppercase">í•©ê³„</span>' +
                    '<span class="cell-mono" style="font-size:13px;font-weight:800;color:' + compColor + '">' + (t.total || 0) + ' ëª…</span>' +
                  '</div>' +
                  '</div>';
              }

              // 3) ìš°ì¸¡ ë§¤íŠ¸ë¦­ìŠ¤ (íšŒì‚¬ ë‹¨ìœ„ â€” ìžê¸° íŒ€ë§Œ)
              function buildRightMatrix(company) {
                var compColor = window.getCompanyColor ? window.getCompanyColor(company.name) : COLOR_TOTAL;
                var compTeams = extractCompanyTeams(company);

                if (compTeams.length === 0) {
                  return '<div style="background:var(--bg-panel);border:1px solid var(--border-subtle);border-radius:10px;padding:32px;text-align:center;color:var(--text-tertiary);font-size:12px;display:flex;align-items:center;justify-content:center">' +
                    '<i class="ph ph-clock-counter-clockwise" style="font-size:18px;margin-right:8px;opacity:0.5"></i>ì˜¤ëŠ˜ ì¶œê·¼ ë°ì´í„° ì—†ìŒ</div>';
                }

                var thTeams = compTeams.map(function(t) {
                  return '<th style="padding:9px 8px;background:linear-gradient(180deg,' + compColor + '25,' + compColor + '08);border-bottom:1px solid ' + compColor + '44;color:var(--text-primary);font-size:11px;font-weight:700;letter-spacing:0.3px;text-align:center;text-transform:uppercase;white-space:nowrap">' +
                    '<i class="ph ' + getTeamIcon(t) + '" style="font-size:13px;color:' + compColor + ';margin-right:4px"></i>' + t + '</th>';
                }).join('');

                var thForemen = compTeams.map(function(t) {
                  var foreman = (t === 'MAIN') ? 'ì‚¬ë¬´ì‹¤' : (tm.foremen[t] || 'â€”');
                  var isOffice = (t === 'MAIN');
                  return '<th style="padding:5px 8px;background:var(--bg-base);border-bottom:1px solid var(--border-subtle);color:' +
                    (isOffice ? 'var(--text-tertiary)' : 'var(--text-secondary)') +
                    ';font-size:10px;font-weight:500;text-align:center;font-style:' + (isOffice ? 'italic' : 'normal') + '">' +
                    (isOffice ? '' : '<i class="ph ph-user-circle" style="font-size:11px;margin-right:3px;opacity:0.6"></i>') +
                    foreman + '</th>';
                }).join('');

                function buildRow(label, rowKey, color, icon) {
                  var cells = compTeams.map(function(t) {
                    var v = (company.matrix[rowKey] && company.matrix[rowKey][t]) || 0;
                    var emptyClass = v === 0 ? 'opacity:0.25' : '';
                    return '<td style="padding:0;border-bottom:1px solid var(--border-subtle);text-align:center;position:relative;height:38px;' + emptyClass + '">' +
                      '<div style="position:relative;display:flex;align-items:center;justify-content:center;height:100%">' +
                      (v > 0 ? '<div style="position:absolute;bottom:0;left:10%;right:10%;height:2px;background:' + color + ';border-radius:1px;opacity:0.7"></div>' : '') +
                      '<span class="cell-mono" style="font-size:14px;font-weight:700;color:' + (v > 0 ? color : 'var(--text-tertiary)') + '">' + v + '</span>' +
                      '</div></td>';
                  }).join('');
                  return '<tr><td style="padding:8px 12px;border-bottom:1px solid var(--border-subtle);background:var(--bg-base);text-align:left;font-weight:600;font-size:12px;color:var(--text-primary);white-space:nowrap">' +
                    '<i class="ph ' + icon + '" style="color:' + color + ';margin-right:6px;font-size:13px"></i>' + label + '</td>' +
                    cells + '</tr>';
                }

                var subtotalCells = compTeams.map(function(t) {
                  var v = company.subtotals && company.subtotals[t] ? company.subtotals[t] : 0;
                  return '<td style="padding:10px 8px;background:linear-gradient(180deg,' + compColor + '20,transparent);text-align:center">' +
                    '<span class="cell-mono" style="font-size:15px;font-weight:800;color:' + (v > 0 ? compColor : 'var(--text-tertiary)') + '">' + v + '</span></td>';
                }).join('');

                return '<div style="background:var(--bg-panel);border:1px solid ' + compColor + '33;border-radius:10px;overflow:hidden">' +
                    '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">' +
                      '<thead>' +
                        '<tr><th style="padding:9px 12px;background:linear-gradient(180deg,' + compColor + '25,' + compColor + '08);border-bottom:1px solid ' + compColor + '44;color:var(--text-tertiary);font-size:10px;font-weight:700;letter-spacing:1px;text-align:left;text-transform:uppercase">TEAM</th>' + thTeams + '</tr>' +
                        '<tr><th style="padding:5px 12px;background:var(--bg-base);border-bottom:1px solid var(--border-subtle);color:var(--text-tertiary);font-size:9px;font-weight:600;letter-spacing:0.5px;text-align:left;text-transform:uppercase">FOREMAN</th>' + thForemen + '</tr>' +
                      '</thead>' +
                      '<tbody>' +
                        buildRow('관리자', '관리자', COLOR_MGR, 'ph-crown') +
                        buildRow('한국인', '한국인', COLOR_KOR, 'ph-flag') +
                        buildRow('외국인', '외국인', COLOR_LOC, 'ph-globe') +
                        '<tr style="border-top:2px solid ' + compColor + '44">' +
                          '<td style="padding:10px 12px;background:linear-gradient(90deg,' + compColor + '22,' + compColor + '08);text-align:left;font-weight:800;font-size:12px;color:var(--text-primary);letter-spacing:0.3px">' +
                          '<i class="ph ph-equals" style="color:' + compColor + ';margin-right:6px;font-size:13px"></i>ì†Œ ê³„</td>' +
                          subtotalCells + '</tr>' +
                      '</tbody>' +
                    '</table></div>' +
                  '</div>';
              }

              // 4) íšŒì‚¬ ëª©ë¡ â€” ë“±ë¡ íšŒì‚¬ ëª¨ë‘ í¬í•¨, ì¶œê·¼ ë§Žì€ ìˆœ ì •ë ¬
              var companies = (tm.byCompany || []).slice();
              var registered = tm.registered || { managerByCompany: {} };
              var existingNames = new Set(companies.map(function(c) { return c.name; }));
              Object.keys(registered.managerByCompany || {}).forEach(function(cn) {
                if (!existingNames.has(cn)) {
                  companies.push({
                    name: cn,
                    matrix: { '관리자': {}, '한국인': {}, '외국인': {} },
                    totals: { manager: 0, korean: 0, local: 0, total: 0 },
                    subtotals: {}
                  });
                }
              });
              companies.sort(function(a, b) { return (b.totals.total || 0) - (a.totals.total || 0); });

              // 5) íšŒì‚¬ë³„ í–‰ (ì¢Œì¸¡ ë°•ìŠ¤ + ìš°ì¸¡ ë§¤íŠ¸ë¦­ìŠ¤) HTML
              var companyRowsHtml = companies.length === 0
                ? '<div style="padding:32px;text-align:center;color:var(--text-tertiary)">ì¶œê·¼ ë°ì´í„° ì—†ìŒ</div>'
                : companies.map(function(c) {
                    return '<div style="display:grid;grid-template-columns:230px 1fr;gap:14px;align-items:start">' +
                      buildLeftStatBox(c) +
                      buildRightMatrix(c) +
                      '</div>';
                  }).join('');

              // 6) í•˜ë‹¨ ì „ì²´ í•©ê³„ (ëª¨ë“  íšŒì‚¬ í•©ì‚°)
              var grandTotal = tm.totals.grandTotal || 0;
              var grandHtml = '<div style="margin-top:6px;padding:14px 18px;background:linear-gradient(135deg,' + COLOR_TOTAL + '22,' + COLOR_TOTAL + '08);border:1px solid ' + COLOR_TOTAL + '44;border-radius:10px;display:flex;align-items:center;justify-content:space-between;gap:18px">' +
                '<div style="display:flex;align-items:center;gap:8px">' +
                  '<i class="ph ph-users-four" style="font-size:22px;color:' + COLOR_TOTAL + '"></i>' +
                  '<span style="font-size:13px;font-weight:800;color:var(--text-primary);letter-spacing:0.3px">ì „ì²´ í•©ê³„</span>' +
                  '<span class="cell-mono" style="font-size:11px;color:var(--text-tertiary);background:var(--bg-base);padding:3px 8px;border-radius:4px">' + companies.length + 'ê°œì‚¬</span>' +
                '</div>' +
                '<div style="display:flex;align-items:center;gap:18px;font-size:12px">' +
                  '<span style="display:flex;align-items:center;gap:5px"><i class="ph ph-crown" style="color:' + COLOR_MGR + '"></i><span style="color:var(--text-tertiary)">관리자</span><span class="cell-mono" style="color:' + COLOR_MGR + ';font-weight:800">' + (tm.totals.manager||0) + '</span></span>' +
                  '<span style="display:flex;align-items:center;gap:5px"><i class="ph ph-flag" style="color:' + COLOR_KOR + '"></i><span style="color:var(--text-tertiary)">한국인</span><span class="cell-mono" style="color:' + COLOR_KOR + ';font-weight:800">' + (tm.totals.korean||0) + '</span></span>' +
                  '<span style="display:flex;align-items:center;gap:5px"><i class="ph ph-globe" style="color:' + COLOR_LOC + '"></i><span style="color:var(--text-tertiary)">외국인</span><span class="cell-mono" style="color:' + COLOR_LOC + ';font-weight:800">' + (tm.totals.local||0) + '</span></span>' +
                  '<span style="display:flex;align-items:center;gap:5px;padding-left:14px;border-left:1px solid var(--border-default)"><span style="color:var(--text-tertiary)">ì´</span><span class="cell-mono" style="font-size:18px;color:' + COLOR_TOTAL + ';font-weight:800">' + grandTotal + '</span><span style="color:var(--text-tertiary)">ëª…</span></span>' +
                '</div></div>';

              var unclassifiedHtml = (tm.unclassified && tm.unclassified.length > 0)
                ? '<div style="margin-top:8px;display:flex;align-items:center;gap:8px;padding:10px 12px;background:rgba(239,68,68,0.10);border-radius:8px;border:1px solid rgba(239,68,68,0.25);font-size:11px;color:var(--status-danger)">' +
                  '<i class="ph ph-warning"></i>Pì—´ ë¯¸ë¶„ë¥˜ <strong>' + tm.unclassified.length + 'ëª…</strong> (관리자 ìˆ˜ë™ ì§€ì • í•„ìš”)</div>'
                : '';

              // â”€â”€ ì „ì²´ íŒ¨ë„ ì»¨í…Œì´ë„ˆ â”€â”€
              matrixHtml =
                '<div class="panel" style="margin-bottom:16px;overflow:hidden">' +
                  '<div class="panel-header" style="background:linear-gradient(90deg,rgba(167,139,250,0.10),transparent);border-bottom:1px solid var(--border-default);padding:14px 18px;display:flex;align-items:center;justify-content:space-between">' +
                    '<div class="panel-title" style="display:flex;align-items:center;gap:10px">' +
                      '<i class="ph ph-chart-bar" style="font-size:18px;color:' + COLOR_TOTAL + '"></i>' +
                      '<span style="color:var(--text-primary);font-weight:700;font-size:14px">ì¼ì¼ íšŒì‚¬Â·íŒ€ë³„ êµ¬ì„±ì¸ì›</span>' +
                      '<span style="font-size:10px;padding:3px 8px;background:rgba(167,139,250,0.15);color:' + COLOR_TOTAL + ';border-radius:4px;font-weight:600;letter-spacing:0.3px">' + yy + '.' + mm + '.' + dd + '</span>' +
                    '</div>' +
                    '<div style="display:flex;align-items:center;gap:6px;font-size:11px;color:var(--text-tertiary)">' +
                      '<i class="ph ph-clock"></i>' +
                      '<span>ì‹¤ì‹œê°„ PAYABLE_TIMESHEET ì§‘ê³„</span>' +
                    '</div>' +
                  '</div>' +
                  '<div class="panel-body" style="padding:14px;display:flex;flex-direction:column;gap:14px">' +
                    companyRowsHtml +
                    grandHtml +
                    unclassifiedHtml +
                  '</div>' +
                '</div>';
            }

            // KPI ì‹ ê·œ ì˜ë¯¸: NAHSHON ë³¸ì‚¬ í†µí•© ì¸ì›í˜„í™©
            var regInfo = (teamMatrix && teamMatrix.registered) || { total: stats.total || 0, managerTotal: 0, managerByCompany: {} };
            var totals = (teamMatrix && teamMatrix.totals) || { manager: 0, korean: 0, local: 0, grandTotal: 0 };
            var totalAttended = totals.grandTotal || 0;
            var totalAbsent = (regInfo.total || 0) - totalAttended;
            // íšŒì‚¬ë³„ 관리자 ë¶„í¬ ë¯¸ë‹ˆ í‘œì‹œ
            var mgrCompanyHint = Object.keys(regInfo.managerByCompany || {}).map(function(cn) {
              return cn + ' ' + regInfo.managerByCompany[cn];
            }).join(' Â· ');

            pageContainer.innerHTML =
              '<div class="header-section"><div><h1 class="page-title">ì¸ì‚¬ / ì¶œí‡´ê·¼ ê´€ë¦¬</h1>' +
              '<p class="page-subtitle">NAHSHON MEP ì´ ì¸ì› í˜„í™© (' + (attendance.date||'') + ')</p></div>' +
              '<div class="action-row"><button class="btn-secondary" onclick="openMasterSheet()"><i class="ph ph-table"></i> ì‹œíŠ¸ ë§ˆìŠ¤í„°</button><button class="btn-primary" onclick="openGoogleForm(\'hr\')"><i class="ph ph-user-plus"></i> êµ¬ê¸€í¼: ì‹ ê·œ ë“±ë¡</button></div></div>' +
              // 60% ì••ì¶• KPI ì¹´ë“œ â€” padding/font ì¶•ì†Œ
              '<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:12px">' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">관리자 ì´í•©<i class="ph ph-crown" style="font-size:12px;color:#f59e0b"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;color:#f59e0b;line-height:1.1">' + (regInfo.managerTotal || 0) + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">' + (mgrCompanyHint || 'íšŒì‚¬ë³„ ë“±ë¡ í•©ê³„') + '</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">한국인<i class="ph ph-flag" style="font-size:12px;color:#3b82f6"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;color:#3b82f6;line-height:1.1">' + (totals.korean || 0) + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">ì˜¤ëŠ˜ ì¶œê·¼</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">외국인<i class="ph ph-globe" style="font-size:12px;color:#10b981"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;color:#10b981;line-height:1.1">' + (totals.local || 0) + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">ì˜¤ëŠ˜ ì¶œê·¼</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">ì´ ë“±ë¡ì¸ì›<i class="ph ph-identification-card" style="font-size:12px;color:var(--brand-primary)"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;color:var(--brand-primary);line-height:1.1">' + (regInfo.total || 0) + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">Active ì¸ì›</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">ì´ ì¶œì„<i class="ph ph-identification-badge" style="font-size:12px;color:var(--status-success)"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;color:var(--status-success);line-height:1.1">' + totalAttended + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--status-warning)">ë¯¸ì¶œì„ ' + totalAbsent + 'ëª…</span></div></div>' +
              '</div>' +
              '<div class="tab-nav" id="hr-tabs"><button class="tab-btn active" data-tab="attendance">ðŸ”– ì¶œí‡´ê·¼ í˜„í™©</button><button class="tab-btn" data-tab="personnel">ðŸ‘¤ ì¸ì› ë§ˆìŠ¤í„°</button></div>' +
              '<div id="tab-attendance">' +
              matrixHtml +
              // â”€â”€ ì¶œê·¼ ìƒì„¸ ë³´ê³  (ì¢Œ) + íšŒì‚¬ë³„ í†µê³„ ë„í‘œ (ìš°) â€” ì»¨í…Œì´ë„ˆë§Œ â”€â”€
              '<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:16px" class="hr-detail-grid">' +
                '<div id="attendance-detail-panel" class="panel"><div class="panel-header" style="display:flex;justify-content:space-between;align-items:center">' +
                  '<div class="panel-title" style="color:var(--status-success)"><i class="ph ph-list-bullets"></i> ì¶œê·¼ ìƒì„¸ ë³´ê³ </div>' +
                  '<span style="font-size:11px;color:var(--text-tertiary)" id="att-detail-count"></span>' +
                '</div><div class="panel-body" id="attendance-detail-body" style="padding:0"><div style="padding:32px;text-align:center;color:var(--text-tertiary)"><i class="ph ph-spinner ph-spin"></i> ë¡œë”© ì¤‘...</div></div></div>' +
                '<div id="company-stats-panel" class="panel"><div class="panel-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px">' +
                  '<div class="panel-title" style="color:var(--brand-primary)"><i class="ph ph-chart-bar-horizontal"></i> íšŒì‚¬ë³„/íŒ€ë³„ í†µê³„</div>' +
                  '<div style="display:flex;align-items:center;gap:6px">' +
                    '<button onclick="window.shiftStatsDate(-1)" style="background:var(--bg-base);border:1px solid var(--border-default);color:var(--text-primary);width:28px;height:28px;border-radius:6px;cursor:pointer">â€¹</button>' +
                    '<input id="stats-date-picker" type="date" value="' + (attendance.date || '') + '" style="background:var(--bg-base);border:1px solid var(--border-default);color:var(--text-primary);padding:4px 8px;border-radius:6px;font-size:12px;cursor:pointer" onchange="window.loadCompanyStats(this.value)">' +
                    '<button onclick="window.shiftStatsDate(1)" style="background:var(--bg-base);border:1px solid var(--border-default);color:var(--text-primary);width:28px;height:28px;border-radius:6px;cursor:pointer">â€º</button>' +
                  '</div>' +
                '</div><div class="panel-body" id="company-stats-body" style="padding:16px"><div style="padding:32px;text-align:center;color:var(--text-tertiary)"><i class="ph ph-spinner ph-spin"></i> ë¡œë”© ì¤‘...</div></div></div>' +
              '</div>' +
              '</div>' +
              '<div id="tab-personnel" style="display:none">' +
              '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-identification-card"></i> ì¸ì› ë§ˆìŠ¤í„°</div>' +
              '<input type="text" class="search-inline" id="hr-search" placeholder="ì´ë¦„, ID, ì†Œì† ê²€ìƒ‰..."></div>' +
              '<div class="panel-body"><table class="data-table" id="hr-table"><thead><tr><th>ì¸ì›ID</th><th>ì„±ëª…</th><th>ì†Œì†</th><th>ì§ì¢…</th><th>êµ­ì </th><th>ë¹„ìžë§Œë£Œ</th><th>í˜„ìž¥</th><th>ìƒíƒœ</th><th>ì•ˆì „êµìœ¡</th></tr></thead><tbody>' + personnelHtml + '</tbody></table></div></div></div>';
          }

          // ê³µí†µ: íƒ­ ì´ë²¤íŠ¸ + ê²€ìƒ‰
          document.querySelectorAll('#hr-tabs .tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
              document.querySelectorAll('#hr-tabs .tab-btn').forEach(function (b) { b.classList.remove('active'); });
              btn.classList.add('active');
              var tab = btn.dataset.tab;
              document.getElementById('tab-attendance').style.display = tab === 'attendance' ? '' : 'none';
              document.getElementById('tab-personnel').style.display = tab === 'personnel' ? '' : 'none';
            });
          });
          if (window._pendingHrTab) {
            var pendingHrTab = window._pendingHrTab;
            window._pendingHrTab = null;
            var pendingHrTabButton = document.querySelector('#hr-tabs .tab-btn[data-tab="' + pendingHrTab + '"]');
            if (pendingHrTabButton) pendingHrTabButton.click();
          }
          var srch = document.getElementById('hr-search');
          if (srch) {
            srch.addEventListener('input', function () {
              var q = this.value.toLowerCase();
              document.querySelectorAll('#hr-table tbody tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
              });
            });
          }

          // ì¶œê·¼ ìƒì„¸ ë³´ê³  + íšŒì‚¬ë³„ í†µê³„ ë¹„ë™ê¸° ë¡œë“œ (ë‹¨ì¼ í˜„ìž¥ ë·°ì¼ ë•Œë§Œ)
          if (!isGlobal && document.getElementById('attendance-detail-body')) {
            window.loadAttendanceDetail(attendance.date || null);
            window.loadCompanyStats(attendance.date || null);
          }
        } catch (err) { renderError('ì¸ì› ë°ì´í„° ë¡œë”© ì‹¤íŒ¨: ' + err.message); console.error(err); }
      }

      // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      // ðŸ‘¥ ì¶œê·¼ ìƒì„¸ ë³´ê³  â€” íšŒì‚¬ë³„ â†’ íŒ€ë³„ ê·¸ë£¹í•‘ ë Œë”
      // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      window.COMPANY_COLOR = {
        'AUTORICA': '#f59e0b',
        'AUTORICA LLC': '#f59e0b',
        'AI-KOREA': '#3b82f6',
        'AI KOREA': '#3b82f6',
        'M-SOL': '#10b981',
        'MSOL': '#10b981',
        'NAHSHON': '#a78bfa'
      };
      window.getCompanyColor = function(name) {
        var k = String(name || '').toUpperCase().replace(/\s+/g, ' ').trim();
        return window.COMPANY_COLOR[k] || window.COMPANY_COLOR[k.replace(/\s+LLC$/, '').trim()] || '#64748b';
      };
      window.getInitials = function(first, last) {
        var f = String(first || '').trim().charAt(0).toUpperCase();
        var l = String(last  || '').trim().charAt(0).toUpperCase();
        return (f + l) || '?';
      };

      window.loadAttendanceDetail = async function(date) {
        var body = document.getElementById('attendance-detail-body');
        if (!body) return;
        body.innerHTML = '<div style="padding:32px;text-align:center;color:var(--text-tertiary)"><i class="ph ph-spinner ph-spin"></i> ë¡œë”© ì¤‘...</div>';
        try {
          var res = await window.API.getAttendanceDetailed(date);
          if (!res.success || !res.companies || res.companies.length === 0) {
            body.innerHTML = '<div style="padding:48px;text-align:center;color:var(--text-tertiary)"><i class="ph ph-info" style="font-size:32px;display:block;margin-bottom:8px;opacity:0.5"></i>í•´ë‹¹ ë‚ ì§œì— ì¶œê·¼ ê¸°ë¡ì´ ì—†ìŠµë‹ˆë‹¤.</div>';
            return;
          }
          var countEl = document.getElementById('att-detail-count');
          if (countEl) countEl.textContent = 'ì´ ' + res.totalCount + 'ëª… Â· ' + res.companies.length + 'ê°œ íšŒì‚¬';

          var html = res.companies.map(function(c) {
            var compColor = window.getCompanyColor(c.name);
            var teamsHtml = c.teams.map(function(t) {
              var empsHtml = t.employees.map(function(e) {
                var hasPhoto = e.photoUrl && /^https?:\/\//.test(e.photoUrl);
                // Badge ID escape â€” ì•ˆì „í•œ ì˜ìˆ«ìžë§Œ í—ˆìš© (ì‚¬ì´íŠ¸ID ê°™ì€ ìž˜ëª»ëœ ê°’ ì°¨ë‹¨)
                var safeBadge = String(e.badgeId || '').replace(/[^A-Za-z0-9_-]/g, '');
                var avatar = hasPhoto
                  ? '<img src="' + e.photoUrl + '" style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid ' + compColor + '" onerror="this.outerHTML=\'<div style=&quot;width:32px;height:32px;border-radius:50%;background:' + compColor + ';display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:11px;border:2px solid ' + compColor + '&quot;>' + window.getInitials(e.firstName, e.lastName) + '</div>\'">'
                  : '<div style="width:32px;height:32px;border-radius:50%;background:' + compColor + ';display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:11px;border:2px solid ' + compColor + '">' + window.getInitials(e.firstName, e.lastName) + '</div>';
                var nameDisplay = (e.firstName + ' ' + e.lastName).trim() || e.badgeId;
                var statusDot = e.isWorking
                  ? '<span title="ê·¼ë¬´ì¤‘" style="display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--status-success);margin-right:4px;animation:pulse 2s infinite"></span>'
                  : '<span title="í‡´ê·¼ì™„ë£Œ" style="display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--text-tertiary);margin-right:4px"></span>';
                return '<div data-badge="' + safeBadge + '" onclick="window.openEmpInfoModal(this.dataset.badge)" style="display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid var(--border-subtle);cursor:pointer;transition:background 0.15s" onmouseover="this.style.background=\'rgba(167,139,250,0.08)\'" onmouseout="this.style.background=\'\'">' +
                  avatar +
                  '<div style="flex:1;min-width:0">' +
                    '<div style="font-size:13px;font-weight:600;color:var(--text-primary)">' + statusDot + nameDisplay + '</div>' +
                    '<div style="font-size:10px;color:var(--text-tertiary);margin-top:1px">' +
                      '<span class="cell-mono">' + e.badgeId + '</span>' + (e.role ? ' Â· ' + e.role : '') + '</div>' +
                  '</div>' +
                  '<div style="text-align:right;flex-shrink:0">' +
                    '<div class="cell-mono" style="font-size:12px;color:var(--status-success);font-weight:700">' + (e.inTime || '-') + '</div>' +
                    '<div style="font-size:10px;color:' + (e.outTime ? 'var(--text-tertiary)' : 'var(--status-warning)') + '">' + (e.outTime || 'ê·¼ë¬´ì¤‘') + '</div>' +
                  '</div>' +
                  '<i class="ph ph-caret-right" style="color:var(--text-tertiary);font-size:14px;flex-shrink:0"></i>' +
                '</div>';
              }).join('');
              return '<div style="padding:6px 14px;background:rgba(167,139,250,0.05);border-bottom:1px solid var(--border-subtle);font-size:11px;font-weight:600;color:var(--text-secondary);letter-spacing:0.5px;text-transform:uppercase;display:flex;justify-content:space-between;align-items:center">' +
                '<span><i class="ph ph-folder-simple" style="margin-right:6px;color:var(--text-tertiary)"></i>' + t.name + '</span>' +
                '<span style="background:var(--bg-base);padding:2px 8px;border-radius:10px;color:var(--text-tertiary);font-size:10px">' + t.count + '</span>' +
              '</div>' + empsHtml;
            }).join('');
            return '<div style="margin-bottom:0">' +
              '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:linear-gradient(90deg,' + compColor + '22,transparent);border-bottom:1px solid ' + compColor + '44;border-left:3px solid ' + compColor + '">' +
                '<div style="display:flex;align-items:center;gap:10px"><i class="ph ph-buildings" style="color:' + compColor + ';font-size:16px"></i>' +
                  '<span style="font-size:13px;font-weight:700;color:var(--text-primary)">' + c.name + '</span></div>' +
                '<span style="font-size:12px;font-weight:700;color:' + compColor + '">' + c.total + 'ëª…</span>' +
              '</div>' + teamsHtml + '</div>';
          }).join('');
          body.innerHTML = '<div style="max-height:600px;overflow-y:auto">' + html + '</div>';
        } catch(err) {
          body.innerHTML = '<div style="padding:32px;text-align:center;color:var(--status-danger)">ë¡œë”© ì‹¤íŒ¨: ' + err.message + '</div>';
        }
      };

      // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      // ðŸ“Š íšŒì‚¬ë³„/íŒ€ë³„ í†µê³„ ë„í‘œ (Stacked Bar + Horizontal Bar)
      // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      window.loadCompanyStats = async function(date) {
        var body = document.getElementById('company-stats-body');
        if (!body) return;
        body.innerHTML = '<div style="padding:32px;text-align:center;color:var(--text-tertiary)"><i class="ph ph-spinner ph-spin"></i> í†µê³„ ì§‘ê³„ ì¤‘...</div>';
        try {
          var picker = document.getElementById('stats-date-picker');
          if (picker && date) picker.value = date;
          var res = await window.API.getCompanyTeamStats(date);
          if (!res.success || (!res.byCompany.length && !res.byTeam.length)) {
            body.innerHTML = '<div style="padding:48px;text-align:center;color:var(--text-tertiary)"><i class="ph ph-info" style="font-size:32px;display:block;margin-bottom:8px;opacity:0.5"></i>í•´ë‹¹ ë‚ ì§œì— ë°ì´í„°ê°€ ì—†ìŠµë‹ˆë‹¤.</div>';
            return;
          }

          var COLOR_MGR = '#f59e0b', COLOR_KOR = '#3b82f6', COLOR_LOC = '#10b981';
          var maxCompTotal = Math.max.apply(null, res.byCompany.map(function(c){return c.total;})) || 1;
          var maxTeamCount = Math.max.apply(null, res.byTeam.map(function(t){return t.count;})) || 1;

          // íšŒì‚¬ë³„ ëˆ„ì  ë§‰ëŒ€
          var companyHtml = res.byCompany.map(function(c) {
            var compColor = window.getCompanyColor(c.name);
            var w = (c.total / maxCompTotal) * 100;
            var mgrW = c.total > 0 ? (c.manager / c.total) * 100 : 0;
            var korW = c.total > 0 ? (c.korean  / c.total) * 100 : 0;
            var locW = c.total > 0 ? (c.local   / c.total) * 100 : 0;
            return '<div style="margin-bottom:14px">' +
              '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">' +
                '<span style="font-size:12px;font-weight:700;color:var(--text-primary)"><i class="ph ph-buildings" style="color:' + compColor + ';margin-right:6px"></i>' + c.name + '</span>' +
                '<span class="cell-mono" style="font-size:13px;font-weight:700;color:' + compColor + '">' + c.total + 'ëª…</span>' +
              '</div>' +
              '<div style="height:24px;background:var(--bg-base);border-radius:6px;overflow:hidden;display:flex;width:' + w + '%;min-width:60px;border:1px solid var(--border-subtle)">' +
                (c.manager > 0 ? '<div title="관리자 ' + c.manager + 'ëª…" style="width:' + mgrW + '%;background:' + COLOR_MGR + ';display:flex;align-items:center;justify-content:center;color:#000;font-size:10px;font-weight:700">' + (mgrW > 12 ? c.manager : '') + '</div>' : '') +
                (c.korean  > 0 ? '<div title="한국인 ' + c.korean  + 'ëª…" style="width:' + korW + '%;background:' + COLOR_KOR + ';display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700">' + (korW > 12 ? c.korean : '') + '</div>' : '') +
                (c.local   > 0 ? '<div title="외국인 ' + c.local   + 'ëª…" style="width:' + locW + '%;background:' + COLOR_LOC + ';display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700">' + (locW > 12 ? c.local : '') + '</div>' : '') +
              '</div>' +
              '<div style="display:flex;gap:10px;font-size:10px;color:var(--text-tertiary);margin-top:4px">' +
                '<span><span style="display:inline-block;width:8px;height:8px;background:' + COLOR_MGR + ';border-radius:2px;margin-right:4px"></span>관리자 ' + c.manager + '</span>' +
                '<span><span style="display:inline-block;width:8px;height:8px;background:' + COLOR_KOR + ';border-radius:2px;margin-right:4px"></span>한국인 ' + c.korean + '</span>' +
                '<span><span style="display:inline-block;width:8px;height:8px;background:' + COLOR_LOC + ';border-radius:2px;margin-right:4px"></span>외국인 ' + c.local + '</span>' +
              '</div>' +
            '</div>';
          }).join('');

          // íŒ€ë³„ ê°€ë¡œ ë§‰ëŒ€
          var teamHtml = res.byTeam.map(function(t) {
            var w = (t.count / maxTeamCount) * 100;
            return '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">' +
              '<span style="font-size:11px;font-weight:600;color:var(--text-secondary);width:90px;text-align:right;flex-shrink:0">' + t.name + '</span>' +
              '<div style="flex:1;height:18px;background:var(--bg-base);border-radius:4px;overflow:hidden;border:1px solid var(--border-subtle)">' +
                '<div style="height:100%;width:' + w + '%;background:linear-gradient(90deg,#a78bfa,#3b82f6);display:flex;align-items:center;padding:0 6px;color:#fff;font-size:10px;font-weight:700">' + (w > 20 ? t.count : '') + '</div>' +
              '</div>' +
              '<span class="cell-mono" style="font-size:12px;font-weight:700;color:var(--brand-primary);width:32px">' + t.count + '</span>' +
            '</div>';
          }).join('');

          body.innerHTML =
            '<div style="margin-bottom:18px">' +
              '<div style="font-size:11px;color:var(--text-tertiary);font-weight:700;letter-spacing:0.5px;margin-bottom:10px;text-transform:uppercase"><i class="ph ph-buildings" style="margin-right:4px"></i>íšŒì‚¬ë³„ ë¶„í¬ (관리자/한국인/외국인)</div>' +
              companyHtml +
            '</div>' +
            '<div style="border-top:1px solid var(--border-subtle);padding-top:14px">' +
              '<div style="font-size:11px;color:var(--text-tertiary);font-weight:700;letter-spacing:0.5px;margin-bottom:10px;text-transform:uppercase"><i class="ph ph-users-three" style="margin-right:4px"></i>íŒ€ë³„ ì¸ì› í˜„í™©</div>' +
              teamHtml +
            '</div>';
        } catch(err) {
          body.innerHTML = '<div style="padding:32px;text-align:center;color:var(--status-danger)">ë¡œë”© ì‹¤íŒ¨: ' + err.message + '</div>';
        }
      };

      window.shiftStatsDate = function(delta) {
        var picker = document.getElementById('stats-date-picker');
        if (!picker) return;
        var d = new Date(picker.value || new Date().toISOString().slice(0,10));
        d.setDate(d.getDate() + delta);
        var ds = d.toISOString().slice(0, 10);
        picker.value = ds;
        window.loadCompanyStats(ds);
        window.loadAttendanceDetail(ds);
      };

      // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      // ðŸ‘¤ ì§ì› ì¸í¬ ëª¨ë‹¬ (ì‚¬ì§„ + ì •ë³´ + ì•¡ì…˜)
      // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      // ðŸ“· ì§ì› ì‚¬ì§„ â€” ì¹´ë©”ë¼ ì´¬ì˜ / íŒŒì¼ ì—…ë¡œë“œ
      // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      window.ADMIN_PASSWORD = '1234';

      // ì´ë¯¸ì§€ ì••ì¶• (canvas ë¦¬ì‚¬ì´ì¦ˆ â†’ JPEG base64) â€” ì—…ë¡œë“œ íŽ˜ì´ë¡œë“œ ì¶•ì†Œ
      window._compressImage = function(file, maxSize, quality) {
        return new Promise(function(resolve, reject) {
          var reader = new FileReader();
          reader.onload = function(ev) {
            var img = new Image();
            img.onload = function() {
              var w = img.width, h = img.height;
              var ratio = Math.min(maxSize / w, maxSize / h, 1);
              w = Math.round(w * ratio); h = Math.round(h * ratio);
              var canvas = document.createElement('canvas');
              canvas.width = w; canvas.height = h;
              canvas.getContext('2d').drawImage(img, 0, 0, w, h);
              resolve(canvas.toDataURL('image/jpeg', quality || 0.85));
            };
            img.onerror = reject;
            img.src = ev.target.result;
          };
          reader.onerror = reject;
          reader.readAsDataURL(file);
        });
      };

      // ì‚¬ì§„ ì—…ë¡œë“œ í›„ ì¸í¬ ëª¨ë‹¬ ì‚¬ì§„ ì˜ì—­ ê°±ì‹  + í† ìŠ¤íŠ¸
      window._afterPhotoUpload = function(res, badgeId) {
        if (!res || !res.success) {
          alert('ì—…ë¡œë“œ ì‹¤íŒ¨: ' + (res && res.error ? res.error : 'ì•Œ ìˆ˜ ì—†ëŠ” ì˜¤ë¥˜'));
          return;
        }
        // ì´ë¯¸ì§€ ì˜ì—­ ì¦‰ì‹œ ê°±ì‹  (ìºì‹œ ë¬´ë ¥í™”)
        var wrap = document.getElementById('emp-photo-wrapper');
        if (wrap) {
          var compColor = '#a78bfa';
          var fresh = res.fileUrl + '&t=' + Date.now();
          wrap.innerHTML = '<img src="' + fresh + '" style="width:160px;height:160px;border-radius:12px;object-fit:cover;border:3px solid var(--brand-primary)">';
        }
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:var(--status-success);color:white;padding:12px 24px;border-radius:8px;font-size:13px;font-weight:700;z-index:10001;box-shadow:0 8px 24px rgba(0,0,0,0.4)';
        t.textContent = 'âœ… ' + badgeId + ' ì‚¬ì§„ ì—…ë¡œë“œ ì™„ë£Œ';
        document.body.appendChild(t);
        setTimeout(function() { t.remove(); }, 2500);
      };

      // â”€â”€ ì¹´ë©”ë¼ ì´¬ì˜ ëª¨ë‹¬ (ëª¨ë“  ì‚¬ìš©ìž) â”€â”€
      window.empPhotoCapture = async function(badgeId) {
        var SITE_ID_PATTERNS = /^(HFF|NV|LGES|SST|HWH)[-_][A-Z0-9]+$/i;
        if (!badgeId || SITE_ID_PATTERNS.test(badgeId)) return;

        var cam = document.createElement('div');
        cam.id = 'cam-modal';
        cam.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:10002;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:20px';
        cam.innerHTML =
          '<div style="color:white;font-size:14px;font-weight:700"><i class="ph ph-camera"></i> ' + badgeId + ' ì‚¬ì§„ ì´¬ì˜</div>' +
          '<video id="cam-video" autoplay playsinline style="width:min(100%, 480px);max-height:60vh;border-radius:12px;background:#000;border:2px solid var(--brand-primary)"></video>' +
          '<canvas id="cam-canvas" style="display:none"></canvas>' +
          '<div style="display:flex;gap:10px">' +
            '<button id="cam-snap" style="background:var(--brand-primary);color:white;border:none;padding:12px 24px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer"><i class="ph ph-camera"></i> ì´¬ì˜</button>' +
            '<button id="cam-flip" style="background:var(--bg-base);color:white;border:1px solid var(--border-default);padding:12px 16px;border-radius:8px;font-size:13px;cursor:pointer" title="ì „í›„ë°© ì¹´ë©”ë¼ ì „í™˜"><i class="ph ph-arrows-clockwise"></i></button>' +
            '<button id="cam-cancel" style="background:var(--bg-base);color:var(--text-secondary);border:1px solid var(--border-default);padding:12px 24px;border-radius:8px;font-size:13px;cursor:pointer">ì·¨ì†Œ</button>' +
          '</div>' +
          '<div style="color:rgba(255,255,255,0.6);font-size:11px">ESC ë˜ëŠ” [ì·¨ì†Œ] ëˆ„ë¥´ë©´ ë‹«íž˜</div>';
        document.body.appendChild(cam);

        var stream = null;
        var facingMode = 'user';
        var video = document.getElementById('cam-video');

        async function startCam(facing) {
          try {
            if (stream) stream.getTracks().forEach(function(t) { t.stop(); });
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing }, audio: false });
            video.srcObject = stream;
          } catch(err) {
            alert('ì¹´ë©”ë¼ ê¶Œí•œ í•„ìš”: ' + err.message);
            cleanup();
          }
        }
        function cleanup() {
          if (stream) stream.getTracks().forEach(function(t) { t.stop(); });
          var m = document.getElementById('cam-modal'); if (m) m.remove();
          document.removeEventListener('keydown', escH);
        }
        var escH = function(e) { if (e.key === 'Escape') cleanup(); };
        document.addEventListener('keydown', escH);
        document.getElementById('cam-cancel').onclick = cleanup;
        document.getElementById('cam-flip').onclick = function() {
          facingMode = facingMode === 'user' ? 'environment' : 'user';
          startCam(facingMode);
        };
        document.getElementById('cam-snap').onclick = async function() {
          var canvas = document.getElementById('cam-canvas');
          var maxSize = 600;
          var ratio = Math.min(maxSize / video.videoWidth, maxSize / video.videoHeight, 1);
          canvas.width = Math.round(video.videoWidth * ratio);
          canvas.height = Math.round(video.videoHeight * ratio);
          canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
          var b64 = canvas.toDataURL('image/jpeg', 0.85);
          this.disabled = true;
          this.innerHTML = '<i class="ph ph-spinner ph-spin"></i> ì—…ë¡œë“œ ì¤‘...';
          try {
            var res = await window.API.uploadEmployeePhoto(badgeId, b64, 'image/jpeg');
            cleanup();
            window._afterPhotoUpload(res, badgeId);
          } catch(err) {
            alert('ì—…ë¡œë“œ ì‹¤íŒ¨: ' + err.message);
            cleanup();
          }
        };
        startCam(facingMode);
      };

      // â”€â”€ íŒŒì¼ ì—…ë¡œë“œ (관리자 â€” ë¹„ë°€ë²ˆí˜¸ 1234) â”€â”€
      window.empPhotoUpload = async function(badgeId) {
        var SITE_ID_PATTERNS = /^(HFF|NV|LGES|SST|HWH)[-_][A-Z0-9]+$/i;
        if (!badgeId || SITE_ID_PATTERNS.test(badgeId)) return;

        var pw = prompt('ðŸ”’ 관리자 ë¹„ë°€ë²ˆí˜¸ë¥¼ ìž…ë ¥í•˜ì„¸ìš” (' + badgeId + ' ì‚¬ì§„ ì—…ë¡œë“œ)');
        if (pw === null) return;
        if (pw !== window.ADMIN_PASSWORD) {
          alert('âŒ ë¹„ë°€ë²ˆí˜¸ê°€ í‹€ë¦½ë‹ˆë‹¤.');
          return;
        }

        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/webp';
        input.style.display = 'none';
        document.body.appendChild(input);
        input.onchange = async function() {
          var file = input.files && input.files[0];
          input.remove();
          if (!file) return;
          if (file.size > 8 * 1024 * 1024) {
            alert('íŒŒì¼ì´ ë„ˆë¬´ í½ë‹ˆë‹¤ (ìµœëŒ€ 8MB)');
            return;
          }
          // ì§„í–‰ í† ìŠ¤íŠ¸
          var t = document.createElement('div');
          t.style.cssText = 'position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:var(--brand-primary);color:white;padding:12px 24px;border-radius:8px;font-size:13px;font-weight:700;z-index:10001';
          t.innerHTML = '<i class="ph ph-spinner ph-spin"></i> ' + badgeId + ' ì‚¬ì§„ ì—…ë¡œë“œ ì¤‘...';
          document.body.appendChild(t);
          try {
            var b64 = await window._compressImage(file, 600, 0.85);
            var res = await window.API.uploadEmployeePhoto(badgeId, b64, 'image/jpeg');
            t.remove();
            window._afterPhotoUpload(res, badgeId);
          } catch(err) {
            t.remove();
            alert('ì—…ë¡œë“œ ì‹¤íŒ¨: ' + err.message);
          }
        };
        input.click();
      };

      window.openEmpInfoModal = async function(badgeId) {
        // ë””ë²„ê·¸ ë¡œê·¸ â€” ì–´ë””ì„œ ì–´ë–¤ ì¸ìžë¡œ í˜¸ì¶œëëŠ”ì§€ ì¶”ì 
        console.log('[openEmpInfoModal] badgeId:', JSON.stringify(badgeId), 'caller:', new Error().stack.split('\n').slice(1, 4).join(' | '));

        // ì‚¬ì´íŠ¸ ID í˜•ì‹ ì°¨ë‹¨ (ì˜ˆ: HFF-02, NV-05, LGES-AZ, SST-03 ë“±)
        var SITE_ID_PATTERNS = /^(HFF|NV|LGES|SST|HWH)[-_][A-Z0-9]+$/i;
        if (!badgeId || SITE_ID_PATTERNS.test(String(badgeId).trim())) {
          console.warn('[openEmpInfoModal] ì°¨ë‹¨ë¨ â€” ì‚¬ì´íŠ¸ ID ë˜ëŠ” ë¹ˆê°’ì´ Badge ID ìžë¦¬ì— ë“¤ì–´ì˜´:', badgeId);
          return;
        }
        // Badge IDëŠ” ë³´í†µ 4-7ìž (TF65, TK01 ë“±). ë„ˆë¬´ ê¸¸ë©´ ìž˜ëª»ëœ í˜¸ì¶œ
        if (String(badgeId).length > 12) {
          console.warn('[openEmpInfoModal] ì°¨ë‹¨ë¨ â€” Badge IDê°€ ë„ˆë¬´ ê¹€:', badgeId);
          return;
        }

        var modal = document.createElement('div');
        modal.id = 'emp-info-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)';
        modal.innerHTML = '<div style="color:white"><i class="ph ph-spinner ph-spin" style="font-size:32px"></i></div>';
        document.body.appendChild(modal);

        var dismissModal = function() { var m = document.getElementById('emp-info-modal'); if (m) m.remove(); document.removeEventListener('keydown', escHandler); };
        var escHandler = function(e) { if (e.key === 'Escape') dismissModal(); };
        document.addEventListener('keydown', escHandler);
        modal.addEventListener('click', function(e) { if (e.target === modal) dismissModal(); });

        try {
          var res = await window.API.getEmployeeDetail(badgeId);
          if (!res.success || !res.employee) {
            var debugInfo = '';
            if (res.totalRowsInSheet) debugInfo += '<div style="margin-top:8px;font-size:11px;color:var(--text-tertiary)">ì‹œíŠ¸ ì „ì²´: ' + res.totalRowsInSheet + 'ëª…</div>';
            if (res.availableBadgesPreview) debugInfo += '<div style="margin-top:4px;font-size:10px;color:var(--text-tertiary);font-family:monospace;word-break:break-all">ìƒ˜í”Œ: ' + res.availableBadgesPreview + '</div>';
            if (res.sheetName) debugInfo += '<div style="margin-top:4px;font-size:10px;color:var(--text-tertiary)">ì‹œíŠ¸ëª…: ' + res.sheetName + '</div>';
            modal.innerHTML = '<div style="background:var(--bg-panel);padding:24px;border-radius:12px;color:var(--text-primary);max-width:500px">' +
              '<div style="color:var(--status-danger);font-weight:700;margin-bottom:8px"><i class="ph ph-warning"></i> ì§ì› ì •ë³´ë¥¼ ì°¾ì„ ìˆ˜ ì—†ìŠµë‹ˆë‹¤</div>' +
              '<div style="color:var(--text-secondary);font-size:13px">' + (res.error || ('Badge ID: ' + badgeId)) + '</div>' +
              debugInfo +
              '<br><button onclick="document.getElementById(\'emp-info-modal\').remove()" class="btn-secondary">ë‹«ê¸°</button></div>';
            return;
          }
          var e = res.employee;
          var compColor = window.getCompanyColor(e.company);
          var hasPhoto = e.photoUrl && /^https?:\/\//.test(e.photoUrl);
          var avatarBlock = hasPhoto
            ? '<img src="' + e.photoUrl + '" style="width:160px;height:160px;border-radius:12px;object-fit:cover;border:3px solid ' + compColor + '" onerror="this.outerHTML=\'<div style=&quot;width:160px;height:160px;border-radius:12px;background:linear-gradient(135deg,' + compColor + ',' + compColor + 'AA);display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:48px;border:3px solid ' + compColor + '&quot;>' + window.getInitials(e.firstName, e.lastName) + '</div>\'">'
            : '<div style="width:160px;height:160px;border-radius:12px;background:linear-gradient(135deg,' + compColor + ',' + compColor + 'AA);display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:48px;border:3px solid ' + compColor + '">' + window.getInitials(e.firstName, e.lastName) + '</div>';

          var natFlag = '';
          if (e.divide === '한국인' || /korea/i.test(e.nationality)) natFlag = 'ðŸ‡°ðŸ‡· 한국인';
          else if (e.divide === '관리자') natFlag = 'ðŸ‘‘ 관리자';
          else natFlag = 'ðŸŒ 외국인';

          var fullName = (e.firstName + ' ' + e.lastName).trim() || badgeId;
          var workingBadge = e.todayWorking
            ? '<span style="background:rgba(16,185,129,0.15);color:var(--status-success);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--status-success);margin-right:4px;animation:pulse 2s infinite"></span>ê·¼ë¬´ì¤‘</span>'
            : (e.todayInTime
                ? '<span style="background:rgba(100,116,139,0.15);color:var(--text-tertiary);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700">í‡´ê·¼ì™„ë£Œ</span>'
                : '<span style="background:rgba(245,158,11,0.15);color:var(--status-warning);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700">ë¯¸ì¶œê·¼</span>');

          function infoRow(label, val, mono) {
            if (!val) val = '<span style="color:var(--text-tertiary)">â€”</span>';
            return '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-subtle)">' +
              '<span style="font-size:11px;color:var(--text-tertiary);font-weight:600;letter-spacing:0.3px;text-transform:uppercase">' + label + '</span>' +
              '<span ' + (mono ? 'class="cell-mono"' : '') + ' style="font-size:13px;color:var(--text-primary);font-weight:600;text-align:right">' + val + '</span>' +
            '</div>';
          }

          modal.innerHTML =
            '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:14px;width:680px;max-width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 48px rgba(0,0,0,0.5)">' +
              '<div style="padding:20px;border-bottom:1px solid var(--border-default);display:flex;justify-content:space-between;align-items:center;background:linear-gradient(90deg,' + compColor + '22,transparent)">' +
                '<div style="display:flex;align-items:center;gap:10px">' +
                  '<i class="ph ph-identification-badge" style="font-size:20px;color:' + compColor + '"></i>' +
                  '<span style="font-size:14px;font-weight:700;color:var(--text-primary)">ì§ì› ì •ë³´</span>' +
                  workingBadge +
                '</div>' +
                '<button onclick="document.getElementById(\'emp-info-modal\').remove()" style="background:var(--bg-base);border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;color:var(--text-secondary);font-size:18px">Ã—</button>' +
              '</div>' +
              '<div style="padding:24px;display:grid;grid-template-columns:160px 1fr;gap:24px">' +
                '<div style="display:flex;flex-direction:column;align-items:center;gap:12px">' +
                  '<div id="emp-photo-wrapper" style="position:relative">' + avatarBlock + '</div>' +
                  // ì¹´ë©”ë¼ / ì—…ë¡œë“œ ë²„íŠ¼
                  '<div style="display:flex;gap:6px;width:100%">' +
                    '<button onclick="window.empPhotoCapture(\'' + e.badgeId + '\')" style="flex:1;background:var(--bg-base);border:1px solid var(--border-default);color:var(--text-primary);padding:7px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:4px" title="ì¹´ë©”ë¼ë¡œ ì´¬ì˜">' +
                      '<i class="ph ph-camera" style="font-size:13px"></i>ì´¬ì˜</button>' +
                    '<button onclick="window.empPhotoUpload(\'' + e.badgeId + '\')" style="flex:1;background:var(--bg-base);border:1px solid var(--border-default);color:var(--text-secondary);padding:7px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:4px" title="관리자: íŒŒì¼ ì—…ë¡œë“œ (ë¹„ë°€ë²ˆí˜¸)">' +
                      '<i class="ph ph-upload-simple" style="font-size:13px"></i>ì—…ë¡œë“œ<i class="ph ph-lock-key" style="font-size:10px;opacity:0.6"></i></button>' +
                  '</div>' +
                  '<div style="text-align:center">' +
                    '<div style="font-size:14px;color:var(--text-tertiary);margin-bottom:2px">' + natFlag + '</div>' +
                    '<div class="cell-mono" style="font-size:11px;color:var(--text-tertiary);background:var(--bg-base);padding:3px 8px;border-radius:6px;display:inline-block">' + e.badgeId + '</div>' +
                  '</div>' +
                '</div>' +
                '<div>' +
                  '<div style="margin-bottom:18px">' +
                    '<div style="font-size:22px;font-weight:800;color:var(--text-primary);line-height:1.2">' + fullName + '</div>' +
                    '<div style="font-size:13px;color:var(--text-secondary);margin-top:4px">' +
                      '<span style="background:' + compColor + '22;color:' + compColor + ';padding:3px 10px;border-radius:6px;font-weight:700;font-size:11px">' + (e.company || '-') + '</span>' +
                      (e.todayTeam ? '  <span style="color:var(--text-tertiary);font-size:11px">Â·</span>  <strong>' + e.todayTeam + '</strong>' : '') +
                      (e.role ? '  <span style="color:var(--text-tertiary);font-size:11px">Â·</span>  ' + e.role : '') +
                    '</div>' +
                  '</div>' +
                  // ì˜¤ëŠ˜ ì¶œí‡´ê·¼ ì¹´ë“œ
                  '<div style="background:var(--bg-base);border-radius:10px;padding:14px;margin-bottom:14px;border:1px solid var(--border-subtle)">' +
                    '<div style="font-size:10px;color:var(--text-tertiary);font-weight:700;letter-spacing:0.5px;margin-bottom:8px;text-transform:uppercase"><i class="ph ph-clock"></i> ì˜¤ëŠ˜ ì¶œí‡´ê·¼</div>' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
                      '<div><div style="font-size:10px;color:var(--text-tertiary)">ì¶œê·¼</div><div class="cell-mono" style="font-size:18px;font-weight:800;color:var(--status-success)">' + (e.todayInTime || 'â€”') + '</div></div>' +
                      '<div><div style="font-size:10px;color:var(--text-tertiary)">í‡´ê·¼</div><div class="cell-mono" style="font-size:18px;font-weight:800;color:' + (e.todayOutTime ? 'var(--text-primary)' : 'var(--status-warning)') + '">' + (e.todayOutTime || 'ê·¼ë¬´ì¤‘') + '</div></div>' +
                    '</div>' +
                    (e.todayShift ? '<div style="margin-top:8px;font-size:11px;color:var(--text-tertiary)">ê·¼ë¬´í˜•íƒœ: <strong style="color:var(--text-secondary)">' + e.todayShift + '</strong></div>' : '') +
                  '</div>' +
                  // ìƒì„¸ ì •ë³´
                  '<div>' +
                    infoRow('NFC UID', '<span class="cell-mono" style="font-size:11px">' + (e.nfcUid || '') + '</span>') +
                    infoRow('ì „í™”', e.phone || '', true) +
                    infoRow('ì´ë©”ì¼', e.email ? '<a href="mailto:' + e.email + '" style="color:var(--brand-primary)">' + e.email + '</a>' : '') +
                    infoRow('êµ­ì ', e.nationality || '') +
                    infoRow('ìƒíƒœ', e.status || '') +
                    infoRow('ë°œê¸‰ì¼', e.issueDate || '', true) +
                    (e.currentRole ? infoRow('í˜„ìž¬ ì—­í• ', e.currentRole) : '') +
                  '</div>' +
                '</div>' +
              '</div>' +
              // ì•¡ì…˜ ë²„íŠ¼
              '<div style="padding:16px 24px;border-top:1px solid var(--border-default);display:flex;gap:10px;justify-content:flex-end;background:var(--bg-base)">' +
                '<button onclick="document.getElementById(\'emp-info-modal\').remove()" class="btn-secondary">ë‹«ê¸°</button>' +
                '<button onclick="window.open(\'' + e.sheetUrl + '\', \'_blank\')" class="btn-primary"><i class="ph ph-arrow-square-out"></i> ë§ˆìŠ¤í„° ì‹œíŠ¸ ì—´ê¸°</button>' +
              '</div>' +
            '</div>';
        } catch(err) {
          modal.innerHTML = '<div style="background:var(--bg-panel);padding:24px;border-radius:12px;color:var(--status-danger);max-width:400px">ë¡œë”© ì‹¤íŒ¨: ' + err.message + '<br><br><button onclick="document.getElementById(\'emp-info-modal\').remove()" class="btn-secondary">ë‹«ê¸°</button></div>';
        }
      };

      // â”€â”€ FINANCE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      window.deleteFinanceExpense = async function(deleteUrl) {
        if (!deleteUrl) return;
        if (!confirm('이 영수증 처리를 삭제할까요?')) return;

        try {
          const tokenEl = document.querySelector('meta[name="csrf-token"]');
          const response = await fetch(deleteUrl, {
            method: 'DELETE',
            headers: {
              'Accept': 'application/json, text/html',
              'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
            }
          });

          if (!response.ok) throw new Error('HTTP ' + response.status);
          window.apiCache = {};
          await renderFinance();
        } catch (err) {
          console.error(err);
          alert('삭제에 실패했습니다. 새로고침 후 다시 시도해 주세요.');
        }
      };

      async function renderFinance() {
        pageContainer.innerHTML = skeleton();
        try {
          var [stats, expenses] = await Promise.all([
            window.API.getFinanceStats(),
            window.API.getExpenses()
          ]);

          stats = stats || {};
          expenses = Array.isArray(expenses) ? expenses : [];

          var mtdBudget = Number(stats.mtdBudget || 0);
          var mtdTotal = Number(stats.mtdTotal || 0);
          var budgetBalance = Number((stats.budgetBalance !== undefined ? stats.budgetBalance : (mtdBudget - mtdTotal)) || 0);
          var budgetPct = mtdBudget > 0 ? Math.min(100, Math.round(mtdTotal / mtdBudget * 100)) : 0;
          var pendingApproval = Number(stats.pendingApproval || 0);
          var pendingAmount = Number(stats.pendingAmount || 0);

          function financeStatusLabel(status) {
            var labels = {
              draft: '임시저장',
              pending: '승인대기',
              approved: '승인완료',
              paid: '지급완료',
              rejected: '반려됨'
            };
            return labels[status] || status || '-';
          }

          var expensesHtml = expenses.map(function (ex) {
            var amtStyle = ex.amount >= 500 ? 'color:var(--status-warning);font-weight:600' : '';
            var receiptLink = ex.receiptUrl ? '<a href="' + safeHtml(ex.receiptUrl) + '" target="_blank" class="btn-primary" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;text-decoration:none;padding:0" title="영수증 보기"><i class="ph ph-image" style="font-size:18px"></i></a>' : '';
            var editLink = ex.editUrl ? '<a href="' + safeHtml(ex.editUrl) + '" class="btn-secondary" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;text-decoration:none;padding:0" title="수정"><i class="ph ph-pencil-simple" style="font-size:17px"></i></a>' : '';
            var deleteButton = ex.deleteUrl ? '<button type="button" class="btn-secondary finance-delete-expense" data-delete-url="' + safeHtml(ex.deleteUrl) + '" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;padding:0;color:var(--status-danger);border-color:var(--status-danger)" title="삭제"><i class="ph ph-trash" style="font-size:17px"></i></button>' : '';
            var actions = (receiptLink || editLink || deleteButton)
              ? '<div style="display:flex;gap:6px;align-items:center;justify-content:flex-end">' + receiptLink + editLink + deleteButton + '</div>'
              : '<span style="color:var(--text-tertiary)">-</span>';
            var siteName = (ex.site && ex.site !== '-') ? '<span class="tag">' + ex.site + '</span>' : '<span style="color:var(--text-tertiary)">-</span>';
            var accountName = (ex.account && ex.account !== '-') ? safeHtml(ex.account) : '<span style="color:var(--text-tertiary)">-</span>';
            var linkedBudget = ex.preApprovalTitle
              ? '<div style="font-size:11px;color:var(--status-success);margin-top:4px">연결 예산: ' + safeHtml(ex.preApprovalTitle) + (ex.preApprovalAmount ? ' · ' + fmtUSD(ex.preApprovalAmount) : '') + '</div>'
              : '';
            var employee = ex.employeeName ? '<div style="font-size:11px;color:var(--text-tertiary);margin-top:4px">제출자: ' + safeHtml(ex.employeeName) + '</div>' : '';
            return '<tr><td class="cell-mono">' + safeHtml(ex.date || '-') + '</td><td>' + siteName + '</td><td>' + accountName + '</td><td class="cell-primary">' + safeHtml(ex.detail || '-') + '<div style="font-size:11px;color:var(--text-tertiary);margin-top:4px">상태: ' + financeStatusLabel(ex.status) + '</div>' + linkedBudget + employee + '</td><td class="cell-mono" style="text-align:right;' + amtStyle + '">' + fmtUSD(ex.amount) + '</td><td>' + actions + '</td></tr>';
          }).join('');

          if (!expensesHtml) {
            expensesHtml = '<tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);padding:32px">등록된 비용 내역이 없습니다.</td></tr>';
          }

          var categoryHtml = (stats.byCategory || []).map(function (c) {
            var pct = mtdTotal > 0 ? Math.round(Number(c.amount || 0) / mtdTotal * 100) : 0;
            return '<div><div style="display:flex;justify-content:space-between;margin-bottom:5px"><span style="font-size:12px;color:var(--text-primary)">' + safeHtml(c.name || 'Other') + '</span><span class="cell-mono" style="font-size:12px">' + fmtUSD(c.amount) + '</span></div>' +
              '<div class="progress-wrapper"><div class="progress-bar"><div class="progress-fill" style="width:' + pct + '%;background:' + c.color + '"></div></div><span class="progress-text" style="color:var(--text-tertiary)">' + pct + '%</span></div></div>';
          }).join('');

          if (!categoryHtml) {
            categoryHtml = '<div style="text-align:center;color:var(--text-tertiary);padding:24px">이번 달 지출 분류가 없습니다.</div>';
          }

          var expenseWizardUrl = '/mobile-expense/wizard-ai';
          if (_siteId() && _siteId() !== 'ALL') {
            expenseWizardUrl += '?site=' + encodeURIComponent(_siteId());
          }

          pageContainer.innerHTML =
            '<div class="header-section"><div><h1 class="page-title">ìž¬ë¬´ / ë¹„ìš© ê´€ë¦¬</h1><p class="page-subtitle">ë¹„ìš© ì œì¶œ ë‚´ì—­ Â· ìŠ¹ì¸ ëŒ€ê¸° Â· ì²­êµ¬ í˜„í™©</p></div>' +
            '<div class="action-row" style="flex-wrap: wrap; gap: 8px;">' +
            '  <a href="/mobile-expense/index" class="btn-secondary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;height:38px;padding:0 14px;border-radius:6px;"><i class="ph ph-receipt" style="font-size:16px"></i> 비용/영수증 목록</a>' +
            '  <a href="/expense-pre-approval/index" class="btn-secondary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;height:38px;padding:0 14px;border-radius:6px;"><i class="ph ph-hand-coins" style="font-size:16px"></i> 사전 예산 승인</a>' +
            '  <a href="' + expenseWizardUrl + '" class="btn-primary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;height:38px;padding:0 14px;border-radius:6px;background:linear-gradient(135deg,#7c3aed,#2563eb);border:none;"><i class="ph ph-magic-wand" style="font-size:16px"></i> AI 경비 등록</a>' +
            '  <button class="btn-secondary" style="height:38px;padding:0 14px;border-radius:6px;" onclick="window.print()"><i class="ph ph-printer"></i> 지출내역 출력</button>' +
            '</div></div>' +
            '<div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">' +
            '<div class="kpi-card"><div class="kpi-label">ì´ ìˆ˜ì£¼ ê¸ˆì•¡ (ì˜ˆì‚°)<i class="ph ph-buildings" style="font-size:14px;color:var(--brand-primary)"></i></div><div class="kpi-value">' + fmtUSD(stats.mtdBudget) + '</div>' +
            '<div class="kpi-meta"><div class="progress-bar" style="flex:1"><div class="progress-fill" style="width:' + budgetPct + '%;background:var(--brand-primary)"></div></div><span style="color:var(--text-secondary);margin-left:6px">ì†Œì§„ìœ¨ ' + budgetPct + '%</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ê¸°ì„± ìˆ˜ê¸ˆì•¡ (ê³ ê°ì‚¬ ì§€ê¸‰)<i class="ph ph-hand-coins" style="font-size:14px;color:var(--status-success)"></i></div><div class="kpi-value" style="color:var(--status-success)">' + fmtUSD(stats.claimable) + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">í˜„ìž¬ê¹Œì§€ ìž…ê¸ˆ(ìˆ˜ë ¹)ëœ ê³„ì•½ê¸ˆ</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ëˆ„ì  ì§€ì¶œ ê¸ˆì•¡ (ë¹„ìš©)<i class="ph ph-credit-card" style="font-size:14px;color:var(--status-warning)"></i></div><div class="kpi-value" style="color:var(--status-warning)">' + fmtUSD(stats.mtdTotal) + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">ë°œìƒí•œ ì „ì²´ ê³µì‚¬ ì§€ì¶œì•¡</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ì‹¤í–‰ ì˜ˆì‚° ìž”ì•¡<i class="ph ph-piggy-bank" style="font-size:14px;color:var(--text-tertiary)"></i></div><div class="kpi-value">' + fmtUSD(stats.mtdBudget - stats.mtdTotal) + '</div><div class="kpi-meta"><span class="trend-' + ((stats.mtdBudget - stats.mtdTotal) >= 0 ? 'up' : 'down') + '"><i class="ph ph-line-segments"></i></span><span style="color:var(--text-secondary)">ê°€ìš© ê°€ëŠ¥ ìž”ì—¬ ì˜ˆì‚° (ì´ìµê¸ˆ)</span></div></div>' +
            '</div>' +

            '<div class="dashboard-grid-main" style="grid-template-columns:2fr 1fr">' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-list-bullets"></i> ë¹„ìš© ì œì¶œ ë‚´ì—­</div>' +
            '<div style="display:flex; gap:8px; align-items:center;"><button id="btn-fin-export" class="btn-secondary" style="font-size:12px; padding:6px 12px; height: 36px;" onclick="window.downloadFinanceExcel()"><i class="ph ph-download-simple"></i> ë§ˆìŠ¤í„° ì—‘ì…€ ë‹¤ìš´ë¡œë“œ</button>' +
            '<input type="text" class="search-inline" id="fin-search" placeholder="ë‚´ì—­ ê²€ìƒ‰..."></div></div>' +
            '<div class="panel-body"><table class="data-table" id="fin-table"><thead><tr>' +
            '<th>ë‚ ì§œ</th><th>í˜„ìž¥</th><th>ê³„ì •</th><th>ì„¸ë¶€ë‚´ì—­</th><th style="text-align:right">ê¸ˆì•¡</th><th style="text-align:right">관리</th>' +
            '</tr></thead><tbody>' + expensesHtml + '</tbody></table></div></div>' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-chart-pie-slice"></i> ê³„ì •ë³„ ì§€ì¶œí˜„í™©</div></div>' +
            '<div class="panel-body padded" style="display:flex;flex-direction:column;gap:14px">' + categoryHtml + '</div></div>' +
            '</div>';

          document.getElementById('fin-search').addEventListener('input', function () {
            var q = this.value.toLowerCase();
            document.querySelectorAll('#fin-table tbody tr').forEach(function (row) {
              row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
          });
          document.querySelectorAll('.finance-delete-expense').forEach(function (button) {
            button.addEventListener('click', function () {
              window.deleteFinanceExpense(this.dataset.deleteUrl);
            });
          });
        } catch (err) { renderError('ìž¬ë¬´ ë°ì´í„° ë¡œë”© ì‹¤íŒ¨'); console.error(err); }
      }

      // â”€â”€ INVENTORY â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      // ì¹´í…Œê³ ë¦¬ë³„ ì•„ì´ì½˜ + ìƒ‰ìƒ
      window.INV_CATEGORY_META = {
        '중장비':   { icon: 'ph-truck',          color: '#f59e0b', prefix: 'EQ' },
        '전동공구': { icon: 'ph-screwdriver',    color: '#3b82f6', prefix: 'TL' },
        '측정기':   { icon: 'ph-gauge',          color: '#a78bfa', prefix: 'IN' },
        '수공구':   { icon: 'ph-wrench',         color: '#10b981', prefix: 'HT' },
        '안전장비': { icon: 'ph-shield-check',   color: '#ef4444', prefix: 'SF' },
        'ì¤‘ìž¥ë¹„':   { icon: 'ph-truck',          color: '#f59e0b', prefix: 'EQ' },
        'ì „ë ™ê³µêµ¬': { icon: 'ph-screwdriver',    color: '#3b82f6', prefix: 'TL' },
        'ì¸¡ì •ê¸°':   { icon: 'ph-gauge',          color: '#a78bfa', prefix: 'IN' },
        'ìˆ˜ê³µêµ¬':   { icon: 'ph-wrench',         color: '#10b981', prefix: 'HT' },
        'ì•ˆì „ìž¥ë¹„': { icon: 'ph-shield-check',   color: '#ef4444', prefix: 'SF' },
        'Power Tool (전동공구)': { icon: 'ph-screwdriver', color: '#3b82f6', prefix: 'TL' },
        'Hand Tool (수공구)': { icon: 'ph-wrench', color: '#10b981', prefix: 'HT' },
        'Pipes & Fittings (배관 자재)': { icon: 'ph-line-segments', color: '#06b6d4', prefix: 'PP' },
        'Conduit & Electrical (전선관/전기 자재)': { icon: 'ph-lightning', color: '#f59e0b', prefix: 'EL' },
        'Wires & Cables (전선/케이블)': { icon: 'ph-sketch-logo', color: '#a78bfa', prefix: 'CB' },
        'Valves & Controls (밸브/계측기)': { icon: 'ph-gauge', color: '#ec4899', prefix: 'VL' },
        'Fasteners & Anchors (체결류/피스)': { icon: 'ph-nut', color: '#64748b', prefix: 'FT' },
        'Generator & Power (발전기/동력원)': { icon: 'ph-lightning-slash', color: '#eab308', prefix: 'GN' },
        'Welding Machine (용접기)': { icon: 'ph-flame', color: '#f97316', prefix: 'WD' },
        'Heavy Equipment (중장비)': { icon: 'ph-truck', color: '#ef4444', prefix: 'EQ' },
        'Safety & PPE (안전 용품)': { icon: 'ph-shield-check', color: '#10b981', prefix: 'SF' },
        'Other Materials (기타 자재/공구)': { icon: 'ph-package', color: '#94a3b8', prefix: 'INV' }
      };
      window.getInvCatMeta = function(cat) {
        return window.INV_CATEGORY_META[cat] || { icon: 'ph-package', color: '#94a3b8', prefix: 'INV' };
      };

      async function renderInventory() {
        pageContainer.innerHTML = skeleton();
        try {
          var res = await window.API.getInventoryDashboard();
          if (!res || !res.success) {
            pageContainer.innerHTML =
              '<div class="header-section"><div><h1 class="page-title">ìžìž¬ / ìž¥ë¹„ (Inventory)</h1>' +
              '<p class="page-subtitle">íšŒì‚¬ ë³´ìœ  ìžì‚° â€” ì‚¬ì§„ AI ë“±ë¡</p></div></div>' +
              '<div class="panel"><div class="panel-body padded">' +
                '<div style="color:var(--status-warning);text-align:center;padding:24px">' +
                'âš  ì‹œíŠ¸ ë¯¸ì´ˆê¸°í™” â€” Apps Scriptì—ì„œ ë‹¤ìŒ í•¨ìˆ˜ 1íšŒì”© ì‹¤í–‰ í•„ìš”:<br><br>' +
                '<strong>1. setupInventorySheets</strong> â€” ì‹œíŠ¸ 3ê°œ ìžë™ ìƒì„±<br>' +
                '<strong>2. setupInventoryFolders</strong> â€” Drive í´ë” 6ê°œ ìžë™ ìƒì„±<br>' +
                '<small style="color:var(--text-tertiary)">' + (res && res.error || '') + '</small>' +
                '<br><br><button class="btn-primary" onclick="window.bootstrapInventory()"><i class="ph ph-rocket-launch"></i> ìžë™ ì…‹ì—… ì‹¤í–‰</button>' +
                '</div></div></div>';
            return;
          }

          var totals = res.totals || {};
          var matrix = res.matrix || { categories: [], sites: [], cells: {} };
          var recent = res.recent || [];
          var inspections = res.upcomingInspections || [];

          // â”€â”€ 1. í—¤ë” + ì•¡ì…˜ â”€â”€
          var headerHtml =
            '<div class="header-section"><div><h1 class="page-title">ìžìž¬ / ìž¥ë¹„ (Inventory)</h1>' +
            '<p class="page-subtitle">íšŒì‚¬ ë³´ìœ  ìžì‚° Â· ì‚¬ì§„ AI ë“±ë¡ Â· 5ê°œ ì¹´í…Œê³ ë¦¬</p></div>' +
            '<div class="action-row">' +
              '<button class="btn-secondary" onclick="window.refreshInventory()"><i class="ph ph-arrow-clockwise"></i> ìƒˆë¡œê³ ì¹¨</button>' +
              '<button class="btn-secondary" onclick="window.openMasterSheet()"><i class="ph ph-table"></i> ì‹œíŠ¸ ë§ˆìŠ¤í„°</button>' +
              '<button class="btn-primary" style="background:linear-gradient(135deg,#7c3aed,#2563eb);border:none" onclick="window.runAIInventoryRegister()"><i class="ph ph-robot"></i> ðŸ¤– AI ì‚¬ì§„ ë“±ë¡</button>' +
            '</div></div>';

          // â”€â”€ 2. KPI 5ì¢… â”€â”€
          var kpiHtml =
            '<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px">' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">ì´ ìžì‚°ê°€ì¹˜<i class="ph ph-currency-dollar" style="font-size:12px;color:#a78bfa"></i></div>' +
                '<div class="kpi-value cell-mono" style="font-size:22px;color:#a78bfa;line-height:1.1">$' + Number(totals.value || 0).toLocaleString() + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">ì „ì²´ ' + (totals.count || 0) + 'ê°œ</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">ì‚¬ìš©ì¤‘/í˜„ìž¥<i class="ph ph-truck" style="font-size:12px;color:#10b981"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;color:#10b981;line-height:1.1">' + (totals.inUse || 0) + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">í˜„ìž¥ ë°°ì¹˜</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">ì°½ê³  ë³´ê´€<i class="ph ph-package" style="font-size:12px;color:#3b82f6"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;color:#3b82f6;line-height:1.1">' + (totals.inStorage || 0) + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">ì¦‰ì‹œ ì‚¬ìš© ê°€ëŠ¥</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">ìˆ˜ë¦¬ì¤‘<i class="ph ph-wrench" style="font-size:12px;color:#f59e0b"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;color:#f59e0b;line-height:1.1">' + (totals.repair || 0) + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">ì‚¬ìš© ë¶ˆê°€</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">ì ê²€ ìž„ë°•<i class="ph ph-warning-circle" style="font-size:12px;color:var(--status-danger)"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;color:' + (totals.inspectionDue > 0 ? 'var(--status-danger)' : 'var(--status-success)') + ';line-height:1.1">' + (totals.inspectionDue || 0) + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">30ì¼ ì´ë‚´</span></div></div>' +
            '</div>';

          // â”€â”€ 3. ì¹´í…Œê³ ë¦¬ Ã— í˜„ìž¥ ë§¤íŠ¸ë¦­ìŠ¤ â”€â”€
          var matrixHtml = '';
          if (matrix.sites.length > 0) {
            var thSites = matrix.sites.map(function(s) {
              var icon = s === 'ì°½ê³ ' ? 'ph-package' : (s === 'ë³´ìœ ìž' ? 'ph-user' : 'ph-buildings');
              return '<th style="padding:10px 8px;background:linear-gradient(180deg,rgba(167,139,250,0.18),rgba(167,139,250,0.05));border-bottom:1px solid var(--border-default);color:var(--text-primary);font-size:11px;font-weight:700;letter-spacing:0.3px;text-align:center;text-transform:uppercase">' +
                '<i class="ph ' + icon + '" style="font-size:13px;margin-right:4px;color:#a78bfa"></i>' + s + '</th>';
            }).join('');

            var rowsHtml = matrix.categories.map(function(cat) {
              var meta = window.getInvCatMeta(cat);
              var cells = matrix.sites.map(function(site) {
                var v = (matrix.cells[cat] && matrix.cells[cat][site]) || 0;
                var emptyClass = v === 0 ? 'opacity:0.25' : '';
                return '<td style="padding:0;border-bottom:1px solid var(--border-subtle);text-align:center;position:relative;height:42px;cursor:pointer;' + emptyClass + '" ' +
                  'onclick="window.filterInventory(\'' + cat + '\', \'' + site + '\')">' +
                  '<span class="cell-mono" style="font-size:15px;font-weight:700;color:' + (v > 0 ? meta.color : 'var(--text-tertiary)') + '">' + v + '</span></td>';
              }).join('');
              return '<tr>' +
                '<td style="padding:10px 14px;border-bottom:1px solid var(--border-subtle);background:var(--bg-base);text-align:left;font-weight:600;font-size:12px;white-space:nowrap">' +
                '<i class="ph ' + meta.icon + '" style="color:' + meta.color + ';margin-right:8px;font-size:14px"></i>' + cat + '</td>' +
                cells + '</tr>';
            }).join('');

            var subtotalCells = matrix.sites.map(function(site) {
              var sum = 0;
              matrix.categories.forEach(function(cat) {
                sum += (matrix.cells[cat] && matrix.cells[cat][site]) || 0;
              });
              return '<td style="padding:12px 8px;background:linear-gradient(180deg,rgba(167,139,250,0.18),transparent);text-align:center">' +
                '<span class="cell-mono" style="font-size:16px;font-weight:800;color:#a78bfa">' + sum + '</span></td>';
            }).join('');

            matrixHtml =
              '<div class="panel" style="margin-bottom:14px;overflow:hidden">' +
                '<div class="panel-header" style="background:linear-gradient(90deg,rgba(167,139,250,0.10),transparent);padding:14px 18px">' +
                  '<div class="panel-title" style="display:flex;align-items:center;gap:10px">' +
                    '<i class="ph ph-chart-bar" style="font-size:18px;color:#a78bfa"></i>' +
                    '<span style="color:var(--text-primary);font-weight:700;font-size:14px">ì¹´í…Œê³ ë¦¬ Ã— ìœ„ì¹˜ ë§¤íŠ¸ë¦­ìŠ¤</span>' +
                    '<span style="font-size:10px;padding:3px 8px;background:rgba(167,139,250,0.15);color:#a78bfa;border-radius:4px;font-weight:600">ì…€ í´ë¦­ ì‹œ í•„í„°</span>' +
                  '</div></div>' +
                '<div class="panel-body" style="padding:0"><div style="overflow-x:auto">' +
                  '<table style="width:100%;border-collapse:collapse"><thead>' +
                    '<tr><th style="padding:10px 14px;background:linear-gradient(180deg,rgba(167,139,250,0.18),rgba(167,139,250,0.05));border-bottom:1px solid var(--border-default);color:var(--text-tertiary);font-size:10px;font-weight:700;letter-spacing:1px;text-align:left;text-transform:uppercase">ì¹´í…Œê³ ë¦¬</th>' +
                    thSites + '</tr>' +
                  '</thead><tbody>' + rowsHtml +
                    '<tr style="border-top:2px solid var(--border-default)"><td style="padding:12px 14px;background:linear-gradient(90deg,rgba(167,139,250,0.18),rgba(167,139,250,0.08));text-align:left;font-weight:700;font-size:13px"><i class="ph ph-equals" style="color:#a78bfa;margin-right:8px"></i>ì†Œ ê³„</td>' +
                    subtotalCells + '</tr>' +
                  '</tbody></table>' +
                '</div></div></div>';
          }

          // â”€â”€ 4. ìµœê·¼ ë“±ë¡ ì‚¬ì§„ ê°¤ëŸ¬ë¦¬ â”€â”€
          var recentHtml = '';
          if (recent.length > 0) {
            var photoCards = recent.map(function(r) {
              var meta = window.getInvCatMeta(r.category);
              var hasPhoto = r.photoUrl && /^https?:\/\//.test(r.photoUrl);
              return '<div style="cursor:pointer;background:var(--bg-base);border:1px solid var(--border-subtle);border-radius:8px;overflow:hidden;transition:transform 0.15s" ' +
                'onclick="window.openInventoryAssetModal(\'' + r.assetId + '\')" ' +
                'onmouseover="this.style.transform=\'translateY(-2px)\';this.style.borderColor=\'' + meta.color + '\'" ' +
                'onmouseout="this.style.transform=\'\';this.style.borderColor=\'var(--border-subtle)\'">' +
                '<div style="height:120px;background:' + meta.color + '22;display:flex;align-items:center;justify-content:center">' +
                  (hasPhoto ? '<img src="' + r.photoUrl + '" style="width:100%;height:100%;object-fit:cover">' :
                    '<i class="ph ' + meta.icon + '" style="font-size:42px;color:' + meta.color + '"></i>') +
                '</div>' +
                '<div style="padding:8px 10px">' +
                  '<div style="display:flex;align-items:center;gap:4px;font-size:9px;color:' + meta.color + ';font-weight:700;text-transform:uppercase">' +
                    '<i class="ph ' + meta.icon + '"></i> ' + r.category + '</div>' +
                  '<div style="font-size:12px;font-weight:700;color:var(--text-primary);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + (r.name || '-') + '</div>' +
                  '<div style="font-size:10px;color:var(--text-tertiary);margin-top:1px">' + (r.brand || '-') + '</div>' +
                  '<div class="cell-mono" style="font-size:10px;color:var(--text-tertiary);margin-top:2px">' + r.assetId + '</div>' +
                '</div></div>';
            }).join('');
            recentHtml =
              '<div class="panel" style="margin-bottom:14px">' +
                '<div class="panel-header"><div class="panel-title"><i class="ph ph-image"></i> ìµœê·¼ ë“±ë¡ ìžì‚°</div></div>' +
                '<div class="panel-body" style="padding:14px"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">' +
                  photoCards +
                '</div></div></div>';
          }

          // â”€â”€ 5. ì ê²€ ìž„ë°• â”€â”€
          var inspHtml = '';
          if (inspections.length > 0) {
            inspHtml =
              '<div class="panel" style="margin-bottom:14px;border-left:3px solid var(--status-warning)">' +
                '<div class="panel-header"><div class="panel-title" style="color:var(--status-warning)"><i class="ph ph-clock-countdown"></i> ì ê²€/ìº˜ë¦¬ë¸Œë ˆì´ì…˜ ìž„ë°• (' + inspections.length + ')</div></div>' +
                '<div class="panel-body" style="padding:0">' +
                '<table class="data-table"><thead><tr><th>AssetID</th><th>ì¹´í…Œê³ ë¦¬</th><th>ì´ë¦„</th><th>ì ê²€ì¼</th><th>D-day</th></tr></thead><tbody>' +
                  inspections.map(function(i) {
                    var meta = window.getInvCatMeta(i.category);
                    var dColor = i.dDay < 0 ? 'var(--status-danger)' : (i.dDay <= 7 ? 'var(--status-warning)' : 'var(--text-secondary)');
                    return '<tr style="cursor:pointer" onclick="window.openInventoryAssetModal(\'' + i.assetId + '\')">' +
                      '<td class="cell-mono">' + i.assetId + '</td>' +
                      '<td><i class="ph ' + meta.icon + '" style="color:' + meta.color + ';margin-right:4px"></i>' + i.category + '</td>' +
                      '<td class="cell-primary">' + i.name + '</td>' +
                      '<td class="cell-mono">' + i.nextInspect + '</td>' +
                      '<td><span style="color:' + dColor + ';font-weight:700">' + (i.dDay < 0 ? 'D+' + Math.abs(i.dDay) : 'D-' + i.dDay) + '</span></td>' +
                    '</tr>';
                  }).join('') +
                '</tbody></table></div></div>';
          }

          // ë¹ˆ ìƒíƒœ
          var emptyHtml = totals.count === 0
            ? '<div class="panel"><div class="panel-body padded"><div style="text-align:center;padding:48px;color:var(--text-tertiary)">' +
              '<i class="ph ph-image-square" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.5"></i>' +
              'ì•„ì§ ë“±ë¡ëœ ìžì‚°ì´ ì—†ìŠµë‹ˆë‹¤.<br>' +
              '<small>Drive [INVENTORY_PHOTOS/PENDING] í´ë”ì— ì‚¬ì§„ì„ ë„£ê³ <br>ìœ„ [ðŸ¤– AI ì‚¬ì§„ ë“±ë¡] ë²„íŠ¼ì„ í´ë¦­í•˜ì„¸ìš”.</small>' +
              '</div></div></div>'
            : '';

          pageContainer.innerHTML = headerHtml + kpiHtml + matrixHtml + recentHtml + inspHtml + emptyHtml;
        } catch (err) {
          pageContainer.innerHTML = '<div class="panel"><div class="panel-body padded"><div style="color:var(--status-danger);text-align:center;padding:32px">ìžìž¬/ìž¥ë¹„ ë¡œë”© ì‹¤íŒ¨: ' + err.message + '</div></div></div>';
        }
      }

      // ìƒˆë¡œê³ ì¹¨
      window.refreshInventory = function() { renderInventory(); };

      // ìžë™ ì…‹ì—… â€” ì‹œíŠ¸ + í´ë” í•œë²ˆì—
      window.bootstrapInventory = async function() {
        if (!confirm('Inventory ì‹œíŠ¸ 3ê°œ + Drive í´ë” 6ê°œë¥¼ ìžë™ ìƒì„±í•©ë‹ˆë‹¤.\nê³„ì†í•˜ì‹œê² ìŠµë‹ˆê¹Œ?')) return;
        try {
          var s = await window.API.setupInventorySheets();
          var f = await window.API.setupInventoryFolders();
          alert('âœ… ì…‹ì—… ì™„ë£Œ\nì‹œíŠ¸: ' + (s.created || []).join(', ') + '\ní´ë” IDëŠ” Apps Script ì‹¤í–‰ ë¡œê·¸ì—ì„œ í™•ì¸í•˜ì—¬ Code.gs FOLDERSì— ìž…ë ¥ í›„ push ìž¬ë°°í¬ í•„ìš”');
          renderInventory();
        } catch(err) {
          alert('ì…‹ì—… ì‹¤íŒ¨: ' + err.message);
        }
      };

      // AI ì‚¬ì§„ ë“±ë¡ ì‹¤í–‰
      window.runAIInventoryRegister = async function() {
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:10000;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;cursor:pointer';
        overlay.innerHTML =
          '<div style="width:64px;height:64px;border:4px solid rgba(124,58,237,0.3);border-top-color:#7c3aed;border-radius:50%;animation:spin 1s linear infinite"></div>' +
          '<div style="color:white;font-size:16px;font-weight:700">ðŸ¤– Gemini AI ë¶„ì„ ì¤‘...</div>' +
          '<div style="color:rgba(255,255,255,0.6);font-size:12px">INVENTORY_PHOTOS/PENDING í´ë” ìŠ¤ìº” + ìžë™ ë“±ë¡</div>' +
          '<div style="color:rgba(255,255,255,0.4);font-size:11px;margin-top:8px">í´ë¦­/ESCë¡œ ë‹«ê¸° (ë°±ê·¸ë¼ìš´ë“œ ê³„ì†)</div>';
        document.body.appendChild(overlay);
        var dismiss = function() { try { overlay.remove(); } catch(e) {} };
        overlay.addEventListener('click', dismiss);
        var esc = function(e) { if (e.key === 'Escape') dismiss(); };
        document.addEventListener('keydown', esc);
        var to = setTimeout(dismiss, 90000);

        try {
          var res = await window.API.processInventoryPhotos();
          clearTimeout(to);
          document.removeEventListener('keydown', esc);
          dismiss();

          var modal = document.createElement('div');
          modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:10001;display:flex;align-items:center;justify-content:center;padding:20px';
          var icon = res.success ? (res.processed === 0 ? 'ðŸ“‚' : 'âœ…') : 'âŒ';
          var detail = (res.results || []).map(function(r) {
            var ic = r.status === 'success' ? 'âœ…' : r.status === 'error' ? 'âŒ' : 'â­ï¸';
            var txt = r.status === 'success'
              ? '<span style="color:var(--status-success)">' + r.category + ' Â· ' + (r.brand || '') + ' ' + (r.model || '') + ' [' + r.assetId + ']</span>'
              : '<span style="color:var(--status-danger)">' + (r.reason || '') + '</span>';
            return '<div style="padding:6px 0;border-bottom:1px solid var(--border-subtle);font-size:11px">' + ic + ' <strong>' + r.file + '</strong><br>' + txt + '</div>';
          }).join('');
          modal.innerHTML =
            '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:14px;padding:24px;width:560px;max-height:80vh;overflow-y:auto">' +
              '<div style="font-size:32px;text-align:center;margin-bottom:8px">' + icon + '</div>' +
              '<h2 style="text-align:center;font-size:18px;margin-bottom:8px">AI ìžì‚° ë“±ë¡ ê²°ê³¼</h2>' +
              (res.success ? (res.processed === 0 ? '<div style="text-align:center;color:var(--text-secondary);font-size:13px;margin-top:8px">' + (res.message || 'ì²˜ë¦¬í•  íŒŒì¼ì´ ì—†ìŠµë‹ˆë‹¤.') + '</div>' :
                '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:14px 0;text-align:center">' +
                  '<div style="background:var(--bg-base);border-radius:8px;padding:10px"><div style="font-size:22px;font-weight:700">' + (res.processed||0) + '</div><div style="font-size:10px;color:var(--text-secondary)">ì´ ì²˜ë¦¬</div></div>' +
                  '<div style="background:var(--bg-base);border-radius:8px;padding:10px"><div style="font-size:22px;font-weight:700;color:var(--status-success)">' + (res.saved||0) + '</div><div style="font-size:10px;color:var(--text-secondary)">ì €ìž¥ ì™„ë£Œ</div></div>' +
                  '<div style="background:var(--bg-base);border-radius:8px;padding:10px"><div style="font-size:22px;font-weight:700;color:var(--status-danger)">' + (res.errors||0) + '</div><div style="font-size:10px;color:var(--text-secondary)">ì˜¤ë¥˜</div></div>' +
                '</div>' +
                '<div style="max-height:300px;overflow-y:auto;margin-bottom:14px">' + detail + '</div>'
              ) : '<div style="color:var(--status-danger);text-align:center;margin-top:8px">' + (res.error || 'ì‹¤íŒ¨') + '</div>') +
              '<button onclick="this.closest(\'div[style*=z-index]\').parentElement.remove();window.refreshInventory()" style="width:100%;background:var(--brand-primary);color:white;border:none;border-radius:8px;padding:10px;font-size:14px;font-weight:700;cursor:pointer">í™•ì¸ í›„ ìƒˆë¡œê³ ì¹¨</button>' +
            '</div>';
          document.body.appendChild(modal);
        } catch(err) {
          clearTimeout(to);
          dismiss();
          alert('AI ë¶„ì„ ì¤‘ ì˜¤ë¥˜: ' + err.message);
        }
      };

      // ìžì‚° ìƒì„¸ ëª¨ë‹¬
      window.openInventoryAssetModal = async function(assetId) {
        var modal = document.createElement('div');
        modal.id = 'inv-asset-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px';
        modal.innerHTML = '<div style="color:white"><i class="ph ph-spinner ph-spin" style="font-size:32px"></i></div>';
        document.body.appendChild(modal);
        modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });

        try {
          var res = await window.API.getInventoryAssetDetail(assetId);
          if (!res || !res.success) {
            modal.innerHTML = '<div style="background:var(--bg-panel);padding:24px;border-radius:12px;color:var(--status-danger)">ìžì‚° ì •ë³´ ì—†ìŒ: ' + (res && res.error || assetId) + '<br><br><button onclick="document.getElementById(\'inv-asset-modal\').remove()" class="btn-secondary">ë‹«ê¸°</button></div>';
            return;
          }
          var a = res.asset;
          var meta = window.getInvCatMeta(a['ì¹´í…Œê³ ë¦¬']);
          var photo = a['ì‚¬ì§„URLs'] || '';
          var hasPhoto = photo && /^https?:\/\//.test(photo);

          function row(label, val) {
            if (val === undefined || val === null || val === '') val = '<span style="color:var(--text-tertiary)">â€”</span>';
            return '<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border-subtle)">' +
              '<span style="font-size:11px;color:var(--text-tertiary);font-weight:600;letter-spacing:0.3px;text-transform:uppercase">' + label + '</span>' +
              '<span style="font-size:12px;color:var(--text-primary);font-weight:600;text-align:right;max-width:60%">' + val + '</span></div>';
          }

          modal.innerHTML =
            '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:14px;width:720px;max-width:100%;max-height:90vh;overflow-y:auto">' +
              '<div style="padding:16px 20px;border-bottom:1px solid var(--border-default);display:flex;justify-content:space-between;align-items:center;background:linear-gradient(90deg,' + meta.color + '22,transparent)">' +
                '<div style="display:flex;align-items:center;gap:10px">' +
                  '<i class="ph ' + meta.icon + '" style="font-size:20px;color:' + meta.color + '"></i>' +
                  '<span style="font-size:14px;font-weight:700">ìžì‚° ìƒì„¸</span>' +
                  '<span class="cell-mono" style="font-size:11px;color:var(--text-tertiary);background:var(--bg-base);padding:3px 8px;border-radius:4px">' + (a['AssetID'] || '-') + '</span>' +
                '</div>' +
                '<button onclick="document.getElementById(\'inv-asset-modal\').remove()" style="background:var(--bg-base);border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;color:var(--text-secondary);font-size:18px">Ã—</button>' +
              '</div>' +
              '<div style="padding:20px;display:grid;grid-template-columns:240px 1fr;gap:20px">' +
                '<div>' +
                  (hasPhoto ? '<img src="' + photo + '" style="width:100%;border-radius:10px;border:2px solid ' + meta.color + '">' :
                    '<div style="width:100%;aspect-ratio:1;background:' + meta.color + '22;display:flex;align-items:center;justify-content:center;border-radius:10px"><i class="ph ' + meta.icon + '" style="font-size:64px;color:' + meta.color + '"></i></div>') +
                  '<div style="text-align:center;margin-top:10px">' +
                    '<div style="font-size:11px;font-weight:700;color:' + meta.color + ';text-transform:uppercase">' + (a['ì¹´í…Œê³ ë¦¬'] || '-') + '</div>' +
                    '<div style="font-size:9px;color:var(--text-tertiary);margin-top:2px">ë“±ë¡: ' + (a['ë“±ë¡ì¼'] ? new Date(a['ë“±ë¡ì¼']).toLocaleDateString('ko-KR') : '-') + '</div>' +
                  '</div>' +
                '</div>' +
                '<div>' +
                  '<div style="font-size:20px;font-weight:800;line-height:1.2;margin-bottom:4px">' + (a['ì´ë¦„'] || '-') + '</div>' +
                  '<div style="font-size:13px;color:var(--text-secondary);margin-bottom:14px"><strong>' + (a['ë¸Œëžœë“œ'] || '-') + '</strong>' + (a['ëª¨ë¸'] ? ' Â· ' + a['ëª¨ë¸'] : '') + '</div>' +
                  row('ì‹œë¦¬ì–¼', a['ì‹œë¦¬ì–¼']) +
                  row('ìŠ¤íŽ™', a['ìŠ¤íŽ™']) +
                  row('í˜„ìž¬ê°€ì¹˜', a['í˜„ìž¬ê°€ì¹˜'] ? '$' + Number(a['í˜„ìž¬ê°€ì¹˜']).toLocaleString() : '-') +
                  row('ìƒíƒœ', a['ìƒíƒœ']) +
                  row('í˜„ìž¥', a['í˜„ìž¬SiteID']) +
                  row('ë³´ê´€ìœ„ì¹˜', a['ë³´ê´€ìœ„ì¹˜']) +
                  row('ë³´ìœ ìž', a['ë³´ìœ ìž_BadgeID']) +
                  row('ë‹¤ìŒì ê²€', a['ë‹¤ìŒì ê²€ì¼']) +
                  row('ì ê²€ì£¼ê¸°', a['ì ê²€ì£¼ê¸°ê°œì›”'] ? a['ì ê²€ì£¼ê¸°ê°œì›”'] + 'ê°œì›”' : '-') +
                  row('ë¹„ê³ ', a['ë¹„ê³ ']) +
                '</div>' +
              '</div>' +
              '<div style="padding:14px 20px;border-top:1px solid var(--border-default);display:flex;gap:8px;justify-content:flex-end;background:var(--bg-base)">' +
                '<button class="btn-secondary" disabled style="opacity:0.5"><i class="ph ph-arrow-square-out"></i> ì´ë™ (Phase 2)</button>' +
                '<button class="btn-secondary" disabled style="opacity:0.5"><i class="ph ph-wrench"></i> ì ê²€ (Phase 3)</button>' +
                '<button onclick="document.getElementById(\'inv-asset-modal\').remove()" class="btn-primary">ë‹«ê¸°</button>' +
              '</div>' +
            '</div>';
        } catch(err) {
          modal.innerHTML = '<div style="background:var(--bg-panel);padding:24px;border-radius:12px;color:var(--status-danger)">ë¡œë”© ì‹¤íŒ¨: ' + err.message + '<br><br><button onclick="document.getElementById(\'inv-asset-modal\').remove()" class="btn-secondary">ë‹«ê¸°</button></div>';
        }
      };

      // ë§¤íŠ¸ë¦­ìŠ¤ ì…€ í´ë¦­ â†’ í•„í„° (Phase 2 ì˜ˆì•½)
      // ë§¤íŠ¸ë¦­ìŠ¤ ì…€ í ´ë¦­ â†’ í•„í„° (Phase 2 ì˜ˆì•½)
      window.filterInventory = function(cat, site) {
        console.log('Filter:', cat, '/', site);
        // Phase 2: í•„í„° + ëª©ë¡  í‘œì‹œ
      };

      // â”€â”€ PROJECT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      async function renderVehicle() {
        pageContainer.innerHTML = skeleton();
        try {
          const [stats, vehicles] = await Promise.all([
            window.API.getVehicleStats(),
            window.API.getVehicleList()
          ]);
          var vehiclesListHtml = vehicles.map(function (v) {
            var rentClass = v.rentEnd < '2026-05-30' ? ' text-warning' : '';
            var insClass = v.insuranceExp < '2026-06-30' ? ' text-warning' : '';
            var oilClass = (v.nextOil - v.mileage) < 1000 ? ' text-warning' : '';
            var assignee = v.assignee || '<span style="color:var(--text-tertiary)">미배정</span>';
            var aiTag = '';
            
            // Render table row
            return '<tr style="cursor:pointer" onclick=\'openVehicleDetailModal(' + JSON.stringify(v).replace(/'/g, "&#39;").replace(/"/g, "&quot;") + ')\'>' +
              '<td class="cell-mono">' + v.id + aiTag + '</td>' +
              '<td class="cell-primary cell-mono">' + v.plate + '</td>' +
              '<td>' + v.type + ' ' + v.model + '</td>' +
              '<td>' + assignee + '</td>' +
              '<td class="cell-mono' + rentClass + '">' + v.rentEnd + '</td>' +
              '<td class="cell-mono' + insClass + '">' + v.insuranceExp + '</td>' +
              '<td class="cell-mono">' + (v.mileage||0).toLocaleString() + '</td>' +
              '<td class="cell-mono' + oilClass + '">' + (v.nextOil||0).toLocaleString() + '</td>' +
              '<td>' + statusPill(v.status) + '</td>' +
              '</tr>';
          }).join('');

          pageContainer.innerHTML =
            '<div class="header-section"><div><h1 class="page-title">차량 관리</h1><p class="page-subtitle">렌트 차량 현황 · 보험/등록 만료 추적 · AI 계약서 자동 등록</p></div>' +
            '<div class="action-row">' +
            '<button class="btn-secondary" onclick="window.print()"><i class="ph ph-file-csv"></i> 목록 출력</button>' +
            '<button class="btn-primary" style="background:linear-gradient(135deg,#7c3aed,#2563eb);border:none;" onclick="openAiVehicleRegModal()">' +
            '<i class="ph ph-robot"></i> 🤖 AI 렌트카 등록</button>' +
            '<button class="btn-primary" style="background:var(--status-warning);color:#000;" onclick="openNfcAssignModal(\'VEHICLE\')"><i class="ph ph-identification-card"></i> NFC 배정</button>' +
            '</div></div>' +
            '<div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">' +
            '<div class="kpi-card"><div class="kpi-label">전체 차량</div><div class="kpi-value">' + stats.total + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">등록 차량</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">운행중</div><div class="kpi-value" style="color:var(--status-success)">' + stats.active + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">정상 배정</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">정비중</div><div class="kpi-value" style="color:var(--status-warning)">' + stats.maintenance + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">서비스 센터</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">렌트만료임박</div><div class="kpi-value" style="color:var(--status-danger)">' + (stats.rentExpiringSoon||0) + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">60일 이내</span></div></div>' +
            '</div>' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-car"></i> 차량 목록</div></div>' +
            '<div class="panel-body"><table class="data-table"><thead><tr><th>차량ID</th><th>번호판</th><th>모델</th><th>배정자</th><th>렌트만료</th><th>보험만료</th><th>현재마일</th><th>다음오일</th><th>상태</th></tr></thead><tbody>' + (vehiclesListHtml || '<tr><td colspan="9" style="text-align:center;color:var(--text-tertiary);padding:32px">등록된 차량 없음</td></tr>') + '</tbody></table></div></div>';

        } catch (err) { renderError('차량 데이터 로딩 실패'); console.error(err); }
      }

      // AI Rent Car Auto Registration Modal
      window.openAiVehicleRegModal = function() {
        var modal = document.createElement('div');
        modal.id = 'ai-vehicle-reg-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;';
        
        modal.innerHTML =
          '<style>' +
            '#ai-vehicle-reg-modal * { box-sizing: border-box; }' +
            '#ai-vehicle-reg-modal .webcam-slot-card { transition: all 0.2s; }' +
            '#ai-vehicle-reg-modal .webcam-slot-card:hover { background: rgba(124, 58, 237, 0.05) !important; border-color: var(--brand-primary) !important; }' +
            '#ai-vehicle-reg-modal .active-target { box-shadow: 0 0 8px rgba(124, 58, 237, 0.4); animation: target-pulse 2s infinite ease-in-out; }' +
            '#ai-vehicle-reg-modal input[type="text"],' +
            '#ai-vehicle-reg-modal input[type="number"],' +
            '#ai-vehicle-reg-modal input[type="date"] {' +
            '  width: 100%;' +
            '  background: var(--bg-base);' +
            '  border: 1px solid var(--border-strong);' +
            '  border-radius: 8px;' +
            '  padding: 10px 12px;' +
            '  color: var(--text-primary);' +
            '  font-size: 13px;' +
            '  outline: none;' +
            '  transition: border-color 0.2s, box-shadow 0.2s;' +
            '}' +
            '#ai-vehicle-reg-modal input[type="text"]:focus,' +
            '#ai-vehicle-reg-modal input[type="number"]:focus,' +
            '#ai-vehicle-reg-modal input[type="date"]:focus {' +
            '  border-color: #7c3aed;' +
            '  box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);' +
            '}' +
            '#ai-vehicle-reg-modal label {' +
            '  display: block;' +
            '  font-size: 12px;' +
            '  font-weight: 600;' +
            '  color: var(--text-secondary);' +
            '  margin-bottom: 6px;' +
            '}' +
            '#ai-vehicle-reg-modal .btn-primary {' +
            '  background: linear-gradient(135deg, #7c3aed, #2563eb);' +
            '  border: none;' +
            '  color: #fff;' +
            '  font-weight: 600;' +
            '  border-radius: 8px;' +
            '  height: 38px;' +
            '  transition: opacity 0.2s, transform 0.1s;' +
            '}' +
            '#ai-vehicle-reg-modal .btn-primary:hover {' +
            '  opacity: 0.9;' +
            '}' +
            '#ai-vehicle-reg-modal .btn-primary:active {' +
            '  transform: scale(0.98);' +
            '}' +
            '#ai-vehicle-reg-modal .btn-secondary {' +
            '  background: var(--bg-base);' +
            '  border: 1px solid var(--border-strong);' +
            '  color: var(--text-primary);' +
            '  font-weight: 600;' +
            '  border-radius: 8px;' +
            '  height: 38px;' +
            '  transition: background 0.2s, transform 0.1s;' +
            '}' +
            '#ai-vehicle-reg-modal .btn-secondary:hover {' +
            '  background: var(--bg-body);' +
            '}' +
            '#ai-vehicle-reg-modal .btn-secondary:active {' +
            '  transform: scale(0.98);' +
            '}' +
            '#vehicle-modal-close-btn {' +
            '  transition: color 0.2s, transform 0.2s;' +
            '}' +
            '#vehicle-modal-close-btn:hover {' +
            '  color: var(--text-primary);' +
            '  transform: rotate(90deg);' +
            '}' +
            '@keyframes target-pulse { 0%, 100% { border-color: var(--brand-primary); } 50% { border-color: var(--border-strong); } }' +
            '@media (max-width: 640px) { #vehicle-camera-area { grid-template-columns: 1fr !important; } }' +
          '</style>' +
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:20px;width:520px;max-width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 10px 25px rgba(0,0,0,0.5);position:relative;box-sizing:border-box;">' +
            // Header
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;border-bottom:1px solid var(--border-subtle);padding-bottom:12px;">' +
              '<h2 style="font-size:16px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px;margin:0;"><i class="ph ph-robot" style="color:#7c3aed;font-size:22px;"></i> AI 렌트카 자동 등록</h2>' +
              '<button type="button" id="vehicle-modal-close-btn" style="background:none;border:none;color:var(--text-secondary);font-size:20px;cursor:pointer;padding:0;"><i class="ph ph-x"></i></button>' +
            '</div>' +
            
            // Description
            '<div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 16px; line-height:1.5;">' +
              '렌트 계약서와 차량 사진들을 업로드하거나 실시간 카메라로 촬영하세요.<br>Gemini AI 엔진이 사진을 자동으로 판독하여 장부에 즉시 등록합니다.' +
            '</div>' +

            // Hidden file inputs
            '<input type="file" id="vehicle-file-contract" style="display:none" accept="application/pdf,image/*">' +
            '<input type="file" id="vehicle-file-photo_front" style="display:none" accept="image/*">' +
            '<input type="file" id="vehicle-file-photo_rear" style="display:none" accept="image/*">' +
            '<input type="file" id="vehicle-file-photo_left" style="display:none" accept="image/*">' +
            '<input type="file" id="vehicle-file-photo_right" style="display:none" accept="image/*">' +

            // Upload / Camera Toggle
            '<div style="display: flex; gap: 8px; margin-bottom: 16px;">' +
              '<button type="button" id="vehicle-btn-toggle-upload" class="btn-primary" style="flex: 1; justify-content: center; height: 38px; border-radius: 8px; cursor:pointer;">' +
                '<i class="ph ph-upload-simple"></i> 파일 업로드' +
              '</button>' +
              '<button type="button" id="vehicle-btn-toggle-camera" class="btn-secondary" style="flex: 1; justify-content: center; height: 38px; border-radius: 8px; cursor:pointer;">' +
                '<i class="ph ph-camera"></i> 실시간 카메라' +
              '</button>' +
            '</div>' +

            // Upload Mode Area (Grid of Cards)
            '<div id="vehicle-upload-area" style="display:flex; flex-direction:column; gap:12px; margin-bottom: 16px;">' +
              // Contract (Full width card)
              '<div id="vehicle-card-contract" style="border: 2px dashed var(--border-strong); border-radius: var(--radius-md); padding: 16px; text-align: center; cursor: pointer; transition: 0.2s; position: relative; background: var(--bg-body); min-height: 75px; display: flex; align-items: center; justify-content: center; flex-direction: column;" onmouseover="this.style.borderColor=\'var(--brand-primary)\'" onmouseout="if(!this.dataset.hasFile) this.style.borderColor=\'var(--border-strong)\'">' +
                '<div class="empty-state" style="display:flex; flex-direction:column; align-items:center; gap:6px;">' +
                  '<i class="ph ph-file-text" style="font-size: 24px; color: var(--text-tertiary);"></i>' +
                  '<div style="font-weight: 600; font-size:13px; color:var(--text-primary);">렌트 계약서 파일 (PDF/이미지) <span style="color:var(--status-danger)">*</span></div>' +
                  '<div style="font-size: 11px; color: var(--text-tertiary);">클릭하여 파일 업로드</div>' +
                '</div>' +
                '<div class="preview-state" style="display: none; width:100%; text-align:center; position:relative;">' +
                  '<div class="preview-icon-wrapper" style="font-size: 24px; color: var(--brand-primary); margin-bottom: 4px;"><i class="ph ph-file-pdf"></i></div>' +
                  '<img class="preview-img" style="max-height: 50px; max-width: 100%; object-fit: contain; border-radius: 4px; display: none; margin: 0 auto 4px;">' +
                  '<div class="file-name" style="font-size: 12px; color: var(--text-primary); font-weight: 600; word-break: break-all; padding: 0 32px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></div>' +
                  '<button type="button" class="trash-btn" style="position: absolute; top: -8px; right: -8px; background: rgba(0,0,0,0.6); color: #fff; width:24px; height:24px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:12px;"><i class="ph ph-trash"></i></button>' +
                '</div>' +
              '</div>' +
              
              // Directional photos (2x2 grid)
              '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">' +
                // Front
                '<div id="vehicle-card-photo_front" style="border: 2px dashed var(--border-strong); border-radius: var(--radius-md); text-align: center; cursor: pointer; transition: 0.2s; position: relative; background: var(--bg-body); height: 80px; display: flex; align-items: center; justify-content: center; flex-direction: column;" onmouseover="this.style.borderColor=\'var(--brand-primary)\'" onmouseout="if(!this.dataset.hasFile) this.style.borderColor=\'var(--border-strong)\'">' +
                  '<div class="empty-state" style="display:flex; flex-direction:column; align-items:center; gap:4px;">' +
                    '<i class="ph ph-camera" style="font-size: 20px; color: var(--text-tertiary);"></i>' +
                    '<div style="font-weight: 600; font-size:12px; color:var(--text-primary);">전면 사진</div>' +
                  '</div>' +
                  '<div class="preview-state" style="display: none; width: 100%; height: 100%;">' +
                    '<img class="preview-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">' +
                    '<button type="button" class="trash-btn" style="position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.6); color: #fff; width:20px; height:20px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size: 11px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
                // Rear
                '<div id="vehicle-card-photo_rear" style="border: 2px dashed var(--border-strong); border-radius: var(--radius-md); text-align: center; cursor: pointer; transition: 0.2s; position: relative; background: var(--bg-body); height: 80px; display: flex; align-items: center; justify-content: center; flex-direction: column;" onmouseover="this.style.borderColor=\'var(--brand-primary)\'" onmouseout="if(!this.dataset.hasFile) this.style.borderColor=\'var(--border-strong)\'">' +
                  '<div class="empty-state" style="display:flex; flex-direction:column; align-items:center; gap:4px;">' +
                    '<i class="ph ph-camera" style="font-size: 20px; color: var(--text-tertiary);"></i>' +
                    '<div style="font-weight: 600; font-size:12px; color:var(--text-primary);">후면 사진</div>' +
                  '</div>' +
                  '<div class="preview-state" style="display: none; width: 100%; height: 100%;">' +
                    '<img class="preview-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">' +
                    '<button type="button" class="trash-btn" style="position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.6); color: #fff; width:20px; height:20px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size: 11px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
                // Left
                '<div id="vehicle-card-photo_left" style="border: 2px dashed var(--border-strong); border-radius: var(--radius-md); text-align: center; cursor: pointer; transition: 0.2s; position: relative; background: var(--bg-body); height: 80px; display: flex; align-items: center; justify-content: center; flex-direction: column;" onmouseover="this.style.borderColor=\'var(--brand-primary)\'" onmouseout="if(!this.dataset.hasFile) this.style.borderColor=\'var(--border-strong)\'">' +
                  '<div class="empty-state" style="display:flex; flex-direction:column; align-items:center; gap:4px;">' +
                    '<i class="ph ph-camera" style="font-size: 20px; color: var(--text-tertiary);"></i>' +
                    '<div style="font-weight: 600; font-size:12px; color:var(--text-primary);">좌측 사진</div>' +
                  '</div>' +
                  '<div class="preview-state" style="display: none; width: 100%; height: 100%;">' +
                    '<img class="preview-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">' +
                    '<button type="button" class="trash-btn" style="position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.6); color: #fff; width:20px; height:20px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size: 11px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
                // Right
                '<div id="vehicle-card-photo_right" style="border: 2px dashed var(--border-strong); border-radius: var(--radius-md); text-align: center; cursor: pointer; transition: 0.2s; position: relative; background: var(--bg-body); height: 80px; display: flex; align-items: center; justify-content: center; flex-direction: column;" onmouseover="this.style.borderColor=\'var(--brand-primary)\'" onmouseout="if(!this.dataset.hasFile) this.style.borderColor=\'var(--border-strong)\'">' +
                  '<div class="empty-state" style="display:flex; flex-direction:column; align-items:center; gap:4px;">' +
                    '<i class="ph ph-camera" style="font-size: 20px; color: var(--text-tertiary);"></i>' +
                    '<div style="font-weight: 600; font-size:12px; color:var(--text-primary);">우측 사진</div>' +
                  '</div>' +
                  '<div class="preview-state" style="display: none; width: 100%; height: 100%;">' +
                    '<img class="preview-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">' +
                    '<button type="button" class="trash-btn" style="position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.6); color: #fff; width:20px; height:20px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size: 11px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
              '</div>' +
            '</div>' +

            // Camera Mode Area (Side-by-side)
            '<div id="vehicle-camera-area" style="display: none; grid-template-columns: 1.2fr 1fr; gap: 12px; align-items: start; margin-bottom: 16px;">' +
              // Left: Video feed
              '<div style="background: var(--bg-body); border-radius: var(--radius-md); padding: 8px; border: 1px solid var(--border-strong);">' +
                '<div style="position: relative; border-radius: var(--radius-sm); overflow: hidden; background: #000; aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center;">' +
                  '<video id="vehicle-video-stream" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>' +
                  '<div id="vehicle-camera-overlay-label" style="position: absolute; bottom: 8px; left: 8px; right: 8px; background: rgba(0,0,0,0.6); color: var(--text-primary); font-size: 11px; padding: 4px 6px; border-radius: 4px; text-align: center; font-weight: 600; border: 1px solid rgba(255,255,255,0.1);">' +
                    '촬영 대상: 계약서' +
                  '</div>' +
                '</div>' +
                '<div style="display: flex; gap: 8px; margin-top: 8px;">' +
                  '<button type="button" id="vehicle-btn-capture" class="btn-primary" style="flex: 1; justify-content: center; height: 34px; font-size: 12px; font-weight: 600; cursor:pointer;">' +
                    '<i class="ph ph-camera"></i> 사진 촬영' +
                  '</button>' +
                '</div>' +
                '<div id="vehicle-camera-select-container" style="margin-top: 8px; display: none; align-items:center; gap:6px;">' +
                  '<span style="font-size:10px; color:var(--text-secondary); font-weight:600; white-space:nowrap;">카메라:</span>' +
                  '<select id="vehicle-camera-device-select" style="flex:1; padding: 4px; background: var(--bg-base); border: 1px solid var(--border-strong); border-radius: 4px; color: var(--text-primary); font-size: 10px; outline: none;"></select>' +
                '</div>' +
              '</div>' +
              
              // Right: Slots selector
              '<div style="display: flex; flex-direction: column; gap: 8px; background: var(--bg-body); border-radius: var(--radius-md); padding: 8px; border: 1px solid var(--border-strong);">' +
                '<div style="font-size:11px; font-weight:700; color:var(--text-secondary); margin-bottom:2px; padding-bottom:4px; border-bottom:1px solid var(--border-subtle)">촬영 슬롯 선택</div>' +
                
                // Contract slot
                '<div class="webcam-slot-card active-target" data-slot="contract" style="display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: 6px 10px; cursor: pointer; transition: 0.2s; background: var(--bg-base);">' +
                  '<div style="display: flex; align-items: center; gap: 8px; overflow: hidden; width: 80%;">' +
                    '<div class="slot-thumbnail" style="width: 28px; height: 28px; border-radius: 4px; background: var(--bg-body); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-subtle); overflow: hidden; flex-shrink: 0;">' +
                      '<i class="ph ph-file-text" style="color: var(--text-tertiary); font-size: 14px;"></i>' +
                    '</div>' +
                    '<div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' +
                      '<div style="font-size: 11px; font-weight: 600; color: var(--text-primary);">계약서 <span style="color:var(--status-danger)">*</span></div>' +
                      '<div class="slot-status" style="font-size: 9px; color: var(--text-tertiary);">선택된 파일 없음</div>' +
                    '</div>' +
                  '</div>' +
                  '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span class="target-badge" style="font-size: 8px; padding: 1px 4px; border-radius: 8px; background: var(--brand-primary); color: #fff; font-weight: 700; display: inline-block;">TARGET</span>' +
                    '<button type="button" class="slot-delete-btn" style="background: none; border: none; color: var(--status-danger); cursor: pointer; display: none; font-size: 12px; padding: 2px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +

                // Front slot
                '<div class="webcam-slot-card" data-slot="photo_front" style="display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: 6px 10px; cursor: pointer; transition: 0.2s; background: var(--bg-base);">' +
                  '<div style="display: flex; align-items: center; gap: 8px; overflow: hidden; width: 80%;">' +
                    '<div class="slot-thumbnail" style="width: 28px; height: 28px; border-radius: 4px; background: var(--bg-body); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-subtle); overflow: hidden; flex-shrink: 0;">' +
                      '<i class="ph ph-camera" style="color: var(--text-tertiary); font-size: 14px;"></i>' +
                    '</div>' +
                    '<div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' +
                      '<div style="font-size: 11px; font-weight: 600; color: var(--text-primary);">전면 사진</div>' +
                      '<div class="slot-status" style="font-size: 9px; color: var(--text-tertiary);">선택된 사진 없음</div>' +
                    '</div>' +
                  '</div>' +
                  '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span class="target-badge" style="font-size: 8px; padding: 1px 4px; border-radius: 8px; background: var(--brand-primary); color: #fff; font-weight: 700; display: none;">TARGET</span>' +
                    '<button type="button" class="slot-delete-btn" style="background: none; border: none; color: var(--status-danger); cursor: pointer; display: none; font-size: 12px; padding: 2px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +

                // Rear slot
                '<div class="webcam-slot-card" data-slot="photo_rear" style="display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: 6px 10px; cursor: pointer; transition: 0.2s; background: var(--bg-base);">' +
                  '<div style="display: flex; align-items: center; gap: 8px; overflow: hidden; width: 80%;">' +
                    '<div class="slot-thumbnail" style="width: 28px; height: 28px; border-radius: 4px; background: var(--bg-body); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-subtle); overflow: hidden; flex-shrink: 0;">' +
                      '<i class="ph ph-camera" style="color: var(--text-tertiary); font-size: 14px;"></i>' +
                    '</div>' +
                    '<div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' +
                      '<div style="font-size: 11px; font-weight: 600; color: var(--text-primary);">후면 사진</div>' +
                      '<div class="slot-status" style="font-size: 9px; color: var(--text-tertiary);">선택된 사진 없음</div>' +
                    '</div>' +
                  '</div>' +
                  '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span class="target-badge" style="font-size: 8px; padding: 1px 4px; border-radius: 8px; background: var(--brand-primary); color: #fff; font-weight: 700; display: none;">TARGET</span>' +
                    '<button type="button" class="slot-delete-btn" style="background: none; border: none; color: var(--status-danger); cursor: pointer; display: none; font-size: 12px; padding: 2px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +

                // Left slot
                '<div class="webcam-slot-card" data-slot="photo_left" style="display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: 6px 10px; cursor: pointer; transition: 0.2s; background: var(--bg-base);">' +
                  '<div style="display: flex; align-items: center; gap: 8px; overflow: hidden; width: 80%;">' +
                    '<div class="slot-thumbnail" style="width: 28px; height: 28px; border-radius: 4px; background: var(--bg-body); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-subtle); overflow: hidden; flex-shrink: 0;">' +
                      '<i class="ph ph-camera" style="color: var(--text-tertiary); font-size: 14px;"></i>' +
                    '</div>' +
                    '<div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' +
                      '<div style="font-size: 11px; font-weight: 600; color: var(--text-primary);">좌측 사진</div>' +
                      '<div class="slot-status" style="font-size: 9px; color: var(--text-tertiary);">선택된 사진 없음</div>' +
                    '</div>' +
                  '</div>' +
                  '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span class="target-badge" style="font-size: 8px; padding: 1px 4px; border-radius: 8px; background: var(--brand-primary); color: #fff; font-weight: 700; display: none;">TARGET</span>' +
                    '<button type="button" class="slot-delete-btn" style="background: none; border: none; color: var(--status-danger); cursor: pointer; display: none; font-size: 12px; padding: 2px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +

                // Right slot
                '<div class="webcam-slot-card" data-slot="photo_right" style="display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: 6px 10px; cursor: pointer; transition: 0.2s; background: var(--bg-base);">' +
                  '<div style="display: flex; align-items: center; gap: 8px; overflow: hidden; width: 80%;">' +
                    '<div class="slot-thumbnail" style="width: 28px; height: 28px; border-radius: 4px; background: var(--bg-body); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-subtle); overflow: hidden; flex-shrink: 0;">' +
                      '<i class="ph ph-camera" style="color: var(--text-tertiary); font-size: 14px;"></i>' +
                    '</div>' +
                    '<div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' +
                      '<div style="font-size: 11px; font-weight: 600; color: var(--text-primary);">우측 사진</div>' +
                      '<div class="slot-status" style="font-size: 9px; color: var(--text-tertiary);">선택된 사진 없음</div>' +
                    '</div>' +
                  '</div>' +
                  '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span class="target-badge" style="font-size: 8px; padding: 1px 4px; border-radius: 8px; background: var(--brand-primary); color: #fff; font-weight: 700; display: none;">TARGET</span>' +
                    '<button type="button" class="slot-delete-btn" style="background: none; border: none; color: var(--status-danger); cursor: pointer; display: none; font-size: 12px; padding: 2px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
              '</div>' +
            '</div>' +

            // Submit Buttons / Controls for Initial Upload Mode
            '<div id="vehicle-initial-controls" style="margin-top: 12px; display:flex; justify-content:flex-end; gap:12px;">' +
              '<button type="button" id="vehicle-upload-cancel-btn" class="btn-secondary" style="padding:0 20px; cursor:pointer;">취소</button>' +
              '<button type="button" id="vehicle-upload-submit-btn" class="btn-primary" style="padding:0 24px; cursor:pointer;">AI 분석 실행</button>' +
            '</div>' +

            // Loading / Analysis Spinner
            '<div id="ai-analysis-loading" style="display:none; flex-direction:column; align-items:center; justify-content:center; padding:30px 0; gap:12px;">' +
              '<div style="width:40px; height:40px; border:4px solid rgba(124,58,237,0.2); border-top-color:#7c3aed; border-radius:50%; animation:spin 1s linear infinite"></div>' +
              '<div style="color:var(--text-primary); font-size:14px; font-weight:700;">Gemini AI가 분석하는 중...</div>' +
              '<div style="color:var(--text-secondary); font-size:12px; text-align:center; max-width:320px; line-height:1.4;">계약서 및 차량 사진 정보를 추출하여 자동 매핑하고 있습니다. 잠시만 기다려주세요.</div>' +
            '</div>' +

            // Verification and save form
            '<div id="ai-analysis-result-container" style="display:none; flex-direction:column; gap:12px; margin-top:12px;">' +
              '<h3 style="font-size:14px; font-weight:700; color:var(--text-primary); margin:0; padding-top:12px; border-top:1px solid var(--border-subtle); display:flex; align-items:center; gap:6px;"><i class="ph ph-check-square" style="color:var(--status-success)"></i> AI 분석 결과 검증</h3>' +
              '<div style="font-size:11px; color:var(--text-secondary); background:rgba(124,58,237,0.06); border:1px solid rgba(124,58,237,0.15); border-radius:6px; padding:8px 10px; line-height:1.4;">AI가 추출한 정보입니다. 실제 계약서 내용과 대조 후 수정이 필요한 부분은 직접 변경하고 저장하세요.</div>' +
              '<form id="ai-vehicle-save-form" style="display:flex; flex-direction:column; gap:12px;">' +
                '<input type="hidden" name="contract_path">' +
                '<input type="hidden" name="photo_front">' +
                '<input type="hidden" name="photo_rear">' +
                '<input type="hidden" name="photo_left">' +
                '<input type="hidden" name="photo_right">' +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">' +
                  '<div>' +
                    '<label>차량 번호판</label>' +
                    '<input type="text" name="plate_number" required>' +
                  '</div>' +
                  '<div>' +
                    '<label>차량 모델명</label>' +
                    '<input type="text" name="model" required>' +
                  '</div>' +
                '</div>' +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">' +
                  '<div>' +
                    '<label>렌트사 (Vendor)</label>' +
                    '<input type="text" name="vendor">' +
                  '</div>' +
                  '<div>' +
                    '<label>현재 마일리지 (Odometer)</label>' +
                    '<input type="number" name="current_mileage" min="0">' +
                  '</div>' +
                '</div>' +
                '<div style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px;">' +
                  '<div>' +
                    '<label>렌트 시작일</label>' +
                    '<input type="date" name="rent_start">' +
                  '</div>' +
                  '<div>' +
                    '<label>렌트 종료일</label>' +
                    '<input type="date" name="rent_end">' +
                  '</div>' +
                  '<div>' +
                    '<label>보험 만료일</label>' +
                    '<input type="date" name="insurance_expiry">' +
                  '</div>' +
                '</div>' +
                '<div id="ai-uploaded-previews" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; margin-top:4px;"></div>' +
                '<div style="margin-top:8px; display:flex; justify-content:flex-end; gap:12px;">' +
                  '<button type="button" id="vehicle-save-cancel-btn" class="btn-secondary" style="padding:0 20px;">취소</button>' +
                  '<button type="submit" class="btn-primary" style="background:var(--status-success) !important; padding:0 24px; border:none;">차량 등록 및 저장</button>' +
                '</div>' +
              '</form>' +
            '</div>' +
          '</div>';

        document.body.appendChild(modal);

        var vehicleFiles = {
          contract: null,
          photo_front: null,
          photo_rear: null,
          photo_left: null,
          photo_right: null
        };

        var currentActiveSlot = 'contract';
        var currentCameraMode = 'upload';
        var localVideoStream = null;
        var availableCameraDevices = [];

        // Elements
        var btnToggleUpload = modal.querySelector('#vehicle-btn-toggle-upload');
        var btnToggleCamera = modal.querySelector('#vehicle-btn-toggle-camera');
        var uploadArea = modal.querySelector('#vehicle-upload-area');
        var cameraArea = modal.querySelector('#vehicle-camera-area');
        var initialControls = modal.querySelector('#vehicle-initial-controls');
        var loadingDiv = modal.querySelector('#ai-analysis-loading');
        var resultDiv = modal.querySelector('#ai-analysis-result-container');
        var saveForm = modal.querySelector('#ai-vehicle-save-form');
        var deviceSelect = modal.querySelector('#vehicle-camera-device-select');
        var deviceSelectContainer = modal.querySelector('#vehicle-camera-select-container');

        // Helpers
        function showUploadMode() {
          currentCameraMode = 'upload';
          btnToggleUpload.className = 'btn-primary';
          btnToggleCamera.className = 'btn-secondary';
          uploadArea.style.display = 'flex';
          cameraArea.style.display = 'none';
          stopCamera();
        }

        async function showCameraMode() {
          currentCameraMode = 'camera';
          btnToggleUpload.className = 'btn-secondary';
          btnToggleCamera.className = 'btn-primary';
          uploadArea.style.display = 'none';
          cameraArea.style.display = 'grid';
          await startCamera();
        }

        btnToggleUpload.onclick = showUploadMode;
        btnToggleCamera.onclick = showCameraMode;

        var cards = ['contract', 'photo_front', 'photo_rear', 'photo_left', 'photo_right'];
        cards.forEach(function(slot) {
          var card = modal.querySelector('#vehicle-card-' + slot);
          if (card) {
            card.onclick = function(e) {
              if (e.target.closest('.trash-btn')) return;
              modal.querySelector('#vehicle-file-' + slot).click();
            };
          }

          var fileInput = modal.querySelector('#vehicle-file-' + slot);
          if (fileInput) {
            fileInput.onchange = function(e) {
              var file = e.target.files[0];
              if (file) {
                setFileSlot(slot, file);
              }
            };
          }
        });

        // Set file into slot and update UI
        function setFileSlot(slot, file) {
          vehicleFiles[slot] = file;
          var url = URL.createObjectURL(file);

          // Update upload cards
          var card = modal.querySelector('#vehicle-card-' + slot);
          if (card) {
            card.dataset.hasFile = 'true';
            card.style.borderColor = 'var(--border-default)';
            var emptyState = card.querySelector('.empty-state');
            var previewState = card.querySelector('.preview-state');
            emptyState.style.display = 'none';
            previewState.style.display = 'block';

            if (slot === 'contract') {
              var isImg = file.type.startsWith('image/');
              var previewImg = previewState.querySelector('.preview-img');
              var iconWrapper = previewState.querySelector('.preview-icon-wrapper');
              if (isImg) {
                previewImg.src = url;
                previewImg.style.display = 'block';
                iconWrapper.style.display = 'none';
              } else {
                previewImg.style.display = 'none';
                iconWrapper.style.display = 'block';
              }
              previewState.querySelector('.file-name').innerText = file.name;
            } else {
              var img = previewState.querySelector('.preview-img');
              img.src = url;
            }
          }

          // Update webcam list rows
          var slotRow = modal.querySelector('.webcam-slot-card[data-slot="' + slot + '"]');
          if (slotRow) {
            slotRow.querySelector('.slot-status').innerText = '완료';
            slotRow.querySelector('.slot-status').style.color = 'var(--status-success)';
            slotRow.querySelector('.slot-delete-btn').style.display = 'inline-block';
            
            var thumb = slotRow.querySelector('.slot-thumbnail');
            var isImg = file.type.startsWith('image/');
            if (isImg || slot !== 'contract') {
              thumb.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;">';
            } else {
              thumb.innerHTML = '<i class="ph ph-file-pdf" style="color:var(--brand-primary); font-size:18px;"></i>';
            }
          }
        }

        // Remove file from slot and update UI
        function clearFileSlot(slot) {
          vehicleFiles[slot] = null;
          var fileInput = modal.querySelector('#vehicle-file-' + slot);
          if (fileInput) fileInput.value = '';

          // Update upload cards
          var card = modal.querySelector('#vehicle-card-' + slot);
          if (card) {
            delete card.dataset.hasFile;
            card.style.borderColor = 'var(--border-strong)';
            var emptyState = card.querySelector('.empty-state');
            var previewState = card.querySelector('.preview-state');
            emptyState.style.display = 'flex';
            previewState.style.display = 'none';
          }

          // Update webcam list rows
          var slotRow = modal.querySelector('.webcam-slot-card[data-slot="' + slot + '"]');
          if (slotRow) {
            var iconName = (slot === 'contract') ? 'ph-file-text' : 'ph-camera';
            slotRow.querySelector('.slot-thumbnail').innerHTML = '<i class="ph ' + iconName + '" style="color:var(--text-tertiary);"></i>';
            slotRow.querySelector('.slot-status').innerText = (slot === 'contract') ? '선택된 파일 없음' : '선택된 사진 없음';
            slotRow.querySelector('.slot-status').style.color = 'var(--text-tertiary)';
            slotRow.querySelector('.slot-delete-btn').style.display = 'none';
          }
        }

        // Trash button click listeners
        cards.forEach(function(slot) {
          var card = modal.querySelector('#vehicle-card-' + slot);
          if (card) {
            var trashBtn = card.querySelector('.trash-btn');
            if (trashBtn) {
              trashBtn.onclick = function(e) {
                e.stopPropagation();
                clearFileSlot(slot);
              };
            }
          }

          var slotRow = modal.querySelector('.webcam-slot-card[data-slot="' + slot + '"]');
          if (slotRow) {
            var delBtn = slotRow.querySelector('.slot-delete-btn');
            delBtn.onclick = function(e) {
              e.stopPropagation();
              clearFileSlot(slot);
            };
          }
        });

        // Webcam slot row clicks (setting active target)
        var slotRows = modal.querySelectorAll('.webcam-slot-card');
        slotRows.forEach(function(row) {
          row.onclick = function() {
            var slot = row.dataset.slot;
            setActiveTargetSlot(slot);
          };
        });

        function setActiveTargetSlot(slot) {
          currentActiveSlot = slot;
          
          slotRows.forEach(function(row) {
            if (row.dataset.slot === slot) {
              row.classList.add('active-target');
              row.style.background = 'rgba(124,58,237,0.1)';
              row.style.borderColor = 'var(--brand-primary)';
              row.querySelector('.target-badge').style.display = 'inline-block';
            } else {
              row.classList.remove('active-target');
              row.style.background = 'var(--bg-base)';
              row.style.borderColor = 'var(--border-strong)';
              row.querySelector('.target-badge').style.display = 'none';
            }
          });

          var labelMap = {
            contract: '렌트 계약서',
            photo_front: '차량 전면 사진',
            photo_rear: '차량 후면 사진',
            photo_left: '차량 좌측 사진',
            photo_right: '차량 우측 사진'
          };
          modal.querySelector('#vehicle-camera-overlay-label').innerText = '촬영 대상: ' + (labelMap[slot] || slot);
        }

        // Camera functions
        async function startCamera(preferredDeviceId) {
          stopCamera();
          var video = modal.querySelector('#vehicle-video-stream');
          if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            var constraints = {
              video: { facingMode: 'environment' }
            };
            if (preferredDeviceId) {
              constraints.video = { deviceId: { exact: preferredDeviceId } };
            }
            try {
              var stream = await navigator.mediaDevices.getUserMedia(constraints);
              localVideoStream = stream;
              video.srcObject = stream;
              
              if (availableCameraDevices.length === 0) {
                var devices = await navigator.mediaDevices.enumerateDevices();
                availableCameraDevices = devices.filter(function(d) { return d.kind === 'videoinput'; });
                if (availableCameraDevices.length > 1) {
                  deviceSelectContainer.style.display = 'flex';
                  deviceSelect.innerHTML = '';
                  availableCameraDevices.forEach(function(d, idx) {
                    var opt = document.createElement('option');
                    opt.value = d.deviceId;
                    opt.text = d.label || ('Camera ' + (idx + 1));
                    if (preferredDeviceId && d.deviceId === preferredDeviceId) {
                      opt.selected = true;
                    }
                    deviceSelect.appendChild(opt);
                  });
                  deviceSelect.onchange = function() {
                    startCamera(deviceSelect.value);
                  };
                }
              }
            } catch (err) {
              console.error('Camera stream error:', err);
              alert('카메라를 활성화할 수 없습니다: ' + err.message);
              showUploadMode();
            }
          } else {
            alert('이 브라우저에서는 카메라 스트리밍을 지원하지 않습니다. 파일 업로드 모드를 사용해 주세요.');
            showUploadMode();
          }
        }

        function stopCamera() {
          var video = modal.querySelector('#vehicle-video-stream');
          if (video && video.srcObject) {
            var stream = video.srcObject;
            var tracks = stream.getTracks();
            tracks.forEach(function(track) { track.stop(); });
            video.srcObject = null;
          }
          localVideoStream = null;
        }

        // Snap and capture
        modal.querySelector('#vehicle-btn-capture').onclick = function() {
          var video = modal.querySelector('#vehicle-video-stream');
          if (!video || !video.srcObject) return;

          var canvas = document.createElement('canvas');
          canvas.width = video.videoWidth || 640;
          canvas.height = video.videoHeight || 480;
          var ctx = canvas.getContext('2d');
          ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

          var dataUrl = canvas.toDataURL('image/jpeg');
          var fileName = 'webcam_' + currentActiveSlot + '.jpg';
          var file = dataURLtoFile(dataUrl, fileName);

          setFileSlot(currentActiveSlot, file);

          var sequence = ['contract', 'photo_front', 'photo_rear', 'photo_left', 'photo_right'];
          var nextSlotIdx = sequence.indexOf(currentActiveSlot) + 1;
          
          var foundNext = false;
          for (var i = 0; i < sequence.length; i++) {
            var s = sequence[(nextSlotIdx + i) % sequence.length];
            if (!vehicleFiles[s]) {
              setActiveTargetSlot(s);
              foundNext = true;
              break;
            }
          }
          if (!foundNext) {
            showToast('모든 슬롯이 촬영되었습니다.');
          }
        };

        function dataURLtoFile(dataurl, filename) {
          var arr = dataurl.split(','),
              mime = arr[0].match(/:(.*?);/)[1],
              bstr = atob(arr[1]),
              n = bstr.length,
              u8arr = new Uint8Array(n);
          while (n--) {
            u8arr[n] = bstr.charCodeAt(n);
          }
          return new File([u8arr], filename, { type: mime });
        }

        function cleanUpAndClose() {
          stopCamera();
          modal.remove();
        }

        modal.querySelector('#vehicle-modal-close-btn').onclick = cleanUpAndClose;
        modal.querySelector('#vehicle-upload-cancel-btn').onclick = cleanUpAndClose;
        modal.querySelector('#vehicle-save-cancel-btn').onclick = cleanUpAndClose;

        // Submit form (Trigger AI Analysis)
        modal.querySelector('#vehicle-upload-submit-btn').onclick = async function(e) {
          e.preventDefault();

          if (!vehicleFiles.contract) {
            alert('렌트 계약서는 필수 등록 항목입니다.');
            return;
          }

          uploadArea.style.display = 'none';
          cameraArea.style.display = 'none';
          btnToggleUpload.parentElement.style.display = 'none';
          initialControls.style.display = 'none';
          loadingDiv.style.display = 'flex';
          stopCamera();

          var formData = new FormData();
          formData.append('contract', vehicleFiles.contract);
          if (vehicleFiles.photo_front) formData.append('photo_front', vehicleFiles.photo_front);
          if (vehicleFiles.photo_rear) formData.append('photo_rear', vehicleFiles.photo_rear);
          if (vehicleFiles.photo_left) formData.append('photo_left', vehicleFiles.photo_left);
          if (vehicleFiles.photo_right) formData.append('photo_right', vehicleFiles.photo_right);

          try {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var response = await fetch('/vehicle-api/scan-rental', {
              method: 'POST',
              headers: {
                'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
              },
              body: formData
            });

            if (!response.ok) {
              var errData = await response.json();
              throw new Error(errData.error || 'AI 분석 중 오류가 발생했습니다.');
            }

            var res = await response.json();
            loadingDiv.style.display = 'none';
            resultDiv.style.display = 'flex';

            var data = res.data || {};
            var files = res.files || {};

            saveForm.querySelector('[name="plate_number"]').value = data.plate_number || '';
            saveForm.querySelector('[name="model"]').value = data.model || '';
            saveForm.querySelector('[name="vendor"]').value = data.vendor || '';
            saveForm.querySelector('[name="current_mileage"]').value = data.current_mileage || 0;
            saveForm.querySelector('[name="rent_start"]').value = data.rent_start || '';
            saveForm.querySelector('[name="rent_end"]').value = data.rent_end || '';
            saveForm.querySelector('[name="insurance_expiry"]').value = data.insurance_expiry || '';

            saveForm.querySelector('[name="contract_path"]').value = files.contract || '';
            saveForm.querySelector('[name="photo_front"]').value = files.photo_front || '';
            saveForm.querySelector('[name="photo_rear"]').value = files.photo_rear || '';
            saveForm.querySelector('[name="photo_left"]').value = files.photo_left || '';
            saveForm.querySelector('[name="photo_right"]').value = files.photo_right || '';

            var previewsDiv = saveForm.querySelector('#ai-uploaded-previews');
            previewsDiv.innerHTML = '';
            var directions = [
              { key: 'photo_front', label: '전면' },
              { key: 'photo_rear', label: '후면' },
              { key: 'photo_left', label: '좌측' },
              { key: 'photo_right', label: '우측' }
            ];
            directions.forEach(function(d) {
              var src = '';
              if (vehicleFiles[d.key]) {
                src = URL.createObjectURL(vehicleFiles[d.key]);
              } else if (files[d.key]) {
                src = files[d.key];
              }

              if (src) {
                var previewCard = document.createElement('div');
                previewCard.style.cssText = 'text-align:center;background:var(--bg-base);border:1px solid var(--border-default);border-radius:6px;padding:4px;';
                previewCard.innerHTML = 
                  '<img src="' + src + '" style="width:100%;height:60px;object-fit:cover;border-radius:4px;margin-bottom:4px;">' +
                  '<span style="font-size:10px;color:var(--text-secondary);">' + d.label + '</span>';
                previewsDiv.appendChild(previewCard);
              }
            });

          } catch (err) {
            loadingDiv.style.display = 'none';
            btnToggleUpload.parentElement.style.display = 'flex';
            initialControls.style.display = 'flex';
            if (currentCameraMode === 'camera') {
              cameraArea.style.display = 'grid';
              startCamera();
            } else {
              uploadArea.style.display = 'flex';
            }
            alert('오류: ' + err.message);
          }
        };

        saveForm.onsubmit = async function(e) {
          e.preventDefault();
          var payload = {
            plate_number: saveForm.querySelector('[name="plate_number"]').value,
            model: saveForm.querySelector('[name="model"]').value,
            vendor: saveForm.querySelector('[name="vendor"]').value,
            current_mileage: parseInt(saveForm.querySelector('[name="current_mileage"]').value, 10) || 0,
            rent_start: saveForm.querySelector('[name="rent_start"]').value || null,
            rent_end: saveForm.querySelector('[name="rent_end"]').value || null,
            insurance_expiry: saveForm.querySelector('[name="insurance_expiry"]').value || null,
            contract_path: saveForm.querySelector('[name="contract_path"]').value || null,
            photo_front: saveForm.querySelector('[name="photo_front"]').value || null,
            photo_rear: saveForm.querySelector('[name="photo_rear"]').value || null,
            photo_left: saveForm.querySelector('[name="photo_left"]').value || null,
            photo_right: saveForm.querySelector('[name="photo_right"]').value || null
          };

          try {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var response = await fetch('/vehicle-api/save', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
              },
              body: JSON.stringify(payload)
            });

            if (!response.ok) {
              var errData = await response.json();
              throw new Error(errData.error || '차량 정보를 저장하는 중 오류가 발생했습니다.');
            }

            modal.remove();
            showToast('차량이 성공적으로 등록되었습니다.');
            window.renderVehicle();
          } catch (err) {
            alert('오류: ' + err.message);
          }
        };
      };

      // Detailed Vehicle Info & Chronological Rental History modal
      window.openVehicleDetailModal = async function(v) {
        function getSecureFileUrl(path) {
          if (!path) return '';
          if (path.indexOf('/storage/') === 0) {
            return '/vehicle-api/file?path=' + encodeURIComponent(path.replace('/storage/', ''));
          }
          return path;
        }

        var modal = document.createElement('div');
        modal.id = 'vehicle-detail-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;';
        
        var photosHtml = '';
        var directions = [
          { path: v.photo_front, label: '전면 사진' },
          { path: v.photo_rear, label: '후면 사진' },
          { path: v.photo_left, label: '좌측 사진' },
          { path: v.photo_right, label: '우측 사진' }
        ];
        directions.forEach(function(d) {
          if (d.path) {
            var secureUrl = getSecureFileUrl(d.path);
            photosHtml += '<div style="background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:6px;text-align:center;">' +
              '<a href="' + secureUrl + '" target="_blank">' +
                '<img src="' + secureUrl + '" style="width:100%;height:100px;object-fit:cover;border-radius:6px;margin-bottom:6px;border:1px solid var(--border-subtle);">' +
              '</a>' +
              '<span style="font-size:11px;font-weight:600;color:var(--text-secondary);">' + d.label + '</span>' +
            '</div>';
          }
        });

        if (photosHtml) {
          photosHtml = '<div style="margin-top:16px;">' +
            '<h4 style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:8px;">차량 사진 (4방향)</h4>' +
            '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">' + photosHtml + '</div>' +
          '</div>';
        }

        var contractHtml = v.contract_path 
          ? '<div style="margin-top:12px;"><a href="' + getSecureFileUrl(v.contract_path) + '" target="_blank" class="btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;font-size:12px;"><i class="ph ph-file-pdf"></i> 렌트 계약서 파일 보기</a></div>'
          : '';

        var actionButton = '';
        if (v.status === '운행중') {
          actionButton = '<button onclick="openReturnVehicleModal(' + v.realId + ', \'' + v.model + '\', ' + v.mileage + ')" class="btn-primary" style="background:var(--status-danger);border:none;padding:10px 20px;font-weight:700;">차량 반납 처리</button>';
        } else {
          actionButton = '<button onclick="openAssignVehicleModal(' + v.realId + ', \'' + v.model + '\')" class="btn-primary" style="background:var(--status-success);border:none;padding:10px 20px;font-weight:700;">차량 운전자 배정</button>';
        }

        modal.innerHTML =
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:28px;width:700px;max-width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 10px 25px rgba(0,0,0,0.5);">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border-bottom:1px solid var(--border-subtle);padding-bottom:12px;">' +
              '<h2 style="font-size:20px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px;margin:0;"><i class="ph ph-car" style="color:var(--brand-primary);"></i> 차량 상세 정보</h2>' +
              '<button onclick="this.closest(\'#vehicle-detail-modal\').remove()" style="background:none;border:none;color:var(--text-secondary);font-size:20px;cursor:pointer;padding:0;"><i class="ph ph-x"></i></button>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;background:var(--bg-base);border-radius:10px;padding:16px;border:1px solid var(--border-subtle);">' +
              '<div>' +
                '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">차량 ID / 모델</span><strong style="font-size:15px;color:var(--text-primary);">' + v.id + ' / ' + v.model + '</strong></div>' +
                '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">번호판 / 제조사 (Vendor)</span><strong style="font-size:15px;color:var(--text-primary);">' + v.plate + ' / ' + v.company + '</strong></div>' +
                '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">현재 마일리지 / 다음 오일 교환</span><strong style="font-size:15px;color:var(--text-primary);">' + v.mileage.toLocaleString() + ' mi / ' + v.nextOil.toLocaleString() + ' mi</strong></div>' +
              '</div>' +
              '<div>' +
                '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">현재 배정자</span><strong style="font-size:15px;color:var(--text-primary);">' + (v.assignee || '미배정') + '</strong></div>' +
                '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">렌트 만료일 / 보험 만료일</span><strong style="font-size:15px;color:var(--text-primary);">' + v.rentEnd + ' / ' + v.insuranceExp + '</strong></div>' +
                '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">차량 상태</span><div>' + statusPill(v.status) + '</div></div>' +
              '</div>' +
            '</div>' +
            photosHtml +
            contractHtml +
            '<div style="margin-top:20px;display:flex;justify-content:space-between;align-items:center;padding-top:16px;border-top:1px solid var(--border-subtle);">' +
              '<div>' + actionButton + '</div>' +
              '<button type="button" onclick="this.closest(\'#vehicle-detail-modal\').remove()" class="btn-secondary" style="padding:10px 20px;">닫기</button>' +
            '</div>' +
            '<div style="margin-top:28px;">' +
              '<h3 style="font-size:16px;font-weight:700;color:var(--text-primary);margin-bottom:12px;display:flex;align-items:center;gap:6px;"><i class="ph ph-clock-counter-clockwise"></i> 대여 및 배정 이력 (Rental History)</h3>' +
              '<div id="vehicle-history-timeline" style="max-height:220px;overflow-y:auto;border:1px solid var(--border-subtle);border-radius:8px;background:var(--bg-base);padding:14px;">' +
                '<div style="color:var(--text-tertiary);text-align:center;padding:12px;">이력을 불러오는 중...</div>' +
              '</div>' +
            '</div>' +
          '</div>';

        document.body.appendChild(modal);

        try {
          var response = await fetch('/vehicle-api/' + v.realId + '/history');
          if (!response.ok) throw new Error('이력 로딩 실패');
          var res = await response.json();
          var history = res.history || [];
          var timelineHtml = '';

          if (history.length === 0) {
            timelineHtml = '<div style="color:var(--text-tertiary);text-align:center;padding:12px;">배정 이력이 없습니다.</div>';
          } else {
            timelineHtml = history.map(function(h) {
              var period = h.returned_at ? h.rented_at + ' ~ ' + h.returned_at : h.rented_at + ' ~ <span style="color:var(--status-success);font-weight:700;">사용 중</span>';
              var mileageInfo = h.end_mileage ? h.start_mileage.toLocaleString() + ' mi → ' + h.end_mileage.toLocaleString() + ' mi' : h.start_mileage.toLocaleString() + ' mi ~';
              var notesText = h.notes ? '<div style="font-size:11px;color:var(--text-tertiary);margin-top:4px;background:rgba(255,255,255,0.05);padding:4px 8px;border-radius:4px;">' + h.notes.replace(/\n/g, '<br>') + '</div>' : '';
              return '<div style="padding:10px 0;border-bottom:1px solid var(--border-subtle);font-size:13px;display:flex;flex-direction:column;gap:4px;">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;">' +
                  '<span style="font-weight:700;color:var(--text-primary);">' + h.driver + '</span>' +
                  '<span style="font-size:11px;color:var(--text-secondary);">' + period + '</span>' +
                '</div>' +
                '<div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);">' +
                  '<span>마일리지: ' + mileageInfo + '</span>' +
                  '<span style="font-weight:600;color:' + (h.status === '대여중' ? 'var(--status-success)' : 'var(--text-tertiary)') + ';">' + h.status + '</span>' +
                '</div>' +
                notesText +
              '</div>';
            }).join('');
          }
          modal.querySelector('#vehicle-history-timeline').innerHTML = timelineHtml;
        } catch (err) {
          modal.querySelector('#vehicle-history-timeline').innerHTML = '<div style="color:var(--status-danger);text-align:center;padding:12px;">이력을 불러오지 못했습니다.</div>';
        }
      };

      // Manual Assignment Modal
      window.openAssignVehicleModal = async function(vehicleId, modelName) {
        var modal = document.createElement('div');
        modal.id = 'vehicle-assign-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;';
        
        modal.innerHTML =
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:24px;width:450px;max-width:100%;box-shadow:0 10px 25px rgba(0,0,0,0.5);">' +
            '<h3 style="font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:16px;">운전자 배정: ' + modelName + '</h3>' +
            '<form id="vehicle-assign-form" style="display:flex;flex-direction:column;gap:16px;">' +
              '<div>' +
                '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">운전자 선택 <span style="color:var(--status-danger)">*</span></label>' +
                '<select name="employee_id" required style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:10px;color:var(--text-primary);">' +
                  '<option value="">불러오는 중...</option>' +
                '</select>' +
              '</div>' +
              '<div>' +
                '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">메모</label>' +
                '<textarea name="notes" placeholder="배정 사유 또는 메모를 입력하세요" style="width:100%;height:80px;background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:10px;color:var(--text-primary);resize:none;"></textarea>' +
              '</div>' +
              '<div style="display:flex;justify-content:flex-end;gap:12px;margin-top:8px;">' +
                '<button type="button" onclick="document.getElementById(\'vehicle-assign-modal\').remove()" class="btn-secondary" style="padding:8px 16px;">취소</button>' +
                '<button type="submit" class="btn-primary" style="background:var(--status-success);border:none;padding:8px 20px;font-weight:700;">배정 완료</button>' +
              '</div>' +
            '</form>' +
          '</div>';

        document.body.appendChild(modal);

        var selectEl = modal.querySelector('[name="employee_id"]');

        try {
          var employees = await window.API.getPersonnelList();
          var activeEmp = employees.filter(function(e) { return e.workerStatus === 'active'; });
          if (activeEmp.length === 0) {
            selectEl.innerHTML = '<option value="">선택 가능한 활성 직원이 없습니다.</option>';
          } else {
            selectEl.innerHTML = '<option value="">-- 운전자 선택 --</option>' + activeEmp.map(function(e) {
              var name = e.nameKr ? e.nameKr + ' (' + e.nameEn + ')' : e.nameEn;
              var comp = e.company ? ' [' + e.company + ']' : '';
              return '<option value="' + e.employeeDbId + '">' + name + comp + '</option>';
            }).join('');
          }
        } catch (err) {
          selectEl.innerHTML = '<option value="">직원 목록을 불러오지 못했습니다.</option>';
        }

        modal.querySelector('#vehicle-assign-form').onsubmit = async function(e) {
          e.preventDefault();
          var payload = {
            vehicle_id: vehicleId,
            employee_id: selectEl.value,
            notes: modal.querySelector('[name="notes"]').value
          };

          try {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var response = await fetch('/vehicle-api/assign', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
              },
              body: JSON.stringify(payload)
            });

            if (!response.ok) {
              var errData = await response.json();
              throw new Error(errData.error || '배정 처리 중 오류가 발생했습니다.');
            }

            modal.remove();
            var detailModal = document.getElementById('vehicle-detail-modal');
            if (detailModal) detailModal.remove();
            
            showToast('운전자가 배정되었습니다.');
            window.renderVehicle();
          } catch (err) {
            alert('오류: ' + err.message);
          }
        };
      };

      // Manual Return Modal
      window.openReturnVehicleModal = function(vehicleId, modelName, currentMileage) {
        var modal = document.createElement('div');
        modal.id = 'vehicle-return-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;';
        
        modal.innerHTML =
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:24px;width:400px;max-width:100%;box-shadow:0 10px 25px rgba(0,0,0,0.5);">' +
            '<h3 style="font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:16px;">차량 반납: ' + modelName + '</h3>' +
            '<form id="vehicle-return-form" style="display:flex;flex-direction:column;gap:16px;">' +
              '<div>' +
                '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">현재 마일리지 (최소 ' + currentMileage.toLocaleString() + ' mi) <span style="color:var(--status-danger)">*</span></label>' +
                '<input type="number" name="current_mileage" required min="' + currentMileage + '" value="' + currentMileage + '" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:10px;color:var(--text-primary);">' +
              '</div>' +
              '<div>' +
                '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">반납 메모</label>' +
                '<textarea name="notes" placeholder="차량 상태(청결도, 파손 여부 등) 또는 특이사항 입력" style="width:100%;height:80px;background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:10px;color:var(--text-primary);resize:none;"></textarea>' +
              '</div>' +
              '<div style="display:flex;justify-content:flex-end;gap:12px;margin-top:8px;">' +
                '<button type="button" onclick="document.getElementById(\'vehicle-return-modal\').remove()" class="btn-secondary" style="padding:8px 16px;">취소</button>' +
                '<button type="submit" class="btn-primary" style="background:var(--status-danger);border:none;padding:8px 20px;font-weight:700;">반납 완료</button>' +
              '</div>' +
            '</form>' +
          '</div>';

        document.body.appendChild(modal);

        modal.querySelector('#vehicle-return-form').onsubmit = async function(e) {
          e.preventDefault();
          var inputMileage = parseInt(modal.querySelector('[name="current_mileage"]').value, 10);
          if (inputMileage < currentMileage) {
            alert('반납 마일리지는 이전 마일리지보다 작을 수 없습니다.');
            return;
          }

          var payload = {
            vehicle_id: vehicleId,
            current_mileage: inputMileage,
            notes: modal.querySelector('[name="notes"]').value
          };

          try {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var response = await fetch('/vehicle-api/return', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
              },
              body: JSON.stringify(payload)
            });

            if (!response.ok) {
              var errData = await response.json();
              throw new Error(errData.error || '반납 처리 중 오류가 발생했습니다.');
            }

            modal.remove();
            var detailModal = document.getElementById('vehicle-detail-modal');
            if (detailModal) detailModal.remove();

            showToast('차량이 반납 완료 처리되었습니다.');
            window.renderVehicle();
          } catch (err) {
            alert('오류: ' + err.message);
          }
        };
      };

      // â”€â”€ PROJECT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      async function renderPayroll(periodStart) {
        pageContainer.innerHTML = skeleton();
        try {
          var res = await window.API.getPayrollDashboard(periodStart);
          if (!res || !res.success) {
            pageContainer.innerHTML = '<div class="panel"><div class="panel-body padded">' +
              '<div style="color:var(--status-danger);text-align:center;padding:32px">급여 ë °ì ´í„° 로딩 실패<br>' + (res && res.error || '알 수 없는 오류') + '</div></div></div>';
            return;
          }

          var period = res.period || {};
          var totals = res.totals || { headcount: 0, regHours: 0, otHours: 0, gross: 0 };
          var companies = res.companies || [];
          var anomalies = res.anomalies || [];
          var employees = res.employees || [];

          var COLOR_MGR = '#f59e0b', COLOR_KOR = '#3b82f6', COLOR_LOC = '#10b981', COLOR_TOTAL = '#a78bfa';

          // ── 1. Pay Period í—¤ë ” ──
          var periodHtml =
            '<div class="panel" style="margin-bottom:14px"><div class="panel-body padded" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">' +
              '<div style="display:flex;align-items:center;gap:12px">' +
                '<button onclick="window.shiftPayPeriod(-1)" style="background:var(--bg-base);border:1px solid var(--border-default);color:var(--text-primary);width:36px;height:36px;border-radius:8px;cursor:pointer;font-size:16px">‹</button>' +
                '<div style="text-align:center;min-width:280px">' +
                  '<div style="font-size:10px;color:var(--text-tertiary);font-weight:700;letter-spacing:0.5px;margin-bottom:2px">PAY PERIOD (Bi-weekly)</div>' +
                  '<div class="cell-mono" style="font-size:16px;font-weight:800;color:var(--text-primary)">' + period.start + ' ~ ' + period.end + '</div>' +
                  '<div style="font-size:10px;color:var(--text-tertiary);margin-top:2px">Day ' + (period.currentDay || 0) + ' / ' + (period.totalDays || 14) +
                    (period.isComplete ? ' · <span style="color:var(--status-success)">완료</span>' : ' · <span style="color:var(--status-warning)">진행중</span>') + '</div>' +
                '</div>' +
                '<button onclick="window.shiftPayPeriod(1)" style="background:var(--bg-base);border:1px solid var(--border-default);color:var(--text-primary);width:36px;height:36px;border-radius:8px;cursor:pointer;font-size:16px">›</button>' +
              '</div>' +
              '<div style="display:flex;gap:8px">' +
                '<button class="btn-secondary" onclick="window.shiftPayPeriod(0)"><i class="ph ph-arrow-clockwise"></i> 현재 주기</button>' +
                '<button class="btn-primary" onclick="window.openPayrollDocs(this)"><i class="ph ph-file-pdf"></i>명세서 (Phase B)</button>' +
              '</div>' +
            '</div></div>';

          // ——— 2. KPI 5종 (60% 압축) ———
          var kpiHtml =
            '<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px">' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">예상 인건비<i class="ph ph-currency-dollar" style="font-size:12px;color:' + COLOR_TOTAL + '"></i></div>' +
                '<div class="kpi-value cell-mono" style="font-size:22px;color:' + COLOR_TOTAL + ';line-height:1.1">$' + (totals.gross||0).toLocaleString() + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">Pay Period 누적</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">활성 인원<i class="ph ph-users" style="font-size:12px;color:#a78bfa"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;line-height:1.1">' + totals.headcount + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">' + companies.length + '개 회사</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">Regular 공수<i class="ph ph-clock" style="font-size:12px;color:#3b82f6"></i></div>' +
                '<div class="kpi-value cell-mono" style="font-size:22px;color:#3b82f6;line-height:1.1">' + (totals.regHours||0).toLocaleString() + '<span style="font-size:11px"> hr</span></div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">정규 근무</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">OT 공수<i class="ph ph-lightning" style="font-size:12px;color:#f59e0b"></i></div>' +
                '<div class="kpi-value cell-mono" style="font-size:22px;color:#f59e0b;line-height:1.1">' + (totals.otHours||0).toLocaleString() + '<span style="font-size:11px"> hr</span></div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">초과 (1.5×)</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">이상 타지<i class="ph ph-warning-circle" style="font-size:12px;color:var(--status-danger)"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;color:' + (anomalies.length > 0 ? 'var(--status-danger)' : 'var(--status-success)') + ';line-height:1.1">' + anomalies.length + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">' + (anomalies.length > 0 ? '검토 필요' : '정상') + '</span></div></div>' +
            '</div>';

          // ——— 3. 회사별 매트릭스 ———
          var companyHtml = companies.length === 0
            ? '<div class="panel" style="margin-bottom:14px"><div class="panel-body padded" style="text-align:center;color:var(--text-tertiary);padding:32px">이번 Pay Period에 데이터 없음</div></div>'
            : '<div class="panel" style="margin-bottom:14px;overflow:hidden">' +
                '<div class="panel-header" style="background:linear-gradient(90deg,rgba(167,139,250,0.10),transparent);padding:14px 18px;display:flex;align-items:center;justify-content:space-between">' +
                  '<div class="panel-title" style="display:flex;align-items:center;gap:10px">' +
                    '<i class="ph ph-chart-bar" style="font-size:18px;color:' + COLOR_TOTAL + '"></i>' +
                    '<span style="color:var(--text-primary);font-weight:700;font-size:14px">회사·직책별 인건비</span>' +
                    '<span style="font-size:10px;padding:3px 8px;background:rgba(167,139,250,0.15);color:' + COLOR_TOTAL + ';border-radius:4px;font-weight:600">' + period.start + ' ~ ' + period.end + '</span>' +
                  '</div>' +
                '</div>' +
                '<div class="panel-body" style="padding:14px;display:grid;grid-template-columns:repeat(auto-fit, minmax(380px, 1fr));gap:14px">' +
                  companies.map(function(c) {
                    var compColor = window.getCompanyColor ? window.getCompanyColor(c.name) : COLOR_TOTAL;
                    var ct = c.totals || { gross:0, count:0, regHours:0, otHours:0 };
                    var div = c.divides || {};
                    ['관리자','한국인','외국인'].forEach(function(k){ if(!div[k]) div[k] = { count:0, hours:0, gross:0 }; });
                    return '<div style="background:var(--bg-panel);border:1px solid ' + compColor + '33;border-radius:10px;overflow:hidden">' +
                        '<div style="padding:12px 16px;background:linear-gradient(90deg,' + compColor + '22,transparent);border-bottom:1px solid ' + compColor + '44;display:flex;align-items:center;justify-content:space-between">' +
                          '<div style="display:flex;align-items:center;gap:8px"><i class="ph ph-buildings" style="font-size:16px;color:' + compColor + '"></i>' +
                          '<span style="font-size:14px;font-weight:800;color:var(--text-primary)">' + c.name + '</span></div>' +
                          '<div style="text-align:right"><div class="cell-mono" style="font-size:18px;font-weight:800;color:' + compColor + '">$' + ct.gross.toLocaleString() + '</div>' +
                          '<div style="font-size:10px;color:var(--text-tertiary)">' + ct.count + '명 · ' + (ct.regHours + ct.otHours).toFixed(1) + 'h</div></div>' +
                        '</div>' +
                        '<div style="padding:12px 14px;display:flex;flex-direction:column;gap:8px">' +
                          (div['관리자'].count > 0 ? '<div style="display:flex;align-items:center;gap:10px"><i class="ph ph-crown" style="color:' + COLOR_MGR + '"></i><span style="flex:1;font-size:12px;color:var(--text-secondary)">관리자 ' + div['관리자'].count + '명</span><span class="cell-mono" style="font-size:11px;color:var(--text-tertiary)">' + div['관리자'].hours.toFixed(1) + 'h</span><span class="cell-mono" style="font-size:13px;font-weight:700;color:' + COLOR_MGR + ';width:90px;text-align:right">$' + div['관리자'].gross.toLocaleString() + '</span></div>' : '') +
                          (div['한국인'].count > 0 ? '<div style="display:flex;align-items:center;gap:10px"><i class="ph ph-flag" style="color:' + COLOR_KOR + '"></i><span style="flex:1;font-size:12px;color:var(--text-secondary)">한국인 ' + div['한국인'].count + '명</span><span class="cell-mono" style="font-size:11px;color:var(--text-tertiary)">' + div['한국인'].hours.toFixed(1) + 'h</span><span class="cell-mono" style="font-size:13px;font-weight:700;color:' + COLOR_KOR + ';width:90px;text-align:right">$' + div['한국인'].gross.toLocaleString() + '</span></div>' : '') +
                          (div['외국인'].count > 0 ? '<div style="display:flex;align-items:center;gap:10px"><i class="ph ph-globe" style="color:' + COLOR_LOC + '"></i><span style="flex:1;font-size:12px;color:var(--text-secondary)">외국인 ' + div['외국인'].count + '명</span><span class="cell-mono" style="font-size:11px;color:var(--text-tertiary)">' + div['외국인'].hours.toFixed(1) + 'h</span><span class="cell-mono" style="font-size:13px;font-weight:700;color:' + COLOR_LOC + ';width:90px;text-align:right">$' + div['외국인'].gross.toLocaleString() + '</span></div>' : '')
                        '</div>' +
                      '</div>';
                  }).join('') +
                '</div>' +
              '</div>';

          // ── 4. 이상 탐지 ──
          var anomalyHtml = anomalies.length === 0
            ? ''
            : '<div class="panel" style="margin-bottom:14px;border-left:3px solid var(--status-danger)">' +
                '<div class="panel-header"><div class="panel-title" style="color:var(--status-danger);display:flex;align-items:center;gap:8px"><i class="ph ph-warning"></i> 이상 탐지 (' + anomalies.length + '건)</div></div>' +
                '<div class="panel-body" style="padding:0">' +
                  '<table class="data-table"><thead><tr><th>Badge</th><th>이름</th><th>회사</th><th>유형</th><th>사유</th></tr></thead><tbody>' +
                  anomalies.map(function(a) {
                    var sevColor = a.severity === 'high' ? 'var(--status-danger)' : 'var(--status-warning)';
                    return '<tr style="cursor:pointer" onclick="window.openEmpInfoModal(\'' + a.badgeId + '\')">' +
                      '<td class="cell-mono">' + a.badgeId + '</td>' +
                      '<td class="cell-primary">' + a.name + '</td>' +
                      '<td><span class="tag">' + a.company + '</span></td>' +
                      '<td><span style="color:' + sevColor + ';font-weight:600;font-size:11px">' + a.type + '</span></td>' +
                      '<td style="font-size:12px">' + a.reason + (a.detail ? ' <span style="color:var(--text-tertiary);font-size:10px">(' + a.detail + ')</span>' : '') + '</td>' +
                    '</tr>';
                  }).join('') +
                  '</tbody></table>' +
                '</div>' +
              '</div>';

          // ── 5. 직원별 정산 테이블 ──
          var empHtml =
            '<div class="panel"><div class="panel-header" style="display:flex;justify-content:space-between;align-items:center">' +
              '<div class="panel-title"><i class="ph ph-list"></i> 직원별 정산 (' + employees.length + '명)</div>' +
              '<input type="text" class="search-inline" id="payroll-search" placeholder="이름, Badge ID 검색...">' +
            '</div>' +
            '<div class="panel-body" style="padding:0">' +
              '<table class="data-table" id="payroll-table">' +
                '<thead><tr><th>Badge</th><th>이름</th><th>회사</th><th>직책</th><th>Reg</th><th>OT</th><th>단가</th><th>Gross</th><th>미마감</th></tr></thead>' +
                '<tbody>' +
                  employees.map(function(e) {
                    var dColor = e.divide === '관리자' ? COLOR_MGR : e.divide === '한국인' ? COLOR_KOR : e.divide === '외국인' ? COLOR_LOC : 'var(--text-tertiary)';
                    var basisLabel = e.basis === 'salary' ? '월급' : '시급';
                    return '<tr style="cursor:pointer" onclick="window.openEmpInfoModal(\'' + e.badgeId + '\')">' +
                      '<td class="cell-mono">' + e.badgeId + '</td>' +
                      '<td class="cell-primary">' + e.name + '</td>' +
                      '<td><span class="tag">' + e.company + '</span></td>' +
                      '<td><span style="color:' + dColor + ';font-size:11px;font-weight:600">' + (e.divide || '-') + '</span></td>' +
                      '<td class="cell-mono">' + (e.regHours||0).toFixed(1) + 'h</td>' +
                      '<td class="cell-mono" style="color:' + (e.otHours > 0 ? COLOR_MGR : 'var(--text-tertiary)') + '">' + (e.otHours||0).toFixed(1) + 'h</td>' +
                      '<td class="cell-mono">$' + (e.rate||0).toFixed(2) + '<span style="font-size:9px;color:var(--text-tertiary)">/' + (e.basis === 'salary' ? 'h*' : 'h') + '</span></td>' +
                      '<td class="cell-mono" style="color:' + COLOR_TOTAL + ';font-weight:700">$' + (e.gross||0).toLocaleString() + '</td>' +
                      '<td>' + (e.openDays > 0 ? '<span style="color:var(--status-danger);font-size:11px;font-weight:600">' + e.openDays + '일</span>' : '-') + '</td>' +
                    '</tr>';
                  }).join('') +
                '</tbody></table>' +
            '</div></div>';

          pageContainer.innerHTML =
            '<div class="header-section"><div><h1 class="page-title">급여 / 정산</h1>' +
              '<p class="page-subtitle">' + (window.SITE_NAMES && window.SITE_NAMES[_siteId()] || _siteId()) + ' · Bi-weekly Pay Period 기준</p></div>' +
              '<div class="action-row"><button class="btn-secondary" onclick="openMasterSheet()"><i class="ph ph-table"></i> 시트 마스터</button></div>' +
            '</div>' +
            periodHtml + kpiHtml + companyHtml + anomalyHtml + empHtml;

          // 검색 핸들러
          var srch = document.getElementById('payroll-search');
          if (srch) srch.addEventListener('input', function() {
            var q = this.value.toLowerCase();
            document.querySelectorAll('#payroll-table tbody tr').forEach(function(row) {
              row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
          });
        } catch (e) {
          pageContainer.innerHTML = '<div class="panel"><div class="panel-body padded"><div style="color:var(--status-danger);text-align:center;padding:32px">급여 현황 로딩 중 오류<br>' + e.message + '</div></div></div>';
        }
      }

      // Pay Period 좌우 이동
      window._payrollPeriodStart = null;
      window.shiftPayPeriod = function(delta) {
        if (delta === 0) {
          window._payrollPeriodStart = null;
          renderPayroll();
          return;
        }
        var current = window._payrollPeriodStart ? new Date(window._payrollPeriodStart) : new Date();
        current.setDate(current.getDate() + (delta * 14));
        var ds = current.toISOString().slice(0, 10);
        window._payrollPeriodStart = ds;
        renderPayroll(ds);
      };

      // Run payroll for the current pay period, then open the WH-347 certified payroll doc.
      window.openPayrollDocs = function(btn) {
        var label = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner"></i> 정산 중...'; }
        gsRun('api_runPayroll', [_siteId(), window._payrollPeriodStart || null], null).then(function(res) {
          if (btn) { btn.disabled = false; btn.innerHTML = label; }
          if (res && res.success && res.certifiedUrl) {
            window.open(res.certifiedUrl, '_blank');
          } else {
            alert((res && res.error) || '급여 정산 문서를 생성할 수 없습니다.');
          }
        }).catch(function(e) {
          if (btn) { btn.disabled = false; btn.innerHTML = label; }
          alert('급여 정산 실행 실패: ' + e.message);
        });
      };

      // ══════════════════════════════════════════════════════
      // WBS — 실시간 공정 관리 (AI 메뉴얼 분석 기반)
      // ══════════════════════════════════════════════════════
      window.WBS_PROJECTS = [
        { id: 'HFF-02',  name: 'Hoffman Logistics Hub' },
        { id: 'LGES-AZ', name: 'LGES Battery Plant AZ' },
        { id: 'NV-05',   name: 'Nevada EV Plant' },
        { id: 'SST-03',  name: 'Samsung Taylor Fab' },
        { id: 'HWH-04',  name: 'Hanwha Solar Site' }
      ];
      window.WBS_CURRENT_PROJECT = 'HFF-02';

      async function renderWbs() {
        pageContainer.innerHTML = skeleton();
        var projectId = window.WBS_CURRENT_PROJECT || 'HFF-02';
        try {
          var [treeRes, sumRes] = await Promise.all([
            window.API.getProjectWbsTree(projectId),
            window.API.getProjectProgressSummary(projectId)
          ]);

          var tree = treeRes && treeRes.success ? (treeRes.stages || []) : [];
          var sum  = sumRes  && sumRes.success  ? sumRes : { totalWbsCount: 0, progress: 0 };

          // Stageë³„ ì§„ì²™ë¥  ë§µ (ì„œë²„ì—ì„œ ë°›ì€ ë°ì´í„°)
          var stageProgressMap = {};
          (sum.stages || []).forEach(function(s){ stageProgressMap[String(s.stage_no)] = s.progress; });

          // í†µê³„ ê³„ì‚°
          var totalSubTasks = 0;
          var totalManhours = 0;
          var byCompany = { NAHSHON: 0, AUTORICA: 0, 'AI-KOREA': 0, 'M-SOL': 0 };
          var ehsHigh = 0;
          tree.forEach(function(stage){
            (stage.tasks||[]).forEach(function(task){
              (task.sub_tasks||[]).forEach(function(sub){
                totalSubTasks++;
                totalManhours += parseFloat(sub.manhours)||0;
                if (byCompany[sub.company] !== undefined) byCompany[sub.company] += parseFloat(sub.manhours)||0;
                if (sub.ehs === 'high') ehsHigh++;
              });
            });
          });

          var projOptions = window.WBS_PROJECTS.map(function(p){
            return '<option value="' + p.id + '"' + (p.id === projectId ? ' selected' : '') + '>' + p.id + ' â€” ' + p.name + '</option>';
          }).join('');

          // í˜‘ë ¥ì‚¬ ë§‰ëŒ€
          var companyBars = Object.keys(byCompany).map(function(c){
            var mh = byCompany[c];
            var pct = totalManhours > 0 ? (mh / totalManhours * 100) : 0;
            var color = c === 'NAHSHON' ? '#2563eb' : c === 'AUTORICA' ? '#f59e0b' : c === 'AI-KOREA' ? '#10b981' : '#8b5cf6';
            return '<div style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);margin-bottom:3px"><span>' + c + '</span><span class="cell-mono">' + mh.toLocaleString() + ' MH (' + pct.toFixed(0) + '%)</span></div>' +
              '<div style="height:8px;background:var(--bg-base);border-radius:4px;overflow:hidden"><div style="height:100%;width:' + pct + '%;background:' + color + '"></div></div></div>';
          }).join('');

          // WBS íŠ¸ë¦¬
          var treeHtml;
          if (tree.length === 0) {
            treeHtml = '<div style="text-align:center;padding:48px;color:var(--text-tertiary)">' +
              '<i class="ph ph-tree-structure" style="font-size:48px;color:#7c3aed;margin-bottom:12px"></i>' +
              '<div style="font-size:16px;color:white;margin-bottom:8px">ì•„ì§ WBSê°€ ìƒì„±ë˜ì§€ ì•Šì•˜ìŠµë‹ˆë‹¤</div>' +
              '<div style="font-size:13px">ë§¤ë‰´ì–¼ PDFë¥¼ <strong style="color:#7c3aed">WBS_MANUAL/01_ì²˜ë¦¬ëŒ€ê¸°</strong> í´ë”ì— ì—…ë¡œë“œ í›„<br>ì•„ëž˜ <strong>AI ë©”ë‰´ì–¼ ë¶„ì„</strong> ë²„íŠ¼ì„ í´ë¦­í•˜ì„¸ìš”.</div>' +
              '</div>';
          } else {
            treeHtml = tree.map(function(stage, sIdx){
              var stageMh = 0, subCount = 0, stageCompleted = 0;
              (stage.tasks||[]).forEach(function(t){ (t.sub_tasks||[]).forEach(function(s){
                stageMh += parseFloat(s.manhours)||0;
                subCount++;
                if (s.status === 'ì™„ë£Œ') stageCompleted++;
              }); });
              var stagePct = stageProgressMap[String(stage.stage_no)] || 0;
              var stageColor = stagePct >= 100 ? '#10b981' : stagePct >= 50 ? '#f59e0b' : '#7c3aed';

              var tasksHtml = (stage.tasks||[]).map(function(task, tIdx){
                // Taskë³„ ì§„ì²™ë¥  (sub_tasks í‰ê· )
                var taskCompleted = 0, taskTotal = (task.sub_tasks||[]).length;
                (task.sub_tasks||[]).forEach(function(s){ if (s.status === 'ì™„ë£Œ') taskCompleted++; });
                var taskPct = taskTotal > 0 ? (taskCompleted / taskTotal * 100) : 0;

                var subHtml = (task.sub_tasks||[]).map(function(sub){
                  var status = sub.status || 'AIìƒì„±';
                  var isDone = status === 'ì™„ë£Œ';
                  var isProg = status === 'ì§„í–‰ì¤‘';
                  var ehsBadge = sub.ehs === 'high' ? '<span style="background:#ef4444;color:white;font-size:9px;padding:1px 5px;border-radius:3px;font-weight:700;margin-left:6px">ðŸ”´ ìœ„í—˜</span>' : sub.ehs === 'medium' ? '<span style="background:#f59e0b;color:white;font-size:9px;padding:1px 5px;border-radius:3px;font-weight:700;margin-left:6px">âš ï¸ ì£¼ì˜</span>' : '';
                  var companyColor = sub.company === 'NAHSHON' ? '#2563eb' : sub.company === 'AUTORICA' ? '#f59e0b' : sub.company === 'AI-KOREA' ? '#10b981' : sub.company === 'M-SOL' ? '#8b5cf6' : '#64748b';
                  var rowBg = isDone ? 'rgba(16,185,129,0.08)' : isProg ? 'rgba(245,158,11,0.08)' : 'var(--bg-base)';
                  var rowBorder = isDone ? '#10b981' : isProg ? '#f59e0b' : 'transparent';
                  var nameStyle = isDone ? 'text-decoration:line-through;color:#10b981;opacity:0.85' : 'color:white';
                  var statusIcon = isDone ? '<i class="ph ph-check-circle" style="color:#10b981;font-size:18px"></i>'
                    : isProg ? '<i class="ph ph-spinner" style="color:#f59e0b;font-size:18px"></i>'
                    : '<i class="ph ph-circle" style="color:var(--text-tertiary);font-size:18px"></i>';
                  // ë¹ ë¥¸ í† ê¸€ ë²„íŠ¼ (ì™„ë£Œ â†” AIìƒì„±)
                  var toggleAction = isDone ? 'AIìƒì„±' : 'ì™„ë£Œ';
                  var toggleLabel = isDone ? 'â†» ë¯¸ì™„ë£Œë¡œ' : 'âœ“ ì™„ë£Œ';
                  var toggleBg = isDone ? '#64748b' : '#10b981';

                  return '<div class="wbs-subtask" data-wbsid="' + sub.wbs_id + '" data-status="' + status + '" style="display:grid;grid-template-columns:auto auto 1fr auto auto auto auto;gap:10px;align-items:center;padding:8px 12px;border-radius:6px;background:' + rowBg + ';margin-bottom:4px;border:1px solid ' + rowBorder + ';transition:all 0.15s">' +
                    '<button onclick="event.stopPropagation();window.toggleWbsComplete(\'' + sub.wbs_id + '\',\'' + toggleAction + '\')" style="background:none;border:none;cursor:pointer;padding:2px;display:flex;align-items:center" title="' + toggleLabel + '">' + statusIcon + '</button>' +
                    '<span class="cell-mono" style="font-size:10px;color:var(--text-tertiary);min-width:60px">' + (sub.sub_no || '') + '</span>' +
                    '<span style="font-size:13px;' + nameStyle + ';cursor:pointer" onclick="openWbsEditModal(\'' + sub.wbs_id + '\')">' + (sub.sub_name || '') + ehsBadge + '</span>' +
                    '<span style="font-size:11px;color:' + companyColor + ';font-weight:700;min-width:80px;text-align:right">' + (sub.company || '-') + '</span>' +
                    '<span class="cell-mono" style="font-size:11px;color:var(--text-secondary);min-width:60px;text-align:right">' + (sub.manhours || 0) + 'MH</span>' +
                    '<span class="cell-mono" style="font-size:11px;color:var(--text-secondary);min-width:50px;text-align:right">' + (sub.days || 0) + 'ì¼</span>' +
                    '<button onclick="event.stopPropagation();window.toggleWbsComplete(\'' + sub.wbs_id + '\',\'' + toggleAction + '\')" style="background:' + toggleBg + ';color:white;border:none;border-radius:4px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap">' + toggleLabel + '</button>' +
                    '</div>';
                }).join('');

                return '<div style="margin-bottom:14px"><div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;padding:6px 10px;background:rgba(124,58,237,0.08);border-left:3px solid #7c3aed;border-radius:4px">' +
                  '<span class="cell-mono" style="font-size:11px;color:#7c3aed;font-weight:700">Task ' + (task.task_no||'') + '</span>' +
                  '<span style="font-size:14px;color:white;font-weight:600">' + (task.task_name||'') + '</span>' +
                  '<span style="margin-left:auto;display:flex;align-items:center;gap:10px;font-size:11px;color:var(--text-tertiary)">' +
                  '<span>' + taskCompleted + '/' + taskTotal + ' ì™„ë£Œ</span>' +
                  '<div style="width:80px;height:6px;background:var(--bg-base);border-radius:3px;overflow:hidden"><div style="height:100%;width:' + taskPct + '%;background:#10b981"></div></div>' +
                  '<span class="cell-mono" style="color:white;font-weight:700;min-width:32px;text-align:right">' + taskPct.toFixed(0) + '%</span>' +
                  '</span></div>' + subHtml + '</div>';
              }).join('');

              return '<details ' + (sIdx === 0 ? 'open' : '') + ' style="margin-bottom:18px;border:1px solid var(--border-default);border-radius:10px;overflow:hidden">' +
                '<summary style="padding:14px 18px;background:var(--bg-surface-elevated);cursor:pointer;display:flex;align-items:center;gap:12px;list-style:none">' +
                '<i class="ph ph-caret-right" style="transition:transform 0.2s"></i>' +
                '<span style="background:' + stageColor + ';color:white;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700">STAGE ' + (stage.stage_no || sIdx + 1) + '</span>' +
                '<span style="font-size:16px;font-weight:700;color:white">' + (stage.stage_name || '') + '</span>' +
                '<div style="margin-left:auto;display:flex;align-items:center;gap:14px;font-size:12px;color:var(--text-secondary)">' +
                '<span><i class="ph ph-check-circle" style="color:#10b981"></i> ' + stageCompleted + '/' + subCount + '</span>' +
                '<span><i class="ph ph-clock"></i> ' + stageMh.toLocaleString() + ' MH</span>' +
                '<div style="display:flex;align-items:center;gap:8px"><div style="width:120px;height:8px;background:var(--bg-base);border-radius:4px;overflow:hidden"><div style="height:100%;width:' + stagePct + '%;background:' + stageColor + '"></div></div>' +
                '<span class="cell-mono" style="color:' + stageColor + ';font-weight:700;min-width:44px;text-align:right">' + stagePct.toFixed(1) + '%</span></div>' +
                '</div></summary>' +
                '<div style="padding:14px 18px">' + tasksHtml + '</div></details>';
            }).join('');
          }

          pageContainer.innerHTML =
            '<div class="header-section"><div>' +
            '<h1 class="page-title"><i class="ph ph-tree-structure" style="color:#7c3aed"></i> ê³µì • ê´€ë¦¬ (WBS)</h1>' +
            '<p class="page-subtitle">AI ë©”ë‰´ì–¼ ë¶„ì„ ê¸°ë°˜ ì‹¤ì‹œê°„ ê³µì • ì¶”ì  Â· Stage â†’ Task â†’ SubTask ê³„ì¸µ êµ¬ì¡°</p>' +
            '</div>' +
            '<div class="action-row" style="gap:8px">' +
            '<select id="wbs-project-select" onchange="window.changeWbsProject(this.value)" style="background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px 12px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">' + projOptions + '</select>' +
            '<button class="btn-primary" style="background:linear-gradient(135deg,#7c3aed,#2563eb);border:none" onclick="window.runWbsAiAnalysis()">' +
            '<i class="ph ph-robot"></i> ðŸ¤– AI ë©”ë‰´ì–¼ ë¶„ì„</button>' +
            '<button class="btn-secondary" onclick="window.openWbsManualFolder()"><i class="ph ph-folder-open"></i> ë©”ë‰´ì–¼ í´ë”</button>' +
            '</div></div>' +
            // KPI Row (6ê°œ)
            '<div class="kpi-row" style="grid-template-columns:repeat(6,1fr)">' +
            '<div class="kpi-card"><div class="kpi-label">ì „ì²´ SubTask</div><div class="kpi-value">' + totalSubTasks + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">' + tree.length + ' Stages</span></div></div>' +
            '<div class="kpi-card" style="border-left:3px solid #7c3aed"><div class="kpi-label">ì „ì²´ ì§„ì²™ë¥ </div><div class="kpi-value" style="color:#7c3aed">' + (sum.progress || 0) + '%</div>' +
            '<div style="height:4px;background:var(--bg-base);border-radius:2px;overflow:hidden;margin-top:4px"><div style="height:100%;width:' + (sum.progress||0) + '%;background:linear-gradient(90deg,#7c3aed,#2563eb)"></div></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">âœ… ì™„ë£Œ</div><div class="kpi-value" style="color:#10b981">' + (sum.completedCount || 0) + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">' + (totalSubTasks > 0 ? ((sum.completedCount||0)/totalSubTasks*100).toFixed(0) : 0) + '% of all</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">â³ ì§„í–‰ì¤‘</div><div class="kpi-value" style="color:#f59e0b">' + (sum.inProgressCount || 0) + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">Active tasks</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ì˜ˆìƒ ì´ê³µìˆ˜</div><div class="kpi-value">' + totalManhours.toLocaleString() + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">MH</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">EHS ê³ ìœ„í—˜</div><div class="kpi-value" style="color:#ef4444">' + ehsHigh + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">ìœ„í—˜ìž‘ì—…</span></div></div>' +
            '</div>' +
            // í˜‘ë ¥ì‚¬ ìž‘ì—… ë¶€í•˜ + AI ì•ˆë‚´
            '<div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;margin-bottom:18px">' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-buildings"></i> í˜‘ë ¥ì‚¬ ìž‘ì—… ë¶€í•˜</div></div>' +
            '<div class="panel-body">' + (companyBars || '<div style="color:var(--text-tertiary);text-align:center;padding:20px">WBS ë°ì´í„° ì—†ìŒ</div>') + '</div></div>' +
            '<div style="background:linear-gradient(135deg,rgba(124,58,237,0.15),rgba(37,99,235,0.1));border:1px solid rgba(124,58,237,0.3);border-radius:10px;padding:18px;display:flex;align-items:center;gap:18px">' +
            '<i class="ph ph-robot" style="font-size:42px;color:#7c3aed;flex-shrink:0"></i>' +
            '<div style="flex:1">' +
            '<div style="font-size:14px;font-weight:700;color:#c4b5fd;margin-bottom:6px">ðŸ¤– AI ë©”ë‰´ì–¼ ë¶„ì„ ì‹œìŠ¤í…œ</div>' +
            '<div style="font-size:12px;color:var(--text-secondary);line-height:1.6">ì„¤ì¹˜ ë§¤ë‰´ì–¼/ì‹œë°©ì„œ PDFë¥¼ <strong style="color:white">WBS_MANUAL / 01_ì²˜ë¦¬ëŒ€ê¸°</strong> í´ë”ì— ì—…ë¡œë“œ í›„ <strong style="color:#c4b5fd">AI ë©”ë‰´ì–¼ ë¶„ì„</strong> ë²„íŠ¼ì„ í´ë¦­í•˜ë©´, Gemini 2.5 Proê°€ ìžë™ìœ¼ë¡œ ìž‘ì—…ì„ ìž˜ê²Œ ìª¼ê°œ WBSë¥¼ ìƒì„±í•©ë‹ˆë‹¤. Stage / Task / SubTask 3ë‹¨ê³„ ê³„ì¸µ êµ¬ì¡°ë¡œ í˜‘ë ¥ì‚¬/EHS/ê³µìˆ˜ê¹Œì§€ ìžë™ ë¶„ë¥˜.</div>' +
            '</div></div></div>' +
            // WBS íŠ¸ë¦¬
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-list-checks"></i> WBS êµ¬ì¡° â€” ' + projectId + '</div>' +
            '<div style="font-size:11px;color:var(--text-tertiary)">í´ë¦­í•˜ì—¬ ìƒì„¸ íŽ¸ì§‘</div></div>' +
            '<div class="panel-body">' + treeHtml + '</div></div>';

        } catch (err) {
          renderError('WBS ë°ì´í„° ë¡œë”© ì‹¤íŒ¨: ' + err.message);
          console.error(err);
        }
      }

      window.changeWbsProject = function(projectId) {
        window.WBS_CURRENT_PROJECT = projectId;
        renderWbs();
      };

      window.openWbsManualFolder = function() {
        window.open('https://drive.google.com/drive/folders/1rC8RSb966nL3H_vaqKD-LkDLWsNdfnl3', '_blank');
      };

      // ë¹ ë¥¸ ì™„ë£Œ í† ê¸€ â€” KPI/Stage/Task ì§„ì²™ë¥  ì¦‰ì‹œ ê°±ì‹ 
      window.toggleWbsComplete = async function(wbsId, newStatus) {
        // ìºì‹œ ë¬´íš¨í™” (ì§„ì²™ë¥  ì¦‰ì‹œ ë°˜ì˜)
        if (window.apiCache) {
          Object.keys(window.apiCache).forEach(function(k){
            if (k.indexOf('api_getProjectWbsTree') >= 0 || k.indexOf('api_getProjectProgressSummary') >= 0) {
              delete window.apiCache[k];
            }
          });
        }
        // ë‚™ê´€ì  UI: í´ë¦­ ì¦‰ì‹œ í–‰ ìƒ‰ìƒ ë³€ê²½
        var row = document.querySelector('.wbs-subtask[data-wbsid="' + wbsId + '"]');
        if (row) {
          row.style.opacity = '0.5';
          row.style.pointerEvents = 'none';
        }
        try {
          var res = await window.API.markWbsStatus(wbsId, newStatus);
          if (res && res.success) {
            // ì „ì²´ ë¦¬ë Œë” (KPI + Stage ì§„ì²™ë¥  ëª¨ë‘ ê°±ì‹ )
            renderWbs();
          } else {
            alert('ìƒíƒœ ë³€ê²½ ì‹¤íŒ¨: ' + (res && res.error ? res.error : 'unknown'));
            if (row) { row.style.opacity = ''; row.style.pointerEvents = ''; }
          }
        } catch(e) {
          alert('ì˜¤ë¥˜: ' + e.message);
          if (row) { row.style.opacity = ''; row.style.pointerEvents = ''; }
        }
      };

      window.runWbsAiAnalysis = async function() {
        var projectId = window.WBS_CURRENT_PROJECT || 'HFF-02';
        var ok = confirm('ðŸ¤– AI ë©”ë‰´ì–¼ ë¶„ì„ì„ ì‹¤í–‰í•˜ì‹œê² ìŠµë‹ˆê¹Œ?\n\ní˜„ìž¥: ' + projectId + '\ní´ë”: WBS_MANUAL / 01_ì²˜ë¦¬ëŒ€ê¸°\n\nGemini 2.5 Proê°€ í´ë” ë‚´ ëª¨ë“  ë§¤ë‰´ì–¼ì„ ë¶„ì„í•˜ì—¬\nWBSë¥¼ ìžë™ ìƒì„±í•©ë‹ˆë‹¤. (ìˆ˜ ë¶„ ì†Œìš” ê°€ëŠ¥)');
        if (!ok) return;

        var overlay = document.createElement('div');
        overlay.id = 'wbs-ai-overlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px';
        overlay.innerHTML =
          '<div style="width:80px;height:80px;border:5px solid rgba(124,58,237,0.3);border-top-color:#7c3aed;border-radius:50%;animation:spin 1s linear infinite"></div>' +
          '<div style="color:white;font-size:18px;font-weight:700">ðŸ¤– Gemini 2.5 Pro ë¶„ì„ ì¤‘...</div>' +
          '<div style="color:rgba(255,255,255,0.7);font-size:13px">WBS_MANUAL / 01_ì²˜ë¦¬ëŒ€ê¸° í´ë” ìŠ¤ìº” â†’ AI ë¶„ì„ â†’ WBS ìƒì„±</div>' +
          '<div style="color:rgba(255,255,255,0.5);font-size:11px">ëŒ€ìš©ëŸ‰ PDFì˜ ê²½ìš° 2~5ë¶„ ì†Œìš”ë©ë‹ˆë‹¤. íŽ˜ì´ì§€ ë‹«ì§€ ë§ˆì„¸ìš”.</div>';
        document.body.appendChild(overlay);

        try {
          var result = await window.API.processWbsManual(projectId);
          overlay.remove();

          var modal = document.createElement('div');
          modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:9999;display:flex;align-items:center;justify-content:center';
          var icon = result.success ? (result.processed === 0 ? 'ðŸ“‚' : 'âœ…') : 'âŒ';
          var detailRows = (result.results || []).map(function(r) {
            var sIcon = r.status === 'success' ? 'âœ…' : 'âŒ';
            var detail = r.status === 'success'
              ? '<span style="color:var(--status-success)">' + r.stages + ' Stages Â· ' + r.tasks + ' Tasks Â· ' + r.subTasks + ' SubTasks</span>'
              : '<span style="color:var(--status-danger)">' + (r.error || '') + '</span>';
            return '<div style="padding:10px 0;border-bottom:1px solid var(--border-subtle);font-size:12px">' +
              sIcon + ' <strong>' + r.file + '</strong><br>' + detail + '</div>';
          }).join('');

          modal.innerHTML =
            '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:28px;width:560px;max-height:80vh;overflow-y:auto">' +
            '<div style="font-size:42px;text-align:center;margin-bottom:12px">' + icon + '</div>' +
            '<h2 style="text-align:center;font-size:18px;margin-bottom:12px">AI ë©”ë‰´ì–¼ ë¶„ì„ ê²°ê³¼</h2>' +
            (result.processed === 0 && result.success
              ? '<div style="text-align:center;color:var(--text-secondary);padding:20px">ì²˜ë¦¬í•  íŒŒì¼ì´ ì—†ìŠµë‹ˆë‹¤.<br><span style="font-size:11px;color:var(--text-tertiary)">01_ì²˜ë¦¬ëŒ€ê¸° í´ë”ì— ë§¤ë‰´ì–¼ì„ ì—…ë¡œë“œí•˜ì„¸ìš”.</span></div>'
              : !result.success
                ? '<div style="text-align:center;color:var(--status-danger);padding:20px">' + (result.error || 'ì•Œ ìˆ˜ ì—†ëŠ” ì˜¤ë¥˜') + '</div>'
                : '<div style="max-height:320px;overflow-y:auto;margin-bottom:18px">' + detailRows + '</div>') +
            '<button id="wbs-result-close" style="width:100%;background:#7c3aed;color:white;border:none;border-radius:8px;padding:12px;font-size:14px;font-weight:700;cursor:pointer">í™•ì¸ í›„ ìƒˆë¡œê³ ì¹¨</button>' +
            '</div>';
          document.body.appendChild(modal);
          modal.querySelector('#wbs-result-close').addEventListener('click', function() {
            modal.remove();
            renderWbs();
          });
        } catch(err) {
          if (document.getElementById('wbs-ai-overlay')) document.getElementById('wbs-ai-overlay').remove();
          alert('AI ë¶„ì„ ì¤‘ ì˜¤ë¥˜:\n' + err.message);
        }
      };

      window.openWbsEditModal = function(wbsId) {
        var modal = document.createElement('div');
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center';
        modal.innerHTML =
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:14px;padding:24px;width:480px">' +
          '<h3 style="margin:0 0 14px 0;display:flex;align-items:center;gap:8px"><i class="ph ph-pencil-simple" style="color:#7c3aed"></i> WBS íŽ¸ì§‘</h3>' +
          '<div style="font-size:12px;color:var(--text-tertiary);margin-bottom:14px;font-family:monospace">' + wbsId + '</div>' +
          '<div style="display:grid;gap:12px">' +
          '<div><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">ìƒíƒœ</label>' +
          '<select id="wbs-edit-status" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px;border-radius:6px">' +
          '<option value="AIìƒì„±">ðŸ“ AIìƒì„± (ëŒ€ê¸°)</option><option value="ê²€ìˆ˜ì™„ë£Œ">âœ… ê²€ìˆ˜ì™„ë£Œ</option><option value="ì§„í–‰ì¤‘">â³ ì§„í–‰ì¤‘</option><option value="ì™„ë£Œ">ðŸŽ¯ ì™„ë£Œ</option><option value="ë³´ë¥˜">â¸ï¸ ë³´ë¥˜</option></select></div>' +
          '<div><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">ë‹´ë‹¹ì‚¬</label>' +
          '<select id="wbs-edit-company" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px;border-radius:6px">' +
          '<option value="NAHSHON">NAHSHON</option><option value="AUTORICA">AUTORICA</option><option value="AI-KOREA">AI-KOREA</option><option value="M-SOL">M-SOL</option></select></div>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">' +
          '<div><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">ì‹œìž‘ì˜ˆì •</label>' +
          '<input type="date" id="wbs-edit-start" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px;border-radius:6px"></div>' +
          '<div><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">ì¢…ë£Œì˜ˆì •</label>' +
          '<input type="date" id="wbs-edit-end" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px;border-radius:6px"></div>' +
          '</div></div>' +
          '<div style="display:flex;gap:10px;margin-top:18px">' +
          '<button id="wbs-edit-cancel" class="btn-secondary" style="flex:1">ì·¨ì†Œ</button>' +
          '<button id="wbs-edit-save" class="btn-primary" style="flex:1;background:#7c3aed">ì €ìž¥</button>' +
          '</div></div>';
        document.body.appendChild(modal);

        modal.querySelector('#wbs-edit-cancel').addEventListener('click', function() { modal.remove(); });
        modal.querySelector('#wbs-edit-save').addEventListener('click', async function() {
          var updates = {
            'ìƒíƒœ': document.getElementById('wbs-edit-status').value,
            'ë‹´ë‹¹ì‚¬': document.getElementById('wbs-edit-company').value,
            'ì‹œìž‘ì˜ˆì •': document.getElementById('wbs-edit-start').value,
            'ì¢…ë£Œì˜ˆì •': document.getElementById('wbs-edit-end').value
          };
          try {
            var res = await window.API.updateWbsRow(wbsId, updates);
            if (res.success) {
              modal.remove();
              renderWbs();
            } else {
              alert('ì €ìž¥ ì‹¤íŒ¨: ' + (res.error || 'unknown'));
            }
          } catch(e) {
            alert('ì €ìž¥ ì˜¤ë¥˜: ' + e.message);
          }
        });
        modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
      };

      // â”€â”€ VEHICLE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€


      async function renderRental() {
        pageContainer.innerHTML = skeleton();
        try {
          const [stats, rentals] = await Promise.all([
            window.API.getRentalStats(),
            window.API.getRentalList()
          ]);

          window.currentRentals = rentals; // Cache globally for detail modal lookup

          var rowsHtml = rentals.map(function(r) {
            var actionBtn = '';
            if (r.status === '대기중') {
              actionBtn = '<button class="btn-primary" style="background:var(--status-success);border:none;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;" onclick="event.stopPropagation(); window.openAssignEquipmentModal(' + r.realId + ', \'' + r.model + '\')">배정</button>';
            } else if (r.status === '사용중') {
              actionBtn = '<button class="btn-secondary" style="background:var(--status-danger);border:none;color:#fff;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;" onclick="event.stopPropagation(); window.openReturnEquipmentModal(' + r.realId + ', \'' + r.model + '\')">반납</button>';
            } else {
              actionBtn = '<span style="color:var(--text-tertiary)">-</span>';
            }

            var operator = r.operator || '<span style="color:var(--text-tertiary)">미배정</span>';

            return '<tr style="cursor:pointer;" onclick="window.openEquipmentDetailModal(' + r.realId + ')">'
              + '<td class="cell-mono">' + r.id + '</td>'
              + '<td class="cell-primary">' + r.equipType + '</td>'
              + '<td>' + r.model + '</td>'
              + '<td>' + r.vendor + '</td>'
              + '<td class="cell-mono">' + r.startDate + ' ~ ' + r.endDate + '</td>'
              + '<td>' + (r.company || '-') + '</td>'
              + '<td>' + (r.team || '-') + '</td>'
              + '<td>' + operator + '</td>'
              + '<td>' + statusPill(r.status) + '</td>'
              + '<td>' + actionBtn + '</td>'
              + '</tr>';
          }).join('');

          var byCompanyHtml = '';
          if (!stats.byCompany || stats.byCompany.length === 0) {
            byCompanyHtml = '<div style="color:var(--text-tertiary); text-align:center; padding:12px; font-size:12px;">사용중인 계약회사가 없습니다.</div>';
          } else {
            byCompanyHtml = stats.byCompany.map(function(c) {
              var pct = stats.active > 0 ? (c.count / stats.active * 100) : 0;
              return '<div style="margin-bottom:12px;">' +
                     '  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">' +
                     '    <span style="font-size:12px; font-weight:600; color:var(--text-primary);">' + c.name + '</span>' +
                     '    <span style="font-size:12px; font-weight:700; color:#7c3aed;">' + c.count + '대 사용중</span>' +
                     '  </div>' +
                     '  <div style="height:6px; background:var(--bg-base); border-radius:3px; overflow:hidden;">' +
                     '    <div style="width:' + pct + '%; height:100%; background:linear-gradient(90deg, #7c3aed, #2563eb); border-radius:3px;"></div>' +
                     '  </div>' +
                     '</div>';
            }).join('');
          }

          pageContainer.innerHTML =
            '<div class="header-section"><div><h1 class="page-title">장비 렌탈 관리</h1><p class="page-subtitle">중장비 단기 렌탈 현황 · 반납일/비용 추적</p></div>' +
            '<div class="action-row">' +
            '<button class="btn-secondary" onclick="window.downloadRentalExcel()"><i class="ph ph-file-xls"></i> 엑셀 다운로드</button>' +
            '<button class="btn-primary" style="background:linear-gradient(135deg,#7c3aed,#2563eb);border:none" onclick="window.openAiEquipmentRegModal()"><i class="ph ph-robot"></i> 🤖 AI 계약서 등록</button>' +
            '</div></div>' +
            
            '<div style="display:grid; grid-template-columns: 7.2fr 2.8fr; gap:20px; align-items:start;">' +
            '  <div style="display:flex; flex-direction:column; gap:20px;">' +
            '    <div class="kpi-row" style="grid-template-columns:repeat(3,1fr); gap:12px;">' +
            '      <div class="kpi-card"><div class="kpi-label">전체 장비</div><div class="kpi-value">' + stats.total + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">등록 장비</span></div></div>' +
            '      <div class="kpi-card"><div class="kpi-label">사용중</div><div class="kpi-value" style="color:var(--status-success)">' + stats.active + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">현장 가동</span></div></div>' +
            '      <div class="kpi-card"><div class="kpi-label">대기중</div><div class="kpi-value" style="color:var(--status-warning)">' + stats.available + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">배정 대기</span></div></div>' +
            '    </div>' +
            '    <div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-bulldozer"></i> 렌탈 목록</div></div>' +
            '    <div class="panel-body" style="overflow-x:auto;"><table class="data-table"><thead><tr>' +
            '    <th>렌탈ID</th><th>장비종류</th><th>모델</th><th>벤더</th><th>기간</th>' +
            '    <th>배정회사</th><th>배정팀</th><th>운영자</th><th>상태</th><th>액션</th>' +
            '    </tr></thead><tbody>' + (rowsHtml || '<tr><td colspan="10" style="text-align:center;color:var(--text-tertiary);padding:32px">등록된 렌탈 없음</td></tr>') + '</tbody></table></div></div>' +
            '  </div>' +
            '  <div class="panel" style="position:sticky; top:20px;">' +
            '    <div class="panel-header"><div class="panel-title"><i class="ph ph-buildings"></i> 계약회사별 사용중인 장비 현황</div></div>' +
            '    <div class="panel-body padded" style="display:flex; flex-direction:column; gap:16px;">' +
                   byCompanyHtml +
            '    </div>' +
            '  </div>' +
            '</div>';

        } catch (err) { renderError('렌탈 데이터 로딩 실패'); console.error(err); }
      }

      // Manual Return Modal
      window.openReturnVehicleModal = function(vehicleId, modelName, currentMileage) {
        var modal = document.createElement('div');
        modal.id = 'vehicle-return-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;';
        
        modal.innerHTML =
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:24px;width:400px;max-width:100%;box-shadow:0 10px 25px rgba(0,0,0,0.5);">' +
            '<h3 style="font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:16px;">차량 반납: ' + modelName + '</h3>' +
            '<form id="vehicle-return-form" style="display:flex;flex-direction:column;gap:16px;">' +
              '<div>' +
                '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">현재 마일리지 (최소 ' + currentMileage.toLocaleString() + ' mi) <span style="color:var(--status-danger)">*</span></label>' +
                '<input type="number" name="current_mileage" required min="' + currentMileage + '" value="' + currentMileage + '" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:10px;color:var(--text-primary);">' +
              '</div>' +
              '<div>' +
                '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">반납 메모</label>' +
                '<textarea name="notes" placeholder="차량 상태(청결도, 파손 여부 등) 또는 특이사항 입력" style="width:100%;height:80px;background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:10px;color:var(--text-primary);resize:none;"></textarea>' +
              '</div>' +
              '<div style="display:flex;justify-content:flex-end;gap:12px;margin-top:8px;">' +
                '<button type="button" onclick="document.getElementById(\'vehicle-return-modal\').remove()" class="btn-secondary" style="padding:8px 16px;">취소</button>' +
                '<button type="submit" class="btn-primary" style="background:var(--status-danger);border:none;padding:8px 20px;font-weight:700;">반납 완료</button>' +
              '</div>' +
            '</form>' +
          '</div>';

        document.body.appendChild(modal);

        modal.querySelector('#vehicle-return-form').onsubmit = async function(e) {
          e.preventDefault();
          var inputMileage = parseInt(modal.querySelector('[name="current_mileage"]').value, 10);
          if (inputMileage < currentMileage) {
            alert('반납 마일리지는 이전 마일리지보다 작을 수 없습니다.');
            return;
          }

          var payload = {
            vehicle_id: vehicleId,
            current_mileage: inputMileage,
            notes: modal.querySelector('[name="notes"]').value
          };

          try {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var response = await fetch('/vehicle-api/return', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
              },
              body: JSON.stringify(payload)
            });

            if (!response.ok) {
              var errData = await response.json();
              throw new Error(errData.error || '반납 처리 중 오류가 발생했습니다.');
            }

            modal.remove();
            var detailModal = document.getElementById('vehicle-detail-modal');
            if (detailModal) detailModal.remove();

            showToast('차량이 반납 완료 처리되었습니다.');
            window.renderVehicle();
          } catch (err) {
            alert('오류: ' + err.message);
          }
        };
      };

      // â”€â”€ PROJECT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      async function renderPayroll(periodStart) {
        pageContainer.innerHTML = skeleton();
        try {
          var res = await window.API.getPayrollDashboard(periodStart);
          if (!res || !res.success) {
            pageContainer.innerHTML = '<div class="panel"><div class="panel-body padded">' +
              '<div style="color:var(--status-danger);text-align:center;padding:32px">급여 ë °ì ´í„° 로딩 실패<br>' + (res && res.error || '알 수 없는 오류') + '</div></div></div>';
            return;
          }

          var period = res.period || {};
          var totals = res.totals || { headcount: 0, regHours: 0, otHours: 0, gross: 0 };
          var companies = res.companies || [];
          var anomalies = res.anomalies || [];
          var employees = res.employees || [];

          var COLOR_MGR = '#f59e0b', COLOR_KOR = '#3b82f6', COLOR_LOC = '#10b981', COLOR_TOTAL = '#a78bfa';

          // ── 1. Pay Period í—¤ë ” ──
          var periodHtml =
            '<div class="panel" style="margin-bottom:14px"><div class="panel-body padded" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">' +
              '<div style="display:flex;align-items:center;gap:12px">' +
                '<button onclick="window.shiftPayPeriod(-1)" style="background:var(--bg-base);border:1px solid var(--border-default);color:var(--text-primary);width:36px;height:36px;border-radius:8px;cursor:pointer;font-size:16px">‹</button>' +
                '<div style="text-align:center;min-width:280px">' +
                  '<div style="font-size:10px;color:var(--text-tertiary);font-weight:700;letter-spacing:0.5px;margin-bottom:2px">PAY PERIOD (Bi-weekly)</div>' +
                  '<div class="cell-mono" style="font-size:16px;font-weight:800;color:var(--text-primary)">' + period.start + ' ~ ' + period.end + '</div>' +
                  '<div style="font-size:10px;color:var(--text-tertiary);margin-top:2px">Day ' + (period.currentDay || 0) + ' / ' + (period.totalDays || 14) +
                    (period.isComplete ? ' · <span style="color:var(--status-success)">완료</span>' : ' · <span style="color:var(--status-warning)">진행중</span>') + '</div>' +
                '</div>' +
                '<button onclick="window.shiftPayPeriod(1)" style="background:var(--bg-base);border:1px solid var(--border-default);color:var(--text-primary);width:36px;height:36px;border-radius:8px;cursor:pointer;font-size:16px">›</button>' +
              '</div>' +
              '<div style="display:flex;gap:8px">' +
                '<button class="btn-secondary" onclick="window.shiftPayPeriod(0)"><i class="ph ph-arrow-clockwise"></i> 현재 주기</button>' +
                '<button class="btn-primary" onclick="window.openPayrollDocs(this)"><i class="ph ph-file-pdf"></i>명세서 (Phase B)</button>' +
              '</div>' +
            '</div></div>';

          // ——— 2. KPI 5종 (60% 압축) ———
          var kpiHtml =
            '<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px">' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">예상 인건비<i class="ph ph-currency-dollar" style="font-size:12px;color:' + COLOR_TOTAL + '"></i></div>' +
                '<div class="kpi-value cell-mono" style="font-size:22px;color:' + COLOR_TOTAL + ';line-height:1.1">$' + (totals.gross||0).toLocaleString() + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">Pay Period 누적</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">활성 인원<i class="ph ph-users" style="font-size:12px;color:#a78bfa"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;line-height:1.1">' + totals.headcount + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">' + companies.length + '개 회사</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">Regular 공수<i class="ph ph-clock" style="font-size:12px;color:#3b82f6"></i></div>' +
                '<div class="kpi-value cell-mono" style="font-size:22px;color:#3b82f6;line-height:1.1">' + (totals.regHours||0).toLocaleString() + '<span style="font-size:11px"> hr</span></div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">정규 근무</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">OT 공수<i class="ph ph-lightning" style="font-size:12px;color:#f59e0b"></i></div>' +
                '<div class="kpi-value cell-mono" style="font-size:22px;color:#f59e0b;line-height:1.1">' + (totals.otHours||0).toLocaleString() + '<span style="font-size:11px"> hr</span></div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">초과 (1.5×)</span></div></div>' +
              '<div class="kpi-card" style="padding:10px 12px"><div class="kpi-label" style="font-size:10px">이상 타지<i class="ph ph-warning-circle" style="font-size:12px;color:var(--status-danger)"></i></div>' +
                '<div class="kpi-value" style="font-size:22px;color:' + (anomalies.length > 0 ? 'var(--status-danger)' : 'var(--status-success)') + ';line-height:1.1">' + anomalies.length + '</div>' +
                '<div class="kpi-meta" style="font-size:9px"><span style="color:var(--text-secondary)">' + (anomalies.length > 0 ? '검토 필요' : '정상') + '</span></div></div>' +
            '</div>';

          // ——— 3. 회사별 매트릭스 ———
          var companyHtml = companies.length === 0
            ? '<div class="panel" style="margin-bottom:14px"><div class="panel-body padded" style="text-align:center;color:var(--text-tertiary);padding:32px">이번 Pay Period에 데이터 없음</div></div>'
            : '<div class="panel" style="margin-bottom:14px;overflow:hidden">' +
                '<div class="panel-header" style="background:linear-gradient(90deg,rgba(167,139,250,0.10),transparent);padding:14px 18px;display:flex;align-items:center;justify-content:space-between">' +
                  '<div class="panel-title" style="display:flex;align-items:center;gap:10px">' +
                    '<i class="ph ph-chart-bar" style="font-size:18px;color:' + COLOR_TOTAL + '"></i>' +
                    '<span style="color:var(--text-primary);font-weight:700;font-size:14px">회사·직책별 인건비</span>' +
                    '<span style="font-size:10px;padding:3px 8px;background:rgba(167,139,250,0.15);color:' + COLOR_TOTAL + ';border-radius:4px;font-weight:600">' + period.start + ' ~ ' + period.end + '</span>' +
                  '</div>' +
                '</div>' +
                '<div class="panel-body" style="padding:14px;display:grid;grid-template-columns:repeat(auto-fit, minmax(380px, 1fr));gap:14px">' +
                  companies.map(function(c) {
                    var compColor = window.getCompanyColor ? window.getCompanyColor(c.name) : COLOR_TOTAL;
                    var ct = c.totals || { gross:0, count:0, regHours:0, otHours:0 };
                    var div = c.divides || {};
                    ['관리자','한국인','외국인'].forEach(function(k){ if(!div[k]) div[k] = { count:0, hours:0, gross:0 }; });
                    return '<div style="background:var(--bg-panel);border:1px solid ' + compColor + '33;border-radius:10px;overflow:hidden">' +
                        '<div style="padding:12px 16px;background:linear-gradient(90deg,' + compColor + '22,transparent);border-bottom:1px solid ' + compColor + '44;display:flex;align-items:center;justify-content:space-between">' +
                          '<div style="display:flex;align-items:center;gap:8px"><i class="ph ph-buildings" style="font-size:16px;color:' + compColor + '"></i>' +
                          '<span style="font-size:14px;font-weight:800;color:var(--text-primary)">' + c.name + '</span></div>' +
                          '<div style="text-align:right"><div class="cell-mono" style="font-size:18px;font-weight:800;color:' + compColor + '">$' + ct.gross.toLocaleString() + '</div>' +
                          '<div style="font-size:10px;color:var(--text-tertiary)">' + ct.count + '명 · ' + (ct.regHours + ct.otHours).toFixed(1) + 'h</div></div>' +
                        '</div>' +
                        '<div style="padding:12px 14px;display:flex;flex-direction:column;gap:8px">' +
                          (div['관리자'].count > 0 ? '<div style="display:flex;align-items:center;gap:10px"><i class="ph ph-crown" style="color:' + COLOR_MGR + '"></i><span style="flex:1;font-size:12px;color:var(--text-secondary)">관리자 ' + div['관리자'].count + '명</span><span class="cell-mono" style="font-size:11px;color:var(--text-tertiary)">' + div['관리자'].hours.toFixed(1) + 'h</span><span class="cell-mono" style="font-size:13px;font-weight:700;color:' + COLOR_MGR + ';width:90px;text-align:right">$' + div['관리자'].gross.toLocaleString() + '</span></div>' : '') +
                          (div['한국인'].count > 0 ? '<div style="display:flex;align-items:center;gap:10px"><i class="ph ph-flag" style="color:' + COLOR_KOR + '"></i><span style="flex:1;font-size:12px;color:var(--text-secondary)">한국인 ' + div['한국인'].count + '명</span><span class="cell-mono" style="font-size:11px;color:var(--text-tertiary)">' + div['한국인'].hours.toFixed(1) + 'h</span><span class="cell-mono" style="font-size:13px;font-weight:700;color:' + COLOR_KOR + ';width:90px;text-align:right">$' + div['한국인'].gross.toLocaleString() + '</span></div>' : '') +
                          (div['외국인'].count > 0 ? '<div style="display:flex;align-items:center;gap:10px"><i class="ph ph-globe" style="color:' + COLOR_LOC + '"></i><span style="flex:1;font-size:12px;color:var(--text-secondary)">외국인 ' + div['외국인'].count + '명</span><span class="cell-mono" style="font-size:11px;color:var(--text-tertiary)">' + div['외국인'].hours.toFixed(1) + 'h</span><span class="cell-mono" style="font-size:13px;font-weight:700;color:' + COLOR_LOC + ';width:90px;text-align:right">$' + div['외국인'].gross.toLocaleString() + '</span></div>' : '')
                        '</div>' +
                      '</div>';
                  }).join('') +
                '</div>' +
              '</div>';

          // ── 4. 이상 탐지 ──
          var anomalyHtml = anomalies.length === 0
            ? ''
            : '<div class="panel" style="margin-bottom:14px;border-left:3px solid var(--status-danger)">' +
                '<div class="panel-header"><div class="panel-title" style="color:var(--status-danger);display:flex;align-items:center;gap:8px"><i class="ph ph-warning"></i> 이상 탐지 (' + anomalies.length + '건)</div></div>' +
                '<div class="panel-body" style="padding:0">' +
                  '<table class="data-table"><thead><tr><th>Badge</th><th>이름</th><th>회사</th><th>유형</th><th>사유</th></tr></thead><tbody>' +
                  anomalies.map(function(a) {
                    var sevColor = a.severity === 'high' ? 'var(--status-danger)' : 'var(--status-warning)';
                    return '<tr style="cursor:pointer" onclick="window.openEmpInfoModal(\'' + a.badgeId + '\')">' +
                      '<td class="cell-mono">' + a.badgeId + '</td>' +
                      '<td class="cell-primary">' + a.name + '</td>' +
                      '<td><span class="tag">' + a.company + '</span></td>' +
                      '<td><span style="color:' + sevColor + ';font-weight:600;font-size:11px">' + a.type + '</span></td>' +
                      '<td style="font-size:12px">' + a.reason + (a.detail ? ' <span style="color:var(--text-tertiary);font-size:10px">(' + a.detail + ')</span>' : '') + '</td>' +
                    '</tr>';
                  }).join('') +
                  '</tbody></table>' +
                '</div>' +
              '</div>';

          // ── 5. 직원별 정산 테이블 ──
          var empHtml =
            '<div class="panel"><div class="panel-header" style="display:flex;justify-content:space-between;align-items:center">' +
              '<div class="panel-title"><i class="ph ph-list"></i> 직원별 정산 (' + employees.length + '명)</div>' +
              '<input type="text" class="search-inline" id="payroll-search" placeholder="이름, Badge ID 검색...">' +
            '</div>' +
            '<div class="panel-body" style="padding:0">' +
              '<table class="data-table" id="payroll-table">' +
                '<thead><tr><th>Badge</th><th>이름</th><th>회사</th><th>직책</th><th>Reg</th><th>OT</th><th>단가</th><th>Gross</th><th>미마감</th></tr></thead>' +
                '<tbody>' +
                  employees.map(function(e) {
                    var dColor = e.divide === '관리자' ? COLOR_MGR : e.divide === '한국인' ? COLOR_KOR : e.divide === '외국인' ? COLOR_LOC : 'var(--text-tertiary)';
                    var basisLabel = e.basis === 'salary' ? '월급' : '시급';
                    return '<tr style="cursor:pointer" onclick="window.openEmpInfoModal(\'' + e.badgeId + '\')">' +
                      '<td class="cell-mono">' + e.badgeId + '</td>' +
                      '<td class="cell-primary">' + e.name + '</td>' +
                      '<td><span class="tag">' + e.company + '</span></td>' +
                      '<td><span style="color:' + dColor + ';font-size:11px;font-weight:600">' + (e.divide || '-') + '</span></td>' +
                      '<td class="cell-mono">' + (e.regHours||0).toFixed(1) + 'h</td>' +
                      '<td class="cell-mono" style="color:' + (e.otHours > 0 ? COLOR_MGR : 'var(--text-tertiary)') + '">' + (e.otHours||0).toFixed(1) + 'h</td>' +
                      '<td class="cell-mono">$' + (e.rate||0).toFixed(2) + '<span style="font-size:9px;color:var(--text-tertiary)">/' + (e.basis === 'salary' ? 'h*' : 'h') + '</span></td>' +
                      '<td class="cell-mono" style="color:' + COLOR_TOTAL + ';font-weight:700">$' + (e.gross||0).toLocaleString() + '</td>' +
                      '<td>' + (e.openDays > 0 ? '<span style="color:var(--status-danger);font-size:11px;font-weight:600">' + e.openDays + '일</span>' : '-') + '</td>' +
                    '</tr>';
                  }).join('') +
                '</tbody></table>' +
            '</div></div>';

          pageContainer.innerHTML =
            '<div class="header-section"><div><h1 class="page-title">급여 / 정산</h1>' +
              '<p class="page-subtitle">' + (window.SITE_NAMES && window.SITE_NAMES[_siteId()] || _siteId()) + ' · Bi-weekly Pay Period 기준</p></div>' +
              '<div class="action-row"><button class="btn-secondary" onclick="openMasterSheet()"><i class="ph ph-table"></i> 시트 마스터</button></div>' +
            '</div>' +
            periodHtml + kpiHtml + companyHtml + anomalyHtml + empHtml;

          // 검색 핸들러
          var srch = document.getElementById('payroll-search');
          if (srch) srch.addEventListener('input', function() {
            var q = this.value.toLowerCase();
            document.querySelectorAll('#payroll-table tbody tr').forEach(function(row) {
              row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
          });
        } catch (e) {
          pageContainer.innerHTML = '<div class="panel"><div class="panel-body padded"><div style="color:var(--status-danger);text-align:center;padding:32px">급여 현황 로딩 중 오류<br>' + e.message + '</div></div></div>';
        }
      }

      // Pay Period 좌우 이동
      window._payrollPeriodStart = null;
      window.shiftPayPeriod = function(delta) {
        if (delta === 0) {
          window._payrollPeriodStart = null;
          renderPayroll();
          return;
        }
        var current = window._payrollPeriodStart ? new Date(window._payrollPeriodStart) : new Date();
        current.setDate(current.getDate() + (delta * 14));
        var ds = current.toISOString().slice(0, 10);
        window._payrollPeriodStart = ds;
        renderPayroll(ds);
      };

      // Run payroll for the current pay period, then open the WH-347 certified payroll doc.
      window.openPayrollDocs = function(btn) {
        var label = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner"></i> 정산 중...'; }
        gsRun('api_runPayroll', [_siteId(), window._payrollPeriodStart || null], null).then(function(res) {
          if (btn) { btn.disabled = false; btn.innerHTML = label; }
          if (res && res.success && res.certifiedUrl) {
            window.open(res.certifiedUrl, '_blank');
          } else {
            alert((res && res.error) || '급여 정산 문서를 생성할 수 없습니다.');
          }
        }).catch(function(e) {
          if (btn) { btn.disabled = false; btn.innerHTML = label; }
          alert('급여 정산 실행 실패: ' + e.message);
        });
      };

      // ══════════════════════════════════════════════════════
      // WBS — 실시간 공정 관리 (AI 메뉴얼 분석 기반)
      // ══════════════════════════════════════════════════════
      window.WBS_PROJECTS = [
        { id: 'HFF-02',  name: 'Hoffman Logistics Hub' },
        { id: 'LGES-AZ', name: 'LGES Battery Plant AZ' },
        { id: 'NV-05',   name: 'Nevada EV Plant' },
        { id: 'SST-03',  name: 'Samsung Taylor Fab' },
        { id: 'HWH-04',  name: 'Hanwha Solar Site' }
      ];
      window.WBS_CURRENT_PROJECT = 'HFF-02';

      async function renderWbs() {
        pageContainer.innerHTML = skeleton();
        var projectId = window.WBS_CURRENT_PROJECT || 'HFF-02';
        try {
          var [treeRes, sumRes] = await Promise.all([
            window.API.getProjectWbsTree(projectId),
            window.API.getProjectProgressSummary(projectId)
          ]);

          var tree = treeRes && treeRes.success ? (treeRes.stages || []) : [];
          var sum  = sumRes  && sumRes.success  ? sumRes : { totalWbsCount: 0, progress: 0 };

          // Stageë³„ ì§„ì²™ë¥  ë§µ (ì„œë²„ì—ì„œ ë°›ì€ ë°ì´í„°)
          var stageProgressMap = {};
          (sum.stages || []).forEach(function(s){ stageProgressMap[String(s.stage_no)] = s.progress; });

          // í†µê³„ ê³„ì‚°
          var totalSubTasks = 0;
          var totalManhours = 0;
          var byCompany = { NAHSHON: 0, AUTORICA: 0, 'AI-KOREA': 0, 'M-SOL': 0 };
          var ehsHigh = 0;
          tree.forEach(function(stage){
            (stage.tasks||[]).forEach(function(task){
              (task.sub_tasks||[]).forEach(function(sub){
                totalSubTasks++;
                totalManhours += parseFloat(sub.manhours)||0;
                if (byCompany[sub.company] !== undefined) byCompany[sub.company] += parseFloat(sub.manhours)||0;
                if (sub.ehs === 'high') ehsHigh++;
              });
            });
          });

          var projOptions = window.WBS_PROJECTS.map(function(p){
            return '<option value="' + p.id + '"' + (p.id === projectId ? ' selected' : '') + '>' + p.id + ' â€” ' + p.name + '</option>';
          }).join('');

          // í˜‘ë ¥ì‚¬ ë§‰ëŒ€
          var companyBars = Object.keys(byCompany).map(function(c){
            var mh = byCompany[c];
            var pct = totalManhours > 0 ? (mh / totalManhours * 100) : 0;
            var color = c === 'NAHSHON' ? '#2563eb' : c === 'AUTORICA' ? '#f59e0b' : c === 'AI-KOREA' ? '#10b981' : '#8b5cf6';
            return '<div style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);margin-bottom:3px"><span>' + c + '</span><span class="cell-mono">' + mh.toLocaleString() + ' MH (' + pct.toFixed(0) + '%)</span></div>' +
              '<div style="height:8px;background:var(--bg-base);border-radius:4px;overflow:hidden"><div style="height:100%;width:' + pct + '%;background:' + color + '"></div></div></div>';
          }).join('');

          // WBS íŠ¸ë¦¬
          var treeHtml;
          if (tree.length === 0) {
            treeHtml = '<div style="text-align:center;padding:48px;color:var(--text-tertiary)">' +
              '<i class="ph ph-tree-structure" style="font-size:48px;color:#7c3aed;margin-bottom:12px"></i>' +
              '<div style="font-size:16px;color:white;margin-bottom:8px">ì•„ì§ WBSê°€ ìƒì„±ë˜ì§€ ì•Šì•˜ìŠµë‹ˆë‹¤</div>' +
              '<div style="font-size:13px">ë§¤ë‰´ì–¼ PDFë¥¼ <strong style="color:#7c3aed">WBS_MANUAL/01_ì²˜ë¦¬ëŒ€ê¸°</strong> í´ë”ì— ì—…ë¡œë“œ í›„<br>ì•„ëž˜ <strong>AI ë©”ë‰´ì–¼ ë¶„ì„</strong> ë²„íŠ¼ì„ í´ë¦­í•˜ì„¸ìš”.</div>' +
              '</div>';
          } else {
            treeHtml = tree.map(function(stage, sIdx){
              var stageMh = 0, subCount = 0, stageCompleted = 0;
              (stage.tasks||[]).forEach(function(t){ (t.sub_tasks||[]).forEach(function(s){
                stageMh += parseFloat(s.manhours)||0;
                subCount++;
                if (s.status === 'ì™„ë£Œ') stageCompleted++;
              }); });
              var stagePct = stageProgressMap[String(stage.stage_no)] || 0;
              var stageColor = stagePct >= 100 ? '#10b981' : stagePct >= 50 ? '#f59e0b' : '#7c3aed';

              var tasksHtml = (stage.tasks||[]).map(function(task, tIdx){
                // Taskë³„ ì§„ì²™ë¥  (sub_tasks í‰ê· )
                var taskCompleted = 0, taskTotal = (task.sub_tasks||[]).length;
                (task.sub_tasks||[]).forEach(function(s){ if (s.status === 'ì™„ë£Œ') taskCompleted++; });
                var taskPct = taskTotal > 0 ? (taskCompleted / taskTotal * 100) : 0;

                var subHtml = (task.sub_tasks||[]).map(function(sub){
                  var status = sub.status || 'AIìƒì„±';
                  var isDone = status === 'ì™„ë£Œ';
                  var isProg = status === 'ì§„í–‰ì¤‘';
                  var ehsBadge = sub.ehs === 'high' ? '<span style="background:#ef4444;color:white;font-size:9px;padding:1px 5px;border-radius:3px;font-weight:700;margin-left:6px">ðŸ”´ ìœ„í—˜</span>' : sub.ehs === 'medium' ? '<span style="background:#f59e0b;color:white;font-size:9px;padding:1px 5px;border-radius:3px;font-weight:700;margin-left:6px">âš ï¸ ì£¼ì˜</span>' : '';
                  var companyColor = sub.company === 'NAHSHON' ? '#2563eb' : sub.company === 'AUTORICA' ? '#f59e0b' : sub.company === 'AI-KOREA' ? '#10b981' : sub.company === 'M-SOL' ? '#8b5cf6' : '#64748b';
                  var rowBg = isDone ? 'rgba(16,185,129,0.08)' : isProg ? 'rgba(245,158,11,0.08)' : 'var(--bg-base)';
                  var rowBorder = isDone ? '#10b981' : isProg ? '#f59e0b' : 'transparent';
                  var nameStyle = isDone ? 'text-decoration:line-through;color:#10b981;opacity:0.85' : 'color:white';
                  var statusIcon = isDone ? '<i class="ph ph-check-circle" style="color:#10b981;font-size:18px"></i>'
                    : isProg ? '<i class="ph ph-spinner" style="color:#f59e0b;font-size:18px"></i>'
                    : '<i class="ph ph-circle" style="color:var(--text-tertiary);font-size:18px"></i>';
                  // ë¹ ë¥¸ í† ê¸€ ë²„íŠ¼ (ì™„ë£Œ â†” AIìƒì„±)
                  var toggleAction = isDone ? 'AIìƒì„±' : 'ì™„ë£Œ';
                  var toggleLabel = isDone ? 'â†» ë¯¸ì™„ë£Œë¡œ' : 'âœ“ ì™„ë£Œ';
                  var toggleBg = isDone ? '#64748b' : '#10b981';

                  return '<div class="wbs-subtask" data-wbsid="' + sub.wbs_id + '" data-status="' + status + '" style="display:grid;grid-template-columns:auto auto 1fr auto auto auto auto;gap:10px;align-items:center;padding:8px 12px;border-radius:6px;background:' + rowBg + ';margin-bottom:4px;border:1px solid ' + rowBorder + ';transition:all 0.15s">' +
                    '<button onclick="event.stopPropagation();window.toggleWbsComplete(\'' + sub.wbs_id + '\',\'' + toggleAction + '\')" style="background:none;border:none;cursor:pointer;padding:2px;display:flex;align-items:center" title="' + toggleLabel + '">' + statusIcon + '</button>' +
                    '<span class="cell-mono" style="font-size:10px;color:var(--text-tertiary);min-width:60px">' + (sub.sub_no || '') + '</span>' +
                    '<span style="font-size:13px;' + nameStyle + ';cursor:pointer" onclick="openWbsEditModal(\'' + sub.wbs_id + '\')">' + (sub.sub_name || '') + ehsBadge + '</span>' +
                    '<span style="font-size:11px;color:' + companyColor + ';font-weight:700;min-width:80px;text-align:right">' + (sub.company || '-') + '</span>' +
                    '<span class="cell-mono" style="font-size:11px;color:var(--text-secondary);min-width:60px;text-align:right">' + (sub.manhours || 0) + 'MH</span>' +
                    '<span class="cell-mono" style="font-size:11px;color:var(--text-secondary);min-width:50px;text-align:right">' + (sub.days || 0) + 'ì¼</span>' +
                    '<button onclick="event.stopPropagation();window.toggleWbsComplete(\'' + sub.wbs_id + '\',\'' + toggleAction + '\')" style="background:' + toggleBg + ';color:white;border:none;border-radius:4px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap">' + toggleLabel + '</button>' +
                    '</div>';
                }).join('');

                return '<div style="margin-bottom:14px"><div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;padding:6px 10px;background:rgba(124,58,237,0.08);border-left:3px solid #7c3aed;border-radius:4px">' +
                  '<span class="cell-mono" style="font-size:11px;color:#7c3aed;font-weight:700">Task ' + (task.task_no||'') + '</span>' +
                  '<span style="font-size:14px;color:white;font-weight:600">' + (task.task_name||'') + '</span>' +
                  '<span style="margin-left:auto;display:flex;align-items:center;gap:10px;font-size:11px;color:var(--text-tertiary)">' +
                  '<span>' + taskCompleted + '/' + taskTotal + ' ì™„ë£Œ</span>' +
                  '<div style="width:80px;height:6px;background:var(--bg-base);border-radius:3px;overflow:hidden"><div style="height:100%;width:' + taskPct + '%;background:#10b981"></div></div>' +
                  '<span class="cell-mono" style="color:white;font-weight:700;min-width:32px;text-align:right">' + taskPct.toFixed(0) + '%</span>' +
                  '</span></div>' + subHtml + '</div>';
              }).join('');

              return '<details ' + (sIdx === 0 ? 'open' : '') + ' style="margin-bottom:18px;border:1px solid var(--border-default);border-radius:10px;overflow:hidden">' +
                '<summary style="padding:14px 18px;background:var(--bg-surface-elevated);cursor:pointer;display:flex;align-items:center;gap:12px;list-style:none">' +
                '<i class="ph ph-caret-right" style="transition:transform 0.2s"></i>' +
                '<span style="background:' + stageColor + ';color:white;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700">STAGE ' + (stage.stage_no || sIdx + 1) + '</span>' +
                '<span style="font-size:16px;font-weight:700;color:white">' + (stage.stage_name || '') + '</span>' +
                '<div style="margin-left:auto;display:flex;align-items:center;gap:14px;font-size:12px;color:var(--text-secondary)">' +
                '<span><i class="ph ph-check-circle" style="color:#10b981"></i> ' + stageCompleted + '/' + subCount + '</span>' +
                '<span><i class="ph ph-clock"></i> ' + stageMh.toLocaleString() + ' MH</span>' +
                '<div style="display:flex;align-items:center;gap:8px"><div style="width:120px;height:8px;background:var(--bg-base);border-radius:4px;overflow:hidden"><div style="height:100%;width:' + stagePct + '%;background:' + stageColor + '"></div></div>' +
                '<span class="cell-mono" style="color:' + stageColor + ';font-weight:700;min-width:44px;text-align:right">' + stagePct.toFixed(1) + '%</span></div>' +
                '</div></summary>' +
                '<div style="padding:14px 18px">' + tasksHtml + '</div></details>';
            }).join('');
          }

          pageContainer.innerHTML =
            '<div class="header-section"><div>' +
            '<h1 class="page-title"><i class="ph ph-tree-structure" style="color:#7c3aed"></i> ê³µì • ê´€ë¦¬ (WBS)</h1>' +
            '<p class="page-subtitle">AI ë©”ë‰´ì–¼ ë¶„ì„ ê¸°ë°˜ ì‹¤ì‹œê°„ ê³µì • ì¶”ì  Â· Stage â†’ Task â†’ SubTask ê³„ì¸µ êµ¬ì¡°</p>' +
            '</div>' +
            '<div class="action-row" style="gap:8px">' +
            '<select id="wbs-project-select" onchange="window.changeWbsProject(this.value)" style="background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px 12px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">' + projOptions + '</select>' +
            '<button class="btn-primary" style="background:linear-gradient(135deg,#7c3aed,#2563eb);border:none" onclick="window.runWbsAiAnalysis()">' +
            '<i class="ph ph-robot"></i> ðŸ¤– AI ë©”ë‰´ì–¼ ë¶„ì„</button>' +
            '<button class="btn-secondary" onclick="window.openWbsManualFolder()"><i class="ph ph-folder-open"></i> ë©”ë‰´ì–¼ í´ë”</button>' +
            '</div></div>' +
            // KPI Row (6ê°œ)
            '<div class="kpi-row" style="grid-template-columns:repeat(6,1fr)">' +
            '<div class="kpi-card"><div class="kpi-label">ì „ì²´ SubTask</div><div class="kpi-value">' + totalSubTasks + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">' + tree.length + ' Stages</span></div></div>' +
            '<div class="kpi-card" style="border-left:3px solid #7c3aed"><div class="kpi-label">ì „ì²´ ì§„ì²™ë¥ </div><div class="kpi-value" style="color:#7c3aed">' + (sum.progress || 0) + '%</div>' +
            '<div style="height:4px;background:var(--bg-base);border-radius:2px;overflow:hidden;margin-top:4px"><div style="height:100%;width:' + (sum.progress||0) + '%;background:linear-gradient(90deg,#7c3aed,#2563eb)"></div></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">âœ… ì™„ë£Œ</div><div class="kpi-value" style="color:#10b981">' + (sum.completedCount || 0) + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">' + (totalSubTasks > 0 ? ((sum.completedCount||0)/totalSubTasks*100).toFixed(0) : 0) + '% of all</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">â³ ì§„í–‰ì¤‘</div><div class="kpi-value" style="color:#f59e0b">' + (sum.inProgressCount || 0) + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">Active tasks</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ì˜ˆìƒ ì´ê³µìˆ˜</div><div class="kpi-value">' + totalManhours.toLocaleString() + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">MH</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">EHS ê³ ìœ„í—˜</div><div class="kpi-value" style="color:#ef4444">' + ehsHigh + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">ìœ„í—˜ìž‘ì—…</span></div></div>' +
            '</div>' +
            // í˜‘ë ¥ì‚¬ ìž‘ì—… ë¶€í•˜ + AI ì•ˆë‚´
            '<div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;margin-bottom:18px">' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-buildings"></i> í˜‘ë ¥ì‚¬ ìž‘ì—… ë¶€í•˜</div></div>' +
            '<div class="panel-body">' + (companyBars || '<div style="color:var(--text-tertiary);text-align:center;padding:20px">WBS ë°ì´í„° ì—†ìŒ</div>') + '</div></div>' +
            '<div style="background:linear-gradient(135deg,rgba(124,58,237,0.15),rgba(37,99,235,0.1));border:1px solid rgba(124,58,237,0.3);border-radius:10px;padding:18px;display:flex;align-items:center;gap:18px">' +
            '<i class="ph ph-robot" style="font-size:42px;color:#7c3aed;flex-shrink:0"></i>' +
            '<div style="flex:1">' +
            '<div style="font-size:14px;font-weight:700;color:#c4b5fd;margin-bottom:6px">ðŸ¤– AI ë©”ë‰´ì–¼ ë¶„ì„ ì‹œìŠ¤í…œ</div>' +
            '<div style="font-size:12px;color:var(--text-secondary);line-height:1.6">ì„¤ì¹˜ ë§¤ë‰´ì–¼/ì‹œë°©ì„œ PDFë¥¼ <strong style="color:white">WBS_MANUAL / 01_ì²˜ë¦¬ëŒ€ê¸°</strong> í´ë”ì— ì—…ë¡œë“œ í›„ <strong style="color:#c4b5fd">AI ë©”ë‰´ì–¼ ë¶„ì„</strong> ë²„íŠ¼ì„ í´ë¦­í•˜ë©´, Gemini 2.5 Proê°€ ìžë™ìœ¼ë¡œ ìž‘ì—…ì„ ìž˜ê²Œ ìª¼ê°œ WBSë¥¼ ìƒì„±í•©ë‹ˆë‹¤. Stage / Task / SubTask 3ë‹¨ê³„ ê³„ì¸µ êµ¬ì¡°ë¡œ í˜‘ë ¥ì‚¬/EHS/ê³µìˆ˜ê¹Œì§€ ìžë™ ë¶„ë¥˜.</div>' +
            '</div></div></div>' +
            // WBS íŠ¸ë¦¬
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-list-checks"></i> WBS êµ¬ì¡° â€” ' + projectId + '</div>' +
            '<div style="font-size:11px;color:var(--text-tertiary)">í´ë¦­í•˜ì—¬ ìƒì„¸ íŽ¸ì§‘</div></div>' +
            '<div class="panel-body">' + treeHtml + '</div></div>';

        } catch (err) {
          renderError('WBS ë°ì´í„° ë¡œë”© ì‹¤íŒ¨: ' + err.message);
          console.error(err);
        }
      }

      window.changeWbsProject = function(projectId) {
        window.WBS_CURRENT_PROJECT = projectId;
        renderWbs();
      };

      window.openWbsManualFolder = function() {
        window.open('https://drive.google.com/drive/folders/1rC8RSb966nL3H_vaqKD-LkDLWsNdfnl3', '_blank');
      };

      // ë¹ ë¥¸ ì™„ë£Œ í† ê¸€ â€” KPI/Stage/Task ì§„ì²™ë¥  ì¦‰ì‹œ ê°±ì‹ 
      window.toggleWbsComplete = async function(wbsId, newStatus) {
        // ìºì‹œ ë¬´íš¨í™” (ì§„ì²™ë¥  ì¦‰ì‹œ ë°˜ì˜)
        if (window.apiCache) {
          Object.keys(window.apiCache).forEach(function(k){
            if (k.indexOf('api_getProjectWbsTree') >= 0 || k.indexOf('api_getProjectProgressSummary') >= 0) {
              delete window.apiCache[k];
            }
          });
        }
        // ë‚™ê´€ì  UI: í´ë¦­ ì¦‰ì‹œ í–‰ ìƒ‰ìƒ ë³€ê²½
        var row = document.querySelector('.wbs-subtask[data-wbsid="' + wbsId + '"]');
        if (row) {
          row.style.opacity = '0.5';
          row.style.pointerEvents = 'none';
        }
        try {
          var res = await window.API.markWbsStatus(wbsId, newStatus);
          if (res && res.success) {
            // ì „ì²´ ë¦¬ë Œë” (KPI + Stage ì§„ì²™ë¥  ëª¨ë‘ ê°±ì‹ )
            renderWbs();
          } else {
            alert('ìƒíƒœ ë³€ê²½ ì‹¤íŒ¨: ' + (res && res.error ? res.error : 'unknown'));
            if (row) { row.style.opacity = ''; row.style.pointerEvents = ''; }
          }
        } catch(e) {
          alert('ì˜¤ë¥˜: ' + e.message);
          if (row) { row.style.opacity = ''; row.style.pointerEvents = ''; }
        }
      };

      window.runWbsAiAnalysis = async function() {
        var projectId = window.WBS_CURRENT_PROJECT || 'HFF-02';
        var ok = confirm('ðŸ¤– AI ë©”ë‰´ì–¼ ë¶„ì„ì„ ì‹¤í–‰í•˜ì‹œê² ìŠµë‹ˆê¹Œ?\n\ní˜„ìž¥: ' + projectId + '\ní´ë”: WBS_MANUAL / 01_ì²˜ë¦¬ëŒ€ê¸°\n\nGemini 2.5 Proê°€ í´ë” ë‚´ ëª¨ë“  ë§¤ë‰´ì–¼ì„ ë¶„ì„í•˜ì—¬\nWBSë¥¼ ìžë™ ìƒì„±í•©ë‹ˆë‹¤. (ìˆ˜ ë¶„ ì†Œìš” ê°€ëŠ¥)');
        if (!ok) return;

        var overlay = document.createElement('div');
        overlay.id = 'wbs-ai-overlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px';
        overlay.innerHTML =
          '<div style="width:80px;height:80px;border:5px solid rgba(124,58,237,0.3);border-top-color:#7c3aed;border-radius:50%;animation:spin 1s linear infinite"></div>' +
          '<div style="color:white;font-size:18px;font-weight:700">ðŸ¤– Gemini 2.5 Pro ë¶„ì„ ì¤‘...</div>' +
          '<div style="color:rgba(255,255,255,0.7);font-size:13px">WBS_MANUAL / 01_ì²˜ë¦¬ëŒ€ê¸° í´ë” ìŠ¤ìº” â†’ AI ë¶„ì„ â†’ WBS ìƒì„±</div>' +
          '<div style="color:rgba(255,255,255,0.5);font-size:11px">ëŒ€ìš©ëŸ‰ PDFì˜ ê²½ìš° 2~5ë¶„ ì†Œìš”ë©ë‹ˆë‹¤. íŽ˜ì´ì§€ ë‹«ì§€ ë§ˆì„¸ìš”.</div>';
        document.body.appendChild(overlay);

        try {
          var result = await window.API.processWbsManual(projectId);
          overlay.remove();

          var modal = document.createElement('div');
          modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:9999;display:flex;align-items:center;justify-content:center';
          var icon = result.success ? (result.processed === 0 ? 'ðŸ“‚' : 'âœ…') : 'âŒ';
          var detailRows = (result.results || []).map(function(r) {
            var sIcon = r.status === 'success' ? 'âœ…' : 'âŒ';
            var detail = r.status === 'success'
              ? '<span style="color:var(--status-success)">' + r.stages + ' Stages Â· ' + r.tasks + ' Tasks Â· ' + r.subTasks + ' SubTasks</span>'
              : '<span style="color:var(--status-danger)">' + (r.error || '') + '</span>';
            return '<div style="padding:10px 0;border-bottom:1px solid var(--border-subtle);font-size:12px">' +
              sIcon + ' <strong>' + r.file + '</strong><br>' + detail + '</div>';
          }).join('');

          modal.innerHTML =
            '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:28px;width:560px;max-height:80vh;overflow-y:auto">' +
            '<div style="font-size:42px;text-align:center;margin-bottom:12px">' + icon + '</div>' +
            '<h2 style="text-align:center;font-size:18px;margin-bottom:12px">AI ë©”ë‰´ì–¼ ë¶„ì„ ê²°ê³¼</h2>' +
            (result.processed === 0 && result.success
              ? '<div style="text-align:center;color:var(--text-secondary);padding:20px">ì²˜ë¦¬í•  íŒŒì¼ì´ ì—†ìŠµë‹ˆë‹¤.<br><span style="font-size:11px;color:var(--text-tertiary)">01_ì²˜ë¦¬ëŒ€ê¸° í´ë”ì— ë§¤ë‰´ì–¼ì„ ì—…ë¡œë“œí•˜ì„¸ìš”.</span></div>'
              : !result.success
                ? '<div style="text-align:center;color:var(--status-danger);padding:20px">' + (result.error || 'ì•Œ ìˆ˜ ì—†ëŠ” ì˜¤ë¥˜') + '</div>'
                : '<div style="max-height:320px;overflow-y:auto;margin-bottom:18px">' + detailRows + '</div>') +
            '<button id="wbs-result-close" style="width:100%;background:#7c3aed;color:white;border:none;border-radius:8px;padding:12px;font-size:14px;font-weight:700;cursor:pointer">í™•ì¸ í›„ ìƒˆë¡œê³ ì¹¨</button>' +
            '</div>';
          document.body.appendChild(modal);
          modal.querySelector('#wbs-result-close').addEventListener('click', function() {
            modal.remove();
            renderWbs();
          });
        } catch(err) {
          if (document.getElementById('wbs-ai-overlay')) document.getElementById('wbs-ai-overlay').remove();
          alert('AI ë¶„ì„ ì¤‘ ì˜¤ë¥˜:\n' + err.message);
        }
      };

      window.openWbsEditModal = function(wbsId) {
        var modal = document.createElement('div');
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center';
        modal.innerHTML =
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:14px;padding:24px;width:480px">' +
          '<h3 style="margin:0 0 14px 0;display:flex;align-items:center;gap:8px"><i class="ph ph-pencil-simple" style="color:#7c3aed"></i> WBS íŽ¸ì§‘</h3>' +
          '<div style="font-size:12px;color:var(--text-tertiary);margin-bottom:14px;font-family:monospace">' + wbsId + '</div>' +
          '<div style="display:grid;gap:12px">' +
          '<div><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">ìƒíƒœ</label>' +
          '<select id="wbs-edit-status" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px;border-radius:6px">' +
          '<option value="AIìƒì„±">ðŸ“ AIìƒì„± (ëŒ€ê¸°)</option><option value="ê²€ìˆ˜ì™„ë£Œ">âœ… ê²€ìˆ˜ì™„ë£Œ</option><option value="ì§„í–‰ì¤‘">â³ ì§„í–‰ì¤‘</option><option value="ì™„ë£Œ">ðŸŽ¯ ì™„ë£Œ</option><option value="ë³´ë¥˜">â¸ï¸ ë³´ë¥˜</option></select></div>' +
          '<div><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">ë‹´ë‹¹ì‚¬</label>' +
          '<select id="wbs-edit-company" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px;border-radius:6px">' +
          '<option value="NAHSHON">NAHSHON</option><option value="AUTORICA">AUTORICA</option><option value="AI-KOREA">AI-KOREA</option><option value="M-SOL">M-SOL</option></select></div>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">' +
          '<div><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">ì‹œìž‘ì˜ˆì •</label>' +
          '<input type="date" id="wbs-edit-start" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px;border-radius:6px"></div>' +
          '<div><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">ì¢…ë£Œì˜ˆì •</label>' +
          '<input type="date" id="wbs-edit-end" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px;border-radius:6px"></div>' +
          '</div></div>' +
          '<div style="display:flex;gap:10px;margin-top:18px">' +
          '<button id="wbs-edit-cancel" class="btn-secondary" style="flex:1">ì·¨ì†Œ</button>' +
          '<button id="wbs-edit-save" class="btn-primary" style="flex:1;background:#7c3aed">ì €ìž¥</button>' +
          '</div></div>';
        document.body.appendChild(modal);

        modal.querySelector('#wbs-edit-cancel').addEventListener('click', function() { modal.remove(); });
        modal.querySelector('#wbs-edit-save').addEventListener('click', async function() {
          var updates = {
            'ìƒíƒœ': document.getElementById('wbs-edit-status').value,
            'ë‹´ë‹¹ì‚¬': document.getElementById('wbs-edit-company').value,
            'ì‹œìž‘ì˜ˆì •': document.getElementById('wbs-edit-start').value,
            'ì¢…ë£Œì˜ˆì •': document.getElementById('wbs-edit-end').value
          };
          try {
            var res = await window.API.updateWbsRow(wbsId, updates);
            if (res.success) {
              modal.remove();
              renderWbs();
            } else {
              alert('ì €ìž¥ ì‹¤íŒ¨: ' + (res.error || 'unknown'));
            }
          } catch(e) {
            alert('ì €ìž¥ ì˜¤ë¥˜: ' + e.message);
          }
        });
        modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
      };

      // â”€â”€ VEHICLE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      

      async function renderRental() {
        pageContainer.innerHTML = skeleton();
        try {
          const [stats, rentals] = await Promise.all([
            window.API.getRentalStats(),
            window.API.getRentalList()
          ]);

          window.currentRentals = rentals; // Cache globally for detail modal lookup

          var rowsHtml = rentals.map(function(r) {
            var actionBtn = '';
            if (r.status === '대기중') {
              actionBtn = '<button class="btn-primary" style="background:var(--status-success);border:none;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;" onclick="event.stopPropagation(); window.openAssignEquipmentModal(' + r.realId + ', \'' + r.model + '\')">배정</button>';
            } else if (r.status === '사용중') {
              actionBtn = '<button class="btn-secondary" style="background:var(--status-danger);border:none;color:#fff;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;" onclick="event.stopPropagation(); window.openReturnEquipmentModal(' + r.realId + ', \'' + r.model + '\')">반납</button>';
            } else {
              actionBtn = '<span style="color:var(--text-tertiary)">-</span>';
            }

            var operator = r.operator || '<span style="color:var(--text-tertiary)">미배정</span>';

            return '<tr style="cursor:pointer;" onclick="window.openEquipmentDetailModal(' + r.realId + ')">'
              + '<td class="cell-mono">' + r.id + '</td>'
              + '<td class="cell-primary">' + r.equipType + '</td>'
              + '<td>' + r.model + '</td>'
              + '<td>' + r.vendor + '</td>'
              + '<td class="cell-mono">' + r.startDate + ' ~ ' + r.endDate + '</td>'
              + '<td>' + (r.company || '-') + '</td>'
              + '<td>' + (r.team || '-') + '</td>'
              + '<td>' + operator + '</td>'
              + '<td>' + statusPill(r.status) + '</td>'
              + '<td>' + actionBtn + '</td>'
              + '</tr>';
          }).join('');

          var byCompanyHtml = '';
          if (!stats.byCompany || stats.byCompany.length === 0) {
            byCompanyHtml = '<div style="color:var(--text-tertiary); text-align:center; padding:12px; font-size:12px;">사용중인 계약회사가 없습니다.</div>';
          } else {
            byCompanyHtml = stats.byCompany.map(function(c) {
              var pct = stats.active > 0 ? (c.count / stats.active * 100) : 0;
              return '<div style="margin-bottom:12px;">' +
                     '  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">' +
                     '    <span style="font-size:12px; font-weight:600; color:var(--text-primary);">' + c.name + '</span>' +
                     '    <span style="font-size:12px; font-weight:700; color:#7c3aed;">' + c.count + '대 사용중</span>' +
                     '  </div>' +
                     '  <div style="height:6px; background:var(--bg-base); border-radius:3px; overflow:hidden;">' +
                     '    <div style="width:' + pct + '%; height:100%; background:linear-gradient(90deg, #7c3aed, #2563eb); border-radius:3px;"></div>' +
                     '  </div>' +
                     '</div>';
            }).join('');
          }

          pageContainer.innerHTML =
            '<div class="header-section"><div><h1 class="page-title">장비 렌탈 관리</h1><p class="page-subtitle">중장비 단기 렌탈 현황 · 반납일/비용 추적</p></div>' +
            '<div class="action-row">' +
            '<button class="btn-secondary" onclick="window.downloadRentalExcel()"><i class="ph ph-file-xls"></i> 엑셀 다운로드</button>' +
            '<button class="btn-primary" style="background:linear-gradient(135deg,#7c3aed,#2563eb);border:none" onclick="window.openAiEquipmentRegModal()"><i class="ph ph-robot"></i> 🤖 AI 계약서 등록</button>' +
            '</div></div>' +
            
            '<div style="display:grid; grid-template-columns: 7.2fr 2.8fr; gap:20px; align-items:start;">' +
            '  <div style="display:flex; flex-direction:column; gap:20px;">' +
            '    <div class="kpi-row" style="grid-template-columns:repeat(3,1fr); gap:12px;">' +
            '      <div class="kpi-card"><div class="kpi-label">전체 장비</div><div class="kpi-value">' + stats.total + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">등록 장비</span></div></div>' +
            '      <div class="kpi-card"><div class="kpi-label">사용중</div><div class="kpi-value" style="color:var(--status-success)">' + stats.active + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">현장 가동</span></div></div>' +
            '      <div class="kpi-card"><div class="kpi-label">대기중</div><div class="kpi-value" style="color:var(--status-warning)">' + stats.available + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">배정 대기</span></div></div>' +
            '    </div>' +
            '    <div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-bulldozer"></i> 렌탈 목록</div></div>' +
            '    <div class="panel-body" style="overflow-x:auto;"><table class="data-table"><thead><tr>' +
            '    <th>렌탈ID</th><th>장비종류</th><th>모델</th><th>벤더</th><th>기간</th>' +
            '    <th>배정회사</th><th>배정팀</th><th>운영자</th><th>상태</th><th>액션</th>' +
            '    </tr></thead><tbody>' + (rowsHtml || '<tr><td colspan="10" style="text-align:center;color:var(--text-tertiary);padding:32px">등록된 렌탈 없음</td></tr>') + '</tbody></table></div></div>' +
            '  </div>' +
            '  <div class="panel" style="position:sticky; top:20px;">' +
            '    <div class="panel-header"><div class="panel-title"><i class="ph ph-buildings"></i> 계약회사별 사용중인 장비 현황</div></div>' +
            '    <div class="panel-body padded" style="display:flex; flex-direction:column; gap:16px;">' +
                   byCompanyHtml +
            '    </div>' +
            '  </div>' +
            '</div>';

        } catch (err) { renderError('렌탈 데이터 로딩 실패'); console.error(err); }
      }

      // 신규 렌탈 등록 모달
      window.openRentalCreateModal = function() {
        var modal = document.createElement('div');
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center';
        modal.innerHTML =
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:28px;width:480px;max-height:90vh;overflow-y:auto">' +
          '<h2 style="margin-bottom:16px;font-size:18px">🚧 신규 장비 렌탈 등록</h2>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
          '<label style="grid-column:span 2;font-size:12px">장비종류<select id="r-type" class="form-input" style="width:100%;margin-top:4px"><option>Excavator</option><option>Forklift</option><option>Boom Lift</option><option>Skid Steer</option><option>Generator</option><option>Compressor</option><option>Crane</option><option>Other</option></select></label>' +
          '<label style="font-size:12px">모델<input id="r-model" class="form-input" placeholder="CAT 320GC" style="width:100%;margin-top:4px"></label>' +
          '<label style="font-size:12px">벤더<input id="r-vendor" class="form-input" placeholder="United Rentals" style="width:100%;margin-top:4px"></label>' +
          '<label style="font-size:12px">시작일<input id="r-start" type="date" class="form-input" style="width:100%;margin-top:4px"></label>' +
          '<label style="font-size:12px">반납예정일<input id="r-end" type="date" class="form-input" style="width:100%;margin-top:4px"></label>' +
          '<label style="font-size:12px">일단가 ($)<input id="r-rate" type="number" class="form-input" placeholder="850" style="width:100%;margin-top:4px"></label>' +
          '<label style="font-size:12px">배송비 ($)<input id="r-delivery" type="number" class="form-input" placeholder="450" style="width:100%;margin-top:4px"></label>' +
          '<label style="grid-column:span 2;font-size:12px">운영자<input id="r-operator" class="form-input" style="width:100%;margin-top:4px"></label>' +
          '<label style="grid-column:span 2;font-size:12px">작업내용<input id="r-task" class="form-input" placeholder="기초공사 굴착" style="width:100%;margin-top:4px"></label>' +
          '<label style="grid-column:span 2;font-size:12px">비고<input id="r-notes" class="form-input" style="width:100%;margin-top:4px"></label>' +
          '</div>' +
          '<div style="display:flex;gap:8px;margin-top:20px">' +
          '<button onclick="this.closest(\'div[style]\').parentElement.remove()" class="btn-secondary" style="flex:1">취소</button>' +
          '<button onclick="window.submitRental()" class="btn-primary" style="flex:1">등록</button>' +
          '</div></div>';
        document.body.appendChild(modal);
      };

      window.submitRental = async function() {
        var payload = {
          siteId: window.currentSiteId || 'HFF-02',
          equipType: document.getElementById('r-type').value,
          model: document.getElementById('r-model').value,
          vendor: document.getElementById('r-vendor').value,
          startDate: document.getElementById('r-start').value,
          endDate: document.getElementById('r-end').value,
          dailyRate: parseFloat(document.getElementById('r-rate').value) || 0,
          deliveryFee: parseFloat(document.getElementById('r-delivery').value) || 0,
          operator: document.getElementById('r-operator').value,
          task: document.getElementById('r-task').value,
          notes: document.getElementById('r-notes').value
        };
        if (!payload.model || !payload.vendor || !payload.startDate || !payload.endDate) {
          alert('모델·벤더·시작일·반납예정일은 필수입니다.');
          return;
        }
        var res = await window.API.createRental(payload);
        if (res.success) {
          alert('등록 완료: ' + res.id);
          document.querySelectorAll('div[style*="z-index:9999"]').forEach(function(el){ el.remove(); });
          window.loadView('rental');
        } else {
          alert('등록 실패: ' + (res.error || '알 수 없는 오류'));
        }
      };

      // Manual Return Modal
      window.openReturnEquipmentModal = function(equipmentId, modelName) {
        var modal = document.createElement('div');
        modal.id = 'equipment-return-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;';
        
        modal.innerHTML =
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:24px;width:400px;max-width:100%;box-shadow:0 10px 25px rgba(0,0,0,0.5);">' +
            '<h3 style="font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:16px;">장비 반납: ' + modelName + '</h3>' +
            '<form id="equipment-return-form" style="display:flex;flex-direction:column;gap:16px;">' +
              '<div>' +
                '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">반납 메모</label>' +
                '<textarea name="notes" placeholder="장비 반납 상태 또는 특이사항 입력" style="width:100%;height:80px;background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:10px;color:var(--text-primary);resize:none;"></textarea>' +
              '</div>' +
              '<div style="display:flex;justify-content:flex-end;gap:12px;margin-top:8px;">' +
                '<button type="button" onclick="document.getElementById(\'equipment-return-modal\').remove()" class="btn-secondary" style="padding:8px 16px;">취소</button>' +
                '<button type="submit" class="btn-primary" style="background:var(--status-danger);border:none;padding:8px 20px;font-weight:700;cursor:pointer;">반납 완료</button>' +
              '</div>' +
            '</form>' +
          '</div>';

        document.body.appendChild(modal);

        modal.querySelector('#equipment-return-form').onsubmit = async function(e) {
          e.preventDefault();
          var payload = {
            equipment_id: equipmentId,
            notes: modal.querySelector('[name="notes"]').value
          };

          try {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var response = await fetch('/equipment-api/return', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
              },
              body: JSON.stringify(payload)
            });

            if (!response.ok) {
              var errData = await response.json();
              throw new Error(errData.error || '반납 처리 중 오류가 발생했습니다.');
            }

            modal.remove();
            var detailModal = document.getElementById('equipment-detail-modal');
            if (detailModal) detailModal.remove();
            
            showToast('장비가 반납되었습니다.');
            window.renderRental();
          } catch (err) {
            alert('오류: ' + err.message);
          }
        };
      };

      // Manual Assignment Modal
      window.openAssignEquipmentModal = async function(equipmentId, modelName) {
        var modal = document.createElement('div');
        modal.id = 'equipment-assign-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;';
        
        modal.innerHTML =
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:24px;width:450px;max-width:100%;box-shadow:0 10px 25px rgba(0,0,0,0.5);">' +
            '<h3 style="font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:16px;">장비 배정: ' + modelName + '</h3>' +
            '<form id="equipment-assign-form" style="display:flex;flex-direction:column;gap:16px;">' +
              '<div>' +
                '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">배정 회사 <span style="color:var(--status-danger)">*</span></label>' +
                '<select name="company_id" required style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:10px;color:var(--text-primary);">' +
                  '<option value="">불러오는 중...</option>' +
                '</select>' +
              '</div>' +
              '<div>' +
                '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">배정 팀 (선택)</label>' +
                '<select name="team_id" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:10px;color:var(--text-primary);">' +
                  '<option value="">회사 먼저 선택하세요</option>' +
                '</select>' +
              '</div>' +
              '<div>' +
                '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">운영자 / 담당 직원 (선택)</label>' +
                '<select name="employee_id" style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:10px;color:var(--text-primary);">' +
                  '<option value="">회사 먼저 선택하세요</option>' +
                '</select>' +
              '</div>' +
              '<div>' +
                '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">메모</label>' +
                '<textarea name="notes" placeholder="배정 메모를 입력하세요" style="width:100%;height:80px;background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:10px;color:var(--text-primary);resize:none;"></textarea>' +
              '</div>' +
              '<div style="display:flex;justify-content:flex-end;gap:12px;margin-top:8px;">' +
                '<button type="button" onclick="document.getElementById(\'equipment-assign-modal\').remove()" class="btn-secondary" style="padding:8px 16px;">취소</button>' +
                '<button type="submit" class="btn-primary" style="background:var(--status-success);border:none;padding:8px 20px;font-weight:700;cursor:pointer;">배정 완료</button>' +
              '</div>' +
            '</form>' +
          '</div>';

        document.body.appendChild(modal);

        var companySelect = modal.querySelector('[name="company_id"]');
        var teamSelect = modal.querySelector('[name="team_id"]');
        var employeeSelect = modal.querySelector('[name="employee_id"]');

        var companyList = [];
        var teamList = [];
        var employeeList = [];

        try {
          const [companies, teams, employees] = await Promise.all([
            window.API.getCompanyList(),
            window.API.getTeamList(),
            window.API.getEmployeeList()
          ]);
          companyList = companies;
          teamList = teams;
          employeeList = employees;

          companySelect.innerHTML = '<option value="">-- 회사 선택 --</option>' + companyList.map(function(c) {
            return '<option value="' + c.id + '">' + c.name + '</option>';
          }).join('');
        } catch (err) {
          companySelect.innerHTML = '<option value="">회사 목록 불러오기 실패</option>';
        }

        companySelect.onchange = function() {
          var cid = companySelect.value;
          if (!cid) {
            teamSelect.innerHTML = '<option value="">회사 먼저 선택하세요</option>';
            employeeSelect.innerHTML = '<option value="">회사 먼저 선택하세요</option>';
            return;
          }

          teamSelect.innerHTML = '<option value="">-- 배정 팀 선택 --</option>' + teamList.map(function(t) {
            return '<option value="' + t.id + '">' + t.name + '</option>';
          }).join('');

          var filteredEmployees = employeeList.filter(function(e) {
            return e.company_id == cid;
          });

          if (filteredEmployees.length === 0) {
            employeeSelect.innerHTML = '<option value="">해당 회사의 직원이 없습니다.</option>';
          } else {
            employeeSelect.innerHTML = '<option value="">-- 운영자 선택 --</option>' + filteredEmployees.map(function(e) {
              return '<option value="' + e.id + '">' + e.name + '</option>';
            }).join('');
          }
        };

        modal.querySelector('#equipment-assign-form').onsubmit = async function(e) {
          e.preventDefault();
          var payload = {
            equipment_id: equipmentId,
            company_id: companySelect.value,
            team_id: teamSelect.value || null,
            employee_id: employeeSelect.value || null,
            notes: modal.querySelector('[name="notes"]').value
          };

          try {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var response = await fetch('/equipment-api/assign', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
              },
              body: JSON.stringify(payload)
            });

            if (!response.ok) {
              var errData = await response.json();
              throw new Error(errData.error || '배정 처리 중 오류가 발생했습니다.');
            }

            modal.remove();
            var detailModal = document.getElementById('equipment-detail-modal');
            if (detailModal) detailModal.remove();
            
            showToast('장비가 배정되었습니다.');
            window.renderRental();
          } catch (err) {
            alert('오류: ' + err.message);
          }
        };
      };

      // Detailed Equipment Info & Chronological Rental History modal
      window.openEquipmentDetailModal = async function(equipmentId) {
        var r = (window.currentRentals || []).find(x => x.realId === equipmentId);
        if (!r) return;

        function getSecureFileUrl(path) {
          if (!path) return '';
          if (path.indexOf('/storage/') === 0) {
            return '/equipment-api/file?path=' + encodeURIComponent(path.replace('/storage/', ''));
          }
          return path;
        }

        var modal = document.createElement('div');
        modal.id = 'equipment-detail-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;';
        
        var photosHtml = '';
        var directions = [
          { path: r.photo_front, label: '전면 사진' },
          { path: r.photo_rear, label: '후면 사진' },
          { path: r.photo_left, label: '좌측 사진' },
          { path: r.photo_right, label: '우측 사진' }
        ];
        directions.forEach(function(d) {
          if (d.path) {
            var secureUrl = getSecureFileUrl(d.path);
            photosHtml += '<div style="background:var(--bg-base);border:1px solid var(--border-default);border-radius:8px;padding:6px;text-align:center;">' +
              '<a href="' + secureUrl + '" target="_blank">' +
                '<img src="' + secureUrl + '" style="width:100%;height:100px;object-fit:cover;border-radius:6px;margin-bottom:6px;border:1px solid var(--border-subtle);">' +
              '</a>' +
              '<span style="font-size:11px;font-weight:600;color:var(--text-secondary);">' + d.label + '</span>' +
            '</div>';
          }
        });

        if (photosHtml) {
          photosHtml = '<div style="margin-top:16px;">' +
            '<h4 style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:8px;">장비 사진 (4방향)</h4>' +
            '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">' + photosHtml + '</div>' +
          '</div>';
        }

        var contractHtml = r.contract_path 
          ? '<div style="margin-top:12px;"><a href="' + getSecureFileUrl(r.contract_path) + '" target="_blank" class="btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;font-size:12px;"><i class="ph ph-file-pdf"></i> 렌트 계약서 파일 보기</a></div>'
          : '';

        var actionButton = '';
        if (r.status === '사용중') {
          actionButton = '<button onclick="window.openReturnEquipmentModal(' + r.realId + ', \'' + r.model + '\')" class="btn-primary" style="background:var(--status-danger);border:none;padding:10px 20px;font-weight:700;cursor:pointer;">장비 반납 처리</button>';
        } else {
          actionButton = '<button onclick="window.openAssignEquipmentModal(' + r.realId + ', \'' + r.model + '\')" class="btn-primary" style="background:var(--status-success);border:none;padding:10px 20px;font-weight:700;cursor:pointer;">장비 배정</button>';
        }

        // Calculate lease duration cost
        var leaseDays = 0;
        var totalLeaseCost = 0;
        if (r.startDate && r.endDate && r.startDate !== '-' && r.endDate !== '-') {
          var startD = new Date(r.startDate);
          var endD = new Date(r.endDate);
          var diffTime = Math.max(0, endD - startD);
          leaseDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
          totalLeaseCost = (r.dailyRate || 0) * leaseDays + (r.deliveryFee || 0);
        }

        modal.innerHTML =
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:28px;width:700px;max-width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 10px 25px rgba(0,0,0,0.5);">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border-bottom:1px solid var(--border-subtle);padding-bottom:12px;">' +
              '<h2 style="font-size:20px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px;margin:0;"><i class="ph ph-bulldozer" style="color:var(--brand-primary);"></i> 장비 상세 정보</h2>' +
              '<button onclick="this.closest(\'#equipment-detail-modal\').remove()" style="background:none;border:none;color:var(--text-secondary);font-size:20px;cursor:pointer;padding:0;"><i class="ph ph-x"></i></button>' +
            '</div>' +
            
            // Detail View Container
            '<div id="eq-detail-view" style="display:flex;flex-direction:column;gap:16px;">' +
              '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;background:var(--bg-base);border-radius:10px;padding:16px;border:1px solid var(--border-subtle);">' +
                '<div>' +
                  '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">장비 코드 / 종류</span><strong style="font-size:15px;color:var(--text-primary);">' + r.id + ' / ' + r.equipType + '</strong></div>' +
                  '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">모델명 / 렌트사 (Vendor)</span><strong style="font-size:15px;color:var(--text-primary);">' + r.model + ' / ' + r.vendor + '</strong></div>' +
                  '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">일단가 / 배송비</span><strong style="font-size:15px;color:var(--text-primary);">$' + (r.dailyRate || 0).toLocaleString() + ' / $' + (r.deliveryFee || 0).toLocaleString() + '</strong></div>' +
                  '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">계약 총 비용</span><strong style="font-size:15px;color:#7c3aed;">$' + totalLeaseCost.toLocaleString() + ' (' + leaseDays + '일 기준)</strong></div>' +
                '</div>' +
                '<div>' +
                  '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">현재 배정회사 / 팀</span><strong style="font-size:15px;color:var(--text-primary);">' + (r.company || '-') + ' / ' + (r.team || '-') + '</strong></div>' +
                  '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">현재 운영자 / 담당자</span><strong style="font-size:15px;color:var(--text-primary);">' + (r.operator || '미배정') + '</strong></div>' +
                  '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">대여 기간</span><strong style="font-size:15px;color:var(--text-primary);">' + r.startDate + ' ~ ' + r.endDate + '</strong></div>' +
                  '<div style="margin-bottom:10px;"><span style="font-size:12px;color:var(--text-tertiary);display:block;">장비 상태</span><div>' + statusPill(r.status) + '</div></div>' +
                '</div>' +
              '</div>' +
              photosHtml +
              contractHtml +
              '<div style="margin-top:20px;display:flex;justify-content:space-between;align-items:center;padding-top:16px;border-top:1px solid var(--border-subtle);">' +
                '<div style="display:flex;gap:10px;">' + 
                  actionButton + 
                  '<button type="button" class="btn-secondary btn-eq-edit-trigger" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;font-weight:700;cursor:pointer;"><i class="ph ph-pencil"></i> 수정</button>' +
                  '<button type="button" class="btn-secondary btn-eq-delete-trigger" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;font-weight:700;color:var(--status-danger);border-color:rgba(239,68,68,0.2);cursor:pointer;"><i class="ph ph-trash"></i> 삭제</button>' +
                '</div>' +
                '<button type="button" onclick="this.closest(\'#equipment-detail-modal\').remove()" class="btn-secondary" style="padding:10px 20px;">닫기</button>' +
              '</div>' +
              '<div style="margin-top:28px;">' +
                '<h3 style="font-size:16px;font-weight:700;color:var(--text-primary);margin-bottom:12px;display:flex;align-items:center;gap:6px;"><i class="ph ph-clock-counter-clockwise"></i> 대여 및 배정 이력 (Rental History)</h3>' +
                '<div id="equipment-history-timeline" style="max-height:220px;overflow-y:auto;border:1px solid var(--border-subtle);border-radius:8px;background:var(--bg-base);padding:14px;">' +
                  '<div style="color:var(--text-tertiary);text-align:center;padding:12px;">이력을 불러오는 중...</div>' +
                '</div>' +
              '</div>' +
            '</div>' +
            
            // Edit View Container
            '<div id="eq-edit-view" style="display:none;">' +
              '<form id="eq-edit-form" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:16px;">' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">' +
                  '<div>' +
                    '<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px;">장비 종류 (Type) *</label>' +
                    '<select name="equipment_type" style="width:100%;padding:8px;border-radius:6px;background:var(--bg-base);border:1px solid var(--border-subtle);color:var(--text-primary);height:38px;" required>' +
                      '<option value="Power Tool (전동공구)">Power Tool (전동공구)</option>' +
                      '<option value="Hand Tool (수공구)">Hand Tool (수공구)</option>' +
                      '<option value="Pipes & Fittings (배관 자재)">Pipes & Fittings (배관 자재)</option>' +
                      '<option value="Conduit & Electrical (전선관/전기 자재)">Conduit & Electrical (전선관/전기 자재)</option>' +
                      '<option value="Wires & Cables (전선/케이블)">Wires & Cables (전선/케이블)</option>' +
                      '<option value="Valves & Controls (밸브/계측기)">Valves & Controls (밸브/계측기)</option>' +
                      '<option value="Fasteners & Anchors (체결류/피스)">Fasteners & Anchors (체결류/피스)</option>' +
                      '<option value="Generator & Power (발전기/동력원)">Generator & Power (발전기/동력원)</option>' +
                      '<option value="Welding Machine (용접기)">Welding Machine (용접기)</option>' +
                      '<option value="Heavy Equipment (중장비)">Heavy Equipment (중장비)</option>' +
                      '<option value="Safety & PPE (안전 용품)">Safety & PPE (안전 용품)</option>' +
                      '<option value="Other Materials (기타 자재/공구)">Other Materials (기타 자재/공구)</option>' +
                    '</select>' +
                  '</div>' +
                  '<div>' +
                    '<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px;">모델명 (Model) *</label>' +
                    '<input type="text" name="model" style="width:100%;padding:8px;border-radius:6px;background:var(--bg-base);border:1px solid var(--border-subtle);color:var(--text-primary);height:38px;" required>' +
                  '</div>' +
                '</div>' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">' +
                  '<div>' +
                    '<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px;">렌트사 / 제조사 (Vendor)</label>' +
                    '<input type="text" name="vendor" style="width:100%;padding:8px;border-radius:6px;background:var(--bg-base);border:1px solid var(--border-subtle);color:var(--text-primary);height:38px;">' +
                  '</div>' +
                  '<div>' +
                    '<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px;">장비 상태 (Status) *</label>' +
                    '<select name="status" style="width:100%;padding:8px;border-radius:6px;background:var(--bg-base);border:1px solid var(--border-subtle);color:var(--text-primary);height:38px;" required>' +
                      '<option value="대기중">대기중</option>' +
                      '<option value="사용중">사용중</option>' +
                      '<option value="정비중">정비중</option>' +
                    '</select>' +
                  '</div>' +
                '</div>' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">' +
                  '<div>' +
                    '<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px;">일단가 (Daily Rate)</label>' +
                    '<input type="number" name="daily_rate" style="width:100%;padding:8px;border-radius:6px;background:var(--bg-base);border:1px solid var(--border-subtle);color:var(--text-primary);height:38px;" min="0">' +
                  '</div>' +
                  '<div>' +
                    '<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px;">배송비 (Delivery Fee)</label>' +
                    '<input type="number" name="delivery_fee" style="width:100%;padding:8px;border-radius:6px;background:var(--bg-base);border:1px solid var(--border-subtle);color:var(--text-primary);height:38px;" min="0">' +
                  '</div>' +
                '</div>' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">' +
                  '<div>' +
                    '<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px;">대여 시작일 (Start Date)</label>' +
                    '<input type="date" name="rent_start" style="width:100%;padding:8px;border-radius:6px;background:var(--bg-base);border:1px solid var(--border-subtle);color:var(--text-primary);height:38px;">' +
                  '</div>' +
                  '<div>' +
                    '<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px;">대여 종료일 (End Date)</label>' +
                    '<input type="date" name="rent_end" style="width:100%;padding:8px;border-radius:6px;background:var(--bg-base);border:1px solid var(--border-subtle);color:var(--text-primary);height:38px;">' +
                  '</div>' +
                '</div>' +
                
                '<div>' +
                  '<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:6px;">장비 사진 변경 (4방향)</label>' +
                  '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">' +
                    '<div class="photo-edit-box" style="position:relative;background:var(--bg-base);border:1px dashed var(--border-strong);border-radius:8px;height:80px;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;" onclick="document.getElementById(\'eq-edit-photo-front\').click()">' +
                      '<img id="eq-edit-preview-front" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:8px;display:none;">' +
                      '<span style="font-size:10px;color:var(--text-tertiary);text-align:center;"><i class="ph ph-camera" style="font-size:18px;"></i><br>전면</span>' +
                      '<input type="file" id="eq-edit-photo-front" name="photo_front_file" accept="image/*" style="display:none;" onchange="window.previewFile(this, \'eq-edit-preview-front\')">' +
                    '</div>' +
                    '<div class="photo-edit-box" style="position:relative;background:var(--bg-base);border:1px dashed var(--border-strong);border-radius:8px;height:80px;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;" onclick="document.getElementById(\'eq-edit-photo-rear\').click()">' +
                      '<img id="eq-edit-preview-rear" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:8px;display:none;">' +
                      '<span style="font-size:10px;color:var(--text-tertiary);text-align:center;"><i class="ph ph-camera" style="font-size:18px;"></i><br>후면</span>' +
                      '<input type="file" id="eq-edit-photo-rear" name="photo_rear_file" accept="image/*" style="display:none;" onchange="window.previewFile(this, \'eq-edit-preview-rear\')">' +
                    '</div>' +
                    '<div class="photo-edit-box" style="position:relative;background:var(--bg-base);border:1px dashed var(--border-strong);border-radius:8px;height:80px;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;" onclick="document.getElementById(\'eq-edit-photo-left\').click()">' +
                      '<img id="eq-edit-preview-left" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:8px;display:none;">' +
                      '<span style="font-size:10px;color:var(--text-tertiary);text-align:center;"><i class="ph ph-camera" style="font-size:18px;"></i><br>좌측</span>' +
                      '<input type="file" id="eq-edit-photo-left" name="photo_left_file" accept="image/*" style="display:none;" onchange="window.previewFile(this, \'eq-edit-preview-left\')">' +
                    '</div>' +
                    '<div class="photo-edit-box" style="position:relative;background:var(--bg-base);border:1px dashed var(--border-strong);border-radius:8px;height:80px;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;" onclick="document.getElementById(\'eq-edit-photo-right\').click()">' +
                      '<img id="eq-edit-preview-right" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:8px;display:none;">' +
                      '<span style="font-size:10px;color:var(--text-tertiary);text-align:center;"><i class="ph ph-camera" style="font-size:18px;"></i><br>우측</span>' +
                      '<input type="file" id="eq-edit-photo-right" name="photo_right_file" accept="image/*" style="display:none;" onchange="window.previewFile(this, \'eq-edit-preview-right\')">' +
                    '</div>' +
                  '</div>' +
                '</div>' +
                
                '<div>' +
                  '<label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px;">렌트 계약서 파일 변경 (PDF/Image)</label>' +
                  '<input type="file" name="contract_file" accept=".pdf,image/*" style="width:100%;padding:8px;border-radius:6px;background:var(--bg-base);border:1px solid var(--border-subtle);color:var(--text-primary);height:38px;">' +
                '</div>' +

                '<div style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px;padding-top:16px;border-top:1px solid var(--border-subtle);">' +
                  '<button type="button" class="btn-secondary btn-eq-edit-cancel" style="padding:10px 20px;cursor:pointer;">취소</button>' +
                  '<button type="submit" class="btn-primary" style="padding:10px 20px;cursor:pointer;">저장하기</button>' +
                '</div>' +
              '</form>' +
            '</div>' +
          '</div>';

        document.body.appendChild(modal);

        var detailView = modal.querySelector('#eq-detail-view');
        var editView = modal.querySelector('#eq-edit-view');
        var editForm = modal.querySelector('#eq-edit-form');

        // Preview helper
        window.previewFile = function(input, imgId) {
          var file = input.files[0];
          var preview = modal.querySelector('#' + imgId);
          if (file) {
            var reader = new FileReader();
            reader.onload = function(e) {
              preview.src = e.target.result;
              preview.style.display = 'block';
            }
            reader.readAsDataURL(file);
          }
        };

        // Switch to Edit
        modal.querySelector('.btn-eq-edit-trigger').onclick = function() {
          editForm.querySelector('[name="equipment_type"]').value = r.equipType || '';
          editForm.querySelector('[name="model"]').value = r.model || '';
          editForm.querySelector('[name="vendor"]').value = r.vendor === '-' ? '' : r.vendor;
          editForm.querySelector('[name="status"]').value = r.status || '대기중';
          editForm.querySelector('[name="daily_rate"]').value = r.dailyRate || 0;
          editForm.querySelector('[name="delivery_fee"]').value = r.deliveryFee || 0;
          editForm.querySelector('[name="rent_start"]').value = r.startDate === '-' ? '' : r.startDate;
          editForm.querySelector('[name="rent_end"]').value = r.endDate === '-' ? '' : r.endDate;

          var setPreview = function(path, imgId) {
            var img = editForm.querySelector('#' + imgId);
            if (path) {
              img.src = getSecureFileUrl(path);
              img.style.display = 'block';
            } else {
              img.style.display = 'none';
            }
          };
          setPreview(r.photo_front, 'eq-edit-preview-front');
          setPreview(r.photo_rear, 'eq-edit-preview-rear');
          setPreview(r.photo_left, 'eq-edit-preview-left');
          setPreview(r.photo_right, 'eq-edit-preview-right');

          detailView.style.display = 'none';
          editView.style.display = 'block';
        };

        // Switch back to Detail
        modal.querySelector('.btn-eq-edit-cancel').onclick = function() {
          editView.style.display = 'none';
          detailView.style.display = 'flex';
        };

        // Submit Form
        editForm.onsubmit = async function(e) {
          e.preventDefault();
          var formData = new FormData(editForm);
          var saveBtn = editForm.querySelector('button[type="submit"]');
          var originalBtnText = saveBtn.textContent;
          saveBtn.disabled = true;
          saveBtn.textContent = '저장 중...';

          try {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var response = await fetch('/equipment-api/' + r.realId + '/update', {
              method: 'POST',
              headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
              },
              body: formData
            });

            if (!response.ok) {
              var errData = await response.json();
              throw new Error(errData.error || '저장 중 오류가 발생했습니다.');
            }

            modal.remove();
            showToast('장비 정보가 수정되었습니다.');
            window.renderRental();
          } catch(err) {
            alert('오류: ' + err.message);
            saveBtn.disabled = false;
            saveBtn.textContent = originalBtnText;
          }
        };

        // Delete Equipment
        modal.querySelector('.btn-eq-delete-trigger').onclick = async function() {
          if (!confirm('정말로 이 장비/자재를 삭제하시겠습니까?\n이 작업은 되돌릴 수 없습니다.')) {
            return;
          }

          try {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var response = await fetch('/equipment-api/' + r.realId, {
              method: 'DELETE',
              headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
              }
            });

            if (!response.ok) {
              var errData = await response.json();
              throw new Error(errData.error || '삭제 중 오류가 발생했습니다.');
            }

            modal.remove();
            showToast('장비가 삭제되었습니다.');
            window.renderRental();
          } catch(err) {
            alert('오류: ' + err.message);
          }
        };

        try {
          var response = await fetch('/equipment-api/' + r.realId + '/history');
          if (!response.ok) throw new Error('이력 로딩 실패');
          var res = await response.json();
          var history = res.history || [];
          var timelineHtml = '';

          if (history.length === 0) {
            timelineHtml = '<div style="color:var(--text-tertiary);text-align:center;padding:12px;">배정 이력이 없습니다.</div>';
          } else {
            timelineHtml = history.map(function(h) {
              var period = h.returned_at ? h.rented_at + ' ~ ' + h.returned_at : h.rented_at + ' ~ <span style="color:var(--status-success);font-weight:700;">사용 중</span>';
              var notesText = h.notes ? '<div style="font-size:11px;color:var(--text-tertiary);margin-top:4px;background:rgba(255,255,255,0.05);padding:4px 8px;border-radius:4px;">' + h.notes.replace(/\\n/g, '<br>') + '</div>' : '';
              return '<div style="padding:10px 0;border-bottom:1px solid var(--border-subtle);font-size:13px;display:flex;flex-direction:column;gap:4px;">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;">' +
                  '<span style="font-weight:700;color:var(--text-primary);">' + h.company + ' - ' + h.team + ' (' + h.operator + ')</span>' +
                  '<span style="font-size:11px;color:var(--text-secondary);">' + period + '</span>' +
                '</div>' +
                '<div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);">' +
                  '<span style="font-weight:600;color:' + (h.status === '대여중' ? 'var(--status-success)' : 'var(--text-tertiary)') + ';">' + h.status + '</span>' +

                '</div>' +
                notesText +
              '</div>';
            }).join('');
          }
          modal.querySelector('#equipment-history-timeline').innerHTML = timelineHtml;
        } catch (err) {
          modal.querySelector('#equipment-history-timeline').innerHTML = '<div style="color:var(--status-danger);text-align:center;padding:12px;">이력을 불러오지 못했습니다.</div>';
        }
      };


      // 🤖 AI Equipment Scanner Modal
      window.openAiEquipmentRegModal = function() {
        var modal = document.createElement('div');
        modal.id = 'ai-equipment-reg-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;';
        
        modal.innerHTML =
          '<style>' +
            '#ai-equipment-reg-modal * { box-sizing: border-box; }' +
            '#ai-equipment-reg-modal .webcam-slot-card { transition: all 0.2s; }' +
            '#ai-equipment-reg-modal .webcam-slot-card:hover { background: rgba(124, 58, 237, 0.05) !important; border-color: var(--brand-primary) !important; }' +
            '#ai-equipment-reg-modal .active-target { box-shadow: 0 0 8px rgba(124, 58, 237, 0.4); animation: target-pulse 2s infinite ease-in-out; }' +
            '#ai-equipment-reg-modal input[type="text"],' +
            '#ai-equipment-reg-modal input[type="number"],' +
            '#ai-equipment-reg-modal input[type="date"] {' +
            '  width: 100%;' +
            '  background: var(--bg-base);' +
            '  border: 1px solid var(--border-strong);' +
            '  border-radius: 8px;' +
            '  padding: 10px 12px;' +
            '  color: var(--text-primary);' +
            '  font-size: 13px;' +
            '  outline: none;' +
            '  transition: border-color 0.2s, box-shadow 0.2s;' +
            '}' +
            '#ai-equipment-reg-modal input[type="text"]:focus,' +
            '#ai-equipment-reg-modal input[type="number"]:focus,' +
            '#ai-equipment-reg-modal input[type="date"]:focus {' +
            '  border-color: #7c3aed;' +
            '  box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);' +
            '}' +
            '#ai-equipment-reg-modal label {' +
            '  display: block;' +
            '  font-size: 12px;' +
            '  font-weight: 600;' +
            '  color: var(--text-secondary);' +
            '  margin-bottom: 6px;' +
            '}' +
            '#ai-equipment-reg-modal .btn-primary {' +
            '  background: linear-gradient(135deg, #7c3aed, #2563eb);' +
            '  border: none;' +
            '  color: #fff;' +
            '  font-weight: 600;' +
            '  border-radius: 8px;' +
            '  height: 38px;' +
            '  transition: opacity 0.2s, transform 0.1s;' +
            '}' +
            '#ai-equipment-reg-modal .btn-primary:hover {' +
            '  opacity: 0.9;' +
            '}' +
            '#ai-equipment-reg-modal .btn-primary:active {' +
            '  transform: scale(0.98);' +
            '}' +
            '#ai-equipment-reg-modal .btn-secondary {' +
            '  background: var(--bg-base);' +
            '  border: 1px solid var(--border-strong);' +
            '  color: var(--text-primary);' +
            '  font-weight: 600;' +
            '  border-radius: 8px;' +
            '  height: 38px;' +
            '  transition: background 0.2s, transform 0.1s;' +
            '}' +
            '#ai-equipment-reg-modal .btn-secondary:hover {' +
            '  background: var(--bg-body);' +
            '}' +
            '#ai-equipment-reg-modal .btn-secondary:active {' +
            '  transform: scale(0.98);' +
            '}' +
            '#equipment-modal-close-btn {' +
            '  transition: color 0.2s, transform 0.2s;' +
            '}' +
            '#equipment-modal-close-btn:hover {' +
            '  color: var(--text-primary);' +
            '  transform: rotate(90deg);' +
            '}' +
            '@keyframes target-pulse { 0%, 100% { border-color: var(--brand-primary); } 50% { border-color: var(--border-strong); } }' +
            '@media (max-width: 640px) { #equipment-camera-area { grid-template-columns: 1fr !important; } }' +
          '</style>' +
          '<div style="background:var(--bg-panel);border:1px solid var(--border-default);border-radius:16px;padding:20px;width:520px;max-width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 10px 25px rgba(0,0,0,0.5);position:relative;box-sizing:border-box;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;border-bottom:1px solid var(--border-subtle);padding-bottom:12px;">' +
              '<h2 style="font-size:16px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px;margin:0;"><i class="ph ph-robot" style="color:#7c3aed;font-size:22px;"></i> AI 장비렌탈 자동 등록</h2>' +
              '<button type="button" id="equipment-modal-close-btn" style="background:none;border:none;color:var(--text-secondary);font-size:20px;cursor:pointer;padding:0;"><i class="ph ph-x"></i></button>' +
            '</div>' +
            '<div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 16px; line-height:1.5;">' +
              '렌트 계약서와 장비 사진들을 업로드하거나 실시간 카메라로 촬영하세요.<br>Gemini AI 엔진이 문서와 사진 정보를 자동으로 판독하여 장부에 등록합니다.' +
            '</div>' +
            '<input type="file" id="equipment-file-contract" style="display:none" accept="application/pdf,image/*">' +
            '<input type="file" id="equipment-file-photo_front" style="display:none" accept="image/*">' +
            '<input type="file" id="equipment-file-photo_rear" style="display:none" accept="image/*">' +
            '<input type="file" id="equipment-file-photo_left" style="display:none" accept="image/*">' +
            '<input type="file" id="equipment-file-photo_right" style="display:none" accept="image/*">' +
            '<div style="display: flex; gap: 8px; margin-bottom: 16px;">' +
              '<button type="button" id="equipment-btn-toggle-upload" class="btn-primary" style="flex: 1; justify-content: center; height: 38px; border-radius: 8px; cursor:pointer;">' +
                '<i class="ph ph-upload-simple"></i> 파일 업로드' +
              '</button>' +
              '<button type="button" id="equipment-btn-toggle-camera" class="btn-secondary" style="flex: 1; justify-content: center; height: 38px; border-radius: 8px; cursor:pointer;">' +
                '<i class="ph ph-camera"></i> 실시간 카메라' +
              '</button>' +
            '</div>' +
            '<div id="equipment-upload-area" style="display:flex; flex-direction:column; gap:12px; margin-bottom: 16px;">' +
              '<div id="equipment-card-contract" style="border: 2px dashed var(--border-strong); border-radius: var(--radius-md); padding: 16px; text-align: center; cursor: pointer; transition: 0.2s; position: relative; background: var(--bg-body); min-height: 75px; display: flex; align-items: center; justify-content: center; flex-direction: column;" onmouseover="this.style.borderColor=\'var(--brand-primary)\'" onmouseout="if(!this.dataset.hasFile) this.style.borderColor=\'var(--border-strong)\'">' +
                '<div class="empty-state" style="display:flex; flex-direction:column; align-items:center; gap:6px;">' +
                  '<i class="ph ph-file-text" style="font-size: 24px; color: var(--text-tertiary);"></i>' +
                  '<div style="font-weight: 600; font-size:13px; color:var(--text-primary);">렌트 계약서 파일 (PDF/이미지) <span style="color:var(--status-danger)">*</span></div>' +
                  '<div style="font-size: 11px; color: var(--text-tertiary);">클릭하여 파일 업로드</div>' +
                '</div>' +
                '<div class="preview-state" style="display: none; width:100%; text-align:center; position:relative;">' +
                  '<div class="preview-icon-wrapper" style="font-size: 24px; color: var(--brand-primary); margin-bottom: 4px;"><i class="ph ph-file-pdf"></i></div>' +
                  '<img class="preview-img" style="max-height: 50px; max-width: 100%; object-fit: contain; border-radius: 4px; display: none; margin: 0 auto 4px;">' +
                  '<div class="file-name" style="font-size: 12px; color: var(--text-primary); font-weight: 600; word-break: break-all; padding: 0 32px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></div>' +
                  '<button type="button" class="trash-btn" style="position: absolute; top: -8px; right: -8px; background: rgba(0,0,0,0.6); color: #fff; width:24px; height:24px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:12px;"><i class="ph ph-trash"></i></button>' +
                '</div>' +
              '</div>' +
              '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">' +
                '<div id="equipment-card-photo_front" style="border: 2px dashed var(--border-strong); border-radius: var(--radius-md); text-align: center; cursor: pointer; transition: 0.2s; position: relative; background: var(--bg-body); height: 80px; display: flex; align-items: center; justify-content: center; flex-direction: column;" onmouseover="this.style.borderColor=\'var(--brand-primary)\'" onmouseout="if(!this.dataset.hasFile) this.style.borderColor=\'var(--border-strong)\'">' +
                  '<div class="empty-state" style="display:flex; flex-direction:column; align-items:center; gap:4px;">' +
                    '<i class="ph ph-camera" style="font-size: 20px; color: var(--text-tertiary);"></i>' +
                    '<div style="font-weight: 600; font-size:12px; color:var(--text-primary);">전면 사진</div>' +
                  '</div>' +
                  '<div class="preview-state" style="display: none; width: 100%; height: 100%;">' +
                    '<img class="preview-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">' +
                    '<button type="button" class="trash-btn" style="position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.6); color: #fff; width:20px; height:20px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size: 11px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
                '<div id="equipment-card-photo_rear" style="border: 2px dashed var(--border-strong); border-radius: var(--radius-md); text-align: center; cursor: pointer; transition: 0.2s; position: relative; background: var(--bg-body); height: 80px; display: flex; align-items: center; justify-content: center; flex-direction: column;" onmouseover="this.style.borderColor=\'var(--brand-primary)\'" onmouseout="if(!this.dataset.hasFile) this.style.borderColor=\'var(--border-strong)\'">' +
                  '<div class="empty-state" style="display:flex; flex-direction:column; align-items:center; gap:4px;">' +
                    '<i class="ph ph-camera" style="font-size: 20px; color: var(--text-tertiary);"></i>' +
                    '<div style="font-weight: 600; font-size:12px; color:var(--text-primary);">후면 사진</div>' +
                  '</div>' +
                  '<div class="preview-state" style="display: none; width: 100%; height: 100%;">' +
                    '<img class="preview-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">' +
                    '<button type="button" class="trash-btn" style="position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.6); color: #fff; width:20px; height:20px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size: 11px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
                '<div id="equipment-card-photo_left" style="border: 2px dashed var(--border-strong); border-radius: var(--radius-md); text-align: center; cursor: pointer; transition: 0.2s; position: relative; background: var(--bg-body); height: 80px; display: flex; align-items: center; justify-content: center; flex-direction: column;" onmouseover="this.style.borderColor=\'var(--brand-primary)\'" onmouseout="if(!this.dataset.hasFile) this.style.borderColor=\'var(--border-strong)\'">' +
                  '<div class="empty-state" style="display:flex; flex-direction:column; align-items:center; gap:4px;">' +
                    '<i class="ph ph-camera" style="font-size: 20px; color: var(--text-tertiary);"></i>' +
                    '<div style="font-weight: 600; font-size:12px; color:var(--text-primary);">좌측 사진</div>' +
                  '</div>' +
                  '<div class="preview-state" style="display: none; width: 100%; height: 100%;">' +
                    '<img class="preview-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">' +
                    '<button type="button" class="trash-btn" style="position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.6); color: #fff; width:20px; height:20px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size: 11px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
                '<div id="equipment-card-photo_right" style="border: 2px dashed var(--border-strong); border-radius: var(--radius-md); text-align: center; cursor: pointer; transition: 0.2s; position: relative; background: var(--bg-body); height: 80px; display: flex; align-items: center; justify-content: center; flex-direction: column;" onmouseover="this.style.borderColor=\'var(--brand-primary)\'" onmouseout="if(!this.dataset.hasFile) this.style.borderColor=\'var(--border-strong)\'">' +
                  '<div class="empty-state" style="display:flex; flex-direction:column; align-items:center; gap:4px;">' +
                    '<i class="ph ph-camera" style="font-size: 20px; color: var(--text-tertiary);"></i>' +
                    '<div style="font-weight: 600; font-size:12px; color:var(--text-primary);">우측 사진</div>' +
                  '</div>' +
                  '<div class="preview-state" style="display: none; width: 100%; height: 100%;">' +
                    '<img class="preview-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">' +
                    '<button type="button" class="trash-btn" style="position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.6); color: #fff; width:20px; height:20px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size: 11px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
              '</div>' +
            '</div>' +
            '<div id="equipment-camera-area" style="display: none; grid-template-columns: 1.2fr 1fr; gap: 12px; align-items: start; margin-bottom: 16px;">' +
              '<div style="background: var(--bg-body); border-radius: var(--radius-md); padding: 8px; border: 1px solid var(--border-strong);">' +
                '<div style="position: relative; border-radius: var(--radius-sm); overflow: hidden; background: #000; aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center;">' +
                  '<video id="equipment-video-stream" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>' +
                  '<div id="equipment-camera-overlay-label" style="position: absolute; bottom: 8px; left: 8px; right: 8px; background: rgba(0,0,0,0.6); color: var(--text-primary); font-size: 11px; padding: 4px 6px; border-radius: 4px; text-align: center; font-weight: 600; border: 1px solid rgba(255,255,255,0.1);">' +
                    '촬영 대상: 계약서' +
                  '</div>' +
                '</div>' +
                '<div style="display: flex; gap: 8px; margin-top: 8px;">' +
                  '<button type="button" id="equipment-btn-capture" class="btn-primary" style="flex: 1; justify-content: center; height: 34px; font-size: 12px; font-weight: 600; cursor:pointer;">' +
                    '<i class="ph ph-camera"></i> 사진 촬영' +
                  '</button>' +
                '</div>' +
                '<div id="equipment-camera-select-container" style="margin-top: 8px; display: none; align-items:center; gap:6px;">' +
                  '<span style="font-size:10px; color:var(--text-secondary); font-weight:600; white-space:nowrap;">카메라:</span>' +
                  '<select id="equipment-camera-device-select" style="flex:1; padding: 4px; background: var(--bg-base); border: 1px solid var(--border-strong); border-radius: 4px; color: var(--text-primary); font-size: 10px; outline: none;"></select>' +
                '</div>' +
              '</div>' +
              '<div style="display: flex; flex-direction: column; gap: 8px; background: var(--bg-body); border-radius: var(--radius-md); padding: 8px; border: 1px solid var(--border-strong);">' +
                '<div style="font-size:11px; font-weight:700; color:var(--text-secondary); margin-bottom:2px; padding-bottom:4px; border-bottom:1px solid var(--border-subtle)">촬영 슬롯 선택</div>' +
                '<div class="webcam-slot-card active-target" data-slot="contract" style="display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: 6px 10px; cursor: pointer; transition: 0.2s; background: var(--bg-base);">' +
                  '<div style="display: flex; align-items: center; gap: 8px; overflow: hidden; width: 80%;">' +
                    '<div class="slot-thumbnail" style="width: 28px; height: 28px; border-radius: 4px; background: var(--bg-body); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-subtle); overflow: hidden; flex-shrink: 0;">' +
                      '<i class="ph ph-file-text" style="color: var(--text-tertiary); font-size: 14px;"></i>' +
                    '</div>' +
                    '<div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' +
                      '<div style="font-size: 11px; font-weight: 600; color: var(--text-primary);">계약서 <span style="color:var(--status-danger)">*</span></div>' +
                      '<div class="slot-status" style="font-size: 9px; color: var(--text-tertiary);">선택된 파일 없음</div>' +
                    '</div>' +
                  '</div>' +
                  '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span class="target-badge" style="font-size: 8px; padding: 1px 4px; border-radius: 8px; background: var(--brand-primary); color: #fff; font-weight: 700; display: inline-block;">TARGET</span>' +
                    '<button type="button" class="slot-delete-btn" style="background: none; border: none; color: var(--status-danger); cursor: pointer; display: none; font-size: 12px; padding: 2px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
                '<div class="webcam-slot-card" data-slot="photo_front" style="display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: 6px 10px; cursor: pointer; transition: 0.2s; background: var(--bg-base);">' +
                  '<div style="display: flex; align-items: center; gap: 8px; overflow: hidden; width: 80%;">' +
                    '<div class="slot-thumbnail" style="width: 28px; height: 28px; border-radius: 4px; background: var(--bg-body); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-subtle); overflow: hidden; flex-shrink: 0;">' +
                      '<i class="ph ph-camera" style="color: var(--text-tertiary); font-size: 14px;"></i>' +
                    '</div>' +
                    '<div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' +
                      '<div style="font-size: 11px; font-weight: 600; color: var(--text-primary);">전면 사진</div>' +
                      '<div class="slot-status" style="font-size: 9px; color: var(--text-tertiary);">선택된 사진 없음</div>' +
                    '</div>' +
                  '</div>' +
                  '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span class="target-badge" style="font-size: 8px; padding: 1px 4px; border-radius: 8px; background: var(--brand-primary); color: #fff; font-weight: 700; display: none;">TARGET</span>' +
                    '<button type="button" class="slot-delete-btn" style="background: none; border: none; color: var(--status-danger); cursor: pointer; display: none; font-size: 12px; padding: 2px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
                '<div class="webcam-slot-card" data-slot="photo_rear" style="display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: 6px 10px; cursor: pointer; transition: 0.2s; background: var(--bg-base);">' +
                  '<div style="display: flex; align-items: center; gap: 8px; overflow: hidden; width: 80%;">' +
                    '<div class="slot-thumbnail" style="width: 28px; height: 28px; border-radius: 4px; background: var(--bg-body); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-subtle); overflow: hidden; flex-shrink: 0;">' +
                      '<i class="ph ph-camera" style="color: var(--text-tertiary); font-size: 14px;"></i>' +
                    '</div>' +
                    '<div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' +
                      '<div style="font-size: 11px; font-weight: 600; color: var(--text-primary);">후면 사진</div>' +
                      '<div class="slot-status" style="font-size: 9px; color: var(--text-tertiary);">선택된 사진 없음</div>' +
                    '</div>' +
                  '</div>' +
                  '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span class="target-badge" style="font-size: 8px; padding: 1px 4px; border-radius: 8px; background: var(--brand-primary); color: #fff; font-weight: 700; display: none;">TARGET</span>' +
                    '<button type="button" class="slot-delete-btn" style="background: none; border: none; color: var(--status-danger); cursor: pointer; display: none; font-size: 12px; padding: 2px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
                '<div class="webcam-slot-card" data-slot="photo_left" style="display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: 6px 10px; cursor: pointer; transition: 0.2s; background: var(--bg-base);">' +
                  '<div style="display: flex; align-items: center; gap: 8px; overflow: hidden; width: 80%;">' +
                    '<div class="slot-thumbnail" style="width: 28px; height: 28px; border-radius: 4px; background: var(--bg-body); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-subtle); overflow: hidden; flex-shrink: 0;">' +
                      '<i class="ph ph-camera" style="color: var(--text-tertiary); font-size: 14px;"></i>' +
                    '</div>' +
                    '<div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' +
                      '<div style="font-size: 11px; font-weight: 600; color: var(--text-primary);">좌측 사진</div>' +
                      '<div class="slot-status" style="font-size: 9px; color: var(--text-tertiary);">선택된 사진 없음</div>' +
                    '</div>' +
                  '</div>' +
                  '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span class="target-badge" style="font-size: 8px; padding: 1px 4px; border-radius: 8px; background: var(--brand-primary); color: #fff; font-weight: 700; display: none;">TARGET</span>' +
                    '<button type="button" class="slot-delete-btn" style="background: none; border: none; color: var(--status-danger); cursor: pointer; display: none; font-size: 12px; padding: 2px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
                '<div class="webcam-slot-card" data-slot="photo_right" style="display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: 6px 10px; cursor: pointer; transition: 0.2s; background: var(--bg-base);">' +
                  '<div style="display: flex; align-items: center; gap: 8px; overflow: hidden; width: 80%;">' +
                    '<div class="slot-thumbnail" style="width: 28px; height: 28px; border-radius: 4px; background: var(--bg-body); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-subtle); overflow: hidden; flex-shrink: 0;">' +
                      '<i class="ph ph-camera" style="color: var(--text-tertiary); font-size: 14px;"></i>' +
                    '</div>' +
                    '<div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' +
                      '<div style="font-size: 11px; font-weight: 600; color: var(--text-primary);">우측 사진</div>' +
                      '<div class="slot-status" style="font-size: 9px; color: var(--text-tertiary);">선택된 사진 없음</div>' +
                    '</div>' +
                  '</div>' +
                  '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span class="target-badge" style="font-size: 8px; padding: 1px 4px; border-radius: 8px; background: var(--brand-primary); color: #fff; font-weight: 700; display: none;">TARGET</span>' +
                    '<button type="button" class="slot-delete-btn" style="background: none; border: none; color: var(--status-danger); cursor: pointer; display: none; font-size: 12px; padding: 2px;"><i class="ph ph-trash"></i></button>' +
                  '</div>' +
                '</div>' +
              '</div>' +
            '</div>' +
            '<div id="equipment-initial-controls" style="margin-top: 12px; display:flex; justify-content:flex-end; gap:12px;">' +
              '<button type="button" id="equipment-upload-cancel-btn" class="btn-secondary" style="padding:0 20px; cursor:pointer;">취소</button>' +
              '<button type="button" id="equipment-upload-submit-btn" class="btn-primary" style="padding:0 24px; cursor:pointer;">AI 분석 실행</button>' +
            '</div>' +
            '<div id="equipment-analysis-loading" style="display:none; flex-direction:column; align-items:center; justify-content:center; padding:30px 0; gap:12px;">' +
              '<div style="width:40px; height:40px; border:4px solid rgba(124,58,237,0.2); border-top-color:#7c3aed; border-radius:50%; animation:spin 1s linear infinite"></div>' +
              '<div style="color:var(--text-primary); font-size:14px; font-weight:700;">Gemini AI가 분석하는 중...</div>' +
              '<div style="color:var(--text-secondary); font-size:12px; text-align:center; max-width:320px; line-height:1.4;">계약서 및 장비 사진 정보를 추출하여 자동 매핑하고 있습니다. 잠시만 기다려주세요.</div>' +
            '</div>' +
            '<div id="equipment-analysis-result-container" style="display:none; flex-direction:column; gap:12px; margin-top:12px;">' +
              '<h3 style="font-size:14px; font-weight:700; color:var(--text-primary); margin:0; padding-top:12px; border-top:1px solid var(--border-subtle); display:flex; align-items:center; gap:6px;"><i class="ph ph-check-square" style="color:var(--status-success)"></i> AI 분석 결과 검증</h3>' +
              '<div style="font-size:11px; color:var(--text-secondary); background:rgba(124,58,237,0.06); border:1px solid rgba(124,58,237,0.15); border-radius:6px; padding:8px 10px; line-height:1.4;">AI가 추출한 정보입니다. 실제 계약서 내용과 대조 후 수정이 필요한 부분은 직접 변경하고 저장하세요.</div>' +
              '<form id="ai-equipment-save-form" style="display:flex; flex-direction:column; gap:12px;">' +
                '<input type="hidden" name="contract_path">' +
                '<input type="hidden" name="photo_front">' +
                '<input type="hidden" name="photo_rear">' +
                '<input type="hidden" name="photo_left">' +
                '<input type="hidden" name="photo_right">' +
                '<input type="hidden" name="details">' +
                '<input type="hidden" name="rate_period">' +
                '<input type="hidden" name="return_fee">' +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">' +
                  '<div>' +
                    '<label>장비 종류 (Type)</label>' +
                    '<input type="text" name="equipment_type" required>' +
                  '</div>' +
                  '<div>' +
                    '<label>장비 모델명</label>' +
                    '<input type="text" name="model" required>' +
                  '</div>' +
                '</div>' +
                '<div style="display:grid; grid-template-columns:2fr 1fr; gap:10px;">' +
                  '<div>' +
                    '<label>렌트사 (Vendor)</label>' +
                    '<input type="text" name="vendor">' +
                  '</div>' +
                  '<div>' +
                    '<label>수량 (Qty)</label>' +
                    '<input type="number" name="quantity" min="1" value="1">' +
                  '</div>' +
                '</div>' +
                '<div id="ai-extracted-summary" style="display:none; font-size:11px; color:var(--text-secondary); background:rgba(124,58,237,0.06); border:1px solid rgba(124,58,237,0.15); border-radius:6px; padding:8px 10px; line-height:1.6;"></div>' +
                '<div style="display:grid; grid-template-columns:repeat(4,1fr); gap:8px;">' +
                  '<div style="grid-column:span 2;">' +
                    '<label>렌트 시작일</label>' +
                    '<input type="date" name="rent_start">' +
                  '</div>' +
                  '<div style="grid-column:span 2;">' +
                    '<label>렌트 종료일</label>' +
                    '<input type="date" name="rent_end">' +
                  '</div>' +
                '</div>' +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">' +
                  '<div>' +
                    '<label>일단가 ($)</label>' +
                    '<input type="number" name="daily_rate" min="0">' +
                  '</div>' +
                  '<div>' +
                    '<label>배송비 ($)</label>' +
                    '<input type="number" name="delivery_fee" min="0">' +
                  '</div>' +
                '</div>' +
                '<div id="ai-uploaded-previews" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; margin-top:4px;"></div>' +
                '<div style="margin-top:8px; display:flex; justify-content:flex-end; gap:12px;">' +
                  '<button type="button" id="equipment-save-cancel-btn" class="btn-secondary" style="padding:0 20px;">취소</button>' +
                  '<button type="submit" class="btn-primary" style="background:var(--status-success) !important; padding:0 24px; border:none; cursor:pointer;">장비 등록 및 저장</button>' +
                '</div>' +
              '</form>' +
            '</div>' +
          '</div>';

        document.body.appendChild(modal);

        var equipmentFiles = {
          contract: null,
          photo_front: null,
          photo_rear: null,
          photo_left: null,
          photo_right: null
        };

        var currentActiveSlot = 'contract';
        var currentCameraMode = 'upload';
        var localVideoStream = null;
        var availableCameraDevices = [];

        var btnToggleUpload = modal.querySelector('#equipment-btn-toggle-upload');
        var btnToggleCamera = modal.querySelector('#equipment-btn-toggle-camera');
        var uploadArea = modal.querySelector('#equipment-upload-area');
        var cameraArea = modal.querySelector('#equipment-camera-area');
        var initialControls = modal.querySelector('#equipment-initial-controls');
        var loadingDiv = modal.querySelector('#equipment-analysis-loading');
        var resultDiv = modal.querySelector('#equipment-analysis-result-container');
        var saveForm = modal.querySelector('#ai-equipment-save-form');
        var deviceSelect = modal.querySelector('#equipment-camera-device-select');
        var deviceSelectContainer = modal.querySelector('#equipment-camera-select-container');

        function showUploadMode() {
          currentCameraMode = 'upload';
          btnToggleUpload.className = 'btn-primary';
          btnToggleCamera.className = 'btn-secondary';
          uploadArea.style.display = 'flex';
          cameraArea.style.display = 'none';
          stopCamera();
        }

        async function showCameraMode() {
          currentCameraMode = 'camera';
          btnToggleUpload.className = 'btn-secondary';
          btnToggleCamera.className = 'btn-primary';
          uploadArea.style.display = 'none';
          cameraArea.style.display = 'grid';
          await startCamera();
        }

        btnToggleUpload.onclick = showUploadMode;
        btnToggleCamera.onclick = showCameraMode;

        var cards = ['contract', 'photo_front', 'photo_rear', 'photo_left', 'photo_right'];
        cards.forEach(function(slot) {
          var card = modal.querySelector('#equipment-card-' + slot);
          if (card) {
            card.onclick = function(e) {
              if (e.target.closest('.trash-btn')) return;
              modal.querySelector('#equipment-file-' + slot).click();
            };
          }

          var fileInput = modal.querySelector('#equipment-file-' + slot);
          if (fileInput) {
            fileInput.onchange = function(e) {
              var file = e.target.files[0];
              if (file) {
                setFileSlot(slot, file);
              }
            };
          }
        });

        function setFileSlot(slot, file) {
          equipmentFiles[slot] = file;
          var url = URL.createObjectURL(file);

          var card = modal.querySelector('#equipment-card-' + slot);
          if (card) {
            card.dataset.hasFile = 'true';
            card.style.borderColor = 'var(--border-default)';
            var emptyState = card.querySelector('.empty-state');
            var previewState = card.querySelector('.preview-state');
            emptyState.style.display = 'none';
            previewState.style.display = 'block';

            if (slot === 'contract') {
              var isImg = file.type.startsWith('image/');
              var previewImg = previewState.querySelector('.preview-img');
              var iconWrapper = previewState.querySelector('.preview-icon-wrapper');
              if (isImg) {
                previewImg.src = url;
                previewImg.style.display = 'block';
                iconWrapper.style.display = 'none';
              } else {
                previewImg.style.display = 'none';
                iconWrapper.style.display = 'block';
              }
              previewState.querySelector('.file-name').innerText = file.name;
            } else {
              var img = previewState.querySelector('.preview-img');
              img.src = url;
            }
          }

          var slotRow = modal.querySelector('.webcam-slot-card[data-slot="' + slot + '"]');
          if (slotRow) {
            slotRow.querySelector('.slot-status').innerText = '완료';
            slotRow.querySelector('.slot-status').style.color = 'var(--status-success)';
            slotRow.querySelector('.slot-delete-btn').style.display = 'inline-block';
            
            var thumb = slotRow.querySelector('.slot-thumbnail');
            var isImg = file.type.startsWith('image/');
            if (isImg || slot !== 'contract') {
              thumb.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;">';
            } else {
              thumb.innerHTML = '<i class="ph ph-file-pdf" style="color:var(--brand-primary); font-size:18px;"></i>';
            }
          }
        }

        function clearFileSlot(slot) {
          equipmentFiles[slot] = null;
          var fileInput = modal.querySelector('#equipment-file-' + slot);
          if (fileInput) fileInput.value = '';

          var card = modal.querySelector('#equipment-card-' + slot);
          if (card) {
            delete card.dataset.hasFile;
            card.style.borderColor = 'var(--border-strong)';
            var emptyState = card.querySelector('.empty-state');
            var previewState = card.querySelector('.preview-state');
            emptyState.style.display = 'flex';
            previewState.style.display = 'none';
          }

          var slotRow = modal.querySelector('.webcam-slot-card[data-slot="' + slot + '"]');
          if (slotRow) {
            var iconName = (slot === 'contract') ? 'ph-file-text' : 'ph-camera';
            slotRow.querySelector('.slot-thumbnail').innerHTML = '<i class="ph ' + iconName + '" style="color:var(--text-tertiary);"></i>';
            slotRow.querySelector('.slot-status').innerText = (slot === 'contract') ? '선택된 파일 없음' : '선택된 사진 없음';
            slotRow.querySelector('.slot-status').style.color = 'var(--text-tertiary)';
            slotRow.querySelector('.slot-delete-btn').style.display = 'none';
          }
        }

        cards.forEach(function(slot) {
          var card = modal.querySelector('#equipment-card-' + slot);
          if (card) {
            var trashBtn = card.querySelector('.trash-btn');
            if (trashBtn) {
              trashBtn.onclick = function(e) {
                e.stopPropagation();
                clearFileSlot(slot);
              };
            }
          }

          var slotRow = modal.querySelector('.webcam-slot-card[data-slot="' + slot + '"]');
          if (slotRow) {
            var delBtn = slotRow.querySelector('.slot-delete-btn');
            delBtn.onclick = function(e) {
              e.stopPropagation();
              clearFileSlot(slot);
            };
          }
        });

        var slotRows = modal.querySelectorAll('.webcam-slot-card');
        slotRows.forEach(function(row) {
          row.onclick = function() {
            var slot = row.dataset.slot;
            setActiveTargetSlot(slot);
          };
        });

        function setActiveTargetSlot(slot) {
          currentActiveSlot = slot;
          
          slotRows.forEach(function(row) {
            if (row.dataset.slot === slot) {
              row.classList.add('active-target');
              row.style.background = 'rgba(124,58,237,0.1)';
              row.style.borderColor = 'var(--brand-primary)';
              row.querySelector('.target-badge').style.display = 'inline-block';
            } else {
              row.classList.remove('active-target');
              row.style.background = 'var(--bg-base)';
              row.style.borderColor = 'var(--border-strong)';
              row.querySelector('.target-badge').style.display = 'none';
            }
          });

          var labelMap = {
            contract: '렌트 계약서',
            photo_front: '장비 전면 사진',
            photo_rear: '장비 후면 사진',
            photo_left: '장비 좌측 사진',
            photo_right: '장비 우측 사진'
          };
          modal.querySelector('#equipment-camera-overlay-label').innerText = '촬영 대상: ' + (labelMap[slot] || slot);
        }

        async function startCamera(preferredDeviceId) {
          stopCamera();
          var video = modal.querySelector('#equipment-video-stream');
          if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            var constraints = {
              video: { facingMode: 'environment' }
            };
            if (preferredDeviceId) {
              constraints.video = { deviceId: { exact: preferredDeviceId } };
            }
            try {
              var stream = await navigator.mediaDevices.getUserMedia(constraints);
              localVideoStream = stream;
              video.srcObject = stream;
              
              if (availableCameraDevices.length === 0) {
                var devices = await navigator.mediaDevices.enumerateDevices();
                availableCameraDevices = devices.filter(function(d) { return d.kind === 'videoinput'; });
                if (availableCameraDevices.length > 1) {
                  deviceSelectContainer.style.display = 'flex';
                  deviceSelect.innerHTML = '';
                  availableCameraDevices.forEach(function(d, idx) {
                    var opt = document.createElement('option');
                    opt.value = d.deviceId;
                    opt.text = d.label || ('Camera ' + (idx + 1));
                    if (preferredDeviceId && d.deviceId === preferredDeviceId) {
                      opt.selected = true;
                    }
                    deviceSelect.appendChild(opt);
                  });
                  deviceSelect.onchange = function() {
                    startCamera(deviceSelect.value);
                  };
                }
              }
            } catch (err) {
              console.error('Camera stream error:', err);
              alert('카메라를 활성화할 수 없습니다: ' + err.message);
              showUploadMode();
            }
          } else {
            alert('이 브라우저에서는 카메라 스트리밍을 지원하지 않습니다. 파일 업로드 모드를 사용해 주세요.');
            showUploadMode();
          }
        }

        function stopCamera() {
          var video = modal.querySelector('#equipment-video-stream');
          if (video && video.srcObject) {
            var stream = video.srcObject;
            var tracks = stream.getTracks();
            tracks.forEach(function(track) { track.stop(); });
            video.srcObject = null;
          }
          localVideoStream = null;
        }

        modal.querySelector('#equipment-btn-capture').onclick = function() {
          var video = modal.querySelector('#equipment-video-stream');
          if (!video || !video.srcObject) return;

          var canvas = document.createElement('canvas');
          canvas.width = video.videoWidth || 640;
          canvas.height = video.videoHeight || 480;
          var ctx = canvas.getContext('2d');
          ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

          var dataUrl = canvas.toDataURL('image/jpeg');
          var fileName = 'webcam_' + currentActiveSlot + '.jpg';
          var file = dataURLtoFile(dataUrl, fileName);

          setFileSlot(currentActiveSlot, file);

          var sequence = ['contract', 'photo_front', 'photo_rear', 'photo_left', 'photo_right'];
          var nextSlotIdx = sequence.indexOf(currentActiveSlot) + 1;
          
          var foundNext = false;
          for (var i = 0; i < sequence.length; i++) {
            var s = sequence[(nextSlotIdx + i) % sequence.length];
            if (!equipmentFiles[s]) {
              setActiveTargetSlot(s);
              foundNext = true;
              break;
            }
          }
          if (!foundNext) {
            showToast('모든 슬롯이 촬영되었습니다.');
          }
        };

        function dataURLtoFile(dataurl, filename) {
          var arr = dataurl.split(','),
              mime = arr[0].match(/:(.*?);/)[1],
              bstr = atob(arr[1]),
              n = bstr.length,
              u8arr = new Uint8Array(n);
          while (n--) {
            u8arr[n] = bstr.charCodeAt(n);
          }
          return new File([u8arr], filename, { type: mime });
        }

        function cleanUpAndClose() {
          stopCamera();
          modal.remove();
        }

        modal.querySelector('#equipment-modal-close-btn').onclick = cleanUpAndClose;
        modal.querySelector('#equipment-upload-cancel-btn').onclick = cleanUpAndClose;
        modal.querySelector('#equipment-save-cancel-btn').onclick = cleanUpAndClose;

        modal.querySelector('#equipment-upload-submit-btn').onclick = async function(e) {
          e.preventDefault();

          if (!equipmentFiles.contract) {
            alert('렌트 계약서는 필수 등록 항목입니다.');
            return;
          }

          uploadArea.style.display = 'none';
          cameraArea.style.display = 'none';
          btnToggleUpload.parentElement.style.display = 'none';
          initialControls.style.display = 'none';
          loadingDiv.style.display = 'flex';
          stopCamera();

          var formData = new FormData();
          formData.append('contract', equipmentFiles.contract);
          if (equipmentFiles.photo_front) formData.append('photo_front', equipmentFiles.photo_front);
          if (equipmentFiles.photo_rear) formData.append('photo_rear', equipmentFiles.photo_rear);
          if (equipmentFiles.photo_left) formData.append('photo_left', equipmentFiles.photo_left);
          if (equipmentFiles.photo_right) formData.append('photo_right', equipmentFiles.photo_right);

          try {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var response = await fetch('/equipment-api/scan-rental', {
              method: 'POST',
              headers: {
                'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
              },
              body: formData
            });

            if (!response.ok) {
              var errData = await response.json();
              throw new Error(errData.error || 'AI 분석 중 오류가 발생했습니다.');
            }

            var res = await response.json();
            loadingDiv.style.display = 'none';
            resultDiv.style.display = 'flex';

            var data = res.data || {};
            var files = res.files || {};

            saveForm.querySelector('[name="equipment_type"]').value = data.equipment_type || '';
            saveForm.querySelector('[name="model"]').value = data.model || '';
            saveForm.querySelector('[name="vendor"]').value = data.vendor || '';
            saveForm.querySelector('[name="quantity"]').value = data.quantity || 1;
            saveForm.querySelector('[name="rent_start"]').value = data.rent_start || '';
            saveForm.querySelector('[name="rent_end"]').value = data.rent_end || '';
            saveForm.querySelector('[name="daily_rate"]').value = data.daily_rate || 0;
            saveForm.querySelector('[name="delivery_fee"]').value = data.delivery_fee || 0;
            saveForm.querySelector('[name="rate_period"]').value = data.rate_period || '';
            saveForm.querySelector('[name="return_fee"]').value = data.return_fee || 0;
            saveForm.querySelector('[name="details"]').value = data.details ? JSON.stringify(data.details) : '';

            // Surface the rich extracted contract metadata (kept on save even if not editable here).
            var d = data.details || {};
            var sumRows = [];
            if (data.rate_amount) sumRows.push('<b>요금</b> $' + data.rate_amount + (data.rate_period ? ' / ' + data.rate_period : ''));
            if (d.quote_no) sumRows.push('<b>견적번호</b> ' + d.quote_no);
            if (d.ship_to_address) sumRows.push('<b>배송지</b> ' + d.ship_to_address);
            if (d.lessee && d.lessee.name) sumRows.push('<b>임차인</b> ' + d.lessee.name + (d.account_no ? ' (계정 ' + d.account_no + ')' : ''));
            if (d.sales_rep && d.sales_rep.name) sumRows.push('<b>영업담당</b> ' + d.sales_rep.name + (d.sales_rep.phone ? ' / ' + d.sales_rep.phone : ''));
            if (d.pricing && d.pricing.total_with_tax) sumRows.push('<b>총액(세포함)</b> $' + d.pricing.total_with_tax);
            if (d.terms && d.terms.payment_terms) sumRows.push('<b>지급조건</b> ' + d.terms.payment_terms);
            if (d.terms && d.terms.min_lease_term) sumRows.push('<b>최소임대</b> ' + d.terms.min_lease_term);
            if (d.scope_of_work) sumRows.push('<b>포함</b> ' + d.scope_of_work);
            var sumDiv = saveForm.querySelector('#ai-extracted-summary');
            if (sumRows.length && sumDiv) {
              sumDiv.innerHTML = '<div style="font-weight:700;color:var(--text-primary);margin-bottom:4px">📋 AI 추출 추가 정보 (저장됨)</div>' + sumRows.join(' &nbsp;·&nbsp; ');
              sumDiv.style.display = 'block';
            }

            saveForm.querySelector('[name="contract_path"]').value = files.contract || '';
            saveForm.querySelector('[name="photo_front"]').value = files.photo_front || '';
            saveForm.querySelector('[name="photo_rear"]').value = files.photo_rear || '';
            saveForm.querySelector('[name="photo_left"]').value = files.photo_left || '';
            saveForm.querySelector('[name="photo_right"]').value = files.photo_right || '';

            var previewsDiv = saveForm.querySelector('#ai-uploaded-previews');
            previewsDiv.innerHTML = '';
            var directions = [
              { key: 'photo_front', label: '전면' },
              { key: 'photo_rear', label: '후면' },
              { key: 'photo_left', label: '좌측' },
              { key: 'photo_right', label: '우측' }
            ];
            directions.forEach(function(d) {
              var src = '';
              if (equipmentFiles[d.key]) {
                src = URL.createObjectURL(equipmentFiles[d.key]);
              } else if (files[d.key]) {
                src = files[d.key];
              }

              if (src) {
                var previewCard = document.createElement('div');
                previewCard.style.cssText = 'text-align:center;background:var(--bg-base);border:1px solid var(--border-default);border-radius:6px;padding:4px;';
                previewCard.innerHTML = 
                  '<img src="' + src + '" style="width:100%;height:60px;object-fit:cover;border-radius:4px;margin-bottom:4px;">' +
                  '<span style="font-size:10px;color:var(--text-secondary);">' + d.label + '</span>';
                previewsDiv.appendChild(previewCard);
              }
            });

          } catch (err) {
            loadingDiv.style.display = 'none';
            btnToggleUpload.parentElement.style.display = 'flex';
            initialControls.style.display = 'flex';
            if (currentCameraMode === 'camera') {
              cameraArea.style.display = 'grid';
              startCamera();
            } else {
              uploadArea.style.display = 'flex';
            }
            alert('오류: ' + err.message);
          }
        };

        saveForm.onsubmit = async function(e) {
          e.preventDefault();
          var detailsRaw = saveForm.querySelector('[name="details"]').value;
          var detailsObj = null;
          if (detailsRaw) { try { detailsObj = JSON.parse(detailsRaw); } catch (e) { detailsObj = null; } }

          var payload = {
            equipment_type: saveForm.querySelector('[name="equipment_type"]').value,
            model: saveForm.querySelector('[name="model"]').value,
            vendor: saveForm.querySelector('[name="vendor"]').value,
            quantity: parseInt(saveForm.querySelector('[name="quantity"]').value, 10) || 1,
            rent_start: saveForm.querySelector('[name="rent_start"]').value || null,
            rent_end: saveForm.querySelector('[name="rent_end"]').value || null,
            daily_rate: parseInt(saveForm.querySelector('[name="daily_rate"]').value, 10) || 0,
            delivery_fee: parseInt(saveForm.querySelector('[name="delivery_fee"]').value, 10) || 0,
            return_fee: parseInt(saveForm.querySelector('[name="return_fee"]').value, 10) || 0,
            rate_period: saveForm.querySelector('[name="rate_period"]').value || null,
            details: detailsObj,
            contract_path: saveForm.querySelector('[name="contract_path"]').value || null,
            photo_front: saveForm.querySelector('[name="photo_front"]').value || null,
            photo_rear: saveForm.querySelector('[name="photo_rear"]').value || null,
            photo_left: saveForm.querySelector('[name="photo_left"]').value || null,
            photo_right: saveForm.querySelector('[name="photo_right"]').value || null
          };

          try {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var response = await fetch('/equipment-api/save', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : ''
              },
              body: JSON.stringify(payload)
            });

            if (!response.ok) {
              var errData = await response.json();
              throw new Error(errData.error || '장비 정보를 저장하는 중 오류가 발생했습니다.');
            }

            modal.remove();
            showToast('장비가 성공적으로 등록되었습니다.');
            window.renderRental();
          } catch (err) {
            alert('오류: ' + err.message);
          }
        };
      };

      // Expose renderRental on window so post-save/return/assign handlers can refresh the list
      // (these call window.renderRental(); the function declaration alone isn't attached to window).
      window.renderRental = renderRental;

            // 엑셀 다운로드
      window.downloadRentalExcel = function() {
        var rentals = window.currentRentals || [];
        if (rentals.length === 0) {
          alert('다운로드할 데이터가 없습니다.');
          return;
        }

        var headers = ['렌탈ID', '장비종류', '모델', '벤더', '시작일', '종료일', '일단가', '배송비', '배정회사', '배정팀', '운영자', '상태'];
        var rows = rentals.map(function(r) {
          return [
            r.id,
            r.equipType,
            r.model,
            r.vendor,
            r.startDate,
            r.endDate,
            r.dailyRate || 0,
            r.deliveryFee || 0,
            r.company || '-',
            r.team || '-',
            r.operator || '미배정',
            r.status
          ];
        });

        var csvContent = '\uFEFF'; // UTF-8 BOM
        csvContent += headers.map(function(h) { return '"' + h.replace(/"/g, '""') + '"'; }).join(',') + '\r\n';
        rows.forEach(function(row) {
          csvContent += row.map(function(v) { 
            var strVal = (v === null || v === undefined) ? '' : String(v);
            return '"' + strVal.replace(/"/g, '""') + '"'; 
          }).join(',') + '\r\n';
        });

        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.setAttribute('href', url);
        link.setAttribute('download', '장비_렌탈_현황_' + new Date().toISOString().slice(0, 10) + '.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      };

      // â”€â”€ HOUSING â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      async function renderHousing() {
        pageContainer.innerHTML = skeleton();
        try {
          const [stats, housings] = await Promise.all([
            window.API.getHousingStats(),
            window.API.getHousingList()
          ]);
          var housingsHtml = housings.map(function (h) {
            var occRate = Math.round(h.currentOcc / h.maxOcc * 100);
            var totalUtil = h.elecAmt + h.waterAmt + h.gasAmt + h.internet;
            var occColor = occRate >= 100 ? 'var(--status-success)' : occRate >= 50 ? 'var(--status-warning)' : 'var(--status-danger)';
            var pillCls = occRate >= 100 ? 'ok' : occRate >= 50 ? 'warning' : 'pending';
            var gasHtml = h.gasAmt > 0 ? '<div style="font-size:11px;color:var(--text-secondary)">ê°€ìŠ¤</div><div class="cell-mono" style="font-size:11px;text-align:right">$' + h.gasAmt + '</div>' : '';
            var residentsHtml = h.residents.map(function (r) { return '<span class="tag">' + r + '</span>'; }).join('');
            return '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-buildings"></i> ' + h.building + ' â€” ' + h.unit + '</div><span class="status-pill ' + pillCls + '">' + h.currentOcc + '/' + h.maxOcc + 'ëª…</span></div>' +
              '<div class="panel-body padded"><div style="font-size:11px;color:var(--text-tertiary);margin-bottom:10px">' + h.address + '</div>' +
              '<div class="progress-wrapper" style="margin-bottom:14px"><div class="progress-bar"><div class="progress-fill" style="width:' + occRate + '%;background:' + occColor + '"></div></div><div class="progress-text cell-primary">' + occRate + '%</div></div>' +
              '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">' +
              '<div style="font-size:11px;color:var(--text-secondary)">ì›” ìž„ëŒ€ë£Œ</div><div class="cell-mono" style="font-size:11px;text-align:right">$' + h.rent + '</div>' +
              '<div style="font-size:11px;color:var(--text-secondary)">ì „ê¸°</div><div class="cell-mono" style="font-size:11px;text-align:right">$' + h.elecAmt + ' (ë‚©ë¶€ì¼: ' + h.elecDue + 'ì¼)</div>' +
              '<div style="font-size:11px;color:var(--text-secondary)">ìˆ˜ë„</div><div class="cell-mono" style="font-size:11px;text-align:right">$' + h.waterAmt + '</div>' +
              gasHtml +
              '<div style="font-size:11px;color:var(--text-secondary)">ì¸í„°ë„·</div><div class="cell-mono" style="font-size:11px;text-align:right">$' + h.internet + '</div>' +
              '<div style="font-size:11px;font-weight:700;color:var(--text-primary)">ì›” í•©ê³„</div><div class="cell-mono" style="font-size:11px;text-align:right;font-weight:700">$' + (h.rent + totalUtil).toLocaleString() + '</div>' +
              '</div>' +
              '<div style="font-size:10px;font-weight:700;color:var(--text-tertiary);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">ìž…ì£¼ìž</div>' +
              '<div style="display:flex;flex-wrap:wrap;gap:4px">' + residentsHtml + '</div>' +
              '</div></div>';
          }).join('');

          pageContainer.innerHTML =
            '<div class="header-section"><div><h1 class="page-title">ìˆ™ì†Œ ê´€ë¦¬</h1><p class="page-subtitle">ìˆ™ì†Œë³„ ìž…ì£¼í˜„í™© Â· ìœ í‹¸ë¦¬í‹° ë‚©ë¶€ ì¶”ì  Â· ìˆ˜ë¦¬ ìš”ì²­</p></div>' +
            '<div class="action-row"><button class="btn-secondary" onclick="window.print()"><i class="ph ph-export"></i> í˜„í™© ì¶œë ¥</button><button class="btn-primary" onclick="openUniversalScanner(\'HOUSING\', \'ìˆ™ì†Œ ë ŒíŠ¸/ë¦¬ìŠ¤ ê³„ì•½ì„œ\')"><i class="ph ph-scan"></i> AI ìˆ™ì†Œ ë“±ë¡</button><button class="btn-primary" style="background:var(--status-warning); color:#000;" onclick="openNfcAssignModal(\'HOUSING\')"><i class="ph ph-identification-card"></i> NFC ìˆ™ì†Œ ë°°ì •</button></div></div>' +
            '<div class="kpi-row" style="grid-template-columns:repeat(5,1fr)">' +
            '<div class="kpi-card"><div class="kpi-label">ìž…ì£¼ìœ¨</div><div class="kpi-value">' + stats.occupancyRate + '%</div><div class="kpi-meta"><span style="color:var(--text-secondary)">' + stats.currentOcc + ' / ' + stats.totalCapacity + 'ëª…</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ì›” ìž„ëŒ€ë¹„</div><div class="kpi-value">$' + stats.monthlyRentTotal.toLocaleString() + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">' + stats.totalUnits + 'ê°œ ìœ ë‹›</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ì›” ìœ í‹¸ë¹„</div><div class="kpi-value">$' + stats.monthlyUtilTotal.toLocaleString() + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">ì „ê¸°+ìˆ˜ë„+ê°€ìŠ¤+ì¸í„°ë„·</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ë‚©ë¶€ìž„ë°•</div><div class="kpi-value" style="color:var(--status-warning)">' + stats.utilPayingDueSoon + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">7ì¼ ì´ë‚´</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ë¯¸ì²˜ë¦¬ ìˆ˜ë¦¬</div><div class="kpi-value" style="color:var(--status-danger)">' + stats.pendingIssues + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">ì²˜ë¦¬ í•„ìš”</span></div></div>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">' + housingsHtml + '</div>';
        } catch (err) { renderError('ìˆ™ì†Œ ë°ì´í„° ë¡œë”© ì‹¤íŒ¨'); console.error(err); }
      }

      // â”€â”€ FLIGHTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      async function renderFlights() {
        pageContainer.innerHTML = skeleton();
        try {
          var flights = await window.API.getFlightList();
          var incoming = flights.filter(function (f) { return f.direction === 'ìž…êµ­'; });
          var outgoing = flights.filter(function (f) { return f.direction === 'ê·€êµ­'; });
          var pickupCount = flights.filter(function (f) { return f.needPickup; }).length;

          var flightsHtml = flights.map(function (f) {
            var dirCls = f.direction === 'ìž…êµ­' ? 'ok' : 'pending';
            var pickupHtml = f.needPickup ? '<span class="status-pill warning">í•„ìš”: ' + (f.pickupBy || 'ë¯¸ë°°ì •') + '</span>' : '<span class="status-pill ok">ë¶ˆí•„ìš”</span>';
            var housingHtml = f.housingReady ? '<span class="status-pill ok">ì™„ë£Œ</span>' : '<span class="status-pill critical">ë¯¸ë°°ì •</span>';
            return '<tr><td class="cell-mono">' + f.id + '</td><td class="cell-primary">' + f.name + '</td><td><span class="status-pill ' + dirCls + '">' + f.direction + '</span></td><td class="cell-mono">' + f.from + ' â†’ ' + f.to + '</td><td class="cell-mono">' + f.depDateTime + '</td><td>' + f.airline + '</td><td class="cell-mono">' + f.pnr + '</td><td>' + pickupHtml + '</td><td>' + housingHtml + '</td><td>' + statusPill(f.status) + '</td></tr>';
          }).join('');

          pageContainer.innerHTML =
            '<div class="header-section"><div><h1 class="page-title">í•­ê³µê¶Œ ê´€ë¦¬</h1><p class="page-subtitle">ìž…ì¶œêµ­ ìŠ¤ì¼€ì¤„ Â· ê³µí•­ í”½ì—… ë°°ì • Â· ìˆ™ì†Œ ì‚¬ì „ ë°°ì • í™•ì¸</p></div>' +
            '<div class="action-row"><button class="btn-secondary" onclick="window.print()"><i class="ph ph-printer"></i> ì¼ì •í‘œ ì¶œë ¥</button><button class="btn-primary" onclick="openUniversalScanner(\'FLIGHTS\', \'ë‹¨ì¼/ë‹¨ì²´ E-Ticket í‘œ\')"><i class="ph ph-scan"></i> AI í•­ê³µê¶Œ ëª…ë‹¨ ë“±ë¡</button></div></div>' +
            '<div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">' +
            '<div class="kpi-card"><div class="kpi-label">ì „ì²´ ì˜ˆì •</div><div class="kpi-value">' + flights.length + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">ìž…/ì¶œêµ­ í•©ì‚°</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ìž…êµ­ ì˜ˆì •</div><div class="kpi-value" style="color:var(--status-success)">' + incoming.length + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">ì‹ ê·œ/ë³µê·€</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ê·€êµ­ ì˜ˆì •</div><div class="kpi-value" style="color:var(--brand-primary)">' + outgoing.length + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">ê·€êµ­/íŒŒê²¬ì¢…ë£Œ</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">í”½ì—… í•„ìš”</div><div class="kpi-value" style="color:var(--status-warning)">' + pickupCount + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">í”½ì—… ë°°ì • í•„ìš”</span></div></div>' +
            '</div>' +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-airplane"></i> í•­ê³µ ì¼ì • ì „ì²´</div></div>' +
            '<div class="panel-body"><table class="data-table"><thead><tr><th>ì˜ˆì•½ID</th><th>ì„±ëª…</th><th>ë°©í–¥</th><th>êµ¬ê°„</th><th>ì¶œë°œì¼ì‹œ</th><th>í•­ê³µì‚¬</th><th>PNR</th><th>í”½ì—…</th><th>ìˆ™ì†Œë°°ì •</th><th>ìƒíƒœ</th></tr></thead><tbody>' + flightsHtml + '</tbody></table></div></div>';
        } catch (err) { renderError('í•­ê³µê¶Œ ë°ì´í„° ë¡œë”© ì‹¤íŒ¨'); console.error(err); }
      }

      // â”€â”€ OFFICE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      async function renderOffice() {
        pageContainer.innerHTML = skeleton();
        try {
          var supplies = await window.API.getOfficeSupplies();
          var reorderList = supplies.filter(function (s) { return s.reorder; });
          var categories = [];
          supplies.forEach(function (s) { if (categories.indexOf(s.category) === -1) categories.push(s.category); });
          var estCost = reorderList.reduce(function (a, s) { return a + s.unitPrice * (s.minQty - s.qty + 2); }, 0);

          var suppliesHtml = supplies.map(function (s) {
            var qtyStyle = s.reorder ? 'color:var(--status-danger);font-weight:700' : '';
            var statHtml = s.reorder ? '<span class="status-pill critical">ì£¼ë¬¸í•„ìš”</span>' : '<span class="status-pill ok">ì¶©ë¶„</span>';
            return '<tr><td class="cell-mono">' + s.id + '</td><td class="cell-primary">' + s.name + '</td><td><span class="tag">' + s.category + '</span></td><td class="cell-mono" style="' + qtyStyle + '">' + s.qty + '</td><td class="cell-mono">' + s.minQty + '</td><td>' + s.location + '</td><td class="cell-mono">' + s.lastRestock + '</td><td class="cell-mono">$' + s.unitPrice + '</td><td>' + statHtml + '</td></tr>';
          }).join('');

          var reorderAlertHtml = '';
          if (reorderList.length > 0) {
            var reorderRows = reorderList.map(function (s) {
              return '<tr><td class="cell-mono">' + s.id + '</td><td class="cell-primary" style="color:var(--status-danger)">' + s.name + '</td><td><span class="tag">' + s.category + '</span></td><td class="cell-mono" style="color:var(--status-danger);font-weight:700">' + s.qty + '</td><td class="cell-mono">' + s.minQty + '</td><td>' + s.location + '</td><td class="cell-mono">$' + s.unitPrice + '</td></tr>';
            }).join('');
            reorderAlertHtml = '<div class="panel" style="border-color:var(--status-danger);margin-bottom:16px"><div class="panel-header"><div class="panel-title" style="color:var(--status-danger)"><i class="ph ph-warning"></i> ìž¬ì£¼ë¬¸ í•„ìš” (' + reorderList.length + 'ê±´)</div></div><div class="panel-body"><table class="data-table"><thead><tr><th>ID</th><th>í’ˆëª…</th><th>ì¹´í…Œê³ ë¦¬</th><th>í˜„ìž¬ìˆ˜ëŸ‰</th><th>ìµœì†Œìˆ˜ëŸ‰</th><th>ë³´ê´€ìœ„ì¹˜</th><th>ë‹¨ê°€</th></tr></thead><tbody>' + reorderRows + '</tbody></table></div></div>';
          }

          pageContainer.innerHTML =
            '<div class="header-section"><div><h1 class="page-title">í˜„ìž¥ì‚¬ë¬´ì‹¤ ë¹„í’ˆ ê´€ë¦¬</h1><p class="page-subtitle">ìž¬ê³  í˜„í™© Â· ìž¬ì£¼ë¬¸ í•„ìš” í•­ëª© Â· ì¹´í…Œê³ ë¦¬ë³„ ë¶„ë¥˜</p></div>' +
            '<div class="action-row"><button class="btn-secondary" onclick="window.print()"><i class="ph ph-printer"></i> ìž¬ê³ í‘œ ì¶œë ¥</button><button class="btn-primary" onclick="openUniversalScanner(\'OFFICE\', \'ë¹„í’ˆ/êµ¬ë§¤ ì˜ìˆ˜ì¦\')"><i class="ph ph-scan"></i> ì˜ìˆ˜ì¦ ê¸°ë°˜ AI êµ¬ë§¤ ë“±ë¡</button></div></div>' +
            '<div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">' +
            '<div class="kpi-card"><div class="kpi-label">ì „ì²´ í’ˆëª©</div><div class="kpi-value">' + supplies.length + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">ë“±ë¡ í•­ëª©</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ìž¬ì£¼ë¬¸ í•„ìš”</div><div class="kpi-value" style="color:var(--status-danger)">' + reorderList.length + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">ìµœì†Œìˆ˜ëŸ‰ ì´í•˜</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ì¹´í…Œê³ ë¦¬</div><div class="kpi-value">' + categories.length + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">' + categories.join(' Â· ') + '</span></div></div>' +
            '<div class="kpi-card"><div class="kpi-label">ìž¬ì£¼ë¬¸ ì˜ˆìƒë¹„ìš©</div><div class="kpi-value">$' + estCost + '</div><div class="kpi-meta"><span style="color:var(--text-secondary)">ì¶”ì • êµ¬ë§¤ë¹„</span></div></div>' +
            '</div>' +
            reorderAlertHtml +
            '<div class="panel"><div class="panel-header"><div class="panel-title"><i class="ph ph-archive"></i> ì „ì²´ ìž¬ê³  í˜„í™©</div></div>' +
            '<div class="panel-body"><table class="data-table"><thead><tr><th>ID</th><th>í’ˆëª…</th><th>ì¹´í…Œê³ ë¦¬</th><th>í˜„ìž¬ìˆ˜ëŸ‰</th><th>ìµœì†Œìˆ˜ëŸ‰</th><th>ë³´ê´€ìœ„ì¹˜</th><th>ìµœê·¼ë³´ì¶©ì¼</th><th>ë‹¨ê°€</th><th>ìƒíƒœ</th></tr></thead><tbody>' + suppliesHtml + '</tbody></table></div></div>';
        } catch (err) { renderError('ë¹„í’ˆ ë°ì´í„° ë¡œë”© ì‹¤íŒ¨'); console.error(err); }
      }

      // â”€â”€ ì´ˆê¸°í™” â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      pageContainer.style.transition = 'opacity 0.15s';
      loadView('dashboard');

      // URL team_code 파라미터 체크 및 QR 출퇴근 자동 진입
      const urlParams = new URLSearchParams(window.location.search);
      const teamCodeParam = urlParams.get('team_code');
      if (teamCodeParam) {
        const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({ path: newUrl }, '', newUrl);
        setTimeout(function() {
          if (typeof window.openMyCommuteModal === 'function') {
            window.openMyCommuteModal(teamCodeParam);
          }
        }, 500);
      }
    });

    // â”€â”€ ë¬¸ì„œ ëª¨ë‹¬ ì œì–´ â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    function showProjectDoc() {
      var modal = document.getElementById('doc-modal');
      modal.classList.add('active');
    }
    function closeProjectDoc() {
      document.getElementById('doc-modal').classList.remove('active');
    }

    window.showToast = function (msg, isError) {
      var toast = document.createElement('div');
      toast.innerText = msg;
      toast.style.position = 'fixed';
      toast.style.top = '20px';
      toast.style.left = '50%';
      toast.style.transform = 'translateX(-50%)';
      toast.style.padding = '12px 24px';
      toast.style.background = isError ? 'var(--status-danger)' : 'var(--status-success)';
      toast.style.color = '#fff';
      toast.style.borderRadius = '8px';
      toast.style.zIndex = '9999';
      toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
      toast.style.transition = 'opacity 0.3s ease';
      document.body.appendChild(toast);
      setTimeout(function () {
        toast.style.opacity = '0';
        setTimeout(function () { toast.remove(); }, 300);
      }, 4000);
    };



  </script>

  <!-- Document Modal UI -->
  <div id="doc-modal" class="doc-modal">
    <div class="doc-modal-content">
      <div class="doc-modal-header">
        <div class="doc-modal-title"><i class="ph ph-file-text" style="color:var(--brand-primary);font-size:20px"></i>
          LGES AZ Plant ìž¥ë¹„ì„¤ì¹˜ ë§ˆìŠ¤í„° ì‹¤í–‰ê³„íšì„œ</div>
        <button class="doc-modal-close" onclick="closeProjectDoc()"><i class="ph ph-x"></i></button>
      </div>
      <div class="doc-modal-body md-content" id="doc-modal-body">
        <!-- Markdown Data -->
        <h1>LGES AZ Plant ë°°í„°ë¦¬ ì œì¡° ìž¥ë¹„ ì„¤ì¹˜ ë§ˆìŠ¤í„° ì‹¤í–‰ê³„íšì„œ (Project Execution Plan)</h1>

        <h2>1. í”„ë¡œì íŠ¸ ê°œìš” (Project Overview)</h2>
        <p>ë³¸ ì‹¤í–‰ê³„íšì„œëŠ” LG Energy Solution (LGES) AZ Plantì˜ ë°°í„°ë¦¬ ì œì¡° ë¼ì¸ ìž¥ë¹„ ë°˜ìž…, ì¡°ë¦½, ì„¤ì¹˜ ë° ì‹œìš´ì „ì„ ìœ„í•œ ë§ˆìŠ¤í„° ê³„íšì„œìž…ë‹ˆë‹¤. NFF 46-Series ìž¥ë¹„
          ìŠ¤íŽ™ê³¼ ê° ë²¤ë”ì˜ ì„¤ì¹˜ ë§¤ë‰´ì–¼(PPTX/PDF) í…ìŠ¤íŠ¸ ë¶„ì„ ë°ì´í„°ë¥¼ ë°”íƒ•ìœ¼ë¡œ ì „ì²´ ì„¤ì¹˜ ìž‘ì—…ì„ í†µì œí•˜ê³  ë‹¨ê³„ë³„ ì‹¤í–‰ ë°©ì•ˆì„ í™•ë¦½í•˜ì—¬ ì•ˆì „í•˜ê³  ì²´ê³„ì ì¸ ë¼ì¸ ì…‹ì—…ì„ ëª©í‘œë¡œ í•©ë‹ˆë‹¤.</p>

        <h3>1.1 ëŒ€ìƒ ê³µì • ë° ë¼ì¸ êµ¬ì„± (NFF 46-Series Process)</h3>
        <p>ì„¤ì¹˜ ëŒ€ìƒì€ í¬ê²Œ 3ê°€ì§€ ì£¼ìš” ë°°í„°ë¦¬ ì œì¡° ê³µì •ìœ¼ë¡œ ë‚˜ë‰˜ë©°, ê° ê³µì •ë³„ í•˜ìœ„ ìž¥ë¹„êµ°ê³¼ í™˜ê²½ ì œì–´ ê¸°ê¸°ë¡œ ì„¸ë¶„í™”ë©ë‹ˆë‹¤.</p>
        <ul>
          <li><strong>100. Winder (ê¶Œì·¨ ê³µì •)</strong>: ì ¤ë¦¬ë¡¤(Jelly Roll) ì œì¡° ì˜ì—­. AZ #1 ë¼ì¸ ê¸°ì¤€ 12ëŒ€ì˜ Winder Machine (#1-1 ~
            #1-12) êµ¬ì„±.</li>
          <li><strong>200. Assembly (ì¡°ë¦½ ê³µì •)</strong>: NFF Cell Assembly Line (Zone 1 & Zone 2)</li>
          <li><strong>300. Formation (í™œì„±í™” ê³µì •)</strong>: ì „ê·¹ í™œì„±í™”, ë””ê°œì‹± ë° í’ˆì§ˆ ê²€ì‚¬ ì˜ì—­</li>
        </ul>

        <hr>

        <h2>2. ì‚¬ì „ ì¤€ë¹„ ë° EHS (í™˜ê²½Â·ë³´ê±´Â·ì•ˆì „)</h2>
        <p>ëª¨ë“  ìž¥ë¹„ ë°˜ìž… ë° ì„¤ì¹˜ëŠ” í˜„ìž¥ ì§€ì • ì•ˆì „ìˆ˜ì¹™(ESAZ Safety Manual) ë° ìŠ¹ì¸ëœ ê¸°ë³¸ ì•ˆì „ ì¤€ìˆ˜(Basic Safety Prevention) ì§€ì¹¨ì— ê¸°ë°˜í•˜ì—¬ ìˆ˜í–‰ë©ë‹ˆë‹¤.</p>

        <h3>2.1 ì‚¬ì´íŠ¸ ê³µí†µ ì² ì¹™ (Basic Safety Prevention)</h3>
        <ul>
          <li><strong>ì˜ë¬´ êµìœ¡</strong>: êµìœ¡(training) ë¯¸ì´ìˆ˜ìžì˜ ìž¥ë¹„ ì»¨íŠ¸ë¡¤ ì ˆëŒ€ ê¸ˆì§€.</li>
          <li><strong>ë³´í˜¸êµ¬ ì°©ìš©</strong>: ì„¤ì¹˜ êµ¬ì—­ ë‚´ í•˜ë“œí–‡, ì•ˆì „í™”, ë³´ì•ˆê²½ ì°©ìš© í•„ìˆ˜.</li>
          <li><strong>Interlock í†µì œ</strong>: ì•ˆì „ìš© ì „ìž ì¸í„°ë½ ìž¥ì¹˜ì˜ ìž„ì˜ í•´ì œ ê¸ˆì§€ (ìœ ì§€ë³´ìˆ˜ ëª©ì  ì œì™¸).</li>
          <li><strong>P&P Gripper ì ‘ê·¼ ê¸ˆì§€</strong>: ìž‘ë™ ì¤‘ì¸ ì²´ì¸, ì»¨ë² ì´ì–´ ì˜ì—­ ë¬´ë‹¨ íˆ¬ìž… ê¸ˆì§€.</li>
        </ul>

        <h3>2.2 ìž¥ë¹„ ì ‘ì§€ ë° ì „ì› ì°¨ë‹¨ í†µì œ</h3>
        <ul>
          <li><strong>ì ‘ì§€(Grounding)</strong>: ê¸°ê³„ ê¸°êµ¬ ë° ì „ìž ì¸¡ì • ìž¥ì¹˜ì˜ ì ‘ì§€ ìƒíƒœ í•­ì‹œ ìœ ì§€.</li>
          <li><strong>LOTO (Lockout/Tagout)</strong>: ëª¨í„° êµì²´ ë˜ëŠ” ì „ìž¥ ì»¤ë„¥í„° ê²°í•© ì‹œ ì „ì›(Power) ì°¨ë‹¨ ìƒíƒœ ì§‘ì¤‘ í™•ì¸.</li>
        </ul>

        <h3>2.3 ì¤‘ìž¥ë¹„ ì•ˆì „ í†µì œ (Heavy Equipment EHS)</h3>
        <ul>
          <li><strong>ì§€ê²Œì°¨ ë° í…”ë ˆí•¸ë“¤ëŸ¬ ìš´ìš©</strong>: ì‹¤ë‚´/ì™¸ í•˜ì—­ ë° ì´ì†¡ ì‹œ ì „ë‹´ ìŠ¤íŒŸí„°(Spotter) 1ì¸ í•„ìˆ˜ ë°°ì¹˜.</li>
          <li><strong>í¬ë ˆì¸ (Mobile Crane)</strong>: ì¶œìž… í†µì œ ë°”ë¦¬ì¼€ì´ë“œ í™•ë¦½ ë° ê·œì • ì‹ í˜¸ìˆ˜ ë°°ì¹˜, í’ì†ê³„(10m/s) í™•ì¸.</li>
        </ul>

        <hr>

        <h2>3. ìž¥ë¹„ ë°˜ìž… ë° ì–‘ì¤‘ ê³„íš (Move-In & Rigging Plan)</h2>

        <h3>3.1 íŒ©í† ë¦¬(Factory) ê²Œì´íŠ¸ ì§„ìž… ê·œì •</h3>
        <ul>
          <li><strong>Zone 1 ìž¥ë¹„êµ°</strong>: í­ 4.5m ì´ìƒ. <strong>Gate 7 (4.5m x 4.5m)</strong> í™œìš©.</li>
          <li><strong>Zone 2 ìž¥ë¹„êµ°</strong>: í­ 4.0m ì´í•˜. <strong>Gate 8 (4.0m x 3.5m)</strong> í™œìš©.</li>
        </ul>

        <h3>3.2 íˆ¬ìž… ì˜ˆìƒ ì¤‘ìž¥ë¹„ (Heavy Equipment)</h3>
        <ul>
          <li><strong>Winder</strong>: ì´ˆì •ë°€ ì§„ë™ í†µì œë¥¼ ìœ„í•œ 10~20í†¤ê¸‰ ì—ì–´ìºìŠ¤í„°(Air Casters) ë° ë¨¸ì‹  ìŠ¤ì¼€ì´íŠ¸ ì ìš©.</li>
          <li><strong>Assembly</strong>: ì¢ì€ êµ¬ì—­ì—ì„œì˜ ì„¸ë°€í•œ ë¶€í’ˆ ì¡°ë¦½ì„ ìœ„í•œ ì „ë™ ìŠ¤íƒœì»¤ ë° ì „ë™ ì‹œì € ë¦¬í”„íŠ¸ ìš´ìš©.</li>
        </ul>

        <hr>

        <h2>4. ê³µì •ë³„ ì„¸ë¶€ ì„¤ì¹˜ ê³„íš (Installation Plan by Process)</h2>

        <h3>4.1 [STAGE 1] Winder ê³µì • </h3>
        <ul>
          <li><strong>ì£¼ìš” ì„¤ì¹˜ ì‹œí€€ìŠ¤</strong>: Lay-down Area ìˆ˜ë ¹ âž” Air Caster ì´ë™ âž” Docking âž” Leveling âž” Anchoring</li>
          <li><strong>ê¶Œì·¨ê¸° ê¸°ê³„ ì—°ê²°</strong>: <strong>ìƒ¤í”„íŠ¸ í•€(Shaft Pin)ê³¼ ì»¤í”Œë§(Coupling) ê²°í•©</strong>ì„ ì„ í–‰í•˜ì—¬ ë™ë ¥ ì¶• ì¼ì¹˜.</li>
          <li><strong>ë ˆë²¨ë§ ì˜¤ì°¨ ê¸°ì¤€</strong>: 1ì°¨ Rough(Â±5mm) âž” 2ì°¨ Final(<strong>Â±0.5mm</strong>) ì´ë‚´ ì •ë°€ êµì •.</li>
        </ul>

        <h3>4.2 [STAGE 2] Assembly ê³µì • (NFF Cell Assembly Line)</h3>
        <ul>
          <li><strong>Zone 1 ê¸°ì¤€ ì„¤ë¹„</strong>: <strong>[CAN LOADER]</strong> ë¨¸ì‹ ì„ ì¶•(Datum)ìœ¼ë¡œ ì§€ì •.</li>
          <li><strong>Zone 2 ê¸°ì¤€ ì„¤ë¹„</strong>: <strong>[IOU]</strong> ë¨¸ì‹ ì„ ì¶•(Datum)ìœ¼ë¡œ ì§€ì •, ì¢Œ/ìš° ìˆœì°¨ ì¡°ë¦½ ì „ê°œ.</li>
          <li><strong>ì„¤ì¹˜ ìœ ì˜ì‚¬í•­</strong>: 1, 2ì°¨ ë ˆë²¨ë§ ê³µì°¨ê°€ ì™„ë²½ížˆ ì¸¡ì •ëœ ì§í›„ì—ë§Œ ë°”ë‹¥ íƒ€ê³µ ë° íŒ©ë‹¤ìš´(Anchoring) ì§„í–‰.</li>
        </ul>

        <hr>

        <h2>5. ê²€ì‚¬ ë° ì „ê¸° ì—°ë™ (Interconnection & Inspection)</h2>

        <h3>5.1 ì „ê¸°(Electrical) ì—°ë™ íƒ€ê²Ÿ</h3>
        <ul>
          <li>Turn Table ìƒ/í•˜ë‹¨ ì¼€ì´ë¸” ë° Eject Conveyorì˜ EtherCAT, Limit Sensor í†µì‹ ì„  ì²´ê²°.</li>
        </ul>

        <h3>5.2 ë²¤ë” ë§ˆê° ì´ê´€ ì ˆì°¨</h3>
        <p>ëª¨ë“  ì„¤ë¹„ê°€ ì •ìƒ ê°€ë™ ë²”ìœ„(Â±0.5mm) ë‚´ì— ë“¤ì–´ì˜¤ë©´, LGES íŒŒê²¬ ì—”ì§€ë‹ˆì–´ ë° ë²¤ë” í•©ë™ìœ¼ë¡œ Punch Item ë³´ì™„ í›„ ê²€ìˆ˜ ë° ì´ê´€(Hand-over) ìŠ¹ì¸ì„ ì§„í–‰í•©ë‹ˆë‹¤.</p>
      </div>
    </div>
  </div>

  <!-- Personnel Integrated Card Modal -->
  <div class="doc-modal" id="personnel-card-modal" style="z-index:9998;">
    <div class="doc-modal-content"
      style="max-width:560px; background:var(--bg-panel); padding:0; border-radius:var(--radius-lg); overflow:hidden;">
      <div
        style="display:flex;justify-content:space-between;align-items:center;padding:18px 24px;border-bottom:1px solid var(--border-strong);background:var(--bg-surface-elevated);">
        <h3 style="margin:0;font-size:17px;display:flex;align-items:center;gap:8px;"><i
            class="ph ph-identification-card" style="color:var(--brand-primary);font-size:22px;"></i> ì¸ì› í†µí•© ì¹´ë“œ</h3>
        <button class="icon-btn" onclick="document.getElementById('personnel-card-modal').classList.remove('active')"><i
            class="ph ph-x"></i></button>
      </div>
      <div id="personnel-card-body" style="padding:24px;overflow-y:auto;max-height:75vh;">
        <div style="text-align:center;padding:40px;color:var(--text-tertiary)"><i class="ph ph-spinner ph-spin"
            style="font-size:32px;"></i><br>ë¡œë”©ì¤‘...</div>
      </div>
      <div
        style="padding:14px 24px;border-top:1px solid var(--border-subtle);display:flex;gap:10px;justify-content:flex-end;background:var(--bg-surface);">
        <select id="personnel-status-select"
          style="padding:7px 12px;border-radius:var(--radius-md);border:1px solid var(--border-strong);background:var(--bg-body);color:var(--text-primary);font-size:13px;">
          <option value="íŒŒê²¬ì¤‘">ðŸŸ¢ íŒŒê²¬ì¤‘</option>
          <option value="ê·€êµ­">ðŸŸ¡ ê·€êµ­</option>
          <option value="í‡´ì‚¬">ðŸ”´ í‡´ì‚¬</option>
        </select>
        <button class="btn-primary" id="btn-personnel-status-save" onclick="window.savePersonnelStatus()"><i
            class="ph ph-floppy-disk"></i> ìƒíƒœ ì €ìž¥</button>
        <button class="btn-secondary"
          onclick="document.getElementById('personnel-card-modal').classList.remove('active')">ë‹«ê¸°</button>
      </div>
    </div>
  </div>

  <!-- Quick Action Command Center Modal -->
  <div class="doc-modal" id="quick-action-modal" style="z-index:10000;">
    <div class="doc-modal-content"
      style="max-width: 540px; background:var(--bg-panel); padding: 24px; border-radius:var(--radius-lg); position:relative;">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid var(--border-strong); padding-bottom:12px;">
        <h3 style="margin:0; font-size:18px; display:flex; align-items:center; gap:8px;"><i class="ph ph-lightning"
            style="color:var(--brand-primary); font-size:24px;"></i> í€µ ì•¡ì…˜ ì»¤ë§¨ë“œ ì„¼í„°</h3>
        <button class="icon-btn" onclick="document.getElementById('quick-action-modal').classList.remove('active')"><i
            class="ph ph-x"></i></button>
      </div>

      <div
        style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:flex; align-items:center; gap:6px;">
        <i class="ph ph-info"></i> ëŒ€ì‹œë³´ë“œì—ì„œ ì‹œìŠ¤í…œì˜ ê°€ìž¥ í•µì‹¬ì ì¸ ê¸°ëŠ¥ì„ ì¦‰ì‹œ ì‹¤í–‰í•©ë‹ˆë‹¤.
      </div>

      <div class="qa-grid">
        <div class="qa-card"
          onclick="document.getElementById('quick-action-modal').classList.remove('active'); window.goToView('command')">
          <div class="qa-icon project"><i class="ph ph-command"></i></div>
          <div class="qa-title">AI í˜„ìž¥ ì§€íœ˜ì‹¤</div>
          <div class="qa-desc">ì˜¤ëŠ˜ ê²°ì •í•  ì¼ê³¼<br>ê³µì§œ ìž‘ì—… ìœ„í—˜ í•œ ë²ˆì— í™•ì¸</div>
        </div>

        <div class="qa-card"
          onclick="document.getElementById('quick-action-modal').classList.remove('active'); openUniversalScanner()">
          <div class="qa-icon scanner"><i class="ph ph-scan"></i></div>
          <div class="qa-title">AI ìŠ¤ë§ˆíŠ¸ ìŠ¤ìºë„ˆ</div>
          <div class="qa-desc">ì˜ìˆ˜ì¦ ë° ê°ì¢… ì„œë¥˜ ì´¬ì˜<br>Gemini ë©€í‹°ëª¨ë‹¬ ìžë™ìž…ë ¥</div>
        </div>

        <div class="qa-card"
          onclick="document.getElementById('quick-action-modal').classList.remove('active'); promptNewProject()">
          <div class="qa-icon project"><i class="ph ph-kanban"></i></div>
          <div class="qa-title">ì‹ ê·œ í˜„ìž¥ ê°œì„¤</div>
          <div class="qa-desc">ìƒˆë¡œìš´ PM ì±…ìž„ìž ë°°ì • ë°<br>ì‹ ê·œ í”„ë¡œì íŠ¸ ì´ˆê¸° ì…‹ì—…</div>
        </div>

        <div class="qa-card"
          onclick="document.getElementById('quick-action-modal').classList.remove('active'); promptActionItem()">
          <div class="qa-icon action"><i class="ph ph-warning-circle"></i></div>
          <div class="qa-title">ìž‘ì—… ì§€ì‹œ í•˜ë‹¬</div>
          <div class="qa-desc">íŠ¹ì • í˜„ìž¥ ì¸ì›ì—ê²Œ ê¸´ê¸‰<br>Action Item ìˆ˜ë™ ë°œí–‰</div>
        </div>

        <div class="qa-card"
          onclick="document.getElementById('quick-action-modal').classList.remove('active'); openGoogleForm('hr')">
          <div class="qa-icon form"><i class="ph ph-user-plus"></i></div>
          <div class="qa-title">ì‹ ê·œ ì¸ì› ë“±ë¡</div>
          <div class="qa-desc">ìƒˆë¡œìš´ ê·¼ë¡œìž HR ì •ë³´<br>ê¸°ë³¸ ë°ì´í„° ìˆ˜ë™ ê¸°ìž… í¼</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Universal AI Scanner Modal -->
  <div class="doc-modal" id="ai-scanner-modal" style="z-index:9999;">
    <div class="doc-modal-content"
      style="max-width: 450px; background:var(--bg-panel); padding: 24px; border-radius:var(--radius-lg); position:relative;">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid var(--border-strong); padding-bottom:12px;">
        <h3 style="margin:0; font-size:18px; display:flex; align-items:center; gap:8px;"><i class="ph ph-scan"
            style="color:var(--brand-primary); font-size:24px;"></i> 스마트 문서 AI 스캐너</h3>
        <button class="icon-btn" onclick="closeUniversalScanner()"><i class="ph ph-x"></i></button>
      </div>
      <div style="font-size: 14px; color: var(--text-secondary); margin-bottom: 20px; line-height:1.5;">
        스마트폰으로 촬영한 문서를 업로드하거나 실시간 카메라로 촬영하세요.<br>Gemini AI 엔진이 사진을 자동으로 판독하여 장부에 즉시 등록합니다.
      </div>

      <div style="margin-bottom: 20px;">
        <label
          style="display:block; font-size:12px; color:var(--text-secondary); margin-bottom:6px; font-weight:600;">스캔 문서 종류 선택</label>
        <select id="ai-scan-target-category"
          style="width:100%; padding: 10px; border-radius: var(--radius-md); border: 1px solid var(--border-strong); background: var(--bg-body); color: var(--text-primary); font-size:14px; outline:none;">
          <option value="EXPENSE">비용 영수증 (재무/결의)</option>
          <option value="VEHICLE">차량 렌트 및 보험 서류</option>
          <option value="HOUSING">부동산 임대/렌트 계약서</option>
          <option value="FLIGHTS">항공 E-Ticket / 보딩패스</option>
          <option value="OFFICE">현장 비품/구매 인보이스</option>
          <option value="VENDORS">거래처 명함/사업자등록증 스캔</option>
        </select>
      </div>

      <!-- Toggle Scanner Mode -->
      <div style="display: flex; gap: 8px; margin-bottom: 20px;">
        <button id="btn-toggle-upload" class="btn-primary" onclick="switchScannerMode('upload')" style="flex: 1; justify-content: center; height: 38px; border-radius: 6px;">
          <i class="ph ph-upload-simple"></i> 파일 업로드 (Upload)
        </button>
        <button id="btn-toggle-camera" class="btn-secondary" onclick="switchScannerMode('camera')" style="flex: 1; justify-content: center; height: 38px; border-radius: 6px;">
          <i class="ph ph-camera"></i> 실시간 카메라 (Webcam)
        </button>
      </div>

      <!-- Upload Mode Area -->
      <div id="ai-upload-area"
        style="border: 2px dashed var(--border-strong); border-radius: var(--radius-md); padding: 40px 20px; text-align: center; cursor: pointer; transition: 0.2s;"
        onmouseover="this.style.borderColor='var(--brand-primary)'"
        onmouseout="this.style.borderColor='var(--border-strong)'"
        onclick="document.getElementById('ai-file-input').click()">
        <i class="ph ph-upload-simple" style="font-size: 36px; color: var(--text-tertiary); margin-bottom: 12px;"></i>
        <div style="font-weight: 500; font-size:15px; color:var(--text-primary);">클릭하여 촬영 또는 사진 선택</div>
        <div style="font-size: 13px; color: var(--text-tertiary); margin-top: 6px;">지원: JPG, PNG, HEIC 등 이미지</div>
        <input type="file" id="ai-file-input" style="display: none;" accept="image/*"
          onchange="handleAiFileSelect(event)">
      </div>

      <!-- Camera Mode Area -->
      <div id="ai-camera-area" style="display: none; text-align: center; background: var(--bg-body); border-radius: var(--radius-md); padding: 12px; border: 1px solid var(--border-strong); margin-bottom: 20px;">
        <div style="position: relative; border-radius: var(--radius-sm); overflow: hidden; background: #000; aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center;">
          <video id="ai-video-stream" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
        </div>
        <button type="button" class="btn-primary" onclick="captureCameraFrame()" style="margin: 12px auto 0; justify-content: center; width: 100%; height: 38px; font-weight: 600; border-radius: 6px;">
          <i class="ph ph-camera" style="font-size: 16px;"></i> 사진 촬영 (Capture)
        </button>
      </div>

      <div id="ai-preview-container"
        style="display: none; margin-top: 16px; border: 1px solid var(--border-strong); border-radius: var(--radius-md); overflow: hidden; position: relative;">
        <img id="ai-image-preview" src=""
          style="width: 100%; height: 240px; object-fit: contain; background:#000; display: block;">
        <button class="icon-btn"
          style="position: absolute; top: 12px; right: 12px; background: rgba(0,0,0,0.6); color: #fff; width:36px; height:36px;"
          onclick="clearAiPreview()"><i class="ph ph-trash"></i></button>
      </div>

      <div id="ai-scan-loading"
        style="display: none; margin-top: 24px; text-align: center; padding: 20px; background: var(--bg-body); border-radius: var(--radius-md);">
        <i class="ph ph-spinner ph-spin" style="font-size: 32px; color: var(--brand-primary); margin-bottom: 12px;"></i>
        <div style="font-size: 14px; font-weight: 600; color:var(--text-primary);">AI가 문서를 해독 중입니다...</div>
        <div style="font-size: 12px; color: var(--text-tertiary); margin-top: 6px;">서류 종류에 따라 최대 10~15초가 소요됩니다.</div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:12px; margin-top: 24px;">
        <button class="btn-secondary" onclick="closeUniversalScanner()">취소</button>
        <button class="btn-primary" id="btn-ai-scan-submit" onclick="submitUniversalAiScan()"><i
            class="ph ph-magic-wand"></i> AI 분석 및 자동 기입</button>
      </div>
    </div>
  </div>

  <script>
    let currentAiBase64 = null;
    let currentAiMime = null;
    let _currentPersonnelUid = null;

    // â”€â”€â”€ ì¸ì› í†µí•© ì¹´ë“œ ì—´ê¸° â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    window.openPersonnelCard = function (uid) {
      _currentPersonnelUid = uid;
      var modal = document.getElementById('personnel-card-modal');
      var body = document.getElementById('personnel-card-body');
      body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-tertiary)"><i class="ph ph-spinner ph-spin" style="font-size:32px;display:block;margin-bottom:12px;"></i>ì¸ì› ë°ì´í„° ë¶ˆëŸ¬ì˜¤ëŠ” ì¤‘...</div>';
      modal.classList.add('active');

      if (typeof google === 'undefined') {
        body.innerHTML = '<div style="padding:20px;color:var(--text-tertiary)">ë¡œì»¬ í…ŒìŠ¤íŠ¸ ëª¨ë“œ: ì‹¤ì œ ë°ì´í„° ì¡°íšŒ ë¶ˆê°€</div>';
        return;
      }
      google.script.run
        .withSuccessHandler(function (result) {
          if (!result || !result.success) {
            body.innerHTML = '<div style="padding:20px;color:var(--status-danger)">âš ï¸ ' + (result && result.error ? result.error : 'ë°ì´í„° ì¡°íšŒ ì‹¤íŒ¨') + '</div>';
            return;
          }
          var p = result.person;
          var v = result.vehicle;
          var h = result.housing;
          var flights = result.flights || [];

          // ìƒíƒœ ë“œë¡­ë‹¤ìš´ ë™ê¸°í™”
          var sel = document.getElementById('personnel-status-select');
          if (sel) sel.value = p.workerStatus || 'íŒŒê²¬ì¤‘';

          // ë¹„ìž ë§Œë£Œ ê²½ê³  ìƒ‰ìƒ
          var visaColor = 'var(--text-primary)';
          if (p.visaExpiry && p.visaExpiry !== '-') {
            var vExp = new Date(p.visaExpiry);
            var now = new Date();
            if (vExp < now) visaColor = 'var(--status-danger)';
            else if (vExp < new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000)) visaColor = 'var(--status-warning)';
          }
          var wsColor = p.workerStatus === 'ê·€êµ­' ? 'var(--text-tertiary)' : p.workerStatus === 'í‡´ì‚¬' ? 'var(--status-danger)' : 'var(--status-success)';

          var flightsHtml = flights.length > 0
            ? flights.map(function (f) {
              return '<div style="display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border-subtle)">' +
                '<i class="ph ph-airplane-takeoff" style="color:var(--brand-primary)"></i>' +
                '<span class="cell-mono" style="font-size:12px">' + (f.depDateTime || '') + '</span>' +
                '<span>' + (f.from || '') + ' â†’ ' + (f.to || '') + '</span>' +
                '<span style="color:var(--text-tertiary);font-size:12px">' + (f.airline || '') + ' ' + (f.pnr || '') + '</span></div>';
            }).join('')
            : '<div style="color:var(--text-tertiary);font-size:13px;padding:8px 0">ë“±ë¡ëœ í•­ê³µ ì •ë³´ ì—†ìŒ</div>';

          body.innerHTML =
            // ê¸°ë³¸ ì •ë³´
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">' +
            _cardRow('ðŸ‘¤ ì˜ë¬¸ ì„±ëª…', p.nameEn) +
            _cardRow('ðŸ‡°ðŸ‡· í•œêµ­ì–´ ì´ë¦„', p.nameKr || '-') +
            _cardRow('ðŸ”– UID', '<code style="font-size:12px;background:var(--bg-base);padding:2px 6px;border-radius:4px">' + p.id + '</code>') +
            _cardRow('ðŸ¢ íšŒì‚¬', p.company) +
            _cardRow('ðŸ’¼ ì§ì¢…', p.role) +
            _cardRow('ðŸ“ í˜„ìž¥', p.site || '-') +
            '</div>' +
            '<hr style="border:none;border-top:1px solid var(--border-subtle);margin:12px 0">' +
            // ì—¬ê¶Œ / ë¹„ìž
            '<div style="font-size:12px;font-weight:700;color:var(--text-tertiary);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px">ì—¬ê¶Œ / ë¹„ìž</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px">' +
            _cardRow('ðŸ›‚ ì—¬ê¶Œë²ˆí˜¸', p.passport || '-') +
            _cardRow('ðŸŽ‚ ìƒë…„ì›”ì¼', p.birthday || '-') +
            _cardRow('ðŸ“‹ ë¹„ìžíƒ€ìž…', p.visa || '-') +
            _cardRow('ðŸ“… ë¹„ìžë§Œë£Œ', '<span style="color:' + visaColor + ';font-weight:600">' + (p.visaExpiry || '-') + '</span>') +
            _cardRow('ðŸŒ êµ­ì ', p.nationality || '-') +
            _cardRow('ðŸ“Š í˜„ìž¬ìƒíƒœ', '<span style="color:' + wsColor + ';font-weight:600">' + (p.workerStatus || 'íŒŒê²¬ì¤‘') + '</span>') +
            '</div>' +
            '<hr style="border:none;border-top:1px solid var(--border-subtle);margin:12px 0">' +
            // ì°¨ëŸ‰
            '<div style="font-size:12px;font-weight:700;color:var(--text-tertiary);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px">ðŸš™ ë°°ì • ì°¨ëŸ‰</div>' +
            (v ? '<div style="background:var(--bg-base);border-radius:6px;padding:10px 14px;font-size:13px;margin-bottom:16px">' +
              '<b>' + (v.model || '') + '</b>&nbsp;&nbsp;<code style="font-size:11px">' + (v.plate || '') + '</code><br>' +
              '<span style="color:var(--text-tertiary);font-size:12px">ë ŒíŠ¸ë§Œë£Œ: ' + (v.rentEnd || '-') + ' | í˜„ìž¬ë§ˆì¼: ' + (v.mileage || 0).toLocaleString() + 'mi</span>' +
              '</div>' : '<div style="color:var(--text-tertiary);font-size:13px;padding:6px 0;margin-bottom:16px">ë°°ì •ëœ ì°¨ëŸ‰ ì—†ìŒ</div>') +
            // ìˆ™ì†Œ
            '<div style="font-size:12px;font-weight:700;color:var(--text-tertiary);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px">ðŸ  ë°°ì • ìˆ™ì†Œ</div>' +
            (h ? '<div style="background:var(--bg-base);border-radius:6px;padding:10px 14px;font-size:13px;margin-bottom:16px">' +
              '<b>' + (h.building || '') + '</b> ' + (h.unit || '') + '<br>' +
              '<span style="color:var(--text-tertiary);font-size:12px">' + (h.address || '') + ' | ì›”ìž„ëŒ€: $' + (h.rent || 0).toLocaleString() + '</span>' +
              '</div>' : '<div style="color:var(--text-tertiary);font-size:13px;padding:6px 0;margin-bottom:16px">ë°°ì •ëœ ìˆ™ì†Œ ì—†ìŒ</div>') +
            // í•­ê³µ
            '<div style="font-size:12px;font-weight:700;color:var(--text-tertiary);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px">âœˆï¸ í•­ê³µ ì´ë ¥</div>' +
            '<div style="font-size:13px">' + flightsHtml + '</div>';
        })
        .withFailureHandler(function (err) {
          body.innerHTML = '<div style="padding:20px;color:var(--status-danger)">âš ï¸ ì˜¤ë¥˜: ' + err.message + '</div>';
        })
        .api_getPersonnelCard(uid);
    };

    function _cardRow(label, value) {
      return '<div style="background:var(--bg-base);border-radius:6px;padding:8px 12px">' +
        '<div style="font-size:10px;color:var(--text-tertiary);margin-bottom:3px">' + label + '</div>' +
        '<div style="font-size:13px;font-weight:500">' + (value || '-') + '</div>' +
        '</div>';
    }

    // â”€â”€â”€ ì¸ì› ìƒíƒœ ì €ìž¥ (Cascade ì²˜ë¦¬) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    window.savePersonnelStatus = function () {
      if (!_currentPersonnelUid) return;
      var newStatus = document.getElementById('personnel-status-select').value;
      var btn = document.getElementById('btn-personnel-status-save');
      if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> ì €ìž¥ì¤‘...'; }

      if (typeof google === 'undefined') {
        alert('[ë¡œì»¬] ìƒíƒœë¥¼ [' + newStatus + ']ìœ¼ë¡œ ë³€ê²½ ì‹œë®¬ë ˆì´ì…˜');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph ph-floppy-disk"></i> ìƒíƒœ ì €ìž¥'; }
        return;
      }
      google.script.run
        .withSuccessHandler(function (result) {
          if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph ph-floppy-disk"></i> ìƒíƒœ ì €ìž¥'; }
          if (result && result.success) {
            document.getElementById('personnel-card-modal').classList.remove('active');
            alert('âœ… ' + result.messages.join('\n'));
            window.loadView && window.loadView('hr'); // HR í™”ë©´ ìƒˆë¡œê³ ì¹¨
          } else {
            alert('âš ï¸ ì €ìž¥ ì‹¤íŒ¨: ' + (result && result.error ? result.error : 'ì•Œ ìˆ˜ ì—†ëŠ” ì˜¤ë¥˜'));
          }
        })
        .withFailureHandler(function (err) {
          if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph ph-floppy-disk"></i> ìƒíƒœ ì €ìž¥'; }
          alert('âš ï¸ ì˜¤ë¥˜: ' + err.message);
        })
        .api_syncWorkerStatus(_currentPersonnelUid, newStatus);
    };


    let currentScannerMode = 'upload'; // Track active scanner mode

    window.switchScannerMode = function (mode) {
      currentScannerMode = mode;
      if (mode === 'camera') {
        document.getElementById('btn-toggle-upload').className = 'btn-secondary';
        document.getElementById('btn-toggle-camera').className = 'btn-primary';
        document.getElementById('ai-upload-area').style.display = 'none';
        document.getElementById('ai-camera-area').style.display = 'block';
        startCameraStream();
      } else {
        document.getElementById('btn-toggle-upload').className = 'btn-primary';
        document.getElementById('btn-toggle-camera').className = 'btn-secondary';
        document.getElementById('ai-upload-area').style.display = 'block';
        document.getElementById('ai-camera-area').style.display = 'none';
        stopCameraStream();
      }
    };

    window.startCameraStream = function () {
      stopCameraStream();
      const video = document.getElementById('ai-video-stream');
      if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
          .then(function (stream) {
            video.srcObject = stream;
          })
          .catch(function (err) {
            console.error('Camera stream error:', err);
            alert('카메라를 활성화할 수 없습니다: ' + err.message);
            switchScannerMode('upload');
          });
      } else {
        alert('이 브라우저에서는 카메라 스트리밍을 지원하지 않습니다. 파일 업로드 모드를 사용해 주세요.');
        switchScannerMode('upload');
      }
    };

    window.stopCameraStream = function () {
      const video = document.getElementById('ai-video-stream');
      if (video && video.srcObject) {
        const stream = video.srcObject;
        const tracks = stream.getTracks();
        tracks.forEach(track => track.stop());
        video.srcObject = null;
      }
    };

    window.captureCameraFrame = function () {
      const video = document.getElementById('ai-video-stream');
      if (!video || !video.srcObject) return;

      const canvas = document.createElement('canvas');
      canvas.width = video.videoWidth || 640;
      canvas.height = video.videoHeight || 480;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

      currentAiBase64 = canvas.toDataURL('image/jpeg');
      currentAiMime = 'image/jpeg';

      document.getElementById('ai-image-preview').src = currentAiBase64;
      document.getElementById('ai-camera-area').style.display = 'none';
      document.getElementById('ai-preview-container').style.display = 'block';
      document.getElementById('btn-ai-scan-submit').style.opacity = '1';
      document.getElementById('btn-ai-scan-submit').style.pointerEvents = 'auto';

      stopCameraStream();
    };

    window.openUniversalScanner = function (category, title) {
      if (category) {
        document.getElementById('ai-scan-target-category').value = category;
      }
      document.getElementById('ai-scanner-modal').classList.add('active');
      switchScannerMode('upload'); // Default to upload mode when opening
      clearAiPreview();
    };


    // â”€â”€ Vendor Management Functions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    window.openVendorCreateModal = function() {
      // Remove existing modal if any
      var existing = document.getElementById('vendorCreateModalOverlay');
      if (existing) existing.remove();

      // Build modal HTML
      var overlay = document.createElement('div');
      overlay.id = 'vendorCreateModalOverlay';
      overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;display:flex;justify-content:center;align-items:center;';
      overlay.innerHTML = [
        '<div style="background:#1e2433;width:90%;max-width:480px;padding:28px;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.5);border:1px solid #2d3748;">',
          '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid #2d3748;">',
            '<h2 style="margin:0;font-size:17px;color:#e2e8f0;font-weight:600;">ì‹ ê·œ ê±°ëž˜ì²˜ ë“±ë¡</h2>',
            '<button id="vc-close" style="background:none;border:none;color:#94a3b8;font-size:22px;cursor:pointer;line-height:1;">&times;</button>',
          '</div>',
          '<div style="display:flex;flex-direction:column;gap:12px;">',
            '<select id="vc-category" style="width:100%;padding:9px 12px;border-radius:6px;background:#0f172a;border:1px solid #2d3748;color:#e2e8f0;font-size:13px;">',
              '<option>ì°¨ëŸ‰ ë ŒíŠ¸</option>',
              '<option>ì»¨í…Œì´ë„ˆ/ìˆ™ì†Œ</option>',
              '<option>ì¤‘ìž¥ë¹„/ë°œì „ê¸°</option>',
              '<option>ì´ë™ì‹ í™”ìž¥ì‹¤</option>',
              '<option>ê¸°íƒ€ ìžìž¬</option>',
            '</select>',
            '<input type="text" id="vc-name" placeholder="ì—…ì²´ëª… *" style="width:100%;padding:9px 12px;border-radius:6px;background:#0f172a;border:1px solid #2d3748;color:#e2e8f0;font-size:13px;box-sizing:border-box;">',
            '<input type="text" id="vc-manager" placeholder="ë‹´ë‹¹ìž ì„±í•¨" style="width:100%;padding:9px 12px;border-radius:6px;background:#0f172a;border:1px solid #2d3748;color:#e2e8f0;font-size:13px;box-sizing:border-box;">',
            '<input type="text" id="vc-phone" placeholder="ì—°ë½ì²˜ (Phone)" style="width:100%;padding:9px 12px;border-radius:6px;background:#0f172a;border:1px solid #2d3748;color:#e2e8f0;font-size:13px;box-sizing:border-box;">',
            '<input type="email" id="vc-email" placeholder="ì´ë©”ì¼ ì£¼ì†Œ" style="width:100%;padding:9px 12px;border-radius:6px;background:#0f172a;border:1px solid #2d3748;color:#e2e8f0;font-size:13px;box-sizing:border-box;">',
            '<button id="vc-submit" style="width:100%;padding:12px;background:#3b82f6;color:white;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;margin-top:4px;">ë§ˆìŠ¤í„° ì‹œíŠ¸ì— ë“±ë¡</button>',
          '</div>',
        '</div>'
      ].join('');

      document.body.appendChild(overlay);

      // Wire up buttons
      document.getElementById('vc-close').onclick = function() { overlay.remove(); };
      overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };
      document.getElementById('vc-submit').onclick = function() {
        var data = {
          category: document.getElementById('vc-category').value,
          name: document.getElementById('vc-name').value.trim(),
          manager: document.getElementById('vc-manager').value.trim(),
          phone: document.getElementById('vc-phone').value.trim(),
          email: document.getElementById('vc-email').value.trim()
        };
        if (!data.name) { alert('ì—…ì²´ëª…ì„ ìž…ë ¥í•´ì£¼ì„¸ìš”.'); return; }
        window.API.createVendor(data).then(function(res) {
          if (res.success) {
            alert('ë“±ë¡ ì™„ë£Œ!');
            overlay.remove();
            if (typeof renderVendors === 'function') renderVendors();
          } else {
            alert('ì‹¤íŒ¨: ' + (res.error || 'ì˜¤ë¥˜'));
          }
        });
      };
    };

    
window.submitVendorCreateBtn = function() {
    var data = {
        category: document.getElementById('vc-category').value,
        name: document.getElementById('vc-name').value.trim(),
        manager: document.getElementById('vc-manager').value.trim(),
        phone: document.getElementById('vc-phone').value.trim(),
        email: document.getElementById('vc-email').value.trim()
    };
    if (!data.name) { alert('ì—…ì²´ëª…ì„ ìž…ë ¥í•´ì£¼ì„¸ìš”.'); return; }
    window.API.createVendor(data).then(function(res) {
        if (res.success) {
            alert('ë“±ë¡ ì™„ë£Œ!');
            var over = document.getElementById('vendorCreateModalOverlay');
            if (over) over.style.display='none';
            if (typeof renderVendors === 'function') renderVendors();
        } else {
            alert('ì‹¤íŒ¨: ' + (res.error || 'ì˜¤ë¥˜'));
        }
    });
};

window.submitVendorCreate = function() {
      var catEl = document.getElementById('vc-category');
      var data = {
        category: catEl ? catEl.value : '',
        name: (document.getElementById('vc-name') || {}).value ? document.getElementById('vc-name').value.trim() : '',
        manager: (document.getElementById('vc-manager') || {}).value ? document.getElementById('vc-manager').value.trim() : '',
        phone: (document.getElementById('vc-phone') || {}).value ? document.getElementById('vc-phone').value.trim() : '',
        email: (document.getElementById('vc-email') || {}).value ? document.getElementById('vc-email').value.trim() : ''
      };
      if (!data.name) { alert('ì—…ì²´ëª…ì„ ìž…ë ¥í•´ì£¼ì„¸ìš”.'); return; }
      window.API.createVendor(data).then(function(res) {
        if (res.success) {
          alert('ë“±ë¡ ì™„ë£Œ!');
          var m = document.getElementById('vendorCreateModalOverlay');
          if(m) m.style.display = 'none';
          if (typeof renderVendors === 'function') renderVendors();
        } else {
          alert('ì‹¤íŒ¨: ' + (res.error || 'ì˜¤ë¥˜'));
        }
      });
    };

    window.openVendorModal = function(enc) {
      var v = JSON.parse(decodeURIComponent(enc));
      document.getElementById('vm-name').innerText = v.name || '';
      document.getElementById('vm-category').innerText = v.category || '';
      document.getElementById('vm-manager').innerText = v.manager || '-';
      document.getElementById('vm-phone').innerText = v.phone || '-';
      document.getElementById('vm-email').innerText = v.email || 'ë¯¸ë“±ë¡';
      document.getElementById('vm-email-val').value = v.email || '';
      document.getElementById('vm-draft').value = '';
      document.getElementById('vm-replies').innerHTML = '<div style="color:var(--text-secondary)">ì´ë ¥ ì¡°íšŒ ì¤‘...</div>';
      document.getElementById('vendorModalOverlay').style.display = 'flex';
      if (v.email) {
        window.API.getVendorReplies(v.email).then(function(res) {
          if (res && res.success && res.replies && res.replies.length > 0) {
            document.getElementById('vm-replies').innerHTML = res.replies.map(function(r) {
              return '<div style="margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid var(--border-color);"><b style="color:var(--brand-primary);font-size:12px;">' + r.date + '</b><div style="margin:4px 0;">' + r.body + '</div>' + (r.summaryKr ? '<div style="color:#b45309;font-size:12px;">AI: ' + r.summaryKr + '</div>' : '') + '</div>';
            }).join('');
          } else {
            document.getElementById('vm-replies').innerHTML = '<div style="color:var(--text-secondary);">ë‹µìž¥ ì—†ìŒ</div>';
          }
        }).catch(function() { document.getElementById('vm-replies').innerHTML = '<div style="color:var(--text-secondary);">ì¡°íšŒ ì‹¤íŒ¨</div>'; });
      } else {
        document.getElementById('vm-replies').innerHTML = '<div style="color:var(--text-secondary);">ì´ë©”ì¼ ë¯¸ë“±ë¡</div>';
      }
    };

    window.generateDraft = function() {
      var input = document.getElementById('vm-draft').value;
      if (!input) { alert('ìƒí™©ì„ ìž…ë ¥í•˜ì„¸ìš”'); return; }
      var email = document.getElementById('vm-email-val').value;
      if (!email) { alert('ì´ë©”ì¼ ë¯¸ë“±ë¡'); return; }
      document.getElementById('vm-draft').value = 'ì´ˆì•ˆ ìž‘ì„± ì¤‘...';
      window.API.generateVendorEmailPrompt(input, email, document.getElementById('vm-name').innerText).then(function(res) {
        document.getElementById('vm-draft').value = res.success ? res.draft : 'ì‹¤íŒ¨: ' + res.error;
      });
    };

    window.translateDraft = function() {
      var d = document.getElementById('vm-draft').value;
      if (!d || d.endsWith('...')) { alert('ì´ˆì•ˆ ë¨¼ì € ìž‘ì„±'); return; }
      document.getElementById('vm-draft').value = 'ë²ˆì—­ ì¤‘...';
      window.API.translateToEnglish(d).then(function(res) {
        document.getElementById('vm-draft').value = res.success ? res.english : 'ì‹¤íŒ¨: ' + res.error;
      });
    };

    window.sendDraft = function() {
      var msg = document.getElementById('vm-draft').value;
      var email = document.getElementById('vm-email-val').value;
      var name = document.getElementById('vm-name').innerText;
      if (!msg || msg.endsWith('...')) { alert('ë©”ì‹œì§€ ì™„ì„± í›„ ë°œì†¡'); return; }
      if (!email) { alert('ì´ë©”ì¼ ë¯¸ë“±ë¡'); return; }
      if (!confirm(name + 'ì—ê²Œ ë°œì†¡?')) return;
      window.API.sendVendorEmail(email, '[NAHSHON MEP] ì—…ë¬´ì—°ë½', msg, name).then(function(res) {
        if (res.success) { alert('ë°œì†¡ ì™„ë£Œ! ' + res.tag); document.getElementById('vendorModalOverlay').style.display = 'none'; }
        else { alert('ì‹¤íŒ¨: ' + res.error); }
      });
    };


    window.closeUniversalScanner = function () {
      stopCameraStream();
      document.getElementById('ai-scanner-modal').classList.remove('active');
    }

    // â”€â”€ Quick Action Controllers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    function openQuickActions() {
      document.getElementById('quick-action-modal').classList.add('active');
    }
    function promptNewProject() {
      alert("ðŸš§ ì‹ ê·œ í˜„ìž¥(í”„ë¡œì íŠ¸) ê°œì„¤ ë§ˆë²•ì‚¬ëŠ” ë‹¤ìŒ ë¦´ë¦¬ì¦ˆì—ì„œ ì§€ì›ë  ì˜ˆì •ìž…ë‹ˆë‹¤.");
    }
    function promptActionItem() {
      alert("ðŸš§ ìƒˆ ìž‘ì—… ì§€ì‹œë¥¼(Action Item) ìƒì„±í•˜ê³  Discordë¡œ ì „ì†¡í•˜ëŠ” íŒ¨ë„ì€ ì¤€ë¹„ ì¤‘ìž…ë‹ˆë‹¤.");
    }

    window.handleAiFileSelect = function (e) {
      var file = e.target.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function (evt) {
        currentAiBase64 = evt.target.result;
        currentAiMime = file.type;
        document.getElementById('ai-image-preview').src = currentAiBase64;
        document.getElementById('ai-upload-area').style.display = 'none';
        document.getElementById('ai-preview-container').style.display = 'block';
        document.getElementById('btn-ai-scan-submit').style.opacity = '1';
        document.getElementById('btn-ai-scan-submit').style.pointerEvents = 'auto';
      };
      reader.readAsDataURL(file);
    };

    window.clearAiPreview = function () {
      currentAiBase64 = null;
      currentAiMime = null;
      document.getElementById('ai-file-input').value = '';
      document.getElementById('ai-preview-container').style.display = 'none';
      document.getElementById('btn-ai-scan-submit').style.opacity = '1';
      document.getElementById('btn-ai-scan-submit').style.pointerEvents = 'auto';
      document.getElementById('ai-scan-loading').style.display = 'none';
      if (document.getElementById('ai-preview-container')) {
        document.getElementById('ai-preview-container').style.opacity = '1';
      }

      // Return to current active mode
      if (currentScannerMode === 'camera') {
        document.getElementById('ai-upload-area').style.display = 'none';
        document.getElementById('ai-camera-area').style.display = 'block';
        startCameraStream();
      } else {
        document.getElementById('ai-upload-area').style.display = 'block';
        document.getElementById('ai-camera-area').style.display = 'none';
        stopCameraStream();
      }
    };

    window.submitUniversalAiScan = function () {
      if (!currentAiBase64) {
        // ì´ë¯¸ì§€ ì—†ìœ¼ë©´ ì—…ë¡œë“œ ì˜ì—­ì„ í”ë“¤ì–´ ì•ˆë‚´
        var zone = document.getElementById('ai-upload-area');
        if (zone) {
          zone.style.border = '2px solid var(--status-warning)';
          zone.style.animation = 'none';
          zone.offsetHeight; // reflow
          zone.style.animation = 'aiShake 0.4s ease';
          setTimeout(function () {
            zone.style.border = '2px dashed var(--border-strong)';
            zone.style.animation = 'none';
          }, 500);
        }
        return;
      }
      var category = document.getElementById('ai-scan-target-category').value;

      document.getElementById('btn-ai-scan-submit').style.opacity = '0.5';
      document.getElementById('btn-ai-scan-submit').style.pointerEvents = 'none';
      document.getElementById('ai-scan-loading').style.display = 'block';
      document.getElementById('ai-preview-container').style.opacity = '0.5';

      if (typeof google === 'undefined') {
        // ë¯¹ìŠ¤ ëª¨ë“œ(ë¡œì»¬ í…ŒìŠ¤íŠ¸) ë™ìž‘
        setTimeout(() => {
          alert('Mock Mode: AI 분류 완료 -> ' + category);
          window.closeUniversalScanner();
        }, 2000);
        return;
      }

      google.script.run
        .withSuccessHandler(function (res) {
          document.getElementById('ai-scan-loading').style.display = 'none';
          // Guard against an empty/null server response so the handler never throws
          // "Cannot read properties of null (reading 'success')".
          if (res == null || typeof res !== 'object') {
            res = { success: false, error: 'Empty or invalid server response. Please retry.' };
          }
          if (res.success) {
            alert('🚀 [AI 자동 기입 완료]\n' + category + ' 장부에 성공적으로 등록되었습니다.');
            window.closeUniversalScanner();
            // View Refresh
            if (category === 'VEHICLE') loadView('vehicle');
            if (category === 'HOUSING') loadView('housing');
            if (category === 'FLIGHTS') loadView('flights');
            if (category === 'OFFICE') loadView('office');
          } else {
            alert('⚠️ AI 인식 실패: ' + res.error);
            document.getElementById('btn-ai-scan-submit').style.opacity = '1';
            document.getElementById('btn-ai-scan-submit').style.pointerEvents = 'auto';
            document.getElementById('ai-preview-container').style.opacity = '1';
          }
        })
        .withFailureHandler(function (err) {
          document.getElementById('ai-scan-loading').style.display = 'none';
          alert('통신 오류: ' + err.message);
          document.getElementById('btn-ai-scan-submit').style.opacity = '1';
          document.getElementById('btn-ai-scan-submit').style.pointerEvents = 'auto';
          document.getElementById('ai-preview-container').style.opacity = '1';
        })
        .api_universalAIScan(category, currentAiBase64, currentAiMime);
    };

    window.downloadFinanceExcel = function () {
      var btn = document.getElementById('btn-fin-export');
      if (btn) {
        btn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> ë§í¬ í™•ì¸ì¤‘...';
        btn.disabled = true;
      }
      if (typeof google === 'undefined') {
        setTimeout(function () {
          alert('ë¡œì»¬ í…ŒìŠ¤íŠ¸ ëª¨ë“œ: ë‹¤ìš´ë¡œë“œ ë§í¬ë¥¼ ì‹œë®¬ë ˆì´ì…˜ í•©ë‹ˆë‹¤.');
          if (btn) {
            btn.innerHTML = '<i class="ph ph-download-simple"></i> ë§ˆìŠ¤í„° ì—‘ì…€ ë‹¤ìš´ë¡œë“œ';
            btn.disabled = false;
          }
        }, 1000);
        return;
      }
      google.script.run
        .withSuccessHandler(function (base64) {
          if (btn) {
            btn.innerHTML = '<i class="ph ph-download-simple"></i> ë§ˆìŠ¤í„° ì—‘ì…€ ë‹¤ìš´ë¡œë“œ';
            btn.disabled = false;
          }
          if (base64) {
            var link = document.createElement('a');
            link.href = 'data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,' + base64;
            link.download = 'ë¹„ìš©_ë§ˆìŠ¤í„°_ë‚´ì—­.xlsx';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
          } else {
            alert('ì—‘ì…€ íŒŒì¼ì„ ìƒì„±í•  ìˆ˜ ì—†ìŠµë‹ˆë‹¤. (ì ‘ê·¼ ê¶Œí•œ ë˜ëŠ” ì‹œíŠ¸ ì´ë¦„ ì˜¤ë¥˜)');
          }
        })
        .withFailureHandler(function (err) {
          if (btn) {
            btn.innerHTML = '<i class="ph ph-download-simple"></i> ë§ˆìŠ¤í„° ì—‘ì…€ ë‹¤ìš´ë¡œë“œ';
            btn.disabled = false;
          }
          alert('ì—‘ì…€ ìƒì„± ì‹¤íŒ¨: ' + err.message);
        })
        .api_getFinanceExcelBase64();
    };
  </script>

  <!-- NFC ì°¨ëŸ‰ ë°°ì • ëª¨ë‹¬ -->
  <div id="nfcAssignModal" style="display:none; position:fixed; inset:0; z-index:9000; background:rgba(0,0,0,0.6);"
    class="flex-center">
    <!-- NFC ê³µìš© ë°°ì • ëª¨ë‹¬ -->
    <div id="nfcAssignModal" style="display:none; position:fixed; inset:0; z-index:9000; background:rgba(0,0,0,0.6);"
      class="flex-center">
      <div class="card" style="width:400px; padding:30px; text-align:center;">
        <h3 id="nfcModalTitle" style="margin-bottom:15px; font-size:20px;">NFC ëŒ€ìƒ ë°°ì •</h3>
        <p id="nfcModalSubtitle" style="color:var(--text-secondary); margin-bottom:20px; font-size:14px;">1. ë°°ì •í•  ëŒ€ìƒì„
          ì„ íƒí•˜ì„¸ìš”.</p>
        <select id="nfcAssignTargetSelect" class="form-control" style="margin-bottom:20px;">
          <option value="">(ë°ì´í„°ë¥¼ ë¶ˆëŸ¬ì˜¤ëŠ” ì¤‘...)</option>
        </select>

        <div id="nfcScanArea" style="display:none;">
          <p style="color:var(--status-danger); font-weight:600; margin-bottom:15px;">2. ë‹´ë‹¹ìžì˜ ì‚¬ì›ì¦(NFC)ì„ íƒœê·¸í•˜ì„¸ìš”.</p>
          <div
            style="width:80px; height:80px; margin:0 auto 20px; border-radius:50%; background:var(--bg-primary); display:flex; align-items:center; justify-content:center; box-shadow:0 0 0 5px rgba(245, 166, 35, 0.2); animation: pulse 2s infinite;">
            <i class="ph ph-identification-card" style="font-size:36px; color:var(--status-warning);"></i>
          </div>
          <input type="text" id="nfcAssignInput" style="opacity:0; height:1px; width:1px; position:absolute;"
            autocomplete="off" />
        </div>

        <div style="display:flex; justify-content:center; gap:10px;">
          <button class="btn-secondary" onclick="closeNfcAssignModal()">ë‹«ê¸°</button>
        </div>
      </div>
    </div>

    <script>
      // NFC í†µí•© ë°°ì • ë¡œì§ (ì°¨ëŸ‰/ìˆ™ì†Œ ê³µìš©)
      function openNfcAssignModal(mode) {
        document.getElementById('nfcAssignModal').style.display = 'flex';
        const sel = document.getElementById('nfcAssignTargetSelect');
        const scanArea = document.getElementById('nfcScanArea');
        const nfcInput = document.getElementById('nfcAssignInput');
        const title = document.getElementById('nfcModalTitle');
        const subtitle = document.getElementById('nfcModalSubtitle');

        sel.innerHTML = '<option value="">ë¡œë”© ì¤‘...</option>';
        scanArea.style.display = 'none';
        nfcInput.value = '';

        if (mode === 'VEHICLE') {
          title.innerText = 'NFC ì°¨ëŸ‰ ë°°ì • ë° í•´ì œ';
          subtitle.innerText = '1. ëŒ€ìƒ ì°¨ëŸ‰ì„ ì„ íƒí•˜ì„¸ìš”. (ê¸°ì¡´ ë°°ì •ìžëŠ” í•´ì œë©ë‹ˆë‹¤)';
          window.API.getVehicleList().then(function (list) {
            if (list.length === 0) {
              sel.innerHTML = '<option value="">ë“±ë¡ëœ ì°¨ëŸ‰ì´ ì—†ìŠµë‹ˆë‹¤.</option>';
              return;
            }
            sel.innerHTML = '<option value="">-- ì°¨ëŸ‰ ì„ íƒ --</option>' + list.map(v => {
              const mark = v.assignee ? `[ë°°ì •:${v.assignee}] ` : '[ë¯¸ë°°ì •] ';
              return `<option value="${v.id}">${mark}${v.model} (${v.plate})</option>`;
            }).join('');
          });
        } else if (mode === 'HOUSING') {
          title.innerText = 'NFC ìˆ™ì†Œ ìž…ì£¼/í‡´ê±°';
          subtitle.innerText = '1. ëŒ€ìƒ ìˆ™ì†Œë¥¼ ì„ íƒí•˜ì„¸ìš”. (ê¸°ì¡´ ìž…ì£¼ìžëŠ” í‡´ê±°ë©ë‹ˆë‹¤)';
          window.API.getHousingList().then(function (list) {
            if (list.length === 0) {
              sel.innerHTML = '<option value="">ë“±ë¡ëœ ìˆ™ì†Œê°€ ì—†ìŠµë‹ˆë‹¤.</option>';
              return;
            }
            sel.innerHTML = '<option value="">-- ìˆ™ì†Œ ì„ íƒ --</option>' + list.map(h => {
              const mark = (h.currentOcc >= h.maxOcc) ? '[ë§Œì‹¤] ' : `[ì—¬ìœ :${h.maxOcc - h.currentOcc}] `;
              return `<option value="${h.id}">${mark}${h.building} - ${h.unit}</option>`;
            }).join('');
          });
        }

        sel.onchange = function () {
          if (sel.value) {
            scanArea.style.display = 'block';
            nfcInput.focus();
          } else {
            scanArea.style.display = 'none';
          }
        };

        // ëª¨ë‹¬ ì˜ì—­ í´ë¦­ì‹œ ì»¤ì„œ ìœ ì§€ ë°©ì–´ì½”ë“œ
        document.getElementById('nfcAssignModal').onclick = function () {
          if (sel.value) {
            setTimeout(() => nfcInput.focus(), 100);
          }
        };

        nfcInput.onkeypress = function (e) {
          if (e.key === 'Enter') {
            const uid = nfcInput.value.trim();
            const targetId = sel.value;
            nfcInput.value = '';
            if (!uid || !targetId) return;

            document.getElementById('nfcAssignModal').style.display = 'none';
            showToast('ë°°ì • ì •ë³´ë¥¼ ì „ì†¡í•˜ëŠ” ì¤‘...');

            const runner = google.script.run
              .withSuccessHandler(function (res) {
                if (res.success) {
                  showToast(res.message);
                  // íƒ­ ìƒˆë¡œê³ ì¹¨
                  const curTab = document.querySelector('.sidebar-item.active').getAttribute('data-tab');
                  if (curTab === 'vehicle') window.renderVehicle();
                  if (curTab === 'rental') window.loadView('rental');
                  if (curTab === 'housing') window.renderHousing();
                  if (curTab === 'personnel') window.renderPersonnel();
                } else {
                  alert('ë°°ì • ì‹¤íŒ¨: ' + res.error);
                }
              })
              .withFailureHandler(function (err) {
                alert('ì„œë²„ ì˜¤ë¥˜: ' + err.message);
              });

            // ëª¨ë“œì— ë”°ë¼ ë°±ì—”ë“œ API í˜¸ì¶œ ë¶„ê¸°
            if (mode === 'VEHICLE') runner.api_nfcAssignVehicle(uid, targetId);
            else if (mode === 'HOUSING') runner.api_nfcAssignHousing(uid, targetId);
          }
        };
      }

      function closeNfcAssignModal() {
        document.getElementById('nfcAssignModal').style.display = 'none';
      }
    

</script>

<style>
.vendor-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-top: 16px; }
.vendor-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; display: flex; flex-direction: column; gap: 8px; transition: all 0.2s; cursor: pointer; }
.vendor-card:hover { border-color: var(--brand-primary); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.vendor-card .v-cat { font-size: 11px; font-weight: 700; color: var(--brand-primary); }
.vendor-card .v-name { font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0; }
.vendor-card .v-contact { font-size: 13px; color: var(--text-secondary); display: flex; align-items: center; gap: 6px; }
.vendor-card .v-tag { align-self: flex-start; padding: 2px 6px; border-radius: 4px; font-size: 11px; background: rgba(16,185,129,0.1); color: var(--status-ok); font-weight: 600; }
</style>

<div id="vendorCreateModalOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
  <div style="background:var(--bg-surface); width:90%; max-width:500px; padding:24px; border-radius:12px; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
    <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:12px; margin-bottom:20px; border-bottom:1px solid var(--border-color);">
      <h2 style="margin:0; font-size:18px; color:var(--text-primary);"><i class="ph ph-plus-circle"></i> ì‹ ê·œ ê±°ëž˜ì²˜ ë“±ë¡</h2>
      <button onclick="document.getElementById(\'vendorCreateModalOverlay\').style.display=\'none\'" style="background:none;border:none;cursor:pointer;font-size:20px;"><i class="ph ph-x"></i></button>
    </div>
    <div style="display:flex; flex-direction:column; gap:12px;">
      <select id="vc-category" style="width:100%; padding:8px; border-radius:6px; background:var(--bg-body); border:1px solid var(--border-color); color:var(--text-primary);">
        <option>ì°¨ëŸ‰ ë ŒíŠ¸</option><option>ì»¨í…Œì´ë„ˆ/ìˆ™ì†Œ</option><option>ì¤‘ìž¥ë¹„/ë°œì „ê¸°</option><option>ì´ë™ì‹ í™”ìž¥ì‹¤</option><option>ê¸°íƒ€ ìžìž¬</option>
      </select>
      <input type="text" id="vc-name" placeholder="ì—…ì²´ëª… *" style="width:100%; padding:8px; border-radius:6px; background:var(--bg-body); border:1px solid var(--border-color); color:var(--text-primary); box-sizing:border-box;">
      <input type="text" id="vc-manager" placeholder="ë‹´ë‹¹ìž ì„±í•¨" style="width:100%; padding:8px; border-radius:6px; background:var(--bg-body); border:1px solid var(--border-color); color:var(--text-primary); box-sizing:border-box;">
      <input type="text" id="vc-phone" placeholder="ì—°ë½ì²˜ (Phone)" style="width:100%; padding:8px; border-radius:6px; background:var(--bg-body); border:1px solid var(--border-color); color:var(--text-primary); box-sizing:border-box;">
      <input type="email" id="vc-email" placeholder="ì´ë©”ì¼" style="width:100%; padding:8px; border-radius:6px; background:var(--bg-body); border:1px solid var(--border-color); color:var(--text-primary); box-sizing:border-box;">
      <button onclick="submitVendorCreate()" style="padding:12px; background:var(--brand-primary); color:white; border:none; border-radius:6px; cursor:pointer; font-weight:600;"><i class="ph ph-check"></i> ë§ˆìŠ¤í„° ì‹œíŠ¸ì— ë“±ë¡</button>
    </div>
  </div>
</div>

<div id="vendorModalOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
  <div style="background:var(--bg-surface); width:90%; max-width:800px; max-height:90vh; border-radius:12px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
    <div style="padding:16px 24px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
      <h2 style="margin:0; font-size:18px; color:var(--text-primary);"><i class="ph ph-storefront"></i> ê±°ëž˜ì²˜ ì´ë©”ì¼ í†µì‹ </h2>
      <button onclick="document.getElementById('vendorModalOverlay').style.display='none'" style="background:none;border:none;cursor:pointer;color:var(--text-secondary);font-size:14px;"><i class="ph ph-x"></i> ë‹«ê¸°</button>
    </div>
    <div style="display:flex; flex:1; overflow:hidden;">
      <div style="flex:1; padding:20px; border-right:1px solid var(--border-color); overflow-y:auto; background:var(--bg-body);">
        <h3 id="vm-name" style="margin-top:0; color:var(--text-primary);"></h3>
        <p id="vm-category" style="color:var(--brand-primary); font-weight:bold; margin-bottom:12px;"></p>
        <div style="font-size:13px; color:var(--text-secondary); line-height:1.8; margin-bottom:16px;">
          <div>ë‹´ë‹¹ìž: <span id="vm-manager"></span></div>
          <div>ì „í™”: <span id="vm-phone"></span></div>
          <div>Email: <span id="vm-email"></span></div>
          <input type="hidden" id="vm-email-val">
        </div>
        <div style="font-weight:600; margin-bottom:8px; color:var(--text-primary);">ìµœê·¼ ì»¤ë®¤ë‹ˆì¼€ì´ì…˜ ì´ë ¥</div>
        <div id="vm-replies" style="font-size:13px; color:var(--text-secondary);">ì¡°íšŒ ì¤‘...</div>
      </div>
      <div style="flex:1.2; padding:20px; display:flex; flex-direction:column;">
        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
          <span style="font-weight:600; color:var(--text-primary);">AI íŽ¸ì§€ ë¹„ì„œ</span>
          <button onclick="translateDraft()" style="padding:4px 10px; background:var(--brand-primary); color:white; border:none; border-radius:4px; cursor:pointer; font-size:12px;">ì˜ì–´ë¡œ ë²ˆì—­</button>
        </div>
        <textarea id="vm-draft" style="flex:1; min-height:180px; width:100%; border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-body); color:var(--text-primary); resize:none; margin-bottom:12px; box-sizing:border-box; font-family:inherit;" placeholder="ìƒí™©ì„ í•œêµ­ì–´ë¡œ ìž…ë ¥í•˜ë©´ AIê°€ ì´ˆì•ˆì„ ìž‘ì„±í•©ë‹ˆë‹¤."></textarea>
        <div style="display:flex; gap:8px;">
          <button onclick="generateDraft()" style="flex:1; padding:10px; background:var(--bg-surface); border:1px solid var(--border-color); border-radius:6px; cursor:pointer; color:var(--text-primary); font-weight:600;">AI ì´ˆì•ˆ</button>
          <button onclick="sendDraft()" style="flex:1; padding:10px; background:#059669; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:600;">ë°œì†¡</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
async function renderVendors() {
  document.getElementById('page-container').innerHTML = '<div class="skeleton-loader"><div class="sk-line sk-w60"></div><div class="sk-line sk-w80"></div><div class="sk-line sk-w40"></div></div>';
  try {
    var vendors = await window.API.getVendorList();
    var h = '<div class="header-section"><div><h1 class="page-title">êµ¬ë§¤/ë ŒíŠ¸ ë‹´ë‹¹ìž</h1><p class="page-subtitle">ë¹„ìƒì—°ë½ë§ ë° AI ë©”ì¼ ë°œì†¡ ì„¼í„°</p></div>' +
            '<div class="action-row"><button onclick="document.getElementById(\'vendorCreateModalOverlay\').style.display=\'flex\'" style="padding:8px 16px;background:var(--brand-primary);color:white;border:none;border-radius:6px;cursor:pointer;font-weight:600;"><i class="ph ph-plus"></i> ì‹ ê·œ ê±°ëž˜ì²˜ ë“±ë¡</button></div></div>' +
            '<div class="vendor-grid">';
    if (!vendors || vendors.length === 0) {
      h += '<div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--text-secondary);">ë“±ë¡ëœ ê±°ëž˜ì²˜ê°€ ì—†ìŠµë‹ˆë‹¤. ìƒë‹¨ ë²„íŠ¼ì„ ëˆŒëŸ¬ ì¶”ê°€í•˜ì„¸ìš”.</div>';
    } else {
      vendors.forEach(function(v) {
        var enc = encodeURIComponent(JSON.stringify(v));
        h += '<div class="vendor-card" onclick="openVendorModal(\'' + enc + '\')">' +
             '<div style="display:flex;justify-content:space-between;"><span class="v-cat">' + (v.category||'') + '</span><span class="v-tag">' + (v.contractStatus||'ì§„í–‰ì¤‘') + '</span></div>' +
             '<h3 class="v-name">' + (v.name||'') + '</h3>' +
             '<div class="v-contact"><i class="ph ph-user"></i> ' + (v.manager||'-') + '</div>' +
             '<div class="v-contact"><i class="ph ph-phone"></i> ' + (v.phone||'-') + '</div>' +
             '</div>';
      });
    }
    h += '</div>';
    
    
    h += '<div id="vendorCreateModalOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:9999;justify-content:center;align-items:center;animation:fadeIn 0.2s ease-out;">' +
         '<div style="background:var(--bg-surface-elevated);width:90%;max-width:550px;border-radius:16px;box-shadow:0 24px 48px rgba(0,0,0,0.4);border:1px solid rgba(255,255,255,0.08);overflow:hidden;animation:slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);">' +
         '<div style="display:flex;justify-content:space-between;align-items:center;padding:24px 28px;background:linear-gradient(135deg, rgba(59,130,246,0.1), transparent);border-bottom:1px solid var(--border-color);">' +
         '<h2 style="margin:0;font-size:20px;color:var(--text-primary);font-weight:700;display:flex;align-items:center;gap:10px;"><i class="ph ph-buildings" style="color:var(--brand-primary);font-size:24px;"></i> ì‹ ê·œ ê±°ëž˜ì²˜ ë§ˆìŠ¤í„° ë“±ë¡</h2>' +
         '<button onclick="document.getElementById(\'vendorCreateModalOverlay\').style.display=\'none\'" style="background:var(--bg-surface);border:1px solid var(--border-color);color:var(--text-secondary);font-size:18px;cursor:pointer;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:all 0.2s;">&times;</button>' +
         '</div>' +
         
         '<div style="padding:24px 28px;display:flex;flex-direction:column;gap:16px;">' +
         '<div style="background:rgba(16,185,129,0.08);border:1px dashed rgba(16,185,129,0.4);padding:16px;border-radius:12px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;transition:all 0.2s;"' +
         ' onmouseover="this.style.background=\'rgba(16,185,129,0.15)\'" onmouseout="this.style.background=\'rgba(16,185,129,0.08)\'"' +
         ' onclick="document.getElementById(\'vendorCreateModalOverlay\').style.display=\'none\'; window.openUniversalScanner(\'VENDORS\', \'ê±°ëž˜ì²˜ ëª…í•¨ / ì‚¬ì—…ìžë“±ë¡ì¦ ë¡œë“œ\');">' +
         '<div style="display:flex;align-items:center;gap:12px;"><div style="width:40px;height:40px;background:var(--status-success);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#000;"><i class="ph ph-scan" style="font-size:24px;bold"></i></div>' +
         '<div><div style="font-weight:700;color:var(--status-success);font-size:15px;">AI ìžë™ ìŠ¤ìº” ê¸°ìž…</div><div style="font-size:13px;color:var(--text-secondary);margin-top:2px;">ëª…í•¨, ì¸ë³´ì´ìŠ¤ ì‚¬ì§„ì„ ë„£ìœ¼ë©´ 3ì´ˆë§Œì— ìžë™ ë“±ë¡</div></div></div>' +
         '<i class="ph ph-caret-right" style="color:var(--status-success);font-size:20px;"></i>' +
         '</div>' +
         
         '<div style="display:flex;align-items:center;gap:16px;"><div style="flex:1;height:1px;background:var(--border-color);"></div><div style="font-size:12px;color:var(--text-tertiary);font-weight:600;">ë˜ëŠ” ìˆ˜ë™ ìž…ë ¥</div><div style="flex:1;height:1px;background:var(--border-color);"></div></div>' +

         '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">' +
         '<div style="grid-column:1/-1;"><label style="display:block;font-size:12px;color:var(--text-secondary);margin-bottom:6px;font-weight:600;">ì—…ì¢… ì¹´í…Œê³ ë¦¬</label><div style="position:relative;"><select id="vc-category" style="width:100%;padding:12px 14px;border-radius:8px;background:rgba(15,23,42,0.6);border:1px solid rgba(255,255,255,0.12);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);color:var(--text-primary);font-size:14px;appearance:none;outline:none;">' +
         '<option>ì°¨ëŸ‰ ë ŒíŠ¸</option><option>ì»¨í…Œì´ë„ˆ/ìˆ™ì†Œ</option><option>ì¤‘ìž¥ë¹„/ë°œì „ê¸°</option><option>ì´ë™ì‹ í™”ìž¥ì‹¤</option><option>ê¸°íƒ€ ìžìž¬</option></select>' +
         '<i class="ph ph-caret-down" style="position:absolute;right:14px;top:14px;color:var(--text-tertiary);pointer-events:none;"></i></div></div>' +
         
         '<div style="grid-column:1/-1;"><label style="display:block;font-size:12px;color:var(--text-secondary);margin-bottom:6px;font-weight:600;">ìƒí˜¸ëª… (ì—…ì²´ëª…) <span style="color:#f43f5e;">*</span></label><input type="text" id="vc-name" placeholder="(ì£¼) ë‚˜ì† ê¸°ì—…" style="width:100%;padding:12px 14px;border-radius:8px;background:rgba(15,23,42,0.6);border:1px solid rgba(255,255,255,0.12);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);color:var(--text-primary);font-size:14px;box-sizing:border-box;outline:none;"></div>' +
         
         '<div><label style="display:block;font-size:12px;color:var(--text-secondary);margin-bottom:6px;font-weight:600;">ë‹´ë‹¹ìž ì„±í•¨</label><input type="text" id="vc-manager" placeholder="í™ê¸¸ë™ ì´ì‚¬" style="width:100%;padding:12px 14px;border-radius:8px;background:rgba(15,23,42,0.6);border:1px solid rgba(255,255,255,0.12);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);color:var(--text-primary);font-size:14px;box-sizing:border-box;outline:none;"></div>' +
         '<div><label style="display:block;font-size:12px;color:var(--text-secondary);margin-bottom:6px;font-weight:600;">ì—°ë½ì²˜ (Phone)</label><input type="text" id="vc-phone" placeholder="000-000-0000" style="width:100%;padding:12px 14px;border-radius:8px;background:rgba(15,23,42,0.6);border:1px solid rgba(255,255,255,0.12);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);color:var(--text-primary);font-size:14px;box-sizing:border-box;outline:none;"></div>' +
         '<div style="grid-column:1/-1;"><label style="display:block;font-size:12px;color:var(--text-secondary);margin-bottom:6px;font-weight:600;">ìˆ˜ì‹ ìš© ì´ë©”ì¼ ì£¼ì†Œ</label><input type="email" id="vc-email" placeholder="billing@company.com" style="width:100%;padding:12px 14px;border-radius:8px;background:rgba(15,23,42,0.6);border:1px solid rgba(255,255,255,0.12);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);color:var(--text-primary);font-size:14px;box-sizing:border-box;outline:none;"></div>' +
         '</div>' +
         
         '<button onclick="window.submitVendorCreateBtn()" style="width:100%;padding:14px;background:var(--brand-primary);color:white;border:none;border-radius:8px;cursor:pointer;font-size:15px;font-weight:700;margin-top:10px;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 14px rgba(59,130,246,0.3);transition:all 0.2s;"><i class="ph ph-floppy-disk"></i> ë§ˆìŠ¤í„° ì‹œíŠ¸ì— ìˆ˜ë™ ë“±ë¡</button>' +
         '</div></div></div>';

    document.getElementById('page-container').innerHTML = h;
  } catch(e) {
    document.getElementById('page-container').innerHTML = '<div style="padding:40px;">ì˜¤ë¥˜: ' + e.message + '</div>';
  }
}

    // ============================================================
    // TEAM QR COMMUTE / ATTENDANCE logic
    // ============================================================
    window._scannedTeamCode = null;

    window._myCommuteHtmlTemplate = function(scannedCode) {
      const today = new Date().toLocaleDateString('ko-KR', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' });

      let validationHtml = '';
      if (scannedCode) {
        validationHtml = '<div style="background:rgba(37,99,235,0.08); border:1px dashed var(--brand-primary); padding:10px 14px; border-radius:8px; font-size:12px; margin-bottom:12px; text-align:center;">' +
          '<span style="color:var(--text-secondary);">스캔한 팀 코드:</span> ' +
          '<strong style="color:var(--brand-primary); font-size:13px;">' + scannedCode + '</strong>' +
          '</div>';
      }

      return '<div class="panel" style="margin-bottom:16px; border:1px solid var(--border-color); background:var(--bg-surface); border-radius:12px; overflow:hidden;">' +
        '<div class="panel-header" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-bottom:1px solid var(--border-subtle);">' +
        '<div class="panel-title" style="font-size:13px; font-weight:700;"><i class="ph ph-qr-code" style="color:var(--brand-primary);"></i> QR 팀별 출퇴근</div>' +
        '<span style="font-size:11px; font-weight:600; color:var(--brand-primary);">' + today + '</span>' +
        '</div>' +
        '<div class="panel-body padded" style="padding:20px; display:flex; flex-direction:column; gap:16px;">' +
        validationHtml +
        '<div style="background:var(--bg-base); border:1px solid var(--border-subtle); border-radius:8px; padding:14px; font-size:12px; display:flex; flex-direction:column; gap:10px;">' +
        '<div style="display:flex; justify-content:space-between;"><span style="color:var(--text-tertiary);">내 성명</span><span style="font-weight:700; color:var(--text-primary);">' + authenticatedAccount.name + '</span></div>' +
        '<div style="display:flex; justify-content:space-between;"><span style="color:var(--text-tertiary);">내 권한</span><span style="font-weight:700; color:var(--text-primary);">' + authenticatedAccount.role + '</span></div>' +
        '<div style="display:flex; justify-content:space-between;"><span style="color:var(--text-tertiary);">스캔 검증 상태</span><span id="commute-validation-status" style="font-weight:700; color:var(--status-warning);">스캔 대기 중</span></div>' +
        '</div>' +
        '<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">' +
        '<button id="btn-clock-in" onclick="window.submitCommuteQr(\'clock_in\')" class="btn-primary" style="padding:14px; font-size:14px; font-weight:700; border-radius:8px; display:flex; flex-direction:column; align-items:center; gap:6px;" disabled>' +
        '<i class="ph ph-sign-in" style="font-size:20px;"></i><span>출근 등록</span></button>' +
        '<button id="btn-clock-out" onclick="window.submitCommuteQr(\'clock_out\')" class="btn-secondary" style="padding:14px; font-size:14px; font-weight:700; border-radius:8px; display:flex; flex-direction:column; align-items:center; gap:6px;" disabled>' +
        '<i class="ph ph-sign-out" style="font-size:20px;"></i><span>퇴근 등록</span></button>' +
        '</div>' +
        '<div id="commute-message" style="font-size:11px; text-align:center; color:var(--text-tertiary);">현장의 팀 QR 코드를 카메라로 정확히 스캔해 주세요.</div>' +
        '</div></div>' +
        '<div class="panel" style="border:1px solid var(--border-color); background:var(--bg-surface); border-radius:12px; overflow:hidden;">' +
        '<div class="panel-header" style="padding:14px 20px; border-bottom:1px solid var(--border-subtle);"><div class="panel-title" style="font-size:13px;"><i class="ph ph-list-bullets"></i> 오늘 내 출퇴근 기록</div></div>' +
        '<div class="panel-body" style="padding:0; overflow-x:auto;">' +
        '<table class="data-table" id="my-attendance-table" style="width:100%; border-collapse:collapse;">' +
        '<thead><tr><th style="text-align:left; padding:10px 14px; font-size:11px; color:var(--text-tertiary);">구분</th><th style="text-align:left; padding:10px 14px; font-size:11px; color:var(--text-tertiary);">기록 시간</th><th style="text-align:left; padding:10px 14px; font-size:11px; color:var(--text-tertiary);">방식</th><th style="text-align:left; padding:10px 14px; font-size:11px; color:var(--text-tertiary);">상태</th></tr></thead>' +
        '<tbody id="my-attendance-logs-body"><tr><td colspan="4" style="text-align:center; padding:20px; color:var(--text-tertiary);">기록 조회 중...</td></tr></tbody>' +
        '</table></div></div>';
    };

    window.openMyCommuteModal = function(teamCode) {
      window._scannedTeamCode = teamCode || null;
      const modal = document.getElementById('myCommuteModalOverlay');
      if (modal) {
        modal.style.display = 'flex';
        document.getElementById('my-commute-modal-body').innerHTML = window._myCommuteHtmlTemplate(teamCode);
        window.loadMyCommuteLogs();
        window.validateScannedTeamCode(teamCode);
      }
    };

    window.closeMyCommuteModal = function() {
      const modal = document.getElementById('myCommuteModalOverlay');
      if (modal) {
        modal.style.display = 'none';
      }
    };

    window.loadMyCommuteLogs = async function() {
      const body = document.getElementById('my-attendance-logs-body');
      if (!body) return;
      try {
        const today = new Date().toISOString().slice(0, 10);
        const res = await gsRun('api_getHrAttendanceRecords', [authenticatedAccount.employee_id, today, today], { success: false, records: [] });
        if (res && res.success && res.records && res.records.length > 0) {
          body.innerHTML = res.records.map(r => {
            const time = r.time || (r.event_at ? new Date(r.event_at).toLocaleTimeString('ko-KR', { hour12: false, hour: '2-digit', minute: '2-digit' }) : '-');
            const type = r.event_type === 'clock_in' ? '<span style="color:var(--status-success); font-weight:700;">출근</span>' : '<span style="color:var(--status-warning); font-weight:700;">퇴근</span>';
            const method = r.source === 'team_qr' ? 'QR 스캔' : r.source === 'nfc_reader' ? 'NFC 리더' : '웹 포탈';
            const status = r.status === 'approved' ? '<span style="color:var(--status-success);">승인완료</span>' : r.status === 'pending' ? '<span style="color:var(--status-warning);">대기중</span>' : '<span style="color:var(--status-danger);">반려</span>';
            return '<tr style="border-bottom:1px solid var(--border-subtle);"><td style="padding:10px 14px; font-weight:600;">' + type + '</td><td class="cell-mono" style="padding:10px 14px;">' + time + '</td><td style="padding:10px 14px;">' + method + '</td><td style="padding:10px 14px;">' + status + '</td></tr>';
          }).join('');
        } else {
          body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--text-tertiary);">오늘 출퇴근 기록이 존재하지 않습니다.</td></tr>';
        }
      } catch (err) {
        body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--status-danger);">이력 조회 실패: ' + err.message + '</td></tr>';
      }
    };

    window.validateScannedTeamCode = function(teamCode) {
      const statusEl = document.getElementById('commute-validation-status');
      const msgEl = document.getElementById('commute-message');
      const btnIn = document.getElementById('btn-clock-in');
      const btnOut = document.getElementById('btn-clock-out');

      if (!teamCode) {
        if (statusEl) statusEl.innerHTML = '<span style="color:var(--status-danger);">스캔 실패 (코드 없음)</span>';
        if (msgEl) msgEl.innerText = '올바른 QR 코드를 스캔해 주세요.';
        return;
      }

      if (statusEl) {
        statusEl.innerHTML = '<span style="color:var(--status-success);"><i class="ph ph-check-circle"></i> 스캔 확인 완료</span>';
      }
      if (msgEl) {
        msgEl.innerHTML = '<span style="color:var(--brand-primary); font-weight:700;">본인 인증이 활성화되었습니다. 출퇴근을 선택해주세요.</span>';
      }
      if (btnIn) btnIn.disabled = false;
      if (btnOut) btnOut.disabled = false;
    };

    window.submitCommuteQr = async function(eventType) {
      if (!window._scannedTeamCode) {
        alert('스캔된 팀 코드가 없습니다. 다시 QR 코드를 스캔해 주세요.');
        return;
      }
      const btnIn = document.getElementById('btn-clock-in');
      const btnOut = document.getElementById('btn-clock-out');
      if (btnIn) btnIn.disabled = true;
      if (btnOut) btnOut.disabled = true;

      showToast('스캔 정보를 전송 및 소속 팀 검증 중...');
      try {
        const res = await window.API.clockInWithTeamQr(window._scannedTeamCode, eventType);
        if (res && res.success) {
          showToast(res.message);
          window.loadMyCommuteLogs();
          const msgEl = document.getElementById('commute-message');
          if (msgEl) {
            msgEl.innerHTML = '<span style="color:var(--status-success); font-weight:700;"><i class="ph ph-check-circle"></i> ' + res.message + '</span>';
          }
          setTimeout(function() {
            window.closeMyCommuteModal();
          }, 2000);
        } else {
          const msgEl = document.getElementById('commute-message');
          if (msgEl) {
            msgEl.innerHTML = '<span style="color:var(--status-danger); font-weight:700;"><i class="ph ph-x-circle"></i> ' + (res.message || '인증 오류') + '</span>';
          }
          alert('인증 반려: ' + (res.message || '소속 팀이 일치하지 않습니다.'));
          if (btnIn) btnIn.disabled = false;
          if (btnOut) btnOut.disabled = false;
        }
      } catch (err) {
        alert('서버 검증 실패: ' + err.message);
        if (btnIn) btnIn.disabled = false;
        if (btnOut) btnOut.disabled = false;
      }
    };
</script>

<div id="myCommuteModalOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.65); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); z-index:9999; justify-content:center; align-items:center;">
  <div style="background:var(--bg-surface-elevated); width:90%; max-width:550px; border-radius:16px; box-shadow:0 24px 48px rgba(0,0,0,0.4); border:1px solid rgba(255,255,255,0.08); overflow:hidden; display:flex; flex-direction:column; max-height:90vh;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:20px 24px; background:linear-gradient(135deg, rgba(37,99,235,0.1), transparent); border-bottom:1px solid var(--border-color);">
      <h2 style="margin:0; font-size:18px; color:var(--text-primary); font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="ph ph-clock" style="color:var(--brand-primary); font-size:22px;"></i>
        내 출퇴근 등록 (QR)
      </h2>
      <button onclick="window.closeMyCommuteModal()" style="background:var(--bg-surface); border:1px solid var(--border-color); color:var(--text-secondary); font-size:18px; cursor:pointer; width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; transition:all 0.2s;">&times;</button>
    </div>
    <div style="padding:24px; overflow-y:auto; flex:1;" id="my-commute-modal-body">
    </div>
  </div>
</div>

</body>
</html>

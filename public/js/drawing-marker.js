/**
 * 도면 위 표시 — 사장 지시(2026-09-25): «각 줄에 사진이나 증거 자료를 넣었을 때 도면에 직접 내용이
 * 넣어지게», «좀 세련된 표시 기능».
 *
 * 한 화면이 두 가지로 쓰인다.
 *  - view: 도면 한 장과 그 위의 모든 표시. 색은 연결된 현장 기록에서 나온다(확인됨 초록 · 반입 파랑 ·
 *    확인 대기 주황 · 계획 회색 · 메모 보라). 표시를 누르면 사진·수량·날짜가 뜬다. 관리자는 여기서
 *    점·선·영역·메모를 더한다.
 *  - pick: 진행 기록을 적는 중에 «어디서 했나» 를 찍는다. 찍은 모양을 돌려주면 기록과 함께 저장된다.
 *
 * 좌표는 쪽 크기 대비 0~1 — 확대·해상도·기기와 무관하게 같은 자리다. 도면은 이 브라우저가 PDF.js 로
 * 그린다(공정별 도면 읽기와 같은 라이브러리). 서버는 그림을 만들지 않는다.
 */
(function (global) {
  'use strict';

  var PDFJS = '/vendor/pdfjs/pdf.min.js';
  var PDFJS_WORKER = '/vendor/pdfjs/pdf.worker.min.js';
  var FONTS = '/vendor/pdfjs/standard_fonts/';

  var STATUS = {
    done: { label: '확인됨', color: '#10b981' },
    stored: { label: '반입 확인', color: '#3b82f6' },
    pending: { label: '확인 대기', color: '#f59e0b' },
    plan: { label: '계획', color: '#64748b' },
    note: { label: '메모', color: '#8b5cf6' },
    rejected: { label: '반려', color: '#9ca3af' },
  };
  var STAGE = { installation: '설치', installed: '시공', stored: '반입' };

  // 기성 회차별 색 — 1차·2차·3차… 가 서로, 그리고 상태 색(초록·주황·파랑)과 헷갈리지 않게.
  var ROUND_COLORS = ['#6366f1', '#ec4899', '#14b8a6', '#f97316', '#a855f7', '#0ea5e9', '#84cc16', '#e11d48'];

  /**
   * 표시의 색 묶음. mode='round' 면 청구된 표시는 «N차 기성», 확인됐지만 아직 청구 안 된 것은
   * «다음 기성 대상», 나머지는 상태 그대로. mode='status' 면 상태만.
   */
  function keyOf(m, mode) {
    if (mode === 'round') {
      if (m.rounds && m.rounds.length) return 'r' + m.rounds[0].no;
      if (m.status === 'done' || m.status === 'stored') return 'next';
    }
    return m.status;
  }
  function styleOf(key) {
    if (key === 'next') return { label: '다음 기성 대상', color: '#10b981', order: 50 };
    if (key && key.charAt(0) === 'r') {
      var no = Number(key.slice(1));
      return { label: no + '차 기성', color: ROUND_COLORS[(no - 1) % ROUND_COLORS.length], order: no };
    }
    var s = STATUS[key] || STATUS.note;
    return { label: s.label, color: s.color, order: 60 + Object.keys(STATUS).indexOf(key) };
  }
  var TOOLS = [
    ['hand', 'ph-hand', '이동'],
    ['point', 'ph-map-pin', '점'],
    ['line', 'ph-line-segments', '선'],
    ['area', 'ph-polygon', '영역'],
    ['note', 'ph-chat-centered-text', '메모'],
  ];

  var pdfjsModule = null;

  function esc(v) {
    return String(v === undefined || v === null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (r) {
      if (!r) throw new Error('서버 응답이 없습니다.');
      return r;
    });
  }

  function toast(msg, kind) { if (global.AdminUI) global.AdminUI.toast(msg, kind); }

  function loadPdfJs() {
    if (pdfjsModule) return Promise.resolve(pdfjsModule);
    return import(PDFJS).then(function (m) {
      m.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
      pdfjsModule = m;
      return m;
    });
  }

  function num(v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 }); }

  /**
   * 기본 도구 — 평면도에서 벽·배관·전선·울타리는 선으로 보인다(단위가 면적이어도). 그 밖에는
   * 면적 단위는 영역, 길이 단위는 선, 개수는 점.
   */
  function shapeForUnit(unit, description) {
    var d = String(description || '').toUpperCase();
    if (/WALL|PARTITION|BASE|PIPE|DUCT|CONDUIT|CABLE|CONDUCTOR|TRAY|FENCE|CURB|TRENCH|EMT|STRIP|GUTTER|LINE/.test(d)) return 'line';
    var u = String(unit || '').toUpperCase().replace(/\s/g, '');
    if (/^(LF2|SF|SQLF|SQFT|FT2|M2|SY|YD2)$/.test(u)) return 'area';
    if (/^(LF|M|FT|LM)$/.test(u)) return 'line';
    return 'point';
  }

  /** 위치 글자에서 도면에서 찾을 말 — 긴 말부터. */
  function searchTerms(text) {
    var s = String(text || '').toUpperCase().trim();
    if (!s) return [];
    var words = s.split(/[^A-Z0-9가-힣-]+/).filter(function (w) { return w.length >= 3 && !/^(THE|AND|WALL|SIDE|AREA|ROOM|FLOOR)$/.test(w); });
    var out = [s].concat(words.sort(function (a, b) { return b.length - a.length; }));
    return out.filter(function (w, i) { return out.indexOf(w) === i; });
  }

  // ── 재기 ──────────────────────────────────────────────────────────
  // 도면 축척 = «PDF 1포인트가 실제 몇 피트». 1인치 = 72포인트.

  function inches(t) {
    var m = String(t).trim().match(/^(\d+)\s+(\d+)\/(\d+)$/);
    if (m) return Number(m[1]) + Number(m[2]) / Number(m[3]);
    m = String(t).trim().match(/^(\d+)\/(\d+)$/);
    if (m) return Number(m[1]) / Number(m[2]);
    return Number(t);
  }

  /** 도면 글자에서 축척을 찾는다 — 건축(1/4" = 1'-0"), 토목(1" = 20'), 미터(SCALE 1:100). */
  function parseScales(text) {
    var t = String(text || '').replace(/[“”″]/g, '"').replace(/[‘’′]/g, "'").replace(/\s+/g, ' ');
    var out = [];
    var add = function (label, fpp) {
      if (!(fpp > 0.0005 && fpp < 10)) return;
      if (out.some(function (o) { return Math.abs(o.feetPerPoint - fpp) / fpp < 0.001; })) return;
      out.push({ label: label, feetPerPoint: fpp });
    };
    var m, re = /(\d+ \d+\/\d+|\d+\/\d+|\d+(?:\.\d+)?)\s*"\s*=\s*1\s*'\s*-?\s*0\s*"?/g;
    while ((m = re.exec(t))) { var x = inches(m[1]); if (x > 0) add(m[1] + '" = 1\'-0"', 1 / (x * 72)); }
    re = /(?:^|[^\d\/])1\s*"\s*=\s*(\d+(?:\.\d+)?)\s*'(?!\s*-?\s*\d)/g;
    while ((m = re.exec(t))) add('1" = ' + m[1] + "'", Number(m[1]) / 72);
    re = /SCALE\s*:?\s*1\s*:\s*(\d{2,4})\b/gi;
    while ((m = re.exec(t))) add('1:' + m[1], (25.4 / 72) * Number(m[1]) / 304.8);
    return out;
  }

  /** 모양의 실제 길이(피트)·면적(제곱피트). 좌표는 0~1, wPt·hPt 는 쪽 크기(포인트). */
  function measure(points, shape, wPt, hPt, fpp) {
    if (!fpp || !points || !points.length) return null;
    var pt = points.map(function (p) { return [p[0] * wPt, p[1] * hPt]; });
    var len = 0;
    for (var i = 1; i < pt.length; i++) len += Math.hypot(pt[i][0] - pt[i - 1][0], pt[i][1] - pt[i - 1][1]);
    var area = 0;
    if (shape === 'area' && pt.length > 2) {
      for (var j = 0; j < pt.length; j++) { var a = pt[j], b = pt[(j + 1) % pt.length]; area += a[0] * b[1] - b[0] * a[1]; }
      area = Math.abs(area) / 2;
      len += Math.hypot(pt[0][0] - pt[pt.length - 1][0], pt[0][1] - pt[pt.length - 1][1]);
    }
    return { lengthFt: len * fpp, areaSqft: area * fpp * fpp };
  }

  /** 잰 값을 줄의 단위로. 면적 단위인데 선으로 그렸으면(벽) 높이를 곱한다. 모르는 단위는 null. */
  function qtyFor(unit, shape, m, heightFt) {
    if (!m) return null;
    var u = String(unit || '').toUpperCase().replace(/\s/g, '');
    var areaUnit = /^(LF2|SF|SQFT|SQLF|FT2|M2|SY|YD2)$/.test(u);
    var sqft = shape === 'area' ? m.areaSqft : (areaUnit && heightFt > 0 ? m.lengthFt * heightFt : null);
    if (areaUnit) {
      if (sqft === null) return null;
      return u === 'M2' ? sqft * 0.09290304 : (u === 'SY' || u === 'YD2') ? sqft / 9 : sqft;
    }
    if (/^(LF|FT|LM)$/.test(u)) return m.lengthFt;
    if (u === 'M') return m.lengthFt * 0.3048;
    return null;
  }

  /** 12.5 → 12'-6" */
  function fmtFeet(ft) {
    var total = Math.round(ft * 12);
    return Math.floor(total / 12) + "'-" + (total % 12) + '"';
  }

  /** 사람이 적은 길이 → 피트. 12'-6" · 12' 6 · 12.5 · 3.8m */
  function parseLength(text) {
    var t = String(text || '').trim().toLowerCase().replace(/[’′]/g, "'").replace(/[”″]/g, '"');
    var m = t.match(/^(\d+(?:\.\d+)?)\s*m$/);
    if (m) return Number(m[1]) / 0.3048;
    m = t.match(/^(\d+(?:\.\d+)?)\s*'\s*-?\s*(\d+(?:\.\d+)?)?\s*"?$/);
    if (m) return Number(m[1]) + (m[2] ? Number(m[2]) / 12 : 0);
    m = t.match(/^(\d+(?:\.\d+)?)\s*"$/);
    if (m) return Number(m[1]) / 12;
    m = t.match(/^(\d+(?:\.\d+)?)$/);
    return m ? Number(m[1]) : null;
  }

  /** 거의 가로·세로면 가로·세로로 — 벽은 대개 반듯하다. 좌표는 화면 비율 맞춘 픽셀. */
  function snapPoint(prev, next, W, H) {
    var dx = (next[0] - prev[0]) * W, dy = (next[1] - prev[1]) * H;
    var tol = Math.tan(7 * Math.PI / 180);
    if (Math.abs(dy) <= Math.abs(dx) * tol) return [next[0], prev[1]];
    if (Math.abs(dx) <= Math.abs(dy) * tol) return [prev[0], next[1]];
    return next;
  }

  function centroid(points) {
    var x = 0, y = 0;
    points.forEach(function (p) { x += p[0]; y += p[1]; });
    return [x / points.length, y / points.length];
  }

  function markTitle(m) {
    if (m.line) return '#' + m.line.lineNo + ' ' + m.line.description;
    return m.label || '메모';
  }

  function markShort(m) {
    if (!m.line) return m.label || '메모';
    var r = m.record;
    return '#' + m.line.lineNo + (r ? ' · ' + (STAGE[r.stage] || r.stage) + ' ' + num(r.verifiedQty !== null ? r.verifiedQty : r.reportedQty) + ' ' + (m.line.unit || '') : '');
  }

  // ── 화면 ───────────────────────────────────────────────────────────

  var CSS = '' +
    '.dm-wrap{position:fixed;inset:0;z-index:10050;background:#0b1220;display:flex;flex-direction:column;color:#e2e8f0;font-family:inherit;animation:dmIn .18s ease-out}' +
    '@keyframes dmIn{from{opacity:0;transform:scale(.99)}to{opacity:1;transform:none}}' +
    '.dm-head{display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(15,23,42,.9);backdrop-filter:blur(10px);border-bottom:1px solid rgba(148,163,184,.15);flex-wrap:wrap}' +
    '.dm-title{font-weight:800;font-size:15px;color:#f8fafc;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:46vw}' +
    '.dm-sub{font-size:12px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '.dm-chips{display:flex;gap:6px;flex-wrap:wrap;margin-left:auto}' +
    '.dm-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;border:1px solid rgba(148,163,184,.25);background:rgba(30,41,59,.7);color:#cbd5e1;font-size:12px;cursor:pointer;user-select:none;transition:all .15s}' +
    '.dm-chip.off{opacity:.38}' +
    '.dm-dot{width:9px;height:9px;border-radius:50%;display:inline-block}' +
    '.dm-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:34px;min-width:34px;padding:0 10px;border-radius:10px;border:1px solid rgba(148,163,184,.22);background:rgba(30,41,59,.75);color:#e2e8f0;font-size:13px;cursor:pointer;transition:background .15s,border-color .15s}' +
    '.dm-btn:hover{background:rgba(51,65,85,.9)}' +
    '.dm-btn.on{background:#0ea5e9;border-color:#0ea5e9;color:#fff}' +
    '.dm-btn.primary{background:#10b981;border-color:#10b981;color:#fff;font-weight:700}' +
    '.dm-body{flex:1;display:flex;min-height:0}' +
    '.dm-stage{position:relative;flex:1;overflow:hidden;touch-action:none;background:radial-gradient(circle at 50% 40%,#1e293b 0,#0b1220 70%);cursor:grab}' +
    '.dm-stage.drawing{cursor:crosshair}' +
    '.dm-world{position:absolute;left:0;top:0;transform-origin:0 0;will-change:transform;box-shadow:0 20px 60px rgba(0,0,0,.55)}' +
    '.dm-world canvas{display:block;background:#fff}' +
    '.dm-world svg{position:absolute;left:0;top:0;overflow:visible}' +
    '.dm-mark{cursor:pointer}' +
    '.dm-mark:hover .dm-shape{filter:brightness(1.12)}' +
    '.dm-sel .dm-shape{filter:drop-shadow(0 0 6px rgba(255,255,255,.9))}' +
    '.dm-tools{position:absolute;left:50%;bottom:18px;transform:translateX(-50%);display:flex;gap:6px;padding:6px;border-radius:14px;background:rgba(15,23,42,.88);backdrop-filter:blur(10px);border:1px solid rgba(148,163,184,.2);box-shadow:0 10px 30px rgba(0,0,0,.4);flex-wrap:wrap;justify-content:center;max-width:calc(100% - 24px)}' +
    '.dm-zoom{position:absolute;right:14px;top:14px;display:flex;flex-direction:column;gap:6px}' +
    '.dm-hint{position:absolute;left:50%;top:14px;transform:translateX(-50%);padding:8px 14px;border-radius:999px;background:rgba(14,165,233,.92);color:#fff;font-size:13px;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,.35);max-width:calc(100% - 120px);text-align:center}' +
    '.dm-side{width:300px;border-left:1px solid rgba(148,163,184,.15);background:rgba(15,23,42,.92);overflow:auto;padding:10px}' +
    '.dm-item{display:flex;gap:10px;padding:9px 10px;border-radius:10px;cursor:pointer;align-items:flex-start}' +
    '.dm-item:hover{background:rgba(51,65,85,.6)}' +
    '.dm-pop{position:absolute;z-index:3;width:300px;max-width:calc(100% - 24px);background:#0f172a;border:1px solid rgba(148,163,184,.25);border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.55);padding:14px;font-size:13px;animation:dmIn .12s ease-out}' +
    '.dm-pop img{width:84px;height:64px;object-fit:cover;border-radius:8px;border:1px solid rgba(148,163,184,.3)}' +
    '.dm-form{position:absolute;z-index:4;left:50%;top:50%;transform:translate(-50%,-50%);width:380px;max-width:calc(100% - 24px);background:#0f172a;border:1px solid rgba(148,163,184,.25);border-radius:16px;padding:16px;box-shadow:0 24px 70px rgba(0,0,0,.6)}' +
    '.dm-form select,.dm-form textarea,.dm-form input{width:100%;box-sizing:border-box;margin-top:5px;padding:9px 10px;border-radius:10px;border:1px solid rgba(148,163,184,.3);background:#1e293b;color:#f1f5f9;font-size:13px;font-family:inherit}' +
    '.dm-empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:10px;color:#94a3b8;text-align:center;padding:30px}' +
    '.dm-spin{width:34px;height:34px;border-radius:50%;border:3px solid rgba(148,163,184,.25);border-top-color:#38bdf8;animation:dmSpin .8s linear infinite}' +
    '@keyframes dmSpin{to{transform:rotate(360deg)}}' +
    '@keyframes dmPulse{0%{r:14;opacity:.9}100%{r:44;opacity:0}}' +
    '@media (max-width:820px){.dm-side{display:none}.dm-side.show{display:block;position:absolute;right:0;top:0;bottom:0;z-index:5;width:86vw}.dm-title{max-width:60vw}.dm-chips{margin-left:0}}';

  function injectCss() {
    if (document.getElementById('dm-css')) return;
    var st = document.createElement('style');
    st.id = 'dm-css';
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  /**
   * @param {object} opts { siteId, sheetNo, title, mode:'view'|'pick', lines:[{id,lineNo,description,unit}], sectionId,
   *                        line (pick 모드의 줄), suggest (위치 글자), shape (기본 도구), onPick(geom), onChange() }
   */
  function open(opts) {
    injectCss();
    var st = {
      opts: opts, pick: opts.mode === 'pick', data: null, marks: [], filter: {}, tool: 'hand', draft: [], sel: null,
      W: 0, H: 0, k: 1, x: 0, y: 0, pointers: {}, gesture: null, suggestAt: null, labels: true,
      colorMode: 'status', picked: [],
      wPt: 0, hPt: 0, fpp: null, scaleLabel: null, scales: [], period: 'all', pendingLine: null, calibrating: false,
    };
    var wrap = document.createElement('div');
    wrap.className = 'dm-wrap';
    wrap.innerHTML =
      '<div class="dm-head">' +
        '<div style="min-width:0"><div class="dm-title" data-title>' + esc(opts.sheetNo) + '</div><div class="dm-sub" data-sub>불러오는 중…</div></div>' +
        '<div class="dm-chips" data-chips></div>' +
        '<div class="dm-chips" data-period style="margin-left:0"></div>' +
        '<button class="dm-btn" data-color title="색 기준 바꾸기">🎨 <span data-color-text>상태별</span></button>' +
        '<button class="dm-btn" data-scale title="도면 축척 — 그은 선·영역을 수량으로 바꾼다">📐 <span data-scale-text>축척</span></button>' +
        '<button class="dm-btn" data-export title="표시가 얹힌 도면을 그림 파일로">⬇ 내보내기</button>' +
        '<button class="dm-btn" data-list title="표시 목록">☰</button>' +
        '<button class="dm-btn" data-close title="닫기 (Esc)" style="font-size:16px">✕</button>' +
      '</div>' +
      '<div class="dm-body"><div class="dm-stage" data-stage>' +
        '<div class="dm-world" data-world><canvas data-canvas></canvas><svg data-svg xmlns="http://www.w3.org/2000/svg"></svg></div>' +
        '<div class="dm-empty" data-empty><div class="dm-spin"></div><div>도면을 그리는 중…</div></div>' +
        '<div class="dm-zoom">' +
          '<button class="dm-btn" data-zin title="확대" style="font-size:18px">+</button>' +
          '<button class="dm-btn" data-zout title="축소" style="font-size:18px">−</button>' +
          '<button class="dm-btn" data-fit title="전체 보기">⤢</button>' +
          '<button class="dm-btn on" data-labels title="이름표 보이기">Aa</button>' +
        '</div>' +
        '<div class="dm-tools" data-tools style="display:none"></div>' +
      '</div><div class="dm-side" data-side></div></div>';
    document.body.appendChild(wrap);
    var $ = function (sel) { return wrap.querySelector(sel); };
    var stage = $('[data-stage]'), world = $('[data-world]'), svg = $('[data-svg]'), canvas = $('[data-canvas]');

    function close() {
      document.removeEventListener('keydown', onKey);
      global.removeEventListener('resize', onResize);
      wrap.remove();
      if (st.task) try { st.task.destroy(); } catch (e) { /* 이미 닫힌 문서 */ }
    }
    function onKey(e) {
      if (e.key === 'Escape') { if (st.draft.length) { st.draft = []; draw(); } else if (st.sel) { select(null); } else close(); }
      if (e.key === 'Enter' && st.draft.length) finishDraft();
      if ((e.key === 'z' || e.key === 'Z') && (e.ctrlKey || e.metaKey) && st.draft.length) { st.draft.pop(); draw(); }
    }
    function onResize() { fit(); }
    document.addEventListener('keydown', onKey);
    global.addEventListener('resize', onResize);
    $('[data-close]').onclick = function () {
      if (st.picked.length && !global.confirm('그린 표시 ' + st.picked.length + '개를 저장하지 않고 닫을까요?')) return;
      close();
    };
    $('[data-list]').onclick = function () { $('[data-side]').classList.toggle('show'); };
    $('[data-zin]').onclick = function () { zoomAt(1.4); };
    $('[data-zout]').onclick = function () { zoomAt(1 / 1.4); };
    $('[data-fit]').onclick = function () { fit(); };
    $('[data-labels]').onclick = function () { st.labels = !st.labels; this.classList.toggle('on', st.labels); draw(); };
    $('[data-scale]').onclick = function () { scalePanel(); };
    $('[data-color]').onclick = function () {
      st.colorMode = st.colorMode === 'round' ? 'status' : 'round';
      st.filter = {};
      renderChips(); renderSide(); draw();
    };
    $('[data-export]').onclick = function () { exportPng(); };

    // ── 불러오기 ──
    call('api_getSheetMarks', [opts.siteId, opts.sheetNo]).then(function (r) {
      if (r.success === false) throw new Error(r.error || '도면을 불러오지 못했습니다.');
      st.data = r;
      st.marks = r.marks || [];
      // 청구된 표시가 있으면 처음부터 기성 회차별 색으로 — 사장이 가장 먼저 보고 싶은 것.
      if (!st.pick && st.marks.some(function (m) { return m.rounds && m.rounds.length; })) st.colorMode = 'round';
      var s = r.sheet;
      $('[data-title]').textContent = r.sheetNo + (s && s.title ? '  ' + s.title : '');
      $('[data-sub]').textContent = st.pick
        ? '작업한 곳을 찍으세요' + (opts.line ? ' — #' + opts.line.lineNo + ' ' + opts.line.description : '')
        : (s ? (s.file || '') + ' · ' + s.pageNo + '쪽' : '') + ' · 표시 ' + st.marks.length + '개';
      renderChips();
      renderSide();
      if (st.pick) { $('[data-side]').style.display = 'none'; $('[data-list]').style.display = 'none'; $('[data-export]').style.display = 'none'; }
      st.fpp = s && s.feetPerPoint ? s.feetPerPoint : null;
      st.scaleLabel = s ? s.scaleLabel : null;
      renderScale();
      renderPeriod();
      setupTools(r.canManage || st.pick);
      if (!s || !s.pdfUrl) {
        $('[data-empty]').innerHTML = '<i class="ph ph-file-dashed" style="font-size:40px"></i><div>이 번호의 도면 파일이 아직 없습니다.</div><div style="font-size:12px">공정별 도면에서 도면 PDF 를 올리면 여기 그려집니다.</div>';
        return;
      }
      return loadPdfJs().then(function (pdfjs) {
        st.task = pdfjs.getDocument({ url: s.pdfUrl, standardFontDataUrl: FONTS, withCredentials: true });
        return st.task.promise;
      }).then(function (pdf) { return pdf.getPage(s.pageNo); }).then(function (page) {
        var base = page.getViewport({ scale: 1 });
        st.wPt = base.width; st.hPt = base.height;
        st.text = page.getTextContent().catch(function () { return { items: [] }; });
        var longSide = global.innerWidth < 820 ? 3000 : 4200;
        var scale = Math.min(4, longSide / Math.max(base.width, base.height));
        var vp = page.getViewport({ scale: scale });
        canvas.width = Math.floor(vp.width);
        canvas.height = Math.floor(vp.height);
        st.W = canvas.width; st.H = canvas.height;
        canvas.style.width = st.W + 'px'; canvas.style.height = st.H + 'px';
        svg.setAttribute('width', st.W); svg.setAttribute('height', st.H);
        svg.setAttribute('viewBox', '0 0 ' + st.W + ' ' + st.H);
        return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp, background: '#ffffff' }).promise.then(function () {
          $('[data-empty]').style.display = 'none';
          fit();
          if (opts.focusMarkId) focusMark(opts.focusMarkId);
          detectScale();
          if (st.pick && opts.suggest) return suggestFrom(page, vp);
        });
      });
    }).catch(function (e) {
      $('[data-empty]').innerHTML = '<i class="ph ph-warning" style="font-size:40px"></i><div>' + esc(e.message || '도면을 열지 못했습니다.') + '</div>';
    });

    /** 위치 글자(예: PANTRY)를 도면의 글자에서 찾아 그 자리를 보여 준다 — 찍는 것은 사람이 한다. */
    function suggestFrom(page, vp) {
      return st.text.then(function (tc) {
        var terms = searchTerms(opts.suggest);
        for (var t = 0; t < terms.length; t++) {
          for (var i = 0; i < tc.items.length; i++) {
            var it = tc.items[i];
            if (String(it.str || '').toUpperCase().indexOf(terms[t]) === -1) continue;
            var p = vp.convertToViewportPoint(it.transform[4], it.transform[5]);
            st.suggestAt = { x: p[0] / st.W, y: p[1] / st.H, term: terms[t] };
            showHint('도면에서 «' + terms[t] + '» 를 찾았습니다. 그 근처를 찍으세요.');
            focusPoint(st.suggestAt.x, st.suggestAt.y, 3);
            return;
          }
        }
      }).catch(function () { /* 사진으로 된 도면은 글자 위치가 없다 */ });
    }

    // ── 보기 변환 ──
    function apply() {
      world.style.transform = 'translate(' + st.x + 'px,' + st.y + 'px) scale(' + st.k + ')';
      scheduleDraw();
    }
    function fit() {
      if (!st.W) return;
      var r = stage.getBoundingClientRect();
      st.k = Math.min(r.width / st.W, r.height / st.H) * 0.94;
      st.x = (r.width - st.W * st.k) / 2;
      st.y = (r.height - st.H * st.k) / 2;
      apply();
    }
    function zoomAt(f, cx, cy) {
      var r = stage.getBoundingClientRect();
      cx = cx === undefined ? r.width / 2 : cx;
      cy = cy === undefined ? r.height / 2 : cy;
      var min = Math.min(r.width / st.W, r.height / st.H) * 0.5;
      var k = Math.max(min, Math.min(8, st.k * f));
      st.x = cx - (cx - st.x) * (k / st.k);
      st.y = cy - (cy - st.y) * (k / st.k);
      st.k = k;
      apply();
    }
    function focusPoint(nx, ny, zoom) {
      var r = stage.getBoundingClientRect();
      var base = Math.min(r.width / st.W, r.height / st.H);
      st.k = Math.min(8, base * (zoom || 3));
      st.x = r.width / 2 - nx * st.W * st.k;
      st.y = r.height / 2 - ny * st.H * st.k;
      world.style.transition = 'transform .35s cubic-bezier(.2,.8,.2,1)';
      apply();
      setTimeout(function () { world.style.transition = ''; }, 380);
    }
    function focusMark(id) {
      var m = st.marks.filter(function (x) { return x.id === id; })[0];
      if (!m) return;
      var xs = m.points.map(function (p) { return p[0]; }), ys = m.points.map(function (p) { return p[1]; });
      var span = Math.max(Math.max.apply(null, xs) - Math.min.apply(null, xs), Math.max.apply(null, ys) - Math.min.apply(null, ys), 0.04);
      var c = centroid(m.points);
      focusPoint(c[0], c[1], Math.max(1.5, Math.min(6, 0.5 / span)));
      setTimeout(function () { select(m.id); }, 360);
    }
    function toWorld(clientX, clientY) {
      var r = stage.getBoundingClientRect();
      return [((clientX - r.left) - st.x) / st.k / st.W, ((clientY - r.top) - st.y) / st.k / st.H];
    }

    // ── 손 동작: 끌면 이동, 두 손가락이면 확대, 제자리 누름이면 점 찍기 ──
    stage.addEventListener('wheel', function (e) {
      e.preventDefault();
      var r = stage.getBoundingClientRect();
      zoomAt(Math.exp(-e.deltaY * 0.0015), e.clientX - r.left, e.clientY - r.top);
    }, { passive: false });
    stage.addEventListener('pointerdown', function (e) {
      if (e.target.closest && (e.target.closest('.dm-tools') || e.target.closest('.dm-zoom') || e.target.closest('.dm-pop') || e.target.closest('.dm-form'))) return;
      stage.setPointerCapture(e.pointerId);
      st.pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
      var ids = Object.keys(st.pointers);
      if (ids.length === 1) {
        st.gesture = { type: 'maybe', sx: e.clientX, sy: e.clientY, ox: st.x, oy: st.y, target: e.target };
      } else if (ids.length === 2) {
        var a = st.pointers[ids[0]], b = st.pointers[ids[1]];
        st.gesture = { type: 'pinch', d: Math.hypot(a.x - b.x, a.y - b.y), k: st.k, ox: st.x, oy: st.y, mx: (a.x + b.x) / 2, my: (a.y + b.y) / 2 };
      }
    });
    stage.addEventListener('pointermove', function (e) {
      if (!st.pointers[e.pointerId]) return;
      st.pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
      var g = st.gesture;
      if (!g) return;
      if (g.type === 'pinch') {
        var ids = Object.keys(st.pointers);
        if (ids.length < 2) return;
        var a = st.pointers[ids[0]], b = st.pointers[ids[1]];
        var r = stage.getBoundingClientRect();
        var k = Math.max(0.02, Math.min(8, g.k * Math.hypot(a.x - b.x, a.y - b.y) / g.d));
        var mx = g.mx - r.left, my = g.my - r.top;
        st.x = mx - (mx - g.ox) * (k / g.k) + ((a.x + b.x) / 2 - g.mx);
        st.y = my - (my - g.oy) * (k / g.k) + ((a.y + b.y) / 2 - g.my);
        st.k = k;
        apply();
        return;
      }
      if (g.type === 'maybe' && Math.hypot(e.clientX - g.sx, e.clientY - g.sy) > 6) g.type = 'pan';
      if (g.type === 'pan') {
        st.x = g.ox + (e.clientX - g.sx);
        st.y = g.oy + (e.clientY - g.sy);
        stage.style.cursor = 'grabbing';
        apply();
      }
    });
    function endPointer(e) {
      var g = st.gesture;
      delete st.pointers[e.pointerId];
      stage.style.cursor = '';
      if (g && g.type === 'maybe' && e.type === 'pointerup') tap(e, g.target);
      if (!Object.keys(st.pointers).length) st.gesture = null;
    }
    stage.addEventListener('pointerup', endPointer);
    stage.addEventListener('pointercancel', endPointer);
    stage.addEventListener('dblclick', function () { if (st.draft.length) finishDraft(); });

    function tap(e, target) {
      if (!st.W) return;
      if (st.tool === 'hand') {
        var mk = target && target.closest && target.closest('[data-mark]');
        select(mk ? Number(mk.getAttribute('data-mark')) : null, e);
        return;
      }
      var p = toWorld(e.clientX, e.clientY);
      if (p[0] < 0 || p[0] > 1 || p[1] < 0 || p[1] > 1) return;
      var last = st.draft[st.draft.length - 1];
      if (st.tool === 'area' && st.draft.length >= 3) {
        // 첫 점 가까이를 누르면 영역을 닫는다.
        var f = st.draft[0];
        if (Math.hypot((p[0] - f[0]) * st.W * st.k, (p[1] - f[1]) * st.H * st.k) < 16) { finishDraft(); return; }
      }
      if (last && (st.tool === 'line' || st.tool === 'area') && !e.altKey) p = snapPoint(last, p, st.W, st.H);
      st.draft.push([p[0], p[1]]);
      if (st.tool === 'point' || st.tool === 'note') { finishDraft(); return; }
      draw();
      renderTools();
    }

    // ── 축척 ──
    function renderScale() {
      var t = $('[data-scale-text]');
      if (!t) return;
      t.textContent = st.fpp ? (st.scaleLabel || '축척 있음') : '축척 맞추기';
      $('[data-scale]').classList.toggle('on', !st.fpp && !!(st.data && st.data.canManage));
    }
    /** 도면 글자에서 축척이 딱 하나 나오면 그것이 이 장의 축척이다 — 사람이 따로 맞출 필요가 없다. */
    function detectScale() {
      if (!st.text) return;
      st.text.then(function (tc) {
        st.scales = parseScales(tc.items.map(function (it) { return it.str; }).join(' '));
        if (!st.fpp && st.scales.length === 1 && st.data.canManage) saveScale(st.scales[0].feetPerPoint, '도면에서 읽음: ' + st.scales[0].label, true);
      });
    }
    function saveScale(fpp, label, quiet) {
      return call('api_setSheetScale', [opts.siteId, st.data.sheetNo, fpp, label]).then(function (r) {
        if (r.success === false) { toast(r.error || '축척을 적지 못했습니다.', 'error'); return; }
        st.fpp = r.feetPerPoint; st.scaleLabel = r.scaleLabel;
        renderScale(); renderTools();
        if (!quiet) toast('축척을 적었습니다. 이제 그은 선과 영역이 수량이 됩니다.');
        else showHint('도면에서 축척 ' + label.replace('도면에서 읽음: ', '') + ' 을 읽었습니다. 선을 그으면 길이가 나옵니다.');
      });
    }
    function scalePanel() {
      if (!st.data || !st.data.sheet) return;
      var old = stage.querySelector('.dm-form'); if (old) old.remove();
      var form = document.createElement('div');
      form.className = 'dm-form';
      var can = st.data.canManage;
      form.innerHTML = '<div style="font-weight:800;font-size:15px">도면 축척</div>' +
        '<div style="font-size:12px;color:#94a3b8;margin:4px 0 10px">축척이 있으면 선은 길이, 영역은 면적이 되어 기록 수량에 들어갑니다.</div>' +
        '<div style="font-size:13px;margin-bottom:10px">지금: <b>' + esc(st.fpp ? st.scaleLabel || '있음' : '없음') + '</b></div>' +
        (can && st.scales.length ? '<div style="font-size:12px;color:#94a3b8">도면에서 읽은 축척</div><div style="display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 10px">' +
          st.scales.map(function (x, i) { return '<button class="dm-btn" data-pick-scale="' + i + '">' + esc(x.label) + '</button>'; }).join('') + '</div>' : '') +
        (can ? '<button class="dm-btn primary" data-calibrate style="width:100%">📏 아는 치수로 맞추기 — 두 점을 찍고 실제 길이를 적기</button>' : '<div style="font-size:12px;color:#94a3b8">축척은 관리자가 맞춥니다.</div>') +
        '<div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="dm-btn" data-cancel>닫기</button></div>';
      stage.appendChild(form);
      form.querySelector('[data-cancel]').onclick = function () { form.remove(); };
      Array.prototype.forEach.call(form.querySelectorAll('[data-pick-scale]'), function (b) {
        b.onclick = function () { var x = st.scales[Number(b.getAttribute('data-pick-scale'))]; form.remove(); saveScale(x.feetPerPoint, '도면에서 읽음: ' + x.label); };
      });
      var cal = form.querySelector('[data-calibrate]');
      if (cal) cal.onclick = function () {
        form.remove();
        st.calibrating = true; st.tool = 'line'; st.draft = [];
        renderTools(); draw();
        showHint('치수를 아는 두 곳(예: 치수선 양 끝)을 차례로 누르세요.');
      };
    }
    function finishCalibration() {
      var a = st.draft[0], b = st.draft[1];
      var pts = Math.hypot((b[0] - a[0]) * st.wPt, (b[1] - a[1]) * st.hPt);
      var form = document.createElement('div');
      form.className = 'dm-form';
      form.innerHTML = '<div style="font-weight:800;font-size:15px">실제 길이</div>' +
        '<label style="display:block;font-size:12px;color:#94a3b8;margin-top:8px">두 점 사이의 실제 길이<input data-len placeholder="예) 12\'-6&quot; · 12.5 · 3.8m"></label>' +
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px"><button class="dm-btn" data-cancel>취소</button><button class="dm-btn primary" data-save>맞추기</button></div>';
      stage.appendChild(form);
      var done = function () { form.remove(); st.calibrating = false; st.draft = []; st.tool = 'hand'; renderTools(); draw(); };
      form.querySelector('[data-cancel]').onclick = done;
      form.querySelector('[data-save]').onclick = function () {
        var ft = parseLength(form.querySelector('[data-len]').value);
        if (!ft || !(pts > 0)) { toast('길이를 12\'-6" 처럼 적으세요.', 'error'); return; }
        var label = fmtFeet(ft) + ' 치수로 맞춤';
        done();
        saveScale(ft / pts, label);
      };
      form.querySelector('[data-len]').focus();
    }

    // ── 기간 ──
    function renderPeriod() {
      var box = $('[data-period]');
      if (!box || st.pick) return;
      var opts2 = [['all', '전체 기간'], ['this', '이번 달'], ['last', '지난 달']];
      box.innerHTML = opts2.map(function (o) { return '<span class="dm-chip' + (st.period === o[0] ? '' : ' off') + '" data-p="' + o[0] + '">' + o[1] + '</span>'; }).join('');
      Array.prototype.forEach.call(box.querySelectorAll('[data-p]'), function (c) {
        c.onclick = function () { st.period = c.getAttribute('data-p'); renderPeriod(); renderChips(); renderSide(); draw(); };
      });
    }
    function periodRange() {
      var t = String(opts.today || new Date().toISOString().slice(0, 10));
      var y = Number(t.slice(0, 4)), m = Number(t.slice(5, 7));
      if (st.period === 'last') { m -= 1; if (m === 0) { m = 12; y -= 1; } }
      var mm = (m < 10 ? '0' : '') + m;
      return [y + '-' + mm + '-01', y + '-' + mm + '-31'];
    }
    function periodText() {
      if (st.period === 'all') return '전체 기간';
      var r = periodRange();
      return r[0].slice(0, 7) + (st.period === 'this' ? ' (이번 달)' : ' (지난 달)');
    }

    // ── 도구 ──
    function setupTools(can) {
      if (!can) return;
      $('[data-tools]').style.display = 'flex';
      if (st.pick) st.tool = opts.shape || shapeForUnit(opts.line && opts.line.unit, opts.line && opts.line.description);
      renderTools();
      if (st.pick) showHint((st.tool === 'point' ? '작업한 곳을 한 번 누르세요.' : st.tool === 'line' ? '벽·배관을 따라 점을 차례로 누르고 «완료».' : '영역의 모서리를 차례로 누르고 «완료».') + ' 여러 개 그리고 설명을 붙일 수 있습니다.');
    }
    function renderTools() {
      var tools = TOOLS;
      var html = tools.map(function (t) {
        return '<button class="dm-btn' + (st.tool === t[0] ? ' on' : '') + '" data-tool="' + t[0] + '" title="' + t[2] + '"><i class="ph ' + t[1] + '"></i><span>' + t[2] + '</span></button>';
      }).join('');
      if (st.draft.length) {
        var mm = measure(st.draft, st.tool, st.wPt, st.hPt, st.fpp);
        if (mm && st.draft.length > 1) {
          html += '<span style="align-self:center;padding:0 8px;font-size:13px;font-weight:700;color:#7dd3fc">' +
            (st.tool === 'area' && st.draft.length > 2 ? Math.round(mm.areaSqft).toLocaleString() + ' sf · ' : '') + fmtFeet(mm.lengthFt) + '</span>';
        }
        html += '<span style="width:1px;background:rgba(148,163,184,.3);margin:4px 2px"></span>' +
          '<button class="dm-btn" data-undo title="마지막 점 지우기">↶</button>' +
          '<button class="dm-btn primary" data-done>✓ <span>완료</span></button>';
      }
      var box = $('[data-tools]');
      box.innerHTML = html;
      Array.prototype.forEach.call(box.querySelectorAll('[data-tool]'), function (b) {
        b.onclick = function () {
          st.tool = b.getAttribute('data-tool');
          st.draft = [];
          stage.classList.toggle('drawing', st.tool !== 'hand');
          renderTools(); draw();
        };
      });
      var u = box.querySelector('[data-undo]'); if (u) u.onclick = function () { st.draft.pop(); draw(); renderTools(); };
      var d = box.querySelector('[data-done]'); if (d) d.onclick = finishDraft;
      stage.classList.toggle('drawing', st.tool !== 'hand');
    }
    function finishDraft() {
      if (st.calibrating) {
        if (st.draft.length < 2) { toast('두 점을 찍으세요.', 'error'); return; }
        st.draft = st.draft.slice(0, 2);
        finishCalibration();
        return;
      }
      var shape = st.tool;
      var need = shape === 'line' ? 2 : shape === 'area' ? 3 : 1;
      if (st.draft.length < need) { toast(shape === 'line' ? '선은 두 점 이상 찍으세요.' : '영역은 세 점 이상 찍으세요.', 'error'); return; }
      var geom = { sheetNo: st.data.sheetNo, shape: shape, points: st.draft.map(function (p) { return [Math.round(p[0] * 1e6) / 1e6, Math.round(p[1] * 1e6) / 1e6]; }) };
      if (st.pick) {
        // 기록 창에서 연 도면 — 여러 개를 그리고 설명을 붙인 뒤 한 번에 기록과 함께 저장한다.
        var push = function (g) { st.picked.push(g); st.draft = []; renderTools(); draw(); renderPickPanel(); };
        if (shape === 'note') { askNote(geom, push); return; }
        withMeasure(geom, opts.line, push);
        return;
      }
      askAndSave(geom);
    }

    /**
     * 잰 수량을 붙여 돌려준다. 면적 단위의 줄을 선으로 그렸으면(벽) 높이를 한 번 묻는다 —
     * 규격에 «H:8'» 같은 높이가 있으면 그 값이 먼저 들어가고, 한 번 적은 높이는 이 기기가 기억한다.
     */
    function withMeasure(geom, line, done) {
      var m = measure(geom.points, geom.shape, st.wPt, st.hPt, st.fpp);
      if (!m || !line) { done(geom); return; }
      var u = String(line.unit || '').toUpperCase();
      var needsHeight = geom.shape === 'line' && /^(LF2|SF|SQFT|SQLF|FT2|M2|SY|YD2)$/.test(u);
      var finish = function (h) {
        var q = qtyFor(line.unit, geom.shape, m, h);
        if (q !== null) {
          geom.measured = { value: Math.round(q * 100) / 100, unit: line.unit,
            text: (geom.shape === 'area' ? Math.round(m.areaSqft) + ' sf' : fmtFeet(m.lengthFt)) + (h ? ' × 높이 ' + fmtFeet(h) : '') + ' → ' + (Math.round(q * 100) / 100) + ' ' + line.unit };
        }
        done(geom);
      };
      if (!needsHeight) { finish(null); return; }
      var key = 'dm-h-' + line.id, remembered = null;
      try { remembered = global.localStorage.getItem(key); } catch (e) { /* 저장소를 못 쓰는 창 */ }
      var fromSpec = String(line.spec || '').match(/H\s*[:=]\s*(\d+(?:\.\d+)?)\s*'/i);
      var form = document.createElement('div');
      form.className = 'dm-form';
      form.innerHTML = '<div style="font-weight:800;font-size:15px">벽 높이</div>' +
        '<div style="font-size:12px;color:#94a3b8;margin-top:4px">길이 ' + fmtFeet(m.lengthFt) + ' — 면적(' + esc(line.unit) + ')은 길이 × 높이입니다.</div>' +
        '<input data-h value="' + esc(remembered || (fromSpec ? fromSpec[1] + "'" : '')) + '" placeholder="예) 10\' · 9\'-6&quot;">' +
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px"><button class="dm-btn" data-skip>수량 없이</button><button class="dm-btn primary" data-save>계산</button></div>';
      stage.appendChild(form);
      form.querySelector('[data-h]').focus();
      form.querySelector('[data-skip]').onclick = function () { form.remove(); finish(null); };
      form.querySelector('[data-save]').onclick = function () {
        var raw = form.querySelector('[data-h]').value;
        var h = parseLength(raw);
        if (!h) { toast('높이를 10\' 처럼 적으세요.', 'error'); return; }
        try { global.localStorage.setItem(key, raw); } catch (e) { /* 기억 못 해도 계산은 한다 */ }
        form.remove();
        finish(h);
      };
    }

    /** 기록 창에서 연 도면의 메모 — 도면 위에 글로 남는다. */
    function askNote(geom, done) {
      var form = document.createElement('div');
      form.className = 'dm-form';
      form.innerHTML = '<div style="font-weight:800;font-size:15px">도면에 적을 메모</div>' +
        '<textarea data-text rows="3" placeholder="예) 여기까지 설치 · 내일 마감 · 천장 개구부 확인 필요"></textarea>' +
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px"><button class="dm-btn" data-cancel>취소</button><button class="dm-btn primary" data-save>붙이기</button></div>';
      stage.appendChild(form);
      form.querySelector('[data-text]').focus();
      form.querySelector('[data-cancel]').onclick = function () { form.remove(); st.draft = []; draw(); renderTools(); };
      form.querySelector('[data-save]').onclick = function () {
        var t = form.querySelector('[data-text]').value.trim();
        if (!t) { toast('메모를 적으세요.', 'error'); return; }
        form.remove();
        geom.label = t;
        done(geom);
      };
    }

    /** 기록 창에서 연 도면의 «이번에 그린 것» — 목록, 도면에 붙일 설명, 저장. */
    function renderPickPanel() {
      var old = stage.querySelector('.dm-pickpanel');
      var keepText = old ? old.querySelector('[data-desc]').value : (opts.note || '');
      if (old) old.remove();
      if (!st.picked.length) return;
      var panel = document.createElement('div');
      panel.className = 'dm-pickpanel dm-form';
      panel.style.cssText = 'left:16px;top:auto;bottom:86px;transform:none;width:340px';
      var name = { point: '점', line: '선', area: '영역', note: '메모' };
      panel.innerHTML = '<div style="font-weight:800;font-size:14px;margin-bottom:6px">이번에 그린 표시 ' + st.picked.length + '개</div>' +
        st.picked.map(function (g, i) {
          return '<div style="display:flex;gap:8px;align-items:center;font-size:12px;padding:5px 0;border-bottom:1px solid rgba(148,163,184,.15)">' +
            '<span style="color:#38bdf8;font-weight:700">' + name[g.shape] + '</span><span style="flex:1;color:#cbd5e1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
            esc(g.shape === 'note' ? g.label : g.measured ? g.measured.text : '') + '</span>' +
            '<button class="dm-btn" data-drop="' + i + '" style="height:26px;min-width:26px;padding:0 6px">✕</button></div>';
        }).join('') +
        '<label style="display:block;font-size:12px;color:#94a3b8;margin-top:8px">도면에 붙일 설명 (붙여넣기 가능)<textarea data-desc rows="3" placeholder="예) 북쪽 벽 1차 마감 완료"></textarea></label>' +
        '<div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:10px"><span style="font-size:11px;color:#64748b">아래 도구로 더 그릴 수 있습니다</span>' +
        '<button class="dm-btn primary" data-finish>✓ 기록 창으로</button></div>';
      stage.appendChild(panel);
      panel.querySelector('[data-desc]').value = keepText;
      Array.prototype.forEach.call(panel.querySelectorAll('[data-drop]'), function (b) {
        b.onclick = function () { st.picked.splice(Number(b.getAttribute('data-drop')), 1); draw(); renderPickPanel(); };
      });
      panel.querySelector('[data-finish]').onclick = function () {
        var measured = st.picked.filter(function (g) { return g.shape !== 'note'; });
        var total = measured.length && measured.every(function (g) { return g.measured; })
          ? Math.round(measured.reduce(function (t, g) { return t + g.measured.value; }, 0) * 100) / 100 : null;
        if (opts.onPick) opts.onPick({ sheetNo: st.data.sheetNo, shapes: st.picked.slice(), label: panel.querySelector('[data-desc]').value.trim(),
          measuredTotal: total, unit: opts.line ? opts.line.unit : null });
        st.picked = [];
        close();
      };
    }

    /** 보기 화면에서 더한 표시 — 어느 줄의 것인지(또는 메모만) 묻고 저장. */
    function askAndSave(geom) {
      var lines = opts.lines || [];
      var form = document.createElement('div');
      form.className = 'dm-form';
      form.innerHTML = '<div style="font-weight:800;font-size:15px;margin-bottom:8px">' + ({ point: '점', line: '선', area: '영역', note: '메모' }[geom.shape]) + ' 저장</div>' +
        (geom.shape !== 'note' && lines.length ? '<label style="font-size:12px;color:#94a3b8">계약 줄<select data-line><option value="">— 줄 없이 메모만 —</option>' +
          lines.map(function (l) { return '<option value="' + l.id + '"' + (st.pendingLine && st.pendingLine.id === l.id ? ' selected' : '') + '>#' + esc(l.lineNo) + ' ' + esc(l.description) + '</option>'; }).join('') + '</select></label>' : '') +
        '<label style="display:block;font-size:12px;color:#94a3b8;margin-top:10px">메모<textarea data-label rows="3" placeholder="예) 여기까지 설치 · 내일 마감"></textarea></label>' +
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px"><button class="dm-btn" data-cancel>취소</button><button class="dm-btn primary" data-save>저장</button></div>';
      stage.appendChild(form);
      form.querySelector('[data-cancel]').onclick = function () { form.remove(); st.draft = []; draw(); renderTools(); };
      form.querySelector('[data-save]').onclick = function () {
        var sel = form.querySelector('[data-line]');
        var payload = { siteId: opts.siteId, sheetNo: geom.sheetNo, shape: geom.shape, points: geom.points, sectionId: opts.sectionId,
          lineId: sel && sel.value ? Number(sel.value) : null, label: form.querySelector('[data-label]').value };
        call('api_saveDrawingMark', [payload]).then(function (r) {
          if (r.success === false) { toast(r.error || '저장하지 못했습니다.', 'error'); return; }
          form.remove();
          st.draft = [];
          st.pendingLine = null;
          st.marks.push(r.mark);
          renderChips(); renderSide(); renderTools(); draw();
          toast('도면에 표시했습니다.');
          if (opts.onChange) opts.onChange();
        });
      };
    }

    // ── 그리기 ──
    var pending = false;
    function scheduleDraw() {
      if (pending) return;
      pending = true;
      global.requestAnimationFrame(function () { pending = false; draw(); });
    }
    function visible(m) {
      if (st.filter[keyOf(m, st.colorMode)]) return false;
      if (st.period === 'all') return true;
      if (!m.record || !m.record.workDate) return false;
      var r = periodRange();
      return m.record.workDate >= r[0] && m.record.workDate <= r[1];
    }
    function draw() {
      if (!st.W) return;
      svg.innerHTML = build(st.k, false);
    }
    function build(k, exporting) {
      var W = st.W, H = st.H;
      var placed = [];   // 이미 놓은 이름표 — 겹치면 위로 비켜 놓는다
      var px = function (v) { return v / k; };   // 화면 v 픽셀 = 도면 좌표 몇
      var out = [];
      out.push('<defs><filter id="dmShadow" x="-50%" y="-50%" width="200%" height="200%"><feDropShadow dx="0" dy="' + px(1.5) + '" stdDeviation="' + px(2) + '" flood-color="#000" flood-opacity=".35"/></filter></defs>');
      st.marks.forEach(function (m) {
        if (!visible(m)) return;
        var c = styleOf(keyOf(m, st.colorMode)).color;
        var pts = m.points.map(function (p) { return [p[0] * W, p[1] * H]; });
        var dash = m.status === 'plan' ? ' stroke-dasharray="' + px(8) + ' ' + px(6) + '"' : '';
        var g = '<g class="dm-mark' + (st.sel === m.id ? ' dm-sel' : '') + '" data-mark="' + m.id + '">';
        if (m.shape === 'line') {
          var d = pts.map(function (p) { return p[0] + ',' + p[1]; }).join(' ');
          g += '<polyline points="' + d + '" fill="none" stroke="#fff" stroke-opacity=".9" stroke-width="' + px(9) + '" stroke-linecap="round" stroke-linejoin="round"/>' +
            '<polyline class="dm-shape" points="' + d + '" fill="none" stroke="' + c + '" stroke-width="' + px(5) + '" stroke-linecap="round" stroke-linejoin="round"' + dash + '/>' +
            pts.map(function (p) { return '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="' + px(4) + '" fill="#fff" stroke="' + c + '" stroke-width="' + px(2) + '"/>'; }).join('');
        } else if (m.shape === 'area') {
          g += '<polygon class="dm-shape" points="' + pts.map(function (p) { return p[0] + ',' + p[1]; }).join(' ') + '" fill="' + c + '" fill-opacity=".2" stroke="' + c + '" stroke-width="' + px(2.5) + '" stroke-linejoin="round"' + dash + '/>';
        } else if (m.shape === 'note') {
          var n = pts[0];
          g += '<g class="dm-shape" filter="url(#dmShadow)">' +
            '<rect x="' + (n[0] - px(15)) + '" y="' + (n[1] - px(40)) + '" width="' + px(30) + '" height="' + px(28) + '" rx="' + px(8) + '" fill="' + c + '" stroke="#fff" stroke-width="' + px(2) + '"/>' +
            '<path d="M' + (n[0] - px(6)) + ' ' + (n[1] - px(13)) + ' L' + n[0] + ' ' + n[1] + ' L' + (n[0] + px(6)) + ' ' + (n[1] - px(13)) + ' Z" fill="' + c + '"/>' +
            '<text x="' + n[0] + '" y="' + (n[1] - px(26)) + '" font-size="' + px(15) + '" fill="#fff" text-anchor="middle" dominant-baseline="central">✎</text></g>';
        } else {
          var q = pts[0];
          g += '<g class="dm-shape" filter="url(#dmShadow)"><path d="M' + q[0] + ' ' + q[1] + ' L' + (q[0] - px(9)) + ' ' + (q[1] - px(15)) +
            ' A' + px(11) + ' ' + px(11) + ' 0 1 1 ' + (q[0] + px(9)) + ' ' + (q[1] - px(15)) + ' Z" fill="' + c + '" stroke="#fff" stroke-width="' + px(2) + '"/>' +
            '<circle cx="' + q[0] + '" cy="' + (q[1] - px(22)) + '" r="' + px(4) + '" fill="#fff"/></g>';
        }
        if (st.labels) g += labelSvg(m, pts, c, px, placed);
        out.push(g + '</g>');
      });
      // 이번에 그린 것(아직 저장 전) — 하늘색 실선.
      if (!exporting) st.picked.forEach(function (g) {
        var pp = g.points.map(function (p) { return [p[0] * W, p[1] * H]; });
        var ps = pp.map(function (p) { return p[0] + ',' + p[1]; }).join(' ');
        if (g.shape === 'line') out.push('<polyline points="' + ps + '" fill="none" stroke="#fff" stroke-width="' + px(9) + '" stroke-linecap="round"/><polyline points="' + ps + '" fill="none" stroke="#0ea5e9" stroke-width="' + px(5) + '" stroke-linecap="round"/>');
        else if (g.shape === 'area') out.push('<polygon points="' + ps + '" fill="#0ea5e9" fill-opacity=".22" stroke="#0ea5e9" stroke-width="' + px(2.5) + '"/>');
        else out.push('<circle cx="' + pp[0][0] + '" cy="' + pp[0][1] + '" r="' + px(9) + '" fill="' + (g.shape === 'note' ? '#8b5cf6' : '#0ea5e9') + '" stroke="#fff" stroke-width="' + px(2) + '"/>');
        if (g.shape === 'note') out.push('<text x="' + pp[0][0] + '" y="' + (pp[0][1] - px(18)) + '" font-size="' + px(13) + '" font-weight="700" fill="#4c1d95" stroke="#fff" stroke-width="' + px(3) + '" paint-order="stroke" text-anchor="middle">' + esc(g.label) + '</text>');
      });
      // 그리는 중인 모양
      if (st.draft.length && !exporting) {
        var dp = st.draft.map(function (p) { return [p[0] * W, p[1] * H]; });
        var ds = dp.map(function (p) { return p[0] + ',' + p[1]; }).join(' ');
        out.push(st.tool === 'area' && dp.length > 2
          ? '<polygon points="' + ds + '" fill="#0ea5e9" fill-opacity=".18" stroke="#0ea5e9" stroke-width="' + px(2.5) + '" stroke-dasharray="' + px(7) + ' ' + px(5) + '"/>'
          : '<polyline points="' + ds + '" fill="none" stroke="#0ea5e9" stroke-width="' + px(4) + '" stroke-linecap="round" stroke-dasharray="' + px(7) + ' ' + px(5) + '"/>');
        dp.forEach(function (p) { out.push('<circle cx="' + p[0] + '" cy="' + p[1] + '" r="' + px(5) + '" fill="#fff" stroke="#0ea5e9" stroke-width="' + px(2.5) + '"/>'); });
      }
      if (st.suggestAt && !exporting) {
        var sx = st.suggestAt.x * W, sy = st.suggestAt.y * H;
        out.push('<circle cx="' + sx + '" cy="' + sy + '" r="' + px(14) + '" fill="none" stroke="#0ea5e9" stroke-width="' + px(3) + '"><animate attributeName="r" from="' + px(10) + '" to="' + px(40) + '" dur="1.4s" repeatCount="indefinite"/><animate attributeName="opacity" from=".9" to="0" dur="1.4s" repeatCount="indefinite"/></circle>');
      }
      return out.join('');
    }
    function labelSvg(m, pts, color, px, placed) {
      var text = markShort(m);
      if (m.line && m.label) text += ' · ' + (m.label.length > 22 ? m.label.slice(0, 21) + '…' : m.label);
      // 이름표가 표시를 가리지 않게 — 점·메모는 위에, 선은 선 위쪽에, 영역은 가운데.
      var top = Math.min.apply(null, pts.map(function (p) { return p[1]; }));
      var at = m.shape === 'point' ? [pts[0][0], pts[0][1] - px(48)]
        : m.shape === 'note' ? [pts[0][0], pts[0][1] - px(56)]
        : m.shape === 'line' ? [centroid(pts)[0], top - px(20)]
        : [centroid(pts)[0], centroid(pts)[1]];
      var w = 0;
      for (var i = 0; i < text.length; i++) w += text.charCodeAt(i) > 255 ? 12 : 6.6;
      w = px(w + 16);
      var h = px(22);
      var x0 = at[0] - w / 2, y0 = at[1] - h / 2;
      var hits = function () {
        return placed.some(function (r) { return x0 < r[0] + r[2] && x0 + w > r[0] && y0 < r[1] + r[3] && y0 + h > r[1]; });
      };
      for (var tries = 0; tries < 8 && hits(); tries++) y0 -= h + px(4);
      placed.push([x0, y0, w, h]);
      return '<g transform="translate(' + x0 + ',' + y0 + ')" pointer-events="none">' +
        '<rect width="' + w + '" height="' + h + '" rx="' + px(11) + '" fill="#0f172a" fill-opacity=".86" stroke="' + color + '" stroke-width="' + px(1.5) + '"/>' +
        '<text x="' + (w / 2) + '" y="' + (h / 2) + '" font-size="' + px(12) + '" font-weight="700" fill="#f8fafc" text-anchor="middle" dominant-baseline="central" font-family="system-ui,sans-serif">' + esc(text) + '</text></g>';
    }

    // ── 선택·설명 카드 ──
    function select(id, e) {
      st.sel = id;
      draw();
      var old = stage.querySelector('.dm-pop');
      if (old) old.remove();
      if (!id) return;
      var m = st.marks.filter(function (x) { return x.id === id; })[0];
      if (!m) return;
      var s = styleOf(keyOf(m, st.colorMode));
      var r = m.record;
      var photos = r && r.evidence && r.evidence.length
        ? '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">' + r.evidence.map(function (ev) {
          return '<a href="' + esc(ev.url) + '" target="_blank" rel="noopener" title="' + esc(ev.title) + '">' +
            (ev.image ? '<img src="' + esc(ev.url) + '" alt="" loading="lazy">' : '<span style="display:inline-flex;align-items:center;gap:4px;padding:6px 8px;border-radius:8px;background:#1e293b;color:#93c5fd;font-size:12px"><i class="ph ph-file"></i>' + esc(ev.title) + '</span>') + '</a>';
        }).join('') + '</div>' : '';
      var pop = document.createElement('div');
      pop.className = 'dm-pop';
      pop.innerHTML =
        '<div style="display:flex;gap:8px;align-items:center"><span class="dm-dot" style="background:' + s.color + '"></span><b style="color:' + s.color + '">' + s.label + '</b>' +
          (m.line && m.line.section ? '<span style="margin-left:auto;font-size:11px;color:#94a3b8">' + esc(m.line.section) + '</span>' : '') + '</div>' +
        '<div style="font-weight:800;font-size:14px;margin-top:6px;color:#f8fafc">' + esc(markTitle(m)) + '</div>' +
        (r ? '<div style="margin-top:6px;color:#cbd5e1">' + esc(STAGE[r.stage] || r.stage) + ' ' + num(r.reportedQty) + ' ' + esc(m.line.unit || '') +
          (r.verifiedQty !== null ? ' · 확인 ' + num(r.verifiedQty) : '') + '</div><div style="color:#94a3b8;font-size:12px">' + esc(r.workDate || '') + ' · ' + esc(r.location || '') + '</div>' : '') +
        (function () {
          var mm = m.shape === 'line' || m.shape === 'area' ? measure(m.points, m.shape, st.wPt, st.hPt, st.fpp) : null;
          return mm ? '<div style="margin-top:4px;color:#7dd3fc;font-size:12px">도면에서 잰 값 ' + (m.shape === 'area' ? Math.round(mm.areaSqft).toLocaleString() + ' sf' : fmtFeet(mm.lengthFt)) + '</div>' : '';
        })() +
        (m.rounds && m.rounds.length ? '<div style="margin-top:6px;display:flex;gap:4px;flex-wrap:wrap">' + m.rounds.map(function (x) {
          return '<span style="padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;color:#fff;background:' + styleOf('r' + x.no).color + '">' + x.no + '차 기성 · ' + num(x.qty) + (x.status === 'draft' ? ' (작성 중)' : '') + '</span>';
        }).join('') + '</div>' : '') +
        (m.label ? '<div style="margin-top:8px;padding:8px 10px;border-radius:10px;background:#1e293b;color:#e2e8f0;white-space:pre-wrap">' + esc(m.label) + '</div>' : '') +
        (r && r.notes ? '<div style="margin-top:6px;color:#94a3b8;font-size:12px;white-space:pre-wrap">' + esc(r.notes) + '</div>' : '') +
        photos +
        '<div style="display:flex;gap:6px;align-items:center;margin-top:12px;font-size:11px;color:#64748b">' + esc((m.createdBy || '') + (m.createdAt ? ' · ' + m.createdAt.slice(0, 10) : '')) +
          (st.data.canManage && !st.pick ? '<button class="dm-btn" data-del style="margin-left:auto;height:28px;color:#fca5a5">지우기</button>' : '') + '</div>';
      stage.appendChild(pop);
      var rect = stage.getBoundingClientRect();
      var cx = e ? e.clientX - rect.left : rect.width / 2, cy = e ? e.clientY - rect.top : rect.height / 2;
      pop.style.left = Math.max(12, Math.min(rect.width - pop.offsetWidth - 12, cx + 14)) + 'px';
      pop.style.top = Math.max(12, Math.min(rect.height - pop.offsetHeight - 12, cy - 20)) + 'px';
      var del = pop.querySelector('[data-del]');
      if (del) del.onclick = function () {
        call('api_deleteDrawingMark', [m.id]).then(function (res) {
          if (res.success === false) { toast(res.error || '지우지 못했습니다.', 'error'); return; }
          st.marks = st.marks.filter(function (x) { return x.id !== m.id; });
          select(null); renderChips(); renderSide();
          if (opts.onChange) opts.onChange();
        });
      };
    }

    // ── 범례·목록 ──
    function renderChips() {
      if (st.data && !st.pick) {
        var sh = st.data.sheet;
        $('[data-sub]').textContent = (sh ? (sh.file || '') + ' · ' + sh.pageNo + '쪽 · ' : '') + '표시 ' + st.marks.length + '개';
      }
      var ct = $('[data-color-text]');
      if (ct) ct.textContent = st.colorMode === 'round' ? '기성 회차별' : '상태별';
      var counts = {};
      st.marks.forEach(function (m) { var key = keyOf(m, st.colorMode); counts[key] = (counts[key] || 0) + 1; });
      var box = $('[data-chips]');
      box.innerHTML = Object.keys(counts).sort(function (x, y) { return styleOf(x).order - styleOf(y).order; }).map(function (k) {
        var sty = styleOf(k);
        return '<span class="dm-chip' + (st.filter[k] ? ' off' : '') + '" data-f="' + k + '"><span class="dm-dot" style="background:' + sty.color + '"></span>' + sty.label + ' ' + counts[k] + '</span>';
      }).join('');
      Array.prototype.forEach.call(box.querySelectorAll('[data-f]'), function (c) {
        c.onclick = function () { var k = c.getAttribute('data-f'); st.filter[k] = !st.filter[k]; renderChips(); renderSide(); draw(); };
      });
    }
    function renderSide() {
      var side = $('[data-side]');
      var list = st.marks.filter(visible);
      side.innerHTML = '<div style="font-size:12px;font-weight:800;color:#94a3b8;margin:4px 4px 8px">표시 ' + list.length + '개</div>' +
        (list.length ? list.map(function (m) {
          var s = styleOf(keyOf(m, st.colorMode));
          return '<div class="dm-item" data-go="' + m.id + '"><span class="dm-dot" style="background:' + s.color + ';margin-top:5px;flex-shrink:0"></span>' +
            '<div style="min-width:0"><div style="font-size:13px;font-weight:700;color:#f1f5f9;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(markTitle(m)) + '</div>' +
            '<div style="font-size:12px;color:#94a3b8">' + esc(s.label + (m.record ? ' · ' + (STAGE[m.record.stage] || '') + ' ' + num(m.record.reportedQty) + ' ' + (m.line.unit || '') + ' · ' + (m.record.workDate || '') : m.label ? ' · ' + m.label : '')) + '</div></div></div>';
        }).join('') : '<div style="padding:20px 6px;color:#64748b;font-size:13px">아직 표시가 없습니다. 줄에서 «기록» 할 때 도면에 찍거나, 아래 도구로 더하세요.</div>');
      // 이 도면에 아직 표시가 없는 이 공정의 줄 — 남은 일. 누르면 바로 그 줄을 찍는다.
      var marked = {};
      st.marks.forEach(function (m) { if (m.line) marked[m.line.id] = true; });
      var left = (opts.lines || []).filter(function (l) { return !marked[l.id]; });
      if (left.length && st.data && st.data.canManage) {
        side.insertAdjacentHTML('beforeend', '<div style="font-size:12px;font-weight:800;color:#94a3b8;margin:16px 4px 8px">이 도면에 표시 안 된 줄 ' + left.length + '개</div>' +
          left.map(function (l) {
            return '<div class="dm-item" data-todo="' + l.id + '"><span class="dm-dot" style="background:transparent;border:2px dashed #64748b;margin-top:4px;flex-shrink:0"></span>' +
              '<div style="min-width:0"><div style="font-size:13px;color:#cbd5e1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">#' + esc(l.lineNo) + ' ' + esc(l.description) + '</div>' +
              '<div style="font-size:11px;color:#64748b">눌러서 위치 찍기</div></div></div>';
          }).join(''));
      }
      Array.prototype.forEach.call(side.querySelectorAll('[data-go]'), function (el) {
        el.onclick = function () { side.classList.remove('show'); focusMark(Number(el.getAttribute('data-go'))); };
      });
      Array.prototype.forEach.call(side.querySelectorAll('[data-todo]'), function (el) {
        el.onclick = function () {
          side.classList.remove('show');
          st.pendingLine = (opts.lines || []).filter(function (l) { return l.id === Number(el.getAttribute('data-todo')); })[0];
          st.tool = shapeForUnit(st.pendingLine.unit, st.pendingLine.description);
          st.draft = [];
          renderTools(); draw();
          showHint('#' + st.pendingLine.lineNo + ' ' + st.pendingLine.description + ' — 위치를 찍으세요.');
        };
      });
    }
    /** 표시가 얹힌 도면을 그림 파일로 — 청구서에 붙이는 증거. 지금 고른 기간·상태만 담는다. */
    function exportPng() {
      if (!st.W) return;
      var k = Math.min(1, 1500 / st.W);   // 이름표가 1500px 너비로 볼 때 읽히는 크기
      var band = Math.round(120 / k);
      var out = document.createElement('canvas');
      out.width = st.W; out.height = st.H + band;
      var ctx = out.getContext('2d');
      ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, out.width, out.height);
      ctx.drawImage(canvas, 0, band);
      var svgDoc = '<svg xmlns="http://www.w3.org/2000/svg" width="' + st.W + '" height="' + st.H + '" viewBox="0 0 ' + st.W + ' ' + st.H + '">' + build(k, true) + '</svg>';
      var img = new Image();
      var url = URL.createObjectURL(new Blob([svgDoc], { type: 'image/svg+xml' }));
      img.onload = function () {
        ctx.drawImage(img, 0, band);
        URL.revokeObjectURL(url);
        var f = function (px) { return Math.round(px / k); };
        ctx.fillStyle = '#0f172a'; ctx.fillRect(0, 0, out.width, band);
        ctx.fillStyle = '#f8fafc'; ctx.font = '800 ' + f(26) + 'px system-ui,sans-serif';
        ctx.fillText(st.data.sheetNo + (st.data.sheet && st.data.sheet.title ? '  ' + st.data.sheet.title : ''), f(24), f(44));
        ctx.fillStyle = '#94a3b8'; ctx.font = f(15) + 'px system-ui,sans-serif';
        var shown = st.marks.filter(visible);
        ctx.fillText(periodText() + ' · 표시 ' + shown.length + '개 · 축척 ' + (st.scaleLabel || '없음') + ' · 내보낸 날 ' + String(opts.today || ''), f(24), f(76));
        var x = f(24);
        var byKey = {};
        shown.forEach(function (m) { var key = keyOf(m, st.colorMode); byKey[key] = (byKey[key] || 0) + 1; });
        Object.keys(byKey).sort(function (p, q) { return styleOf(p).order - styleOf(q).order; }).forEach(function (key) {
          var n = byKey[key];
          var sty = styleOf(key);
          ctx.fillStyle = sty.color; ctx.beginPath(); ctx.arc(x + f(6), f(100), f(6), 0, Math.PI * 2); ctx.fill();
          ctx.fillStyle = '#e2e8f0'; ctx.font = '600 ' + f(14) + 'px system-ui,sans-serif';
          var label = sty.label + ' ' + n;
          ctx.fillText(label, x + f(18), f(105));
          x += f(34) + ctx.measureText(label).width;
        });
        out.toBlob(function (blob) {
          var a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = st.data.sheetNo + '_' + (st.period === 'all' ? 'all' : periodRange()[0].slice(0, 7)) + '.png';
          document.body.appendChild(a); a.click(); a.remove();
          setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
          toast('도면을 그림 파일로 내려받았습니다.');
        }, 'image/png');
      };
      img.onerror = function () { toast('그림 파일을 만들지 못했습니다.', 'error'); };
      img.src = url;
    }

    function showHint(text) {
      var old = stage.querySelector('.dm-hint');
      if (old) old.remove();
      var h = document.createElement('div');
      h.className = 'dm-hint';
      h.textContent = text;
      stage.appendChild(h);
      setTimeout(function () { if (h.parentNode) { h.style.transition = 'opacity .4s'; h.style.opacity = '0'; setTimeout(function () { h.remove(); }, 420); } }, 5200);
    }

    return { close: close, state: st };
  }

  global.DrawingMarker = {
    open: open,
    _shapeForUnit: shapeForUnit,
    _searchTerms: searchTerms,
    _statusColors: STATUS,
    _keyOf: keyOf,
    _styleOf: styleOf,
    _parseScales: parseScales,
    _measure: measure,
    _qtyFor: qtyFor,
    _fmtFeet: fmtFeet,
    _parseLength: parseLength,
    _snapPoint: snapPoint,
  };
})(window);

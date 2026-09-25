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
      W: 0, H: 0, k: 1, x: 0, y: 0, pointers: {}, gesture: null, suggestAt: null, labels: true, textItems: null,
    };
    var wrap = document.createElement('div');
    wrap.className = 'dm-wrap';
    wrap.innerHTML =
      '<div class="dm-head">' +
        '<div style="min-width:0"><div class="dm-title" data-title>' + esc(opts.sheetNo) + '</div><div class="dm-sub" data-sub>불러오는 중…</div></div>' +
        '<div class="dm-chips" data-chips></div>' +
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
    $('[data-close]').onclick = close;
    $('[data-list]').onclick = function () { $('[data-side]').classList.toggle('show'); };
    $('[data-zin]').onclick = function () { zoomAt(1.4); };
    $('[data-zout]').onclick = function () { zoomAt(1 / 1.4); };
    $('[data-fit]').onclick = function () { fit(); };
    $('[data-labels]').onclick = function () { st.labels = !st.labels; this.classList.toggle('on', st.labels); draw(); };

    // ── 불러오기 ──
    call('api_getSheetMarks', [opts.siteId, opts.sheetNo]).then(function (r) {
      if (r.success === false) throw new Error(r.error || '도면을 불러오지 못했습니다.');
      st.data = r;
      st.marks = r.marks || [];
      var s = r.sheet;
      $('[data-title]').textContent = r.sheetNo + (s && s.title ? '  ' + s.title : '');
      $('[data-sub]').textContent = st.pick
        ? '작업한 곳을 찍으세요' + (opts.line ? ' — #' + opts.line.lineNo + ' ' + opts.line.description : '')
        : (s ? (s.file || '') + ' · ' + s.pageNo + '쪽' : '') + ' · 표시 ' + st.marks.length + '개';
      renderChips();
      renderSide();
      if (st.pick) { $('[data-side]').style.display = 'none'; $('[data-list]').style.display = 'none'; }
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
          if (st.pick && opts.suggest) return suggestFrom(page, vp);
        });
      });
    }).catch(function (e) {
      $('[data-empty]').innerHTML = '<i class="ph ph-warning" style="font-size:40px"></i><div>' + esc(e.message || '도면을 열지 못했습니다.') + '</div>';
    });

    /** 위치 글자(예: PANTRY)를 도면의 글자에서 찾아 그 자리를 보여 준다 — 찍는 것은 사람이 한다. */
    function suggestFrom(page, vp) {
      return page.getTextContent().then(function (tc) {
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
      st.draft.push([p[0], p[1]]);
      if (st.tool === 'point' || st.tool === 'note') { finishDraft(); return; }
      draw();
      renderTools();
    }

    // ── 도구 ──
    function setupTools(can) {
      if (!can) return;
      $('[data-tools]').style.display = 'flex';
      if (st.pick) st.tool = opts.shape || shapeForUnit(opts.line && opts.line.unit, opts.line && opts.line.description);
      renderTools();
      if (st.pick) showHint(st.tool === 'point' ? '작업한 곳을 한 번 누르세요.' : st.tool === 'line' ? '벽·배관을 따라 점을 차례로 누르고 «완료».' : '영역의 모서리를 차례로 누르고 «완료».');
    }
    function renderTools() {
      var tools = TOOLS.filter(function (t) { return !(st.pick && t[0] === 'note'); });
      var html = tools.map(function (t) {
        return '<button class="dm-btn' + (st.tool === t[0] ? ' on' : '') + '" data-tool="' + t[0] + '" title="' + t[2] + '"><i class="ph ' + t[1] + '"></i><span>' + t[2] + '</span></button>';
      }).join('');
      if (st.draft.length) {
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
      var shape = st.tool;
      var need = shape === 'line' ? 2 : shape === 'area' ? 3 : 1;
      if (st.draft.length < need) { toast(shape === 'line' ? '선은 두 점 이상 찍으세요.' : '영역은 세 점 이상 찍으세요.', 'error'); return; }
      var geom = { sheetNo: st.data.sheetNo, shape: shape, points: st.draft.map(function (p) { return [Math.round(p[0] * 1e6) / 1e6, Math.round(p[1] * 1e6) / 1e6]; }) };
      if (st.pick) {
        if (opts.onPick) opts.onPick(geom);
        close();
        return;
      }
      askAndSave(geom);
    }

    /** 보기 화면에서 더한 표시 — 어느 줄의 것인지(또는 메모만) 묻고 저장. */
    function askAndSave(geom) {
      var lines = opts.lines || [];
      var form = document.createElement('div');
      form.className = 'dm-form';
      form.innerHTML = '<div style="font-weight:800;font-size:15px;margin-bottom:8px">' + ({ point: '점', line: '선', area: '영역', note: '메모' }[geom.shape]) + ' 저장</div>' +
        (geom.shape !== 'note' && lines.length ? '<label style="font-size:12px;color:#94a3b8">계약 줄<select data-line><option value="">— 줄 없이 메모만 —</option>' +
          lines.map(function (l) { return '<option value="' + l.id + '">#' + esc(l.lineNo) + ' ' + esc(l.description) + '</option>'; }).join('') + '</select></label>' : '') +
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
    function visible(m) { return !st.filter[m.status]; }
    function draw() {
      if (!st.W) return;
      var k = st.k, W = st.W, H = st.H;
      var px = function (v) { return v / k; };   // 화면 v 픽셀 = 도면 좌표 몇
      var out = [];
      out.push('<defs><filter id="dmShadow" x="-50%" y="-50%" width="200%" height="200%"><feDropShadow dx="0" dy="' + px(1.5) + '" stdDeviation="' + px(2) + '" flood-color="#000" flood-opacity=".35"/></filter></defs>');
      st.marks.forEach(function (m) {
        if (!visible(m)) return;
        var c = (STATUS[m.status] || STATUS.note).color;
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
        if (st.labels) g += labelSvg(m, pts, c, px);
        out.push(g + '</g>');
      });
      // 그리는 중인 모양
      if (st.draft.length) {
        var dp = st.draft.map(function (p) { return [p[0] * W, p[1] * H]; });
        var ds = dp.map(function (p) { return p[0] + ',' + p[1]; }).join(' ');
        out.push(st.tool === 'area' && dp.length > 2
          ? '<polygon points="' + ds + '" fill="#0ea5e9" fill-opacity=".18" stroke="#0ea5e9" stroke-width="' + px(2.5) + '" stroke-dasharray="' + px(7) + ' ' + px(5) + '"/>'
          : '<polyline points="' + ds + '" fill="none" stroke="#0ea5e9" stroke-width="' + px(4) + '" stroke-linecap="round" stroke-dasharray="' + px(7) + ' ' + px(5) + '"/>');
        dp.forEach(function (p) { out.push('<circle cx="' + p[0] + '" cy="' + p[1] + '" r="' + px(5) + '" fill="#fff" stroke="#0ea5e9" stroke-width="' + px(2.5) + '"/>'); });
      }
      if (st.suggestAt) {
        var sx = st.suggestAt.x * W, sy = st.suggestAt.y * H;
        out.push('<circle cx="' + sx + '" cy="' + sy + '" r="' + px(14) + '" fill="none" stroke="#0ea5e9" stroke-width="' + px(3) + '"><animate attributeName="r" from="' + px(10) + '" to="' + px(40) + '" dur="1.4s" repeatCount="indefinite"/><animate attributeName="opacity" from=".9" to="0" dur="1.4s" repeatCount="indefinite"/></circle>');
      }
      svg.innerHTML = out.join('');
    }
    function labelSvg(m, pts, color, px) {
      var text = markShort(m);
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
      return '<g transform="translate(' + (at[0] - w / 2) + ',' + (at[1] - h / 2) + ')" pointer-events="none">' +
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
      var s = STATUS[m.status] || STATUS.note;
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
      var counts = {};
      st.marks.forEach(function (m) { counts[m.status] = (counts[m.status] || 0) + 1; });
      var box = $('[data-chips]');
      box.innerHTML = Object.keys(STATUS).filter(function (k) { return counts[k]; }).map(function (k) {
        return '<span class="dm-chip' + (st.filter[k] ? ' off' : '') + '" data-f="' + k + '"><span class="dm-dot" style="background:' + STATUS[k].color + '"></span>' + STATUS[k].label + ' ' + counts[k] + '</span>';
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
          var s = STATUS[m.status] || STATUS.note;
          return '<div class="dm-item" data-go="' + m.id + '"><span class="dm-dot" style="background:' + s.color + ';margin-top:5px;flex-shrink:0"></span>' +
            '<div style="min-width:0"><div style="font-size:13px;font-weight:700;color:#f1f5f9;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(markTitle(m)) + '</div>' +
            '<div style="font-size:12px;color:#94a3b8">' + esc(s.label + (m.record ? ' · ' + (STAGE[m.record.stage] || '') + ' ' + num(m.record.reportedQty) + ' ' + (m.line.unit || '') + ' · ' + (m.record.workDate || '') : m.label ? ' · ' + m.label : '')) + '</div></div></div>';
        }).join('') : '<div style="padding:20px 6px;color:#64748b;font-size:13px">아직 표시가 없습니다. 줄에서 «기록» 할 때 도면에 찍거나, 아래 도구로 더하세요.</div>');
      Array.prototype.forEach.call(side.querySelectorAll('[data-go]'), function (el) {
        el.onclick = function () { side.classList.remove('show'); focusMark(Number(el.getAttribute('data-go'))); };
      });
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
  };
})(window);

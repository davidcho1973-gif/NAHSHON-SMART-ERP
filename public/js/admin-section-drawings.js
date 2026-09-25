/**
 * 공정별 도면 — 계약서의 공정마다 «그 일을 그 위에 그리고 적을 도면» 을 고른다.
 *
 * 사장 지시(2026-09-25): 「사진으로 되어 있을 경우 도면을 올리면 자동으로 글자를 읽어 붙이게.
 * 공정별로 사용할 페이지를 선정해서 도면 위에 그리거나 글을 적는 기능으로 활용할 거야.」
 *
 * 이 화면이 하는 일 셋:
 *  1. 도면 읽기 — 문서함의 도면 PDF 를 이 브라우저가 한 쪽씩 그려 서버로 보낸다. 글자가 든 쪽은
 *     글자를, 사진으로 된 쪽은 네 조각 사진을 보내고 서버의 AI 가 글자를 읽어 붙인다.
 *     안 읽은 파일이 있으면 화면을 여는 것만으로 읽기가 시작된다.
 *  2. 공정별 선택 — 공정마다 도면 번호를 고른다. 번호로 저장하므로 개정판이 와도 선택이 산다.
 *  3. 확인 — 도면을 누르면 크게 보이고, AI 가 읽은 글자와 번호를 사람이 고칠 수 있다.
 *  4. 기성 — 원청 계약 기성표(엑셀)를 올리면 계약서의 줄이 공정 아래에 붙고, 공정마다
 *     확인된 기성과 진행률이 보인다. 사장: «기성관리가 공정관리다». 금액은 기성 근거 대장이
 *     계산한 값을 그대로 보여 준다. 줄의 기록·확인은 대장 화면에서 한다.
 */
(function (global) {
  'use strict';

  var PDFJS = '/vendor/pdfjs/pdf.min.js';
  var PDFJS_WORKER = '/vendor/pdfjs/pdf.worker.min.js';
  var FONTS = '/vendor/pdfjs/standard_fonts/';

  // 사진 쪽 판독 해상도 — 44×34 인치 도면을 가로 6000 화소로 그려 2×2 로 자른다.
  // 한 장으로 넘기면 방 이름·치수 글자가 뭉개진다. 작은 종이는 확대 배율을 4 로 막는다.
  var OCR_LONG_SIDE = 6000;
  var OCR_MAX_SCALE = 4;
  var THUMB_WIDTH = 900;

  var A = null;
  var state = { data: null, pdfjs: null, reading: false, progress: {}, poll: null, auto: true };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function active() { return global._currentView === 'section-drawings' || !global._currentView; }

  function csrf() {
    var el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') : '';
  }

  function request(url, method, body) {
    var opts = { method: method || 'GET', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } };
    if (body instanceof FormData) opts.body = body;
    else if (body) { opts.body = JSON.stringify(body); opts.headers['Content-Type'] = 'application/json'; }
    return fetch(url, opts).then(function (r) {
      if (r.status === 413) return { success: false, error: '파일이 서버 한도를 넘었습니다.' };
      return r.json().catch(function () { return { success: false, error: '응답을 읽지 못했습니다. (HTTP ' + r.status + ')' }; })
        .then(function (j) { if (!r.ok && j && j.success !== false) j.success = false; if (!r.ok && j && !j.error) j.error = j.message || ('HTTP ' + r.status); return j; });
    });
  }

  function money(v) {
    if (v === null || v === undefined) return '';
    var n = Number(v);
    return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString(undefined, { maximumFractionDigits: 0 });
  }

  function pct(v) { return v === null || v === undefined ? '—' : (Math.round(v * 10) / 10) + '%'; }

  function bar(percent) {
    var w = Math.max(0, Math.min(100, Number(percent) || 0));
    return '<div style="height:6px;border-radius:3px;background:var(--bg-base);overflow:hidden;min-width:80px">' +
      '<div style="height:100%;width:' + w + '%;background:' + (w >= 100 ? 'var(--status-success)' : 'var(--brand-primary)') + '"></div></div>';
  }

  // ── PDF.js ─────────────────────────────────────────────────────────

  function loadPdfJs() {
    if (state.pdfjs) return Promise.resolve(state.pdfjs);
    // 모듈 파일이지만 확장자를 .js 로 두었다 — 어떤 서버도 .js 는 자바스크립트로 내보낸다.
    return import(PDFJS).then(function (m) {
      m.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
      state.pdfjs = m;
      return m;
    });
  }

  /** 도면에 뽑을 만한 글자가 들어 있는가 — CAD 서브셋 글꼴은 알아볼 수 없는 기호를 뱉는다. */
  function meaningful(text) {
    var s = String(text || '').replace(/\s+/g, '');
    if (!s.length) return false;
    var good = (s.match(/[A-Za-z0-9가-힣]/g) || []).length;
    return good >= 150 && good / s.length >= 0.5;
  }

  function renderBlob(page, scale, x, y, w, h, quality) {
    var vp = page.getViewport({ scale: scale, offsetX: -x, offsetY: -y });
    var canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(w));
    canvas.height = Math.max(1, Math.round(h));
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    return page.render({ canvasContext: ctx, viewport: vp, background: '#ffffff' }).promise.then(function () {
      return new Promise(function (resolve, reject) {
        canvas.toBlob(function (b) {
          canvas.width = 0; canvas.height = 0;   // 큰 캔버스 메모리를 바로 돌려준다
          if (b) resolve(b); else reject(new Error('그림을 만들지 못했습니다.'));
        }, 'image/jpeg', quality);
      });
    });
  }

  function renderTiles(page) {
    var base = page.getViewport({ scale: 1 });
    var scale = Math.min(OCR_MAX_SCALE, OCR_LONG_SIDE / Math.max(base.width, base.height));
    var W = base.width * scale, H = base.height * scale;
    var tw = Math.ceil(W / 2), th = Math.ceil(H / 2);
    var spots = [[0, 0], [tw, 0], [0, th], [tw, th]];   // 왼쪽 위 · 오른쪽 위 · 왼쪽 아래 · 오른쪽 아래
    var out = [];
    return spots.reduce(function (p, s) {
      return p.then(function () {
        return renderBlob(page, scale, s[0], s[1], Math.min(tw, W - s[0]), Math.min(th, H - s[1]), 0.85)
          .then(function (b) { out.push(b); });
      });
    }, Promise.resolve()).then(function () { return out; });
  }

  function readPage(pdf, doc, n) {
    return pdf.getPage(n).then(function (page) {
      var base = page.getViewport({ scale: 1 });
      return page.getTextContent().then(function (tc) {
        var text = tc.items.map(function (it) { return (it.str || '') + (it.hasEOL ? '\n' : ' '); }).join('');
        var scale = THUMB_WIDTH / base.width;
        return renderBlob(page, scale, 0, 0, base.width * scale, base.height * scale, 0.72).then(function (thumb) {
          var fd = new FormData();
          fd.append('width', String(base.width));
          fd.append('height', String(base.height));
          fd.append('thumb', thumb, 'thumb.jpg');
          if (meaningful(text)) { fd.append('text', text); return fd; }
          // 사진으로 된 쪽 — 네 조각을 보내면 서버의 AI 가 글자를 읽어 붙인다.
          return renderTiles(page).then(function (tiles) {
            tiles.forEach(function (b, i) { fd.append('tiles[]', b, 't' + i + '.jpg'); });
            return fd;
          });
        });
      }).then(function (fd) {
        return request('/drawing-api/documents/' + doc.id + '/pages/' + n, 'POST', fd);
      }).then(function (res) {
        page.cleanup();
        if (!res || res.success === false) throw new Error((res && res.error) || (n + '쪽을 보내지 못했습니다.'));
        return res;
      });
    });
  }

  /** 파일 하나를 읽는다. force 가 아니면 아직 안 읽었거나 실패한 쪽만. */
  function readDocument(doc, force) {
    var u = ui();
    setProgress(doc.id, '파일 여는 중…');
    var task = null;   // 이 판(PDF.js 6)에서는 문서가 아니라 불러오기 작업이 destroy() 를 갖는다
    return loadPdfJs().then(function (pdfjs) {
      task = pdfjs.getDocument({ url: doc.pdfUrl, withCredentials: true, standardFontDataUrl: FONTS });
      return task.promise;
    }).then(function (pdf) {
      return request('/drawing-api/documents/' + doc.id + '/start', 'POST', { page_count: pdf.numPages }).then(function (res) {
        if (!res || res.success === false) throw new Error((res && res.error) || '읽기를 시작하지 못했습니다.');
        var todo = (res.sheets || []).filter(function (s) {
          return force || s.status === 'pending' || s.status === 'failed';
        }).map(function (s) { return s.pageNo; });
        var done = 0;
        return todo.reduce(function (p, n) {
          return p.then(function () {
            setProgress(doc.id, (done + 1) + ' / ' + todo.length + '쪽 보내는 중…');
            return readPage(pdf, doc, n).then(function () { done++; });
          });
        }, Promise.resolve()).then(function () { return todo.length; });
      });
    }).then(function (count) {
      setProgress(doc.id, count ? '보냈습니다. AI 가 읽는 중…' : null);
      startPolling();
      return count;
    }).catch(function (e) {
      setProgress(doc.id, null);
      u.toast((doc.title || '도면') + ': ' + (e.message || '읽지 못했습니다.'), 'error');
    }).finally(function () {
      if (task) task.destroy();
    });
  }

  function setProgress(docId, text) {
    if (text) state.progress[docId] = text; else delete state.progress[docId];
    var el = document.getElementById('sd-prog-' + docId);
    if (el) el.textContent = text || '';
  }

  /** 줄 서서 하나씩 — 도면 여러 개를 한꺼번에 그리면 브라우저 메모리가 모자란다. */
  function readQueue(docs, force) {
    if (state.reading || !docs.length) return Promise.resolve();
    state.reading = true;
    return docs.reduce(function (p, d) {
      return p.then(function () { return readDocument(d, force); });
    }, Promise.resolve()).finally(function () {
      state.reading = false;
      if (active()) reload();
    });
  }

  function startPolling() {
    if (state.poll) return;
    state.poll = setInterval(function () {
      if (!active()) { clearInterval(state.poll); state.poll = null; return; }
      call('api_getSectionDrawings', []).then(function (res) {
        if (res.success === false) return;
        var busy = (res.documents || []).some(function (d) {
          return (d.sheets || []).some(function (s) { return s.status === 'reading'; });
        });
        state.data = res;
        paint(render());
        if (!busy && !state.reading) { clearInterval(state.poll); state.poll = null; }
      }).catch(function () { /* 현장 회선이 끊겼다 붙어도 계속 되묻는다 */ });
    }, 5000);
  }

  // ── 그리기 ─────────────────────────────────────────────────────────

  function docsPanel(u, d) {
    var rows = (d.documents || []).map(function (doc) {
      var reading = (doc.sheets || []).filter(function (s) { return s.status === 'reading'; }).length;
      var status = doc.pages === 0 ? '아직 안 읽음'
        : doc.done + ' / ' + doc.pages + '쪽 읽음' + (doc.ocr ? ' · 사진 ' + doc.ocr + '쪽은 AI 가 읽음' : '') +
          (reading ? ' · ' + reading + '쪽 읽는 중' : '') + (doc.failed ? ' · ' + doc.failed + '쪽 실패' : '');
      var act = d.canManage
        ? (doc.failed || doc.pages === 0 ? u.rowButton(doc.pages ? '실패한 쪽 다시 읽기' : '읽기', 'window.AdminSectionDrawings.read(' + doc.id + ')') : '') + ' ' +
          (doc.pages ? u.rowButton('전부 다시 읽기', 'window.AdminSectionDrawings.read(' + doc.id + ',true)') : '')
        : '';
      return '<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-top:1px solid var(--border-subtle);flex-wrap:wrap">' +
        '<i class="ph ph-file-pdf" style="font-size:18px;color:var(--status-danger)"></i>' +
        '<div style="flex:1;min-width:200px"><div style="font-size:13px;font-weight:600">' + u.esc(doc.title) + '</div>' +
          '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(status) +
          ' <span id="sd-prog-' + doc.id + '" style="color:var(--brand-primary);font-weight:600">' + u.esc(state.progress[doc.id] || '') + '</span></div></div>' +
        '<div style="display:flex;gap:4px;flex-wrap:wrap">' + act + '</div></div>';
    }).join('');

    var empty = '<div style="padding:16px 14px;font-size:13px;color:var(--text-tertiary)">이 현장에 도면 PDF 가 없습니다. 「도면 올리기」 로 올리세요. 문서함에 이미 있다면 현장이 지정됐는지 확인하세요.</div>';
    var other = d.canManage && (d.otherPdfs || []).length
      ? '<div style="padding:8px 14px;border-top:1px solid var(--border-subtle)">' +
        u.rowButton('문서함의 다른 PDF 를 도면으로 쓰기 (' + d.otherPdfs.length + ')', 'window.AdminSectionDrawings.pickOther()') + '</div>'
      : '';

    return '<div style="border:1px solid var(--border-default);border-radius:12px;overflow:hidden;margin-bottom:14px;background:var(--bg-surface)">' +
      '<div style="padding:10px 14px;background:var(--bg-base);display:flex;justify-content:space-between;align-items:center">' +
        '<div style="font-size:14px;font-weight:700">도면 파일</div>' +
        '<div style="font-size:12px;color:var(--text-secondary)">사진으로 된 쪽은 AI 가 글자를 읽어 붙입니다</div></div>' +
      (rows || empty) + other + '</div>';
  }

  function sheetChip(u, pick) {
    var s = pick.sheet;
    if (!s) {
      return '<div title="이 번호의 도면이 아직 읽히지 않았습니다" style="width:150px;border:1px dashed var(--border-default);border-radius:10px;padding:8px;background:var(--bg-base)">' +
        '<div style="height:96px;display:flex;align-items:center;justify-content:center;color:var(--text-tertiary);font-size:11px;text-align:center">파일 없음<br>또는 아직 안 읽음</div>' +
        '<div style="font-size:12px;font-weight:700;margin-top:6px">' + u.esc(pick.sheetNo) + '</div></div>';
    }
    return '<div onclick="window.AdminSectionDrawings.openSheet(' + s.id + ')" style="width:150px;border:1px solid var(--border-default);border-radius:10px;padding:8px;cursor:pointer;background:var(--bg-surface)">' +
      (s.thumbUrl ? '<img src="' + u.esc(s.thumbUrl) + '" alt="" loading="lazy" style="width:100%;height:96px;object-fit:contain;background:#fff;border-radius:6px">'
        : '<div style="height:96px;background:var(--bg-base);border-radius:6px"></div>') +
      '<div style="font-size:12px;font-weight:700;margin-top:6px">' + u.esc(pick.sheetNo) + '</div>' +
      '<div style="font-size:11px;color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + u.esc(s.title || '') + '</div>' +
      (s.textSource === 'ocr' ? '<div style="font-size:10px;color:var(--status-warning)">사진 → AI 판독</div>' : '') +
      '</div>';
  }

  /** 공정 카드의 기성 줄 — 계약 줄이 올라온 공정만. */
  function sectionMoney(u, s) {
    var b = s.billing;
    if (!b) return '';
    return '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 10px">' +
      '<div style="flex:1;min-width:140px">' + bar(b.percent) + '</div>' +
      '<span style="font-size:12px;font-weight:700">' + pct(b.percent) + '</span>' +
      '<span style="font-size:12px;color:var(--text-secondary)">기성 ' + money(b.earned) + ' / 계약 ' + money(b.amount) + '</span>' +
      (b.storedOnSite > 0 ? '<span style="font-size:12px;color:var(--text-secondary)">반입 자재 ' + money(b.storedOnSite) + '</span>' : '') +
      (b.pendingCount ? '<span style="font-size:12px;color:var(--status-warning)">확인 대기 ' + b.pendingCount + '건</span>' : '') +
      (b.gaps ? '<span style="font-size:12px;color:var(--status-danger)">반입 기록 빠짐 ' + b.gaps + '줄</span>' : '') +
      u.rowButton('줄 ' + b.lines + '개 보기', 'window.AdminSectionDrawings.lines(' + s.id + ')') +
      '</div>';
  }

  /** 현장 전체 기성 — 계약서가 올라왔으면 합계, 안 올라왔으면 올리라는 안내. */
  function billingPanel(u, d) {
    var b = d.billing;
    if (!b) return '';
    if (!b.lines) {
      return b.canImport ? '<div style="border:1px dashed var(--border-default);border-radius:12px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:var(--text-secondary)">' +
        '원청 계약 기성표(엑셀)를 올리면 계약서의 줄이 공정 아래에 붙고, 줄마다 진행률과 기성 금액이 보입니다. 위의 <b>계약서 올리기</b>를 누르세요.</div>' : '';
    }
    var cells = [
      ['계약', money(b.contractAmount !== null ? b.contractAmount : b.lineTotal), b.contractAmount !== null && Math.abs(b.contractAmount - b.lineTotal) >= 0.01 ? '줄 합계 ' + money(b.lineTotal) + ' · 절사 ' + money(b.contractAmount - b.lineTotal) : '줄 ' + b.lines + '개'],
      ['확인된 기성', money(b.earned), pct(b.percent) + ' · 사람이 확인한 수량 × 계약 단가'],
      ['반입 자재 (미설치)', money(b.storedOnSite), '청구서에 따로 적는 칸'],
      ['확인 대기', (b.pendingCount || 0) + '건', '기록은 됐고 아직 확인 전'],
    ];
    return '<div style="border:1px solid var(--border-default);border-radius:12px;padding:14px 16px;margin-bottom:16px;background:var(--bg-surface)">' +
      '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px"><span style="font-size:14px;font-weight:800;flex:1">기성 = 공정 · ' + u.esc(b.contractTitle || '') + '</span>' +
      (b.contractId ? u.rowButton('기성 근거 대장 열기', 'window.AdminSectionDrawings.ledger(' + b.contractId + ')') : '') + '</div>' +
      bar(b.percent) +
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-top:12px">' + cells.map(function (c) {
        return '<div><div style="font-size:12px;color:var(--text-secondary)">' + u.esc(c[0]) + '</div><div style="font-size:18px;font-weight:800;margin:2px 0">' + u.esc(c[1]) + '</div>' +
          '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(c[2]) + '</div></div>';
      }).join('') + '</div></div>';
  }

  function sectionsPanel(u, d) {
    if (!(d.sections || []).length) {
      return '<div style="padding:30px;text-align:center;color:var(--text-tertiary);border:1px dashed var(--border-default);border-radius:12px">' +
        '이 현장에는 아직 공정 목록이 없습니다. 계약서 기성표의 공정을 적으세요.' +
        (d.canManage ? '<div style="margin-top:10px">' + u.primaryButton('공정 추가', 'window.AdminSectionDrawings.editSection()', 'plus') + '</div>' : '') + '</div>';
    }
    var groups = [];
    d.sections.forEach(function (s) {
      var g = groups.filter(function (x) { return x.name === s.division; })[0];
      if (!g) { g = { name: s.division, items: [] }; groups.push(g); }
      g.items.push(s);
    });
    return groups.map(function (g) {
      return '<div style="margin-bottom:16px"><div style="font-size:13px;font-weight:800;color:var(--text-secondary);margin:4px 2px 8px">' + u.esc(g.name) + '</div>' +
        g.items.map(function (s) {
          var found = s.sheets.filter(function (p) { return p.found; }).length;
          return '<div style="border:1px solid var(--border-default);border-radius:12px;padding:12px 14px;margin-bottom:10px;background:var(--bg-surface)">' +
            '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px">' +
              '<span style="font-size:11px;font-weight:700;padding:2px 7px;border-radius:6px;background:var(--bg-base);color:var(--text-secondary)">' + u.esc(s.code) + '</span>' +
              '<span style="font-size:14px;font-weight:700;flex:1">' + u.esc(s.name) + '</span>' +
              (s.contractAmount && !s.billing ? '<span style="font-size:12px;color:var(--text-secondary)">계약 ' + money(s.contractAmount) + '</span>' : '') +
              '<span style="font-size:12px;color:var(--text-tertiary)">도면 ' + s.sheets.length + '장' + (s.sheets.length && found < s.sheets.length ? ' · ' + (s.sheets.length - found) + '장은 아직 없음' : '') + '</span>' +
              (d.canManage ? u.rowButton('도면 고르기', 'window.AdminSectionDrawings.pick(' + s.id + ')') : '') +
            '</div>' + sectionMoney(u, s) +
            (s.sheets.length
              ? '<div style="display:flex;gap:10px;flex-wrap:wrap">' + s.sheets.map(function (p) { return sheetChip(u, p); }).join('') + '</div>'
              : '<div style="font-size:12px;color:var(--text-tertiary)">고른 도면이 없습니다.</div>') +
            '</div>';
        }).join('') + '</div>';
    }).join('');
  }

  function render() {
    var u = ui();
    var d = state.data;
    var siteSel = '';
    if (d.sites && (d.sites.length > 1 || (d.noSite && d.sites.length))) {
      siteSel = '<select onchange="window.AdminSectionDrawings.pickSite(this.value)" style="padding:7px 10px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:13px">' +
        (d.noSite ? '<option value="">— 현장 고르기 —</option>' : '') +
        d.sites.map(function (s) { return '<option value="' + u.esc(s.value) + '"' + (String(s.value) === String(d.siteId) ? ' selected' : '') + '>' + u.esc(s.label) + '</option>'; }).join('') + '</select>';
    }
    if (d.noSite) {
      return u.pageHeader('공정별 도면', '현장을 먼저 고르세요.', siteSel) +
        '<div style="padding:40px;text-align:center;color:var(--text-tertiary)">위에서 현장을 고르면 그 현장의 공정과 도면이 나옵니다.</div>';
    }
    var actions = siteSel +
      (d.canManage ? '<label style="display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:8px;background:var(--brand-primary);color:#fff;font-size:13px;font-weight:600;cursor:pointer">' +
        '<i class="ph ph-upload-simple"></i>도면 올리기<input type="file" accept="application/pdf,.pdf" multiple style="display:none" onchange="window.AdminSectionDrawings.upload(this)"></label>' : '') +
      (d.billing && d.billing.canImport ? '<label style="display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-surface);color:var(--text-primary);font-size:13px;font-weight:600;cursor:pointer">' +
        '<i class="ph ph-file-xls"></i>계약서 올리기<input type="file" accept=".xlsx,.xlsm,.xls" style="display:none" onchange="window.AdminSectionDrawings.uploadContract(this)"></label>' : '') +
      (d.canManage ? u.rowButton('공정 추가', 'window.AdminSectionDrawings.editSection()') : '');

    return u.pageHeader('공정별 도면',
      d.site + ' · 계약서의 공정마다 그 일을 그리고 적을 도면을 고릅니다. 도면 번호로 저장하므로 개정판이 와도 선택이 유지됩니다.',
      actions) + billingPanel(u, d) + docsPanel(u, d) + sectionsPanel(u, d);
  }

  function reload() {
    return call('api_getSectionDrawings', []).then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' + ui().esc(res.error || '불러오지 못했습니다.') + '</div>');
        return;
      }
      state.data = res;
      if (active()) paint(render());
      autoRead();
    });
  }

  /** 안 읽은 파일이 있으면 화면을 연 것만으로 읽기를 시작한다 — 「올리면 자동으로」. */
  function autoRead() {
    var d = state.data;
    if (!state.auto || !d || !d.canManage || state.reading) return;
    var todo = (d.documents || []).filter(function (doc) {
      return doc.pages === 0 || (doc.sheets || []).some(function (s) { return s.status === 'pending'; });
    });
    if (todo.length) readQueue(todo, false);
    if ((d.documents || []).some(function (doc) { return (doc.sheets || []).some(function (s) { return s.status === 'reading'; }); })) startPolling();
  }

  // ── 동작 ───────────────────────────────────────────────────────────

  function findDoc(id) {
    var d = state.data;
    return (d.documents || []).concat(d.otherPdfs || []).filter(function (x) { return x.id === id; })[0];
  }

  function read(id, force) {
    var doc = findDoc(id);
    if (!doc) return;
    readQueue([doc], !!force);
  }

  function findSheet(id) {
    var out = null;
    (state.data.catalog || []).forEach(function (s) { if (s.id === id) out = s; });
    return out;
  }

  function upload(input) {
    var u = ui();
    var files = Array.prototype.slice.call(input.files || []);
    input.value = '';
    if (!files.length) return;
    var ids = [];
    files.reduce(function (p, f) {
      return p.then(function () {
        u.toast(f.name + ' 올리는 중…');
        var fd = new FormData();
        fd.append('files[]', f);
        fd.append('site_id', String(state.data.siteId));
        return request('/document-hub/api/upload', 'POST', fd).then(function (res) {
          if (!res || res.success === false) { u.toast(f.name + ': ' + ((res && (res.error || res.message)) || '올리지 못했습니다.'), 'error'); return; }
          (res.documents || []).forEach(function (x) { ids.push(x.id); });
          (res.duplicates || []).forEach(function (x) { if (x.documentId) ids.push(x.documentId); });
          (res.failed || []).forEach(function (x) { u.toast(x.file + ': ' + x.reason, 'error'); });
        });
      });
    }, Promise.resolve()).then(function () {
      return call('api_getSectionDrawings', []);
    }).then(function (res) {
      state.data = res;
      if (active()) paint(render());
      var docs = ids.map(function (id) { return findDoc(id); }).filter(Boolean);
      if (docs.length) { u.toast(docs.length + '개 파일을 올렸습니다. 쪽을 읽기 시작합니다.'); readQueue(docs, false); }
    }).catch(function (e) { u.toast(e.message || '올리지 못했습니다.', 'error'); });
  }

  function pickOther() {
    var u = ui();
    var list = state.data.otherPdfs || [];
    var m = u.modal({
      title: '문서함의 다른 PDF 를 도면으로 쓰기',
      subtitle: '문서함이 도면으로 분류하지 않은 PDF 입니다. 도면이면 골라서 읽으세요 — 읽고 나면 도면 파일 목록으로 옮겨 갑니다.',
      width: 560,
      body: list.map(function (x) {
        return '<label style="display:flex;gap:8px;align-items:center;padding:7px 0;border-bottom:1px solid var(--border-subtle);font-size:13px;cursor:pointer">' +
          '<input type="checkbox" value="' + x.id + '"> ' + u.esc(x.title) + '</label>';
      }).join(''),
      actions: [{ label: '취소', value: null }, { label: '골라서 읽기', value: 'go', kind: 'primary' }],
    });
    var box = m.el;
    m.result.then(function (v) {
      if (v !== 'go') return;
      var ids = Array.prototype.map.call(box.querySelectorAll('input[type=checkbox]:checked'), function (c) { return Number(c.value); });
      var docs = ids.map(function (id) { return findDoc(id); }).filter(Boolean);
      if (docs.length) readQueue(docs, false);
    });
  }

  /** 공정 하나의 도면 고르기 — 읽힌 도면을 번호별로 보여 주고, 아직 없는 번호는 직접 적는다. */
  function pick(sectionId) {
    var u = ui();
    var d = state.data;
    var section = d.sections.filter(function (s) { return s.id === sectionId; })[0];
    if (!section) return;
    var chosen = section.sheets.map(function (p) { return p.sheetNo; });
    var byNo = {};
    (d.catalog || []).forEach(function (s) { if (s.sheetNo && !byNo[s.sheetNo]) byNo[s.sheetNo] = s; });
    var nos = Object.keys(byNo).sort();
    var missing = chosen.filter(function (n) { return !byNo[n]; });
    var noNumber = (d.catalog || []).filter(function (s) { return !s.sheetNo && s.status === 'done'; });

    var grid = nos.map(function (no) {
      var s = byNo[no];
      var on = chosen.indexOf(no) !== -1;
      return '<label style="display:flex;gap:10px;align-items:center;padding:8px;border:1px solid ' + (on ? 'var(--brand-primary)' : 'var(--border-default)') +
        ';border-radius:10px;cursor:pointer;background:var(--bg-surface)">' +
        '<input type="checkbox" value="' + u.esc(no) + '"' + (on ? ' checked' : '') + ' style="width:16px;height:16px">' +
        (s.thumbUrl ? '<img src="' + u.esc(s.thumbUrl) + '" alt="" loading="lazy" style="width:84px;height:56px;object-fit:contain;background:#fff;border-radius:4px;flex-shrink:0">' : '') +
        '<span style="min-width:0"><span style="display:block;font-size:12px;font-weight:700">' + u.esc(no) + '</span>' +
        '<span style="display:block;font-size:11px;color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis">' + u.esc(s.title || '') + '</span>' +
        '<span style="display:block;font-size:10px;color:var(--text-tertiary)">' + u.esc((s.file || '') + ' · ' + s.pageNo + '쪽') + '</span></span></label>';
    }).join('');

    var body =
      (nos.length ? '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px">' + grid + '</div>'
        : '<div style="padding:16px;color:var(--text-tertiary);font-size:13px">아직 읽힌 도면이 없습니다. 도면 번호를 아래에 직접 적어 두면 파일이 읽힐 때 자동으로 붙습니다.</div>') +
      (noNumber.length ? '<div style="margin-top:10px;font-size:12px;color:var(--status-warning)">번호를 못 읽은 쪽이 ' + noNumber.length + '장 있습니다. 도면을 눌러 번호를 적으면 여기 나옵니다.</div>' : '') +
      '<div style="margin-top:14px"><div style="font-size:12px;font-weight:700;margin-bottom:4px">아직 없는 도면 번호 (한 줄에 하나)</div>' +
      '<textarea data-extra rows="3" style="width:100%;padding:8px 10px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:13px;font-family:inherit">' +
      u.esc(missing.join('\n')) + '</textarea></div>';

    var m = u.modal({
      title: section.code + ' ' + section.name + ' — 도면 고르기',
      subtitle: '이 공정의 일을 그 위에 그리고 적을 도면을 고르세요. 평면을 먼저, 일람표·상세는 참고로.',
      width: 900,
      body: body,
      actions: [{ label: '취소', value: null }, { label: '저장', value: 'save', kind: 'primary' }],
    });
    var box = m.el;
    m.result.then(function (v) {
      if (v !== 'save') return;
      var picked = Array.prototype.map.call(box.querySelectorAll('input[type=checkbox]:checked'), function (c) { return c.value; });
      var extra = String((box.querySelector('[data-extra]') || {}).value || '').split(/\n+/).map(function (x) { return x.trim(); }).filter(Boolean);
      // 원래 순서를 지킨다 — 먼저 고른 것(대개 평면)이 앞에.
      var ordered = chosen.filter(function (n) { return picked.indexOf(n) !== -1 || extra.indexOf(n) !== -1; })
        .concat(picked.filter(function (n) { return chosen.indexOf(n) === -1; }))
        .concat(extra.filter(function (n) { return chosen.indexOf(n) === -1 && picked.indexOf(n) === -1; }));
      call('api_setSectionSheets', [section.id, ordered]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '저장하지 못했습니다.', 'error'); return; }
        u.toast(section.name + ' — 도면 ' + res.count + '장을 저장했습니다.');
        return reload();
      }).catch(function (e) { u.toast(e.message || '저장하지 못했습니다.', 'error'); });
    });
  }

  /** 도면 한 장 크게 보기 + 번호·제목 고치기 + AI 가 읽은 글자 확인. */
  function openSheet(id) {
    var u = ui();
    var s = findSheet(id);
    if (!s) return;
    var can = state.data.canManage;
    var doc = findDoc(s.documentId);
    var body =
      (s.thumbUrl ? '<img src="' + u.esc(s.thumbUrl) + '" alt="" style="width:100%;background:#fff;border-radius:8px;border:1px solid var(--border-default)">' : '') +
      '<div style="display:grid;grid-template-columns:1fr 2fr;gap:10px;margin-top:12px">' +
        '<label style="font-size:12px;font-weight:700">도면 번호<input data-no value="' + u.esc(s.sheetNo || '') + '"' + (can ? '' : ' disabled') +
          ' style="width:100%;margin-top:4px;padding:8px 10px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:13px"></label>' +
        '<label style="font-size:12px;font-weight:700">제목<input data-title value="' + u.esc(s.title || '') + '"' + (can ? '' : ' disabled') +
          ' style="width:100%;margin-top:4px;padding:8px 10px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:13px"></label>' +
      '</div>' +
      '<div style="font-size:12px;color:var(--text-tertiary);margin-top:6px">' + u.esc((s.file || '') + ' · ' + s.pageNo + '쪽 · ' +
        (s.textSource === 'ocr' ? '사진으로 된 도면이라 AI 가 글자를 읽었습니다' : s.textSource === 'pdf' ? '도면에 든 글자를 그대로 썼습니다' : '글자 없음') +
        (s.manual ? ' · 번호는 사람이 고침' : '')) + '</div>' +
      '<div style="font-size:12px;font-weight:700;margin-top:12px">읽은 글자</div>' +
      '<pre data-text style="white-space:pre-wrap;max-height:220px;overflow:auto;font-size:11px;background:var(--bg-base);padding:10px;border-radius:8px;margin:4px 0 0">불러오는 중…</pre>';

    var actions = [{ label: '닫기', value: null }];
    if (doc && doc.pdfUrl) actions.unshift({ label: 'PDF 로 열기', value: 'pdf' });
    if (can) actions.push({ label: '번호·제목 저장', value: 'save', kind: 'primary' });
    var m = u.modal({ title: (s.sheetNo || '번호 없음') + ' ' + (s.title || ''), subtitle: '', width: 960, body: body, actions: actions });
    var box = m.el;
    request('/drawing-api/sheets/' + id + '/text').then(function (res) {
      var pre = box.querySelector('[data-text]');
      if (pre) pre.textContent = res && res.text ? res.text : '(읽은 글자가 없습니다)';
    });
    m.result.then(function (v) {
      if (v === 'pdf') { global.open(doc.pdfUrl + '#page=' + s.pageNo, '_blank'); return; }
      if (v !== 'save') return;
      var no = (box.querySelector('[data-no]') || {}).value;
      var title = (box.querySelector('[data-title]') || {}).value;
      call('api_updateDrawingSheet', [id, no, title]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '저장하지 못했습니다.', 'error'); return; }
        u.toast('도면 번호를 고쳤습니다. 다시 읽어도 이 번호가 유지됩니다.');
        return reload();
      });
    });
  }

  function editSection(id) {
    var u = ui();
    var s = id ? state.data.sections.filter(function (x) { return x.id === id; })[0] : null;
    u.formModal({
      title: s ? '공정 고치기' : '공정 추가',
      subtitle: '계약서 기성표의 공정을 그대로 적습니다. RFI 로 늘어난 공정도 여기서 더합니다.',
      fields: [
        { name: 'division', label: '공종 묶음', value: s ? s.division : '', hint: '예) 건축공사 / 설비공사 / 전기공사' },
        { name: 'code', label: '부호', required: true, value: s ? s.code : '', hint: '예) A3, M1-2, E01 — 현장 안에서 겹치지 않게' },
        { name: 'name', label: '공정 이름', required: true, colSpan: 2, value: s ? s.name : '', hint: '예) 3) DRYWALL' },
        { name: 'contractAmount', label: '계약 금액 (USD)', type: 'number', value: s ? s.contractAmount : '' },
      ],
      onSave: function (v) {
        var payload = { id: s ? s.id : 0, siteId: state.data.siteId, division: v.division, code: v.code, name: v.name, contractAmount: v.contractAmount };
        return call('api_saveWorkSection', [payload]).then(function (res) {
          if (res.success === false) return res;
          u.toast(s ? '고쳤습니다.' : '공정을 더했습니다.');
          return reload().then(function () { return { success: true }; });
        });
      },
    });
  }

  // ── 계약서·기성 ─────────────────────────────────────────────────────

  /** 계약서 엑셀 → 문서함에 올리고 → 무엇을 읽었는지 보여 주고 → 확인하면 줄을 올린다. */
  function uploadContract(input) {
    var u = ui();
    var f = (input.files || [])[0];
    input.value = '';
    if (!f) return;
    u.toast(f.name + ' 올리는 중…');
    var fd = new FormData();
    fd.append('files[]', f);
    fd.append('site_id', String(state.data.siteId));
    request('/document-hub/api/upload', 'POST', fd).then(function (res) {
      if (!res || res.success === false) throw new Error((res && (res.error || res.message)) || '올리지 못했습니다.');
      var id = ((res.documents || [])[0] || {}).id || ((res.duplicates || [])[0] || {}).documentId;
      if (!id) throw new Error((((res.failed || [])[0]) || {}).reason || '올리지 못했습니다.');
      return call('api_previewContractSheet', [id]);
    }).then(function (p) {
      if (p.success === false) throw new Error(p.error || '계약서를 읽지 못했습니다.');
      confirmContract(p);
    }).catch(function (e) { u.toast(e.message || '올리지 못했습니다.', 'error'); });
  }

  function confirmContract(p) {
    var u = ui();
    var options = (p.contracts || []).map(function (c) {
      var same = c.amount !== null && Math.abs(c.amount - p.contractTotal) < 0.01;
      return '<option value="' + c.id + '"' + (c.id === p.suggestedContractId ? ' selected' : '') + '>' + u.esc(c.title + ' · ' + (c.amount !== null ? money(c.amount) : '금액 없음') + (same ? '' : ' (금액 다름)') + (c.lines ? ' · 줄 ' + c.lines + '개' : '')) + '</option>';
    }).join('');
    var sections = (p.sections || []).map(function (s) {
      return '<tr><td style="padding:4px 6px;color:var(--text-secondary)">' + u.esc(s.division || '') + '</td><td style="padding:4px 6px">' + u.esc(s.name) + '</td>' +
        '<td style="padding:4px 6px;text-align:right">' + s.lines + '줄</td><td style="padding:4px 6px;text-align:right">' + money(s.amount) + '</td>' +
        '<td style="padding:4px 6px;font-size:11px;color:' + (s.matches ? 'var(--text-secondary)' : 'var(--status-warning)') + '">' + u.esc(s.matches ? '→ ' + s.matches.code + ' (도면 선택 유지)' : '새 공정') + '</td></tr>';
    }).join('');
    var body =
      '<div style="font-size:13px;line-height:1.7">' +
        '<b>' + u.esc(p.project || p.fileName) + '</b>' + (p.scope ? ' · ' + u.esc(p.scope) : '') + '<br>' +
        '계약 합계 <b>' + money(p.contractTotal) + '</b> · 줄 <b>' + p.lineCount + '개</b> · 공정 ' + p.sections.length + '개' +
        (Math.abs(p.roundOff) >= 0.01 ? ' · 줄 합계 ' + money(p.lineTotal) + ' (절사 ' + money(p.roundOff) + ')' : '') + '<br>' +
        '자재 단가가 있는 ' + p.storedLineCount + '줄은 반입 자재 기성(자재 단가)과 설치 기성(나머지)을 따로 받습니다.' +
      '</div>' +
      '<div style="max-height:260px;overflow:auto;margin:10px 0;border:1px solid var(--border-default);border-radius:8px"><table style="width:100%;border-collapse:collapse;font-size:12px">' + sections + '</table></div>' +
      '<label style="display:block;font-size:12px;font-weight:700;margin-top:6px">어느 계약의 줄인가요?' +
        '<select data-contract style="width:100%;margin-top:4px;padding:8px 10px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:13px">' +
        options + '<option value="0"' + (p.suggestedContractId ? '' : ' selected') + '>새 수주 계약 만들기 — ' + u.esc(p.newContractTitle) + ' · ' + money(p.contractTotal) + '</option></select></label>' +
      '<label style="display:flex;gap:8px;align-items:flex-start;margin-top:12px;font-size:13px;cursor:pointer"><input type="checkbox" data-confirm style="margin-top:3px">' +
        '<span>이 파일은 원청과 맺은 계약 내역입니다. 줄의 수량과 단가를 계약 조건으로 확정합니다. 이후 변경은 RFI 로만 합니다.</span></label>';
    var m = u.modal({
      title: '계약서 올리기 — ' + p.site.label,
      subtitle: p.fileName + ' · ' + p.sheetName + ' 시트를 읽었습니다. 섹션마다 줄 금액의 합이 계약서 소계와 맞는 것을 확인했습니다.',
      width: 760, body: body,
      actions: [{ label: '취소', value: null }, { label: '줄 올리기', value: 'keep', kind: 'primary' }],
      onAction: function (a, box) {
        if (!box.querySelector('[data-confirm]').checked) { u.toast('계약 내역인지 확인란을 눌러 주세요.', 'error'); return; }
        var cid = Number(box.querySelector('[data-contract]').value);
        u.toast('계약 줄을 올리는 중…');
        call('api_importContractSheet', [{ documentId: p.documentId, contractId: cid, createContract: cid === 0, confirmContract: true }]).then(function (r) {
          if (r.success === false) { u.toast(r.error || '올리지 못했습니다.', 'error'); return; }
          m.close(null);
          global.apiCache = {};
          u.toast(r.message);
          return reload();
        }).catch(function (e) { u.toast(e.message || '올리지 못했습니다.', 'error'); });
      },
    });
  }

  /** 한 공정의 계약 줄 — 줄마다 진행률. 기록·확인은 기성 근거 대장에서. */
  function lines(sectionId) {
    var u = ui();
    call('api_getSectionLines', [sectionId]).then(function (r) {
      if (r.success === false) { u.toast(r.error || '불러오지 못했습니다.', 'error'); return; }
      var group = null;
      // 표가 아니라 줄 카드 — 휴대폰에서는 오른쪽 칸이 아래로 내려와야 버튼이 보인다.
      var rows = r.lines.map(function (l) {
        var head = '';
        if ((l.group || '') !== (group || '')) { group = l.group; head = group ? '<div style="padding:12px 2px 2px;font-size:12px;font-weight:800;color:var(--text-secondary)">' + u.esc(group) + '</div>' : ''; }
        var done = l.splitsMaterial
          ? '반입 ' + qtyText(l.storedQty) + ' · 설치 ' + qtyText(l.installedQty)
          : '시공 ' + qtyText(l.installedQty);
        return head + '<div style="display:flex;flex-wrap:wrap;gap:8px 16px;padding:10px 2px;border-top:1px solid var(--border-subtle)">' +
          '<div style="flex:1 1 300px;min-width:0"><div style="font-size:13px;font-weight:700">#' + u.esc(l.lineNo) + ' ' + u.esc(l.description) + '</div>' +
            (l.spec ? '<div style="font-size:11px;color:var(--text-tertiary);margin-top:2px">' + u.esc(l.spec) + '</div>' : '') +
            '<div style="font-size:12px;color:var(--text-secondary);margin-top:4px">계약 ' + qtyText(l.contractQty) + ' ' + u.esc(l.unit) + ' · ' + money(l.amount) + '</div></div>' +
          '<div style="flex:1 1 200px;font-size:12px">' + bar(l.percent) +
            '<div style="margin-top:4px"><b>' + pct(l.percent) + '</b> · ' + money(l.earned) + '</div>' +
            '<div style="color:var(--text-tertiary)">' + done + '</div>' +
            (l.pendingCount ? '<div style="color:var(--status-warning)">확인 대기 ' + l.pendingCount + '건</div>' : '') +
            (l.installGap ? '<div style="color:var(--status-danger)">설치가 반입보다 많음 · 반입 기록을 더하세요</div>' : '') + '</div>' +
          '<div style="flex:0 0 auto;align-self:flex-start">' + (r.contractId ? u.rowButton(r.canManage ? '기록·확인' : '근거 보기', 'window.AdminSectionDrawings.ledger(' + r.contractId + ',' + l.id + ')') : '') + '</div></div>';
      }).join('');
      state.linesModal = u.modal({
        title: r.section.code + ' ' + r.section.name + ' — 계약 줄 ' + r.lines.length + '개',
        subtitle: '진행률은 사람이 확인한 수량 × 계약 단가입니다. 자재가 들어오면 반입, 설치하면 설치로 기록합니다.',
        width: 980,
        body: rows ? rows : '<div style="padding:16px;color:var(--text-tertiary)">이 공정에 올라온 계약 줄이 없습니다.</div>',
      });
    }).catch(function (e) { u.toast(e.message || '불러오지 못했습니다.', 'error'); });
  }

  function qtyText(v) { return v === null || v === undefined ? '—' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 }); }

  function ledger(contractId, lineId) {
    if (state.linesModal) { state.linesModal.close(null); state.linesModal = null; }
    if (global.AdminClaimEvidence) global.AdminClaimEvidence.open(contractId, lineId);
  }

  function pickSite(id) {
    if (!id) return;
    var code = null;
    Object.keys(global.SITE_DB_IDS || {}).forEach(function (k) { if (String(global.SITE_DB_IDS[k]) === String(id)) code = k; });
    if (code && typeof global.setProjectContext === 'function') global.setProjectContext(code);
    else if (code) global.currentSiteId = code;
    reload();
  }

  function renderScreen() {
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    reload().catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' + ui().esc(e.message || '불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminSectionDrawings = {
    render: renderScreen,
    read: read,
    upload: upload,
    pick: pick,
    pickOther: pickOther,
    openSheet: openSheet,
    editSection: editSection,
    pickSite: pickSite,
    uploadContract: uploadContract,
    lines: lines,
    ledger: ledger,
    _state: state,
    _meaningful: meaningful,
  };
})(window);

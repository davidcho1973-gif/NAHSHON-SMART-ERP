/**
 * 자재 입고 — 트럭이 왔고, 무엇이 몇 개 왔는가.
 *
 * 공정표를 묻지 않는다. 현장과 날짜만 있으면 적힌다. 조달 추적(공정 관리 하위)은
 * «언제까지 와야 하는가» 를 보는 화면이고, 여기는 «무엇이 실제로 왔는가» 를 적는 곳이다.
 *
 * 가장 빠른 길은 사진이다 — 「사진으로 입고」 를 누르면 폰 카메라가 열리고, 납품서를
 * 찍으면 AI 가 품목·수량을 읽어 <b>확인 대기</b> 한 장을 만든다. 사람이 수량을 보고
 * 「확정」 을 눌러야 숫자가 된다.
 *
 * 품목 줄은 여러 칸짜리 표 대신 <b>한 줄에 하나</b>씩 적는 칸으로 둔다. 현장에서 폰으로
 * 고치는 화면이라, 칸이 여러 개인 표는 손가락으로 맞추기 어렵다. AI 가 읽은 값도
 * 같은 모양으로 채워져 있어 틀린 숫자만 지우고 다시 적으면 된다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = { items: [], sites: [], canManage: false, siteId: '', busy: false };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  function money(v) {
    if (v === null || v === undefined || v === '') return '';
    return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function qty(v) {
    var n = Number(v || 0);
    // 120 은 "120" 으로, 40.5 는 "40.5" 로 — 없는 소수점을 보여주면 읽기 어렵다.
    return n.toLocaleString('en-US', { maximumFractionDigits: 3 });
  }

  /** 줄 → 「품목명 | 수량 | 단위 | 단가 | 메모」 한 줄씩. */
  function linesToText(lines) {
    return (lines || []).map(function (l) {
      return [l.name, l.quantity, l.unit || '', l.unitPrice === null || l.unitPrice === undefined ? '' : l.unitPrice,
        l.note || ''].join(' | ').replace(/(\s*\|\s*)+$/, '');
    }).join('\n');
  }

  /** 사람이 적은 텍스트 → 줄 배열. 수량이 없는 줄은 버린다(소계·운임·서명란). */
  function textToLines(text) {
    return String(text || '').split('\n').map(function (raw) {
      var parts = raw.split('|').map(function (p) { return p.trim(); });
      if (!parts[0]) return null;
      var n = Number(String(parts[1] || '').replace(/,/g, ''));
      if (!isFinite(n) || n <= 0) return null;
      return {
        name: parts[0],
        quantity: n,
        unit: parts[2] || '',
        unit_price: parts[3] === '' || parts[3] === undefined ? null : Number(String(parts[3]).replace(/[$,]/g, '')),
        note: parts[4] || '',
      };
    }).filter(Boolean);
  }

  function siteOfRow(r) { return r.siteCode ? r.siteCode + ' ' + r.site : r.site; }

  function table() {
    var u = ui();
    return u.table({
      id: 'mr-tbl',
      searchPlaceholder: '공급처 · 납품서 번호 · 품목 검색',
      emptyText: '입고 기록이 없습니다. 납품서를 찍어 올리면 여기에 쌓입니다.',
      columns: [
        { key: 'receivedOn', label: '입고일', width: '110px' },
        {
          key: 'vendor', label: '공급처',
          render: function (r) {
            return '<div style="font-weight:600">' + u.esc(r.vendor || '(미입력)') + '</div>' +
              '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(siteOfRow(r)) + '</div>';
          },
        },
        {
          key: 'deliveryNo', label: '납품서 · PO', width: '150px',
          render: function (r) {
            var bits = [];
            if (r.deliveryNo) bits.push(u.esc(r.deliveryNo));
            if (r.poNo) bits.push('<span style="color:var(--text-tertiary)">PO ' + u.esc(r.poNo) + '</span>');
            return bits.join('<br>');
          },
        },
        {
          key: 'lineCount', label: '품목', width: '220px',
          render: function (r) {
            var first = (r.lines || []).slice(0, 2).map(function (l) {
              return u.esc(l.name) + ' <b>' + qty(l.quantity) + '</b>' + (l.unit ? ' ' + u.esc(l.unit) : '');
            }).join('<br>');
            var more = r.lineCount > 2 ? '<div style="font-size:11px;color:var(--text-tertiary)">외 ' + (r.lineCount - 2) + '건</div>' : '';
            return '<div style="font-size:12px;line-height:1.6">' + first + '</div>' + more;
          },
        },
        {
          key: 'amount', label: '금액', align: 'right', width: '110px',
          render: function (r) {
            // 단가를 안 적은 입고는 «0 달러» 가 아니라 «모름» 이다.
            return r.amount === null
              ? '<span style="color:var(--text-tertiary)">—</span>'
              : u.esc(money(r.amount));
          },
        },
        {
          key: 'statusLabel', label: '상태', width: '120px',
          render: function (r) {
            var badge = u.badge(r.statusLabel, r.status === 'confirmed' ? 'ok' : 'warn');
            var ai = r.aiConfidence !== null && r.aiConfidence !== undefined
              ? '<div style="font-size:11px;color:var(--text-tertiary)">AI 판독 ' + Math.round(r.aiConfidence * 100) + '%</div>'
              : '';
            return badge + ai;
          },
        },
        {
          key: 'act', label: '', align: 'right', width: '210px',
          render: function (r) {
            var bits = [];
            if (r.photoUrl) bits.push('<a href="' + u.esc(r.photoUrl) + '" target="_blank" rel="noopener" ' +
              'style="font-size:12px;color:var(--brand-primary);text-decoration:none">사진</a>');
            if (!state.canManage) return bits.join(' ');
            if (r.status === 'confirmed') {
              bits.push(u.rowButton('반입 기록', 'window.AdminMaterialReceipts.claims(' + r.id + ')'));
              bits.push(u.rowButton('확정 해제', 'window.AdminMaterialReceipts.confirm(' + r.id + ',false)'));
            } else {
              bits.push(u.rowButton('수정', 'window.AdminMaterialReceipts.open(' + r.id + ')'));
              bits.push(u.rowButton('확정', 'window.AdminMaterialReceipts.confirm(' + r.id + ',true)'));
              bits.push(u.rowButton('삭제', 'window.AdminMaterialReceipts.remove(' + r.id + ')', 'danger'));
            }
            return bits.join(' ');
          },
        },
      ],
      rows: state.items,
    });
  }

  function header() {
    var u = ui();
    var notes = [state.items.length + '건'];
    if (state.draftCount) notes.push(state.draftCount + '건 확인 대기');

    var actions = '';
    if (state.canManage) {
      actions =
        // capture 를 붙여야 폰에서 앨범이 아니라 카메라가 먼저 열린다.
        '<input type="file" id="mr-photo" accept="image/*,application/pdf" capture="environment" ' +
        'style="display:none" onchange="window.AdminMaterialReceipts.photoPicked(this)">' +
        u.primaryButton('사진으로 입고', 'window.AdminMaterialReceipts.pickPhoto()', 'camera') + ' ' +
        u.rowButton('직접 입력', 'window.AdminMaterialReceipts.open()');
    }

    return u.pageHeader(
      '자재 입고',
      '현장에 도착한 자재를 수량과 함께 적습니다. 공정표가 없어도 됩니다. ' +
      '납품서를 찍어 올리면 AI 가 품목·수량을 읽어 «확인 대기» 로 만들고, 확정은 사람이 합니다. — ' + notes.join(' · '),
      actions
    );
  }

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function draw() {
    paint(header() + table());
    ui().bindSearch('mr-tbl');
  }

  function reload() {
    return call('api_getMaterialReceipts').then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '목록을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.items = res.items || [];
      state.sites = res.sites || [];
      state.canManage = !!res.canManage;
      state.draftCount = res.draftCount || 0;
      if (!state.siteId) state.siteId = defaultSiteId();
      draw();
    });
  }

  /** 위에서 고른 현장을 그대로 쓴다. '전체' 면 고를 수 있는 현장이 하나일 때만 정한다. */
  function defaultSiteId() {
    var mapped = global.SITE_DB_IDS && global.SITE_DB_IDS[global.currentSiteId];
    if (mapped) {
      var known = state.sites.filter(function (s) { return String(s.value) === String(mapped); })[0];
      if (known) return String(mapped);
    }
    return state.sites.length === 1 ? String(state.sites[0].value) : '';
  }

  function pickPhoto() {
    var el = document.getElementById('mr-photo');
    if (el) el.click();
  }

  function photoPicked(input) {
    var u = ui();
    var file = input && input.files && input.files[0];
    input.value = '';                      // 같은 파일을 다시 골라도 change 가 뜨게.
    if (!file || state.busy) return;

    if (!state.siteId) {
      // 현장을 모르면 어디에 온 자재인지 알 수 없다 — 읽기 전에 물어본다.
      askSite().then(function (siteId) {
        if (!siteId) return;
        state.siteId = siteId;
        upload(file);
      });
      return;
    }
    upload(file);
  }

  function askSite() {
    var u = ui();
    return u.formModal({
      title: '어느 현장에 온 자재인가요?',
      subtitle: '위에서 현장을 고르면 다음부터는 묻지 않습니다.',
      saveLabel: '선택',
      fields: [{ name: 'siteId', label: '현장', type: 'select', required: true, colSpan: 2, options: state.sites }],
    }).then(function (v) { return v && v.siteId ? String(v.siteId) : ''; });
  }

  function upload(file) {
    var u = ui();
    state.busy = true;
    u.toast('납품서를 읽는 중입니다… 잠시만 기다려 주세요.');

    u.uploadFile('/material-receipt-api/analyze', file, { site_id: state.siteId }).then(function (res) {
      state.busy = false;
      if (!res || res.success === false) {
        u.toast((res && res.error) || '납품서를 읽지 못했습니다.', 'error');
        return;
      }
      if (res.saveError) { u.toast(res.saveError, 'error'); }

      if (res.id) {
        u.toast('납품서를 읽었습니다. 수량을 확인하고 «확정» 을 눌러 주세요.');
        return reload().then(function () { open(res.id); });
      }
      // 한 줄도 못 읽었으면 빈 입고를 만들지 않는다. 읽은 만큼만 채워서 직접 입력으로 넘긴다.
      u.toast('품목을 읽지 못했습니다. 직접 적어 주세요.', 'error');
      open(null, {
        receivedOn: (res.data && res.data.received_on) || '',
        vendor: (res.data && res.data.vendor) || '',
        poNo: (res.data && res.data.po_no) || '',
        deliveryNo: (res.data && res.data.delivery_no) || '',
        photo: res.file,
      });
    }).catch(function (e) {
      state.busy = false;
      u.toast(e.message || '업로드에 실패했습니다.', 'error');
    });
  }

  function open(id, prefill) {
    var u = ui();
    var row = id ? state.items.filter(function (r) { return r.id === id; })[0] : null;
    var base = row || prefill || {};

    u.formModal({
      title: row ? '입고 수정' : '자재 입고',
      subtitle: row && row.aiSummary
        ? 'AI 판독: ' + row.aiSummary + ' — 수량을 눈으로 확인하고 저장하세요.'
        : '공정표가 없어도 됩니다. 현장과 날짜, 품목·수량만 적으면 기록됩니다.',
      saveLabel: '저장',
      fields: [
        { name: 'siteId', label: '현장', type: 'select', required: true, group: '언제 · 어디로',
          options: state.sites, value: base.siteId || state.siteId },
        { name: 'receivedOn', label: '입고일', type: 'date', required: true, group: '언제 · 어디로',
          value: base.receivedOn || new Date().toISOString().slice(0, 10) },

        { name: 'vendor', label: '공급처', group: '납품서', value: base.vendor || '',
          hint: '거래처 대장에 없으면 새로 만들어집니다.' },
        { name: 'deliveryNo', label: '납품서 번호', group: '납품서', value: base.deliveryNo || '' },
        { name: 'poNo', label: '발주(PO) 번호', group: '납품서', value: base.poNo || '', colSpan: 2 },

        { name: 'lines', label: '품목 · 수량', type: 'textarea', rows: 8, colSpan: 2, group: '무엇이 몇 개',
          value: linesToText(base.lines),
          hint: '한 줄에 하나씩: 품목명 | 수량 | 단위 | 단가 | 메모 — 예) EMT 1/2" Conduit | 120 | EA | 4.50 | 일부만 도착. ' +
            '수량이 없는 줄(소계·운임)은 저장되지 않습니다.' },
        { name: 'note', label: '메모', type: 'textarea', rows: 2, colSpan: 2, group: '무엇이 몇 개', value: base.note || '' },
      ],
      onSave: function (v) {
        var lines = textToLines(v.lines);
        if (!lines.length) {
          return { success: false, errors: { lines: '품목을 한 줄 이상 적어주세요. (품목명 | 수량)' } };
        }
        var payload = {
          id: id || 0,
          site_id: v.siteId,
          received_on: v.receivedOn,
          vendor: v.vendor,
          po_no: v.poNo,
          delivery_no: v.deliveryNo,
          note: v.note,
          lines: lines,
        };
        if (!id && (prefill && prefill.photo)) payload.photo = prefill.photo;

        return call('api_saveMaterialReceipt', [payload]).then(function (res) {
          if (res.success === false) return res;
          state.siteId = String(v.siteId);
          u.toast(id ? '입고를 수정했습니다.' : '입고를 기록했습니다. 확인 후 «확정» 을 눌러 주세요.');
          return reload().then(function () { return { success: true }; });
        });
      },
    });
  }

  function confirm(id, on) {
    var u = ui();
    call('api_confirmMaterialReceipt', [id, !!on]).then(function (res) {
      if (res.success === false) { u.toast(res.error || '처리하지 못했습니다.', 'error'); return; }
      // 확정은 회계 대기로 이어진다 — 얼마가 넘어갔는지, 왜 안 넘어갔는지 그 자리에서 말한다.
      var f = res.finance || {};
      if (res.financeWarning) u.toast(res.financeWarning, 'error');
      else if (on && f.posted && f.amount !== null && f.amount !== undefined) u.toast('입고를 확정했습니다. $' + Number(f.amount).toLocaleString() + ' 을(를) 회계 대기로 넘겼습니다.');
      else if (on && f.note) u.toast('입고를 확정했습니다. ' + f.note, 'error');
      else u.toast(on ? '입고를 확정했습니다.' : '확정을 해제했습니다. 회계 대기에서 빠졌고, 이제 수정할 수 있습니다.');
      // 확정은 기성의 반입 기록으로도 이어진다 — 몇 건이 됐고 몇 건은 사람이 골라야 하는지.
      var c = res.claims || null;
      if (on && c) {
        if (c.warning) u.toast(c.warning, 'error');
        else if (c.deferred) u.toast('반입 기록은 기성 담당자가 입고 화면을 열면 자동으로 연결됩니다.');
        else if (c.created || c.unmatched) u.toast('반입 기록 ' + c.created + '건을 확인 대기로 만들었습니다.' + (c.unmatched ? ' 계약 줄을 못 찾은 품목 ' + c.unmatched + '건은 직접 고르세요.' : ''));
      }
      return reload().then(function () { if (on && c && c.unmatched) claims(id); });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  /** 송장 줄 ↔ 기성 반입 기록. 못 찾은 줄은 계약 줄을 골라 잇는다 — 고른 연결은 다음 송장부터 저절로. */
  function claims(id) {
    var u = ui();
    call('api_getReceiptClaims', [id]).then(function (r) {
      if (r.success === false) { u.toast(r.error || '불러오지 못했습니다.', 'error'); return; }
      var opts = '<option value="">— 계약 줄 고르기 —</option>' + (r.options || []).map(function (o) {
        return '<option value="' + o.id + '" data-unit="' + u.esc(o.unit) + '">' + u.esc(o.label) + ' (' + u.esc(o.unit) + ')</option>';
      }).join('');
      var st = { pending: '확인 대기', verified: '확인됨 · 기성 반영', rejected: '반려' };
      var body = (r.hasPhoto ? '' : '<div style="font-size:12px;color:var(--status-warning);margin-bottom:8px">이 입고에는 송장 사진이 없어 반입 기록을 확인(청구)할 수 없습니다. 사진을 올린 입고만 근거가 됩니다.</div>') +
        (r.rows || []).map(function (row) {
          var head = '<div style="font-size:13px;font-weight:700">' + u.esc(row.name) + ' <span style="font-weight:400;color:var(--text-secondary)">' + qty(row.qty) + ' ' + u.esc(row.unit || '') + '</span></div>';
          if (row.record) {
            return '<div style="padding:10px 2px;border-bottom:1px solid var(--border-subtle)">' + head +
              '<div style="font-size:12px;margin-top:3px">→ <b>#' + u.esc(row.record.lineNo) + ' ' + u.esc(row.record.description) + '</b> · 반입 ' + qty(row.record.qty) + ' ' + u.esc(row.record.unit || '') +
              ' · <span style="color:' + (row.record.status === 'verified' ? 'var(--status-success)' : 'var(--status-warning)') + '">' + (st[row.record.status] || row.record.status) + '</span></div>' +
              '<div style="font-size:11px;color:var(--text-tertiary);margin-top:2px">' + u.esc(row.record.notes || '') + '</div></div>';
          }
          return '<div data-row="' + row.receiptLineId + '" style="padding:10px 2px;border-bottom:1px solid var(--border-subtle)">' + head +
            (r.canManage && r.confirmed && (r.options || []).length
              ? '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;align-items:center"><select data-line style="flex:1;min-width:220px;padding:7px 8px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:12px">' + opts + '</select>' +
                '<span style="font-size:12px">송장 1 ' + u.esc(row.unit || '개') + ' =</span><input data-factor type="number" step="any" value="1" style="width:80px;padding:7px 8px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:12px"><span data-unit style="font-size:12px"></span>' +
                '<button type="button" data-link style="padding:7px 12px;border-radius:8px;border:none;background:var(--brand-primary);color:#fff;font-size:12px;font-weight:600;cursor:pointer">연결</button></div>'
              : '<div style="font-size:12px;color:var(--text-tertiary);margin-top:3px">' + (r.confirmed ? '연결할 계약 줄이 없습니다 (이 현장에 반입으로 받는 계약 줄 없음)' : '입고를 확정하면 반입 기록이 만들어집니다') + '</div>') +
            '</div>';
        }).join('');
      u.modal({ title: '송장 → 기성 반입 기록', subtitle: '확정된 송장의 품목이 계약 줄의 «반입» 기록(확인 대기)이 됩니다. 담당자가 확인해야 기성에 들어갑니다. 한 번 연결한 품목은 다음 송장부터 저절로 연결됩니다.', width: 760, body: body,
        onReady: function (box) {
          Array.prototype.forEach.call(box.querySelectorAll('[data-row]'), function (el) {
            var sel = el.querySelector('[data-line]');
            if (!sel) return;
            sel.onchange = function () { var o = sel.options[sel.selectedIndex]; el.querySelector('[data-unit]').textContent = o ? (o.getAttribute('data-unit') || '') : ''; };
            el.querySelector('[data-link]').onclick = function () {
              if (!sel.value) { u.toast('계약 줄을 고르세요.', 'error'); return; }
              call('api_linkReceiptLine', [{ receiptLineId: Number(el.getAttribute('data-row')), contractLineId: Number(sel.value), factor: Number(el.querySelector('[data-factor]').value) }]).then(function (res) {
                if (res.success === false) { u.toast(res.error || '연결하지 못했습니다.', 'error'); return; }
                u.toast(res.message);
                var dlg = box.closest('[role=dialog]');
                if (dlg && dlg.parentNode) dlg.parentNode.remove();
                claims(id);
              });
            };
          });
        } });
    }).catch(function (e) { u.toast(e.message || '불러오지 못했습니다.', 'error'); });
  }

  function remove(id) {
    var u = ui();
    var row = state.items.filter(function (r) { return r.id === id; })[0];
    u.confirmDanger({
      title: '입고 기록을 삭제할까요?',
      body: (row ? (row.receivedOn + ' ' + (row.vendor || '')) : '이 기록') + ' 을(를) 삭제합니다. 되돌릴 수 없습니다.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteMaterialReceipt', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('입고 기록을 삭제했습니다.');
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function renderScreen() {
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    reload().catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' +
        ui().esc(e.message || '목록을 불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminMaterialReceipts = {
    render: renderScreen,
    open: open,
    confirm: confirm,
    claims: claims,
    remove: remove,
    pickPhoto: pickPhoto,
    photoPicked: photoPicked,
    _state: state,
    _textToLines: textToLines,
  };
})(window);

/**
 * 품목 · 분류 마스터 — Filament ItemResource / ItemCategoryResource 를 SPA 로 옮긴 것.
 *
 * 자재를 세는 단위를 정하는 곳이다. 품목과 분류는 따로 관리하지만 늘 같이 보게 되므로
 * (분류를 만들다가 품목을 넣고, 품목을 넣다가 분류가 없어 되돌아간다) 한 화면에 탭으로 둔다.
 *
 * 분류 표에는 "품목 N개" 를 같이 보여준다. 지울 수 있는지 판단하는 근거이고,
 * 아무도 안 쓰는 분류가 쌓여 있는 것도 여기서 보인다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = { items: [], categories: [], options: null, canManage: false, tab: 'items' };

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

  function tabs() {
    var u = ui();
    var btn = function (key, label, n) {
      var on = state.tab === key;
      return '<button type="button" onclick="window.AdminItems.setTab(\'' + key + '\')" ' +
        'style="padding:8px 16px;border:none;background:none;font-size:14px;cursor:pointer;' +
        'border-bottom:2px solid ' + (on ? 'var(--brand-primary)' : 'transparent') + ';' +
        'color:' + (on ? 'var(--text-primary)' : 'var(--text-secondary)') + ';font-weight:' + (on ? '700' : '500') + '">' +
        u.esc(label) + ' <span style="color:var(--text-tertiary);font-weight:400">' + n + '</span></button>';
    };
    return '<div style="display:flex;gap:4px;border-bottom:1px solid var(--border-default);margin-bottom:16px">' +
      btn('items', '품목', state.items.length) + btn('categories', '분류', state.categories.length) + '</div>';
  }

  function itemsTable() {
    var u = ui();
    return u.table({
      id: 'it-tbl',
      searchPlaceholder: '품목명 · 코드 · 분류 검색',
      emptyText: '등록된 품목이 없습니다.',
      columns: [
        {
          key: 'code', label: '코드', width: '130px',
          render: function (r) {
            return r.code
              ? '<span style="font-family:var(--font-mono,monospace);font-size:12px">' + u.esc(r.code) + '</span>'
              : '';
          },
        },
        {
          key: 'name', label: '품목명',
          render: function (r) {
            return '<div style="font-weight:600">' + u.esc(r.name) + '</div>' +
              (r.description ? '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.description) + '</div>' : '');
          },
        },
        { key: 'category', label: '분류', width: '150px' },
        { key: 'unit', label: '단위', width: '80px' },
        {
          key: 'standardCost', label: '표준 단가', align: 'right', width: '110px',
          render: function (r) { return u.esc(money(r.standardCost)); },
        },
        {
          key: 'company', label: '소속', width: '120px',
          render: function (r) {
            return r.isShared
              ? '<span style="font-size:12px;color:var(--text-tertiary)">전사 공통</span>'
              : u.esc(r.company || '');
          },
        },
        {
          key: 'statusLabel', label: '상태', width: '80px',
          render: function (r) { return u.badge(r.statusLabel, r.status === 'active' ? 'ok' : 'muted'); },
        },
        {
          key: 'act', label: '', align: 'right', width: '120px',
          render: function (r) {
            if (!state.canManage) return '';
            return u.rowButton('수정', 'window.AdminItems.openItem(' + r.id + ')') + ' ' +
              u.rowButton('삭제', 'window.AdminItems.removeItem(' + r.id + ')', 'danger');
          },
        },
      ],
      rows: state.items,
    });
  }

  function categoriesTable() {
    var u = ui();
    return u.table({
      id: 'ic-tbl',
      searchPlaceholder: '분류명 · 코드 검색',
      emptyText: '등록된 분류가 없습니다.',
      columns: [
        {
          key: 'name', label: '분류명',
          render: function (r) {
            // 상위 분류를 앞에 붙여 계층을 한 줄로 보여준다. 트리 UI 는 항목이
            // 수십 개일 때 오히려 찾기 어렵다.
            return (r.parent ? '<span style="color:var(--text-tertiary)">' + u.esc(r.parent) + ' › </span>' : '') +
              '<span style="font-weight:600">' + u.esc(r.name) + '</span>';
          },
        },
        {
          key: 'code', label: '코드', width: '120px',
          render: function (r) {
            return r.code ? '<span style="font-family:var(--font-mono,monospace);font-size:12px">' + u.esc(r.code) + '</span>' : '';
          },
        },
        {
          key: 'itemCount', label: '품목', align: 'right', width: '90px',
          render: function (r) {
            if (!r.itemCount) return '<span style="color:var(--text-tertiary)">0</span>';
            return '<span style="font-weight:600">' + r.itemCount + '</span>';
          },
        },
        { key: 'sort', label: '정렬', align: 'right', width: '70px' },
        {
          key: 'company', label: '소속', width: '120px',
          render: function (r) {
            return r.isShared ? '<span style="font-size:12px;color:var(--text-tertiary)">전사 공통</span>' : u.esc(r.company || '');
          },
        },
        {
          key: 'statusLabel', label: '상태', width: '80px',
          render: function (r) { return u.badge(r.statusLabel, r.status === 'active' ? 'ok' : 'muted'); },
        },
        {
          key: 'act', label: '', align: 'right', width: '120px',
          render: function (r) {
            if (!state.canManage) return '';
            return u.rowButton('수정', 'window.AdminItems.openCategory(' + r.id + ')') + ' ' +
              u.rowButton('삭제', 'window.AdminItems.removeCategory(' + r.id + ')', 'danger');
          },
        },
      ],
      rows: state.categories,
    });
  }

  function render() {
    var u = ui();
    var onItems = state.tab === 'items';
    var unused = state.categories.filter(function (c) { return !c.itemCount && !c.childCount; }).length;

    var notes = [];
    if (onItems) {
      notes.push(state.items.length + '개 품목');
      var off = state.items.filter(function (i) { return i.status !== 'active'; }).length;
      if (off) notes.push(off + '개 미사용');
    } else {
      notes.push(state.categories.length + '개 분류');
      if (unused) notes.push(unused + '개 비어 있음');
    }

    var action = state.canManage
      ? (onItems
        ? u.primaryButton('품목 추가', 'window.AdminItems.openItem()', 'plus')
        : u.primaryButton('분류 추가', 'window.AdminItems.openCategory()', 'plus'))
      : '';

    return u.pageHeader(
      '품목 · 분류',
      '자재를 세는 단위를 정합니다. 재고와 발주가 이 목록을 씁니다. — ' + notes.join(' · '),
      action
    ) + tabs() + (onItems ? itemsTable() : categoriesTable());
  }

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function draw() {
    paint(render());
    ui().bindSearch(state.tab === 'items' ? 'it-tbl' : 'ic-tbl');
  }

  function reload() {
    return call('api_getItemMaster').then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '목록을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.items = res.items || [];
      state.categories = res.categories || [];
      state.canManage = !!res.canManage;
      draw();
    });
  }

  function loadOptions(force) {
    if (state.options && !force) return Promise.resolve(state.options);
    return call('api_getItemMasterOptions').then(function (res) {
      if (res.success === false) throw new Error(res.error || '선택지를 불러오지 못했습니다.');
      state.options = res;
      return res;
    });
  }

  function setTab(tab) { state.tab = tab; draw(); }

  function openItem(id) {
    var u = ui();
    var row = id ? state.items.filter(function (r) { return r.id === id; })[0] : null;

    loadOptions().then(function (o) {
      u.formModal({
        title: row ? '품목 수정' : '품목 추가',
        subtitle: '코드는 같은 회사 안에서만 겹치지 않으면 됩니다.',
        saveLabel: row ? '수정' : '추가',
        fields: [
          { name: 'name', label: '품목명', required: true, group: '기본', value: row ? row.name : '', colSpan: 2 },
          { name: 'code', label: '코드 (SKU)', group: '기본', value: row ? row.code : '',
            hint: '비워두면 코드 없이 이름으로만 관리합니다.' },
          { name: 'categoryId', label: '분류', type: 'select', group: '기본',
            options: o.categories, value: row ? row.categoryId : '' },

          { name: 'unit', label: '단위', group: '수량 · 단가', value: row ? row.unit : '',
            hint: 'EA · BOX · M · KG 처럼 세는 단위' },
          { name: 'standardCost', label: '표준 단가 (USD)', group: '수량 · 단가',
            value: row && row.standardCost !== null ? row.standardCost : '' },

          { name: 'status', label: '상태', type: 'select', required: true, group: '분류 · 상태',
            options: o.statuses, value: row ? row.status : 'active',
            hint: '더 안 쓰는 품목은 지우지 말고 "미사용" 으로 두세요. 과거 기록이 남습니다.' },
          { name: 'companyId', label: '소속 회사', type: 'select', group: '분류 · 상태',
            options: o.companies, value: row ? row.companyId : '',
            hint: '비우면 전사 공통 품목이 됩니다.' },
          { name: 'description', label: '설명', type: 'textarea', colSpan: 2, group: '분류 · 상태',
            value: row ? row.description : '' },
        ],
        onSave: function (v) {
          v.id = id || 0;
          return call('api_saveItem', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(row ? '품목을 수정했습니다.' : '품목을 추가했습니다.');
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function openCategory(id) {
    var u = ui();
    var row = id ? state.categories.filter(function (r) { return r.id === id; })[0] : null;

    loadOptions().then(function (o) {
      // 자기 자신은 상위 후보에서 뺀다(고리가 생기면 화면이 무한히 돈다 — 서버도 막는다).
      var parents = (o.categories || []).filter(function (c) { return !row || String(c.value) !== String(row.id); });

      u.formModal({
        title: row ? '분류 수정' : '분류 추가',
        subtitle: row && row.itemCount
          ? '이 분류를 쓰는 품목이 ' + row.itemCount + '개 있습니다.'
          : '상위 분류를 고르면 두 단계로 묶입니다.',
        saveLabel: row ? '수정' : '추가',
        fields: [
          { name: 'name', label: '분류명', required: true, group: '기본', value: row ? row.name : '' },
          { name: 'code', label: '코드', group: '기본', value: row ? row.code : '' },
          { name: 'parentId', label: '상위 분류', type: 'select', group: '기본',
            options: parents, value: row ? row.parentId : '', colSpan: 2,
            hint: '비우면 최상위 분류가 됩니다.' },
          { name: 'sort', label: '정렬 순서', type: 'number', group: '표시',
            value: row ? row.sort : 0, hint: '작을수록 위에 옵니다.' },
          { name: 'status', label: '상태', type: 'select', required: true, group: '표시',
            options: o.statuses, value: row ? row.status : 'active' },
          { name: 'companyId', label: '소속 회사', type: 'select', group: '표시',
            options: o.companies, value: row ? row.companyId : '', colSpan: 2,
            hint: '비우면 전사 공통 분류가 됩니다.' },
        ],
        onSave: function (v) {
          v.id = id || 0;
          return call('api_saveItemCategory', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(row ? '분류를 수정했습니다.' : '분류를 추가했습니다.');
            // 분류가 바뀌면 폼 선택지도 바뀐다.
            state.options = null;
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function removeItem(id) {
    var u = ui();
    var row = state.items.filter(function (r) { return r.id === id; })[0];
    u.confirmDanger({
      title: '품목을 삭제할까요?',
      body: (row ? row.name : '이 품목') + ' 을(를) 삭제합니다. 되돌릴 수 없습니다. ' +
        '과거 기록을 남기려면 삭제 대신 상태를 "미사용" 으로 두세요.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteItem', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('품목을 삭제했습니다.');
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function removeCategory(id) {
    var u = ui();
    var row = state.categories.filter(function (r) { return r.id === id; })[0];

    // 쓰이는 분류는 서버가 막지만, 여기서 미리 알려주면 확인창을 띄웠다 지우는
    // 헛걸음을 줄일 수 있다.
    if (row && (row.itemCount || row.childCount)) {
      var why = row.itemCount
        ? '이 분류를 쓰는 품목이 ' + row.itemCount + '개 있습니다.'
        : '하위 분류가 ' + row.childCount + '개 있습니다.';
      u.toast(why + ' 먼저 정리하거나 상태를 "미사용" 으로 두세요.', 'error');
      return;
    }

    u.confirmDanger({
      title: '분류를 삭제할까요?',
      body: (row ? row.name : '이 분류') + ' 을(를) 삭제합니다. 되돌릴 수 없습니다.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteItemCategory', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('분류를 삭제했습니다.');
        state.options = null;
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

  global.AdminItems = {
    render: renderScreen,
    setTab: setTab,
    openItem: openItem,
    openCategory: openCategory,
    removeItem: removeItem,
    removeCategory: removeCategory,
    _state: state,
  };
})(window);

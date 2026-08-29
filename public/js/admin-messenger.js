/**
 * 메신저 방 · 메시지 관리 — Filament CommunicationRoom/Message 를 SPA 로 옮긴 것.
 *
 * 직원이 실제로 대화하는 곳은 출퇴근앱의 메시지 화면이다. 여기는 그 뒤편 —
 * 방을 만들고, 나중에 합류한 직원을 방에 넣고, 잘못 올라간 글을 지운다.
 *
 * 가장 자주 쓰는 버튼은 "구성원 동기화" 다. 현장에 나중에 온 사람은 방에 없어서
 * 공지를 못 보는데, 그 사실은 아무도 모른 채 지나간다. 그래서 표에 구성원 수를
 * 같이 띄우고 버튼을 행에 바로 붙였다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = { rooms: [], messages: [], options: null, canManage: false, limit: 0, tab: 'rooms', showArchived: false, archivedCount: 0 };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  function shortDate(v) {
    if (!v) return '';
    return String(v).slice(0, 16).replace('T', ' ');
  }

  function tabs() {
    var u = ui();
    var btn = function (key, label, n) {
      var on = state.tab === key;
      return '<button type="button" onclick="window.AdminMessenger.setTab(\'' + key + '\')" ' +
        'style="padding:8px 16px;border:none;background:none;font-size:14px;cursor:pointer;' +
        'border-bottom:2px solid ' + (on ? 'var(--brand-primary)' : 'transparent') + ';' +
        'color:' + (on ? 'var(--text-primary)' : 'var(--text-secondary)') + ';font-weight:' + (on ? '700' : '500') + '">' +
        u.esc(label) + ' <span style="color:var(--text-tertiary);font-weight:400">' + n + '</span></button>';
    };
    return '<div style="display:flex;gap:4px;border-bottom:1px solid var(--border-default);margin-bottom:16px">' +
      btn('rooms', '메신저 방', state.rooms.length) + btn('messages', '메시지', state.messages.length) + '</div>';
  }

  function roomsTable() {
    var u = ui();
    return u.table({
      id: 'cr-tbl',
      searchPlaceholder: '방 이름 · 현장 · 유형 검색',
      emptyText: '메신저 방이 없습니다. 현장을 등록하면 채팅방·공지방이 자동으로 생깁니다.',
      columns: [
        {
          key: 'name', label: '방 이름',
          render: function (r) {
            return '<div style="font-weight:600">' + u.esc(r.name) +
              (r.isReadOnly ? ' <span style="font-size:11px;color:var(--text-tertiary)">· 공지 전용</span>' : '') + '</div>' +
              (r.description ? '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.description) + '</div>' : '');
          },
        },
        { key: 'typeLabel', label: '유형', width: '160px' },
        {
          key: 'site', label: '현장', width: '90px',
          render: function (r) { return r.site ? u.badge(r.site, 'muted') : ''; },
        },
        {
          key: 'memberCount', label: '구성원', align: 'right', width: '80px',
          render: function (r) {
            // 구성원이 0 인 방은 아무에게도 안 보인다 — 공지를 올려도 읽힐 리 없다.
            if (!r.memberCount) return '<span style="color:var(--status-danger);font-weight:700">0</span>';
            return '<span style="font-weight:600">' + r.memberCount + '</span>';
          },
        },
        {
          key: 'messageCount', label: '메시지', align: 'right', width: '80px',
          render: function (r) {
            if (!r.messageCount) return '<span style="color:var(--text-tertiary)">0</span>';
            return String(r.messageCount);
          },
        },
        {
          key: 'lastMessageAt', label: '최근 메시지', width: '130px',
          render: function (r) {
            return '<span style="font-size:12px;color:var(--text-secondary)">' + u.esc(shortDate(r.lastMessageAt)) + '</span>';
          },
        },
        {
          key: 'status', label: '상태', width: '90px',
          render: function (r) { return u.badge(r.status === 'active' ? 'Active' : 'Archived', r.status === 'active' ? 'ok' : 'muted'); },
        },
        {
          key: 'act', label: '', align: 'right', width: '270px',
          render: function (r) {
            if (!state.canManage) return '';
            var html = '';
            var archived = r.status !== 'active';
            // 보관한 방은 직원 화면에서 내려가 있어 열리지 않는다 — 눌러서 403 을 보느니
            // 왜 못 여는지 알려 주는 편이 낫다.
            if (archived) {
              html += u.rowButton('보관 해제', "window.AdminMessenger.setArchived(" + r.id + ",false)") + ' ';
            } else {
              html += u.rowButton('열기', 'window.AdminMessenger.enterRoom(' + r.id + ')') + ' ';
              if (r.canSyncMembers) {
                html += u.rowButton('직원 동기화', 'window.AdminMessenger.syncMembers(' + r.id + ')') + ' ';
              }
            }
            html += u.rowButton('수정', 'window.AdminMessenger.openRoom(' + r.id + ')');
            if (!r.messageCount) {
              // 오간 대화가 없는 방은 지워도 잃을 기록이 없다.
              html += ' ' + u.rowButton('삭제', 'window.AdminMessenger.removeRoom(' + r.id + ')', 'danger');
            } else if (!archived) {
              // 대화가 쌓인 방은 지우지 않고 치운다 — 기록은 남고 목록에서만 사라진다.
              html += ' ' + u.rowButton('보관', "window.AdminMessenger.setArchived(" + r.id + ",true)");
            }
            return html;
          },
        },
      ],
      rows: state.rooms,
    });
  }

  function messagesTable() {
    var u = ui();
    return u.table({
      id: 'cm-tbl',
      searchPlaceholder: '내용 · 방 · 작성자 검색',
      emptyText: '메시지가 없습니다.',
      columns: [
        { key: 'room', label: '방', width: '150px' },
        {
          key: 'body', label: '내용',
          render: function (r) {
            var body = String(r.body || '');
            if (body.length > 90) body = body.slice(0, 90) + '…';
            return (r.title ? '<div style="font-weight:600">' + u.esc(r.title) + '</div>' : '') +
              '<div style="font-size:12px;color:var(--text-secondary);white-space:pre-wrap">' + u.esc(body) + '</div>' +
              (r.isReply ? '<div style="font-size:11px;color:var(--text-tertiary)">댓글</div>' : '');
          },
        },
        { key: 'kindLabel', label: '유형', width: '110px' },
        { key: 'sender', label: '작성자', width: '110px' },
        {
          key: 'priority', label: '중요도', width: '90px',
          render: function (r) {
            if (r.priority === 'urgent') return u.badge('Urgent', 'danger');
            if (r.priority === 'important') return u.badge('Important', 'warn');
            return '';
          },
        },
        {
          key: 'readCount', label: '읽음', align: 'right', width: '70px',
          render: function (r) { return r.readCount ? String(r.readCount) : '<span style="color:var(--text-tertiary)">0</span>'; },
        },
        {
          key: 'sentAt', label: '발송', width: '130px',
          render: function (r) {
            return '<span style="font-size:12px;color:var(--text-secondary)">' + u.esc(shortDate(r.sentAt)) + '</span>';
          },
        },
        {
          key: 'act', label: '', align: 'right', width: '120px',
          render: function (r) {
            if (!state.canManage) return '';
            return u.rowButton('수정', 'window.AdminMessenger.openMessage(' + r.id + ')') + ' ' +
              u.rowButton('삭제', 'window.AdminMessenger.removeMessage(' + r.id + ')', 'danger');
          },
        },
      ],
      rows: state.messages,
    });
  }

  /** 보관한 방을 꺼내 보는 줄 — 평소에는 목록이 깨끗해야 한다. */
  function archivedToggle() {
    if (!state.archivedCount && !state.showArchived) return '';
    return '<div style="margin-bottom:10px;font-size:13px;color:var(--text-secondary)">' +
      '<label style="display:inline-flex;align-items:center;gap:7px;cursor:pointer">' +
      '<input type="checkbox" onchange="window.AdminMessenger.toggleArchived()"' +
      (state.showArchived ? ' checked' : '') + '>' +
      '보관한 방도 보기 <span style="color:var(--text-tertiary)">(' + state.archivedCount + '개)</span>' +
      '</label></div>';
  }

  function render() {
    var u = ui();
    var onRooms = state.tab === 'rooms';

    var notes = [];
    if (onRooms) {
      notes.push(state.rooms.length + '개 방');
      var empty = state.rooms.filter(function (r) { return !r.memberCount; }).length;
      if (empty) notes.push(empty + '개 구성원 없음');
      if (state.archivedCount) notes.push('보관 ' + state.archivedCount + '개');
    } else {
      notes.push('최근 ' + state.messages.length + '건');
      if (state.limit && state.messages.length >= state.limit) notes.push('최신 ' + state.limit + '건만 표시');
    }

    var action = state.canManage
      ? (onRooms
        ? u.primaryButton('방 만들기', 'window.AdminMessenger.openRoom()', 'plus')
        : u.primaryButton('메시지 쓰기', 'window.AdminMessenger.openMessage()', 'plus'))
      : '';

    return u.pageHeader(
      '메신저 관리',
      onRooms
        ? '현장 방과 구성원을 관리합니다. 나중에 합류한 직원은 "직원 동기화" 로 넣어 주세요. — ' + notes.join(' · ')
        : '올라간 글을 확인하고 정리합니다. — ' + notes.join(' · '),
      action
    ) + tabs() + (onRooms ? (archivedToggle() + roomsTable()) : messagesTable());
  }

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function draw() {
    paint(render());
    ui().bindSearch(state.tab === 'rooms' ? 'cr-tbl' : 'cm-tbl');
  }

  function reload() {
    return call('api_getCommunicationAdmin', [{ includeArchived: state.showArchived }]).then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '목록을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.rooms = res.rooms || [];
      state.messages = res.messages || [];
      state.canManage = !!res.canManage;
      state.limit = res.messageLimit || 0;
      state.archivedCount = res.archivedCount || 0;
      draw();
    });
  }

  /** 보관함 보기 토글 — 치운 방을 다시 꺼내 볼 때. */
  function toggleArchived() {
    state.showArchived = !state.showArchived;
    reload();
  }

  /** 방을 치우거나 되돌린다. 지우지 않으므로 대화 기록은 그대로 남는다. */
  function setArchived(id, archived) {
    var u = ui();
    var r = state.rooms.filter(function (x) { return x.id === id; })[0];
    if (!r) return;

    call('api_saveCommunicationRoom', [{
      id: id, name: r.name, type: r.type, description: r.description,
      siteId: r.siteId, teamId: r.teamId, isReadOnly: r.isReadOnly,
      status: archived ? 'archived' : 'active',
    }]).then(function (res) {
      if (res.success === false) { u.toast(res.error || '바꾸지 못했습니다.', 'error'); return; }
      u.toast(archived
        ? '"' + r.name + '" 을 보관했습니다. 직원 화면에서는 사라지고 기록은 남습니다.'
        : '"' + r.name + '" 을 다시 열었습니다.');
      return reload();
    }).catch(function (e) { u.toast(e.message || '바꾸지 못했습니다.', 'error'); });
  }

  function loadOptions(force) {
    if (state.options && !force) return Promise.resolve(state.options);
    return call('api_getCommunicationAdminOptions').then(function (res) {
      if (res.success === false) throw new Error(res.error || '선택지를 불러오지 못했습니다.');
      state.options = res;
      return res;
    });
  }

  function setTab(tab) { state.tab = tab; draw(); }

  function openRoom(id) {
    var u = ui();
    var row = id ? state.rooms.filter(function (r) { return r.id === id; })[0] : null;

    loadOptions().then(function (o) {
      // 1:1 방은 직원이 대화를 시작할 때만 만들어져야 짝이 어긋나지 않는다.
      var types = row ? o.types : (o.types || []).filter(function (t) { return t.value !== 'direct'; });

      u.formModal({
        title: row ? '메신저 방 수정' : '메신저 방 만들기',
        subtitle: row && !row.memberCount
          ? '이 방에는 구성원이 없습니다. 저장 뒤 "직원 동기화" 를 눌러 주세요.'
          : '현장을 고르면 그 현장 직원을 한 번에 넣을 수 있습니다.',
        saveLabel: row ? '수정' : '만들기',
        fields: [
          { name: 'name', label: '방 이름', required: true, group: '기본', value: row ? row.name : '', colSpan: 2 },
          { name: 'type', label: '방 유형', type: 'select', required: true, group: '기본',
            options: types, value: row ? row.type : 'site_chat' },
          { name: 'status', label: '상태', type: 'select', group: '기본',
            options: o.statuses, value: row ? row.status : 'active',
            hint: '끝난 현장의 방은 지우지 말고 Archived 로 두세요. 대화 기록이 남습니다.' },
          { name: 'site_id', label: '현장', type: 'select', group: '소속',
            options: o.sites, value: row ? row.siteId : '',
            hint: '현장을 고른 방만 "직원 동기화" 를 쓸 수 있습니다.' },
          { name: 'team_id', label: '팀', type: 'select', group: '소속',
            options: o.teams, value: row ? row.teamId : '' },
          { name: 'is_read_only', label: '공지 전용', type: 'checkbox', group: '소속',
            value: row ? row.isReadOnly : false, checkboxLabel: '관리자만 글쓰기',
            hint: '켜면 직원은 댓글만 남길 수 있습니다.' },
          { name: 'description', label: '설명', type: 'textarea', group: '소속', colSpan: 2,
            value: row ? row.description : '' },
        ],
        onSave: function (v) {
          v.id = id || 0;
          return call('api_saveCommunicationRoom', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(row ? '메신저 방을 수정했습니다.' : '메신저 방을 만들었습니다.');
            state.options = null;
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function syncMembers(id) {
    var u = ui();
    call('api_syncCommunicationRoomMembers', [id]).then(function (res) {
      if (res.success === false) { u.toast(res.error || '동기화하지 못했습니다.', 'error'); return; }
      u.toast(res.added
        ? res.added + '명을 방에 추가했습니다. (구성원 ' + res.total + '명)'
        : '이미 모두 들어와 있습니다. (구성원 ' + res.total + '명)');
      return reload();
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function removeRoom(id) {
    var u = ui();
    var row = state.rooms.filter(function (r) { return r.id === id; })[0];

    if (row && row.messageCount) {
      u.toast('메시지 ' + row.messageCount + '건이 오간 방입니다. 삭제 대신 상태를 Archived 로 두세요.', 'error');
      return;
    }

    u.confirmDanger({
      title: '메신저 방을 삭제할까요?',
      body: (row ? row.name : '이 방') + ' 을(를) 삭제합니다. 구성원 명단도 함께 사라집니다.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteCommunicationRoom', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('메신저 방을 삭제했습니다.');
        state.options = null;
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function openMessage(id) {
    var u = ui();
    var row = id ? state.messages.filter(function (r) { return r.id === id; })[0] : null;

    loadOptions().then(function (o) {
      if (!row && !(o.rooms || []).length) {
        u.toast('열려 있는 방이 없습니다. 방을 먼저 만들어 주세요.', 'error');
        return;
      }

      var fields = [];
      if (row) {
        // 방을 옮기면 그 방 사람들이 읽지도 않은 글이 읽은 것으로 남는다. 고정한다.
        fields.push({ name: '_room', label: '방', value: row.room || '', group: '대상', colSpan: 2,
          hint: '메시지는 다른 방으로 옮길 수 없습니다.' });
      } else {
        fields.push({ name: 'communication_room_id', label: '방', type: 'select', required: true,
          group: '대상', options: o.rooms, colSpan: 2 });
      }

      fields.push(
        { name: 'kind', label: '메시지 유형', type: 'select', required: true, group: '내용',
          options: o.kinds, value: row ? row.kind : 'message',
          hint: '"공지" 로 올리면 방 구성원 전원에게 알림이 갑니다.' },
        { name: 'priority', label: '중요도', type: 'select', group: '내용',
          options: o.priorities, value: row ? row.priority : 'normal' },
        { name: 'title', label: '제목', group: '내용', colSpan: 2, value: row ? row.title : '' },
        { name: 'body', label: '내용', type: 'textarea', required: true, rows: 6, group: '내용',
          colSpan: 2, value: row ? row.body : '' },
        { name: 'is_pinned', label: '상단 고정', type: 'checkbox', group: '내용',
          value: row ? row.isPinned : false, checkboxLabel: '방 맨 위에 고정' }
      );

      u.formModal({
        title: row ? '메시지 수정' : '메시지 쓰기',
        subtitle: row ? '이미 읽은 사람에게는 고친 내용이 다시 알려지지 않습니다.' : '',
        saveLabel: row ? '수정' : '보내기',
        fields: fields,
        onSave: function (v) {
          v.id = id || 0;
          delete v._room;
          return call('api_saveCommunicationMessage', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(row ? '메시지를 수정했습니다.' : '메시지를 보냈습니다.');
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function removeMessage(id) {
    var u = ui();
    var row = state.messages.filter(function (r) { return r.id === id; })[0];

    u.confirmDanger({
      title: '메시지를 삭제할까요?',
      body: '올라간 글을 지웁니다. 되돌릴 수 없습니다.' +
        (row && row.readCount ? ' 이미 ' + row.readCount + '명이 읽었습니다.' : ''),
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteCommunicationMessage', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('메시지를 삭제했습니다.');
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  /** 그 방의 대화 화면으로 간다. ERP 안에 얹힌 상태(iframe)면 그 안에서 연다. */
  function enterRoom(id) {
    var url = '/attendance-app/messages/' + id;
    if (window.top !== window.self) { window.location.href = url; return; }
    window.open(url, '_blank', 'noopener');
  }

  function renderScreen() {
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    reload().catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' +
        ui().esc(e.message || '목록을 불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminMessenger = {
    render: renderScreen,
    enterRoom: enterRoom,
    setTab: setTab,
    openRoom: openRoom,
    syncMembers: syncMembers,
    removeRoom: removeRoom,
    setArchived: setArchived,
    toggleArchived: toggleArchived,
    openMessage: openMessage,
    removeMessage: removeMessage,
    _state: state,
  };
})(window);

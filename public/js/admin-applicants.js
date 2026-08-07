/**
 * 입사지원 → 면접 → 안전교육 → 배지 → 활성화 — Filament MemberRegistrationResource 를 SPA 로.
 *
 * 이 화면의 핵심은 폼이 아니라 줄(pipeline)이다. 지원자 30명이 각기 다른 단계에 걸려
 * 있을 때 "지금 누가 어디서 막혀 있나" 를 못 보는 것이 실제 문제다. 그래서:
 *
 *  - 목록 맨 앞에 단계 막대를 그린다. 몇 단계 중 몇 번째인지 한눈에 보인다.
 *  - 행마다 "다음에 할 일" 을 문장으로 적고, 그 일을 하는 버튼 하나만 띄운다.
 *    단계마다 쓸 수 있는 버튼이 다른데 전부 늘어놓으면 어느 걸 눌러야 할지 모른다.
 *  - 지원자가 아직 안 냈으면 "우리가 할 일 없음" 으로 구분한다. 재촉할 링크만 준다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = {
    rows: [], options: null, canManage: false, canSafety: false,
    filters: { status: '', siteId: '', onlyOpen: '1' },
  };

  var STAGE_KEYS = ['invited', 'submitted', 'interview', 'safety', 'badge', 'active'];

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  /** 단계 막대 — 지난 단계는 채우고 지금은 강조, 남은 것은 비운다. */
  function stageBar(r) {
    if (r.stage === 'rejected' || r.stage === 'archived') {
      return '<span style="font-size:12px;color:var(--status-danger)">반려됨</span>';
    }
    var idx = STAGE_KEYS.indexOf(r.stage);
    var dots = STAGE_KEYS.map(function (k, i) {
      var color = i < idx ? 'var(--status-success)'
        : i === idx ? 'var(--brand-primary)' : 'var(--border-default)';
      var w = i === idx ? '18px' : '10px';
      return '<span style="display:inline-block;width:' + w + ';height:6px;border-radius:3px;background:' + color + '"></span>';
    }).join('<span style="display:inline-block;width:3px"></span>');
    return '<div style="display:flex;align-items:center;gap:0;margin-bottom:3px">' + dots + '</div>' +
      '<div style="font-size:11px;color:var(--text-secondary)">' + ui().esc(r.stageLabel) +
      ' <span style="color:var(--text-tertiary)">' + (idx + 1) + '/' + STAGE_KEYS.length + '</span></div>';
  }

  /** 이 단계에서 할 수 있는 일 하나. 전부 늘어놓지 않는다. */
  function actionFor(r) {
    var u = ui();
    if (r.stage === 'active') {
      return u.rowButton('다시 반영', 'window.AdminApplicants.resync(' + r.id + ')');
    }
    if (r.stage === 'rejected' || r.stage === 'archived') return '';
    if (r.waitingOnApplicant) {
      return r.intakeUrl
        ? '<button type="button" onclick="window.AdminApplicants.copyLink(' + r.id + ')" ' +
          'style="padding:5px 10px;border-radius:6px;border:1px solid var(--border-default);background:transparent;' +
          'color:var(--text-secondary);font-size:12px;cursor:pointer">링크 복사</button>'
        : '';
    }
    if (r.stage === 'submitted' && state.canManage) {
      return u.rowButton('면접 결과', 'window.AdminApplicants.openInterview(' + r.id + ')');
    }
    if (r.stage === 'interview' && state.canSafety) {
      return u.rowButton('안전교육 등록', 'window.AdminApplicants.openSafety(' + r.id + ')');
    }
    if (r.stage === 'safety' && state.canManage) {
      return u.rowButton('배지 등록', 'window.AdminApplicants.openBadge(' + r.id + ')');
    }
    if (r.stage === 'badge' && state.canManage) {
      return '<button type="button" onclick="window.AdminApplicants.activate(' + r.id + ')" ' +
        'style="padding:5px 12px;border-radius:6px;border:none;background:var(--status-success);color:#fff;' +
        'font-size:12px;font-weight:600;cursor:pointer">활성화</button>';
    }
    return '';
  }

  function filterBar() {
    var u = ui();
    var o = state.options || {};
    var f = state.filters;
    return '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px;padding:12px;' +
      'background:var(--bg-surface);border:1px solid var(--border-default);border-radius:12px">' +
      '<div><label for="ap-status" style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px">상태</label>' +
      '<select id="ap-status" onchange="window.AdminApplicants.applyFilters()" style="padding:7px 10px;border-radius:8px;' +
      'border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:13px;min-width:160px">' +
      '<option value="">진행 중만</option>' +
      (o.statuses || []).map(function (x) {
        return '<option value="' + u.esc(x.value) + '"' + (String(x.value) === String(f.status) ? ' selected' : '') + '>' + u.esc(x.label) + '</option>';
      }).join('') + '</select></div>' +
      '<div><label for="ap-site" style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px">현장</label>' +
      '<select id="ap-site" onchange="window.AdminApplicants.applyFilters()" style="padding:7px 10px;border-radius:8px;' +
      'border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:13px;min-width:180px">' +
      '<option value="">전체</option>' +
      (o.sites || []).map(function (x) {
        return '<option value="' + u.esc(x.value) + '"' + (String(x.value) === String(f.siteId) ? ' selected' : '') + '>' + u.esc(x.label) + '</option>';
      }).join('') + '</select></div>' +
      (state.canManage ? '<button type="button" onclick="window.AdminApplicants.openInvite()" ' +
        'style="padding:8px 16px;border-radius:8px;border:none;background:var(--brand-primary);color:#fff;' +
        'font-size:13px;font-weight:600;cursor:pointer">지원서 링크 만들기</button>' : '') +
      '</div>';
  }

  function render() {
    var u = ui();
    var rows = state.rows;
    var ours = rows.filter(function (r) { return !r.waitingOnApplicant && r.stage !== 'active'; }).length;
    var theirs = rows.filter(function (r) { return r.waitingOnApplicant; }).length;

    var notes = [rows.length + '명'];
    if (ours) notes.push(ours + '명 우리 차례');
    if (theirs) notes.push(theirs + '명 지원자 대기');

    return u.pageHeader(
      '입사지원 · 온보딩',
      '지원 → 면접 → 안전교육 → 배지 → 활성화. 지금 누가 어디서 막혀 있는지 봅니다. — ' + notes.join(' · '),
      ''
    ) + filterBar() + u.table({
      id: 'ap-tbl',
      searchPlaceholder: '이름 · 이메일 · 지원자 코드 검색',
      emptyText: '진행 중인 지원자가 없습니다.',
      columns: [
        {
          key: 'name', label: '지원자', width: '190px',
          render: function (r) {
            return '<div style="font-weight:600">' + u.esc(r.name || '(이름 미입력)') + '</div>' +
              '<div style="font-size:11px;color:var(--text-tertiary)">' +
              u.esc(r.applicantCode || '') + (r.role ? ' · ' + u.esc(r.role) : '') + '</div>';
          },
        },
        { key: 'site', label: '현장', width: '100px' },
        { key: 'stage', label: '단계', width: '160px', render: stageBar },
        {
          key: 'nextAction', label: '다음에 할 일',
          render: function (r) {
            if (!r.nextAction) return '';
            var color = r.waitingOnApplicant ? 'var(--text-tertiary)' : 'var(--text-primary)';
            return '<div style="font-size:12px;color:' + color + '">' + u.esc(r.nextAction) + '</div>' +
              (r.waitingOnApplicant
                ? '<div style="font-size:11px;color:var(--status-warning);margin-top:2px">지원자 대기</div>'
                : '');
          },
        },
        {
          key: 'badgeNumber', label: '배지', width: '120px',
          render: function (r) {
            if (r.badgeNumber) {
              return '<span style="font-family:var(--font-mono,monospace);font-size:12px">' + u.esc(r.badgeNumber) + '</span>' +
                (r.hasBadgePhoto ? '' : '<div style="font-size:11px;color:var(--status-warning)">사진 없음</div>');
            }
            return '';
          },
        },
        {
          key: 'act', label: '', align: 'right', width: '150px',
          render: function (r) {
            var main = actionFor(r);
            var reject = (state.canManage && r.stage !== 'active' && r.stage !== 'rejected' && r.stage !== 'archived')
              ? ' ' + u.rowButton('반려', 'window.AdminApplicants.reject(' + r.id + ')', 'danger')
              : '';
            return main + reject;
          },
        },
      ],
      rows: rows,
    });
  }

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function reload() {
    return call('api_getApplicants', [state.filters]).then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '지원자를 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.rows = res.rows || [];
      state.canManage = !!res.canManage;
      state.canSafety = !!res.canSafety;
      paint(render());
      ui().bindSearch('ap-tbl');
    });
  }

  function loadOptions() {
    if (state.options) return Promise.resolve(state.options);
    return call('api_getApplicantOptions').then(function (res) {
      if (res.success === false) throw new Error(res.error || '선택지를 불러오지 못했습니다.');
      state.options = res;
      return res;
    });
  }

  function applyFilters() {
    var g = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
    state.filters = { status: g('ap-status'), siteId: g('ap-site'), onlyOpen: g('ap-status') ? '0' : '1' };
    reload();
  }

  function row(id) { return state.rows.filter(function (r) { return r.id === id; })[0]; }

  function openInvite() {
    var u = ui();
    loadOptions().then(function (o) {
      u.formModal({
        title: '지원서 링크 만들기',
        subtitle: '관리자가 지원서를 대신 쓰지 않습니다. 링크를 주면 지원자가 직접 작성해서 제출합니다(개인정보 동의는 본인이 눌러야 합니다).',
        saveLabel: '링크 만들기',
        fields: [
          { name: 'siteId', label: '현장', type: 'select', required: true, group: '어디로', options: o.sites, value: '' },
          { name: 'companyId', label: '소속 회사', type: 'select', group: '어디로', options: o.companies, value: '',
            hint: '협력사면 그 회사를 고르세요.' },
          { name: 'name', label: '이름', group: '누구에게 (선택)', value: '',
            hint: '알고 있으면 넣어두면 목록에서 찾기 쉽습니다.' },
          { name: 'email', label: '이메일', type: 'email', group: '누구에게 (선택)', value: '' },
          { name: 'phone', label: '전화', type: 'tel', group: '누구에게 (선택)', value: '' },
          { name: 'language', label: '언어', type: 'select', group: '누구에게 (선택)',
            options: [{ value: 'ko', label: '한국어' }, { value: 'en', label: 'English' }, { value: 'es', label: 'Español' }],
            value: 'ko', hint: '지원서가 이 언어로 열립니다.' },
        ],
        onSave: function (v) {
          return call('api_inviteApplicant', [v]).then(function (res) {
            if (res.success === false) return res;
            return reload().then(function () {
              showLink(res.url);
              return { success: true };
            });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  /** 만든 링크는 바로 복사할 수 있게 보여준다 — 목록에서 다시 찾게 하면 번거롭다. */
  function showLink(url) {
    var u = ui();
    var wrap = document.createElement('div');
    wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:20px';
    wrap.innerHTML = '<div role="dialog" aria-modal="true" style="background:var(--bg-surface);border:1px solid var(--border-default);' +
      'border-radius:14px;max-width:560px;width:100%;padding:22px">' +
      '<div style="font-size:16px;font-weight:700;color:var(--text-primary);margin-bottom:8px">지원서 링크가 만들어졌습니다</div>' +
      '<div style="font-size:13px;color:var(--text-secondary);margin-bottom:14px">이 링크를 지원자에게 보내세요. 지원자가 직접 작성해서 제출합니다.</div>' +
      '<input readonly value="' + u.esc(url) + '" id="ap-link" style="width:100%;padding:10px;border-radius:8px;' +
      'border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:12px;margin-bottom:14px">' +
      '<div style="display:flex;gap:8px;justify-content:flex-end">' +
      '<button type="button" data-x="copy" style="padding:9px 16px;border-radius:8px;border:1px solid var(--border-default);' +
      'background:var(--bg-base);color:var(--text-primary);font-size:13px;cursor:pointer">복사</button>' +
      '<button type="button" data-x="close" style="padding:9px 16px;border-radius:8px;border:none;background:var(--brand-primary);' +
      'color:#fff;font-size:13px;font-weight:600;cursor:pointer">닫기</button></div></div>';
    wrap.addEventListener('click', function (e) {
      var a = e.target.getAttribute && e.target.getAttribute('data-x');
      if (a === 'copy') {
        var el = wrap.querySelector('#ap-link');
        el.select();
        try { document.execCommand('copy'); u.toast('링크를 복사했습니다.'); } catch (err) { u.toast('복사에 실패했습니다. 직접 선택해 주세요.', 'error'); }
        return;
      }
      if (a === 'close' || e.target === wrap) wrap.remove();
    });
    document.body.appendChild(wrap);
  }

  function copyLink(id) {
    var r = row(id);
    if (r && r.intakeUrl) showLink(r.intakeUrl);
  }

  function openInterview(id) {
    var u = ui();
    var r = row(id);
    u.formModal({
      title: '면접 결과 — ' + (r ? r.name : ''),
      subtitle: '불합격도 기록해야 합니다. 아무 표시가 없으면 "아직 안 봤나" 와 구분이 안 됩니다.',
      saveLabel: '저장',
      fields: [
        { name: 'result', label: '결과', type: 'select', required: true, group: '결과',
          options: [{ value: 'passed', label: '합격' }, { value: 'failed', label: '불합격' }], value: 'passed',
          hint: '불합격을 고르면 지원이 반려 처리됩니다.' },
        { name: 'notes', label: '면접 메모', type: 'textarea', colSpan: 2, group: '결과', value: '' },
      ],
      onSave: function (v) {
        return call('api_setApplicantInterview', [id, v.result, v.notes]).then(function (res) {
          if (res.success === false) return res;
          u.toast(v.result === 'passed' ? '합격으로 기록했습니다. 다음은 안전교육입니다.' : '불합격으로 기록하고 반려했습니다.');
          return reload().then(function () { return { success: true }; });
        });
      },
    });
  }

  function openSafety(id) {
    var u = ui();
    var r = row(id);
    var today = new Date().toISOString().slice(0, 10);
    u.formModal({
      title: '안전교육 이수 — ' + (r ? r.name : ''),
      subtitle: 'Hoffman 안전교육을 마쳐야 배지를 받을 수 있습니다.',
      saveLabel: '등록',
      fields: [
        { name: 'completedOn', label: '이수일', type: 'date', required: true, group: '이수', value: today },
        { name: 'expiresOn', label: '만료일', type: 'date', group: '이수', value: '',
          hint: '만료가 다가오면 직원 목록에 표시됩니다.' },
      ],
      onSave: function (v) {
        return call('api_setApplicantSafety', [id, v.completedOn, v.expiresOn]).then(function (res) {
          if (res.success === false) return res;
          u.toast('안전교육을 등록했습니다. 다음은 배지 등록입니다.');
          return reload().then(function () { return { success: true }; });
        });
      },
    });
  }

  function openBadge(id) {
    var u = ui();
    var r = row(id);
    u.formModal({
      title: '배지 · NFC 등록 — ' + (r ? r.name : ''),
      subtitle: '배지를 스캔해 UID 를 넣고 사진을 올리면 AI 가 배지 내용을 읽습니다.',
      saveLabel: '등록',
      fields: [
        { name: 'nfcRawUid', label: 'NFC 원본 UID', required: true, group: '스캔',
          value: r ? r.nfcRawUid : '', colSpan: 2,
          hint: '리더에 배지를 대면 나오는 값 그대로. 시스템이 표준형으로 바꿔 저장합니다.' },
        { name: 'badgeIssuedOn', label: '배지 발급일', type: 'date', required: true, group: '스캔',
          value: r ? r.badgeIssuedOn : '',
          hint: '이 날짜가 입사일이 됩니다.' },
        { name: 'badgePrintedNumber', label: '배지 인쇄번호', group: '스캔', value: r ? r.badgePrintedNumber : '' },
        { name: 'badgeCompanyName', label: '배지 회사명', group: '스캔', value: r ? r.badgeCompanyName : '', colSpan: 2 },
        { name: 'photo', label: '배지 사진', type: 'file', group: '사진', colSpan: 2,
          accept: 'image/*',
          currentName: r && r.hasBadgePhoto ? '등록됨' : null,
          currentUrl: r ? r.badgePhotoUrl : null,
          hint: '나중에 사람 대조에 씁니다. 올리면 AI 가 이름·회사·발급일을 읽어 채웁니다.' },
      ],
      onSave: function (v) {
        var photo = v.photo;
        return call('api_registerApplicantBadge', [id, {
          nfcRawUid: v.nfcRawUid, badgeIssuedOn: v.badgeIssuedOn,
          badgePrintedNumber: v.badgePrintedNumber, badgeCompanyName: v.badgeCompanyName,
        }]).then(function (res) {
          if (res.success === false) return res;
          if (!photo) {
            u.toast('배지를 등록했습니다. NFC ID: ' + (res.badgeNumber || ''));
            return reload().then(function () { return { success: true }; });
          }
          return u.uploadFile('/admin-api/applicants/' + id + '/badge-photo', photo, { analyze: '1' })
            .then(function (up) {
              if (up.success === false) {
                // 배지 등록 자체는 됐으므로 실패로 되돌리지 않고 사진만 다시 올리라고 알린다.
                u.toast('배지는 등록됐지만 사진 업로드에 실패했습니다: ' + (up.error || ''), 'error');
              } else if (up.analysisFailed) {
                u.toast('사진은 올렸지만 AI 판독에 실패했습니다. 값은 직접 확인해 주세요.', 'error');
              } else {
                u.toast('배지와 사진을 등록했습니다. NFC ID: ' + (res.badgeNumber || ''));
              }
              return reload().then(function () { return { success: true }; });
            });
        });
      },
    });
  }

  function activate(id) {
    var u = ui();
    var r = row(id);
    u.confirmDanger({
      title: '활성화할까요?',
      body: (r ? r.name : '이 지원자') + ' 을(를) 직원으로 등록합니다. 출퇴근·급여·문서가 함께 만들어집니다.',
      confirmLabel: '활성화',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_activateApplicant', [id]).then(function (res) {
        if (res.success === false) {
          // 무엇이 모자란지 한꺼번에 보여준다.
          var why = (res.blockers && res.blockers.length)
            ? res.error + '\n· ' + res.blockers.join('\n· ')
            : (res.error || '활성화하지 못했습니다.');
          u.toast(why, 'error');
          return;
        }
        u.toast('활성화했습니다. 사번 ' + (res.employeeNumber || '') + ' 이(가) 발급되었습니다.');
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function reject(id) {
    var u = ui();
    var r = row(id);
    u.confirmDanger({
      title: '지원을 반려할까요?',
      body: (r ? r.name : '이 지원자') + ' 의 지원을 반려합니다. 기록은 남고, 다시 진행하려면 새 링크를 보내야 합니다.',
      confirmLabel: '반려',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_rejectApplicant', [id, null]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '반려하지 못했습니다.', 'error'); return; }
        u.toast('반려했습니다.');
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function resync(id) {
    var u = ui();
    call('api_resyncApplicant', [id]).then(function (res) {
      if (res.success === false) { u.toast(res.error || '다시 반영하지 못했습니다.', 'error'); return; }
      u.toast('직원 · 계정 · 문서에 다시 반영했습니다.');
      return reload();
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function renderScreen() {
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    loadOptions().then(reload).catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' +
        ui().esc(e.message || '지원자를 불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminApplicants = {
    render: renderScreen,
    applyFilters: applyFilters,
    openInvite: openInvite,
    copyLink: copyLink,
    openInterview: openInterview,
    openSafety: openSafety,
    openBadge: openBadge,
    activate: activate,
    reject: reject,
    resync: resync,
    _state: state,
  };
})(window);

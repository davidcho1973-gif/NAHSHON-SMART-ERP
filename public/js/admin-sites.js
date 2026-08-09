/**
 * 현장 · 프로젝트 — Filament SiteResource / ProjectResource 를 SPA 로 옮긴 것.
 *
 * 모든 것이 여기서 시작한다. 현장이 없으면 출퇴근도 QR 도 붙을 곳이 없고, 프로젝트가
 * 없으면 공정표를 올릴 대상이 없다. 두 가지를 한 화면 탭으로 둔 이유는 늘 같이
 * 만들기 때문이다 — 현장을 만들고 바로 그 현장의 프로젝트를 만든다.
 *
 * 삭제는 어렵게 만든다. sites 는 일곱 개 테이블에서 연쇄 삭제로 참조돼서, 잘못 지우면
 * 협력사 명단·QR·인원 마감이 함께 사라진다. 그래서 서버가 이유를 미리 계산해 보내 주고
 * 여기서는 삭제 버튼 자체를 잠근다 — 눌러 보고 거절당하는 것보다 낫다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = { sites: [], projects: [], options: null, canManage: false, tab: 'sites' };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  function money(v) {
    if (v === null || v === undefined || v === '') return '';
    return '$' + Number(v).toLocaleString('en-US', { maximumFractionDigits: 0 });
  }

  function tabs() {
    var u = ui();
    var btn = function (key, label, n) {
      var on = state.tab === key;
      return '<button type="button" onclick="window.AdminSites.setTab(\'' + key + '\')" ' +
        'style="padding:8px 16px;border:none;background:none;font-size:14px;cursor:pointer;' +
        'border-bottom:2px solid ' + (on ? 'var(--brand-primary)' : 'transparent') + ';' +
        'color:' + (on ? 'var(--text-primary)' : 'var(--text-secondary)') + ';font-weight:' + (on ? '700' : '500') + '">' +
        u.esc(label) + ' <span style="color:var(--text-tertiary);font-weight:400">' + n + '</span></button>';
    };
    return '<div style="display:flex;gap:4px;border-bottom:1px solid var(--border-default);margin-bottom:16px">' +
      btn('sites', '현장', state.sites.length) + btn('projects', '프로젝트', state.projects.length) + '</div>';
  }

  function sitesTable() {
    var u = ui();
    return u.table({
      id: 'st-tbl',
      searchPlaceholder: '현장 코드 · 이름 · 주소 검색',
      emptyText: '등록된 현장이 없습니다. 먼저 현장을 만들어야 출퇴근·공정이 붙습니다.',
      columns: [
        {
          key: 'code', label: '코드', width: '110px',
          render: function (r) {
            return '<span style="font-family:var(--font-mono,monospace);font-size:12px;font-weight:700">' +
              u.esc(r.code) + '</span>';
          },
        },
        {
          key: 'name', label: '현장명',
          render: function (r) {
            return '<div style="font-weight:600">' + u.esc(r.name) + '</div>' +
              (r.address ? '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.address) + '</div>' : '');
          },
        },
        {
          key: 'timezone', label: '타임존', width: '150px',
          render: function (r) {
            // 타임존이 틀리면 출퇴근 시각이 하루씩 밀린다. 눈에 띄게 둔다.
            return '<span style="font-size:12px;color:var(--text-secondary)">' + u.esc(r.timezone || '—') + '</span>';
          },
        },
        {
          key: 'projectCount', label: '프로젝트', align: 'right', width: '90px',
          render: function (r) {
            if (!r.projectCount) return '<span style="color:var(--text-tertiary)">0</span>';
            return '<span style="font-weight:600">' + r.projectCount + '</span>';
          },
        },
        {
          key: 'crewCount', label: '인원', align: 'right', width: '80px',
          render: function (r) {
            if (!r.crewCount) return '<span style="color:var(--text-tertiary)">0</span>';
            return '<span style="font-weight:600">' + r.crewCount + '</span>';
          },
        },
        { key: 'clientCompany', label: '발주처', width: '130px' },
        {
          key: 'status', label: '상태', width: '80px',
          render: function (r) { return u.badge(r.status === 'active' ? 'Active' : 'Inactive', r.status === 'active' ? 'ok' : 'muted'); },
        },
        {
          key: 'act', label: '', align: 'right', width: '120px',
          render: function (r) {
            if (!state.canManage) return '';
            var edit = u.rowButton('수정', 'window.AdminSites.openSite(' + r.id + ')');
            if (!r.deletable) return edit;
            return edit + ' ' + u.rowButton('삭제', 'window.AdminSites.removeSite(' + r.id + ')', 'danger');
          },
        },
      ],
      rows: state.sites,
    });
  }

  function projectsTable() {
    var u = ui();
    return u.table({
      id: 'pj-tbl',
      searchPlaceholder: '프로젝트 코드 · 이름 · 발주처 검색',
      emptyText: '등록된 프로젝트가 없습니다.',
      columns: [
        {
          key: 'projectCode', label: '코드', width: '160px',
          render: function (r) {
            return '<span style="font-family:var(--font-mono,monospace);font-size:12px;font-weight:700">' +
              u.esc(r.projectCode) + '</span>';
          },
        },
        {
          key: 'name', label: '프로젝트명',
          render: function (r) {
            return '<div style="font-weight:600">' + u.esc(r.name) + '</div>' +
              (r.endClient ? '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.endClient) + '</div>' : '');
          },
        },
        {
          key: 'site', label: '현장', width: '90px',
          render: function (r) { return r.site ? u.badge(r.site, 'muted') : ''; },
        },
        { key: 'constructionTypeLabel', label: '공사 유형', width: '130px' },
        {
          key: 'stageLabel', label: '단계', width: '110px',
          render: function (r) { return u.badge(r.stageLabel, 'muted'); },
        },
        {
          key: 'contractAmount', label: '계약 금액', align: 'right', width: '120px',
          render: function (r) { return u.esc(money(r.contractAmount)); },
        },
        {
          key: 'wbsCount', label: '공정', align: 'right', width: '80px',
          render: function (r) {
            if (!r.wbsCount) return '<span style="color:var(--text-tertiary)">—</span>';
            return '<span style="font-weight:600">' + r.wbsCount + '</span>';
          },
        },
        {
          key: 'act', label: '', align: 'right', width: '120px',
          render: function (r) {
            if (!state.canManage) return '';
            var edit = u.rowButton('수정', 'window.AdminSites.openProject(' + r.id + ')');
            if (r.wbsCount) return edit;
            return edit + ' ' + u.rowButton('삭제', 'window.AdminSites.removeProject(' + r.id + ')', 'danger');
          },
        },
      ],
      rows: state.projects,
    });
  }

  function render() {
    var u = ui();
    var onSites = state.tab === 'sites';

    var notes = [];
    if (onSites) {
      notes.push(state.sites.length + '개 현장');
      var noTz = state.sites.filter(function (s) { return !s.timezone; }).length;
      if (noTz) notes.push(noTz + '개 타임존 미설정');
    } else {
      notes.push(state.projects.length + '개 프로젝트');
      var noWbs = state.projects.filter(function (p) { return !p.wbsCount; }).length;
      if (noWbs) notes.push(noWbs + '개 공정표 없음');
    }

    var action = state.canManage
      ? (onSites
        ? u.primaryButton('현장 등록', 'window.AdminSites.openSite()', 'plus')
        : u.primaryButton('프로젝트 등록', 'window.AdminSites.openProject()', 'plus'))
      : '';

    return u.pageHeader(
      '현장 · 프로젝트',
      onSites
        ? '출퇴근·QR·인원이 붙는 기준입니다. 타임존이 근무 시각의 기준이 됩니다. — ' + notes.join(' · ')
        : '공정표·조달·원가가 프로젝트 코드로 묶입니다. — ' + notes.join(' · '),
      action
    ) + tabs() + (onSites ? sitesTable() : projectsTable());
  }

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function draw() {
    paint(render());
    ui().bindSearch(state.tab === 'sites' ? 'st-tbl' : 'pj-tbl');
  }

  function reload() {
    return call('api_getSiteAdmin').then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '목록을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.sites = res.sites || [];
      state.projects = res.projects || [];
      state.canManage = !!res.canManage;
      draw();
    });
  }

  function loadOptions(force) {
    if (state.options && !force) return Promise.resolve(state.options);
    return call('api_getSiteAdminOptions').then(function (res) {
      if (res.success === false) throw new Error(res.error || '선택지를 불러오지 못했습니다.');
      state.options = res;
      return res;
    });
  }

  function setTab(tab) { state.tab = tab; draw(); }

  function openSite(id) {
    var u = ui();
    var row = id ? state.sites.filter(function (r) { return r.id === id; })[0] : null;

    loadOptions().then(function (o) {
      u.formModal({
        title: row ? '현장 수정' : '현장 등록',
        subtitle: row && row.crewCount
          ? '이 현장에 배정된 직원이 ' + row.crewCount + '명 있습니다.'
          : '현장을 만들면 채팅방·공지방이 함께 생깁니다.',
        saveLabel: row ? '수정' : '등록',
        fields: [
          { name: 'code', label: '현장 코드', required: true, group: '기본', value: row ? row.code : '',
            hint: 'QR·출퇴근·문서에 찍히는 짧은 이름. 예: PHX1' },
          { name: 'name', label: '현장명', required: true, group: '기본', value: row ? row.name : '' },
          { name: 'address', label: '주소', group: '기본', colSpan: 2, value: row ? row.address : '' },

          { name: 'country', label: '국가', type: 'select', group: '시각 · 상태',
            options: o.countries, value: row ? row.country : 'US' },
          { name: 'timezone', label: '타임존', required: true, group: '시각 · 상태',
            value: row ? row.timezone : 'America/Phoenix',
            hint: '출퇴근 시각과 일일 마감의 기준입니다. 틀리면 하루가 밀립니다. 예: America/Phoenix' },
          { name: 'status', label: '상태', type: 'select', required: true, group: '시각 · 상태',
            options: o.statuses, value: row ? row.status : 'active',
            hint: '끝난 현장은 지우지 말고 Inactive 로 두세요. 출퇴근 기록이 남습니다.' },

          { name: 'company_id', label: '소속 회사', type: 'select', group: '소속',
            options: o.companies, value: row ? row.companyId : '' },
          { name: 'client_company_id', label: '발주처', type: 'select', group: '소속',
            options: o.companies, value: row ? row.clientCompanyId : '',
            hint: '이 현장을 발주한 회사. 거래처 목록에서 고릅니다.' },
        ],
        onSave: function (v) {
          v.id = id || 0;
          return call('api_saveSiteAdmin', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(row ? '현장을 수정했습니다.' : '현장을 등록했습니다.');
            state.options = null;
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function openProject(id) {
    var u = ui();
    var row = id ? state.projects.filter(function (r) { return r.id === id; })[0] : null;

    loadOptions().then(function (o) {
      if (!(o.sites || []).length) {
        u.toast('현장이 없습니다. 프로젝트는 현장에 속하므로 현장을 먼저 등록해 주세요.', 'error');
        state.tab = 'sites';
        draw();
        return;
      }

      var locked = !!(row && row.wbsCount);

      u.formModal({
        title: row ? '프로젝트 수정' : '프로젝트 등록',
        subtitle: locked
          ? '공정 ' + row.wbsCount + '건이 이 코드로 연결돼 있어 코드는 바꿀 수 없습니다.'
          : '코드를 비우면 현장·주·연도로 자동으로 만듭니다.',
        saveLabel: row ? '수정' : '등록',
        fields: [
          { name: 'name', label: '프로젝트명', required: true, group: '기본', value: row ? row.name : '', colSpan: 2 },
          { name: 'project_code', label: '프로젝트 코드', group: '기본', value: row ? row.projectCode : '',
            hint: locked
              ? '공정표가 있어 바꿀 수 없습니다.'
              : '비우면 자동 생성합니다. 공정·조달·사진이 이 코드로 묶입니다.' },
          { name: 'site_id', label: '현장', type: 'select', required: true, group: '기본',
            options: o.sites, value: row ? row.siteId : '' },

          { name: 'construction_type', label: '공사 유형', type: 'select', required: true, group: '계약',
            options: o.constructionTypes, value: row ? row.constructionType : '' },
          { name: 'project_stage', label: '단계', type: 'select', group: '계약',
            options: o.stages, value: row ? row.stage : 'estimate' },
          { name: 'end_client_company_id', label: '최종 발주처', type: 'select', group: '계약',
            options: o.companies, value: row ? row.endClientCompanyId : '' },
          { name: 'vendor_tier', label: '도급 차수', type: 'select', group: '계약',
            options: o.vendorTiers, value: row ? row.vendorTier : '' },
          { name: 'contract_type', label: '계약 형태', type: 'select', group: '계약',
            options: o.contractTypes, value: row ? row.contractType : '' },
          { name: 'po_number', label: 'PO 번호', group: '계약', value: row ? row.poNumber : '' },
          { name: 'contract_amount', label: '계약 금액 (USD)', type: 'number', group: '계약',
            value: row && row.contractAmount !== null ? row.contractAmount : '' },
          { name: 'state', label: '주 (State)', group: '계약', value: row ? row.state : '',
            hint: 'AZ · TX 처럼 두 글자. 세금·인증임금 규정이 주마다 다릅니다.' },

          { name: 'ntp_date', label: '착공 지시일 (NTP)', type: 'date', group: '일정',
            value: row ? row.ntpDate : '' },
          { name: 'mobilization_date', label: '투입일', type: 'date', group: '일정',
            value: row ? row.mobilizationDate : '' },
          { name: 'planned_completion_date', label: '계획 준공일', type: 'date', group: '일정',
            value: row ? row.plannedCompletionDate : '' },
          { name: 'actual_completion_date', label: '실제 준공일', type: 'date', group: '일정',
            value: row ? row.actualCompletionDate : '' },

          // 인증임금(WH-347) 대상 여부는 급여 마감 때 경고를 띄우는 근거다. 여기서
          // 안 켜면 제출 대상인 줄 모르고 지나간다.
          { name: 'prevailing_wage_required', label: '인증임금 (Prevailing Wage)', type: 'checkbox',
            group: '규정', value: row ? row.prevailingWageRequired : false, checkboxLabel: '적용 대상',
            hint: '켜면 급여 마감에서 WH-347 제출 대상으로 잡힙니다.' },
          { name: 'certified_payroll_required', label: '인증 급여 제출', type: 'checkbox',
            group: '규정', value: row ? row.certifiedPayrollRequired : false, checkboxLabel: '제출 필요' },
          { name: 'davis_bacon_required', label: 'Davis-Bacon', type: 'checkbox',
            group: '규정', value: row ? row.davisBaconRequired : false, checkboxLabel: '적용 대상' },
          { name: 'union_status', label: '노조 구분', type: 'select', group: '규정',
            options: o.unionStatuses, value: row ? row.unionStatus : '' },
          { name: 'ocip_ccip_status', label: 'OCIP / CCIP', type: 'select', group: '규정',
            options: o.ocipStatuses, value: row ? row.ocipCcipStatus : '' },
          { name: 'osha_plan_status', label: 'OSHA 계획', type: 'select', group: '규정',
            options: o.oshaStatuses, value: row ? row.oshaPlanStatus : '' },
          { name: 'bonding_required', label: '본드 (Bonding)', type: 'checkbox', group: '규정',
            value: row ? row.bondingRequired : false, checkboxLabel: '필요' },
          { name: 'lien_notice_required', label: '유치권 통지', type: 'checkbox', group: '규정',
            value: row ? row.lienNoticeRequired : false, checkboxLabel: '필요' },
          { name: 'preliminary_notice_due_on', label: '사전 통지 기한', type: 'date', group: '규정',
            value: row ? row.preliminaryNoticeDueOn : '', colSpan: 2,
            hint: '이 날짜를 넘기면 유치권 주장이 어려워집니다.' },

          { name: 'currency', label: '통화', type: 'select', group: '예산',
            options: o.currencies, value: row ? row.currency : 'USD' },
          { name: 'retainage_percent', label: '유보율 (%)', type: 'number', group: '예산',
            value: row && row.retainagePercent !== null ? row.retainagePercent : '' },
          { name: 'budget_labor_amount', label: '노무 예산', type: 'number', group: '예산',
            value: row && row.budgetLabor !== null ? row.budgetLabor : '',
            hint: '원가 대비 실적을 보는 기준선입니다.' },
          { name: 'budget_material_amount', label: '자재 예산', type: 'number', group: '예산',
            value: row && row.budgetMaterial !== null ? row.budgetMaterial : '' },
          { name: 'budget_equipment_amount', label: '장비 예산', type: 'number', group: '예산',
            value: row && row.budgetEquipment !== null ? row.budgetEquipment : '' },
          { name: 'budget_expense_amount', label: '경비 예산', type: 'number', group: '예산',
            value: row && row.budgetExpense !== null ? row.budgetExpense : '' },
          { name: 'payment_terms', label: '지급 조건', group: '예산', colSpan: 2,
            value: row ? row.paymentTerms : '', hint: '예: Progress Billing, Net 30' },

          { name: 'upper_contractor_company_id', label: '상위 도급사', type: 'select', group: '기타',
            options: o.companies, value: row ? row.upperContractorCompanyId : '' },
          { name: 'epc_company_id', label: 'EPC 사', type: 'select', group: '기타',
            options: o.companies, value: row ? row.epcCompanyId : '' },
          { name: 'site_address', label: '공사 주소', group: '기타', colSpan: 2,
            value: row ? row.siteAddress : '', hint: '현장 주소와 다를 때만 채우세요.' },
          { name: 'jurisdiction', label: '관할 (County 등)', group: '기타', value: row ? row.jurisdiction : '' },
          { name: 'per_diem_policy', label: '일비 정책', group: '기타', value: row ? row.perDiemPolicy : '' },
          { name: 'scope_of_work', label: '공사 범위', type: 'textarea', group: '기타', colSpan: 2,
            value: row ? row.scopeOfWork : '' },
        ],
        onSave: function (v) {
          v.id = id || 0;
          if (locked) v.project_code = row.projectCode;
          return call('api_saveProjectAdmin', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(row ? '프로젝트를 수정했습니다.' : '프로젝트를 등록했습니다: ' + (res.projectCode || ''));
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function removeSite(id) {
    var u = ui();
    var row = state.sites.filter(function (r) { return r.id === id; })[0];

    // 서버가 이미 이유를 계산해 보냈다. 확인창을 띄웠다 거절당하는 헛걸음을 줄인다.
    if (row && row.deleteBlocker) { u.toast(row.deleteBlocker, 'error'); return; }

    u.confirmDanger({
      title: '현장을 삭제할까요?',
      body: (row ? row.code + ' · ' + row.name : '이 현장') + ' 을(를) 삭제합니다. 되돌릴 수 없습니다. ' +
        '나중에 다시 쓸 수 있으면 삭제 대신 상태를 Inactive 로 두세요.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteSiteAdmin', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('현장을 삭제했습니다.');
        state.options = null;
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function removeProject(id) {
    var u = ui();
    var row = state.projects.filter(function (r) { return r.id === id; })[0];

    if (row && row.wbsCount) {
      u.toast('공정표 ' + row.wbsCount + '건이 등록된 프로젝트입니다. 공정관리에서 먼저 정리해 주세요.', 'error');
      return;
    }

    u.confirmDanger({
      title: '프로젝트를 삭제할까요?',
      body: (row ? row.projectCode + ' · ' + row.name : '이 프로젝트') + ' 을(를) 삭제합니다. 되돌릴 수 없습니다.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteProjectAdmin', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('프로젝트를 삭제했습니다.');
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

  global.AdminSites = {
    render: renderScreen,
    setTab: setTab,
    openSite: openSite,
    openProject: openProject,
    removeSite: removeSite,
    removeProject: removeProject,
    _state: state,
  };
})(window);

(function () {
  'use strict';
  var lines = [], u = window.AdminUI, host = document.getElementById('field-claim-content');
  var stages = {installed: '설치', installation: '설치', stored: '반입', completed: '완료'};
  var statuses = {pending: '확인 대기', verified: '확인 완료', rejected: '반려'};
  async function request(path, body) {
    var headers = {Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content};
    if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
    var r = await fetch('/field-claims' + path, {credentials: 'same-origin', method: body ? 'POST' : 'GET', headers: headers,
      body: body ? (body instanceof FormData ? body : JSON.stringify(body)) : undefined});
    var data = await r.json().catch(function () { return {}; });
    if (!r.ok || data.success === false) throw new Error(data.error || data.message || '요청하지 못했습니다. 로그인과 권한을 확인하세요.');
    return data;
  }
  async function load() {
    var d = await request('/data'); lines = [];
    host.innerHTML = d.sections.length ? d.sections.map(function (s) {
      return '<section><h2>' + u.esc(s.code + ' · ' + s.name) + '</h2>' + s.lines.map(function (l) {
        lines.push(l);
        return '<article><h3>#' + u.esc(l.lineNo + ' ' + l.description) + '</h3><p>공정 수량 ' + u.esc(l.quantity + ' ' + l.unit) + '</p>' +
          '<button data-upload="' + l.id + '">증빙 올리기</button><button data-record="' + l.id + '">작업 기록 · 검토 요청</button>' +
          '<p>내 증빙 ' + l.attachments.length + '개 · 원본은 검토 담당자가 열람합니다.</p>' +
          l.records.map(function (r) { return '<div class="record"><strong>' + u.esc(statuses[r.status] || r.status) + '</strong> · ' +
            u.esc(r.workDate + ' / ' + r.location + ' / ' + (stages[r.stage] || r.stage) + ' / ' + r.reportedQty + ' ' + l.unit) +
            '<p>' + u.esc(r.notes || '') + '</p><span class="' + (r.evidenceCount ? '' : 'missing') + '">' +
            (r.evidenceCount ? '증빙 ' + r.evidenceCount + '개' : '증빙 미첨부') + '</span>' +
            (r.status === 'pending' ? '<button data-record="' + l.id + '" data-id="' + r.id + '">수정 · 증빙 추가</button>' : '') + '</div>'; }).join('') + '</article>';
      }).join('') + '</section>';
    }).join('') : '<article><h2>담당 공정 배정을 기다리고 있습니다.</h2><p>관리자에게 현장과 담당 공종 배정을 요청하세요. 배정 후 이 화면에 공정이 나타납니다.</p></article>';
  }
  function upload(l) {
    var input;
    u.formModal({title: '증빙 한 번에 올리기', subtitle: l.description + ' · 파일당 최대 20MB. 원본을 보존하여 검토자에게 전달합니다.', saveLabel: '업로드',
      fields: [{name: 'files', label: '사진 · PDF · Word · Excel', type: 'file', accept: '.pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx', required: true, colSpan: 2}],
      onReady: function (el) { input = el.querySelector('input[type=file]'); input.multiple = true; },
      onSave: async function () {
        var files = Array.from(input.files); if (files.length > 20) return {success: false, error: '한 번에 20개까지 올려주세요.'};
        for (var f of files) { var form = new FormData(); form.append('file', f); await request('/lines/' + l.id + '/attachments', form); }
        await load(); u.toast('접수했습니다. 작업 기록에서 증빙을 선택해 검토 요청하세요.'); return {success: true};
      }
    });
  }
  function record(l, id) {
    var r = l.records.find(function (x) { return x.id === id; }) || {};
    var fields = [
      {name: 'workDate', label: '작업일', type: 'date', value: r.workDate || '', required: true},
      {name: 'stage', label: '작업 단계', type: 'select', options: l.stages.map(function (x) { return {value: x, label: stages[x] || x}; }), value: r.stage || l.stages[0], required: true},
      {name: 'reportedQty', label: '이번 수량 (' + l.unit + ')', type: 'number', value: r.reportedQty, required: true},
      {name: 'location', label: '작업 위치', value: r.location || '', required: true},
      {name: 'notes', label: '작업 내용', type: 'textarea', value: r.notes || '', colSpan: 2}
    ];
    l.attachments.forEach(function (a) { fields.push({name: 'proof_' + a.id, label: a.name, type: 'checkbox', checkboxLabel: '이 기록에 첨부',
      value: (r.evidence || []).some(function (e) { return e.attachmentId === a.id; }), colSpan: 2}); });
    u.formModal({title: (id ? '작업 기록 수정' : '작업 기록 작성') + ' · #' + l.lineNo,
      subtitle: '제출하면 확인 대기로 저장됩니다. 증빙이 없으면 미첨부로 표시됩니다.', saveLabel: '검토 요청', fields: fields,
      onSave: async function (v) {
        var body = {workDate: v.workDate, stage: v.stage, location: v.location, reportedQty: v.reportedQty, notes: v.notes,
          evidence: l.attachments.filter(function (a) { return v['proof_' + a.id]; }).map(function (a) { return {attachmentId: a.id, locator: '문서 전체'}; })};
        if (id) body.id = id;
        await request('/lines/' + l.id + '/records', body); await load(); u.toast('검토 요청을 저장했습니다.'); return {success: true};
      }
    });
  }
  host.addEventListener('click', function (e) {
    var target = e.target.closest('button'); if (!target) return;
    var l = lines.find(function (x) { return x.id === Number(target.dataset.upload || target.dataset.record); }); if (!l) return;
    if (target.dataset.upload) upload(l); else record(l, Number(target.dataset.id) || null);
  });
  load().catch(function (e) { host.textContent = e.message; });
})();

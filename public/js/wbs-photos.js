/**
 * 공정별 현장 사진 패널 — WBS 상세 편집 모달의 오른쪽 칸.
 *
 * 날짜별로 묶어 보여 준다. 공정 하나는 며칠에 걸쳐 진행되고, 사진의 가치는
 * "그날 어디까지 됐는지" 라서 날짜가 곧 목차다. 사진이 많아지면 패널 안에서만
 * 스크롤된다 — 왼쪽 편집 폼은 그대로 있어야 하니까.
 *
 * 업로드하면 서버가 즉시 줄여서 저장한다(장변 1,600px). 응답에 "8.2MB → 310KB"
 * 처럼 얼마나 줄었는지 보여 줘서, 사용자가 원본 그대로 올려도 된다는 걸 알게 한다.
 *
 * index.blade.php 가 아니라 별도 파일인 이유: 그 파일은 15,000 줄이고 한글이 깨진
 * 인코딩이라 큰 블록을 넣을수록 사고가 난다.
 */
(function () {
  'use strict';

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function csrf() {
    var el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') : '';
  }

  function req(method, url, formData) {
    return fetch(url, {
      method: method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: formData || undefined
    }).then(function (r) {
      return r.json().catch(function () {
        return { success: false, error: '서버 응답을 읽지 못했습니다 (HTTP ' + r.status + ').' };
      }).then(function (body) {
        if (!body || typeof body !== 'object') body = {};
        if (!('success' in body)) body.success = r.ok;
        if (!body.success && !body.error) body.error = body.message || ('요청이 거부되었습니다 (HTTP ' + r.status + ').');
        return body;
      });
    }).catch(function (e) {
      return { success: false, error: e.message || '네트워크 오류' };
    });
  }

  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n >= 1048576) return (n / 1048576).toFixed(1) + 'MB';
    if (n >= 1024) return Math.round(n / 1024) + 'KB';
    return n + 'B';
  }

  function fmtDate(iso) {
    // 2026-08-09 → 8/9 (토)
    var d = new Date(iso + 'T00:00:00');
    if (isNaN(d.getTime())) return iso;
    var day = ['일', '월', '화', '수', '목', '금', '토'][d.getDay()];
    return (d.getMonth() + 1) + '/' + d.getDate() + ' (' + day + ')';
  }

  function today() {
    var d = new Date();
    var m = String(d.getMonth() + 1);
    var day = String(d.getDate());
    if (m.length < 2) m = '0' + m;
    if (day.length < 2) day = '0' + day;
    return d.getFullYear() + '-' + m + '-' + day;
  }

  /** 큰 사진 보기 — 새 탭 대신 오버레이. 현장 폰에서 탭 전환은 길을 잃는다. */
  function openViewer(photo) {
    var old = document.getElementById('wbs-photo-viewer');
    if (old) old.remove();

    var ov = document.createElement('div');
    ov.id = 'wbs-photo-viewer';
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.88);z-index:10001;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;cursor:zoom-out';
    ov.innerHTML =
      '<img src="' + esc(photo.fileUrl) + '" style="max-width:96vw;max-height:82vh;object-fit:contain;border-radius:8px">' +
      (photo.caption
        ? '<div style="margin-top:12px;max-width:760px;max-height:20vh;overflow-y:auto;color:#fff;font-size:13px;line-height:1.6;text-align:center;white-space:pre-wrap">' + esc(photo.caption) + '</div>'
        : '') +
      '<div style="margin-top:8px;color:rgba(255,255,255,0.6);font-size:11px">닫으려면 아무 곳이나 누르세요</div>';
    ov.addEventListener('click', function () { ov.remove(); });
    document.body.appendChild(ov);
  }

  /**
   * 사진 패널을 컨테이너에 그린다.
   * openWbsEditModal 이 modal.querySelector('#wbs-photo-panel') 을 넘겨 부른다.
   */
  window.initWbsPhotoPanel = function (panel, wbsId) {
    if (!panel) return;

    panel.innerHTML =
      '<div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:10px;display:flex;align-items:center;gap:6px">' +
        '<i class="ph ph-camera" style="color:#7c3aed"></i> 현장 사진 <span id="wbs-photo-count" style="color:var(--text-tertiary);font-weight:400"></span></div>' +
      // 입구는 둘: 갤러리에서 고르기 / 폰 카메라로 바로 찍기(capture=environment 는 모바일에서
      // 곧장 후면 카메라를 연다. 데스크톱에서는 그냥 파일 선택으로 동작한다).
      // 어느 쪽이든 바로 올리지 않는다 — 찍고 나서 내용을 적은 뒤 "올리기" 를 눌러야 올라간다.
      '<div style="display:grid;gap:8px;margin-bottom:12px;padding:10px;border:1px solid var(--border-strong);border-radius:10px;background:var(--bg-base)">' +
        '<input type="date" id="wbs-photo-date" class="wbs-edit-field" value="' + today() + '" style="margin:0">' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">' +
          '<label class="btn-secondary" style="margin:0;cursor:pointer;white-space:nowrap;font-size:12px;padding:9px 10px;text-align:center">' +
            '<i class="ph ph-images"></i> 사진 올리기' +
            '<input type="file" id="wbs-photo-file" accept="image/*" multiple style="display:none">' +
          '</label>' +
          '<label class="btn-secondary" style="margin:0;cursor:pointer;white-space:nowrap;font-size:12px;padding:9px 10px;text-align:center">' +
            '<i class="ph ph-camera"></i> 바로 찍기' +
            '<input type="file" id="wbs-photo-camera" accept="image/*" capture="environment" style="display:none">' +
          '</label>' +
        '</div>' +
        // 찍은/고른 사진이 여기 잠시 모인다 — 내용을 적고 올리기 전까지.
        '<div id="wbs-photo-pending" style="display:none;gap:8px;grid-template-columns:1fr">' +
          '<div id="wbs-photo-previews" style="display:flex;gap:6px;flex-wrap:wrap"></div>' +
          '<textarea id="wbs-photo-caption" class="wbs-edit-field" rows="2" placeholder="사진 내용 (예: 2층 배관 용접 완료, 검사 대기)" style="margin:0;resize:vertical"></textarea>' +
          '<div style="display:grid;grid-template-columns:auto 1fr;gap:8px">' +
            '<button type="button" id="wbs-photo-clear" class="btn-secondary" style="margin:0;font-size:12px;padding:8px 12px">취소</button>' +
            '<button type="button" id="wbs-photo-send" class="btn-primary" style="margin:0;font-size:12px;padding:8px 12px;background:#7c3aed">올리기</button>' +
          '</div>' +
        '</div>' +
        '<div id="wbs-photo-upload-note" style="font-size:11px;color:var(--text-tertiary)">원본 그대로 올려도 서버가 줄여서 저장합니다.</div>' +
      '</div>' +
      // 목록: 날짜별 그룹. 패널 안에서만 스크롤.
      '<div id="wbs-photo-list" style="max-height:46vh;overflow-y:auto;padding-right:4px"></div>';

    var listEl = panel.querySelector('#wbs-photo-list');
    var countEl = panel.querySelector('#wbs-photo-count');
    var noteEl = panel.querySelector('#wbs-photo-upload-note');
    var fileEl = panel.querySelector('#wbs-photo-file');
    var cameraEl = panel.querySelector('#wbs-photo-camera');
    var pendingEl = panel.querySelector('#wbs-photo-pending');
    var previewsEl = panel.querySelector('#wbs-photo-previews');
    var sendBtn = panel.querySelector('#wbs-photo-send');
    var photoIndex = {};
    var pending = [];   // [{file, url}] — 올리기 전의 사진들

    function render(dates) {
      var total = 0;
      (dates || []).forEach(function (g) { total += (g.photos || []).length; });
      countEl.textContent = total ? '· ' + total + '장' : '';

      if (!total) {
        listEl.innerHTML = '<div style="padding:22px 10px;text-align:center;color:var(--text-tertiary);font-size:12px">아직 사진이 없습니다.<br>위에서 날짜를 고르고 사진을 올려 보세요.</div>';
        return;
      }

      photoIndex = {};
      listEl.innerHTML = (dates || []).map(function (g) {
        var rows = (g.photos || []).map(function (p) {
          photoIndex[p.id] = p;
          var shrunk = p.originalBytes > p.bytes
            ? '<span title="원본 ' + fmtBytes(p.originalBytes) + ' → 저장 ' + fmtBytes(p.bytes) + '">' + fmtBytes(p.bytes) + '</span>'
            : fmtBytes(p.bytes);
          // 썸네일 왼쪽, 설명(캡션) 오른쪽 — 설명이 길면 그 칸 안에서만 스크롤.
          return '<div style="display:grid;grid-template-columns:96px 1fr;gap:10px;margin-bottom:8px;padding:8px;border:1px solid var(--border-strong);border-radius:10px;background:var(--bg-base)">' +
            '<img src="' + esc(p.thumbUrl) + '" loading="lazy" data-photo-view="' + p.id + '" ' +
              'style="width:96px;height:72px;object-fit:cover;border-radius:6px;cursor:zoom-in;background:#111">' +
            '<div style="min-width:0;display:flex;flex-direction:column;gap:4px">' +
              '<div style="font-size:12px;color:var(--text-primary);line-height:1.5;white-space:pre-wrap;overflow-wrap:break-word;max-height:64px;overflow-y:auto">' +
                (p.caption ? esc(p.caption) : '<span style="color:var(--text-tertiary)">설명 없음</span>') + '</div>' +
              '<div style="display:flex;align-items:center;gap:8px;font-size:11px;color:var(--text-tertiary);margin-top:auto">' +
                shrunk +
                (p.uploadedBy ? ' · ' + esc(p.uploadedBy) : '') +
                (p.canEdit
                  ? '<span style="margin-left:auto;display:flex;gap:6px">' +
                      '<a href="#" data-photo-caption="' + p.id + '" style="color:var(--text-tertiary)" title="설명 수정"><i class="ph ph-pencil-simple"></i></a>' +
                      '<a href="#" data-photo-del="' + p.id + '" style="color:#ef4444" title="삭제"><i class="ph ph-trash"></i></a>' +
                    '</span>'
                  : '') +
              '</div>' +
            '</div>' +
          '</div>';
        }).join('');

        return '<div style="margin-bottom:12px">' +
          '<div style="font-size:12px;font-weight:700;color:var(--text-secondary);margin-bottom:6px;display:flex;align-items:center;gap:6px">' +
            '<i class="ph ph-calendar-blank"></i> ' + esc(fmtDate(g.date)) +
            '<span style="color:var(--text-tertiary);font-weight:400">· ' + (g.photos || []).length + '장</span></div>' +
          rows +
        '</div>';
      }).join('');
    }

    function reload() {
      return req('GET', '/wbs-api/photos?wbs=' + encodeURIComponent(wbsId)).then(function (res) {
        if (res.success) render(res.dates || []);
        else listEl.innerHTML = '<div style="padding:16px;color:#ef4444;font-size:12px">' + esc(res.error || '사진을 불러오지 못했습니다.') + '</div>';
      });
    }

    listEl.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-photo-view],[data-photo-caption],[data-photo-del]') : null;
      if (!t) return;
      e.preventDefault();

      var viewId = t.getAttribute('data-photo-view');
      if (viewId && photoIndex[viewId]) { openViewer(photoIndex[viewId]); return; }

      var capId = t.getAttribute('data-photo-caption');
      if (capId && photoIndex[capId]) {
        var next = prompt('사진 내용', photoIndex[capId].caption || '');
        if (next === null) return;
        var fd = new FormData();
        fd.append('caption', next);
        req('POST', '/wbs-api/photos/' + capId + '/caption', fd).then(function (res) {
          if (res.success) reload();
          else alert(res.error || '수정에 실패했습니다.');
        });
        return;
      }

      var delId = t.getAttribute('data-photo-del');
      if (delId) {
        if (!confirm('이 사진을 삭제할까요? 되돌릴 수 없습니다.')) return;
        req('DELETE', '/wbs-api/photos/' + delId).then(function (res) {
          if (res.success) reload();
          else alert(res.error || '삭제에 실패했습니다.');
        });
      }
    });

    // ── 올리기 전 대기열 ──────────────────────────────────────────────
    //
    // 찍자마자 올리지 않는 이유: 현장의 실제 순서는 "찍는다 → 무엇인지 적는다 → 올린다" 다.
    // 찍는 순간 올라가 버리면 내용은 영영 안 적히고, 한 달 뒤 그 사진이 무엇인지 아무도 모른다.

    function renderPending() {
      pendingEl.style.display = pending.length ? 'grid' : 'none';
      sendBtn.textContent = pending.length ? pending.length + '장 올리기' : '올리기';
      previewsEl.innerHTML = pending.map(function (it, i) {
        return '<div style="position:relative">' +
          '<img src="' + it.url + '" style="width:64px;height:64px;object-fit:cover;border-radius:8px;background:#111">' +
          '<a href="#" data-pending-remove="' + i + '" title="빼기" ' +
            'style="position:absolute;top:-6px;right:-6px;width:18px;height:18px;border-radius:50%;background:#ef4444;color:#fff;font-size:12px;line-height:18px;text-align:center;text-decoration:none">&times;</a>' +
        '</div>';
      }).join('');
    }

    function addPending(files) {
      files.forEach(function (f) {
        pending.push({ file: f, url: URL.createObjectURL(f) });
      });
      renderPending();
      var cap = panel.querySelector('#wbs-photo-caption');
      if (cap && files.length) cap.focus();
    }

    previewsEl.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-pending-remove]') : null;
      if (!t) return;
      e.preventDefault();
      var i = parseInt(t.getAttribute('data-pending-remove'), 10);
      if (pending[i]) { URL.revokeObjectURL(pending[i].url); pending.splice(i, 1); }
      renderPending();
    });

    panel.querySelector('#wbs-photo-clear').addEventListener('click', function () {
      pending.forEach(function (it) { URL.revokeObjectURL(it.url); });
      pending = [];
      panel.querySelector('#wbs-photo-caption').value = '';
      renderPending();
    });

    fileEl.addEventListener('change', function () {
      addPending(Array.prototype.slice.call(fileEl.files || []));
      fileEl.value = '';
    });
    // 카메라는 한 번에 한 장 — 여러 장 찍으려면 버튼을 다시 누른다(대기열에 계속 쌓인다).
    cameraEl.addEventListener('change', function () {
      addPending(Array.prototype.slice.call(cameraEl.files || []));
      cameraEl.value = '';
    });

    sendBtn.addEventListener('click', function () {
      if (!pending.length) return;

      var date = panel.querySelector('#wbs-photo-date').value;
      if (!date) { alert('사진 날짜를 먼저 골라 주세요.'); return; }
      var caption = panel.querySelector('#wbs-photo-caption').value.trim();

      var items = pending.slice();
      var done = 0, savedTotal = 0, originalTotal = 0, failed = [];
      sendBtn.disabled = true;
      noteEl.textContent = '올리는 중... (0/' + items.length + ')';

      // 순차 업로드 — 현장 LTE 에서 대용량 병렬 업로드는 전부 함께 느려지거나 끊긴다.
      var chain = Promise.resolve();
      items.forEach(function (it) {
        chain = chain.then(function () {
          var fd = new FormData();
          fd.append('wbs', wbsId);
          fd.append('photo', it.file);
          fd.append('photo_date', date);
          if (caption) fd.append('caption', caption);
          return req('POST', '/wbs-api/photos', fd).then(function (res) {
            done++;
            if (res.success) { savedTotal += res.saved || 0; originalTotal += res.original || 0; }
            else failed.push((it.file.name || '사진') + ': ' + (res.error || '실패'));
            noteEl.textContent = '올리는 중... (' + done + '/' + items.length + ')';
          });
        });
      });

      chain.then(function () {
        sendBtn.disabled = false;
        items.forEach(function (it) { URL.revokeObjectURL(it.url); });
        pending = [];
        panel.querySelector('#wbs-photo-caption').value = '';
        renderPending();
        var msg = (items.length - failed.length) + '장 저장';
        if (originalTotal > savedTotal && savedTotal > 0) {
          msg += ' · ' + fmtBytes(originalTotal) + ' → ' + fmtBytes(savedTotal) + ' 로 줄임';
        }
        noteEl.textContent = msg;
        if (failed.length) alert('일부 실패:\n' + failed.join('\n'));
        reload();
      });
    });

    reload();
  };
})();

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>현장 상황실</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; background: #f6f7f9; color: #111827; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #f6f7f9; }
        .app { min-height: 100vh; max-width: 520px; margin: 0 auto; background: #fff; display: flex; flex-direction: column; }
        header { padding: 18px 20px 14px; border-bottom: 1px solid #e5e7eb; }
        .back { display: inline-block; color: #2563eb; font-size: 13px; font-weight: 700; text-decoration: none; margin-bottom: 8px; }
        .eyebrow { margin: 0 0 4px; color: #16a34a; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; font-size: 24px; line-height: 1.2; }
        .sub { margin: 6px 0 0; color: #6b7280; font-size: 13px; line-height: 1.5; }
        main { padding: 16px 20px 40px; flex: 1; }
        .card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; margin-bottom: 16px; background: #fafafa; }
        /* 오늘 내 몫 — 이 화면에서 제일 먼저 눈에 들어와야 하는 칸이다. */
        .card.mine { background: #fffbea; border-color: #f2d675; }
        .msg.reopened { color: #b45309; font-weight: 700; }
        .card h2 { font-size: 15px; margin: 0 0 10px; }
        textarea { width: 100%; min-height: 120px; border: 1px solid #d1d5db; border-radius: 10px; padding: 11px; font-size: 15px; font-family: inherit; resize: vertical; background: #fff; color: #111827; }
        input[type=file] { font-size: 13px; margin-top: 10px; max-width: 100%; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #374151; border-radius: 10px; padding: 11px 16px; font-size: 14px; font-weight: 700; cursor: pointer; }
        .btn.primary { background: #145fff; border-color: #145fff; color: #fff; }
        .btn.danger { color: #b91c1c; border-color: #fecaca; background: #fef2f2; }
        .btn.full { width: 100%; }
        .btn:disabled { opacity: .55; }
        .row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .msg { font-size: 12.5px; color: #6b7280; margin-top: 8px; line-height: 1.5; }
        .list h2 { font-size: 15px; margin: 0 0 8px; }
        .item { border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; margin-bottom: 10px; background: #fff; cursor: pointer; }
        .meta { display: flex; gap: 7px; flex-wrap: wrap; align-items: center; margin-bottom: 5px; font-size: 11.5px; }
        .time { color: #6b7280; font-family: ui-monospace, Menlo, monospace; }
        .who { color: #374151; font-weight: 700; }
        .chip { border-radius: 999px; padding: 2px 8px; font-weight: 800; font-size: 10.5px; }
        .chip.work { background: #eef2ff; color: #3730a3; }
        .chip.photo { background: #ecfdf5; color: #047857; }
        .chip.edited { background: #fffbeb; color: #b45309; }
        .chip.applied { background: #ecfdf5; color: #047857; }
        .preview { font-size: 13px; color: #4b5563; line-height: 1.5; }
        .empty { color: #6b7280; font-size: 14px; padding: 20px 0; text-align: center; }
        pre.raw { white-space: pre-wrap; word-break: break-word; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; font-size: 13.5px; line-height: 1.6; margin: 0 0 12px; font-family: inherit; color: #111827; }
        details summary { font-size: 12.5px; color: #6b7280; font-weight: 700; cursor: pointer; margin-bottom: 8px; }
        .edited-note { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 9px 11px; font-size: 12.5px; margin-bottom: 10px; line-height: 1.5; }
        .warn { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 9px 11px; font-size: 12.5px; margin-bottom: 10px; line-height: 1.5; }
        .parsed { font-size: 12.5px; color: #374151; border-top: 1px solid #eef0f3; padding: 9px 0; }
        .parsed b { color: #111827; }
        .hidden { display: none; }
    </style>
</head>
<body>
    @include('partials.erp-home')
    <div class="app">
        <header>
            <a class="back" href="{{ route('attendance-app.index') }}">← 홈</a>
            <p class="eyebrow">{{ \App\Support\Org::name() }} · 현장 상황실</p>
            <h1>원문 기록</h1>
            <p class="sub">{{ $siteName ?: '전체 현장' }} · 올린 대화 원문이 그대로 보관됩니다.@if ($canManage) 수정·삭제할 수 있습니다.@endif</p>
        </header>

        <main>
            @if ($myTrade)
                {{-- 오늘 내 몫 — 반장이 자기 공종의 보고를 확정하는 자리.
                     이 신호가 없으면 소장은 "덕트가 아직 안 냈다" 를 알 수 없고,
                     빠진 공종이 있는 채로 마감보고서가 원청에 나간다. --}}
                <section class="card mine" id="mine-card">
                    <h2>오늘 내 보고 — {{ $myTrade }}</h2>
                    <p class="msg" id="mine-msg">
                        @if ($reportStatus === 'submitted')
                            ✅ 제출 완료 — 오늘 몫은 끝났습니다. 더 올리면 그대로 보고에 들어갑니다.
                        @else
                            올린 기록 {{ $reportEntries }}건. 오늘 한 일을 다 올리셨으면 아래를 눌러 주세요.
                        @endif
                    </p>
                    @if ($reopenReason)
                        {{-- 소장이 되돌린 이유 — 무엇을 더 올려야 하는지 알아야 다시 낸다. --}}
                        <p class="msg reopened">↩ 소장이 되돌렸습니다: {{ $reopenReason }}</p>
                    @endif
                    <div class="row">
                        <button class="btn primary full" id="submit-report"
                                @if ($reportStatus === 'submitted') disabled @endif>
                            {{ $reportStatus === 'submitted' ? '제출 완료' : '오늘 보고 제출' }}
                        </button>
                    </div>
                </section>
            @endif

            {{-- 새로 올리기 --}}
            <section class="card">
                <h2>현장 이야기 올리기</h2>
                <textarea id="raw" placeholder="예)&#10;천장 배관 20개 중 12개 했습니다&#10;그레이바 자재 화요일 도착&#10;내일 전기 3명 투입"></textarea>
                <input type="file" id="photos" accept="image/*" multiple capture="environment">
                <div class="row">
                    <button class="btn primary full" id="send">AI 판독 요청</button>
                </div>
                <p class="msg" id="send-msg">잡담은 자동으로 걸러집니다. 공정표 반영은 PC 상황실에서 확인 후 진행합니다.</p>
            </section>

            {{-- 목록 --}}
            <section class="list" id="list-screen">
                <h2>기록 <span id="count">({{ count($batches) }})</span></h2>
                <div id="list">
                    @forelse ($batches as $b)
                        <div class="item" data-id="{{ $b['id'] }}">
                            <div class="meta">
                                <span class="time">{{ $b['at'] }}</span>
                                @if (!empty($b['by']))<span class="who">{{ $b['by'] }}</span>@endif
                                <span class="chip work">업무 {{ $b['actionable'] }}건</span>
                                @if (!empty($b['imageCount']))<span class="chip photo">📷 {{ $b['imageCount'] }}</span>@endif
                                @if (!empty($b['edited']))<span class="chip edited">✎ 수정됨</span>@endif
                                @if (!empty($b['applied']))<span class="chip applied">반영 {{ $b['applied'] }}건</span>@endif
                            </div>
                            <div class="preview">{{ $b['preview'] }}</div>
                        </div>
                    @empty
                        <div class="empty">아직 기록이 없습니다.<br>위에 현장 이야기를 올리면 원문이 여기 보관됩니다.</div>
                    @endforelse
                </div>
            </section>

            {{-- 상세 --}}
            <section id="detail-screen" class="hidden"></section>
        </main>
    </div>

    <script>
        var API = @json(url('/smart-company-api'));
        var CSRF = document.querySelector('meta[name=csrf-token]').getAttribute('content');
        var CAN_MANAGE = @json($canManage);
        var photos = [];
        var STATUS = { pending: '확인 대기', applied: '반영됨', dismissed: '무시함', needs_input: '확인 필요' };

        function api(method, args) {
            return fetch(API + '/' + method, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ args: args || [], siteId: 'ALL' })
            }).then(function (r) { return r.json(); });
        }

        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        function show(which) {
            document.getElementById('list-screen').classList.toggle('hidden', which !== 'list');
            document.getElementById('detail-screen').classList.toggle('hidden', which !== 'detail');
            window.scrollTo(0, 0);
        }

        // 오늘 보고 제출 — 확정 신호. 되돌리기는 소장이 ERP 현황판에서 한다.
        var submitBtn = document.getElementById('submit-report');
        if (submitBtn) {
            submitBtn.addEventListener('click', function () {
                var msg = document.getElementById('mine-msg');
                submitBtn.disabled = true;
                fetch(@json(route('ops.trade-report.submit')), {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || d.success === false) {
                            if (msg) { msg.textContent = (d && d.error) || '제출하지 못했습니다.'; }
                            submitBtn.disabled = false;
                            return;
                        }
                        submitBtn.textContent = '제출 완료';
                        if (msg) { msg.textContent = '✅ ' + (d.message || '제출했습니다.'); }
                    })
                    .catch(function () {
                        if (msg) { msg.textContent = '연결에 실패했습니다. 잠시 뒤 다시 눌러 주세요.'; }
                        submitBtn.disabled = false;
                    });
            });
        }

        // 사진은 원본 그대로 들고 있다가 한 장씩 올린다 — 요청 하나가 작아 크기 제한이 사라지고,
        // 줄이는 일은 AI 에 넘기기 직전에 서버가 한다.
        document.getElementById('photos').addEventListener('change', function () {
            photos = Array.prototype.slice.call(this.files || [])
                .filter(function (f) { return /^image\//.test(f.type); })
                .slice(0, 20);
            var msg = document.getElementById('send-msg');
            if (msg && photos.length) { msg.textContent = '사진 ' + photos.length + '장 준비됨.'; }
        });

        function uploadPhotos(onProgress) {
            var tokens = [];
            return photos.reduce(function (chain, file, i) {
                return chain.then(function () {
                    if (onProgress) onProgress(i + 1, photos.length);
                    var fd = new FormData();
                    fd.append('photo', file);
                    return fetch(@json(route('ops.photo')), {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                        body: fd
                    }).then(function (r) { return r.json(); }).then(function (d) {
                        if (!d || !d.success) { throw new Error((d && d.error) || '사진 업로드 실패'); }
                        tokens.push(d.token);
                    });
                });
            }, Promise.resolve()).then(function () { return tokens; });
        }

        document.getElementById('send').addEventListener('click', function () {
            var raw = document.getElementById('raw').value.trim();
            var msg = document.getElementById('send-msg');
            if (!raw && !photos.length) { msg.textContent = '내용이나 사진을 올려 주세요.'; return; }
            var btn = this;
            btn.disabled = true; btn.textContent = photos.length ? '사진 올리는 중…' : 'AI 판독 중…';

            uploadPhotos(function (n, total) { msg.textContent = '사진 올리는 중 ' + n + '/' + total + '…'; })
                .then(function (tokens) {
                    btn.textContent = 'AI 판독 중…';
                    return api('api_opsIngest', [raw, tokens]);
                })
                .then(function (d) {
                    if (!d || d.success === false) { throw new Error((d && d.error) || '판독에 실패했습니다.'); }
                    document.getElementById('raw').value = '';
                    document.getElementById('photos').value = '';
                    photos = [];
                    return awaitJob(d.batchId, msg);
                })
                .then(function () { btn.disabled = false; btn.textContent = 'AI 판독 요청'; reload(); })
                .catch(function (e) {
                    btn.disabled = false; btn.textContent = 'AI 판독 요청';
                    msg.textContent = (e && e.message) || '네트워크 오류. 다시 시도하세요.';
                });
        });

        // 판독이 끝날 때까지 상태만 짧게 되묻는다 — 요청 하나가 수십 ms 라 시간 제한에 걸리지 않는다.
        function awaitJob(batchId, msg) {
            var started = Date.now();
            var delay = 1500;
            return new Promise(function (resolve) {
                (function tick() {
                    setTimeout(function () {
                        delay = Math.min(delay * 1.25, 5000);
                        var elapsed = Math.round((Date.now() - started) / 1000);
                        api('api_getOpsJob', [batchId]).then(function (j) {
                            if (!j || !j.success || j.status === 'analyzing') {
                                msg.textContent = 'AI 판독 중… ' + elapsed + '초 경과';
                                return tick();
                            }
                            if (j.status === 'failed') { msg.textContent = '판독 실패: ' + (j.error || ''); return resolve(); }
                            msg.textContent = '판독 완료 — 업무 ' + (j.actionable || 0) + '건 (' + elapsed + '초)';
                            resolve();
                        }).catch(function () {
                            // 현장 네트워크가 끊겼다 붙어도 결과를 잃지 않게 계속 되묻는다.
                            msg.textContent = 'AI 판독 중… ' + elapsed + '초 경과 (연결 재시도 중)';
                            tick();
                        });
                    }, delay);
                })();
            });
        }

        function reload() {
            api('api_getOpsBatches', []).then(function (d) {
                var list = (d && d.batches) || [];
                document.getElementById('count').textContent = '(' + list.length + ')';
                var host = document.getElementById('list');
                if (!list.length) { host.innerHTML = '<div class="empty">아직 기록이 없습니다.</div>'; return; }
                host.innerHTML = list.map(function (b) {
                    return '<div class="item" data-id="' + b.id + '"><div class="meta">' +
                        '<span class="time">' + esc(b.at) + '</span>' +
                        (b.by ? '<span class="who">' + esc(b.by) + '</span>' : '') +
                        '<span class="chip work">업무 ' + b.actionable + '건</span>' +
                        (b.imageCount ? '<span class="chip photo">📷 ' + b.imageCount + '</span>' : '') +
                        (b.edited ? '<span class="chip edited">✎ 수정됨</span>' : '') +
                        (b.applied ? '<span class="chip applied">반영 ' + b.applied + '건</span>' : '') +
                        '</div><div class="preview">' + esc(b.preview) + '</div></div>';
                }).join('');
                bind();
            });
            show('list');
        }

        function bind() {
            Array.prototype.forEach.call(document.querySelectorAll('.item'), function (el) {
                el.addEventListener('click', function () { openDetail(el.getAttribute('data-id')); });
            });
        }

        function openDetail(id) {
            api('api_getOpsBatch', [Number(id)]).then(function (d) {
                if (!d || d.success === false) { alert('원문을 불러오지 못했습니다.'); return; }
                var host = document.getElementById('detail-screen');
                var manage = CAN_MANAGE
                    ? '<div class="row"><button class="btn" id="edit-btn">✎ 수정</button><button class="btn danger" id="del-btn">🗑 삭제</button></div>'
                    : '';
                host.innerHTML =
                    '<div class="row" style="margin:0 0 12px"><button class="btn" id="back-btn">← 목록</button></div>' +
                    '<div class="meta"><span class="time">' + esc(d.at) + '</span>' +
                    (d.by ? '<span class="who">' + esc(d.by) + '</span>' : '') +
                    (d.imageCount ? '<span class="chip photo">📷 ' + d.imageCount + '</span>' : '') + '</div>' +
                    (d.editedAt ? '<div class="edited-note">✎ ' + esc(d.editedAt) + (d.editedBy ? ' · ' + esc(d.editedBy) : '') + ' 수정됨</div>' : '') +
                    (d.appliedCount ? '<div class="warn">이 원문에서 <b>' + d.appliedCount + '건</b>이 이미 공정표에 반영됐습니다. 삭제하려면 PC 상황실에서 먼저 되돌리세요.</div>' : '') +
                    '<pre class="raw">' + esc(d.raw || '(사진만 첨부)') + '</pre>' +
                    (d.originalText ? '<details><summary>최초 원문 (수정 전) 보기</summary><pre class="raw">' + esc(d.originalText) + '</pre></details>' : '') +
                    manage +
                    '<div style="margin-top:14px"><h2 style="font-size:14px;margin:0 0 4px">뽑은 항목 (' + (d.items || []).length + '건)</h2>' +
                    ((d.items || []).length
                        ? d.items.map(function (it) {
                            return '<div class="parsed"><b>' + esc(it.targetName || it.targetCode || it.categoryLabel || it.category) + '</b> · ' + esc(STATUS[it.status] || it.status) +
                                (it.summary ? '<br><span style="color:#6b7280">' + esc(it.summary) + '</span>' : '') + '</div>';
                        }).join('')
                        : '<div class="empty">뽑힌 항목이 없습니다.</div>') +
                    '</div>';
                show('detail');

                document.getElementById('back-btn').addEventListener('click', reload);
                var eb = document.getElementById('edit-btn');
                if (eb) eb.addEventListener('click', function () { openEdit(d); });
                var db = document.getElementById('del-btn');
                if (db) db.addEventListener('click', function () { removeBatch(d.id); });
            });
        }

        function openEdit(d) {
            var host = document.getElementById('detail-screen');
            host.innerHTML =
                '<div class="row" style="margin:0 0 12px"><button class="btn" id="cancel-btn">← 취소</button></div>' +
                '<h2 style="font-size:15px;margin:0 0 8px">원문 수정</h2>' +
                '<textarea id="edit-text" style="min-height:220px"></textarea>' +
                '<p class="msg">고치기 전 내용은 <b>최초 원문</b>으로 보관되고, 누가 언제 고쳤는지 남습니다. 이미 반영된 공정표 값은 바뀌지 않습니다.</p>' +
                '<div class="row"><button class="btn primary full" id="save-btn">저장</button></div>';
            document.getElementById('edit-text').value = d.raw || '';
            document.getElementById('cancel-btn').addEventListener('click', function () { openDetail(d.id); });
            document.getElementById('save-btn').addEventListener('click', function () {
                var btn = this;
                btn.disabled = true; btn.textContent = '저장 중…';
                api('api_updateOpsBatch', [d.id, document.getElementById('edit-text').value]).then(function (r) {
                    btn.disabled = false; btn.textContent = '저장';
                    if (!r || !r.success) { alert((r && r.error) || '수정에 실패했습니다.'); return; }
                    openDetail(d.id);
                });
            });
        }

        function removeBatch(id) {
            if (!confirm('이 원문 기록을 삭제할까요?\n대화 원문과 그때 뽑힌 판독 항목이 함께 지워집니다. (되돌릴 수 없습니다)')) return;
            api('api_deleteOpsBatch', [id]).then(function (r) {
                if (!r || !r.success) { alert((r && r.error) || '삭제에 실패했습니다.'); return; }
                reload();
            });
        }

        bind();
    </script>
</body>
</html>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>오늘 보고</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; background: #f6f7f9; color: #111827; }
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        body { margin: 0; min-height: 100vh; background: #f6f7f9; }
        .app { min-height: 100vh; max-width: 520px; margin: 0 auto; background: #fff; display: flex; flex-direction: column; }
        header { padding: 18px 20px 14px; border-bottom: 1px solid #e5e7eb; }
        .back { display: inline-block; color: #2563eb; font-size: 13px; font-weight: 700; text-decoration: none; margin-bottom: 8px; }
        .eyebrow { margin: 0 0 4px; color: #16a34a; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; font-size: 24px; line-height: 1.2; }

        /* 상태 한 줄 — 화면을 열자마자 «지금 어디까지 왔나» 를 말해 준다. */
        .state { margin: 8px 0 0; font-size: 14px; line-height: 1.5; font-weight: 700; color: #374151; }
        .state.done { color: #15803d; }
        .state.todo { color: #b45309; }
        .state-sub { margin: 4px 0 0; font-size: 12.5px; color: #6b7280; line-height: 1.5; }

        main { padding: 18px 20px 40px; flex: 1; }

        /* ── 제일 큰 것 하나: 말하기 ─────────────────────────────────
           현장은 장갑을 끼고 손이 더럽다. 타자보다 말이 빠르다. */
        .mic { width: 100%; border: 0; border-radius: 18px; padding: 26px 20px; background: #145fff; color: #fff;
               cursor: pointer; text-align: center; box-shadow: 0 6px 18px rgba(20,95,255,.28); }
        .mic:disabled { opacity: .6; }
        .mic .icon { font-size: 40px; line-height: 1; display: block; margin-bottom: 8px; }
        .mic .label { font-size: 20px; font-weight: 800; display: block; }
        .mic .hint { font-size: 12.5px; opacity: .88; display: block; margin-top: 5px; }
        .mic.rec { background: #dc2626; box-shadow: 0 6px 18px rgba(220,38,38,.3); animation: pulse 1.4s ease-in-out infinite; }
        @keyframes pulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.015); } }
        @media (prefers-reduced-motion: reduce) { .mic.rec { animation: none; } }

        .two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid #d1d5db;
               background: #fff; color: #374151; border-radius: 12px; padding: 14px 12px; font-size: 15px; font-weight: 700;
               cursor: pointer; font-family: inherit; }
        .btn.primary { background: #145fff; border-color: #145fff; color: #fff; }
        .btn.danger { color: #b91c1c; border-color: #fecaca; background: #fef2f2; }
        .btn.full { width: 100%; }
        .btn:disabled { opacity: .55; }
        .btn.on { border-color: #145fff; color: #145fff; background: #eff4ff; }
        .row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }

        .writer { margin-top: 14px; }
        textarea { width: 100%; min-height: 130px; border: 1px solid #d1d5db; border-radius: 12px; padding: 12px;
                   font-size: 16px; font-family: inherit; resize: vertical; background: #fff; color: #111827; line-height: 1.6; }
        .heard { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; border-radius: 10px;
                 padding: 9px 11px; font-size: 12.5px; margin-bottom: 8px; line-height: 1.5; font-weight: 700; }

        .shots { display: flex; gap: 7px; flex-wrap: wrap; margin-top: 10px; }
        .shot { position: relative; width: 62px; height: 62px; border-radius: 10px; overflow: hidden; border: 1px solid #e5e7eb; }
        .shot img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .shot button { position: absolute; top: 2px; right: 2px; width: 20px; height: 20px; border-radius: 50%;
                       border: 0; background: rgba(0,0,0,.62); color: #fff; font-size: 13px; line-height: 1; cursor: pointer; padding: 0; }

        .msg { font-size: 13px; color: #6b7280; margin-top: 10px; line-height: 1.55; }
        .msg.warn { color: #b45309; font-weight: 700; }
        .msg.ok { color: #15803d; font-weight: 700; }

        /* ── 오늘 올린 것 ───────────────────────────────────────── */
        .sec-title { font-size: 14px; font-weight: 800; margin: 26px 0 8px; color: #111827; }
        .item { border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; margin-bottom: 9px; background: #fff; cursor: pointer; }
        .meta { display: flex; gap: 7px; flex-wrap: wrap; align-items: center; margin-bottom: 5px; font-size: 11.5px; }
        .time { color: #6b7280; font-family: ui-monospace, Menlo, monospace; }
        .who { color: #374151; font-weight: 700; }
        .chip { border-radius: 999px; padding: 2px 8px; font-weight: 800; font-size: 10.5px; }
        .chip.work { background: #eef2ff; color: #3730a3; }
        .chip.photo { background: #ecfdf5; color: #047857; }
        .chip.edited { background: #fffbeb; color: #b45309; }
        .chip.applied { background: #ecfdf5; color: #047857; }
        .preview { font-size: 13.5px; color: #4b5563; line-height: 1.55; }
        .empty { color: #6b7280; font-size: 13.5px; padding: 18px 0; text-align: center; line-height: 1.6; }
        .more { display: block; width: 100%; text-align: center; background: none; border: 0; color: #2563eb;
                font-size: 13px; font-weight: 700; padding: 10px; cursor: pointer; font-family: inherit; }

        /* ── 제출 ─────────────────────────────────────────────── */
        .submit-box { margin-top: 22px; padding-top: 18px; border-top: 1px solid #e5e7eb; }
        .finish-hint { margin: 0 0 8px; font-size: 12.5px; color: #6b7280; text-align: center; }
        .btn.finish { background: #15803d; border-color: #15803d; color: #fff; padding: 16px 12px; font-size: 16px; }
        .reflected { color: #15803d; font-weight: 700; font-size: 12.5px; margin-top: 8px; line-height: 1.5; }
        .reopened { color: #b45309; font-weight: 700; font-size: 12.5px; margin-top: 8px; line-height: 1.5; }

        pre.raw { white-space: pre-wrap; word-break: break-word; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; font-size: 13.5px; line-height: 1.6; margin: 0 0 12px; font-family: inherit; color: #111827; }
        details summary { font-size: 12.5px; color: #6b7280; font-weight: 700; cursor: pointer; margin-bottom: 8px; }
        .edited-note { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 9px 11px; font-size: 12.5px; margin-bottom: 10px; line-height: 1.5; }
        .warnbox { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 9px 11px; font-size: 12.5px; margin-bottom: 10px; line-height: 1.5; }
        .parsed { font-size: 12.5px; color: #374151; border-top: 1px solid #eef0f3; padding: 9px 0; }
        .parsed b { color: #111827; }
    </style>
</head>
<body>
    @include('partials.erp-home')
    <div class="app">
        <header>
            <a class="back" href="{{ route('attendance-app.index') }}">← 홈</a>
            <p class="eyebrow">{{ \App\Support\Org::name() }} · {{ $siteName ?: '현장' }}</p>
            <h1>{{ $myTrade ? '오늘 보고 — '.$myTrade : '현장 기록' }}</h1>

            {{-- 화면을 여는 순간 «지금 뭘 해야 하나» 가 읽혀야 한다. --}}
            @if ($myTrade)
                @if ($reportStatus === 'submitted')
                    <p class="state done" id="state">✅ 오늘 보고 제출을 마쳤습니다</p>
                    <p class="state-sub" id="state-sub">더 올리시면 그대로 오늘 보고에 들어갑니다.</p>
                @elseif ($reportEntries > 0)
                    <p class="state todo" id="state">아직 제출 전 — 올린 것 {{ $reportEntries }}건</p>
                    <p class="state-sub" id="state-sub">더 없으시면 맨 아래 「오늘 보고 제출」을 눌러 주세요.</p>
                @else
                    <p class="state todo" id="state">오늘 아직 아무것도 안 올리셨습니다</p>
                    <p class="state-sub" id="state-sub">한 일을 말하거나 적어 주시면 됩니다. 1분이면 끝납니다.</p>
                @endif
            @else
                <p class="state" id="state">현장에서 있었던 일을 남기는 곳입니다</p>
                <p class="state-sub" id="state-sub">말하거나 적어 주시면 ERP 가 알아서 정리합니다.</p>
            @endif
        </header>

        <main>
            <section id="compose">
                {{-- ① 말하기 — 이 화면의 주인공. 현장에서 제일 빠른 길이다. --}}
                <button class="mic" id="mic" type="button">
                    <span class="icon" id="mic-icon">🎤</span>
                    <span class="label" id="mic-label">눌러서 말하기</span>
                    <span class="hint" id="mic-hint">오늘 한 일을 말씀하세요. 글자로 바꿔 드립니다.</span>
                </button>

                {{-- ② 두 번째 길 — 글로 쓰기, 그리고 사진(증거) --}}
                <div class="two">
                    <button class="btn" id="write-toggle" type="button">⌨️ 글로 쓰기</button>
                    <button class="btn" id="photo-btn" type="button">📷 사진 붙이기</button>
                </div>
                <input type="file" id="photos" accept="image/*" multiple capture="environment" hidden>

                <div class="writer" id="writer" hidden>
                    <p class="heard" id="heard" hidden>말씀하신 내용입니다. 틀린 곳이 있으면 고치고 보내 주세요.</p>
                    <textarea id="raw" placeholder="예)&#10;3층 천장 배관 20개 중 12개 했습니다&#10;그레이바 자재 화요일 도착&#10;내일 전기 3명 투입"></textarea>
                </div>

                <div class="shots" id="shots"></div>

                <div class="row" id="send-row" hidden>
                    <button class="btn primary full" id="send" type="button">보내기</button>
                </div>

                <p class="msg" id="msg">말하거나 적으시면 ERP 가 알아서 정리합니다. 사진은 증거로 함께 붙일 수 있습니다.</p>
            </section>

            <section id="list-screen">
                <div class="sec-title">오늘 올린 것 <span id="count"></span></div>
                <div id="list"></div>
                <button class="more" id="more" type="button" hidden></button>
            </section>

            @if ($myTrade)
                <section class="submit-box">
                    @if ($reopenReason)
                        <p class="reopened">↩ 소장이 되돌렸습니다: {{ $reopenReason }}</p>
                    @endif
                    {{-- 「보내기」와 색을 다르게 둔다. 하나는 올리는 일(여러 번), 하나는
                         오늘을 끝내는 일(하루 한 번)이라, 같은 파란 버튼 둘이 나란히 있으면
                         현장에서 헷갈린다. --}}
                    <p class="finish-hint">오늘 올릴 것을 다 올리셨으면</p>
                    <button class="btn finish full" id="submit-report" type="button"
                            @if ($reportStatus === 'submitted') disabled @endif>
                        {{ $reportStatus === 'submitted' ? '✓ 제출 완료' : '오늘 보고 제출' }}
                    </button>
                    @if ($reflectionNote)
                        <p class="reflected" id="mine-reflect">↳ {{ $reflectionNote }}</p>
                    @else
                        <p class="reflected" id="mine-reflect" hidden></p>
                    @endif
                </section>
            @endif

            <section id="detail-screen" hidden></section>
        </main>
    </div>

    <script>
        var API = @json(url('/smart-company-api'));
        var CSRF = document.querySelector('meta[name=csrf-token]').getAttribute('content');
        var CAN_MANAGE = @json($canManage);
        var SITE_SCOPE = @json($siteScope);
        var TODAY = @json($today);
        // 한글을 \uXXXX 로 바꾸지 않는다 — 본문이 작아지고, 화면에 실려 나간 내용을
        // 사람이 그대로 읽을 수 있다.
        var BATCHES = @json($batches, JSON_UNESCAPED_UNICODE);
        var STATUS = { pending: '확인 대기', applied: '반영됨', dismissed: '무시함', needs_input: '확인 필요' };

        var photos = [];       // 아직 안 올린 사진 파일
        var showAll = false;   // 지난 기록까지 펼쳤는가

        function api(method, args) {
            return fetch(API + '/' + method, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                // 자기 현장으로 올린다. 'ALL' 로 보내면 기록이 현장 없이 저장돼
                // 그날 그 공종의 보고에 묶이지 않는다.
                body: JSON.stringify({ args: args || [], siteId: SITE_SCOPE })
            }).then(function (r) { return r.json(); });
        }

        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        function el(id) { return document.getElementById(id); }
        function say(text, kind) {
            var m = el('msg');
            m.className = 'msg' + (kind ? ' ' + kind : '');
            m.innerHTML = text;
        }
        function showScreen(which) {
            el('compose').hidden = which !== 'list';
            el('list-screen').hidden = which !== 'list';
            var sb = document.querySelector('.submit-box');
            if (sb) sb.hidden = which !== 'list';
            el('detail-screen').hidden = which !== 'detail';
            window.scrollTo(0, 0);
        }

        // 보낼 것이 생기면 「보내기」가 나타난다 — 누를 것이 없는 버튼은 화면에 두지 않는다.
        function syncSend() {
            var has = el('raw').value.trim() !== '' || photos.length > 0;
            el('send-row').hidden = !has;
        }
        el('raw').addEventListener('input', syncSend);

        // ── ① 말하기 ────────────────────────────────────────────────
        // 녹음 → 서버가 글자로 옮김 → 화면에 띄워 반장이 고침 → 보내기.
        // 옮긴 글을 그냥 보내지 않는 것이 핵심이다. 현장은 시끄럽고, 잘못 들린
        // 한 단어가 그대로 공정표 숫자가 되면 안 된다.
        var recorder = null, chunks = [], recStart = 0, recTimer = null, recMime = '';

        function pickMime() {
            if (!window.MediaRecorder) return null;
            var cands = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/aac', 'audio/ogg'];
            for (var i = 0; i < cands.length; i++) {
                try { if (MediaRecorder.isTypeSupported(cands[i])) return cands[i]; } catch (e) {}
            }
            return '';
        }

        function micFace(icon, label, hint, recording) {
            el('mic-icon').textContent = icon;
            el('mic-label').textContent = label;
            el('mic-hint').textContent = hint;
            el('mic').classList.toggle('rec', !!recording);
        }

        function stopTracks(stream) {
            try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
        }

        el('mic').addEventListener('click', function () {
            if (recorder && recorder.state === 'recording') { recorder.stop(); return; }

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || pickMime() === null) {
                openWriter();
                say('이 폰에서는 녹음이 안 됩니다. 글로 적어 주세요.', 'warn');
                return;
            }

            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                recMime = pickMime() || '';
                try {
                    recorder = recMime ? new MediaRecorder(stream, { mimeType: recMime }) : new MediaRecorder(stream);
                } catch (e) {
                    recorder = new MediaRecorder(stream);
                }
                recMime = recorder.mimeType || recMime || 'audio/webm';
                chunks = [];
                recorder.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
                recorder.onstop = function () {
                    clearInterval(recTimer);
                    stopTracks(stream);
                    micFace('⏳', '옮기는 중…', '조금만 기다려 주세요', false);
                    el('mic').disabled = true;
                    sendVoice(new Blob(chunks, { type: recMime }));
                };
                recorder.start();
                recStart = Date.now();
                micFace('⏹', '다 말했으면 누르세요', '● 듣는 중 0:00', true);
                recTimer = setInterval(function () {
                    var s = Math.round((Date.now() - recStart) / 1000);
                    el('mic-hint').textContent = '● 듣는 중 ' + Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
                    // 너무 길면 스스로 끊는다 — 3분이면 현장 보고 한 건은 충분하다.
                    if (s >= 180 && recorder && recorder.state === 'recording') { recorder.stop(); }
                }, 500);
                say('말씀하세요. 다 하시면 버튼을 한 번 더 누르면 됩니다.');
            }).catch(function () {
                openWriter();
                say('마이크를 쓸 수 없습니다. 폰 설정에서 마이크를 켜 주시거나, 글로 적어 주세요.', 'warn');
            });
        });

        function resetMic() {
            recorder = null;
            el('mic').disabled = false;
            micFace('🎤', '눌러서 말하기', '오늘 한 일을 말씀하세요. 글자로 바꿔 드립니다.', false);
        }

        function sendVoice(blob) {
            var fd = new FormData();
            fd.append('audio', blob, 'note');
            fd.append('mime', blob.type || recMime);
            fetch(@json(route('ops.voice')), {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: fd
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    resetMic();
                    if (!d || !d.success) {
                        openWriter();
                        say((d && d.error) || '말씀을 옮기지 못했습니다. 글로 적어 주세요.', 'warn');
                        return;
                    }
                    openWriter();
                    el('heard').hidden = false;
                    var box = el('raw');
                    box.value = (box.value ? box.value.replace(/\s+$/, '') + '\n' : '') + d.text;
                    syncSend();
                    say('옮겼습니다. 틀린 곳을 고치고 <b>보내기</b>를 눌러 주세요.', 'ok');
                    box.focus();
                })
                .catch(function () {
                    resetMic();
                    openWriter();
                    say('연결이 끊겼습니다. 글로 적어 주세요.', 'warn');
                });
        }

        // ── ② 글로 쓰기 ────────────────────────────────────────────
        function openWriter() {
            el('writer').hidden = false;
            el('write-toggle').classList.add('on');
            syncSend();
        }
        el('write-toggle').addEventListener('click', function () {
            var hid = el('writer').hidden;
            if (hid) { openWriter(); el('raw').focus(); }
            else if (el('raw').value.trim() === '') {
                el('writer').hidden = true;
                el('heard').hidden = true;
                this.classList.remove('on');
                syncSend();
            }
        });

        // ── ③ 사진 — 증거로만 붙는다 ───────────────────────────────
        el('photo-btn').addEventListener('click', function () { el('photos').click(); });
        el('photos').addEventListener('change', function () {
            Array.prototype.slice.call(this.files || [])
                .filter(function (f) { return /^image\//.test(f.type); })
                .forEach(function (f) { if (photos.length < 20) photos.push(f); });
            this.value = '';
            drawShots();
            syncSend();
            if (el('raw').value.trim() === '') {
                say('사진 ' + photos.length + '장 준비됨. <b>무슨 사진인지 한마디만</b> 말하거나 적어 주세요 — 사진만으로는 시스템이 맥락을 알 수 없습니다.', 'warn');
            }
        });

        function drawShots() {
            var host = el('shots');
            host.innerHTML = '';
            photos.forEach(function (f, i) {
                var wrap = document.createElement('div');
                wrap.className = 'shot';
                var img = document.createElement('img');
                img.src = URL.createObjectURL(f);
                img.onload = function () { URL.revokeObjectURL(img.src); };
                var x = document.createElement('button');
                x.type = 'button';
                x.textContent = '×';
                x.setAttribute('aria-label', '사진 빼기');
                x.addEventListener('click', function () { photos.splice(i, 1); drawShots(); syncSend(); });
                wrap.appendChild(img); wrap.appendChild(x);
                host.appendChild(wrap);
            });
        }

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

        // ── 보내기 ─────────────────────────────────────────────────
        el('send').addEventListener('click', function () {
            var raw = el('raw').value.trim();
            var btn = this;
            if (!raw && !photos.length) { say('말하거나 적어 주세요.', 'warn'); return; }

            btn.disabled = true;
            btn.textContent = photos.length ? '사진 올리는 중…' : '보내는 중…';

            uploadPhotos(function (n, total) { say('사진 올리는 중 ' + n + '/' + total + '…'); })
                .then(function (tokens) {
                    btn.textContent = raw ? '정리하는 중…' : '올리는 중…';
                    return api('api_opsIngest', [raw, tokens]);
                })
                .then(function (d) {
                    if (!d || d.success === false) { throw new Error((d && d.error) || '보내지 못했습니다.'); }
                    el('raw').value = '';
                    el('heard').hidden = true;
                    photos = [];
                    drawShots();
                    syncSend();
                    return awaitJob(d.batchId);
                })
                .then(function () { btn.disabled = false; btn.textContent = '보내기'; reload(); })
                .catch(function (e) {
                    btn.disabled = false; btn.textContent = '보내기';
                    say((e && e.message) || '연결에 실패했습니다. 다시 눌러 주세요.', 'warn');
                });
        });

        // 판독이 끝날 때까지 상태만 짧게 되묻는다 — 요청 하나가 수십 ms 라 시간 제한에 걸리지 않는다.
        function awaitJob(batchId) {
            var started = Date.now();
            var delay = 1200;
            return new Promise(function (resolve) {
                (function tick() {
                    setTimeout(function () {
                        delay = Math.min(delay * 1.25, 5000);
                        var elapsed = Math.round((Date.now() - started) / 1000);
                        api('api_getOpsJob', [batchId]).then(function (j) {
                            if (!j || !j.success || j.status === 'analyzing') {
                                say('정리하는 중… ' + elapsed + '초');
                                return tick();
                            }
                            if (j.status === 'failed') { say('정리하지 못했습니다: ' + esc(j.error || ''), 'warn'); return resolve(); }
                            if (j.photoOnly) {
                                // 사진만 올렸다. 사진은 보고에 붙었지만 무슨 일이 있었는지는
                                // 아무도 말해 주지 않았다 — 그 사실을 그대로 알린다.
                                say('사진을 오늘 보고에 붙였습니다.' +
                                    (j.evidenceFiled ? ' (증빙 ' + j.evidenceFiled + '건은 문서함에도 보관)' : '') +
                                    ' <b>무슨 일이었는지 한마디만</b> 남겨 주시면 공정표까지 올라갑니다.', 'warn');
                                return resolve();
                            }
                            say('올렸습니다 — 업무 ' + (j.actionable || 0) + '건 정리됨', 'ok');
                            resolve();
                        }).catch(function () {
                            say('정리하는 중… ' + elapsed + '초 (연결 재시도 중)');
                            tick();
                        });
                    }, delay);
                })();
            });
        }

        // ── 오늘 올린 것 ───────────────────────────────────────────
        function isToday(at) { return String(at || '').slice(0, 10) === TODAY; }

        function render() {
            var todays = BATCHES.filter(function (b) { return isToday(b.at); });
            var older = BATCHES.length - todays.length;
            var rows = showAll ? BATCHES : todays;

            el('count').textContent = todays.length ? '(' + todays.length + ')' : '';

            var host = el('list');
            if (!rows.length) {
                host.innerHTML = '<div class="empty">오늘 올린 것이 없습니다.<br>위에서 말하거나 적어 주세요.</div>';
            } else {
                host.innerHTML = rows.map(function (b) {
                    return '<div class="item" data-id="' + b.id + '"><div class="meta">' +
                        '<span class="time">' + esc(String(b.at || '').slice(11)) + '</span>' +
                        (b.by ? '<span class="who">' + esc(b.by) + '</span>' : '') +
                        (b.actionable ? '<span class="chip work">업무 ' + b.actionable + '건</span>' : '') +
                        (b.imageCount ? '<span class="chip photo">📷 ' + b.imageCount + '</span>' : '') +
                        (b.edited ? '<span class="chip edited">✎ 수정됨</span>' : '') +
                        (b.applied ? '<span class="chip applied">반영 ' + b.applied + '건</span>' : '') +
                        '</div><div class="preview">' + esc(b.preview) + '</div></div>';
                }).join('');
            }

            var more = el('more');
            more.hidden = older <= 0;
            more.textContent = showAll ? '지난 기록 접기' : '지난 기록 ' + older + '건 보기';

            Array.prototype.forEach.call(document.querySelectorAll('.item'), function (node) {
                node.addEventListener('click', function () { openDetail(node.getAttribute('data-id')); });
            });
        }

        el('more').addEventListener('click', function () { showAll = !showAll; render(); });

        function reload() {
            api('api_getOpsBatches', []).then(function (d) {
                BATCHES = (d && d.batches) || [];
                render();
            });
            showScreen('list');
        }

        // ── 제출 ───────────────────────────────────────────────────
        function pollReflection(tries) {
            tries = tries || 0;
            var box = el('mine-reflect');
            if (!box) return;
            if (tries === 0) { box.hidden = false; box.textContent = '↳ ERP 에 반영하는 중…'; }
            if (tries > 15) { box.textContent = '↳ 반영에 시간이 걸리고 있습니다. 잠시 뒤 새로고침해 주세요.'; return; }
            setTimeout(function () {
                fetch(@json(route('ops.trade-report.status')), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.success && d.reflected) { box.textContent = '↳ ' + (d.note || 'ERP 반영을 마쳤습니다.'); return; }
                        pollReflection(tries + 1);
                    })
                    .catch(function () { box.hidden = true; });
            }, 2000);
        }

        var submitBtn = el('submit-report');
        if (submitBtn) {
            submitBtn.addEventListener('click', function () {
                submitBtn.disabled = true;
                fetch(@json(route('ops.trade-report.submit')), {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || d.success === false) {
                            el('state').className = 'state todo';
                            el('state').textContent = (d && d.error) || '제출하지 못했습니다.';
                            submitBtn.disabled = false;
                            return;
                        }
                        submitBtn.textContent = '✓ 제출 완료';
                        el('state').className = 'state done';
                        el('state').textContent = '✅ 오늘 보고 제출을 마쳤습니다';
                        el('state-sub').textContent = '더 올리시면 그대로 오늘 보고에 들어갑니다.';
                        if (d.reflecting) { pollReflection(); }
                    })
                    .catch(function () {
                        el('state').textContent = '연결에 실패했습니다. 잠시 뒤 다시 눌러 주세요.';
                        submitBtn.disabled = false;
                    });
            });
        }

        // ── 원문 상세 (관리자는 여기서 고치거나 지운다) ─────────────
        function openDetail(id) {
            api('api_getOpsBatch', [Number(id)]).then(function (d) {
                if (!d || d.success === false) { alert('원문을 불러오지 못했습니다.'); return; }
                var host = el('detail-screen');
                var manage = CAN_MANAGE
                    ? '<div class="row"><button class="btn" id="edit-btn">✎ 수정</button><button class="btn danger" id="del-btn">🗑 삭제</button></div>'
                    : '';
                host.innerHTML =
                    '<div class="row" style="margin:0 0 12px"><button class="btn" id="back-btn">← 돌아가기</button></div>' +
                    '<div class="meta"><span class="time">' + esc(d.at) + '</span>' +
                    (d.by ? '<span class="who">' + esc(d.by) + '</span>' : '') +
                    (d.imageCount ? '<span class="chip photo">📷 ' + d.imageCount + '</span>' : '') + '</div>' +
                    (d.editedAt ? '<div class="edited-note">✎ ' + esc(d.editedAt) + (d.editedBy ? ' · ' + esc(d.editedBy) : '') + ' 수정됨</div>' : '') +
                    (d.appliedCount ? '<div class="warnbox">이 기록에서 <b>' + d.appliedCount + '건</b>이 이미 공정표에 반영됐습니다. 지우려면 PC 상황실에서 먼저 되돌리세요.</div>' : '') +
                    '<pre class="raw">' + esc(d.raw || '(사진만 첨부 — 무슨 일이었는지는 적히지 않았습니다)') + '</pre>' +
                    (d.originalText ? '<details><summary>최초 원문 (수정 전) 보기</summary><pre class="raw">' + esc(d.originalText) + '</pre></details>' : '') +
                    manage +
                    '<div style="margin-top:14px"><div class="sec-title" style="margin-top:0">정리된 항목 (' + (d.items || []).length + '건)</div>' +
                    ((d.items || []).length
                        ? d.items.map(function (it) {
                            return '<div class="parsed"><b>' + esc(it.targetName || it.targetCode || it.categoryLabel || it.category) + '</b> · ' + esc(STATUS[it.status] || it.status) +
                                (it.summary ? '<br><span style="color:#6b7280">' + esc(it.summary) + '</span>' : '') + '</div>';
                        }).join('')
                        : '<div class="empty">정리된 항목이 없습니다.</div>') +
                    '</div>';
                showScreen('detail');

                el('back-btn').addEventListener('click', function () { showScreen('list'); });
                var eb = el('edit-btn');
                if (eb) eb.addEventListener('click', function () { openEdit(d); });
                var db = el('del-btn');
                if (db) db.addEventListener('click', function () { removeBatch(d.id); });
            });
        }

        function openEdit(d) {
            var host = el('detail-screen');
            host.innerHTML =
                '<div class="row" style="margin:0 0 12px"><button class="btn" id="cancel-btn">← 취소</button></div>' +
                '<div class="sec-title" style="margin-top:0">원문 수정</div>' +
                '<textarea id="edit-text" style="min-height:220px"></textarea>' +
                '<p class="msg">고치기 전 내용은 <b>최초 원문</b>으로 보관되고, 누가 언제 고쳤는지 남습니다. 이미 반영된 공정표 값은 바뀌지 않습니다.</p>' +
                '<div class="row"><button class="btn primary full" id="save-btn">저장</button></div>';
            el('edit-text').value = d.raw || '';
            el('cancel-btn').addEventListener('click', function () { openDetail(d.id); });
            el('save-btn').addEventListener('click', function () {
                var btn = this;
                btn.disabled = true; btn.textContent = '저장 중…';
                api('api_updateOpsBatch', [d.id, el('edit-text').value]).then(function (r) {
                    btn.disabled = false; btn.textContent = '저장';
                    if (!r || !r.success) { alert((r && r.error) || '수정에 실패했습니다.'); return; }
                    openDetail(d.id);
                });
            });
        }

        function removeBatch(id) {
            if (!confirm('이 기록을 지울까요?\n올린 원문과 그때 정리된 항목이 함께 지워집니다. (되돌릴 수 없습니다)')) return;
            api('api_deleteOpsBatch', [id]).then(function (r) {
                if (!r || !r.success) { alert((r && r.error) || '삭제에 실패했습니다.'); return; }
                reload();
            });
        }

        render();
    </script>
</body>
</html>

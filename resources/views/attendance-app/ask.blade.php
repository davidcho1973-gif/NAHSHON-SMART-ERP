<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>물어보기</title>
    <style>
        /* 앱의 다른 화면과 같은 규격 — 문이 여럿이어도 한 앱으로 보여야 한다. */
        :root {
            color-scheme: light;
            --paper: #F2F3F5; --card: #FFFFFF;
            --ink: #191919; --ink-2: #767676; --ink-3: #B0B8C1; --rule: #EDEEF0;
            --ok: #1E8E3E; --ok-bg: #E8F5EA;
            --warn: #B26A00; --warn-bg: #FFF4E0;
            --bad: #D94C4C; --bad-bg: #FDECEC;
            --info: #3E6BE0; --info-bg: #ECF1FE;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        [hidden] { display: none !important; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: var(--paper); color: var(--ink);
            font-family: "Pretendard Variable", Pretendard, -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', 'Malgun Gothic', 'Noto Sans KR', sans-serif;
            font-size: 15px; line-height: 1.55; -webkit-font-smoothing: antialiased;
        }
        .app { max-width: 520px; margin: 0 auto; min-height: 100dvh; background: var(--paper); }
        header { background: var(--card); padding: 16px 18px 15px; border-bottom: 1px solid var(--rule); }
        .back { display: inline-block; color: var(--info); font-size: 13px; font-weight: 700; text-decoration: none; margin-bottom: 7px; }
        h1 { margin: 0; font-size: 21px; font-weight: 800; line-height: 1.25; }
        .sub { margin: 5px 0 0; color: var(--ink-2); font-size: 13px; line-height: 1.5; }
        main { padding: 14px 16px 40px; }

        .askbox { background: var(--card); border-radius: 16px; padding: 12px 12px 10px; }
        textarea {
            width: 100%; border: 0; background: transparent; font: inherit; font-size: 16px; color: var(--ink);
            resize: none; min-height: 72px; outline: none; line-height: 1.5; padding: 4px 2px;
        }
        textarea::placeholder { color: var(--ink-3); }
        .askrow { display: flex; gap: 8px; align-items: center; margin-top: 6px; }
        .mic {
            flex: none; width: 48px; height: 48px; border-radius: 50%; border: 0; background: var(--info-bg);
            color: var(--info); font-size: 22px; cursor: pointer; font-family: inherit; line-height: 1;
        }
        .mic.rec { background: var(--bad-bg); color: var(--bad); }
        .mic:disabled { opacity: .5; }
        .go {
            flex: 1; border: 0; border-radius: 14px; padding: 14px; font-size: 16px; font-weight: 800;
            cursor: pointer; font-family: inherit; background: var(--info); color: #fff;
        }
        .go:disabled { opacity: .5; }
        .hint { font-size: 12.5px; color: var(--ink-2); margin: 8px 2px 0; line-height: 1.5; }
        .hint.warn { color: var(--warn); font-weight: 700; }
        .hint.bad { color: var(--bad); font-weight: 700; }

        .chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
        .chips button {
            border: 1px solid var(--rule); background: var(--card); color: var(--ink-2); border-radius: 999px;
            padding: 6px 11px; font-size: 12.5px; font-family: inherit; cursor: pointer;
        }

        .answer { background: var(--card); border-radius: 16px; padding: 14px 15px; margin-top: 14px; border-left: 4px solid var(--info); }
        .answer.nf { border-left-color: var(--warn); }
        .answer .q { font-size: 12.5px; color: var(--ink-2); margin-bottom: 6px; }
        .answer .a { font-size: 15px; line-height: 1.65; white-space: pre-line; }
        .answer .a.thinking { color: var(--ink-2); }
        .src { margin-top: 10px; border-top: 1px solid var(--rule); padding-top: 8px; }
        .src-h { font-size: 11.5px; font-weight: 800; color: var(--ink-2); margin-bottom: 5px; }
        .src a, .src span.lock {
            display: flex; align-items: center; gap: 8px; text-decoration: none; color: var(--ink);
            background: var(--paper); border-radius: 10px; padding: 8px 10px; margin-bottom: 5px; font-size: 13px;
        }
        .src .t { flex: 1; min-width: 0; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .src .m { font-size: 11px; color: var(--ink-2); flex: none; }
        .src span.lock { color: var(--ink-2); }
        .denied { margin-top: 8px; font-size: 12px; color: var(--warn); }
        .upload { display: inline-block; margin-top: 8px; color: var(--info); font-weight: 700; font-size: 13px; text-decoration: none; }

        .sec-h { font-size: 13px; font-weight: 800; color: var(--ink-2); margin: 24px 0 8px; }
        .row { background: var(--card); border-radius: 12px; padding: 11px 14px; margin-bottom: 8px; cursor: pointer; }
        .row .qq { font-size: 13.5px; font-weight: 700; line-height: 1.4; }
        .row .meta { font-size: 11.5px; color: var(--ink-2); margin-top: 3px; display: flex; gap: 7px; align-items: center; }
        .chip { border-radius: 999px; padding: 2px 8px; font-size: 10.5px; font-weight: 800; }
        .chip.ok { background: var(--ok-bg); color: var(--ok); }
        .chip.nf { background: var(--warn-bg); color: var(--warn); }
        .row .aa { font-size: 13px; color: var(--ink-2); margin-top: 6px; white-space: pre-line; line-height: 1.55; }
        .empty { color: var(--ink-2); font-size: 13px; text-align: center; padding: 18px 0; line-height: 1.6; }
        .off { background: var(--warn-bg); color: var(--warn); border-radius: 12px; padding: 12px 14px; font-size: 13px; font-weight: 700; }
    </style>
</head>
<body>
    @include('partials.erp-home')
    <div class="app">
        <header>
            <a class="back" href="{{ route('attendance-app.index') }}">← 홈</a>
            <h1>물어보기</h1>
            <p class="sub">{{ $siteName ?: '현장' }} · 작업자도 공정·시공·수량·도면·시방을 물어볼 수 있습니다. 답은 나만 봅니다. 회계·급여·단가·견적·계약금액은 재무 권한에 따라 제한됩니다.</p>
        </header>

        <main>
            @if (! $available)
                <div class="off">AI 도우미가 이 서버에 켜져 있지 않습니다. 관리자에게 알려 주세요.</div>
            @else
                <div class="askbox">
                    <textarea id="q" rows="3" placeholder="예) 주방 배기 덕트 두께가 얼마야?&#10;예) 에폭시 바닥 양생 며칠 걸려?&#10;예) 후드 샵드로잉 언제 냈어?"></textarea>
                    <div class="askrow">
                        @if ($voiceReady)
                            <button class="mic" id="mic" type="button" aria-label="말로 묻기">🎤</button>
                        @endif
                        <button class="go" id="go" type="button">찾아 줘</button>
                    </div>
                </div>
                <p class="hint" id="hint">말하거나 적으세요. 등록된 문서에 없으면 없다고 말합니다.</p>

                <div class="chips" id="chips">
                    <button type="button">주방 바닥 양생 며칠이야?</button>
                    <button type="button">후드 샵드로잉 상태 알려줘</button>
                    <button type="button">검사 일정 뭐 잡혀 있어?</button>
                    <button type="button">배관 진행률 어디까지야?</button>
                </div>

                <div id="answer"></div>
            @endif

            <div class="sec-h">최근 물어본 것</div>
            <div id="recent"></div>
        </main>
    </div>

    <script>
        var CSRF = document.querySelector('meta[name=csrf-token]').getAttribute('content');
        var RECENT = @json($recent, JSON_UNESCAPED_UNICODE);
        var UPLOAD_URL = @json(route('attendance-app.docs'));

        function el(id) { return document.getElementById(id); }
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        function say(t, kind) { var h = el('hint'); if (!h) return; h.className = 'hint' + (kind ? ' ' + kind : ''); h.textContent = t; }

        function sourcesHtml(list) {
            if (!list || !list.length) return '';
            return '<div class="src"><div class="src-h">출처</div>' + list.map(function (s) {
                var meta = [s.type, s.revision, s.date].filter(Boolean).join(' · ');
                if (s.can_open && s.url) {
                    return '<a href="' + esc(s.url) + '" target="_blank" rel="noopener"><span>📄</span><span class="t">' + esc(s.title) + '</span><span class="m">' + esc(meta) + ' ›</span></a>';
                }
                return '<span class="lock"><span>🔒</span><span class="t">' + esc(s.title) + '</span><span class="m">열람 권한 없음</span></span>';
            }).join('') + '</div>';
        }

        function answerHtml(r) {
            var h = '<div class="answer' + (r.found ? '' : ' nf') + '">' +
                '<div class="q">' + esc(r.question) + (r.askedAt ? ' · ' + esc(r.askedAt) : '') + '</div>' +
                '<div class="a">' + esc(r.answer) + '</div>' +
                sourcesHtml(r.sources);
            if (r.denied && r.denied.length) {
                h += '<div class="denied">권한이 없어 보지 못한 것: ' + r.denied.map(esc).join(', ') + '</div>';
            }
            if (!r.found) {
                // 답이 없는 이유의 절반은 문서가 아직 안 올라간 것이다 — 올리는 문을 바로 옆에 둔다.
                h += '<a class="upload" href="' + esc(UPLOAD_URL) + '">📄 관련 문서 올리기 →</a>';
            }
            return h + '</div>';
        }

        function ask(question) {
            question = (question || '').trim();
            if (!question) { say('무엇을 찾을지 적어 주세요.', 'warn'); return; }
            var go = el('go');
            go.disabled = true;
            el('answer').innerHTML = '<div class="answer"><div class="q">' + esc(question) + '</div><div class="a thinking">문서를 뒤지는 중… 10초쯤 걸립니다.</div></div>';
            say('찾는 중입니다.');

            fetch(@json(route('ask.question')), {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ question: question })
            })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (res) {
                    go.disabled = false;
                    var d = res.d || {};
                    if (!d.success) {
                        el('answer').innerHTML = '';
                        say(d.error || (d.errors && Object.values(d.errors)[0][0]) || '답을 만들지 못했습니다.', 'bad');
                        return;
                    }
                    el('answer').innerHTML = answerHtml(d);
                    RECENT.unshift(d);
                    drawRecent();
                    say(d.found ? '등록된 문서에서 찾았습니다. 출처를 눌러 원문을 여세요.' : '등록된 문서에는 없었습니다. 문서를 올리면 다음엔 답할 수 있습니다.', d.found ? '' : 'warn');
                })
                .catch(function () {
                    go.disabled = false;
                    el('answer').innerHTML = '';
                    say('연결이 끊겼습니다. 다시 눌러 주세요.', 'bad');
                });
        }

        if (el('go')) {
            el('go').addEventListener('click', function () { ask(el('q').value); });
            el('q').addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(el('q').value); }
            });
            Array.prototype.forEach.call(el('chips').querySelectorAll('button'), function (b) {
                b.addEventListener('click', function () { el('q').value = b.textContent; ask(b.textContent); });
            });
        }

        function drawRecent() {
            var host = el('recent');
            if (!RECENT.length) {
                host.innerHTML = '<div class="empty">아직 물어본 것이 없습니다.</div>';
                return;
            }
            host.innerHTML = RECENT.slice(0, 10).map(function (r, i) {
                return '<div class="row" data-i="' + i + '"><div class="qq">' + esc(r.question) + '</div>' +
                    '<div class="meta">' + (r.found ? '<span class="chip ok">찾음</span>' : '<span class="chip nf">문서에 없음</span>') +
                    (r.askedAt ? '<span>' + esc(r.askedAt) + '</span>' : '') +
                    (r.sources && r.sources.length ? '<span>출처 ' + r.sources.length + '</span>' : '') + '</div>' +
                    '<div class="aa" hidden>' + esc(r.answer) + '</div></div>';
            }).join('');
            Array.prototype.forEach.call(host.querySelectorAll('.row'), function (row) {
                row.addEventListener('click', function () {
                    var r = RECENT[Number(row.getAttribute('data-i'))];
                    if (!r) return;
                    if (el('answer')) { el('answer').innerHTML = answerHtml(r); window.scrollTo({ top: 0, behavior: 'smooth' }); }
                    else { var a = row.querySelector('.aa'); a.hidden = !a.hidden; }
                });
            });
        }
        drawRecent();

        // ── 말로 묻기. 오늘 보고 화면과 같은 녹음기, 같은 받아쓰기 — 다만 여기서는
        // 보고 문장으로 다듬은 것이 아니라 «들은 그대로(heard)» 를 쓴다. 질문은 다듬으면 뜻이 바뀐다.
        var recorder = null, chunks = [], recMime = '', recTimer = null, recStart = 0;
        function pickMime() {
            if (!window.MediaRecorder) return null;
            var cands = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/aac', 'audio/ogg'];
            for (var i = 0; i < cands.length; i++) { try { if (MediaRecorder.isTypeSupported(cands[i])) return cands[i]; } catch (e) {} }
            return '';
        }
        function stopTracks(stream) { try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} }

        var micBtn = el('mic');
        if (micBtn) micBtn.addEventListener('click', function () {
            if (recorder && recorder.state === 'recording') { recorder.stop(); return; }
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || pickMime() === null) {
                say('이 폰에서는 녹음이 안 됩니다. 글로 적어 주세요.', 'warn');
                return;
            }
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                recMime = pickMime() || '';
                try { recorder = recMime ? new MediaRecorder(stream, { mimeType: recMime }) : new MediaRecorder(stream); }
                catch (e) { recorder = new MediaRecorder(stream); }
                recMime = recorder.mimeType || recMime || 'audio/webm';
                chunks = [];
                recorder.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
                recorder.onstop = function () {
                    clearInterval(recTimer);
                    stopTracks(stream);
                    micBtn.classList.remove('rec');
                    micBtn.disabled = true;
                    micBtn.textContent = '⏳';
                    var fd = new FormData();
                    var blob = new Blob(chunks, { type: recMime });
                    fd.append('audio', blob, 'question');
                    fd.append('mime', blob.type || recMime);
                    fetch(@json(route('ops.voice')), {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            micBtn.disabled = false; micBtn.textContent = '🎤';
                            if (!d || !d.success) { say((d && d.error) || '말씀을 옮기지 못했습니다. 글로 적어 주세요.', 'warn'); return; }
                            var text = (d.heard || d.text || '').trim();
                            el('q').value = text;
                            if (text) ask(text);
                        })
                        .catch(function () { micBtn.disabled = false; micBtn.textContent = '🎤'; say('연결이 끊겼습니다. 다시 말씀해 주세요.', 'bad'); });
                };
                recorder.start();
                recStart = Date.now();
                micBtn.classList.add('rec');
                micBtn.textContent = '⏹';
                say('말씀하세요. 다 하시면 버튼을 한 번 더 누르세요.');
                recTimer = setInterval(function () {
                    var s = Math.round((Date.now() - recStart) / 1000);
                    say('● 듣는 중 ' + Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2) + ' — 다 하시면 버튼을 다시 누르세요.');
                    if (s >= 60 && recorder && recorder.state === 'recording') { recorder.stop(); }
                }, 500);
            }).catch(function () {
                say('마이크를 쓸 수 없습니다. 폰 설정에서 마이크를 켜 주시거나, 글로 적어 주세요.', 'warn');
            });
        });
    </script>
</body>
</html>

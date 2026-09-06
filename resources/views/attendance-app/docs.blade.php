<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('문서 올리기') }}</title>
    <style>
        /* 앱의 다른 화면과 같은 규격을 쓴다 — 문이 여럿이어도 한 앱으로 보여야 한다. */
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

        .pick {
            width: 100%; border: 0; border-radius: 16px; padding: 24px 18px; background: var(--card);
            cursor: pointer; text-align: center; font-family: inherit; color: var(--ink);
            border: 1.5px dashed var(--ink-3);
        }
        .pick .i { display: block; font-size: 34px; line-height: 1; margin-bottom: 8px; }
        .pick b { display: block; font-size: 17px; font-weight: 800; }
        .pick span { display: block; font-size: 12.5px; color: var(--ink-2); margin-top: 4px; }

        .queue { margin-top: 12px; }
        .file {
            background: var(--card); border-radius: 12px; padding: 12px 14px; margin-bottom: 8px;
            display: flex; align-items: center; gap: 10px;
        }
        .file .nm { flex: 1; min-width: 0; font-size: 13.5px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file .sz { font-size: 11.5px; color: var(--ink-2); flex: none; }
        .file button {
            border: 0; background: var(--paper); color: var(--ink-2); width: 26px; height: 26px;
            border-radius: 50%; font-size: 15px; line-height: 1; cursor: pointer; flex: none; font-family: inherit;
        }

        .btn {
            width: 100%; border: 0; border-radius: 14px; padding: 16px; font-size: 16px; font-weight: 800;
            cursor: pointer; font-family: inherit; background: var(--info); color: #fff; margin-top: 12px;
        }
        .btn:disabled { opacity: .5; }

        .msg { font-size: 13px; color: var(--ink-2); margin-top: 12px; line-height: 1.55; }
        .msg.ok { color: var(--ok); font-weight: 700; }
        .msg.bad { color: var(--bad); font-weight: 700; }

        .sec-h { font-size: 13px; font-weight: 800; color: var(--ink-2); margin: 24px 0 8px; }
        .row {
            background: var(--card); border-radius: 12px; padding: 12px 14px; margin-bottom: 8px;
        }
        .row .nm { font-size: 13.5px; font-weight: 700; line-height: 1.4; }
        .row .meta { font-size: 11.5px; color: var(--ink-2); margin-top: 3px; display: flex; gap: 7px; flex-wrap: wrap; align-items: center; }
        .chip { border-radius: 999px; padding: 2px 8px; font-size: 10.5px; font-weight: 800; }
        .chip.ok { background: var(--ok-bg); color: var(--ok); }
        .chip.wait { background: var(--warn-bg); color: var(--warn); }
        .chip.bad { background: var(--bad-bg); color: var(--bad); }
        .empty { color: var(--ink-2); font-size: 13px; text-align: center; padding: 18px 0; line-height: 1.6; }
    </style>
</head>
<body>
    @include('partials.erp-home')
    <div class="app">
        <header>
            <a class="back" href="{{ route('attendance-app.index') }}">{{ __('← 홈') }}</a>
            <h1>{{ __('문서 올리기') }}</h1>
            <p class="sub">{{ $siteName ?: __('현장') }} · {{ __('도면 · 계약서 · 시방서를 올리면 ERP 문서함에 자동으로 분류돼 들어갑니다.') }}</p>
        </header>

        <main>
            <button class="pick" id="pick" type="button">
                <span class="i">📄</span>
                <b>{{ __('파일 고르기') }}</b>
                <span>{{ __('PDF · 사진 · 워드 · 엑셀 · 캐드 (여러 개 가능)') }}</span>
            </button>
            <input type="file" id="files" multiple hidden
                   accept=".pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.pptx,.dwg,.dxf,.doc,.xls,.ppt,.hwp">

            <div class="queue" id="queue"></div>
            <button class="btn" id="send" type="button" hidden>{{ __('올리기') }}</button>

            <p class="msg" id="msg">{{ __('사진으로 찍은 서류도 됩니다. 올리면 AI 가 종류를 보고 폴더를 정합니다.') }}</p>

            <div class="sec-h">{{ __('최근에 올린 것') }}</div>
            <div id="recent"></div>
        </main>
    </div>

    <script>
    // 화면 안의 글도 서버와 같은 사전을 읽는다. 블레이드는 __(), 여기서는 t().
    // 사전이 두 벌이면 한쪽만 번역되는 사고가 난다.
    const TR = @json(\App\Support\AppLocale::dictionary());
    function t(s) { return (TR && TR[s]) || s; }

        var CSRF = document.querySelector('meta[name=csrf-token]').getAttribute('content');
        var SITE_ID = @json($user?->employee?->site_id);
        var RECENT = @json($recent, JSON_UNESCAPED_UNICODE);
        var queue = [];

        function el(id) { return document.getElementById(id); }
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        function say(t, kind) { var m = el('msg'); m.className = 'msg' + (kind ? ' ' + kind : ''); m.textContent = t; }
        function kb(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + 'MB' : Math.max(1, Math.round(n / 1024)) + 'KB'; }

        el('pick').addEventListener('click', function () { el('files').click(); });

        el('files').addEventListener('change', function () {
            Array.prototype.slice.call(this.files || []).forEach(function (f) {
                if (queue.length < 10) queue.push(f);
            });
            this.value = '';
            drawQueue();
        });

        function drawQueue() {
            var host = el('queue');
            host.innerHTML = '';
            queue.forEach(function (f, i) {
                var row = document.createElement('div');
                row.className = 'file';
                row.innerHTML = '<span class="nm">' + esc(f.name) + '</span><span class="sz">' + kb(f.size) + '</span>';
                var x = document.createElement('button');
                x.type = 'button';
                x.textContent = '×';
                x.setAttribute('aria-label', t('빼기'));
                x.addEventListener('click', function () { queue.splice(i, 1); drawQueue(); });
                row.appendChild(x);
                host.appendChild(row);
            });
            el('send').hidden = queue.length === 0;
            el('send').textContent = queue.length > 1 ? queue.length + t('개 올리기') : t('올리기');
        }

        // 한 번에 한 개씩 올린다 — 요청 하나가 작아야 현장 네트워크에서 끊기지 않고,
        // 몇 개까지 갔는지 사람에게 보여 줄 수 있다.
        el('send').addEventListener('click', function () {
            var btn = this;
            var total = queue.length;
            var done = 0;
            var failed = [];
            btn.disabled = true;

            queue.reduce(function (chain, f) {
                return chain.then(function () {
                    say(t('올리는 중 ') + (done + 1) + '/' + total + ' — ' + f.name);
                    var fd = new FormData();
                    fd.append('file', f);
                    if (SITE_ID) fd.append('site_id', SITE_ID);
                    return fetch(@json(route('docs.upload')), {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                        body: fd
                    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                      .then(function (res) {
                          if (!res.d || res.d.success === false) { failed.push(f.name + ' — ' + ((res.d && res.d.error) || t('실패'))); return; }
                          done++;
                          if (res.d.document) {
                              RECENT.unshift({
                                  id: res.d.document.id,
                                  name: res.d.document.title || f.name,
                                  status: res.d.document.status || res.d.status || 'analyzing',
                                  folder: res.d.document.folder_code ? (res.d.document.folder_name || '') : '',
                                  at: t('방금'),
                              });
                          }
                      })
                      .catch(function () { failed.push(f.name + t(' — 연결 실패')); });
                });
            }, Promise.resolve()).then(function () {
                queue = [];
                drawQueue();
                btn.disabled = false;
                drawRecent();
                if (failed.length) {
                    say(done + t('개 올렸습니다. 안 된 것: ') + failed.join(' / '), 'bad');
                } else {
                    say(done + t('개를 문서함에 올렸습니다. 종류를 읽는 중이라 잠시 뒤 폴더가 정해집니다.'), 'ok');
                }
            });
        });

        function drawRecent() {
            var host = el('recent');
            if (!RECENT.length) {
                host.innerHTML = t('<div class="empty">아직 올린 것이 없습니다.<br>위에서 파일을 골라 주세요.</div>');
                return;
            }
            host.innerHTML = RECENT.slice(0, 15).map(function (r) {
                // analyzing / needs_review / confirmed / failed — 폰에서는 셋으로 줄여 보여 준다.
                // 「확인 대기」와 「보관됨」의 차이는 문서함에서 관리자가 가리는 일이다.
                var chip = r.status === 'analyzing'
                    ? t('<span class="chip wait">읽는 중</span>')
                    : (r.status === 'failed' ? t('<span class="chip bad">읽기 실패</span>') : t('<span class="chip ok">보관됨</span>'));
                return '<div class="row"><div class="nm">' + esc(r.name) + '</div>' +
                    '<div class="meta">' + chip +
                    (r.folder ? '<span>' + esc(r.folder) + '</span>' : '') +
                    (r.at ? '<span>' + esc(r.at) + '</span>' : '') + '</div></div>';
            }).join('');
        }

        drawRecent();
    </script>
</body>
</html>

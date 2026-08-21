{{--
    대화방 — 카카오톡을 쓰는 사람이 배우지 않고 바로 쓸 수 있게.

    현장 작업자 대부분이 한국인이고 카카오톡에 익숙하다. 익숙한 모양을 그대로 쓰면
    "이건 어떻게 쓰는 거냐" 는 질문 자체가 사라진다 — 그래서 색·배치·규칙을 맞춘다:
    하늘빛 배경, 내 말은 오른쪽 노란 말풍선, 남의 말은 왼쪽 흰 말풍선에 이름과 얼굴,
    날짜는 가운데 알약, 시간은 말풍선 옆 작게.

    다만 그대로 베끼지 않은 것이 둘 있다.
      1. <b>지운 글은 자리를 남긴다.</b> 현장 지시는 나중에 분쟁의 증거가 된다.
      2. <b>고친 글에는 (수정됨)이 붙는다.</b> 조용히 바뀌면 다툼이 된다.
--}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $roomLabel ?? $room->name }}</title>
    <style>
        :root {
            color-scheme: light;
            font-family: -apple-system, BlinkMacSystemFont, "Apple SD Gothic Neo", "Malgun Gothic", "Noto Sans KR", sans-serif;
            --kakao-bg: #b2c7d9;      /* 카카오톡 대화 배경 */
            --mine: #fee500;          /* 내 말풍선 */
            --line: #dfe3e8;
        }
        * { -webkit-tap-highlight-color: transparent; }
        body { margin: 0; background: var(--kakao-bg); color: #111827; }
        .app { min-height: 100vh; max-width: 640px; margin: 0 auto; background: var(--kakao-bg); display: flex; flex-direction: column; }

        /* 머리 — 방 이름 + 지금 몇 명이 보고 있는지 */
        header { position: sticky; top: 0; z-index: 20; background: #a1b8cc; padding: 10px 12px; }
        .top { display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; }
        .back { color: #1f2937; font-size: 22px; text-decoration: none; line-height: 1; padding: 2px 6px; }
        h1 { margin: 0; font-size: 17px; font-weight: 800; line-height: 1.2; }
        .sub { margin-top: 2px; font-size: 12px; color: #33475b; display: flex; align-items: center; gap: 5px; }
        .dot { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; display: inline-block; }
        .peo { background: rgba(255,255,255,.55); border: 0; border-radius: 999px; padding: 6px 11px; font-size: 12px; font-weight: 700; cursor: pointer; }

        /* 대화 */
        main { flex: 1; padding: 14px 12px 120px; }
        .day { text-align: center; margin: 16px 0 12px; }
        .day span { background: rgba(0,0,0,.18); color: #fff; font-size: 11px; padding: 4px 12px; border-radius: 999px; }

        .row { display: flex; gap: 8px; margin-bottom: 10px; align-items: flex-end; }
        .row.mine { flex-direction: row-reverse; }
        .face { width: 36px; height: 36px; border-radius: 13px; background: #fff; color: #64748b; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; flex: 0 0 36px; overflow: hidden; }
        .stack { max-width: 74%; display: flex; flex-direction: column; gap: 3px; }
        .who { font-size: 12px; color: #33475b; margin-left: 2px; }
        .bundle { display: flex; gap: 5px; align-items: flex-end; }
        .row.mine .bundle { flex-direction: row-reverse; }
        .bubble { background: #fff; border-radius: 14px; padding: 9px 12px; font-size: 15px; line-height: 1.45; white-space: pre-wrap; word-break: break-word; box-shadow: 0 1px 1px rgba(0,0,0,.06); }
        .row.mine .bubble { background: var(--mine); }
        .bubble.gone { background: rgba(255,255,255,.55); color: #64748b; font-style: italic; }
        .stamp { font-size: 10px; color: #4b5563; white-space: nowrap; padding-bottom: 2px; }
        .stamp .unread { color: #eab308; font-weight: 800; }
        .edited { font-size: 10px; color: #6b7280; }

        /* 공지·AI — 가운데 카드 */
        .notice-card { background: rgba(255,255,255,.92); border-radius: 12px; padding: 11px 13px; margin: 0 auto 12px; max-width: 90%; font-size: 14px; line-height: 1.5; white-space: pre-wrap; border-left: 4px solid #f59e0b; }
        .notice-card.ai { border-left-color: #2563eb; }
        .notice-card b { display: block; font-size: 12px; margin-bottom: 5px; color: #374151; }

        /* 첨부 */
        .files { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .files img { max-width: min(230px, 62vw); border-radius: 12px; display: block; }
        .filecard { display: flex; gap: 8px; align-items: center; background: #fff; border-radius: 12px; padding: 9px 11px; text-decoration: none; color: #111827; }
        .filecard .nm { font-size: 13px; font-weight: 700; word-break: break-all; }
        .filecard .sz { font-size: 11px; color: #6b7280; }

        /* 내 글 손보기 */
        .tools { display: flex; gap: 6px; justify-content: flex-end; }
        .tools button { background: rgba(255,255,255,.7); border: 0; border-radius: 8px; padding: 3px 8px; font-size: 11px; color: #374151; cursor: pointer; }

        /* 입력창 */
        .composer { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: min(640px, 100vw); background: #fff; border-top: 1px solid var(--line); padding: 8px 10px calc(8px + env(safe-area-inset-bottom)); box-sizing: border-box; }
        .cbar { display: grid; grid-template-columns: auto 1fr auto; gap: 8px; align-items: end; }
        .plus { width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--line); background: #f7f8fa; font-size: 22px; color: #4b5563; cursor: pointer; line-height: 1; }
        textarea { width: 100%; box-sizing: border-box; border: 1px solid var(--line); border-radius: 18px; padding: 10px 13px; font: inherit; font-size: 15px; resize: none; max-height: 120px; min-height: 40px; background: #f7f8fa; }
        .send { border: 0; border-radius: 14px; padding: 0 16px; height: 40px; background: var(--mine); color: #3c1e1e; font-weight: 800; cursor: pointer; }
        .send:disabled { background: #e5e7eb; color: #9ca3af; }
        .picked { font-size: 12px; color: #4b5563; padding: 6px 2px 0; display: none; }
        .hint { font-size: 11px; color: #6b7280; padding: 6px 2px 0; }
        input[type=file] { display: none; }
        .readonly { padding: 12px; color: #6b7280; font-size: 13px; text-align: center; }

        /* 참여자 시트 */
        .sheet-back { position: fixed; inset: 0; background: rgba(0,0,0,.35); z-index: 40; display: none; }
        .sheet { position: fixed; left: 50%; bottom: 0; transform: translateX(-50%); width: min(640px, 100vw); background: #fff; border-radius: 18px 18px 0 0; z-index: 41; padding: 16px 16px calc(20px + env(safe-area-inset-bottom)); display: none; max-height: 70vh; overflow: auto; }
        .sheet h2 { margin: 0 0 12px; font-size: 16px; }
        .mem { display: flex; align-items: center; gap: 10px; padding: 9px 2px; border-bottom: 1px solid #f1f3f5; }
        .mem .face { width: 32px; height: 32px; flex: 0 0 32px; background: #eef2f7; border-radius: 11px; }
        .mem .nm { font-size: 14px; font-weight: 700; }
        .mem .st { font-size: 11px; color: #6b7280; margin-left: auto; }
        .mem .st.on { color: #16a34a; font-weight: 800; }
    </style>
</head>
<body>
    <div class="app">
        @php
            $typeLabel = [
                'site_announcement' => '공지방',
                'site_chat' => '현장 채팅방',
                'site_ops' => '현장 상황실',
                'company' => '회사 채팅방',
                'team' => '팀 채팅방',
                'direct' => '1:1 대화',
            ][$room->type] ?? '채팅방';
        @endphp
        <header>
            <div class="top">
                <a class="back" href="{{ route('communication.index') }}" aria-label="목록">‹</a>
                <div>
                    <h1>{{ $roomLabel ?? $room->name }}</h1>
                    <div class="sub">
                        <span id="online-dot" class="dot" hidden></span>
                        <span id="sub-text">{{ $typeLabel }} · {{ $membersCount }}명</span>
                    </div>
                </div>
                <button class="peo" type="button" id="btn-members">참여자</button>
            </div>
        </header>

        <main id="thread"></main>

        <section class="composer">
            @if($canPostTopLevel)
                <form id="composer-form" method="POST" action="{{ route('communication.store', ['room' => $room]) }}" enctype="multipart/form-data">
                    @csrf
                    @if($room->type === 'site_announcement')
                        <input type="text" name="title" maxlength="255" placeholder="공지 제목"
                               style="border:1px solid var(--line);border-radius:12px;padding:9px 12px;width:100%;box-sizing:border-box;margin-bottom:8px;font:inherit">
                    @endif
                    <div class="cbar">
                        <button class="plus" type="button" id="btn-file" aria-label="파일 첨부">＋</button>
                        <textarea name="body" id="body" maxlength="4000" rows="1"
                                  placeholder="{{ $room->type === 'site_announcement' ? '공지 내용' : '메시지 입력' }}"></textarea>
                        <button class="send" type="submit" id="btn-send">전송</button>
                    </div>
                    <input type="file" name="files[]" id="files" multiple
                           accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.heic">
                    <div class="picked" id="picked"></div>
                    @if($room->type !== 'direct')
                        <div class="hint">사진·영수증·도면을 올리면 AI 가 읽고 재무·장비·문서함으로 보냅니다.</div>
                    @endif
                </form>
            @else
                <div class="readonly">이 방은 공지 전용입니다. 각 공지에 답글로 이야기해 주세요.</div>
            @endif
        </section>
    </div>

    <div class="sheet-back" id="sheet-back"></div>
    <div class="sheet" id="sheet">
        <h2>참여자 <span id="sheet-count" style="color:#6b7280;font-weight:400"></span></h2>
        <div id="sheet-list"></div>
    </div>

<script>
/**
 * 대화 화면 — 서버가 주는 "메시지 목록" 만 받아 그린다.
 *
 * 전송 방식(폴링/웹소켓)을 갈아타도 이 아래는 그대로다. 처음 그릴 때도 같은 통로를
 * 쓴다 — 화면을 두 벌(서버 렌더 + 실시간 렌더)로 만들면 언젠가 둘이 달라진다.
 */
(function () {
    var thread = document.getElementById('thread');
    var streamUrl = '{{ route('communication.stream', ['room' => $room], false) }}';
    var membersUrl = '{{ route('communication.members', ['room' => $room], false) }}';
    var roomBase = '{{ url('/attendance-app/messages/'.$room->id) }}';
    var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    var lastId = 0;
    var lastDay = '';
    var timer = null;
    var membersCache = [];

    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function initials(name) {
        var n = (name || '').trim();
        if (!n) return '?';
        return /[가-힣]/.test(n) ? n.slice(-2) : n.slice(0, 2).toUpperCase();
    }
    function dayLabel(iso) {
        if (!iso) return '';
        var d = new Date(iso + 'T00:00:00');
        var days = ['일', '월', '화', '수', '목', '금', '토'];
        return (d.getMonth() + 1) + '월 ' + d.getDate() + '일 ' + days[d.getDay()] + '요일';
    }

    function filesHtml(files) {
        if (!files || !files.length) return '';
        return '<div class="files">' + files.map(function (f) {
            if (f.isImage && f.url) {
                return '<a href="' + esc(f.url) + '" target="_blank" rel="noopener"><img src="' + esc(f.url) + '" alt="' + esc(f.name) + '" loading="lazy"></a>';
            }
            var open = f.url ? '<a class="filecard" href="' + esc(f.url) + '" target="_blank" rel="noopener">' : '<a class="filecard">';
            return open + '<span>📎</span><span><span class="nm">' + esc(f.name) + '</span><br><span class="sz">' + esc(f.size) + '</span></span></a>';
        }).join('') + '</div>';
    }

    function noticeHtml(m) {
        var ai = m.kind === 'system';
        return '<div class="notice-card ' + (ai ? 'ai' : '') + '" id="message-' + m.id + '">' +
            '<b>' + esc(m.title || (ai ? '🤖 AI' : '공지')) + '</b>' +
            esc(m.body) + filesHtml(m.files) + '</div>';
    }

    function bubbleHtml(m) {
        var tools = '';
        if (!m.removed && (m.canEdit || m.canRemove)) {
            tools = '<div class="tools">' +
                (m.canEdit ? '<button type="button" onclick="window.Chat.edit(' + m.id + ')">수정</button>' : '') +
                (m.canRemove ? '<button type="button" onclick="window.Chat.remove(' + m.id + ')">삭제</button>' : '') +
                '</div>';
        }

        var stamp = '<span class="stamp">' + (m.edited ? '<span class="edited">수정됨 </span>' : '') + esc(m.sentAt || '') + '</span>';
        var bubble = '<div class="bubble' + (m.removed ? ' gone' : '') + '">' + esc(m.body) + '</div>';
        var body = m.removed ? bubble : bubble + filesHtml(m.files);

        if (m.mine) {
            return '<div class="row mine" id="message-' + m.id + '" data-body="' + esc(m.body) + '">' +
                '<div class="stack"><div class="bundle">' + stamp + body + '</div>' + tools + '</div></div>';
        }

        return '<div class="row" id="message-' + m.id + '">' +
            '<div class="face">' + esc(initials(m.sender)) + '</div>' +
            '<div class="stack"><div class="who">' + esc(m.sender) + '</div>' +
            '<div class="bundle">' + body + stamp + '</div>' + tools + '</div></div>';
    }

    function render(m) {
        var existing = document.getElementById('message-' + m.id);
        var isNotice = m.kind === 'announcement' || m.kind === 'system' || m.kind === 'attendance_alert';
        var html = isNotice ? noticeHtml(m) : bubbleHtml(m);

        if (existing) {                       // 고쳐지거나 지워진 글 — 제자리에서 바꾼다
            existing.outerHTML = html;
            return;
        }

        if (m.sentOn && m.sentOn !== lastDay) {
            lastDay = m.sentOn;
            thread.insertAdjacentHTML('beforeend', '<div class="day"><span>' + esc(dayLabel(m.sentOn)) + '</span></div>');
        }
        thread.insertAdjacentHTML('beforeend', html);
    }

    function paintMembers(members, onlineCount) {
        membersCache = members || [];
        var dot = document.getElementById('online-dot');
        var sub = document.getElementById('sub-text');
        if (onlineCount > 0) {
            dot.hidden = false;
            sub.textContent = '{{ $typeLabel }} · ' + membersCache.length + '명 · ' + onlineCount + '명 접속 중';
        } else {
            dot.hidden = true;
            sub.textContent = '{{ $typeLabel }} · ' + membersCache.length + '명';
        }
    }

    function openMembers() {
        var list = document.getElementById('sheet-list');
        document.getElementById('sheet-count').textContent = membersCache.length + '명';
        list.innerHTML = membersCache.map(function (m) {
            return '<div class="mem"><div class="face">' + esc(initials(m.name)) + '</div>' +
                '<div><div class="nm">' + esc(m.name) + '</div></div>' +
                '<div class="st ' + (m.online ? 'on' : '') + '">' + (m.online ? '● 접속 중' : esc(m.lastSeen || '접속 기록 없음')) + '</div></div>';
        }).join('') || '<div style="color:#6b7280;font-size:13px">아직 참여자가 없습니다. 관리 화면에서 "직원 동기화" 를 눌러 주세요.</div>';
        document.getElementById('sheet').style.display = 'block';
        document.getElementById('sheet-back').style.display = 'block';
    }
    function closeMembers() {
        document.getElementById('sheet').style.display = 'none';
        document.getElementById('sheet-back').style.display = 'none';
    }
    document.getElementById('btn-members').addEventListener('click', function () {
        fetch(membersUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { paintMembers(d.members, (d.members || []).filter(function (m) { return m.online; }).length); openMembers(); })
            .catch(openMembers);
    });
    document.getElementById('sheet-back').addEventListener('click', closeMembers);

    // ── 내 글 손보기 ────────────────────────────────────────────────
    window.Chat = {
        edit: function (id) {
            var row = document.getElementById('message-' + id);
            var current = row ? (row.getAttribute('data-body') || '') : '';
            var next = window.prompt('메시지 수정', current);
            if (next === null || next.trim() === '' || next === current) return;

            fetch(roomBase + '/' + id, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ body: next })
            }).then(function (r) {
                if (!r.ok) throw new Error();
                poll();
            }).catch(function () { alert('수정하지 못했습니다.'); });
        },
        remove: function (id) {
            if (!confirm('이 메시지를 삭제할까요?\n(기록에는 "삭제된 메시지" 로 남습니다)')) return;

            fetch(roomBase + '/' + id, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token }
            }).then(function (r) {
                if (!r.ok) throw new Error();
                poll();
            }).catch(function () { alert('삭제하지 못했습니다.'); });
        }
    };

    // ── 받아오기 ───────────────────────────────────────────────────
    function schedule(ms) { clearTimeout(timer); timer = setTimeout(poll, Math.max(2000, ms || 5000)); }

    function poll() {
        if (document.hidden) { schedule(15000); return; }

        fetch(streamUrl + '?after=' + lastId, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { schedule(30000); return; }

                var atBottom = (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 140);
                (data.messages || []).forEach(render);
                paintMembers(data.members, data.onlineCount);

                if (data.messages && data.messages.length && (atBottom || lastId === 0)) {
                    window.scrollTo(0, document.body.scrollHeight);
                }
                if (data.lastId) lastId = data.lastId;
                schedule(data.nextPollMs);
            })
            .catch(function () { schedule(30000); });
    }

    document.addEventListener('visibilitychange', function () { if (!document.hidden) { clearTimeout(timer); poll(); } });
    window.addEventListener('pagehide', function () { clearTimeout(timer); });

    // ── 입력창 ─────────────────────────────────────────────────────
    var form = document.getElementById('composer-form');
    if (form) {
        var body = document.getElementById('body');
        var files = document.getElementById('files');
        var picked = document.getElementById('picked');

        document.getElementById('btn-file').addEventListener('click', function () { files.click(); });
        files.addEventListener('change', function () {
            var names = Array.prototype.map.call(files.files, function (f) { return f.name; });
            picked.textContent = names.length ? '📎 ' + names.join(', ') : '';
            picked.style.display = names.length ? 'block' : 'none';
        });

        // 줄이 늘면 입력창도 자란다 — 긴 보고를 좁은 칸에 밀어 넣지 않게.
        body.addEventListener('input', function () {
            body.style.height = 'auto';
            body.style.height = Math.min(120, body.scrollHeight) + 'px';
        });

        // PC 에서는 엔터로 보내고, 줄바꿈은 Shift+엔터. 폰에서는 엔터가 줄바꿈이다.
        body.addEventListener('keydown', function (e) {
            var phone = window.matchMedia('(max-width: 820px)').matches;
            if (!phone && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
        });
    }

    poll();
})();
</script>
</body>
</html>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $roomLabel ?? $room->name }}</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; background: #f6f7f9; color: #111827; }
        body { margin: 0; min-height: 100vh; background: #f6f7f9; }
        .app { min-height: 100vh; max-width: 640px; margin: 0 auto; background: #fff; display: flex; flex-direction: column; }
        header { position: sticky; top: 0; z-index: 10; padding: 14px 16px 12px; border-bottom: 1px solid #e5e7eb; background: #fff; }
        .top { display: grid; grid-template-columns: auto 1fr; gap: 12px; align-items: center; }
        .back { color: #2563eb; font-weight: 800; text-decoration: none; font-size: 14px; }
        h1 { margin: 0; font-size: 20px; line-height: 1.25; }
        .meta { margin-top: 4px; color: #6b7280; font-size: 12px; }
        main { flex: 1; padding: 12px 12px 110px; }
        .day { text-align: center; color: #6b7280; font-size: 12px; margin: 12px 0; }
        .message { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; margin-bottom: 10px; background: #fff; }
        .message.announcement { border-color: #f59e0b; background: #fffbeb; }
        .message.system { border-color: #bfdbfe; background: #eff6ff; }
        .message h2 { margin: 0 0 8px; font-size: 16px; line-height: 1.3; }
        .body { white-space: pre-wrap; font-size: 15px; line-height: 1.45; }
        .line { display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; color: #6b7280; font-size: 12px; margin-top: 9px; }
        .kind { border-radius: 999px; padding: 3px 7px; background: #eef2ff; color: #3730a3; font-weight: 800; }
        .replies { display: grid; gap: 8px; margin-top: 10px; padding-left: 12px; border-left: 2px solid #e5e7eb; }
        .reply { background: #f9fafb; border-radius: 9px; padding: 9px; }
        .reply .body { font-size: 14px; }
        form.reply-form { display: grid; grid-template-columns: 1fr auto; gap: 8px; margin-top: 10px; }
        .composer { position: fixed; left: 50%; bottom: 0; width: min(640px, 100vw); transform: translateX(-50%); box-sizing: border-box; border-top: 1px solid #d1d5db; padding: 10px; background: #fff; }
        .composer form { display: grid; gap: 8px; }
        textarea, input { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 9px; padding: 10px 11px; font: inherit; background: #fff; }
        textarea { min-height: 46px; resize: vertical; }
        button { appearance: none; border: 0; border-radius: 9px; padding: 10px 13px; background: #145fff; color: #fff; font-weight: 800; cursor: pointer; }
        .attachments { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .attachments img { max-width: min(260px, 72vw); max-height: 260px; border-radius: 10px; border: 1px solid #e5e7eb; display: block; }
        .file-card { display: grid; gap: 2px; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 10px; background: #fff; text-decoration: none; color: #111827; }
        .file-name { font-size: 13px; font-weight: 700; word-break: break-all; }
        .file-size { font-size: 11px; color: #6b7280; }
        .attach-row { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #4b5563; }
        .attach-row input[type=file] { border: 0; padding: 0; font-size: 12px; }
        .reply-form button { padding: 9px 11px; }
        .empty { padding: 36px 8px; color: #6b7280; text-align: center; }
        .notice { color: #6b7280; font-size: 13px; line-height: 1.4; }
    </style>
</head>
<body>
    <div class="app">
        <header>
            <div class="top">
                <a class="back" href="{{ route('communication.index') }}">목록</a>
                @php
                    $typeLabel = [
                        'site_announcement' => '공지 알림방',
                        'site_chat' => '현장 채팅방',
                        'company' => '회사 채팅방',
                        'team' => '팀 채팅방',
                        'direct' => '1:1 메시지',
                    ][$room->type] ?? '채팅방';
                @endphp
                <div>
                    <h1>{{ $roomLabel ?? $room->name }}</h1>
                    <div class="meta">{{ $room->site?->name ?? '전체' }} · {{ $membersCount }}명 · {{ $typeLabel }}</div>
                </div>
            </div>
        </header>

        <main>
            @forelse($messages as $message)
                @php
                    $sender = $message->senderEmployee?->name ?? $message->senderUser?->name ?? 'SMART ERP';
                    $readCount = $message->reads->filter(fn ($read) => $read->employee_id || $read->user_id)->count();
                    $kindClass = $message->kind === 'announcement' ? 'announcement' : ($message->kind === 'attendance_alert' ? 'system' : '');
                @endphp
                <article class="message {{ $kindClass }}" id="message-{{ $message->id }}">
                    @if($message->title)
                        <h2>{{ $message->title }}</h2>
                    @endif
                    <div class="body">{{ $message->body }}</div>
                    @include('communication.partials.attachments', ['message' => $message, 'room' => $room])
                    <div class="line">
                        <span class="kind">{{ \App\Models\CommunicationMessage::KIND_OPTIONS[$message->kind] ?? $message->kind }}</span>
                        <span>{{ $sender }}</span>
                        <span>{{ $message->sent_at?->format('m/d H:i') }}</span>
                        <span>읽음 {{ $readCount }}/{{ $membersCount }}</span>
                    </div>

                    @if($message->replies->isNotEmpty())
                        <div class="replies">
                            @foreach($message->replies as $reply)
                                @php
                                    $replySender = $reply->senderEmployee?->name ?? $reply->senderUser?->name ?? 'SMART ERP';
                                    $replyReadCount = $reply->reads->filter(fn ($read) => $read->employee_id || $read->user_id)->count();
                                @endphp
                                <div class="reply">
                                    <div class="body">{{ $reply->body }}</div>
                                    @include('communication.partials.attachments', ['message' => $reply, 'room' => $room])
                                    <div class="line">
                                        <span>{{ $replySender }}</span>
                                        <span>{{ $reply->sent_at?->format('m/d H:i') }}</span>
                                        <span>읽음 {{ $replyReadCount }}/{{ $membersCount }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <form class="reply-form" method="POST" action="{{ route('communication.store', ['room' => $room]) }}">
                        @csrf
                        <input type="hidden" name="parent_id" value="{{ $message->id }}">
                        <input name="body" maxlength="4000" placeholder="댓글" required>
                        <button type="submit">등록</button>
                    </form>
                </article>
            @empty
                <div class="empty">아직 메시지가 없습니다.</div>
            @endforelse
        </main>

        <section class="composer">
            @if($canPostTopLevel)
                <form method="POST" action="{{ route('communication.store', ['room' => $room]) }}" enctype="multipart/form-data">
                    @csrf
                    @if($room->type === 'site_announcement')
                        <input name="title" maxlength="255" placeholder="공지 제목">
                    @endif
                    <textarea name="body" maxlength="4000" placeholder="{{ $room->type === 'site_announcement' ? '공지 내용' : '메시지' }}"></textarea>
                    {{-- 사진·영수증·도면을 그대로 던지면 문서함이 읽고 해당 모듈로 보낸다. --}}
                    <div class="attach-row">
                        <input type="file" name="files[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.heic">
                    </div>
                    <button type="submit">{{ $room->type === 'site_announcement' ? '공지 보내기' : '보내기' }}</button>
                </form>
                @if($room->type !== 'direct')
                    <div class="meta" style="margin-top:6px">사진·영수증·도면을 올리면 AI 가 읽고 재무·장비·문서함으로 보냅니다.</div>
                @endif
            @else
                <div class="notice">이 방은 공지 전용입니다. 댓글은 각 공지 아래에서 남길 수 있습니다.</div>
            @endif
        </section>
    </div>

<script>
/**
 * 새로고침 없이 대화가 흐르게 — 마지막으로 받은 번호 이후만 받아 온다.
 *
 * 간격은 서버가 정한다(응답의 nextPollMs). 화면에 3초를 박아 두면 나중에 요금이
 * 문제가 됐을 때 앱을 새로 배포해야 바꿀 수 있고, 조용한 방까지 3초마다 두드린다.
 *
 * 다른 화면을 보고 있을 때는 아예 묻지 않는다(보이지 않는 화면을 위해 서버를 두드리는
 * 것은 순전한 낭비다 — 앱이 잠들 수 있는 배포에서는 그게 곧 요금이다). 돌아오면
 * 그 즉시 한 번 물어본다.
 *
 * 이 화면은 "새 메시지 목록" 만 받는다. 그것이 폴링으로 왔는지 나중에 웹소켓으로
 * 오는지 모른다 — 전송 방식을 갈아탈 때 이 아래를 고치면 되고 화면은 그대로다.
 */
(function () {
    var main = document.querySelector('main');
    if (!main) return;

    var streamUrl = '{{ route('communication.stream', ['room' => $room], false) }}';
    var lastId = {{ $messages->max('id') ?? 0 }};
    @php($replyMax = $messages->flatMap(fn ($m) => $m->replies)->max('id') ?? 0)
    if ({{ $replyMax }} > lastId) lastId = {{ $replyMax }};

    var timer = null;
    var stopped = false;

    function esc(text) {
        var d = document.createElement('div');
        d.textContent = text == null ? '' : String(text);
        return d.innerHTML;
    }

    function attachmentsHtml(files) {
        if (!files || !files.length) return '';
        var parts = files.map(function (f) {
            if (f.isImage && f.url) {
                return '<a href="' + esc(f.url) + '" target="_blank" rel="noopener"><img src="' + esc(f.url) + '" alt="' + esc(f.name) + '" loading="lazy"></a>';
            }
            var open = f.url ? '<a class="file-card" href="' + esc(f.url) + '" target="_blank" rel="noopener">' : '<a class="file-card">';
            return open + '<span class="file-name">📎 ' + esc(f.name) + '</span><span class="file-size">' + esc(f.size) + '</span></a>';
        });
        return '<div class="attachments">' + parts.join('') + '</div>';
    }

    function render(message) {
        // 이미 화면에 있는 글은 다시 그리지 않는다(보낸 뒤 되돌아오는 경우).
        if (document.getElementById('message-' + message.id)) return;

        var body = '<div class="body">' + esc(message.body) + '</div>' + attachmentsHtml(message.files);
        var line = '<div class="line"><span>' + esc(message.sender) + '</span><span>' + esc(message.sentAt || '') + '</span></div>';

        if (message.parentId) {
            var parent = document.getElementById('message-' + message.parentId);
            if (!parent) return;                       // 원글이 화면에 없으면 건너뛴다
            var box = parent.querySelector('.replies');
            if (!box) {
                box = document.createElement('div');
                box.className = 'replies';
                parent.insertBefore(box, parent.querySelector('.reply-form'));
            }
            var reply = document.createElement('div');
            reply.className = 'reply';
            reply.id = 'message-' + message.id;
            reply.innerHTML = body + line;
            box.appendChild(reply);

            return;
        }

        var article = document.createElement('article');
        article.className = 'message' + (message.kind === 'announcement' ? ' announcement' : (message.kind === 'system' || message.kind === 'attendance_alert' ? ' system' : ''));
        article.id = 'message-' + message.id;
        article.innerHTML = (message.title ? '<h2>' + esc(message.title) + '</h2>' : '') + body + line;

        var empty = main.querySelector('.empty');
        if (empty) empty.remove();
        main.appendChild(article);
    }

    function schedule(ms) {
        if (stopped) return;
        clearTimeout(timer);
        timer = setTimeout(poll, Math.max(2000, ms || 5000));
    }

    function poll() {
        if (document.hidden) { schedule(15000); return; }   // 안 보는 화면은 묻지 않는다

        fetch(streamUrl + '?after=' + lastId, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { schedule(30000); return; }      // 실패하면 천천히 다시

                var atBottom = (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 120);
                (data.messages || []).forEach(render);
                if (data.messages && data.messages.length && atBottom) {
                    window.scrollTo(0, document.body.scrollHeight);
                }
                if (data.lastId) lastId = data.lastId;
                schedule(data.nextPollMs);
            })
            .catch(function () { schedule(30000); });
    }

    // 다른 화면을 보다 돌아오면 그 즉시 한 번 확인한다.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { clearTimeout(timer); poll(); }
    });

    window.addEventListener('pagehide', function () { stopped = true; clearTimeout(timer); });

    schedule(3000);
})();
</script>
</body>
</html>

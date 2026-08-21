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
</body>
</html>

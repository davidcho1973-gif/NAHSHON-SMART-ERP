<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ \App\Support\Org::name() }} 메신저</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; background: #f6f7f9; color: #111827; }
        body { margin: 0; min-height: 100vh; background: #f6f7f9; }
        .app { min-height: 100vh; max-width: 560px; margin: 0 auto; background: #fff; display: flex; flex-direction: column; }
        header { padding: 18px 18px 12px; border-bottom: 1px solid #e5e7eb; }
        .top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .back { color: #2563eb; font-weight: 800; text-decoration: none; font-size: 14px; }
        .eyebrow { margin: 0 0 6px; color: #2563eb; font-size: 12px; font-weight: 800; text-transform: uppercase; }
        h1 { margin: 0; font-size: 24px; line-height: 1.2; }
        main { padding: 14px 14px 28px; flex: 1; }
        .identity { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; background: #fafafa; margin-bottom: 12px; }
        .identity strong { display: block; font-size: 16px; }
        .identity span { display: block; color: #6b7280; font-size: 13px; margin-top: 4px; }
        .rooms { display: grid; gap: 10px; }
        .room { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; border: 1px solid #e5e7eb; border-radius: 10px; padding: 13px; color: inherit; text-decoration: none; background: #fff; }
        .room strong { display: block; font-size: 16px; margin-bottom: 5px; }
        .room span { display: block; color: #6b7280; font-size: 13px; line-height: 1.4; }
        .badge { min-width: 24px; height: 24px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; padding: 0 7px; background: #ef4444; color: #fff; font-size: 12px; font-weight: 800; }
        .type { display: inline-flex; margin-top: 7px; border-radius: 999px; padding: 3px 7px; background: #eef2ff; color: #3730a3; font-size: 12px; font-weight: 700; }
        .empty { padding: 24px 10px; color: #6b7280; text-align: center; line-height: 1.5; }
        .section-title { margin: 18px 2px 8px; font-size: 13px; font-weight: 800; color: #374151; }
        .bell { border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; overflow: hidden; margin-bottom: 8px; }
        .bell-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 11px 13px; border-bottom: 1px solid #f1f2f4; }
        .bell-head strong { font-size: 14px; }
        .bell-head .count { min-width: 22px; height: 22px; border-radius: 999px; background: #ef4444; color: #fff; font-size: 12px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; padding: 0 7px; }
        .bell-head form { margin: 0; }
        .bell-head button { appearance: none; border: 0; background: transparent; color: #2563eb; font-weight: 800; font-size: 12px; cursor: pointer; }
        .notif { display: block; padding: 10px 13px; border-bottom: 1px solid #f5f6f7; color: inherit; text-decoration: none; }
        .notif:last-child { border-bottom: 0; }
        .notif.unread { background: #fef2f2; }
        .notif strong { display: block; font-size: 13px; }
        .notif span { display: block; color: #6b7280; font-size: 12px; margin-top: 2px; }
        .picker { border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; padding: 11px; margin-bottom: 8px; }
        .picker input[type=search] { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 9px; padding: 9px 11px; font: inherit; }
        .picker .cand { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 8px; }
        .picker .cand span { font-size: 14px; }
        .picker .cand small { color: #6b7280; }
        .picker .cand button { appearance: none; border: 0; border-radius: 8px; padding: 7px 12px; background: #145fff; color: #fff; font-weight: 800; cursor: pointer; }
        .picker form.inline { margin: 0; }
    </style>
</head>
<body>
    <div class="app">
        <header>
            <div class="top">
                <div>
                    <p class="eyebrow">{{ \App\Support\Org::name() }}</p>
                    <h1>내부 메신저</h1>
                </div>
                <a class="back" href="{{ route('attendance-app.index') }}">출석 홈</a>
            </div>
        </header>

        <main>
            @include('partials.push-optin')
            <section class="identity">
                <strong>{{ $employee?->name ?? $user->name }}</strong>
                <span>{{ $employee?->site?->name ?? '현장 미지정' }} · {{ $employee?->company?->name ?? '소속 회사 미지정' }}</span>
            </section>

            @if($notifications->isNotEmpty())
                <div class="bell">
                    <div class="bell-head">
                        <strong>알림</strong>
                        @if($notificationUnread > 0)
                            <span class="count">{{ $notificationUnread > 99 ? '99+' : $notificationUnread }}</span>
                            <form method="POST" action="{{ route('communication.notifications.read') }}">
                                @csrf
                                <button type="submit">모두 읽음</button>
                            </form>
                        @endif
                    </div>
                    @foreach($notifications as $notification)
                        <a class="notif {{ $notification->read_at ? '' : 'unread' }}"
                           href="{{ $notification->communication_room_id ? route('communication.show', ['room' => $notification->communication_room_id]) : route('communication.index') }}">
                            <strong>{{ $notification->title }}</strong>
                            @if($notification->body)<span>{{ \Illuminate\Support\Str::limit($notification->body, 70) }}</span>@endif
                        </a>
                    @endforeach
                </div>
            @endif

            @if($employee)
                <form class="picker" method="GET" action="{{ route('communication.index') }}">
                    <input type="search" name="people" value="{{ $peopleSearch }}" placeholder="이름으로 검색해 1:1 메시지 시작…">
                    @foreach($dmCandidates as $candidate)
                        <div class="cand">
                            <span>{{ $candidate->name }} <small>· {{ $candidate->role ?? $candidate->site?->name ?? '' }}</small></span>
                            <form class="inline" method="POST" action="{{ route('communication.direct.start') }}">
                                @csrf
                                <input type="hidden" name="employee_id" value="{{ $candidate->id }}">
                                <button type="submit">메시지</button>
                            </form>
                        </div>
                    @endforeach
                    @if($peopleSearch !== '' && $dmCandidates->isEmpty())
                        <div class="cand"><small>검색 결과가 없습니다.</small></div>
                    @endif
                </form>
            @endif

            <div class="section-title">대화방</div>
            <section class="rooms">
                @forelse($rooms as $room)
                    @php
                        $latest = $room->latestMessage;
                        $unread = $unreadCounts[$room->id] ?? 0;
                    @endphp
                    @php
                        $typeLabel = [
                            'site_announcement' => '공지 알림방',
                            'site_chat' => '현장 채팅방',
                            'company' => '회사 채팅방',
                            'team' => '팀 채팅방',
                            'direct' => '1:1 메시지',
                        ][$room->type] ?? '채팅방';
                    @endphp
                    <a class="room" href="{{ route('communication.show', ['room' => $room]) }}">
                        <div>
                            <strong>{{ $roomLabels[$room->id] ?? $room->name }}</strong>
                            <span>{{ $latest?->body ? \Illuminate\Support\Str::limit($latest->body, 68) : ($room->description ?? '새 메시지가 없습니다.') }}</span>
                            <span class="type">{{ $typeLabel }}</span>
                        </div>
                        @if($unread > 0)
                            <span class="badge">{{ $unread > 99 ? '99+' : $unread }}</span>
                        @endif
                    </a>
                @empty
                    <div class="empty">참여 중인 메신저 방이 아직 없습니다.</div>
                @endforelse
            </section>
        </main>
    </div>
</body>
</html>

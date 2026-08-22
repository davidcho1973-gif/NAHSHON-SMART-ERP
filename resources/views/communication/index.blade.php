{{--
    채팅 목록 — 카카오톡의 "채팅" 탭과 같은 모양.

    방마다 동그란 얼굴, 이름 굵게, 마지막 대화 한 줄, 오른쪽에 시간과 안 읽은 수.
    익숙한 모양이면 현장 사람들이 배우지 않고 바로 쓴다.
--}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ \App\Support\Org::name() }} 메신저</title>
    <style>
        :root { color-scheme: light; font-family: -apple-system, BlinkMacSystemFont, "Apple SD Gothic Neo", "Malgun Gothic", "Noto Sans KR", sans-serif; }
        * { -webkit-tap-highlight-color: transparent; }
        body { margin: 0; background: #fff; color: #111827; }
        .app { min-height: 100vh; max-width: 640px; margin: 0 auto; background: #fff; }

        header { position: sticky; top: 0; z-index: 10; background: #fff; padding: 16px 16px 10px; border-bottom: 1px solid #f1f3f5; }
        .top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        h1 { margin: 0; font-size: 22px; font-weight: 800; }
        .who { margin-top: 3px; font-size: 12px; color: #6b7280; }
        .back { color: #2563eb; font-weight: 700; text-decoration: none; font-size: 13px; }

        main { padding: 0 0 40px; }
        .pad { padding: 12px 16px 0; }

        /* 알림(서류 만료 등) */
        .bell { margin: 12px 16px; border: 1px solid #fee2e2; background: #fff5f5; border-radius: 14px; overflow: hidden; }
        .bell-head { display: flex; align-items: center; gap: 8px; padding: 10px 13px; border-bottom: 1px solid #fee2e2; font-size: 13px; }
        .bell-head strong { font-size: 13px; }
        .count { background: #ef4444; color: #fff; border-radius: 999px; padding: 1px 8px; font-size: 11px; font-weight: 800; }
        .bell form { margin-left: auto; }
        .bell button { background: none; border: 0; color: #2563eb; font-size: 12px; font-weight: 700; cursor: pointer; }
        .notif { display: block; padding: 10px 13px; text-decoration: none; color: inherit; border-top: 1px solid #fef2f2; }
        .notif strong { display: block; font-size: 13px; line-height: 1.35; }
        .notif span { display: block; font-size: 12px; color: #6b7280; margin-top: 2px; }

        /* 방 목록 */
        .rooms { display: block; }
        .room { display: grid; grid-template-columns: auto 1fr auto; gap: 12px; align-items: center; padding: 12px 16px; text-decoration: none; color: inherit; }
        .room:active { background: #f6f7f9; }
        .face { width: 48px; height: 48px; border-radius: 17px; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 800; color: #fff; }
        .mid { min-width: 0; }
        .nm { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
        .nm small { font-size: 11px; color: #9ca3af; font-weight: 500; }
        .last { font-size: 13px; color: #6b7280; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .right { text-align: right; display: grid; gap: 5px; justify-items: end; }
        .time { font-size: 11px; color: #9ca3af; }
        .badge { background: #ff3b30; color: #fff; border-radius: 999px; min-width: 20px; padding: 2px 6px; font-size: 11px; font-weight: 800; text-align: center; }

        .section-title { padding: 16px 16px 6px; font-size: 12px; font-weight: 800; color: #9ca3af; }
        .empty { padding: 40px 16px; text-align: center; color: #9ca3af; font-size: 13px; }

        /* 1:1 시작 */
        .picker { margin: 0 16px 8px; }
        .picker input { width: 100%; box-sizing: border-box; border: 1px solid #e5e7eb; border-radius: 12px; padding: 11px 13px; font: inherit; font-size: 14px; background: #f7f8fa; }
        .cand { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 2px; border-bottom: 1px solid #f4f5f7; font-size: 14px; }
        .cand button { border: 0; background: #fee500; color: #3c1e1e; border-radius: 9px; padding: 7px 12px; font-weight: 800; font-size: 12px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="app">
        <header>
            <div class="top">
                <div>
                    <h1>채팅</h1>
                    <div class="who">{{ $employee?->name ?? $user->name }} · {{ $employee?->site?->name ?? '현장 미지정' }}</div>
                </div>
                <div style="display:flex;align-items:center;gap:12px">
                    {{-- 알림 상태를 한눈에 — 켜짐 🔔 / 꺼짐 🔕. 눌러서 켜고 끈다. --}}
                    <button type="button" id="push-bell" hidden
                            style="border:0;background:none;font-size:22px;line-height:1;cursor:pointer;padding:2px">🔕</button>
                    @if($canManageRooms)
                        {{-- 방 만들기 — 폰에서 못 만들면 없는 기능이나 마찬가지다. --}}
                        <button type="button" id="btn-new-room"
                                style="border:0;background:none;font-size:24px;line-height:1;cursor:pointer;padding:2px;color:#2563eb">＋</button>
                    @endif
                    <a class="back" href="{{ route('attendance-app.index') }}">출석 홈</a>
                </div>
            </div>
        </header>

        <main>
            <div class="pad">@include('partials.push-optin')</div>

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

            <section class="rooms">
                @php
                    // 방 종류마다 얼굴 색을 달리해 목록에서 한눈에 구분되게.
                    $faceColors = [
                        'site_announcement' => '#f59e0b',
                        'site_chat' => '#3b82f6',
                        'site_ops' => '#10b981',
                        'company' => '#8b5cf6',
                        'team' => '#0ea5e9',
                        'direct' => '#64748b',
                    ];
                    $typeLabels = [
                        'site_announcement' => '공지',
                        'site_chat' => '현장',
                        'site_ops' => '상황실',
                        'company' => '회사',
                        'team' => '팀',
                        'direct' => '1:1',
                    ];
                @endphp
                @forelse($rooms as $room)
                    @php
                        $latest = $room->latestMessage;
                        $unread = $unreadCounts[$room->id] ?? 0;
                        $label = $roomLabels[$room->id] ?? $room->name;
                        $color = $faceColors[$room->type] ?? '#64748b';
                        $initial = preg_match('/[가-힣]/u', $label)
                            ? mb_substr($label, 0, 2)
                            : mb_strtoupper(mb_substr($label, 0, 2));
                        $preview = $latest?->removed_at
                            ? '삭제된 메시지입니다.'
                            : ($latest?->body ? \Illuminate\Support\Str::limit($latest->body, 40) : ($room->description ?: '새 메시지가 없습니다.'));
                    @endphp
                    <a class="room" href="{{ route('communication.show', ['room' => $room]) }}">
                        <div class="face" style="background: {{ $color }}">{{ $initial }}</div>
                        <div class="mid">
                            <div class="nm">{{ $label }} <small>{{ $typeLabels[$room->type] ?? '' }}</small></div>
                            <div class="last">{{ $preview }}</div>
                        </div>
                        <div class="right">
                            <span class="time">{{ $room->last_message_at?->format('n/j') ?? '' }}</span>
                            @if($unread > 0)
                                <span class="badge">{{ $unread > 99 ? '99+' : $unread }}</span>
                            @endif
                        </div>
                    </a>
                @empty
                    <div class="empty">참여 중인 채팅방이 아직 없습니다.<br>관리자에게 방 참여를 요청해 주세요.</div>
                @endforelse
            </section>

            @if($canManageRooms)
                <div id="new-room" hidden style="margin:12px 16px;padding:14px;border:1px solid #e5e7eb;border-radius:14px;background:#fafbfc">
                    <form method="POST" action="{{ route('communication.room.store') }}" style="display:grid;gap:9px">
                        @csrf
                        <b style="font-size:14px">새 대화방 만들기</b>
                        <input name="name" maxlength="255" placeholder="방 이름 (예: 3층 배관팀)" required
                               style="border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;font:inherit;font-size:14px">
                        <select name="type" style="border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;font:inherit;font-size:14px">
                            <option value="site_chat">현장 채팅방</option>
                            <option value="team">팀 채팅방</option>
                            <option value="company">회사 채팅방</option>
                            <option value="site_announcement">공지방 (관리자만 글쓰기)</option>
                        </select>
                        @if($siteOptions->isNotEmpty())
                            <select name="site_id" style="border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;font:inherit;font-size:14px">
                                <option value="">현장 지정 안 함</option>
                                @foreach($siteOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->code }} · {{ $option->name }}</option>
                                @endforeach
                            </select>
                        @endif
                        <input name="description" maxlength="500" placeholder="어떤 이야기를 하는 방인지 한 줄 (선택)"
                               style="border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;font:inherit;font-size:14px">
                        <div style="display:flex;gap:8px;justify-content:flex-end">
                            <button type="button" id="new-room-cancel" style="border:1px solid #e5e7eb;background:#fff;border-radius:9px;padding:8px 14px;font-size:13px;cursor:pointer">취소</button>
                            <button type="submit" style="border:0;background:#fee500;color:#3c1e1e;border-radius:9px;padding:8px 16px;font-weight:800;font-size:13px;cursor:pointer">만들기</button>
                        </div>
                    </form>
                </div>
            @endif

            @if($employee)
                <div class="section-title">1:1 대화 시작</div>
                <form class="picker" method="GET" action="{{ route('communication.index') }}">
                    <input type="search" name="people" value="{{ $peopleSearch }}" placeholder="이름으로 검색…">
                    @foreach($dmCandidates as $candidate)
                        <div class="cand">
                            <span>{{ $candidate->name }} <small style="color:#9ca3af">· {{ $candidate->role ?? $candidate->site?->name ?? '' }}</small></span>
                            <form method="POST" action="{{ route('communication.direct.start') }}">
                                @csrf
                                <input type="hidden" name="employee_id" value="{{ $candidate->id }}">
                                <button type="submit">대화</button>
                            </form>
                        </div>
                    @endforeach
                    @if($peopleSearch !== '' && $dmCandidates->isEmpty())
                        <div class="cand"><small style="color:#9ca3af">검색 결과가 없습니다.</small></div>
                    @endif
                </form>
            @endif
        </main>
    </div>

    @if(session('success') || session('error'))
        <div style="position:fixed;left:50%;bottom:20px;transform:translateX(-50%);background:#111827;color:#fff;padding:11px 18px;border-radius:999px;font-size:13px;z-index:50;max-width:92vw">
            {{ session('success') ?: session('error') }}
        </div>
    @endif

<script>
(function () {
    var open = document.getElementById('btn-new-room');
    var box = document.getElementById('new-room');
    var cancel = document.getElementById('new-room-cancel');
    if (!open || !box) return;

    open.addEventListener('click', function () {
        box.hidden = !box.hidden;
        if (!box.hidden) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    if (cancel) cancel.addEventListener('click', function () { box.hidden = true; });
})();
</script>
</body>
</html>

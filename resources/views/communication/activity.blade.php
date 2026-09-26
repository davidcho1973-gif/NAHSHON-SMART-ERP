{{--
    활동함 — 나를 부른 글 · 내 글에 달린 답글 · 공지를 한곳에.

    방이 열 개가 되면 "누가 나를 찾았나" 를 방마다 들어가 확인할 수 없다. 슬랙의
    "활동" 탭과 같은 자리다. 한 줄을 누르면 그 글이 있는 자리로 바로 간다.

    알림 문장("…님이 불렀습니다")은 저장하지 않고 여기서 붙인다 — 저장된 한국어 문장은
    영어·스페인어로 보는 사람에게 번역되지 않는다. 저장된 것은 부른 사람 이름과 글뿐이다.
--}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('활동') }}</title>
    @include('partials.field-app-theme')
    <style>
        * { -webkit-tap-highlight-color: transparent; }
        .top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .top-left { display: flex; align-items: center; gap: 10px; }
        .read-all { border: 0; background: rgba(255,255,255,.65); border-radius: 999px; padding: 6px 11px; font-size: 12px; font-weight: 700; color: var(--label); cursor: pointer; }

        .chips { display: flex; gap: 6px; padding: 12px 16px 6px; overflow-x: auto; }
        .chip { flex: 0 0 auto; padding: 6px 12px; border-radius: 999px; background: #f2f3f5; color: #374151; font-size: 12px; font-weight: 700; text-decoration: none; }
        .chip.on { background: var(--accent-bg); color: var(--label); }

        .item { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 11px; align-items: start; padding: 12px 16px; text-decoration: none; color: inherit; border-bottom: 1px solid #f4f5f7; }
        .item:active { background: #f6f7f9; }
        .icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 800; background: #f2f3f5; color: var(--label); }
        .icon.mention { background: #dbeafe; color: #1d4ed8; }
        .icon.reply { background: #dcfce7; color: #15803d; }
        .icon.announcement { background: var(--accent-bg); }
        .icon.invite { background: #fef3c7; color: #92400e; }
        .what { font-size: 14px; font-weight: 700; line-height: 1.35; }
        .item.read .what { font-weight: 500; color: #4b5563; }
        .mid { min-width: 0; }
        .where { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .preview { font-size: 13px; color: #374151; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .when { font-size: 11px; color: #9ca3af; white-space: nowrap; display: grid; justify-items: end; gap: 6px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: #ef4444; }
        .empty { padding: 48px 16px; text-align: center; color: #9ca3af; font-size: 13px; line-height: 1.6; }
    </style>
</head>
<body class="field-app field-messages">
    <div class="app field-shell">
        <header class="field-header">
            <div class="top">
                <div class="top-left">
                    <a class="back field-back" href="{{ route('communication.index') }}" aria-label="{{ __('채팅') }}">‹</a>
                    <h1>{{ __('활동') }}</h1>
                </div>
                @if($unread > 0)
                    <form method="POST" action="{{ route('communication.notifications.read') }}">
                        @csrf
                        <button type="submit" class="read-all">{{ __('모두 읽음') }}</button>
                    </form>
                @endif
            </div>
        </header>

        <main class="field-content">
            @php
                $chips = [
                    'all' => __('전체'),
                    'mention' => __('나를 부름'),
                    'reply' => __('답글'),
                    'announcement' => __('공지'),
                ];
            @endphp
            <nav class="chips" aria-label="{{ __('알림 종류') }}">
                @foreach($chips as $key => $label)
                    <a class="chip {{ $filter === $key ? 'on' : '' }}"
                       href="{{ route('communication.activity', $key === 'all' ? [] : ['type' => $key]) }}">{{ $label }}</a>
                @endforeach
            </nav>

            @forelse($items as $item)
                @php
                    $kind = in_array($item->type, ['mention', 'reply', 'announcement', 'invite'], true) ? $item->type : 'announcement';
                    $icon = ['mention' => '@', 'reply' => '↩', 'announcement' => '📢', 'invite' => '#'][$kind];
                    $what = match ($kind) {
                        'mention' => __(':name님이 나를 불렀습니다', ['name' => $item->title]),
                        'reply' => __(':name님이 답글을 달았습니다', ['name' => $item->title]),
                        'invite' => __(':name님이 방에 초대했습니다', ['name' => $item->title]),
                        default => $item->title,
                    };
                @endphp
                <a class="item {{ $item->read_at ? 'read' : '' }}" href="{{ route('communication.activity.open', ['notification' => $item]) }}">
                    <div class="icon {{ $kind }}">{{ $icon }}</div>
                    <div class="mid">
                        <div class="what">{{ $what }}</div>
                        @if($item->room)<div class="where">{{ $item->room->name }}</div>@endif
                        @if($item->body)<div class="preview">{{ $item->body }}</div>@endif
                    </div>
                    <div class="when">
                        <span>{{ $item->created_at?->format('n/j H:i') }}</span>
                        @if(! $item->read_at)<span class="dot" aria-label="{{ __('안 읽음') }}"></span>@endif
                    </div>
                </a>
            @empty
                <div class="empty">
                    {{ __('아직 활동이 없습니다.') }}<br>
                    {{ __('누가 @내 이름 으로 부르거나 내 글에 답글을 달면 여기에 모입니다.') }}
                </div>
            @endforelse
        </main>
        @include('partials.field-app-nav')
    </div>

    @if(session('success') || session('error'))
        <div style="position:fixed;left:50%;bottom:90px;transform:translateX(-50%);background:#111827;color:#fff;padding:11px 18px;border-radius:999px;font-size:13px;z-index:50;max-width:92vw">
            {{ session('success') ?: session('error') }}
        </div>
    @endif
</body>
</html>

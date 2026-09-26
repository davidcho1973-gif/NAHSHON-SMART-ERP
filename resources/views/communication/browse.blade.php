{{--
    방 찾기 — 같은 회사의 주제방(공개)을 둘러보고 스스로 들어간다.

    현장방·공지방은 현장에 배정되면 저절로 들어가진다. 그 밖의 이야기(3층 배관, 자재,
    견적)는 필요한 사람만 들어오는 방이 낫다 — 모두를 넣으면 다시 소음이 된다.
    비공개 그룹방은 여기에 나오지 않는다. 초대로만 들어간다.
--}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('방 찾기') }}</title>
    @include('partials.field-app-theme')
    <style>
        * { -webkit-tap-highlight-color: transparent; }
        .top { display: flex; align-items: center; gap: 10px; }
        .intro { padding: 12px 16px 4px; font-size: 12px; color: #6b7280; line-height: 1.6; }
        .topic { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 13px 16px; border-bottom: 1px solid #f4f5f7; }
        .hash { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800; background: var(--accent-bg); color: var(--label); }
        .t-name { font-size: 15px; font-weight: 700; overflow-wrap: anywhere; }
        .t-desc { font-size: 12px; color: #6b7280; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .t-meta { font-size: 11px; color: #9ca3af; margin-top: 3px; }
        .join { border: 0; border-radius: 10px; padding: 9px 14px; background: rgba(0,0,0,.85); color: #fff; font-weight: 800; font-size: 13px; cursor: pointer; }
        .empty { padding: 48px 16px; text-align: center; color: #9ca3af; font-size: 13px; line-height: 1.6; }
    </style>
</head>
<body class="field-app field-messages">
    <div class="app field-shell">
        <header class="field-header">
            <div class="top">
                <a class="back field-back" href="{{ route('communication.index') }}" aria-label="{{ __('채팅') }}">‹</a>
                <h1>{{ __('방 찾기') }}</h1>
            </div>
        </header>

        <main class="field-content" style="padding-left:0;padding-right:0">
            <div class="intro">{{ __('같은 회사의 주제방입니다. 들어가면 내 채팅 목록에 생기고, 언제든 나올 수 있습니다.') }}</div>

            @forelse($topics as $topic)
                <div class="topic">
                    <div class="hash">#</div>
                    <div>
                        <div class="t-name">{{ $topic->name }}</div>
                        @if($topic->description)<div class="t-desc">{{ $topic->description }}</div>@endif
                        <div class="t-meta">
                            {{ __(':n명', ['n' => $topic->active_members_count]) }}
                            @if($topic->last_message_at) · {{ __('최근 대화') }} {{ $topic->last_message_at->format('n/j') }}@endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('communication.join', ['room' => $topic]) }}">
                        @csrf
                        <button type="submit" class="join">{{ __('참여') }}</button>
                    </form>
                </div>
            @empty
                <div class="empty">{{ __('새로 들어갈 수 있는 주제방이 없습니다.') }}<br>{{ __('주제방은 관리자가 채팅 목록의 [+] 에서 만듭니다.') }}</div>
            @endforelse
        </main>
        @include('partials.field-app-nav')
    </div>
</body>
</html>

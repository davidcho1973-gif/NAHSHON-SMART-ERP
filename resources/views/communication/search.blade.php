{{--
    대화 검색 — "두 달 전에 누가 슬리브 얘기 했더라".

    볼 수 있는 방의 글만 나온다(방 목록과 같은 규칙). 한 줄을 누르면 그 글이 있는
    자리로 바로 간다 — 찾아 놓고 다시 스크롤로 뒤지게 하면 찾은 것이 아니다.
--}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ __('대화 검색') }}</title>
    @include('partials.field-app-theme')
    <style>
        * { -webkit-tap-highlight-color: transparent; }
        .top { display: flex; align-items: center; gap: 10px; }
        .searchbox { display: flex; gap: 8px; margin: 12px 16px 4px; }
        .searchbox input[type=search] { flex: 1; min-width: 0; border: 1px solid #e5e7eb; border-radius: 12px; padding: 11px 13px; font: inherit; font-size: 16px; background: #f7f8fa; }
        .searchbox button { border: 0; border-radius: 12px; padding: 0 16px; background: rgba(0,0,0,.85); color: #fff; font-weight: 800; cursor: pointer; }
        .scope { margin: 6px 16px 2px; font-size: 12px; color: #6b7280; display: flex; gap: 8px; align-items: center; }
        .scope a { color: #2563eb; font-weight: 700; text-decoration: none; }
        .count-line { padding: 10px 16px 4px; font-size: 12px; color: #6b7280; }
        .hit { display: block; padding: 12px 16px; text-decoration: none; color: inherit; border-bottom: 1px solid #f4f5f7; }
        .hit:active { background: #f6f7f9; }
        .hit-head { display: flex; justify-content: space-between; gap: 10px; font-size: 12px; color: #6b7280; }
        .hit-head b { color: #111827; font-size: 13px; }
        .hit-where { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .snippet { font-size: 14px; margin-top: 5px; line-height: 1.5; overflow-wrap: anywhere; }
        .snippet mark { background: #fde68a; border-radius: 3px; padding: 0 1px; }
        .empty { padding: 48px 16px; text-align: center; color: #9ca3af; font-size: 13px; line-height: 1.6; }
    </style>
</head>
<body class="field-app field-messages">
    <div class="app field-shell">
        <header class="field-header">
            <div class="top">
                <a class="back field-back" href="{{ $room ? route('communication.show', ['room' => $room]) : route('communication.index') }}" aria-label="{{ __('뒤로') }}">‹</a>
                <h1>{{ __('대화 검색') }}</h1>
            </div>
        </header>

        <main class="field-content" style="padding-left:0;padding-right:0">
            <form class="searchbox" method="GET" action="{{ route('communication.search') }}">
                @if($room)<input type="hidden" name="room" value="{{ $room->id }}">@endif
                <input type="search" name="q" value="{{ $query }}" placeholder="{{ __('낱말·이름·파일 이름으로 찾기') }}" autofocus>
                <button type="submit">{{ __('찾기') }}</button>
            </form>
            @if($room)
                <div class="scope">
                    <span>{{ __(':room 안에서만 찾는 중', ['room' => $roomLabel]) }}</span>
                    <a href="{{ route('communication.search', ['q' => $query]) }}">{{ __('모든 방에서 찾기') }}</a>
                </div>
            @endif

            @if($query !== '' && $terms === [])
                <div class="empty">{{ __('두 글자 이상 입력해 주세요.') }}</div>
            @elseif($query !== '')
                <div class="count-line">{{ __(':n건', ['n' => count($results)]) }}@if(count($results) >= 50) · {{ __('최근 50건만 보여 줍니다. 낱말을 더 넣어 좁혀 보세요.') }}@endif</div>
                @forelse($results as $hit)
                    @php
                        // 찾은 낱말을 칠한다 — 먼저 전체를 이스케이프하고, 그 위에 표시만 얹는다.
                        $snippet = e($hit['snippet']);
                        foreach ($terms as $term) {
                            $snippet = preg_replace('/('.preg_quote(e($term), '/').')/iu', '<mark>$1</mark>', $snippet) ?? $snippet;
                        }
                    @endphp
                    <a class="hit" href="{{ route('communication.show', ['room' => $hit['roomId'], 'focus' => $hit['id']]) }}">
                        <div class="hit-head"><b>{{ $hit['sender'] }}</b><span>{{ $hit['sentAt'] }}</span></div>
                        <div class="hit-where">{{ $hit['roomName'] }}</div>
                        <div class="snippet">{!! $snippet !!}</div>
                    </a>
                @empty
                    <div class="empty">{{ __('찾는 말이 들어간 대화가 없습니다.') }}</div>
                @endforelse
            @else
                <div class="empty">{{ __('내가 들어갈 수 있는 방의 대화에서 찾습니다.') }}<br>{{ __('지운 글과 남의 1:1 대화는 나오지 않습니다.') }}</div>
            @endif
        </main>
        @include('partials.field-app-nav')
    </div>
</body>
</html>

{{--
    대화방 — 카카오톡을 쓰는 사람이 배우지 않고 바로 쓸 수 있게.

    현장 작업자 대부분이 한국인이고 카카오톡에 익숙하다. 익숙한 모양을 그대로 쓰면
    "이건 어떻게 쓰는 거냐" 는 질문 자체가 사라진다 — 그래서 색·배치·규칙을 맞춘다:
    하늘빛 배경, 내 말은 오른쪽 노란 말풍선, 남의 말은 왼쪽 흰 말풍선에 이름과 얼굴,
    날짜는 가운데 알약, 시간은 말풍선 옆 작게.

    다만 그대로 베끼지 않은 것이 둘 있다.
      1. <b>지운 글은 자리를 남긴다.</b> 현장 지시는 나중에 분쟁의 증거가 된다.
      2. <b>고친 글에는 (수정됨)이 붙는다.</b> 조용히 바뀌면 다툼이 된다.

    겉모양은 카카오톡, 쓰임새는 슬랙에서 가져온 것이 있다 — 방이 늘어도 소음이 되지 않게.
      · "@이름" 으로 사람을 부른다(입력창에 @ 를 치면 방 사람 목록이 뜬다).
      · 방마다 언제 폰을 울릴지 고른다(모든 글 / 부를 때만 / 끄기). 🚨 긴급만 예외.
      · 알림을 눌러 들어오면 그 글로 바로 간다. 위로 올리면 더 오래된 대화를 불러온다.
--}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $roomLabel ?? $room->name }}</title>
    {{-- Pretendard — 윈도우 기본 한글 글꼴(맑은 고딕)이 화면을 낡아 보이게 한다.
         이 글꼴 하나로 어느 기기에서 열어도 같은 얼굴이 된다. CDN 이 안 닿으면
         뒤의 시스템 글꼴로 조용히 물러난다. --}}
    @include('partials.field-app-theme')
    <style>

        * { -webkit-tap-highlight-color: transparent; }
        /* hidden 을 붙였는데도 보이던 것들 — 브라우저 기본값 [hidden]{display:none} 은
           우리가 클래스에 display 를 쓰면 곧바로 진다(작성자 규칙이 이긴다). 그래서
           답장 대상 칸(.replying, display:flex)이 빈 채로 늘 떠 있었고, 접속 표시
           점(.dot, display:inline-block)도 항상 켜져 있었다. 규칙을 여기서 되돌린다. */
        [hidden] { display: none !important; }

        /* 머리띠 — 노랑 면에 검정 글자. 방 이름 + 지금 몇 명이 보고 있는지. */

        .top { display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; }

        .sub { margin-top: 2px; font-size: 12px; color: rgba(0,0,0,.55); display: flex; align-items: center; gap: 5px; }
        .dot { width: 7px; height: 7px; border-radius: 50%; background: #1e8e3e; display: inline-block; }
        .peo { background: rgba(255,255,255,.65); border: 0; border-radius: 999px; padding: 6px 11px; font-size: 12px; font-weight: 700; color: var(--label); cursor: pointer; }

        /* 대화 */

        .day { text-align: center; margin: 16px 0 12px; }
        .day span { background: rgba(0,0,0,.18); color: #fff; font-size: 11px; padding: 4px 12px; border-radius: 999px; }

        .row { display: flex; gap: 8px; margin-bottom: 10px; align-items: flex-end; }
        .row.mine { flex-direction: row-reverse; }
        /* 얼굴은 흰 동그라미에 검정 글자 — 노란 말풍선(내 말)과 겹치지 않게 반대로 둔다. */
        .face { width: 36px; height: 36px; border-radius: 50%; background: #fff; color: var(--label); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; flex: 0 0 36px; overflow: hidden; }
        .stack { max-width: 74%; display: flex; flex-direction: column; gap: 3px; }
        .who { font-size: 12px; color: #33475b; margin-left: 2px; }
        .bundle { display: flex; gap: 5px; align-items: flex-end; }
        .row.mine .bundle { flex-direction: row-reverse; }
        /* 14px — 카카오톡 기본 크기와 같다. 15px 는 PC 에서 소리치는 것처럼 보였다. */
        .bubble { background: #fff; border-radius: 14px; padding: 8px 12px; font-size: 14px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; box-shadow: 0 1px 1px rgba(0,0,0,.06); }
        .row.mine .bubble { background: #DDEAF4; }
        .bubble.gone { background: rgba(255,255,255,.55); color: #64748b; font-style: italic; }
        .stamp { font-size: 10px; color: #4b5563; white-space: nowrap; padding-bottom: 2px; }
        .stamp .unread { color: #eab308; font-weight: 800; }
        .edited { font-size: 10px; color: #6b7280; }

        /* "@이름" — 부른 이름은 파랗게. 나를 부른 말풍선은 테두리째 노랗게 — 스크롤하다가도 눈에 걸리게. */
        .mention { color: #1d4ed8; font-weight: 700; background: rgba(59,130,246,.10); border-radius: 4px; padding: 0 2px; }
        .row.called .bubble { box-shadow: 0 0 0 2px #f5c518; }
        /* 긴급 — 빨간 띠. 알림을 꺼 둔 사람에게도 울린 글이라는 것이 화면에서도 보여야 한다. */
        .bubble.urgent, .notice-card.urgent { border-left: 4px solid #dc2626; }
        .urgent-tag { display: block; font-size: 11px; font-weight: 800; color: #dc2626; margin-bottom: 3px; }
        /* 알림에서 눌러 들어온 글 — 잠깐 빛나고 사라진다. */
        .flash .bubble, .notice-card.flash { animation: flash 2.4s ease-out; }
        @keyframes flash { 0%, 30% { box-shadow: 0 0 0 3px #f59e0b; } 100% { box-shadow: 0 0 0 0 transparent; } }
        /* 위로 올려 더 오래된 대화 불러오기 */
        .older { text-align: center; margin: 4px 0 14px; }
        .older button { border: 0; background: rgba(255,255,255,.8); border-radius: 999px; padding: 7px 16px; font-size: 12px; font-weight: 700; color: #374151; cursor: pointer; }

        /* 공지·AI — 가운데 카드 */
        /* AI·공지 카드 — 말풍선(14px)보다 한 단 작게(13px). 기계의 말이 사람 말보다
           커 보이면 방의 주인이 바뀐 것처럼 느껴진다. 제목은 작은 꼬리표로. */
        .notice-card { background: rgba(255,255,255,.94); border-radius: 14px; padding: 11px 14px 12px; margin: 0 auto 12px; max-width: 88%; font-size: 13px; line-height: 1.6; white-space: pre-wrap; word-break: break-word; border-left: 4px solid var(--accent-bg); }
        .notice-card.ai { border-left-color: #3e6be0; }
        .notice-card b { display: block; font-size: 11px; font-weight: 700; letter-spacing: .02em; margin-bottom: 6px; color: #767676; }

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
        .composer { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: var(--app-width); background: #fff; border-top: 1px solid var(--line); padding: 8px 10px calc(8px + env(safe-area-inset-bottom)); box-sizing: border-box; }
        /* 가로 줄 — 버튼 수가 권한·열쇠에 따라 달라진다. 칸 수를 고정한 격자는 [AI] 가 빠지면
           입력칸이 좁은 칸으로 밀려났다. 입력칸만 남는 폭을 다 쓰게 한다. */
        .cbar { display: flex; gap: 7px; align-items: flex-end; }
        .cbar textarea { flex: 1; min-width: 0; }
        .cbar > button { flex: 0 0 auto; }
        /* @ 를 치면 뜨는 방 사람 목록 — 이름을 외워 치게 하지 않는다. */
        .mention-pop { display: grid; gap: 2px; margin-bottom: 8px; max-height: 196px; overflow: auto; border: 1px solid var(--line); border-radius: 12px; padding: 4px; background: #fff; }
        .mention-pop button { display: flex; align-items: center; gap: 8px; border: 0; background: none; padding: 8px 10px; border-radius: 8px; font: inherit; font-size: 14px; text-align: left; cursor: pointer; }
        .mention-pop button:hover, .mention-pop button:focus { background: #f2f3f5; }
        .mention-pop small { color: #6b7280; font-size: 11px; }
        .urgent-row { display: flex; align-items: center; gap: 8px; margin-bottom: 7px; }
        .urgent-chip { border: 1px solid var(--line); background: #fff; border-radius: 999px; padding: 4px 11px; font-size: 12px; font-weight: 700; color: #6b7280; cursor: pointer; }
        .urgent-chip[aria-pressed="true"] { background: #dc2626; border-color: #dc2626; color: #fff; }
        /* 첨부 [＋] 는 노란 동그라미에 검정 글자 — 카카오가 아이콘을 담는 방식이다. */
        .plus { width: 40px; height: 40px; border-radius: 50%; border: 0; background: var(--accent-bg); font-size: 22px; font-weight: 800; color: var(--label); cursor: pointer; line-height: 1; }
        /* AI 부르기 — 노란 [＋] 옆이라 검정으로 뒤집는다. "@AI" 를 외우게 하지 않는 장치다. */
        .aibtn { width: 40px; height: 40px; border-radius: 50%; border: 0; background: rgba(0,0,0,.85); color: var(--accent-bg); font-size: 13px; font-weight: 800; cursor: pointer; line-height: 1; letter-spacing: .02em; }
        textarea { width: 100%; box-sizing: border-box; border: 1px solid var(--line); border-radius: 18px; padding: 10px 13px; font: inherit; font-size: 14px; resize: none; max-height: 120px; min-height: 40px; background: #f2f3f5; }
        /* 보내기는 검정 — 노란 [＋] 와 나란히 서므로 여기서 노랑을 또 쓰면 둘 다 죽는다. */
        .send { border: 0; border-radius: 12px; padding: 0 16px; height: 40px; background: rgba(0,0,0,.85); color: #fff; font-weight: 800; cursor: pointer; }
        .send:disabled { background: #edeef0; color: #b0b8c1; }
        .picked { font-size: 12px; color: #4b5563; padding: 6px 2px 0; display: none; }
        .hint { font-size: 11px; color: #6b7280; padding: 6px 2px 0; }
        input[type=file] { display: none; }
        .readonly { padding: 12px; color: #6b7280; font-size: 13px; text-align: center; }
        .replying { display: flex; align-items: center; gap: 8px; background: #f2f3f5; border-left: 4px solid var(--accent-bg); border-radius: 10px; padding: 7px 10px; margin-bottom: 8px; font-size: 12px; color: #191919; }
        .replying span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .replying button { border: 0; background: none; font-size: 16px; color: #64748b; cursor: pointer; line-height: 1; }
        .quote { border-left: 3px solid rgba(0,0,0,.15); padding-left: 8px; margin-bottom: 5px; font-size: 11px; color: #767676; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* 참여자 시트 */
        .sheet-back { position: fixed; inset: 0; background: rgba(0,0,0,.35); z-index: 40; display: none; }
        .sheet { position: fixed; left: 50%; bottom: 0; transform: translateX(-50%); width: 100%; max-width: var(--app-width); background: #fff; border-radius: 18px 18px 0 0; z-index: 41; padding: 16px 16px calc(20px + env(safe-area-inset-bottom)); display: none; max-height: 70vh; overflow: auto; }
        .sheet h2 { margin: 0 0 12px; font-size: 16px; }
        .mem { display: flex; align-items: center; gap: 10px; padding: 9px 2px; border-bottom: 1px solid #f1f3f5; }
        .mem .face { width: 32px; height: 32px; flex: 0 0 32px; background: var(--accent-bg); color: var(--label); border-radius: 50%; }
        .mem .face.bot { background: rgba(0,0,0,.85); font-size: 15px; }
        .mem .nm { font-size: 14px; font-weight: 700; }
        .mem .st { font-size: 11px; color: #6b7280; margin-left: auto; }
        .mem .st.on { color: #16a34a; font-weight: 800; }

        /* 이 방 알림 — 세 가지 중 하나. 고른 것은 노란 테두리. */
        .opt { display: block; width: 100%; text-align: left; border: 1px solid var(--line); background: #fff; border-radius: 12px; padding: 11px 13px; margin-bottom: 8px; font: inherit; cursor: pointer; }
        .opt b { display: block; font-size: 14px; }
        .opt span { display: block; font-size: 12px; color: #6b7280; margin-top: 2px; }
        .opt[aria-checked="true"] { border: 2px solid var(--accent, #1d4ed8); background: #fffbea; }
        .sound-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 2px 2px; font-size: 13px; color: #374151; border-top: 1px solid #f1f3f5; margin-top: 4px; }
        .sound-row button { border: 1px solid var(--line); background: #fff; border-radius: 999px; padding: 5px 12px; font-size: 14px; cursor: pointer; }
    </style>
</head>
<body class="field-app field-chat">
    <div class="app field-shell">
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
        <header class="field-header">
            <div class="top">
                <a class="back field-back" href="{{ route('communication.index') }}" aria-label="목록">‹</a>
                <div>
                    <h1>{{ $roomLabel ?? $room->name }}</h1>
                    <div class="sub">
                        <span id="online-dot" class="dot" hidden></span>
                        <span id="sub-text">{{ $typeLabel }} · {{ __(':n명', ['n' => $membersCount]) }}</span>
                    </div>
                </div>
                <div style="display:flex;gap:6px;align-items:center">
                    {{-- 이 방 알림 — 🔔 모든 글 / @ 부를 때만 / 🔕 끄기. 누르면 고르는 시트가 뜬다. --}}
                    <button class="peo" type="button" id="btn-notify" aria-label="{{ __('이 방 알림') }}">{{ ['all' => '🔔', 'mentions' => '@', 'none' => '🔕'][$notifyLevel] ?? '🔔' }}</button>
                    <button class="peo" type="button" id="btn-members">{{ __('참여자') }}</button>
                    @if($canManageRoom)
                        {{-- 대화가 오간 방은 지워지지 않고 보관으로 내려간다 — 기록이 증거이기 때문이다. --}}
                        <form method="POST" action="{{ route('communication.room.destroy', ['room' => $room]) }}"
                              onsubmit="return confirm('이 방을 정리할까요?\n대화가 오간 방은 삭제되지 않고 보관으로 내려갑니다(기록은 남습니다).')">
                            @csrf
                            @method('DELETE')
                            <button class="peo" type="submit" style="color:#b91c1c">{{ __('방 정리') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </header>

        <main class="field-content" id="thread"></main>

        <section class="composer" aria-label="{{ __('메시지 입력') }}">
            @if($canPostTopLevel)
                <form id="composer-form" method="POST" action="{{ route('communication.store', ['room' => $room]) }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="parent_id" id="parent-id" value="">
                    {{-- 답장 대상 — 무엇에 답하는 중인지 보이지 않으면 엉뚱한 곳에 달린다. --}}
                    <div class="replying" id="replying" hidden>
                        <span id="replying-text"></span>
                        <button type="button" id="replying-cancel" aria-label="답장 취소">×</button>
                    </div>
                    @if($room->type === 'site_announcement')
                        <input type="text" name="title" maxlength="255" placeholder="공지 제목"
                               style="border:1px solid var(--line);border-radius:12px;padding:9px 12px;width:100%;box-sizing:border-box;margin-bottom:8px;font:inherit">
                    @endif
                    @if($canPostUrgent)
                        {{-- 긴급은 알림을 꺼 둔 사람에게도 울린다. 그래서 켤 때마다 그 사실을 보여준다. --}}
                        <input type="hidden" name="urgent" id="urgent" value="0">
                        <div class="urgent-row">
                            <button type="button" class="urgent-chip" id="btn-urgent" aria-pressed="false">🚨 {{ __('긴급') }}</button>
                            <span class="hint" id="urgent-hint" style="padding:0" hidden>{{ __('알림을 꺼 둔 사람에게도 울립니다.') }}</span>
                        </div>
                    @endif
                    <div class="mention-pop" id="mention-pop" hidden></div>
                    <div class="cbar">
                        <button class="plus" type="button" id="btn-file" aria-label="파일 첨부">＋</button>
                        @if($aiAvailable)
                            {{-- 규칙("@AI 라고 쓰세요")을 외우게 하지 않는다 — 버튼이 대신 써 준다. --}}
                            <button class="aibtn" type="button" id="btn-ai" aria-label="AI 에게 묻기" title="AI 에게 묻기">AI</button>
                        @endif
                        <textarea name="body" id="body" maxlength="4000" rows="1"
                                  placeholder="{{ $room->type === 'site_announcement' ? '공지 내용' : '메시지 입력' }}"></textarea>
                        <button class="send" type="submit" id="btn-send">{{ __('전송') }}</button>
                    </div>
                    <input type="file" name="files[]" id="files" multiple
                           accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.heic">
                    <div class="picked" id="picked"></div>
                    @if($room->type !== 'direct')
                        <div class="hint">{{ __('사진·영수증·도면을 올리면 AI 가 읽고 재무·장비·문서함으로 보냅니다.') }}</div>
                    @endif
                    @if($aiAvailable)
                        <div class="hint">{{ __('[AI] 를 누르고 물어보세요 — 공정·물량·제출물·자재·장비·문서를 대신 찾아 답합니다(볼 수 있는 것만).') }}</div>
                    @endif
                </form>
            @else
                {{-- 공지 전용 방이라도 답글은 쓸 수 있다 — 안내만 하고 길을 막아 두면 거짓말이 된다. --}}
                <form id="composer-form" method="POST" action="{{ route('communication.store', ['room' => $room]) }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="parent_id" id="parent-id" value="">
                    <div class="replying" id="replying" hidden>
                        <span id="replying-text"></span>
                        <button type="button" id="replying-cancel" aria-label="답장 취소">×</button>
                    </div>
                    <div class="mention-pop" id="mention-pop" hidden></div>
                    <div class="cbar">
                        <button class="plus" type="button" id="btn-file" aria-label="파일 첨부">＋</button>
                        @if($aiAvailable)
                            <button class="aibtn" type="button" id="btn-ai" aria-label="AI 에게 묻기" title="AI 에게 묻기">AI</button>
                        @endif
                        <textarea name="body" id="body" maxlength="4000" rows="1" placeholder="공지의 [답글] 을 눌러 답을 남겨 주세요"></textarea>
                        <button class="send" type="submit" id="btn-send">{{ __('전송') }}</button>
                    </div>
                    <input type="file" name="files[]" id="files" multiple
                           accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.heic">
                    <div class="picked" id="picked"></div>
                    <div class="hint">{{ __('이 방은 공지 전용입니다 — 새 글은 관리자만 쓰고, 각 공지에는 누구나 답글을 달 수 있습니다.') }}</div>
                </form>
            @endif
        </section>
    </div>

    <div class="sheet-back" id="sheet-back"></div>
    <div class="sheet" id="sheet">
        <h2>{{ __('참여자') }} <span id="sheet-count" style="color:#6b7280;font-weight:400"></span></h2>
        <div id="sheet-list"></div>
    </div>

    {{-- 이 방 알림 — 방마다 고른다. 시끄러운 방은 줄이고, 지시가 오가는 방은 다 받는다. --}}
    <div class="sheet" id="notify-sheet" role="dialog" aria-label="{{ __('이 방 알림') }}">
        <h2>{{ __('이 방 알림') }}</h2>
        <div role="radiogroup">
            <button type="button" class="opt" role="radio" data-level="all" aria-checked="{{ $notifyLevel === 'all' ? 'true' : 'false' }}">
                <b>🔔 {{ __('모든 글') }}</b><span>{{ __('새 글이 올라올 때마다 울립니다.') }}</span>
            </button>
            <button type="button" class="opt" role="radio" data-level="mentions" aria-checked="{{ $notifyLevel === 'mentions' ? 'true' : 'false' }}">
                <b>@ {{ __('나를 부를 때만') }}</b><span>{{ __('@내 이름 · @모두 · 내 글에 달린 답글만 울립니다.') }}</span>
            </button>
            <button type="button" class="opt" role="radio" data-level="none" aria-checked="{{ $notifyLevel === 'none' ? 'true' : 'false' }}">
                <b>🔕 {{ __('끄기') }}</b><span>{{ __('울리지 않습니다. 🚨 긴급 글만 예외입니다.') }}</span>
            </button>
        </div>
        {{-- 새 글이 오면 소리로 알린다. 앱을 보고 있을 때만 울리는 소리다 —
             꺼져 있을 때의 알림음은 휴대폰 설정이 정한다(웹은 못 바꾼다). --}}
        <div class="sound-row">
            <span>{{ __('앱을 보고 있을 때 소리') }}</span>
            <button type="button" id="btn-sound">🔔</button>
        </div>
    </div>

<script>
    // 화면 안의 글도 서버와 같은 사전을 읽는다. 블레이드는 __(), 여기서는 t().
    // 사전이 두 벌이면 한쪽만 번역되는 사고가 난다.
    const TR = @json(\App\Support\AppLocale::dictionary());
    function t(s) { return (TR && TR[s]) || s; }

/**
 * 대화 화면 — 서버가 주는 t('메시지 목록') 만 받아 그린다.
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

    var notifyUrl = '{{ route('communication.notify', ['room' => $room], false) }}';
    var focusId = {{ (int) $focusId }};
    var myName = @json($employee?->name ?? $user->name);
    // "@모두" 로 치는 말 — 서버가 알림을 보낼 때 읽는 목록과 같은 한 벌이다.
    var EVERYONE = @json(\App\Services\Communication\MentionResolver::EVERYONE);
    var CAN_CALL_EVERYONE = {{ $canCallEveryone ? 'true' : 'false' }};

    var lastId = 0;
    var lastDay = '';
    var cursor = null;      // 지난번 응답 시각 — 그 뒤로 바뀐 글(고침·지움)도 받는다
    var timer = null;
    var membersCache = [];
    var byId = {};          // 인용에 쓰려고 받은 메시지를 기억해 둔다
    var order = [];         // 화면에 그린 글 번호(오름차순) — 과거를 위에 붙일 때 다시 그리는 기준
    var hasOlder = false;

    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function escRe(s) { return s.replace(/[.*+?^$()|[\]\\{}]/g, '\\$&'); }

    /** 글 본문 — "@이름" 은 강조한다. 이름 목록은 서버가 알림을 보낸 사람들 그대로다. */
    function bodyHtml(m) {
        var html = esc(m.body);
        if (m.removed) return html;
        var names = (m.mentions || []).slice();
        if (m.mentionEveryone) names = names.concat(EVERYONE);
        if (!names.length) return html;
        // 긴 이름부터 — "@김철수" 안의 "@김철" 이 따로 칠해지지 않게.
        var alts = names.map(function (n) { return escRe(esc('@' + n)); })
            .sort(function (a, b) { return b.length - a.length; });
        return html.replace(new RegExp('(' + alts.join('|') + ')', 'gi'), '<span class="mention">$1</span>');
    }
    function urgentTag(m) { return m.priority === 'urgent' && !m.removed ? '<span class="urgent-tag">' + t('🚨 긴급') + '</span>' : ''; }
    function initials(name) {
        var n = (name || '').trim();
        if (!n) return '?';
        return /[가-힣]/.test(n) ? n.slice(-2) : n.slice(0, 2).toUpperCase();
    }
    function dayLabel(iso) {
        if (!iso) return '';
        var d = new Date(iso + 'T00:00:00');
        var days = [t('일'), t('월'), t('화'), t('수'), t('목'), t('금'), t('토')];
        return (d.getMonth() + 1) + t('월 ') + d.getDate() + t('일 ') + days[d.getDay()] + t('요일');
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
        return '<div class="notice-card ' + (ai ? 'ai' : '') + (m.priority === 'urgent' ? ' urgent' : '') + '" id="message-' + m.id + '">' +
            '<b>' + esc(m.title || (ai ? '🤖 AI' : t('공지'))) + '</b>' +
            urgentTag(m) + quoteHtml(m) + bodyHtml(m) + filesHtml(m.files) +
            (m.removed ? '' : '<div class="tools" style="margin-top:8px"><button type="button" onclick="window.Chat.reply(' + m.id + t(')">답글</button>') +
                (m.canRemove ? '<button type="button" onclick="window.Chat.remove(' + m.id + t(')">삭제</button>') : '') + '</div>') +
            '</div>';
    }

    /** 무엇에 답한 글인지 한 줄로 — 답글이 어디에 달린 건지 모르면 대화가 엉킨다. */
    function quoteHtml(m) {
        if (!m.parentId) return '';
        var parent = byId[m.parentId];
        var who = parent ? parent.sender : '';
        var text = parent ? (parent.body || '') : t('(원본 메시지)');
        return '<div class="quote">↩ ' + esc(who ? who + ': ' : '') + esc(text.slice(0, 60)) + '</div>';
    }

    function bubbleHtml(m) {
        var tools = '';
        if (!m.removed) {
            tools = '<div class="tools">' +
                '<button type="button" onclick="window.Chat.reply(' + m.id + t(')">답글</button>') +
                (m.canEdit ? '<button type="button" onclick="window.Chat.edit(' + m.id + t(')">수정</button>') : '') +
                (m.canRemove ? '<button type="button" onclick="window.Chat.remove(' + m.id + t(')">삭제</button>') : '') +
                '</div>';
        }

        var stamp = '<span class="stamp">' + (m.edited ? t('<span class="edited">수정됨 </span>') : '') + esc(m.sentAt || '') + '</span>';
        var bubble = '<div class="bubble' + (m.removed ? ' gone' : '') + (m.priority === 'urgent' ? ' urgent' : '') + '">' +
            urgentTag(m) + quoteHtml(m) + bodyHtml(m) + '</div>';
        var body = m.removed ? bubble : bubble + filesHtml(m.files);

        if (m.mine) {
            return '<div class="row mine" id="message-' + m.id + '" data-body="' + esc(m.body) + '">' +
                '<div class="stack"><div class="bundle">' + stamp + body + '</div>' + tools + '</div></div>';
        }

        return '<div class="row' + (m.mentionsMe ? ' called' : '') + '" id="message-' + m.id + '">' +
            '<div class="face">' + esc(initials(m.sender)) + '</div>' +
            '<div class="stack"><div class="who">' + esc(m.sender) + '</div>' +
            '<div class="bundle">' + body + stamp + '</div>' + tools + '</div></div>';
    }

    function htmlFor(m) {
        var isNotice = m.kind === 'announcement' || m.kind === 'system' || m.kind === 'attendance_alert';
        return isNotice ? noticeHtml(m) : bubbleHtml(m);
    }

    function dayHtml(m) {
        if (!m.sentOn || m.sentOn === lastDay) return '';
        lastDay = m.sentOn;
        return '<div class="day"><span>' + esc(dayLabel(m.sentOn)) + '</span></div>';
    }

    function olderHtml() {
        return hasOlder ? '<div class="older" id="older"><button type="button" onclick="window.Chat.older()">' + t('이전 대화 더 보기') + '</button></div>' : '';
    }

    function render(m) {
        byId[m.id] = m;
        var existing = document.getElementById('message-' + m.id);

        if (existing) {                       // 고쳐지거나 지워진 글 — 제자리에서 바꾼다
            existing.outerHTML = htmlFor(m);
            return;
        }

        // 아직 불러오지 않은 옛 글이 바뀐 것 — 아래에 붙이면 순서가 뒤집힌다.
        // 위로 올려 과거를 불러올 때 바뀐 모습 그대로 함께 온다.
        if (order.length && m.id < order[order.length - 1]) return;

        order.push(m.id);
        thread.insertAdjacentHTML('beforeend', dayHtml(m) + htmlFor(m));
    }

    /** 과거를 위에 붙일 때 — 날짜 줄이 어긋나지 않게 통째로 다시 그리고, 보던 자리는 지킨다. */
    function rebuild() {
        var fromBottom = document.body.scrollHeight - window.scrollY;
        lastDay = '';
        thread.innerHTML = olderHtml() + order.map(function (id) { return dayHtml(byId[id]) + htmlFor(byId[id]); }).join('');
        window.scrollTo(0, document.body.scrollHeight - fromBottom);
    }

    function paintOlder() {
        var el = document.getElementById('older');
        if (hasOlder && !el) thread.insertAdjacentHTML('afterbegin', olderHtml());
        if (!hasOlder && el) el.remove();
    }

    /** 알림에서 눌러 들어온 글로 데려가서 잠깐 빛나게 한다. */
    function flashFocus() {
        var el = focusId ? document.getElementById('message-' + focusId) : null;
        if (!el) return false;
        el.scrollIntoView({ block: 'center' });
        el.classList.add('flash');
        setTimeout(function () { el.classList.remove('flash'); }, 2600);
        return true;
    }

    function paintMembers(members, onlineCount) {
        membersCache = members || [];
        var dot = document.getElementById('online-dot');
        var sub = document.getElementById('sub-text');
        if (onlineCount > 0) {
            dot.hidden = false;
            sub.textContent = '{{ $typeLabel }} · ' + membersCache.length + t('명 · ') + onlineCount + t('명 접속 중');
        } else {
            dot.hidden = true;
            sub.textContent = '{{ $typeLabel }} · ' + membersCache.length + t('명');
        }
    }

    function openMembers() {
        var list = document.getElementById('sheet-list');
        document.getElementById('sheet-count').textContent = membersCache.length + t('명');
        list.innerHTML = membersCache.map(function (m) {
            // AI 도 참여자 줄에 선다 — 목록에 없으면 부를 수 있는 줄 아무도 모른다.
            var face = m.bot ? '<div class="face bot">🤖</div>' : '<div class="face">' + esc(initials(m.name)) + '</div>';
            var right = m.bot
                ? '<div class="st on">● ' + esc(m.lastSeen || t('대기 중')) + '</div>'
                : '<div class="st ' + (m.online ? 'on' : '') + '">' + (m.online ? t('● 접속 중') : esc(m.lastSeen || t('접속 기록 없음'))) + '</div>';

            return '<div class="mem">' + face + '<div><div class="nm">' + esc(m.name) + '</div></div>' + right + '</div>';
        }).join('') || t('<div style="color:#6b7280;font-size:13px">아직 참여자가 없습니다. 관리 화면에서 "직원 동기화" 를 눌러 주세요.</div>');
        openSheet('sheet');
    }

    // 아래에서 올라오는 시트는 한 번에 하나 — 뒤를 누르면 모두 닫힌다.
    function openSheet(id) {
        closeSheets();
        document.getElementById(id).style.display = 'block';
        document.getElementById('sheet-back').style.display = 'block';
    }
    function closeSheets() {
        Array.prototype.forEach.call(document.querySelectorAll('.sheet'), function (s) { s.style.display = 'none'; });
        document.getElementById('sheet-back').style.display = 'none';
    }
    document.getElementById('btn-members').addEventListener('click', function () {
        fetch(membersUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { paintMembers(d.members, (d.members || []).filter(function (m) { return m.online; }).length); openMembers(); })
            .catch(openMembers);
    });
    document.getElementById('sheet-back').addEventListener('click', closeSheets);

    // ── 이 방 알림 ──────────────────────────────────────────────────
    var NOTIFY_ICON = { all: '🔔', mentions: '@', none: '🔕' };
    document.getElementById('btn-notify').addEventListener('click', function () { openSheet('notify-sheet'); });
    Array.prototype.forEach.call(document.querySelectorAll('#notify-sheet .opt'), function (opt) {
        opt.addEventListener('click', function () {
            var level = opt.getAttribute('data-level');
            fetch(notifyUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ level: level })
            }).then(function (r) { return r.json().then(function (d) { if (!r.ok || !d.success) throw new Error(d.error || ''); return d; }); })
                .then(function () {
                    Array.prototype.forEach.call(document.querySelectorAll('#notify-sheet .opt'), function (o) {
                        o.setAttribute('aria-checked', o === opt ? 'true' : 'false');
                    });
                    document.getElementById('btn-notify').textContent = NOTIFY_ICON[level] || '🔔';
                    setTimeout(closeSheets, 250);
                })
                .catch(function (e) { alert(e.message || t('알림 설정을 바꾸지 못했습니다.')); });
        });
    });

    // ── 내 글 손보기 ────────────────────────────────────────────────
    window.Chat = {
        /** 위로 올려 더 오래된 대화 — 두 달 전 지시도 "있었는데 못 찾는" 것이 되지 않게. */
        older: function () {
            if (!order.length) return;
            var btn = document.querySelector('#older button');
            if (btn) { btn.disabled = true; btn.textContent = t('불러오는 중…'); }

            fetch(streamUrl + '?before=' + order[0], { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data) throw new Error();
                    var fresh = (data.messages || []).filter(function (m) { return order.indexOf(m.id) < 0; });
                    fresh.forEach(function (m) { byId[m.id] = m; });
                    order = fresh.map(function (m) { return m.id; }).concat(order);
                    hasOlder = !!data.hasOlder;
                    rebuild();
                })
                .catch(function () { if (btn) { btn.disabled = false; btn.textContent = t('이전 대화 더 보기'); } });
        },
        reply: function (id) {
            var target = byId[id];
            var box = document.getElementById('replying');
            var field = document.getElementById('parent-id');
            if (!box || !field || !target) return;

            // 답글은 원글에만 달린다 — 답글의 답글까지 허용하면 대화가 계단이 된다.
            field.value = target.parentId ? target.parentId : id;
            var shown = byId[field.value] || target;
            document.getElementById('replying-text').textContent =
                '↩ ' + (shown.sender ? shown.sender + ': ' : '') + (shown.body || '').slice(0, 50);
            box.hidden = false;

            var body = document.getElementById('body');
            if (body) body.focus();
        },
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

        /* ── 알림 소리 ────────────────────────────────────────────────────────
         * 앱을 보고 있을 때 새 글이 오면 t('깨똑깨똑') 하고 알린다.
         *
         * 소리를 두 갈래로 둔 이유: 휴대폰에 한국어 음성이 깔려 있으면 실제로
         * t('깨똑깨똑') 이라고 말하고, 없으면 나무 두드리는 소리 두 번으로 대신한다.
         * 음성이 없는 기기에서 아무 소리도 안 나면 t('알림이 고장났다') 가 된다.
         *
         * 브라우저는 사용자가 화면을 한 번 만지기 전에는 소리를 막는다(자동재생 정책).
         * 그래서 첫 터치에서 오디오를 깨워 둔다 — 이게 없으면 t('소리를 켰는데 안 난다').
         */
        window.ChatChime = (function () {
            var KEY = 'chat-sound';
            var on = (function () { try { return localStorage.getItem(KEY) !== 'off'; } catch (e) { return true; } })();
            var ctx = null;

            function unlock() {
                try {
                    if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
                    if (ctx.state === 'suspended') ctx.resume();
                } catch (e) {}
            }

            /** 나무 두드리는 소리 두 번 — 음성이 없을 때의 대역. */
            function knock() {
                try {
                    if (!ctx) unlock();
                    if (!ctx) return;
                    [0, 0.17].forEach(function (delay, i) {
                        var t = ctx.currentTime + delay;
                        var osc = ctx.createOscillator();
                        var gain = ctx.createGain();
                        osc.type = 'triangle';
                        osc.frequency.setValueAtTime(i ? 780 : 660, t);
                        osc.frequency.exponentialRampToValueAtTime(i ? 520 : 440, t + 0.11);
                        gain.gain.setValueAtTime(0.0001, t);
                        gain.gain.exponentialRampToValueAtTime(0.28, t + 0.012);
                        gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.13);
                        osc.connect(gain); gain.connect(ctx.destination);
                        osc.start(t); osc.stop(t + 0.15);
                    });
                } catch (e) {}
            }

            function speak() {
                try {
                    if (!('speechSynthesis' in window)) return false;
                    var voices = window.speechSynthesis.getVoices() || [];
                    var ko = voices.filter(function (v) { return (v.lang || '').toLowerCase().indexOf('ko') === 0; })[0];
                    if (!ko) return false;                       // 한국어 음성이 없으면 소리로 대신한다
                    var u = new SpeechSynthesisUtterance(t('깨똑깨똑'));
                    u.voice = ko; u.lang = 'ko-KR'; u.rate = 1.15; u.volume = 0.9;
                    window.speechSynthesis.cancel();
                    window.speechSynthesis.speak(u);
                    return true;
                } catch (e) { return false; }
            }

            function paint() {
                var b = document.getElementById('btn-sound');
                if (b) { b.textContent = on ? '🔔' : '🔕'; b.title = on ? t('알림 소리 켜짐') : t('알림 소리 꺼짐'); }
            }

            document.addEventListener('DOMContentLoaded', function () {
                paint();
                var b = document.getElementById('btn-sound');
                if (b) {
                    b.addEventListener('click', function () {
                        on = !on;
                        try { localStorage.setItem(KEY, on ? 'on' : 'off'); } catch (e) {}
                        paint();
                        if (on) { unlock(); ring(1); }           // 켜는 순간 한 번 들려준다
                    });
                }
                ['touchstart', 'click', 'keydown'].forEach(function (ev) {
                    document.addEventListener(ev, unlock, { once: true, passive: true });
                });
                // 음성 목록은 늦게 채워지는 브라우저가 있다 — 미리 한 번 깨워 둔다.
                try { window.speechSynthesis && window.speechSynthesis.getVoices(); } catch (e) {}
            });

            return {
                ring: function (count) {
                    if (!on) return;
                    if (!speak()) knock();
                    // 다른 화면을 보고 있으면 제목에도 표시해 둔다.
                    if (document.hidden) {
                        document.title = '(' + count + ') ' + document.title.replace(/^\(\d+\)\s*/, '');
                    }
                },
            };
        })();

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) document.title = document.title.replace(/^\(\d+\)\s*/, '');
        });

    function poll() {
        if (document.hidden) { schedule(15000); return; }

        var first = lastId === 0;
        var url = streamUrl + '?after=' + lastId +
            (first && focusId ? '&focus=' + focusId : '') +
            (cursor ? '&changed=' + encodeURIComponent(cursor) : '');

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { schedule(30000); return; }

                var atBottom = (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 140);

                // 새로 도착한 남의 글만 센다 — 첫 진입의 과거 글이나 내 글, 수정으로 인한
                // 재그리기에 소리가 나면 그날로 음소거된다.
                var fresh = (data.messages || []).filter(function (m) {
                    return !first && !m.mine && m.id > lastId;
                }).length;

                (data.messages || []).forEach(render);
                paintMembers(data.members, data.onlineCount);
                if (first) { hasOlder = !!data.hasOlder; paintOlder(); }

                if (fresh > 0) { window.ChatChime && window.ChatChime.ring(fresh); }

                // 처음 열 때: 알림에서 온 글이 있으면 그 글로, 아니면 맨 아래(가장 최근)로.
                if (first && flashFocus()) {
                    // 그 글에 머문다
                } else if (data.messages && data.messages.length && (atBottom || first)) {
                    window.scrollTo(0, document.body.scrollHeight);
                }
                if (data.lastId) lastId = data.lastId;
                if (data.cursor) cursor = data.cursor;
                schedule(data.nextPollMs);
            })
            .catch(function () { schedule(30000); });
    }

    document.addEventListener('visibilitychange', function () { if (!document.hidden) { clearTimeout(timer); poll(); } });
    window.addEventListener('pagehide', function () { clearTimeout(timer); });

    // ── 입력창 ─────────────────────────────────────────────────────
    var cancel = document.getElementById('replying-cancel');
    if (cancel) {
        cancel.addEventListener('click', function () {
            document.getElementById('parent-id').value = '';
            document.getElementById('replying').hidden = true;
        });
    }

    var form = document.getElementById('composer-form');
    if (form) {
        var body = document.getElementById('body');
        var files = document.getElementById('files');
        var picked = document.getElementById('picked');

        document.getElementById('btn-file').addEventListener('click', function () { files.click(); });

        // [AI] — "@AI" 를 대신 써 준다. 이미 부른 뒤라면 두 번 붙이지 않는다.
        var ai = document.getElementById('btn-ai');
        if (ai) {
            ai.addEventListener('click', function () {
                if (!/@\s*(ai|에이아이)\b/i.test(body.value)) {
                    body.value = '@AI ' + body.value.replace(/^\s+/, '');
                }
                body.focus();
                body.setSelectionRange(body.value.length, body.value.length);
                body.dispatchEvent(new Event('input'));
            });
        }
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
            if (!phone && e.key === 'Enter' && !e.shiftKey && pop.hidden) { e.preventDefault(); form.requestSubmit(); }
            if (!pop.hidden && e.key === 'Escape') { pop.hidden = true; }
        });

        // ── @ 부르기 ───────────────────────────────────────────────
        // "@" 를 치면 방 사람 목록이 뜬다. 고르면 "@이름 " 을 대신 써 줄 뿐 — 누구를
        // 불렀는지는 서버가 글자에서 읽는다(부르는 길이 둘이면 한쪽만 알림이 간다).
        var pop = document.getElementById('mention-pop');

        function mentionToken() {
            var pos = body.selectionStart || 0;
            var m = body.value.slice(0, pos).match(/(^|\s)@([^\s@]*)$/);
            return m ? { start: pos - m[2].length - 1, end: pos, q: m[2].toLowerCase() } : null;
        }

        function suggestions(q) {
            var list = membersCache.filter(function (p) {
                return p.name && p.name !== myName && p.name.toLowerCase().indexOf(q) >= 0;
            }).map(function (p) {
                return { name: p.bot ? 'AI' : p.name, label: p.bot ? '🤖 ' + t('AI 도우미') : p.name, hint: p.bot ? t('질문에 답합니다') : '' };
            });
            // "@모두" 는 보는 사람의 말로 쓴다(@all · @todos) — 서버는 셋 다 알아듣는다.
            var everyoneWord = t('모두');
            if (CAN_CALL_EVERYONE && (everyoneWord.toLowerCase().indexOf(q) === 0 || '모두'.indexOf(q) === 0)) {
                list.unshift({ name: everyoneWord, label: '@' + everyoneWord, hint: t('이 방 전원을 부릅니다') });
            }
            return list.slice(0, 6);
        }

        function paintPop() {
            var tok = mentionToken();
            var list = tok ? suggestions(tok.q) : [];
            if (!list.length) { pop.hidden = true; return; }
            pop.innerHTML = list.map(function (s) {
                return '<button type="button" data-name="' + esc(s.name) + '">' + esc(s.label) +
                    (s.hint ? ' <small>' + esc(s.hint) + '</small>' : '') + '</button>';
            }).join('');
            pop.hidden = false;
        }

        pop.addEventListener('mousedown', function (e) { e.preventDefault(); });   // 입력창 포커스를 뺏지 않게
        pop.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-name]');
            var tok = mentionToken();
            if (!b || !tok) return;
            var insert = '@' + b.getAttribute('data-name') + ' ';
            body.value = body.value.slice(0, tok.start) + insert + body.value.slice(tok.end);
            var caret = tok.start + insert.length;
            body.focus();
            body.setSelectionRange(caret, caret);
            pop.hidden = true;
            body.dispatchEvent(new Event('input'));
        });
        body.addEventListener('input', paintPop);
        body.addEventListener('click', paintPop);
        body.addEventListener('blur', function () { setTimeout(function () { pop.hidden = true; }, 150); });

        // ── 🚨 긴급 ────────────────────────────────────────────────
        var urgentBtn = document.getElementById('btn-urgent');
        if (urgentBtn) {
            urgentBtn.addEventListener('click', function () {
                var on = urgentBtn.getAttribute('aria-pressed') !== 'true';
                urgentBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
                document.getElementById('urgent').value = on ? '1' : '0';
                document.getElementById('urgent-hint').hidden = !on;
            });
        }
    }

    poll();
})();
</script>
<script>
    (function () {
        var composer = document.querySelector('.composer');
        if (composer && window.ResizeObserver) {
            new ResizeObserver(function () {
                document.documentElement.style.setProperty('--composer-height', composer.getBoundingClientRect().height + 'px');
            }).observe(composer);
        }
    })();
    </script>
</body>
</html>

{{--
    사이드 앱에서 ERP 로 돌아가는 문.

    출퇴근앱·영수증앱처럼 ERP 에서 기능 하나만 떼어 온 화면들은 홈 화면 아이콘으로
    바로 열리기 때문에, 한 번 들어가면 나가는 길이 없다. 관리자는 브라우저 주소창을
    직접 고쳐야 ERP 로 돌아갔다.

    <b>앞으로 만드는 모든 사이드 앱은 &lt;body&gt; 맨 위에 이 한 줄을 넣는다:</b>

        @include('partials.erp-home')

    앱마다 따로 만들지 않는 이유는 분명하다 — 복사해 두면 앱이 늘어날 때마다 하나씩
    빠지고, 빠진 앱에서는 아무도 그 사실을 말해 주지 않는다.

    ── 누구에게 보이나 ────────────────────────────────────────────────
    ERP 가 자기 집인 사람에게만 보인다(User::landingPath() 가 '/' 인 사람).
    작업자·반장에게는 보이지 않는다. 그들에게 ERP 는 자기 화면이 아니고, 눌러서
    회사 전체 화면이 뜨면 뭘 잘못 눌렀다고 생각하고 앱을 지운다 — 설치를 부탁하는
    첫날에 그걸 겪으면 두 번째 기회는 없다(landingPath 가 존재하는 이유와 같다).

    판정을 여기서 새로 만들지 않고 landingPath() 를 그대로 쓴다. 규칙이 두 벌이면
    "로그인하면 앱으로 보내는데 앱에서는 ERP 로 가라고 하는" 모순이 생긴다.
--}}
@auth
    @if (auth()->user()->landingPath() === '/')
        {{-- 회사 이름은 붙이지 않는다. 바로 아래 머리띠에 이미 있고, 회사 이름이
             ERP 로 끝나는 배포에서는 "ERP ERP" 가 된다. 세 언어 모두 이대로 읽힌다. --}}
        <a class="erp-home" href="{{ route('smart-company.index') }}">
            <span aria-hidden="true">←</span> ERP
        </a>
        <style>
            /* 앱마다 CSS 가 따로라 여기서 자기 것만 정의한다(변수는 있으면 쓰고 없으면 기본값). */
            .erp-home {
                display: flex; align-items: center; justify-content: center; gap: 6px;
                padding: calc(9px + env(safe-area-inset-top)) 12px 9px;
                background: rgba(0, 0, 0, .85); color: #fff; text-decoration: none;
                font-family: inherit; font-size: 12.5px; font-weight: 700; letter-spacing: -.01em;
                position: relative; z-index: 30;
            }
            .erp-home span { font-size: 14px; opacity: .8; }
            .erp-home:active { background: #000; }
            @media print { .erp-home { display: none; } }
        </style>
    @endif
@endauth

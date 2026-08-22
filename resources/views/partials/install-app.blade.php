{{--
    "홈 화면에 추가" 안내. 게이트 화면과 작업자 앱이 같은 것을 쓴다.

    필요한 변수:
      $installLang  현재 언어 코드(ko|en|es)

    설계 하나 — 처음 열자마자 띄우지 않는다. 아무것도 해 보지 않은 사람에게 설치를
    권하면 대부분 닫는다. 출퇴근을 한 번 찍고 나서, 그러니까 이 화면이 쓸모 있다는 걸
    안 다음에 올라온다. 부르는 쪽에서 window.AppInstall.offer() 를 호출한다.

    설계 둘 — 이미 홈 화면에서 연 사람에게는 영원히 안 뜬다. 닫은 사람에게도 안 뜬다.
    현장에서 같은 안내를 두 번 보는 것만큼 앱을 미워하게 만드는 건 없다.

    스타일이 이 파일 안에 다 들어 있는 이유 — 게이트(남색·흰 카드)와 작업자 앱(종이색)의
    디자인이 완전히 다르다. 두 곳에 얹히려면 남의 CSS 에 기대지 않아야 한다.
--}}
@php
    $installDict = \App\Support\WorkerLang::install();
    $installLang = \App\Support\WorkerLang::resolve($installLang ?? null);
@endphp

<div id="app-install" hidden>
    <div class="di-back" data-di-dismiss></div>
    <div class="di-sheet" role="dialog" aria-modal="true" aria-labelledby="di-title">
        <div class="di-head">
            <img class="di-icon" src="{{ asset('images/worker-icon-192.png') }}" alt="" width="52" height="52">
            <div>
                <b id="di-title"></b>
                <p id="di-body"></p>
            </div>
        </div>

        {{-- 안드로이드: 버튼 하나로 끝난다. --}}
        <div class="di-auto" hidden>
            <button type="button" class="di-go" id="di-go"></button>
            <button type="button" class="di-later" data-di-dismiss></button>
        </div>

        {{-- 아이폰: 사람이 직접 눌러야 해서 어디를 누르는지 그림으로 짚는다. --}}
        <div class="di-ios" hidden>
            <div class="di-ios-h" id="di-ios-title"></div>
            <ol class="di-steps">
                <li><span class="di-n">1</span><span class="di-t" id="di-s1"></span>
                    <svg class="di-g" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3.6"/><path d="m8.2 7.4 3.8-3.8 3.8 3.8"/><path d="M5.5 12.4v6.1a1.9 1.9 0 0 0 1.9 1.9h9.2a1.9 1.9 0 0 0 1.9-1.9v-6.1"/></svg>
                </li>
                <li><span class="di-n">2</span><span class="di-t" id="di-s2"></span>
                    <svg class="di-g" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M12 8.6v6.8M8.6 12h6.8"/></svg>
                </li>
                <li><span class="di-n">3</span><span class="di-t" id="di-s3"></span></li>
            </ol>
            <p class="di-note" id="di-safari"></p>
            <button type="button" class="di-later" data-di-dismiss></button>
        </div>
    </div>
</div>

<style>
    #app-install { position: fixed; inset: 0; z-index: 9000; font-family: inherit; }
    #app-install[hidden] { display: none; }
    .di-back { position: absolute; inset: 0; background: rgba(10,10,8,.55); }
    .di-sheet {
        position: absolute; left: 0; right: 0; bottom: 0;
        background: #FFFFFF; color: #191919;
        border-radius: 22px 22px 0 0; padding: 22px 20px calc(20px + env(safe-area-inset-bottom));
        max-width: 520px; margin: 0 auto;
        box-shadow: 0 -14px 40px rgba(0,0,0,.3);
        animation: di-up .26s cubic-bezier(.2,.8,.3,1);
    }
    @keyframes di-up { from { transform: translateY(100%); } to { transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) { .di-sheet { animation: none; } }

    .di-head { display: flex; gap: 14px; align-items: flex-start; }
    .di-icon { border-radius: 13px; flex-shrink: 0; }
    .di-head b { display: block; font-size: 1.12rem; line-height: 1.3; font-weight: 900; }
    .di-head p { margin: 6px 0 0; font-size: .93rem; line-height: 1.5; color: #767676; }

    .di-go {
        width: 100%; margin-top: 18px; border: 0; border-radius: 12px;
        background: #FEE500; color: rgba(0,0,0,.85);
        padding: 18px; font-size: 1.12rem; font-weight: 900; font-family: inherit; cursor: pointer;
    }
    .di-later {
        width: 100%; margin-top: 10px; border: 0; background: none;
        color: #767676; padding: 13px; font-size: .93rem; font-weight: 700; font-family: inherit; cursor: pointer;
    }

    .di-ios-h { margin-top: 20px; font-size: .78rem; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; color: #B0B8C1; }
    .di-steps { list-style: none; margin: 12px 0 0; padding: 0; }
    .di-steps li { display: flex; align-items: center; gap: 11px; padding: 11px 0; border-bottom: 1px solid #EDEEF0; }
    .di-steps li:last-child { border-bottom: 0; }
    .di-n {
        flex-shrink: 0; width: 25px; height: 25px; border-radius: 50%;
        background: #FEE500; color: rgba(0,0,0,.85); font-size: .82rem; font-weight: 900;
        display: grid; place-items: center;
    }
    .di-t { flex: 1; font-size: .97rem; line-height: 1.45; }
    .di-t b { font-weight: 900; }
    .di-g { width: 24px; height: 24px; flex-shrink: 0; color: #191919; }
    .di-note { margin: 14px 0 0; font-size: .84rem; line-height: 1.5; color: #B26A00; background: #FFF4E0; border-radius: 10px; padding: 11px 13px; }
</style>

<script>
(function () {
    {{-- 문구에 <b> 가 들어 있다. 태그를 < 로 escape 해 넣어야 </script> 로 오해될 여지가 없다. --}}
    var DICT = @json($installDict, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    var lang = @json($installLang);
    var KEY = 'appInstallDismissed';

    var root = document.getElementById('app-install');
    var deferred = null;   // 안드로이드가 넘겨주는 설치 권한. 한 번만 쓸 수 있다.

    // 이미 홈 화면에서 연 사람. 안내할 것이 없다.
    function installed() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
            || window.navigator.standalone === true;
    }

    function dismissed() {
        try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; }
    }

    function remember() {
        try { localStorage.setItem(KEY, '1'); } catch (e) {}
    }

    // 사파리만 홈 화면 추가가 된다. 아이폰의 크롬·파이어폭스는 껍데기만 사파리라
    // 판별이 안 되므로, 아이폰이면 일단 안내하고 "사파리에서 열어야 한다"고 덧붙인다.
    function isIos() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function paint() {
        var T = DICT[lang] || DICT.ko;
        document.getElementById('di-title').textContent = T.title;
        document.getElementById('di-body').textContent = T.body;
        document.getElementById('di-go').textContent = T.install;
        document.getElementById('di-ios-title').textContent = T.iosTitle;
        // 문구 안의 <b> 는 우리가 쓴 것이다(사용자 입력이 아니다).
        document.getElementById('di-s1').innerHTML = T.iosStep1;
        document.getElementById('di-s2').innerHTML = T.iosStep2;
        document.getElementById('di-s3').innerHTML = T.iosStep3;
        document.getElementById('di-safari').innerHTML = T.iosSafari;
        Array.prototype.forEach.call(root.querySelectorAll('.di-later'), function (b) {
            b.textContent = T.later;
        });
    }

    function open(force) {
        if (installed()) return false;
        if (!force && dismissed()) return false;
        // 안드로이드가 아직 설치 권한을 안 넘겼고 아이폰도 아니면, 보여 줄 방법이 없다.
        if (!deferred && !isIos()) return false;

        paint();
        root.querySelector('.di-auto').hidden = !deferred;
        root.querySelector('.di-ios').hidden = !!deferred;
        root.hidden = false;
        return true;
    }

    function close(andRemember) {
        root.hidden = true;
        if (andRemember) remember();
    }

    // 서비스워커를 등록해 둔다. 크롬은 fetch 를 처리하는 워커가 없으면 아래
    // beforeinstallprompt 를 아예 주지 않는다 — 그러면 안드로이드에서 설치 버튼이
    // 영영 안 뜬다. 오류도 안 난다. 실패하면 조용히 넘어간다(아이폰 경로는 그대로 된다).
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
        });
    }

    window.addEventListener('beforeinstallprompt', function (ev) {
        ev.preventDefault();   // 브라우저 기본 배너를 막고 우리 시점에 띄운다.
        deferred = ev;
    });

    window.addEventListener('appinstalled', function () {
        deferred = null;
        remember();
        close(false);
    });

    document.getElementById('di-go').addEventListener('click', function () {
        if (!deferred) { close(true); return; }
        var p = deferred;
        deferred = null;             // 한 번 쓴 프롬프트는 다시 못 쓴다.
        close(false);
        p.prompt();
    });

    Array.prototype.forEach.call(root.querySelectorAll('[data-di-dismiss]'), function (el) {
        // 닫으면 기억한다 — 매번 다시 뜨는 안내는 앱을 미워하게 만든다.
        el.addEventListener('click', function () { close(true); });
    });

    window.AppInstall = {
        /** 쓸모를 한 번 보여 준 뒤 부른다. 띄웠으면 true. */
        offer: function () { return open(false); },
        /** 사람이 직접 "앱 설치"를 눌렀을 때. 닫았던 기억을 무시한다. */
        show: function () { return open(true); },
        /** 이미 홈 화면에서 열고 있는가 — 메뉴에서 설치 줄을 숨길 때 쓴다. */
        installed: installed,
        /** 부르는 화면이 자기 버튼에 쓸 현재 언어의 "홈 화면에 추가". */
        label: function () { return (DICT[lang] || DICT.ko).install; },
        setLang: function (code) { if (DICT[code]) { lang = code; if (!root.hidden) paint(); } },
    };
})();
</script>

{{--
    알림 — 머리에 종 하나.

    처음에는 큰 상자로 "알림을 켜면…" 이라고 설명했는데, 자리만 차지하고 지금 켜져
    있는지 꺼져 있는지는 알 수 없었다. 설명이 필요한 화면은 설명이 아니라 모양을
    고쳐야 한다.

    그래서 카카오톡처럼 <b>종 아이콘 하나</b>로 바꾼다:
      켜짐 🔔(파랑) / 꺼짐 🔕(회색) — 눌러서 켜고, 다시 눌러서 끈다.

    설계 하나: 열자마자 권한을 묻지 않는다. 브라우저는 한 번 거절당하면 다시 물을 수
    없고, 그러면 그 사람에게는 알림이 영영 안 간다. 종을 누를 때만 묻는다.
    설계 둘: 아직 안 켠 사람에게만 얇은 한 줄 안내가 뜨고, 닫으면 다시 안 뜬다.
    설계 셋: 서버에 열쇠가 없으면 종 자체를 만들지 않는다 — 눌러도 아무 일 없는
    버튼이 가장 나쁘다.

    부르는 화면에 <button id="push-bell"> 자리를 두면 여기서 그 버튼을 살린다.
--}}
<div id="push-strip" hidden style="display:flex;align-items:center;gap:8px;margin:8px 0;padding:8px 12px;background:#eff6ff;border-radius:10px;font-size:12.5px;color:#1e40af">
    <span style="flex:1">🔔 알림을 켜면 화면이 꺼져 있어도 새 메시지를 받습니다.</span>
    <button type="button" id="push-strip-go" style="border:0;background:#2563eb;color:#fff;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:800;cursor:pointer">켜기</button>
    <button type="button" id="push-strip-x" aria-label="닫기" style="border:0;background:none;color:#64748b;font-size:16px;cursor:pointer;line-height:1;padding:2px 4px">×</button>
</div>

<script>
(function () {
    var bell = document.getElementById('push-bell');
    var strip = document.getElementById('push-strip');
    var stripGo = document.getElementById('push-strip-go');
    var stripX = document.getElementById('push-strip-x');
    var meta = document.querySelector('meta[name="csrf-token"]');
    var csrf = meta ? meta.getAttribute('content') : '';
    var DISMISS_KEY = 'push-strip-dismissed';
    var publicKey = null;

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body || {})
        });
    }

    function toBytes(base64) {
        var padded = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(padded);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function remembered() {
        try { return window.localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
    }
    function remember() {
        try { window.localStorage.setItem(DISMISS_KEY, '1'); } catch (e) {}
    }

    /** 종의 얼굴을 지금 상태에 맞춘다 — 보면 바로 알 수 있게. */
    function paint(on) {
        if (!bell) return;
        bell.hidden = false;
        bell.textContent = on ? '🔔' : '🔕';
        bell.title = on ? '알림 켜짐 — 누르면 끕니다' : '알림 꺼짐 — 누르면 켭니다';
        bell.setAttribute('aria-label', bell.title);
        bell.style.opacity = on ? '1' : '.45';
        if (strip) strip.hidden = on || remembered();
    }

    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        // 아이폰은 홈 화면에 설치하기 전에는 알림 자체가 불가능하다 — 그 사실만 짧게.
        if (isIOS && !standalone && strip && !remembered()) {
            strip.hidden = false;
            strip.querySelector('span').textContent = '🔔 알림을 받으려면 공유 → "홈 화면에 추가" 로 설치해 주세요.';
            stripGo.hidden = true;
        }
        return;
    }

    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});

    function subscribe() {
        return navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.getSubscription().then(function (existing) {
                return existing || reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: toBytes(publicKey)
                });
            });
        }).then(function (sub) {
            var json = sub.toJSON();
            return post('{{ route('push.subscribe') }}', {
                endpoint: json.endpoint,
                keys: json.keys,
                contentEncoding: (PushManager.supportedContentEncodings || ['aes128gcm'])[0]
            });
        });
    }

    function unsubscribe() {
        return navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.getSubscription();
        }).then(function (sub) {
            if (!sub) return;
            var endpoint = sub.endpoint;

            return sub.unsubscribe().then(function () {
                return post('{{ route('push.unsubscribe') }}', { endpoint: endpoint });
            });
        });
    }

    function turnOn() {
        if (Notification.permission === 'denied') {
            alert('브라우저에서 이 사이트의 알림이 차단돼 있습니다.\n브라우저 설정 → 알림에서 허용으로 바꿔 주세요.');

            return;
        }

        Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') { paint(false); return; }
            subscribe().then(function () { paint(true); }).catch(function () {
                alert('알림을 켜지 못했습니다. 잠시 후 다시 시도해 주세요.');
            });
        });
    }

    function turnOff() {
        unsubscribe().then(function () { paint(false); }).catch(function () { paint(false); });
    }

    fetch('{{ route('push.key') }}', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (info) {
            if (!info || !info.available) return;  // 이 배포에는 알림이 설정돼 있지 않다 — 종을 감춘다.
            publicKey = info.publicKey;

            return navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.getSubscription();
            }).then(function (sub) {
                var on = Notification.permission === 'granted' && !!sub;
                paint(on);

                // 이미 허락은 했는데 이 기기 등록이 없다면 조용히 등록해 둔다
                // (기기를 바꾸거나 브라우저 데이터를 지우면 구독이 죽는다).
                if (Notification.permission === 'granted' && !sub) {
                    subscribe().then(function () { paint(true); }).catch(function () {});
                }

                if (bell) {
                    bell.addEventListener('click', function () {
                        if (bell.textContent === '🔔') { turnOff(); } else { turnOn(); }
                    });
                }
                if (stripGo) stripGo.addEventListener('click', turnOn);
                if (stripX) stripX.addEventListener('click', function () { remember(); strip.hidden = true; });
            });
        })
        .catch(function () {});
})();
</script>

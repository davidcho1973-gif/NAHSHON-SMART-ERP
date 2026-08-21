{{--
    알림 받기 — 화면이 꺼져 있어도 지시가 닿게.

    설계 하나: 열자마자 권한을 묻지 않는다. 브라우저는 한 번 거절당하면 다시 물을 수
    없고, 그러면 그 사람에게는 알림이 영영 안 간다. 사용자가 버튼을 누를 때만 묻는다.

    설계 둘: 이미 허락한 사람은 조용히 이 기기를 등록만 한다(폰을 바꾸거나 브라우저
    데이터를 지우면 구독이 죽는데, 본인은 알 길이 없다).

    설계 셋: 서버에 키가 없으면 버튼 자체를 만들지 않는다. 눌러도 아무 일 없는 버튼이
    가장 나쁘다.

    아이폰은 홈 화면에 설치한 뒤에만 알림을 받을 수 있다 — 그 경우 설치부터 안내한다.
--}}
<div id="push-optin" hidden style="margin:10px 0;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;background:#fff;display:flex;gap:10px;align-items:center;justify-content:space-between">
    <span id="push-optin-text" style="font-size:13px;color:#374151">알림을 켜면 화면이 꺼져 있어도 새 메시지를 받습니다.</span>
    <button type="button" id="push-optin-go" style="appearance:none;border:0;border-radius:8px;padding:8px 12px;background:#145fff;color:#fff;font-weight:800;cursor:pointer;font-size:13px">알림 켜기</button>
</div>

<script>
(function () {
    var box = document.getElementById('push-optin');
    var text = document.getElementById('push-optin-text');
    var go = document.getElementById('push-optin-go');
    if (!box) return;

    var token = document.querySelector('meta[name="csrf-token"]');
    var csrf = token ? token.getAttribute('content') : '';

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body || {})
        });
    }

    // 서버가 준 공개키(base64url)를 브라우저가 요구하는 바이트 배열로.
    function toBytes(base64) {
        var padded = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(padded);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        // 아이폰에서 홈 화면에 설치하기 전에는 알림 자체가 불가능하다 — 그 사실을 알린다.
        if (isIOS && !standalone) {
            box.hidden = false;
            text.textContent = '알림을 받으려면 먼저 공유 → "홈 화면에 추가" 로 설치해 주세요.';
            go.hidden = true;
        }
        return;
    }

    if (!navigator.serviceWorker.controller && 'serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
    }

    function subscribe(publicKey) {
        return navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.getSubscription().then(function (existing) {
                if (existing) return existing;
                return reg.pushManager.subscribe({
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

    fetch('{{ route('push.key') }}', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (info) {
            if (!info || !info.available) return;   // 이 배포에는 알림이 설정돼 있지 않다.

            if (Notification.permission === 'granted') {
                subscribe(info.publicKey).catch(function () {});   // 조용히 이 기기 등록
                return;
            }

            if (Notification.permission === 'denied') return;      // 다시 물을 수 없다

            box.hidden = false;
            go.addEventListener('click', function () {
                go.disabled = true;
                Notification.requestPermission().then(function (permission) {
                    if (permission !== 'granted') {
                        text.textContent = '알림이 차단되어 있습니다. 브라우저 설정에서 허용해 주세요.';
                        go.hidden = true;
                        return;
                    }
                    subscribe(info.publicKey).then(function () {
                        text.textContent = '알림을 켰습니다.';
                        go.hidden = true;
                        setTimeout(function () { box.hidden = true; }, 2500);
                    }).catch(function () {
                        text.textContent = '알림 등록에 실패했습니다. 잠시 후 다시 시도해 주세요.';
                        go.disabled = false;
                    });
                });
            });
        })
        .catch(function () {});
})();
</script>

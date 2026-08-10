/**
 * 서비스워커 — 안드로이드가 "설치" 를 제안하게 만드는 최소 조건이자, 신호가 약한
 * 현장에서 화면이 아예 안 뜨는 일을 막는 안전망.
 *
 * 왜 필요한가 — 크롬은 fetch 를 처리하는 서비스워커가 없으면 beforeinstallprompt 를
 * 주지 않는다. 그러면 우리 설치 버튼은 영영 안 뜨고, 안드로이드 작업자는 앱을 못 받는다.
 * 오류는 안 난다. 그냥 아무 일도 안 일어난다.
 *
 * 무엇을 하지 않는가가 더 중요하다:
 *
 *  1. HTML 을 절대 캐시하지 않는다. 출퇴근 화면에는 CSRF 토큰이 박혀 있다. 캐시된
 *     화면을 다시 띄우면 토큰이 만료돼 있어 출근 버튼이 419 로 죽는다 — 작업자에게는
 *     "눌렀는데 안 찍힌다" 로 보인다. 이게 이 파일에서 가장 위험한 실수다.
 *  2. POST 를 건드리지 않는다. 출퇴근 기록은 언제나 서버까지 간다.
 *  3. 우리가 아는 그림 파일 말고는 respondWith 를 부르지 않는다. 손대지 않은 요청은
 *     브라우저가 평소대로 처리한다 — 서비스워커가 조용히 앱 전체를 망가뜨리는 사고를
 *     구조적으로 막는다.
 *
 * 이 워커는 ERP 전체(/)에 걸린다. 그래서 이렇게까지 소극적으로 짰다.
 */

const VERSION = 'v1';
const SHELL = `dasol-shell-${VERSION}`;

/** 손대는 것은 이 목록뿐. 여기 없으면 브라우저에게 그대로 넘긴다. */
const ASSETS = [
    '/offline.html',
    '/images/worker-icon-192.png',
    '/images/worker-icon-512.png',
    '/images/worker-icon-maskable-512.png',
    '/images/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL)
            // 하나라도 실패하면 addAll 이 통째로 실패한다. 개별로 담아 한 장이 없어도
            // 나머지가 살아남게 한다.
            .then((cache) => Promise.all(ASSETS.map((url) => cache.add(url).catch(() => null))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== SHELL).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // 화면 이동: 언제나 서버를 먼저 본다. 신호가 없을 때만 안내 페이지를 보여 준다.
    // 진짜 화면을 캐시에서 꺼내 주지 않는 이유는 위의 1번(CSRF)이다.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('/offline.html'))
        );

        return;
    }

    // 아이콘. 이것만 캐시에서 먼저 꺼낸다 — 홈 화면 아이콘과 설치 안내에 쓰인다.
    if (ASSETS.includes(url.pathname)) {
        event.respondWith(
            caches.match(request).then((hit) => hit || fetch(request))
        );
    }

    // 그 밖의 모든 요청: 아무것도 하지 않는다.
});

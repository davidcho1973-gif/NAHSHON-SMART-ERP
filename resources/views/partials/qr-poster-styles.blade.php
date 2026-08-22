{{--
    현장 부착용 QR 포스터 공통 스타일 — 등록 QR·게이트 QR·모아 인쇄가 같은 판형을 쓴다.

    카카오 디자인 언어를 따른다. 노란 바닥에 흰 판, 검정 QR — 카카오가 QR 포스터를
    만드는 방식 그대로다. 다만 인쇄할 때는 바탕을 흰색으로 되돌린다(노랑을 A4 한 장
    가득 찍으면 토너만 먹고 벽에 붙였을 때 오히려 흐릿하다).
--}}
<style>
    :root {
        color-scheme: light;
        font-family: -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', 'Malgun Gothic', Arial, sans-serif;
        --kakao: #FEE500; --label: rgba(0,0,0,.85);
        --ink: #191919; --ink-2: #767676; --ink-3: #B0B8C1; --rule: #EDEEF0; --paper: #F2F3F5;
        background: var(--kakao); color: var(--ink);
    }
    body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; }
    .sheet { width: min(100%, 640px); background: #fff; border: 0; border-radius: 20px; padding: 40px; box-sizing: border-box; text-align: center; }
    .brand { margin: 0 0 6px; font-size: .8rem; letter-spacing: .12em; text-transform: uppercase; color: var(--ink-2); font-weight: 800; }
    h1 { margin: 0; font-size: 2.1rem; line-height: 1.15; font-weight: 800; letter-spacing: -.02em; }
    .site { margin: 14px 0 2px; font-size: 1.35rem; font-weight: 800; }
    .addr { margin: 0; color: var(--ink-2); font-size: .98rem; }
        .qr { width: min(78vw, 300px); height: min(78vw, 300px); border: 1px solid var(--rule); border-radius: 16px; padding: 14px; background: #fff; }
    .alt-titles { margin: 4px 0 0; color: var(--ink-2); font-size: 1.02rem; font-weight: 700; }
    .alt-titles span { white-space: nowrap; }
    .alt-titles span + span::before { content: ' · '; color: var(--ink-3); }
    .lang-blocks { margin: 22px auto 0; max-width: 460px; text-align: left; }
    .lang-block { padding: 12px 0; border-top: 1px solid var(--rule); }
    .lang-block:first-child { border-top: 0; padding-top: 4px; }
    .lang-chip { display: inline-block; margin: 0 0 6px; padding: 3px 10px; border-radius: 999px; background: var(--kakao); color: var(--label); font-size: .72rem; font-weight: 800; letter-spacing: .04em; }
    .lang-hint { margin: 0 0 6px; color: var(--ink-2); font-size: .9rem; line-height: 1.5; }
    .steps { text-align: left; margin: 0; color: var(--ink); line-height: 1.6; font-size: .88rem; padding-left: 18px; }
    .big-in-out { display: flex; gap: 10px; justify-content: center; margin: 18px 0 0; }
    .tag { padding: 8px 16px; border-radius: 999px; font-weight: 800; font-size: .95rem; }
    /* 출근은 노랑, 퇴근은 검정 — 게이트 화면·작업자 앱의 버튼과 같은 규칙이다. */
    .tag.in { background: var(--kakao); color: var(--label); }
    .tag.out { background: var(--label); color: #fff; }
    .type { display: inline-block; border-radius: 999px; padding: 7px 20px; font-size: 1rem; font-weight: 800; margin: 10px 0 0; }
    .type-direct { background: var(--label); color: #fff; }
    .type-indirect { background: var(--paper); color: var(--ink-2); }
    .url { margin: 20px 0 0; overflow-wrap: anywhere; color: var(--ink-2); font-size: .85rem; font-family: monospace; }
    .actions { margin-top: 26px; }
    button { appearance: none; border: 0; background: var(--kakao); color: var(--label); border-radius: 12px; padding: 13px 22px; font-weight: 800; font-family: inherit; font-size: .95rem; cursor: pointer; min-height: 52px; }
    /* 인쇄: 3개 언어를 넣어도 포스터 한 장이 A4 한 페이지를 넘지 않게 죈다. */
    @media print {
        :root, body { background: #fff; }
        body { min-height: auto; padding: 0; }
        .sheet { width: auto; border: 0; border-radius: 0; box-shadow: none; padding: 10mm 12mm; }
        .actions, .no-print { display: none !important; }
        h1 { font-size: 1.85rem; }
        .alt-titles { font-size: .95rem; }
        .site { margin: 8px 0 2px; font-size: 1.2rem; }
        .big-in-out { margin-top: 10px; }
        .qr { width: 62mm; height: 62mm; padding: 8px; }
        .lang-blocks { margin-top: 12px; max-width: none; }
        .lang-block { padding: 7px 0; }
        .lang-hint { font-size: .82rem; margin-bottom: 4px; }
        .steps { font-size: .8rem; line-height: 1.45; }
        .url { margin-top: 10px; font-size: .75rem; }
    }
</style>

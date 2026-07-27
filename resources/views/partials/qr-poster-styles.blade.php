{{-- 현장 부착용 QR 포스터 공통 스타일 — 등록 QR·게이트 QR·모아 인쇄가 같은 판형을 쓴다. --}}
<style>
    :root { color-scheme: light; font-family: 'Malgun Gothic', Arial, Helvetica, sans-serif; background: #f1f5f9; color: #0f172a; }
    body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; }
    .sheet { width: min(100%, 640px); background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 20px 50px rgba(15,23,42,.1); padding: 40px; box-sizing: border-box; text-align: center; }
    .brand { margin: 0 0 6px; font-size: .8rem; letter-spacing: .12em; text-transform: uppercase; color: #4f46e5; font-weight: 800; }
    h1 { margin: 0; font-size: 2.1rem; line-height: 1.15; }
    .site { margin: 14px 0 2px; font-size: 1.35rem; font-weight: 800; }
    .addr { margin: 0; color: #64748b; font-size: .98rem; }
    .hint { margin: 14px auto 20px; color: #475569; line-height: 1.6; font-size: 1.02rem; max-width: 460px; }
    .qr { width: min(78vw, 300px); height: min(78vw, 300px); border: 1px solid #e2e8f0; border-radius: 16px; padding: 14px; background: #fff; }
    .steps { text-align: left; max-width: 440px; margin: 22px auto 0; color: #334155; line-height: 1.75; font-size: .98rem; padding-left: 20px; }
    .big-in-out { display: flex; gap: 10px; justify-content: center; margin: 18px 0 0; }
    .tag { padding: 8px 16px; border-radius: 999px; font-weight: 800; font-size: .95rem; }
    .tag.in { background: #dcfce7; color: #059669; }
    .tag.out { background: #fee2e2; color: #dc2626; }
    .type { display: inline-block; border-radius: 999px; padding: 7px 20px; font-size: 1rem; font-weight: 800; margin: 10px 0 0; }
    .type-direct { background: #eef2ff; color: #4338ca; }
    .type-indirect { background: #ecfdf5; color: #047857; }
    .url { margin: 20px 0 0; overflow-wrap: anywhere; color: #4f46e5; font-size: .85rem; font-family: monospace; }
    .actions { margin-top: 26px; }
    button { appearance: none; border: 1px solid #4f46e5; background: #4f46e5; color: #fff; border-radius: 10px; padding: 12px 22px; font-weight: 700; font-size: .95rem; cursor: pointer; }
    @media print { :root, body { background: #fff; } body { min-height: auto; padding: 0; } .sheet { width: auto; border: 0; border-radius: 0; box-shadow: none; padding: 15mm; } .actions, .no-print { display: none !important; } }
</style>

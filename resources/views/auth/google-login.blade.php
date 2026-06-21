<!DOCTYPE html>
<html lang="ko">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#0f172a">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="SMART COMPANY">
  <link rel="manifest" href="{{ asset('site.webmanifest') }}">
  <link rel="icon" href="{{ asset('images/nahshon-app-icon.svg') }}" type="image/svg+xml">
  <link rel="apple-touch-icon" href="{{ asset('images/nahshon-app-icon.svg') }}">
  <title>Sign In - SMART COMPANY ERP</title>
  <style>
    :root {
      color-scheme: light;
      --bg: #f6f8fb;
      --panel: #ffffff;
      --text: #111827;
      --muted: #64748b;
      --line: #e5e7eb;
      --blue: #2563eb;
      --blue-dark: #1d4ed8;
      --danger-bg: #fef2f2;
      --danger-text: #991b1b;
      --success-bg: #ecfdf5;
      --success-text: #047857;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .topbar {
      height: 64px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 28px;
      background: #151b23;
      color: #e5e7eb;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 800;
      letter-spacing: .02em;
    }

    .brand-mark {
      width: 34px;
      height: 34px;
      border-radius: 8px;
      display: grid;
      place-items: center;
      background: #0ea5e9;
      color: white;
      font-size: 14px;
    }

    .admin-link {
      color: #93c5fd;
      font-size: 14px;
      font-weight: 700;
      text-decoration: none;
    }

    .page {
      width: min(100%, 560px);
      margin: 0 auto;
      padding: 96px 20px 40px;
    }

    .card {
      background: var(--panel);
      border: 1px solid rgba(15, 23, 42, .06);
      border-radius: 8px;
      box-shadow: 0 16px 40px rgba(15, 23, 42, .10);
      padding: 42px 40px 38px;
    }

    h1 {
      margin: 0;
      font-size: 30px;
      line-height: 1.2;
      text-align: center;
    }

    .subtitle {
      margin: 10px 0 30px;
      color: var(--muted);
      text-align: center;
      font-size: 15px;
    }

    .google-button,
    .password-button {
      width: 100%;
      height: 52px;
      border-radius: 8px;
      border: 1px solid var(--line);
      background: white;
      color: #1f2937;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      font-size: 15px;
      font-weight: 800;
      text-decoration: none;
      transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
    }

    .google-button:hover,
    .password-button:hover {
      border-color: #bfdbfe;
      box-shadow: 0 8px 20px rgba(37, 99, 235, .10);
      transform: translateY(-1px);
    }

    .google-mark {
      font-size: 23px;
      font-weight: 900;
      color: #4285f4;
    }

    .lock-mark {
      width: 15px;
      height: 12px;
      border: 2px solid #94a3b8;
      border-radius: 3px;
      position: relative;
    }

    .lock-mark::before {
      content: "";
      position: absolute;
      width: 9px;
      height: 8px;
      left: 1px;
      top: -10px;
      border: 2px solid #94a3b8;
      border-bottom: 0;
      border-radius: 8px 8px 0 0;
    }

    .divider {
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      align-items: center;
      gap: 14px;
      margin: 30px 0;
      color: #94a3b8;
      font-size: 14px;
      font-weight: 700;
    }

    .divider::before,
    .divider::after {
      content: "";
      height: 1px;
      background: var(--line);
    }

    .password-button {
      color: #334155;
    }

    .notice {
      border-radius: 8px;
      padding: 12px 14px;
      margin-bottom: 18px;
      font-size: 14px;
      line-height: 1.45;
    }

    .notice.error {
      background: var(--danger-bg);
      color: var(--danger-text);
      border: 1px solid #fecaca;
    }

    .notice.success {
      background: var(--success-bg);
      color: var(--success-text);
      border: 1px solid #bbf7d0;
    }

    .setup-note {
      margin-top: 18px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
      text-align: center;
    }

    .mobile-only {
      display: none;
    }

    @media (max-width: 640px) {
      :root {
        color-scheme: dark;
      }

      html {
        min-height: 100%;
        background: #070b14;
      }

      body {
        min-width: 320px;
        min-height: 100dvh;
        background:
          radial-gradient(circle at 50% -8%, rgba(37, 99, 235, .46), transparent 34%),
          linear-gradient(180deg, #0f172a 0%, #101827 48%, #070b14 100%);
        color: #f8fafc;
        overflow-x: hidden;
      }

      .topbar {
        min-height: 64px;
        height: auto;
        padding: calc(12px + env(safe-area-inset-top)) 18px 10px;
        gap: 12px;
        background: transparent;
        color: #f8fafc;
      }

      .brand {
        gap: 9px;
      }

      .brand-mark {
        width: 36px;
        height: 36px;
        border-radius: 12px;
        background: linear-gradient(135deg, #38bdf8, #2563eb);
        box-shadow: 0 12px 30px rgba(37, 99, 235, .38);
      }

      .brand span:last-child {
        max-width: 155px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 14px;
      }

      .admin-link {
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 999px;
        padding: 9px 11px;
        background: rgba(15, 23, 42, .58);
        color: #bfdbfe;
        font-size: 12px;
        white-space: nowrap;
      }

      .page {
        width: 100%;
        min-height: calc(100dvh - 64px);
        padding: 18px 18px calc(24px + env(safe-area-inset-bottom));
        display: flex;
        align-items: stretch;
      }

      .card {
        width: 100%;
        min-height: min(700px, calc(100dvh - 116px));
        padding: 22px 0 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        display: flex;
        flex-direction: column;
        justify-content: center;
      }

      h1 {
        font-size: 34px;
        letter-spacing: 0;
        text-align: left;
      }

      .subtitle {
        margin: 10px 0 22px;
        color: #9fb0c8;
        text-align: left;
        font-size: 16px;
        line-height: 1.55;
      }

      .mobile-only {
        display: flex;
      }

      .mobile-app-hero {
        flex-direction: column;
        gap: 18px;
        margin-bottom: 28px;
      }

      .mobile-app-icon {
        width: 82px;
        height: 82px;
        border-radius: 24px;
        display: grid;
        place-items: center;
        background: linear-gradient(135deg, rgba(56, 189, 248, .96), rgba(37, 99, 235, .96));
        color: #fff;
        font-size: 26px;
        font-weight: 900;
        letter-spacing: .02em;
        box-shadow: 0 22px 60px rgba(37, 99, 235, .42);
      }

      .mobile-kicker {
        width: max-content;
        max-width: 100%;
        padding: 6px 10px;
        border: 1px solid rgba(125, 211, 252, .24);
        border-radius: 999px;
        background: rgba(14, 165, 233, .10);
        color: #bae6fd;
        font-size: 12px;
        font-weight: 800;
      }

      .mobile-feature-row {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
        margin: 0 0 18px;
      }

      .mobile-feature {
        display: flex;
        min-height: 70px;
        border: 1px solid rgba(148, 163, 184, .16);
        border-radius: 18px;
        background: rgba(15, 23, 42, .58);
        padding: 12px 10px;
        flex-direction: column;
        justify-content: space-between;
      }

      .mobile-feature strong {
        color: #f8fafc;
        font-size: 13px;
      }

      .mobile-feature span {
        color: #94a3b8;
        font-size: 11px;
        line-height: 1.3;
      }

      .auth-actions {
        margin-top: auto;
        padding: 16px;
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 28px;
        background: rgba(15, 23, 42, .74);
        box-shadow: 0 28px 70px rgba(2, 6, 23, .45);
        backdrop-filter: blur(22px);
      }

      .auth-actions > h1 {
        font-size: 22px;
      }

      .auth-actions > .subtitle {
        margin-bottom: 16px;
        font-size: 13px;
      }

      .google-button,
      .password-button {
        min-height: 56px;
        height: auto;
        border-radius: 18px;
        padding: 15px 16px;
        line-height: 1.25;
        font-size: 16px;
        border-color: rgba(148, 163, 184, .20);
      }

      .google-button {
        background: #f8fafc;
        color: #0f172a;
      }

      .password-button {
        background: rgba(15, 23, 42, .72);
        color: #dbeafe;
      }

      .divider {
        margin: 18px 0;
        color: #64748b;
      }

      .divider::before,
      .divider::after {
        background: rgba(148, 163, 184, .18);
      }

      .notice {
        margin-bottom: 14px;
      }

      .setup-note,
      .notice {
        text-align: left;
      }

      .setup-note {
        margin: 14px 2px 0;
        color: #93a4bc;
      }
    }
  </style>
</head>

<body>
  <header class="topbar">
    <div class="brand">
      <span class="brand-mark">NS</span>
      <span>SMART COMPANY</span>
    </div>
    <a class="admin-link" href="{{ url('/admin/login') }}">Password Sign In</a>
  </header>

  <main class="page">
    <section class="card" aria-label="ERP sign in">
      <div class="mobile-only mobile-app-hero" aria-hidden="true">
        <div class="mobile-app-icon">NS</div>
        <div>
          <div class="mobile-kicker">NAHSHON MEP FIELD ERP</div>
          <h1>SMART COMPANY</h1>
          <p class="subtitle">현장, 직원, 안전, 장비 데이터를 휴대폰에서 바로 확인합니다.</p>
        </div>
      </div>

      <div class="mobile-only mobile-feature-row" aria-hidden="true">
        <div class="mobile-feature"><strong>현장</strong><span>Site status</span></div>
        <div class="mobile-feature"><strong>인원</strong><span>Workers</span></div>
        <div class="mobile-feature"><strong>안전</strong><span>Access</span></div>
      </div>

      <div class="auth-actions">
      <h1>Sign In</h1>
      <p class="subtitle">Google 인증 후 권한에 맞는 ERP 화면으로 이동합니다.</p>

      @if ($errors->has('google'))
        <div class="notice error">{{ $errors->first('google') }}</div>
      @endif

      @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
      @endif

      <a class="google-button" href="{{ route('auth.google.redirect') }}" aria-disabled="{{ $googleConfigured ? 'false' : 'true' }}">
        <span class="google-mark">G</span>
        <span>Continue with Google</span>
      </a>

      <div class="divider">or</div>

      <a class="password-button" href="{{ url('/admin/login') }}">
        <span class="lock-mark" aria-hidden="true"></span>
        <span>Sign in with password</span>
      </a>

      @unless ($googleConfigured)
        <p class="setup-note">Google OAuth 환경변수가 아직 설정되지 않았습니다. Laravel Cloud와 로컬 `.env`에 client ID, secret, callback URL을 추가해 주세요.</p>
      @endunless
      </div>
    </section>
  </main>
</body>

</html>

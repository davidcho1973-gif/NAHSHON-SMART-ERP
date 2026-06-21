<!DOCTYPE html>
<html lang="ko">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

    @media (max-width: 640px) {
      .topbar {
        padding: 0 18px;
      }

      .page {
        padding-top: 54px;
      }

      .card {
        padding: 32px 22px;
      }

      h1 {
        font-size: 26px;
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
    </section>
  </main>
</body>

</html>

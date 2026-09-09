<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>이메일 비밀번호 설정 · {{ \App\Support\Org::name() }}</title>
  <style>
    *{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#172033;font:16px/1.6 system-ui,sans-serif}
    main{max-width:460px;margin:7vh auto;padding:30px;background:#fff;border:1px solid #e1e7ef;border-radius:20px}
    h1{font-size:24px;margin:8px 0}p{color:#526174;font-size:14px}label{display:block;margin:18px 0 6px;font-weight:600;font-size:14px}
    input{width:100%;padding:13px;border:1px solid #a6b3c3;border-radius:9px;font:inherit}button{width:100%;padding:14px;margin-top:24px;border:0;border-radius:10px;background:#183b62;color:#fff;font:inherit;font-weight:700;cursor:pointer}
    .error{background:#fff1f2;color:#991b1b;padding:12px;border-radius:8px;font-size:14px}a{color:#183b62}@media(max-width:500px){main{margin:18px 12px;padding:24px}}
  </style>
</head>
<body><main>
  <small>{{ \App\Support\Org::name() }}</small>
  <h1>{{ $changing ? '비밀번호 변경' : '내 비밀번호 설정' }}</h1>
  <p>{{ $changing ? 'Change your email sign-in password.' : 'Set your email sign-in password.' }}<br>영문과 숫자를 포함하여 8자 이상 입력하세요.<br>Use at least 8 characters with letters and numbers.</p>
  @unless($changing)<p>설정 후에는 전화번호 끝 4자리 대신 새 비밀번호로 로그인합니다.<br>After setup, use your new password instead of your phone digits.</p>@endunless
  @if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
  <form method="POST" action="{{ route('password.setup.store') }}">
    @csrf
    @if($changing)
      <label for="current-password">현재 비밀번호 / Current password</label>
      <input id="current-password" type="password" name="current_password" autocomplete="current-password" required maxlength="128">
    @endif
    <label for="new-password">새 비밀번호 / New password</label>
    <input id="new-password" type="password" name="password" autocomplete="new-password" required minlength="8" maxlength="128">
    <label for="confirm-password">새 비밀번호 확인 / Confirm password</label>
    <input id="confirm-password" type="password" name="password_confirmation" autocomplete="new-password" required minlength="8" maxlength="128">
    <button type="submit">저장하고 시작하기 / Save and continue</button>
  </form>
  <p><a href="{{ route('login') }}">돌아가기 / Back</a></p>
</main></body>
</html>

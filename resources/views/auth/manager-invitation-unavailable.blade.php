<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>관리자 초대 등록 안내</title>
    <style>
        body{margin:0;background:#f3f5f8;font:16px system-ui;color:#17233b}
        main{box-sizing:border-box;max-width:486px;margin:40px auto;padding:28px;background:white;border-radius:16px}
        h1{font-size:24px;line-height:1.4}p{line-height:1.7;color:#526078}
        .button{display:block;box-sizing:border-box;width:100%;padding:14px;margin-top:22px;background:#234ed8;color:white;border-radius:8px;text-align:center;text-decoration:none;font:inherit}
        @media(max-width:520px){main{margin:16px;padding:24px}}
    </style>
</head>
<body>
<main>
    @if($verificationExpired)
        <h1>전화번호를 다시 확인해 주세요</h1>
        <p>전화번호 확인 후 15분이 지났거나 이 브라우저에 확인 정보가 없습니다. 등록을 계속하려면 초대 링크에서 전화번호를 다시 확인해 주세요.</p>
        @if($restartUrl)
            <a class="button" href="{{ $restartUrl }}">전화번호 다시 확인</a>
        @else
            <p>관리자에게 받은 초대 링크를 다시 열어 주세요.</p>
        @endif
    @else
        <h1>이 초대 링크로는 등록을 진행할 수 없습니다</h1>
        <p>초대는 7일간 한 번 사용할 수 있습니다. 재발급·취소되었거나 계정 정보가 변경된 경우에도 이전 링크를 사용할 수 없습니다.</p>
        <p>아직 등록하지 않았다면 초대를 보낸 관리자에게 새 초대 링크를 요청해 주세요.</p>
    @endif
    <p>이미 등록을 완료했다면 초대 링크 대신 등록한 Google 계정 또는 이메일·ERP 비밀번호로 로그인해 주세요.</p>
    <a class="button" href="{{ route('login', ['erp' => 1]) }}">로그인 화면으로 이동</a>
</main>
</body>
</html>

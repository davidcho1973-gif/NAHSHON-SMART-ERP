<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>등록 완료</title>
<style>body{background:#f3f5f8;color:#17233b;font:16px system-ui}main{max-width:440px;margin:40px auto;padding:28px;background:white;border-radius:16px}a{display:block;padding:15px;margin:15px 0;border-radius:8px;background:#234ed8;color:white;text-decoration:none;text-align:center}p{line-height:1.7}</style></head><body><main>
<h1>등록이 완료되었습니다</h1><p>{{ auth()->user()->name }} 님, 기존 직원 정보와 출퇴근 기록이 그대로 연결되었습니다.</p>
<a href="{{ auth()->user()->landingPath() }}">ERP 들어가기</a><a href="{{ route('attendance-app.index') }}">개인앱 열기</a><a href="{{ route('install-guide') }}">휴대폰에 개인앱 설치 안내</a>
<p>다음부터는 방금 등록한 Google 계정 또는 이메일·ERP 비밀번호로 로그인하세요.</p>
</main></body></html>

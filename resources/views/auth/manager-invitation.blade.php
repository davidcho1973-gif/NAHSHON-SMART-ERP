<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>관리자 초대 등록</title>
<style>body{margin:0;background:#f3f5f8;font:16px system-ui;color:#17233b}main{max-width:430px;margin:40px auto;padding:28px;background:white;border-radius:16px}h1{font-size:24px}p{line-height:1.6;color:#526078}label{display:block;margin:18px 0 8px}input{box-sizing:border-box;width:100%;padding:13px;border:1px solid #bdc8d8;border-radius:8px;font:inherit}button,.button{display:block;box-sizing:border-box;width:100%;padding:14px;margin-top:22px;background:#234ed8;color:white;border:0;border-radius:8px;text-align:center;text-decoration:none;font:inherit}.error{color:#b42318}@media(max-width:500px){main{margin:16px;padding:24px}}</style></head><body><main>
<h1>관리자 초대 등록</h1>
<p>기존 직원 기록에 로그인 정보를 연결합니다. 등록을 완료하면 관리자가 지정한 범위에서 ERP와 개인앱을 사용할 수 있습니다.</p>
@foreach($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach
@if(!$verified)
<form method="post" action="{{ route('manager-invitation.verify', ['token'=>$token]) }}">@csrf
<label for="phone">직원 등록 때 사용한 전화번호 전체</label><input id="phone" name="phone" type="tel" autocomplete="tel" required maxlength="40" value="{{ old('phone') }}">
<p>초대를 받은 본인만 진행하세요. 등록한 국가번호가 있다면 함께 입력하세요.</p><button>전화번호 확인</button></form>
@else
<p><strong>{{ $user->name }}</strong> 님의 전화번호가 확인되었습니다. 로그인 방법을 선택하세요.</p>
@if($googleConfigured)<a class="button" href="{{ route('auth.google.redirect') }}">Google 계정으로 등록</a>@endif
<form method="post" action="{{ route('manager-invitation.complete', ['token'=>$token]) }}">@csrf
<label for="email">로그인에 사용할 이메일</label><input id="email" name="email" type="email" autocomplete="email" required maxlength="255" value="{{ old('email') }}">
<label for="password">새 ERP 비밀번호</label><input id="password" name="password" type="password" autocomplete="new-password" required minlength="10" maxlength="128">
<p>문자와 숫자를 포함해 10자 이상 입력하세요. 이메일 서비스 비밀번호와 별개입니다.</p>
<label for="password_confirmation">비밀번호 다시 입력</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="10" maxlength="128">
<button>등록 완료하고 입장</button></form>
@endif
</main></body></html>

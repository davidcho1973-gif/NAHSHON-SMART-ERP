<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>등록 완료</title>
@include('partials.personal-app-meta')
<style>body{background:#f3f5f8;color:#17233b;font:16px system-ui}main{max-width:440px;margin:40px auto;padding:28px;background:white;border-radius:16px}a{display:block;padding:15px;margin:15px 0;border-radius:8px;background:#234ed8;color:white;text-decoration:none;text-align:center}p{line-height:1.7}</style></head><body><main>
<h1>등록이 완료되었습니다</h1><p>{{ auth()->user()->name }} 님, 기존 직원 정보와 출퇴근 기록이 그대로 연결되었습니다.</p>
<p>직책: {{ \App\Support\JobAccess::label(auth()->user()) }} · {{ \App\Models\User::SCOPE_LABELS_KO[auth()->user()->access_scope] ?? '' }}</p>
@if (\App\Support\PersonalAppAccess::eligible(auth()->user()))
<a href="{{ route('attendance-app.index', ['install' => 1]) }}">내 앱 설치하기</a>
<p>개인앱에서 홈 화면에 추가한 뒤, 새 아이콘을 눌러 본인 이름이 나오는지 확인하세요.</p>
<a href="{{ route('install-guide') }}" style="background:#eaf2fa;color:#234e70">설치 방법 보기</a>
<a href="{{ auth()->user()->landingPath() }}" style="background:#eaf2fa;color:#234e70">업무 화면 열기</a>
<p>다시 로그인이 필요하면 등록한 Google 계정 또는 이메일·ERP 비밀번호를 사용하세요. 직원등록은 다시 하지 않습니다.</p>
@else
<p>일반 작업자는 앱 설치 없이 현장 QR로 출퇴근합니다. 이미 등록되었으니 다시 가입하지 마세요.</p>
<a href="{{ \App\Support\PersonalAppAccess::gateUrl(auth()->user()->employee) ?? route('worker-app.entry') }}">출퇴근 화면 열기</a>
@endif
</main></body></html>

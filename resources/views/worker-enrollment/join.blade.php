@extends('worker-enrollment.layout')
@section('content')
<div class="panel" style="max-width:460px;margin:24px auto">
@if($done)
<h1>가입 신청 완료 · 승인 대기</h1><p>담당 반장 또는 관리자에게 본인 확인을 요청하세요. 승인 후 개인용 QR로 PIN을 설정하면 출퇴근을 사용할 수 있습니다.</p>
<p>Request received. Ask your supervisor to confirm your identity and give you a personal activation QR.</p>
<p>Solicitud recibida. Pida a su supervisor que confirme su identidad y le entregue su QR personal.</p>
@else
<h1>이름·전화번호로 등록</h1><p>Name & phone · Nombre y teléfono</p>
<div class="notice">{{ $team->company?->name }}<br>{{ $team->site?->name }} · {{ $team->name }}<br><small>시급 작업자 가입 · Hourly worker</small></div>
<form method="post" action="{{ request()->fullUrl() }}">@csrf
<label for="name">이름 · Name · Nombre</label><input id="name" name="name" required maxlength="160" autocomplete="name" value="{{ old('name') }}">
<label for="phone">전화번호 · Phone · Teléfono</label><input id="phone" name="phone" type="tel" required maxlength="30" autocomplete="tel" placeholder="+1 202 555 0147" value="{{ old('phone') }}">
<p><small>미국 번호는 10자리, 다른 국가는 +국가번호를 포함하세요. No email needed. Include country code outside the US.</small></p>
<p><small>이름과 연락처를 직원 등록·출퇴근 계정 연결을 위해 담당 관리자에게 제출합니다. Submit your name and phone to your supervisor for employee enrollment and attendance access.</small></p>
<button type="submit" style="width:100%">가입 신청 · Submit · Enviar</button>
</form>
@endif
</div>
@endsection

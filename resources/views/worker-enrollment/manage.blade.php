@extends('worker-enrollment.layout')
@section('content')
<nav><a href="{{ auth()->user()->landingPath() }}">← 이전 업무 화면 / Back</a></nav>
<h1>작업자 간편등록 · 승인</h1><p>가입 QR → 이름·전화 접수 → 본인 확인 → 개인 QR → PIN 설정</p>
<div class="panel"><h2>① 팀 가입 QR 발급</h2><p>시급 작업자용입니다. 협력사 출역 인원·관리자 계정 등록은 기존 인사 메뉴를 사용하세요.</p>
@forelse($teams as $team)<form method="post" action="{{ route('worker-enrollment.invite', $team) }}">@csrf<div class="row"><span>{{ $team->company?->name }} / {{ $team->site?->code }} / {{ $team->name }}</span><button type="submit">가입 QR</button></div></form>@empty<p>승인 가능한 팀이 없습니다. 관리자에게 팀 배정을 요청하세요.</p>@endforelse
</div>
<h2>② 가입 신청 및 앱 연결 상태</h2>
@forelse($rows as $row)
<article class="panel"><div class="row"><strong>{{ $row->name }}</strong><span>{{ $row->phone }}</span><span class="tag">{{ $row->team->name }}</span></div>
@if($row->status === 'pending')
<p>승인 대기 · Pending</p><form method="post" action="{{ route('worker-enrollment.approve', $row) }}">@csrf<label class="check"><input type="checkbox" name="confirmed" value="1" required><span>실제 직원 본인과 소속 팀을 확인했습니다. 승인 시 본인 출퇴근 권한만 생성됩니다.</span></label><button type="submit">확인 · 승인</button></form>
<form method="post" action="{{ route('worker-enrollment.reject', $row) }}">@csrf<button class="secondary" type="submit">반려</button></form>
@elseif($row->status === 'approved')
@if($row->employee?->employment_status !== 'active' || $row->employee?->user?->account_status !== 'active')<p class="error">직원 또는 계정이 비활성 상태입니다. 관리자에게 확인하세요.</p>
@elseif($row->employee?->user?->hasPin())<p class="notice">PIN 설정 완료 · 출퇴근 계정 연결됨</p><small>시급 금액 및 재직 상태는 직원·급여 설정에서 관리합니다.</small>
@else<p>승인 완료 · PIN 설정 대기</p><form method="post" action="{{ route('worker-enrollment.activation', $row) }}">@csrf<button type="submit">개인용 앱 연결 QR 발급 / 재발급</button></form>@endif
@else<p>반려됨 · Rejected</p>@endif
</article>
@empty<p>아직 가입 신청이 없습니다.</p>@endforelse
{{ $rows->links() }}
@endsection

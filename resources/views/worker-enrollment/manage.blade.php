@extends('worker-enrollment.layout')
@section('content')
<nav><a href="{{ $returnTo }}">← 이전 업무 화면 / Back</a></nav>
<h1>{{ $canRegister ? '인사 · 작업자 등록 및 앱 연결' : '우리 팀 직원 등록 현황' }}</h1>
@if($canRegister)
<div class="steps" aria-label="신규 작업자 등록 흐름"><strong>1. 인사담당자 등록</strong><span>→</span><strong>2. 직원이 QR 촬영</strong><span>→</span><strong>3. PIN 설정 후 출근</strong></div>
<section class="panel"><h2>신규 작업자 등록</h2><p>인사담당자가 신원과 소속을 확인한 뒤 개인 QR을 만듭니다. 직원은 별도 앱을 열거나 설치할 필요가 없습니다.</p>
<form method="post" action="{{ route('worker-enrollment.store') }}">@csrf
@if($returnTo === '/attendance-app')<input type="hidden" name="return_to" value="/attendance-app">@endif
<label for="name">직원 이름</label><input id="name" name="name" value="{{ old('name') }}" maxlength="160" required>
<label for="phone">전화번호</label><input id="phone" name="phone" type="tel" value="{{ old('phone') }}" maxlength="30" required><small>미국 번호 10자리 / 다른 국가는 +국가번호 포함</small>
<label for="team">소속</label><select id="team" name="team_id" required><option value="">소속 선택</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(old('team_id') == $team->id)>{{ $team->company?->name }} / {{ $team->site?->code }} / {{ $team->name }}</option>@endforeach</select>
<label class="check"><input type="checkbox" name="confirmed" value="1" required><span>인사담당자로서 직원 신원과 소속을 확인했습니다.</span></label>
<p><button type="submit">등록하고 개인 QR 만들기</button></p></form></section>
@else
<p>자기 팀 직원의 등록 상태를 조회하는 화면입니다. 등록·수정·승인·앱 연결은 인사담당자가 처리합니다.</p>
@endif
<h2>직원 등록 및 앱 연결 상태</h2>
@forelse($rows as $row)
<article class="panel"><div class="row"><strong>{{ $row->name }}</strong><span>{{ $row->phone }}</span><span class="tag">{{ $row->team->name }}</span></div>
@if($row->status === 'pending')
<p>인사 승인 대기 · Pending</p>
@if($canRegister)
<form method="post" action="{{ route('worker-enrollment.approve', $row) }}">@csrf @if($returnTo === '/attendance-app')<input type="hidden" name="return_to" value="/attendance-app">@endif<label class="check"><input type="checkbox" name="confirmed" value="1" required><span>인사담당자로서 직원 신원과 소속을 확인했습니다.</span></label><button type="submit">인사 승인</button></form>
<form method="post" action="{{ route('worker-enrollment.reject', $row) }}">@csrf @if($returnTo === '/attendance-app')<input type="hidden" name="return_to" value="/attendance-app">@endif<button class="secondary" type="submit">반려</button></form>
@endif
@elseif($row->status === 'approved')
@if($row->employee?->employment_status !== 'active' || $row->employee?->user?->account_status !== 'active')<p class="error">직원 또는 계정이 비활성 상태입니다. 인사담당자에게 확인하세요.</p>
@elseif($row->employee?->user?->hasPin())<p class="notice">PIN 설정 완료 · 출퇴근 계정 연결됨</p>
@else<p>인사 승인 완료 · PIN 설정 대기</p>@if($canRegister)<form method="post" action="{{ route('worker-enrollment.activation', $row) }}">@csrf<button type="submit">직원 개인용 앱 연결 QR 발급 / 재발급</button></form>@endif
@endif
@else<p>반려됨 · Rejected</p>@endif
</article>
@empty<p>등록된 내역이 없습니다.</p>@endforelse
{{ $rows->links() }}
@endsection

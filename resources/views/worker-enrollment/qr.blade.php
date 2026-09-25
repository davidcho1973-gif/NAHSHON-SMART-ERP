@extends('worker-enrollment.layout')
@section('content')
<nav><a href="{{ route('worker-enrollment.index') }}">← 가입 승인 목록</a></nav>
<div class="panel" style="max-width:500px;margin:auto"><h1>{{ $title }}</h1><p>{{ $note }}</p><div class="steps"><strong>카메라로 촬영</strong><span>→</span><strong>이름·전화번호</strong><span>→</span><strong>출근 버튼</strong></div><img class="qr" src="{{ $qr }}" alt="{{ $title }} QR"><label for="link">원격 전달용 개인 링크</label><input id="link" readonly value="{{ $url }}"><button type="button" id="copy">링크 복사</button><p id="message" role="status"></p><small>이 QR은 직원 한 사람의 휴대폰 등록용입니다. 직원 본인이 휴대폰 기본 카메라로 스캔하면 그 휴대폰이 본인 것으로 연결됩니다.</small></div>
<script>document.getElementById('copy').addEventListener('click',async function(){const input=document.getElementById('link');input.select();try{await navigator.clipboard.writeText(input.value);document.getElementById('message').textContent='복사했습니다. 본인 확인 후 전달하세요.';}catch(e){document.getElementById('message').textContent='위 링크를 길게 눌러 복사하세요.';}});</script>
@endsection

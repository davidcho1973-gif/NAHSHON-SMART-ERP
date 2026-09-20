@extends('worker-enrollment.layout')
@section('content')
<nav><a href="{{ route('worker-enrollment.index') }}">← 가입 승인 목록</a></nav>
<div class="panel" style="max-width:500px;margin:auto"><h1>{{ $title }}</h1><p>{{ $note }}</p><img class="qr" src="{{ $qr }}" alt="{{ $title }} QR"><label for="link">전달할 링크</label><input id="link" readonly value="{{ $url }}"><button type="button" id="copy">링크 복사</button><p id="message" role="status"></p><small>개인용 QR은 직원 본인 휴대폰으로 스캔하세요. 관리자가 대신 PIN을 설정하지 않습니다.</small></div>
<script>document.getElementById('copy').addEventListener('click',async function(){const input=document.getElementById('link');input.select();try{await navigator.clipboard.writeText(input.value);document.getElementById('message').textContent='복사했습니다. 본인 확인 후 전달하세요.';}catch(e){document.getElementById('message').textContent='위 링크를 길게 눌러 복사하세요.';}});</script>
@endsection

<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>출퇴근 공지 확인 현황</title><style>body{font:16px system-ui;max-width:850px;margin:30px auto;padding:20px;color:#17364c;background:#f4f7fa}article{background:white;padding:20px;margin:20px 0;border-radius:16px}td,th{text-align:left;padding:10px}table{width:100%}</style>
<a href="{{ route('communication.show', $room) }}">← {{ $room->name }}</a><h1>출퇴근 공지 확인 현황 / Notice confirmations</h1>
<p>작업자가 확인 버튼을 누른 기록입니다. 내용 수정 전 확인은 이전 확인으로 표시됩니다.</p>
@forelse($messages as $message)
<article><h2>{{ $message->title ?: '공지 / Notice' }}</h2><p>{{ $message->payload['attendance_notice']['event'] }} · {{ ($message->payload['attendance_notice']['required'] ?? false) ? '필수 확인 / Required' : '일반 / Normal' }} · 종료 {{ $message->payload['attendance_notice']['expires_at'] }}</p>
<table><tr><th>작업자 / Worker</th><th>확인 시각 / Confirmed ({{ config('app.timezone') }})</th><th>상태 / Status</th></tr>
@forelse($receipts->get($message->id, collect()) as $receipt)
<tr><td>{{ $receipt->name }}</td><td>{{ $receipt->acknowledged_at }}</td><td>{{ \Illuminate\Support\Carbon::parse($receipt->acknowledged_at)->lt($message->edited_at ?? $message->sent_at) ? '이전 확인 / Previous' : '확인 / Confirmed' }}</td></tr>
@empty<tr><td colspan="3">아직 확인 기록이 없습니다. / No confirmations yet.</td></tr>@endforelse</table></article>
@empty<p>출퇴근 공지가 없습니다. / No attendance notices.</p>@endforelse</html>

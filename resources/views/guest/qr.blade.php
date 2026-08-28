<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>손님 링크 QR — {{ $link->site?->name }}</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Pretendard Variable',Pretendard,-apple-system,'Apple SD Gothic Neo','Malgun Gothic',sans-serif;
         background:#f4f5f7; display:flex; justify-content:center; padding:24px 16px; }
  .card { background:#fff; border:1px solid #e8eaed; border-radius:20px; padding:28px 24px; max-width:420px;
          width:100%; text-align:center; }
  .org { font-size:13px; font-weight:700; color:{{ \App\Support\Org::color() }}; margin-bottom:4px; }
  h1 { font-size:19px; font-weight:800; margin-bottom:2px; }
  .sub { font-size:12.5px; color:#8b919a; margin-bottom:18px; }
  .qr { width:min(280px, 70vw); margin:0 auto 16px; display:block; }
  .url { font-size:11px; color:#6b7280; word-break:break-all; background:#f4f5f7; border-radius:10px;
         padding:10px 12px; margin-bottom:14px; }
  .note { font-size:12px; color:#4b5563; line-height:1.7; }
  .btns { display:flex; gap:8px; margin-top:18px; }
  .btns button { flex:1; border:none; border-radius:10px; padding:12px; font-size:14px; font-weight:700;
                 cursor:pointer; font-family:inherit; }
  .b-copy { background:#1a1d21; color:#fff; }
  .b-print { background:#eef0f3; color:#1a1d21; }
  @media print { body { background:#fff; padding:0; } .card { border:none; } .btns { display:none; } }
</style>
</head>
<body>
<div class="card">
  <div class="org">{{ \App\Support\Org::name() }}</div>
  <h1>{{ $link->site?->name }}</h1>
  <div class="sub">공정 현황 열람 링크{{ $link->label ? ' · '.$link->label : '' }}</div>
  <img class="qr" src="{{ $qrImage }}" alt="QR">
  <div class="url">{{ $url }}</div>
  <div class="note">
    QR 을 찍거나 링크를 열면 로그인 없이 이 현장의 공정 현황을 볼 수 있습니다.<br>
    {{ $link->expires_at ? '유효기간: '.$link->expires_at->toDateString().' 까지' : '유효기간: 회수할 때까지' }}
  </div>
  <div class="btns">
    <button class="b-copy" onclick="copyUrl(this)">링크 복사</button>
    <button class="b-print" onclick="window.print()">인쇄</button>
  </div>
</div>
<script>
function copyUrl(btn) {
  var url = @json($url);
  (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () {
    btn.textContent = '복사됨 ✓';
    setTimeout(function () { btn.textContent = '링크 복사'; }, 1500);
  }).catch(function () { window.prompt('길게 눌러 복사하세요', url); });
}
</script>
</body>
</html>

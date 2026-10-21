<!doctype html>
<html lang="ko">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>CAD AI 연결 승인</title><style>body{font:16px/1.65 system-ui,sans-serif;background:#eff4f8;color:#173047;margin:0;padding:24px}main{max-width:760px;margin:auto;background:white;padding:28px;border-radius:12px;border:1px solid #cad8e3}h1{font-size:24px}code{overflow-wrap:anywhere}form{display:inline-block;margin:12px 8px 0 0}button{font:inherit;padding:10px 18px;border:1px solid #45627b;border-radius:6px;cursor:pointer}dl{background:#f1f5f8;padding:14px}dd{margin:0 0 12px;overflow-wrap:anywhere}.notice{border-left:4px solid #c48629;padding:12px;background:#fff8ea}</style></head>
<body><main>
<h1>CAD AI 연결 승인</h1>
<p>{{ $user->name }} 계정의 CAD 공유 기능에 연결을 요청했습니다.</p>
<dl><dt>클라이언트가 표시한 이름 · 검증되지 않음</dt><dd><strong>{{ $client->name }}</strong></dd><dt>승인 결과를 받는 정확한 주소</dt><dd><code>{{ $request->input('redirect_uri', '') }}</code></dd><dt>주소의 호스트</dt><dd><code>{{ parse_url($request->input('redirect_uri', ''), PHP_URL_HOST) ?: '호스트 없음 · 전체 주소를 확인하세요' }}</code></dd></dl>
<p class="notice">클라이언트 이름만으로 요청자의 신원을 보장하지 않습니다. 본인이 시작한 연결인지, 위 주소가 사용하려는 서비스와 일치하는지 확인하세요.</p>
<p>권한 범위: <strong>cad:session</strong></p>
<ul><li>CAD 편집기에서 직접 공유를 시작한 현재 도면 스냅샷만 읽습니다. 선택 객체 또는 보이는 객체 중 사용자가 정한 범위가 전달됩니다.</li><li>편집기의 AI 편집 요청 허용을 켠 경우, 지원되는 도면 편집 요청을 대기열에 넣을 수 있습니다. 기본적으로 편집기에서 사용자가 확인한 뒤 적용합니다. 자동 적용은 편집기에서 별도로 켤 수 있습니다.</li><li>도면 저장은 기존 저장 버튼으로 진행합니다. 이 연결 자체로 DWG/DXF 저장이나 기성 수량·청구·급여 등의 기록을 확정하지 않습니다.</li><li>이 권한은 일반 ERP 자료·인사·급여·다른 도면을 조회할 권한을 주지 않습니다.</li></ul>
<p>CAD 편집기에서 공유 중지 또는 연결 해제를 누르면 해당 공유·연결을 종료할 수 있습니다. 접근 권한과 공유 세션은 요청마다 확인합니다.</p>
<form method="post" action="{{ route('cad-mcp.oauth.approve') }}">@csrf<input type="hidden" name="auth_token" value="{{ $authToken }}"><button type="submit">이 CAD 연결 허용</button></form>
<form method="post" action="{{ route('cad-mcp.oauth.deny') }}">@csrf @method('DELETE')<input type="hidden" name="auth_token" value="{{ $authToken }}"><button type="submit">취소</button></form>
</main></body></html>

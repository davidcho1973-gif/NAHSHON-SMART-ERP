<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>ERP 읽기 전용 연결 승인</title>
</head>
<body>
<main>
    <h1>ERP 읽기 전용 연결 승인 (Read-only ERP connection)</h1>
    <p><strong>{{ $client->name }}</strong>에서 {{ $user->name }} 계정으로 ERP 데이터를 읽도록 허용하시겠습니까?</p>
    <p>연결된 클라이언트에 현재 계정이 볼 수 있는 회사·현장 업무 정보, 인사 정보, 급여 정보 및 업무 문서의 허용된 내용이 전달될 수 있습니다. 민감한 데이터가 포함될 수 있으므로 본인이 시작한 연결인지 확인하세요.</p>
    <p>This permits the connected client to read data your ERP account is allowed to access, including permitted HR, payroll and business documents. This connection cannot create, edit or delete ERP records. Passwords, API keys and other credentials are excluded.</p>
    <p>권한 범위 (Scope): <strong>erp:read</strong></p>
    <p>Resource: {{ config('erp_mcp.resource') }}</p>
    <p>접근 권한은 매 요청마다 다시 확인합니다. 계정 또는 OAuth 연결이 해제되면 접근이 중단됩니다.</p>
    <form method="post" action="{{ route('erp-mcp.oauth.approve') }}">
        @csrf
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit">읽기 전용 접근 허용 (Allow read-only access)</button>
    </form>
    <form method="post" action="{{ route('erp-mcp.oauth.deny') }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit">취소 (Cancel)</button>
    </form>
</main>
</body>
</html>

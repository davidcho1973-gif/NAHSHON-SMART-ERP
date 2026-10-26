<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ \App\Support\Org::name() }} · CAD 도면 편집</title>
<link rel="stylesheet" href="{{ asset('css/cad-editor.css') }}?v={{ filemtime(public_path('css/cad-editor.css')) }}">
<script defer src="{{ asset('js/cad-core.js') }}?v={{ filemtime(public_path('js/cad-core.js')) }}">
</script>
<script defer src="{{ asset('js/cad-fidelity.js') }}?v={{ filemtime(public_path('js/cad-fidelity.js')) }}"></script>
<script defer src="{{ asset('js/cad-reference.js') }}?v={{ filemtime(public_path('js/cad-reference.js')) }}"></script>
<script defer src="{{ asset('js/cad-markup.js') }}?v={{ filemtime(public_path('js/cad-markup.js')) }}">
</script>
<script defer src="{{ asset('vendor/polygon-clipping/polygon-clipping.js') }}?v=0.15.7"></script>
<script defer src="{{ asset('js/cad-markup-geometry.js') }}?v={{ filemtime(public_path('js/cad-markup-geometry.js')) }}"></script>
<script defer src="{{ asset('js/cad-markup-workbench.js') }}?v={{ filemtime(public_path('js/cad-markup-workbench.js')) }}"></script>
<script defer src="{{ asset('js/cad-recovery.js') }}?v={{ filemtime(public_path('js/cad-recovery.js')) }}"></script>
<script defer src="{{ asset('js/cad-print.js') }}?v={{ filemtime(public_path('js/cad-print.js')) }}"></script>
<script defer src="{{ asset('js/cad-review.js') }}?v={{ filemtime(public_path('js/cad-review.js')) }}"></script>
<script defer src="{{ asset('js/cad-review-workbench.js') }}?v={{ filemtime(public_path('js/cad-review-workbench.js')) }}"></script>
<script defer src="{{ asset('js/cad-workflow.js') }}?v={{ filemtime(public_path('js/cad-workflow.js')) }}"></script>
<script defer src="{{ asset('js/cad-packets.js') }}?v={{ filemtime(public_path('js/cad-packets.js')) }}"></script>
<script defer src="{{ asset('js/cad-markup-tools.js') }}?v={{ filemtime(public_path('js/cad-markup-tools.js')) }}">
</script>
<script defer src="{{ asset('js/cad-sketch-tools.js') }}?v={{ filemtime(public_path('js/cad-sketch-tools.js')) }}"></script>
<script defer src="{{ asset('js/cad-editor.js') }}?v={{ filemtime(public_path('js/cad-editor.js')) }}">
</script>
</head>
<body data-workspace="markup" data-editor-owner="{{ auth()->id() }}">
<header>
<a href="/">← ERP</a>
<strong class="brand" title="{{ \App\Support\Org::name() }}">{{ \App\Support\Org::name() }} <span>Drawing Studio</span>
</strong>
<select id="sites" aria-label="현장">
</select>
<select id="drawings" aria-label="도면">
<option value="">도면 선택</option>
</select>
<label class="button">CAD / PDF / 이미지 올리기<input id="upload" type="file" accept=".dwg,.dxf,.pdf,.png,.jpg,.jpeg" hidden>
</label>
<button id="save">저장 Ctrl+S</button>
<button id="reload">다시 열기</button>
<button id="history">변경 이력</button>
<button id="print-markups">출력 작업대 / PDF</button>
<button id="original">업로드 원본</button>
<button id="dxf">CAD만 DXF</button>
<button id="dwg">CAD만 DWG</button>
<span id="dirty">
</span>
<button id="ai-toggle" type="button" aria-expanded="false" aria-controls="ai-panel">AI 연결</button></header>
<section id="claim-context" hidden>
<strong id="claim-context-label">
</strong>
<button id="claim-attach">저장한 CAD 개정을 기성 증빙에 연결</button>
<button id="claim-return">기성 편집으로 돌아가기</button>
<span id="claim-result" role="status">
</span>
</section>
<div id="capability" role="status">변환 엔진 확인 중…</div>
<nav class="workspace-bar" aria-label="작업 모드">
<div class="mode-switch">
<button data-workspace="markup" aria-pressed="true">도면 위 표시</button>
<button data-workspace="cad" aria-pressed="false">CAD 정밀 편집</button>
</div>
<span class="note">표시는 원본과 분리 저장 · DXF / DWG에는 표시 제외</span>
<label>
<input id="markup-visible" type="checkbox" checked> 표시 보기</label>
<span id="markup-count">0개 표시</span>
<label class="canvas-background-control" title="화면 배경만 변경합니다. 원본 도면과 출력 색상은 유지됩니다.">도면 배경
<select id="canvas-background" aria-label="도면 배경">
<option value="dark">어둡게</option>
<option value="white">흰색</option>
</select>
</label>
</nav>
<main>
<aside id="tools">
<section class="markup-only">
<div class="panel-eyebrow">MARKUP TOOLS</div>
<h2>도면에 표현하기</h2>
<div id="markup-tools">
</div>
<p class="note">영역 · 강조 · 변경 사항을 도면 위에 남기세요.</p>
</section>
<section class="cad-only">
<h2>그리기 / 편집</h2>
<div id="tool-buttons">
</div>
<section id="tape-panel" aria-label="줄자와 수치 그리기">
<label>줄자 모드<select id="tape-mode"><option value="distance">거리 측정 · 저장 안 함</option><option value="parallel">평행 안내선 · 기준 선 클릭</option><option value="point">안내점 · 시작점 클릭</option></select></label>
<label><input id="direction-inference" type="checkbox" checked> 자동 방향 추론 (2D XY)</label><label>각도 간격 °<input id="inference-step" type="number" min="0" max="180" value="15" step="1"></label><p class="note">가로 X 빨강 · 세로 Y 초록 · 기준선 평행/직각 파랑. 3° 이내 자동 맞춤. → X, ←/↑ Y, ↓ 해제, Shift 현재 방향 고정. 1200mm&lt;80 으로 정확한 80°.</p>

<p id="numeric-help" class="note">선: 1200mm · 사각형: 1200mm,800mm · 원: 반지름 또는 D500mm. 캔버스에서 바로 입력 후 Enter. Esc 취소.</p>
<label><input id="guide-visible" type="checkbox" checked> 안내선·안내점 보기 / 스냅</label><button id="guide-clear" type="button">안내선·안내점 모두 삭제</button><div id="guide-list"></div>
<details><summary>줄자 사용 안내</summary><p class="note">T: 줄자. 거리: 두 점 클릭. 평행선: 선·폴리선의 선분 클릭 → 옆으로 이동 → 클릭 또는 거리 입력. 안내점: 시작점 → 방향 → 거리 입력 또는 클릭. 방향키 → X / ↑ Y, ↓ 잠금 해제. Shift: 가까운 축. 끝점·중점·중심과 안내선의 점·교차점에 스냅됩니다.</p><p class="note">안내선은 저장·실행 취소할 수 있지만 DXF·DWG·수량에는 포함하지 않습니다. 실제 단위는 원본 기준을 먼저 지정하세요. 전체 모델 크기는 바꾸지 않습니다.</p><button id="tape-calibration" type="button">표시 면적·길이의 단위·축척 설정 열기</button></details>
</section>
<label>치수 표시 단위<select id="dimension-unit" aria-label="치수 표시 단위">
<option value="drawing">원본 도면 단위</option>
<option value="in">인치 (in)</option>
<option value="ft">피트 (ft)</option>
<option value="ft-in">피트 + 인치 (ft-in)</option>
<option value="mm">밀리미터 (mm)</option>
<option value="cm">센티미터 (cm)</option>
<option value="m">미터 (m)</option>
</select></label>
<label id="source-unit-control" hidden>원본 1단위의 기준<select id="source-unit" aria-label="원본 도면 기준 단위">
<option value="">기준 단위 선택</option><option value="in">1 인치</option><option value="ft">1 피트</option><option value="mm">1 mm</option><option value="cm">1 cm</option><option value="m">1 m</option>
</select></label>
<p id="dimension-unit-note" class="note" role="status"></p>
<label>치수 방향<select id="dimension-mode">
<option value="aligned">정렬 · 실제 거리</option>
<option value="horizontal">수평 거리</option>
<option value="vertical">수직 거리</option>
</select>
</label>
<p class="note">두 점 뒤 마우스로 치수 위치를 옮기고 클릭하세요. 기준 도형을 수정하면 다시 측정하세요.</p>
<details open>
<summary>새 객체 기본값</summary>
<label>선·박스 색<input id="default-color" type="color" value="#62dbed">
</label>
<label>선 굵기 (mm)<input id="default-width" type="number" min="0" max="2.11" step="0.01" value="0.25">
</label>
<p class="note">문자 내용은 캔버스를 클릭한 뒤 입력창에서 작성합니다.</p>
<label>새 글자 크기<input id="default-text-height" type="number" min="0.001" step="any" value="2.5">
</label>
<label>새 글자 색<input id="default-text-color" type="color" value="#e2e8f0">
</label>
</details>
<h2>수치 입력 <small id="length-input-unit">(도면 단위)</small></h2>
<label>X <input id="x" type="number" step="any" value="0">
</label>
<label>Y <input id="y" type="number" step="any" value="0">
</label>
<button id="coordinate">좌표로 점 지정</button>
<label>각도(도) <input id="angle" type="number" step="any" value="0">
</label>
<label>배율 <input id="factor" type="number" min="0.0001" step="any" value="1">
</label>
<h2>정밀 편집</h2>
<label>오프셋 거리 <input id="offset-distance" type="number" step="any" value="10">
</label>
<button id="offset">선·원 오프셋</button>
<button id="trim">두 선 자르기</button>
<button id="extend">두 선 연장</button>
<p class="note">Shift로 대상 선 → 기준 선 순서로 선택. 자르기는 X/Y 좌표와 가까운 끝을 제거합니다.</p>
<label>배열 행 <input id="rows" type="number" value="2" min="1">
</label>
<label>배열 열 <input id="cols" type="number" value="2" min="1">
</label>
<label>배열 X 간격 <input id="array-x" type="number" step="any" value="100">
</label>
<label>배열 Y 간격 <input id="array-y" type="number" step="any" value="100">
</label>
<button id="array">선택 객체 사각 배열</button>
</section>
<h2>화면 / 보조</h2>
<button id="fit">전체 보기 Shift+Z</button>
<button id="undo">실행 취소 Ctrl+Z</button>
<button id="redo">다시 실행 Ctrl+Y</button>
<label>
<input id="grid" type="checkbox" checked> 격자</label>
<label>격자 간격 <input id="spacing" type="number" min="0.000001" step="any" value="10">
</label>
<label>
<input id="ortho" type="checkbox"> 직교</label>
<label>
<input id="snap" type="checkbox" checked> 끝점·중점·중심 스냅</label>
</aside>
<section id="viewport">
<div id="drawing-state" data-state="empty" role="status" aria-live="polite">
<strong id="drawing-state-title">도면을 선택하거나 업로드하세요</strong>
<p id="drawing-state-message">현장을 선택한 뒤 DWG / DXF 파일을 올리면 이곳에 도면이 표시됩니다.</p>
<button id="drawing-state-dismiss" type="button" hidden>기존 도면 계속 편집</button>
</div>
<canvas id="canvas" tabindex="0" aria-label="CAD 도면 편집 영역: 휠 확대, 가운데 버튼 화면 이동, Shift 선택 추가">
</canvas>
<div id="measurement-hud" class="cad-only" aria-label="길이와 방향 입력"><output id="tape-readout" aria-live="polite"></output><label><span id="numeric-label">그리는 길이</span><input id="numeric-length" type="text" autocomplete="off" aria-describedby="numeric-help" placeholder="방향을 가리키고 길이 입력"></label></div>
<div id="annotation-readout" role="status" aria-live="polite">
</div>
<button id="drawing-fidelity" hidden></button>
<div id="hint">
</div>
</section>
<aside id="properties">
<section class="markup-only">
<div class="panel-eyebrow">PROPERTIES</div>
<h2 id="markup-property-title">새 표시 기본값</h2>
<p id="markup-selection" class="note">선택한 표시 없음</p>
<div class="markup-palette" aria-label="표시 색상">
<button data-markup-color="#38bdf8" aria-label="하늘색">
</button>
<button data-markup-color="#34d399" aria-label="초록색">
</button>
<button data-markup-color="#fbbf24" aria-label="노란색">
</button>
<button data-markup-color="#fb7185" aria-label="분홍색">
</button>
<button data-markup-color="#a78bfa" aria-label="보라색">
</button>
</div>
<label class="property-row">색상<input id="markup-color" type="color" value="#38bdf8">
</label>
<label>불투명도 <output id="markup-opacity-value">35%</output>
<input id="markup-opacity" type="range" min="5" max="100" value="35">
</label>
<label>선 두께<input id="markup-width" type="number" min="1" max="40" step="1" value="2">
</label>
<label>영역 무늬<select id="markup-pattern">
<option value="solid">단색</option>
<option value="diagonal">사선 해치</option>
<option value="cross">교차 해치</option>
</select>
</label>
<label>글자 / 구름 / 핀 크기 조절<select id="markup-size">
<option value="100">현재 크기</option>
<option value="75">75%로 줄이기</option>
<option value="125">125%로 늘리기</option>
<option value="150">150%로 늘리기</option>
<option value="200">200%로 늘리기</option>
</select>
</label>
<div class="action-grid">
<button id="markup-edit-text">내용 수정</button>
<button id="markup-copy">복사</button>
<button id="markup-front">맨 앞으로</button>
<button id="markup-back">맨 뒤로</button>
<button id="markup-lock">잠금</button>
<button id="markup-delete" class="danger">삭제</button>
</div>
<details class="preset-panel">
<summary>내 도구함</summary>
<p class="note">자주 쓰는 색·두께·무늬를 이 브라우저에 저장합니다.</p>
<select id="markup-presets" aria-label="내 도구 선택">
</select>
<input id="markup-preset-name" maxlength="30" placeholder="예: 철거 구역" aria-label="내 도구 이름">
<div class="action-grid">
<button id="markup-preset-save">현재 속성 저장</button>
<button id="markup-preset-delete">도구 삭제</button>
</div>
</details>
<h2>표시 목록</h2>
<div id="markup-workbench"></div>
<p class="note">겹친 표시도 목록에서 선택 · Shift 추가 선택</p>
<div id="markup-list">
</div>
</section>
<section class="cad-only">
<h2>객체 / 레이어</h2>
<div id="selection">선택 0개</div>
<p id="selection-properties" role="status">객체를 선택하면 속성이 표시됩니다.</p>
<fieldset id="native-properties" disabled>
<legend>해치 · 채우기 · 원본 치수</legend>
<label>선택 객체 색<input id="native-color" type="color" value="#e2e8f0"></label>
<button type="button" id="fill-selected">선택 박스·닫힌 선 안쪽 채우기</button>
<button type="button" id="explode-selected">블록 풀기 · 내부 객체 편집</button>
<div id="hatch-properties" hidden>
<label>채우기 불투명도 (10~100%)<input id="hatch-opacity" type="number" min="10" max="100" step="1" value="50"></label>
<label>원본 패턴 배율<input id="hatch-scale" type="number" min="0.000001" step="any" value="1"></label>
<label>원본 패턴 각도 (도)<input id="hatch-angle" type="number" step="any" value="0"></label>
<button type="button" id="apply-hatch">선택 해치·채우기 적용</button>
<p class="note">채움은 테두리와 별도 객체입니다. 함께 옮기려면 Shift로 테두리와 채움을 함께 선택하세요.</p>
</div>
<div id="native-dimension-properties" hidden>
<label>치수 표시 문자 · &lt;&gt;는 실측값<input id="native-dimension-text" type="text" maxlength="2000" value="&lt;&gt;"></label>
<label>치수 문자 크기<input id="native-dimension-height" type="number" min="0.001" step="any" value="2.5"></label>
<button type="button" id="apply-native-dimension">원본 치수 문자 적용</button>
<p class="note">측정점·치수선·문자는 손잡이로 옮깁니다. 연관 치수는 측정점이 보호됩니다.</p>
</div>
</fieldset>
<fieldset id="text-properties">
<legend>선택 문자</legend>
<label>문자 내용<textarea id="text" rows="3">
</textarea>
</label>
<label>글자 크기<input id="height" type="number" min="0.001" step="any" value="2.5">
</label>
<label>글자 색<input id="text-color" type="color" value="#e2e8f0">
</label>
<button id="apply-text">선택 문자 수정</button>
</fieldset>
<button id="edit-text-dialog">입력창에서 문자 편집</button>
<label>작업 레이어 <select id="layer">
</select>
</label>
<button id="new-layer">레이어 추가</button>
<button id="rename-layer">레이어 이름 변경</button>
<button id="assign-layer">선택 객체 레이어 적용</button>
<label>객체 색상 <input id="entity-color" type="color" value="#e2e8f0">
</label>
<label>선 형식 <select id="entity-linetype">
<option>BYLAYER</option>
<option>CONTINUOUS</option>
</select>
</label>
<label>선 굵기 (mm / -1 레이어) <input id="entity-width" type="number" step="0.01" min="-3" max="2.11" value="0.25">
</label>
<button id="apply-style">선택 선·도형 색 / 굵기 적용</button>

<div id="layers">
</div>
<p class="note">2D 모델 공간 편집. 3D·미지원 특수 객체는 원본을 보존합니다. 지원 범위는 선택한 객체에 표시됩니다. 특수 객체와 외부 참조는 원래 CAD에서 검증하세요.</p>
</section>
<details class="drawing-info">
<summary>도면 호환성 / 원본 안내</summary>
<div id="warnings">
</div>
</details>
<div id="revisions">
</div>
</aside>
</main>
<footer id="status" role="status" aria-live="polite">도면을 선택하거나 업로드하세요.</footer>
<dialog id="text-dialog">
<form method="dialog">
<h2 id="text-dialog-title">문자 입력</h2>
<label>내용<textarea id="text-dialog-content" rows="7" maxlength="20000" placeholder="문자를 입력하세요. Enter로 줄바꿈">
</textarea>
</label>
<label>글자 크기<input id="text-dialog-height" type="number" min="0.001" step="any" value="2.5">
</label>
<label>글자 색<input id="text-dialog-color" type="color" value="#e2e8f0">
</label>
<label>문자 박스 폭 · 0은 자동<input id="text-dialog-width" type="number" min="0" step="any" value="0">
</label>
<p id="text-dialog-error" role="alert">
</p>
<div>
<button id="text-dialog-confirm" type="button">확인 · Ctrl+Enter</button>
<button id="text-dialog-cancel" type="button">취소 · Esc</button>
</div>
</form>
</dialog>
<dialog id="markup-dialog">
<form method="dialog">
<h2 id="markup-dialog-title">연결 메모</h2>
<label for="markup-dialog-text">내용</label>
<textarea id="markup-dialog-text" rows="5" maxlength="500">
</textarea>
<p id="markup-dialog-error" role="alert">
</p>
<div class="dialog-actions">
<button type="button" id="markup-dialog-cancel">취소 · Esc</button>
<button type="button" id="markup-dialog-confirm">확인 · Ctrl+Enter</button>
</div>
</form>
</dialog>
<section id="cad-print">
<h1 id="cad-print-title">
</h1>
<img id="cad-print-image" alt="현재 도면 화면과 표시">
<p id="cad-print-note">
</p>
</section>
<section id="ai-panel" hidden aria-label="CAD AI 연결"><button id="ai-close" type="button">도면으로 돌아가기</button><h2>기존 AI 계정 연결</h2><p>사용 중인 ChatGPT·Claude·Gemini에서 지원하는 MCP 연결 설정을 확인하세요. 연결 지원 여부는 서비스와 계정에 따라 다릅니다. ERP에 AI API 키를 입력하지 않습니다.</p><p><a href="https://chatgpt.com" target="_blank" rel="noopener">ChatGPT</a> · <a href="https://claude.ai" target="_blank" rel="noopener">Claude</a> · <a href="https://gemini.google.com" target="_blank" rel="noopener">Gemini</a></p><label>MCP 서버 주소<input id="ai-server-url" readonly></label><button id="ai-copy-url" type="button">서버 주소 복사</button><p id="ai-config-reason"></p><label>공유 범위<select id="ai-share-scope"><option value="selection">선택한 객체</option><option value="visible">보이는 객체</option></select></label><label>도면 1단위의 실제 단위<select id="ai-unit"><option value="">단위를 직접 선택하세요</option><option value="drawing">실제 단위 미지정 · 도면 단위</option><option value="mm">mm</option><option value="cm">cm</option><option value="m">m</option><option value="in">inch</option><option value="ft">feet</option></select></label><label><input id="ai-allow-edits" type="checkbox">AI 편집 요청 허용 · 기본은 확인 후 적용</label><label><input id="ai-auto-apply" type="checkbox">검증된 편집 요청 자동 적용</label><p>CAD 원본 객체만 공유하며 별도 도면 표시·메모는 포함하지 않습니다. 선택 공유는 CAD 편집 모드에서 선택한 원본 객체를 기준으로 합니다. 공유를 시작한 객체만 전달합니다. 선택·범위를 바꾸려면 공유 내용 갱신을 누르세요. 수량은 선택한 도면 단위를 사용하며 기성 수량·저장은 자동 변경하지 않습니다.</p><button id="ai-start" type="button">현재 도면 공유 시작</button><button id="ai-refresh" type="button" disabled>공유 내용 갱신</button><button id="ai-stop" type="button" disabled>공유 중지</button><p id="ai-status" role="status" aria-live="polite">공유하지 않음</p><h3>대기 중인 편집 요청</h3><div id="ai-command-list"></div><h3>허용한 연결</h3><button id="ai-reload-config" type="button">연결 목록 새로고침</button><div id="ai-connections"></div></section>
</body>
</html>

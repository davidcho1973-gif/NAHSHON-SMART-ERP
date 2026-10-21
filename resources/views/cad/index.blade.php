<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>나손 ERP · CAD 도면 편집</title>
<link rel="stylesheet" href="{{ asset('css/cad-editor.css') }}?v={{ filemtime(public_path('css/cad-editor.css')) }}">
<script defer src="{{ asset('js/cad-core.js') }}?v={{ filemtime(public_path('js/cad-core.js')) }}">
</script>
<script defer src="{{ asset('js/cad-markup.js') }}?v={{ filemtime(public_path('js/cad-markup.js')) }}">
</script>
<script defer src="{{ asset('js/cad-markup-tools.js') }}?v={{ filemtime(public_path('js/cad-markup-tools.js')) }}">
</script>
<script defer src="{{ asset('js/cad-editor.js') }}?v={{ filemtime(public_path('js/cad-editor.js')) }}">
</script>
</head>
<body data-workspace="markup">
<header>
<a href="/">← ERP</a>
<strong class="brand">NAHSHON <span>Drawing Studio</span>
</strong>
<select id="sites" aria-label="현장">
</select>
<select id="drawings" aria-label="도면">
<option value="">도면 선택</option>
</select>
<label class="button">DWG / DXF 올리기<input id="upload" type="file" accept=".dwg,.dxf" hidden>
</label>
<button id="save">저장 Ctrl+S</button>
<button id="reload">다시 열기</button>
<button id="history">변경 이력</button>
<button id="print-markups">현재 화면 인쇄 / PDF</button>
<button id="original">업로드 원본</button>
<button id="dxf">CAD만 DXF</button>
<button id="dwg">CAD만 DWG</button>
<span id="dirty">
</span>
</header>
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
<h2>수치 입력</h2>
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
<button id="fit">전체 보기 F</button>
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
<p class="note">겹친 표시도 목록에서 선택 · Shift 추가 선택</p>
<div id="markup-list">
</div>
</section>
<section class="cad-only">
<h2>객체 / 레이어</h2>
<div id="selection">선택 0개</div>
<p id="selection-properties" role="status">객체를 선택하면 속성이 표시됩니다.</p>
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
<p class="note">2D 모델 공간 편집. 미지원 객체·3D·곡선 폴리선은 읽기 전용으로 원본 레코드를 보존합니다. 특수 객체와 외부 참조는 원래 CAD에서 검증하세요.</p>
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
</body>
</html>

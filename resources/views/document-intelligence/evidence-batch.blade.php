@if($canEvidenceManage)
<dialog class="upload-dialog evidence-dialog" id="evidence-dialog" aria-labelledby="evidence-title">
    <div class="panel-head"><div><h2 id="evidence-title">기성 자료 넣기</h2><p>현장 선택 → 파일 넣기 → 처리 시작</p></div><button class="btn small" type="button" id="evidence-close">닫기</button></div>
    <div class="drop-panel">
        <p id="evidence-feedback" role="status" aria-live="polite"></p>
        <div class="field"><label for="evidence-project">현장 · 프로젝트</label><select id="evidence-project"><option value="">불러오는 중…</option></select></div>
        <div class="field" id="evidence-contract-field" hidden><label for="evidence-contract">기성 계약</label><select id="evidence-contract"></select></div>
        <p class="doc-sub" id="evidence-scope-note"></p>
        <div class="dropzone" id="evidence-drop"><div><strong>영수증 · 입고내역서 · 사진 · 문서</strong><p>구분하지 않고 함께 넣으세요.</p><button class="btn" id="evidence-pick" type="button">파일 선택</button> <button class="btn" id="evidence-folder" type="button">폴더 선택</button><small>한 번에 최대 1,000개 · 파일당 {{ $maxUploadMb }}MB</small></div></div>
        <input type="file" id="evidence-files" multiple hidden>
        <input type="file" id="evidence-directory" multiple webkitdirectory hidden>
        <div id="evidence-local" hidden><p id="evidence-selected"></p><progress id="evidence-progress" max="100" value="0" aria-label="업로드 진행"></progress><details><summary>선택한 파일</summary><div id="evidence-selected-rows"></div></details></div>
        <div class="evidence-actions"><button class="btn primary" id="evidence-upload" type="button" disabled>업로드</button><button class="btn primary" id="evidence-start" type="button" hidden>처리 시작</button><button class="btn" id="evidence-new" type="button">새로 넣기</button></div>
        <p class="doc-sub" id="evidence-help">업로드 중에는 이 화면을 유지하세요. 처리 시작 후에는 닫아도 됩니다.</p>
        <section id="evidence-result" hidden aria-label="자료 처리 결과">
            <h3 id="evidence-state"></h3><div class="evidence-counts" id="evidence-counts"></div>
            <div class="evidence-actions"><label><input type="checkbox" id="evidence-review-only"> 확인 필요만</label><button type="button" class="btn small" id="evidence-retry" hidden>실패만 재시도</button><button type="button" class="btn small" id="evidence-rematch" hidden>공정 다시 연결</button><a class="btn small" id="evidence-claim" hidden target="_top">기성 증빙 보기</a></div>
            <div id="evidence-rows"></div><div class="desk-pagination"><span id="evidence-page-label"></span><div class="tools"><button class="btn small" id="evidence-prev">이전</button><button class="btn small" id="evidence-next">다음</button></div></div>
        </section>
        <details class="detail-secondary" id="evidence-history"><summary>지난 접수 · 이어서 보기</summary><div id="evidence-batches"></div></details>
    </div>
</dialog>
@endif

/**
 * 등록을 마친 휴대폰을 기억할지 말지 — 등록 화면 둘이 함께 쓰는 단 하나의 규칙.
 *
 * ── 왜 «그냥 기억» 이 아닌가 ──────────────────────────────────────────
 * 한 대로 한 사람만 등록했으면 그 사람의 폰이다. 기억해 두면 다음부터 게이트에서
 * 이름을 찾지 않아도 된다.
 *
 * 그런데 반장이 자기 폰으로 팀원을 여러 명 등록하는 일이 잦다. 그때 마지막 사람으로
 * 기억해 버리면 반장이 게이트에 폰을 댈 때마다 그 팀원의 출근이 찍힌다 — 남의
 * 근무시간이 만들어지는 것이고, 아무도 원인을 모른 채 급여까지 간다.
 *
 * 그래서 두 사람 이상이 등록된 폰은 <b>누구의 것으로도</b> 기억하지 않는다.
 *
 * ── 왜 파일로 빼 뒀나 ────────────────────────────────────────────────
 * 등록 화면이 둘이다(인사담당자용 worker-join/form · 현장 공용 worker-join/quick).
 * 이 판단이 화면마다 적혀 있으면 한쪽만 고쳐지고, 고쳐지지 않은 쪽에서 위의 사고가
 * 조용히 다시 일어난다. 한 곳에 둔다.
 *
 * @param {string} employeeId 방금 등록된 사람
 * @param {string} deviceToken 서버가 발급한 기기 토큰(원문)
 * @param {string} lang 이 사람이 고른 언어
 * @returns {boolean} true = 이 폰은 여러 사람이 써서 기억하지 않았다
 */
window.rememberWorkerDevice = function (employeeId, deviceToken, lang) {
    var TOKEN_KEY = 'dasolWorkerDevice';
    var OWNER_KEY = 'workerJoinLastPerson';
    var shared = false;

    try {
        var previous = localStorage.getItem(OWNER_KEY);
        // 서버가 토큰을 안 줬다면 그쪽이 이미 «공용 폰» 이라고 판단한 것이다.
        shared = (!!previous && previous !== String(employeeId)) || !deviceToken;

        if (shared) {
            // 지운 토큰은 어디에도 남지 않으므로 그 자리에서 쓸 수 없게 된다.
            localStorage.removeItem(TOKEN_KEY);
        } else {
            localStorage.setItem(TOKEN_KEY, deviceToken);
        }

        localStorage.setItem(OWNER_KEY, String(employeeId));
        if (lang) {
            localStorage.setItem('dasolWorkerLang', lang);
        }
    } catch (e) {
        // 사파리 시크릿 창 등 저장이 막힌 환경 — 기억하지 못할 뿐 등록은 이미 끝났다.
    }

    return shared;
};

/**
 * 등록 폼에 «이 휴대폰은 이미 누구를 등록했는가» 를 실어 보낸다.
 *
 * 서버가 그 값을 보고 공용 휴대폰이면 이 사람으로 연결하지 않는다 — 반장 폰이
 * 마지막 팀원의 출퇴근 열쇠가 되는 것을 막는 규칙이고, 판단은 서버가 한다.
 * 값을 지어내면 연결이 <b>안 되는</b> 방향이라 거짓말로 얻을 것이 없다.
 *
 * @param {HTMLFormElement} form 등록 폼
 */
window.markRegisteringPhone = function (form) {
    if (!form) {
        return;
    }

    var field = form.querySelector('input[name="device_owner"]');
    if (!field) {
        return;
    }

    try {
        field.value = localStorage.getItem('workerJoinLastPerson') || '';
    } catch (e) {
        // 저장이 막힌 브라우저 — 알릴 것이 없으면 빈 값이고, 그건 «처음 쓰는 폰» 과 같다.
    }
};

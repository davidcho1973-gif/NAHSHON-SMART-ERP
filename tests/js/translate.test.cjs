/**
 * 영어·스페인어 번역 규칙 — 낱말 가운데를 잘라 먹지 않는가.
 *
 * 한국어에는 낱말 사이에 띄어쓰기가 없다. 그래서 사전에 짧은 말을 하나 넣으면
 * 그 말이 들어간 <b>모든</b> 긴 낱말이 함께 부서진다. '퇴근' 하나 때문에
 * "출퇴근 기록" 이 "출Check Out 기록" 이 됐고, '장비' 때문에 "중장비" 가
 * "중Equipment" 가 됐다. 화면 곳곳에서 50군데가 그랬다.
 *
 * 눈으로만 잡으면 다음에 짧은 말을 하나 더 넣는 순간 조용히 되살아난다.
 *
 * php artisan test 가 이 파일을 node 로 돌린다(TranslationRulesTest).
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const file = path.join(__dirname, '../../public/js/smart-language.js');

// 브라우저 흉내 — document 는 일부러 주지 않는다. 없으면 화면에 손대지 않는다.
const sandbox = {
  window: { ORG_NAME: 'ABC 건설' },
  localStorage: { getItem: () => 'en', setItem: () => {} },
  TextDecoder,
  Uint8Array,
  console,
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(file, 'utf8'), sandbox, { filename: 'smart-language.js' });

const { translateToEnglish } = sandbox.window.__i18n;

let failed = 0;
function check(name, actual, expected) {
  const ok = expected instanceof RegExp ? expected.test(actual) : actual === expected;
  if (!ok) {
    failed += 1;
    console.error(`  ✗ ${name}\n      나온 값: ${JSON.stringify(actual)}\n      기대한 값: ${expected}`);
  }
}

// ── 낱말이 잘리지 않는다 ────────────────────────────────────────────────
// 앞에 한글이 붙어 있으면 더 큰 낱말의 일부다. 건드리지 않는다.
check('출퇴근', translateToEnglish('출퇴근 기록'), 'Attendance Records');
check('출퇴근 등록', translateToEnglish('출퇴근 등록'), /^(?!.*출Check).*/);
check('중장비', translateToEnglish('중장비 가동'), /^(?!.*중Equipment).*/);
check('담당자', translateToEnglish('담당자 성함'), /^(?!.*Owner자).*/);
check('자재장비', translateToEnglish('자재장비'), 'Materials & Equipment');

// ── 조사는 함께 지운다 ──────────────────────────────────────────────────
// "회사를" 의 '를' 은 낱말이 아니라 문법이라 영어에 옮길 자리가 없다.
check('조사 를', translateToEnglish('회사를'), 'Company');
check('조사 에서', translateToEnglish('장비에서'), 'Equipment');

// ── 조사가 아닌 한글이 붙으면 그냥 둔다 ────────────────────────────────
// 번역 안 된 한국어는 읽을 수 있지만, 반쯤 잘린 낱말은 읽을 수 없다.
check('장비별', translateToEnglish('장비별'), '장비별');

// ── 메뉴 이름 ───────────────────────────────────────────────────────────
// 여기가 한글로 남으면 영어를 골라도 첫 화면부터 한국어다.
check('내 출퇴근 기록', translateToEnglish('내 출퇴근 기록'), 'My Attendance');
check('조직 설정', translateToEnglish('조직 설정'), 'Organization Settings');
check('계정 · 권한 관리', translateToEnglish('계정 · 권한 관리'), 'Accounts & Permissions');
check('현장 상황실', translateToEnglish('현장 상황실'), 'Field Operations Room');
check('문서통합관리', translateToEnglish('문서통합관리'), 'Document Management');

// ── 고객사 이름은 배포마다 다르다 ──────────────────────────────────────
check('회사 이름 치환', translateToEnglish('ABC 건설 통합관리'), 'ABC 건설 Operations');

if (failed > 0) {
  console.error(`\n번역 규칙 ${failed}건 실패`);
  process.exit(1);
}
console.log('번역 규칙 통과');

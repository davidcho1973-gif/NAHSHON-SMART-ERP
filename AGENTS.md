# AGENTS.md — DASOL PRISM SMART ERP 멀티 에이전트 협업 가이드

> 이 문서는 **CODEX · Antigravity · Cowork(Claude)** 세 AI 에이전트가 같은 저장소에서
> 동시에 작업할 때 충돌 없이 협업하기 위한 단일 조율 문서입니다.
> **작업 시작 전 반드시 이 파일을 먼저 읽고, 끝낼 때 "진행 로그"를 갱신하세요.**

- Repo: https://github.com/davidcho1973-gif/NAHSHON-SMART-ERP
- 배포: https://cloud.laravel.com/davidcho1973/nahshon-smart-erp/main (`main` 브랜치 → Laravel Cloud 자동 배포)
- 오너: David (davidcho1973@gmail.com)
- 최종 갱신: 2026-08-18 — 일하는 원칙(§ 맨 앞) 추가, §1 을 실제 코드에 맞춰 다시 씀 (그 전 갱신 2026-06-20)

---

## 일하는 원칙 — 증상이 아니라 원인을 고친다

> **오너 지시 (2026-08-18):**
> *"앞으로 모든 문제는 편법 대안보다 근본적인 원인을 찾아 근본적으로 고치도록 해.
> 임기응변식 대응은 피해."*
>
> 이 절은 `tests/Feature/RepoFactsTest.php` 가 지킨다. 지우면 시험이 깨진다.

문제를 만나면 **고치기 전에 왜 그렇게 됐는지부터 답한다.** 원인을 못 적으면 아직
고칠 준비가 안 된 것이다.

**하지 않는 것**

- 증상만 막는 분기(`if` 하나로 그 화면만 통과시키기)
- 어긋난 두 곳을 맞추는 세 번째 층(다리·동기화·보정 테이블을 하나 더 얹기)
- "일단 되게 해두고 나중에" — 나중은 오지 않고, 다음 사람은 그게 설계인 줄 안다
- 시험을 고쳐서 통과시키기

**하는 것**

1. **원인을 한 문장으로 적는다.** 커밋 메시지나 코드 주석에 남긴다.
2. **그 원인을 없앤다.** 같은 사실이 두 곳에 있으면 한 곳으로 모으고, 규칙이 두 벌이면
   한 벌로 만든다. 화면이 아니라 구조를 고친다.
3. **다시 못 들어오게 막는다.** 시험으로 잠근다. 시험이 없으면 같은 일이 반드시 다시 온다 —
   이 저장소에서만 세 번 겪었다.

**이 저장소의 선례** — 새 문제도 이 형태로 끝낸다.

| 겪은 일 | 증상만 고쳤다면 | 실제로 한 것 | 잠근 시험 |
|---|---|---|---|
| 안내 문서가 걷어낸 Filament 를 현재형으로 말해 기획서 한 판을 버렸다 | 문서 한 줄 수정 | 문서 + `config/database.php` 기본값까지 사실과 맞추고, 문서가 코드와 어긋나면 깨지게 함 | `RepoFactsTest` |
| 병합 충돌 표시가 커밋에 들어갔다 | 그 파일만 손으로 정리 | 저장소 전체에서 금지 | `NoMergeMarkersTest` |
| 인원을 세는 규칙이 모바일앱과 상황실에 두 벌 있었다 | 두 숫자를 맞추는 보정 로직 추가 | 규칙을 `DailyHeadcountService` 한 곳으로 모음 | `HeadcountSingleSourceTest` |
| 현장 일일보고와 일일 마감이 같은 `(현장, 날짜)` 를 각자 갖고 있었다 | 둘을 베끼는 동기화 다리 추가 | 표 하나로 합치고 `field_daily_reports` 를 없앰 | `OneDailyReportTest` |

**예외는 없지만 급할 때는 있다.** 지금 당장 막아야 하면 막되, **그 자리에 왜 임시인지와
진짜 원인을 주석으로 남기고** 같은 커밋에서 원인 수정까지 끝낸다. 임시만 남기고 커밋을
닫지 않는다.

---

## 0. 지금 진행 중인 기획 — K-TALK / K-BRAIN

- 기획서: [`docs/K-TALK_기획서_v1.1.md`](./docs/K-TALK_기획서_v1.1.md)
- 작업 단위는 그 문서의 **P번호**(P0-1, P1-2 …)를 그대로 쓴다.
- 착수 전에 **부록 A(코드 대조 결과)** 를 먼저 읽는다. §2 의 전제 대부분은 실제 코드와
  맞지만, **검색 방식은 틀렸다** — 이 저장소는 PostgreSQL 이라 MySQL `FULLTEXT` 를 쓸 수 없다.
- 확정 결정(D1~D8)은 바꾸지 않는다. 바꿔야 할 근거가 생기면 문서를 먼저 고치고 시작한다.

> 기획서가 `CLAUDE.md` 참조를 요구하지만 이 저장소에는 그 파일이 없다.
> 에이전트 지침은 이 `AGENTS.md` 한 곳에 둔다 — 같은 내용을 두 파일에 두면
> 한쪽만 고쳐지고 나머지는 몇 달 뒤에 발견된다.

---

## 1. 이 저장소는 무엇인가

> **이 절은 `tests/Feature/RepoFactsTest.php` 가 지킨다.** 여기 적힌 것이 코드와
> 어긋나면 시험이 깨진다. 문서만 고쳐 두면 반드시 낡기 때문이다 — 실제로 그래서
> 기획서 한 판을 버렸다(아래 "겪은 일" 참고).

기존 Google Apps Script "SMART COMPANY" ERP를 **Laravel SPA** 로 옮긴 현장 관리 시스템.
코드는 한 벌, 배포는 고객마다 하나(`DEPLOYMENT_ENVIRONMENTS.md`).

| 항목 | 값 |
|---|---|
| 프레임워크 | Laravel 13 · Livewire 4 |
| 관리자 패널 | **없음.** 2026-07 에 걷어냈다(`e21b20a`). 관리 화면은 전부 ERP SPA 안에 있다 |
| PHP | 8.3+ |
| DB | **PostgreSQL (`pgsql`)** — MySQL 문법(`FULLTEXT` 등)은 쓸 수 없다 |
| 화면 | `resources/views/smart-company/index.blade.php` (SPA 한 장) + `public/js/admin-*.js` |
| **공용 UI 킷** | **`public/js/admin-shell.js` 의 `AdminUI`** — `table` · `formModal` · `pageHeader` · `confirmDanger` · `badge` · `toast` · `uploadFile`. 관리 화면은 전부 이 위에 서 있다 |
| 다국어 | `public/js/smart-language.js` (ko/en/es) |
| 테스트 | PHPUnit (`tests/Feature`, `tests/Unit`) |
| 에이전트 지침 | **이 파일 하나.** `CLAUDE.md` 는 없다 — 같은 내용을 두 파일에 두면 한쪽만 고쳐진다 |

레거시 UI는 `/api/smart-company/{method}` 어댑터로 유지된다.

### 겪은 일 — 이 절이 시험으로 잠긴 이유 (2026-08-18)

2026-07 에 Filament 를 걷어냈다. 코드는 고쳤지만 **이 문서는 안 고쳤다.** 고칠 이유가
없었기 때문이다 — 아무도 강제하지 않았다.

두 달 뒤, 이 문서를 읽고 K-TALK 기획서 v1.0 이 작성됐다. "Filament 기반" 이라는
전제 위에 화면 설계와 일정이 얹혔다. 실코드를 확인한 v1.1 에서 그 전제를 통째로
버려야 했다.

**낡은 문서 한 줄이 기획서 한 판을 버리게 했다.** 그래서 이제 이 절은 시험이 지킨다.

---

## 2. 에이전트별 모듈 분담 (CODEX 확정 — 2026-06-20)

> CODEX가 먼저 시작했으므로 **핵심 도메인은 CODEX 소유**입니다. CODEX가 분담을 확정했습니다.
> CODEX 현재 진행 축: `인원관리 / 회원등록 / 접근제어 / 계정 UI / 관리자 진입 연결`.
> **유일한 겹침 지점은 레거시 `App\Support\SmartCompanyData` 와 `/api/smart-company/*` 응답** —
> Cowork가 안전/장비/재고 API를 실제 DB 기반으로 바꿀 때 이 파일들은 **사전 공유 후** 수정.
> **다른 에이전트 소유 모듈의 파일은 직접 수정하지 말고, 필요하면 PR/질문으로 요청하세요.**

| 에이전트 | 담당 모듈 | 주요 디렉터리 |
|---|---|---|
| **CODEX** (핵심·기준) | 회사/현장/직원, 스마트멤버 등록, 문서, SmartRecord, 근태·AI, 접근제어/인증, **재무·급여(Payroll), WBS, 협력사(Vendor)** | `app/Services/Admin/*`, `app/Models` (핵심), `app/Http/Controllers/GoogleAuthController.php` |
| **Cowork (Claude)** | **안전(Safety), 장비(Equipment), 재고(Inventory)** | `app/Services/Safety`, `app/Services/Inventory`, `public/js/admin-items.js`, 해당 모델·마이그레이션·테스트 |
| **Antigravity** | **프론트엔드/UI, 차량(Vehicle), 임대·숙소(Rental/Housing)**, 다국어(ko/en/es) | `resources/views`, `public/js`, `public/css` |

**공유(누구나 수정 가능하지만 PR 필수):** `composer.json`, `package.json`, `config/*`, `routes/*`, `.github/*`, 이 `AGENTS.md`.
공유 파일 변경 시 PR 설명에 이유를 명시하고, 머지 전 다른 에이전트 작업과의 충돌을 확인합니다.

---

## 3. 브랜치 & PR 워크플로 (필수)

`main`은 **항상 배포 가능 상태**여야 합니다 (Laravel Cloud 자동 배포). `main` 직접 push 금지.

1. 브랜치 네이밍: `<agent>/<module>-<짧은설명>`
   - 예: `codex/payroll-core`, `cowork/safety-resource`, `antigravity/vehicle-ui`
2. 작업 → 로컬 `php artisan test` 통과 확인 → push → **PR 생성**
3. PR은 CI(`tests.yml`) 통과해야 머지. 가능하면 다른 에이전트/오너 리뷰.
4. 머지 후 `main`에 배포. 머지된 브랜치는 삭제.
5. 시작 전 `git pull --rebase origin main`으로 최신화하여 충돌 최소화.

**커밋 메시지:** 명령형 영어 한 줄 (기존 히스토리 컨벤션 유지). 예: `Add safety incident resource`.

---

## 4. 코드 컨벤션 (기존 코드 기준)

**관리 화면 구조** (2026-07 Filament 제거 후) — 화면 하나당 세 조각이다.

- `app/Services/Admin/<Module>AdminService.php` — 목록·저장·권한. `canView()/canManage()` 패턴.
- `public/js/admin-<module>.js` — 화면. **`AdminUI` 위에 조립한다**(표·폼·모달을 새로 짜지 않는다).
- `App\Support\SmartCompanyData` 의 `api_*` 디스패치에 한 줄 — 프론트가 `gsRun('api_...')` 로 부른다.

새 UI 부품이 필요하면 `AdminUI` 에 추가한다. 화면마다 따로 만들면 생김새가 갈라지고,
그때부터는 어느 것이 표준인지 아무도 모른다.
- 테이블은 `->recordActions([...])`, `->toolbarActions([...])` 사용 (구버전 `actions()` 아님).
- 라벨은 한국어 + 괄호 영문 병기. 예: `->label('현장 코드')`, `navigationLabel = '현장 관리 (Sites)'`.
- `navigationGroup = 'SMART COMPANY'` 로 그룹 통일.

**마이그레이션 네이밍:** `YYYY_MM_DD_NNNNNN_<설명>.php`.
- 동시 작업 충돌 방지: **에이전트별 시퀀스 대역 예약** (CODEX 확정)
  - 기존 사용: `2026_06_20_000001`~`000006`.
  - **Cowork: `2026_06_20_000100_*` 부터**, CODEX: `000200_*` 이후, Antigravity: `000300_*` 이후.
- 새 모듈은 **전용 테이블**로 생성 (SmartRecord 범용 저장소에서 점진 분리).
- **scope 컬럼 필수:** 안전/장비/재고 테이블에 `company_id`, `site_id`, (필요시) `team_id`, `employee_id` 포함 → 접근제어(§6-3) 적용 용이.

**PostgreSQL 주의:** 최근 `pgsql json distinct` 이슈 수정 이력 있음 (커밋 `75662e6`). 다대다 select/distinct 쿼리 시 컬럼 명시(`->select('table.col')`) 패턴을 따를 것.

**접근제어:** `users` 테이블에 `access_role`, `access_scope`, `account_status`, `allowed_company_id/site_id/team_id` 존재. 신규 리소스는 이 권한 스킴을 따라야 함 (구체 규칙은 §6에서 CODEX 확인).

**테스트:** 새 모듈마다 `tests/Feature/<Module>Test.php` 최소 1개 (생성/조회). `PostgresSchemaTest` 패턴 참고.

---

## 5. 배포 / 테스트 운영 규칙

- `main` 머지 시 Laravel Cloud가 자동 배포.
- **공식 테스트 흐름은 Staging 배포 방식입니다.** 코드 수정 후 가능한 범위에서 로컬 서버, 로컬 DB, `php artisan test`, `npm run build` 등으로 먼저 확인한 뒤 `staging` 브랜치/Laravel Cloud staging 환경에 배포해 David가 실제 화면과 DB 흐름을 검증합니다.
- **Staging은 테스트용 원격 환경입니다.** David가 "배포", "테스트 배포", "staging 배포"를 요청하면 `staging`에 배포해도 됩니다. 배포 후에는 변경 내용, 테스트 결과, staging 확인 주소를 작업 로그에 남깁니다.
- **Production(main)도 함께 배포합니다 (David 지시 2026-08-18: "메인에도 배포해 앞으로").**
- **확인은 나손(NAHSHON MEP) 환경에서 먼저 합니다 (David 지시 2026-09-01: "현재 모든 작업은
  여기에 배포해줘 우선적으로").** 배포 뒤 "어디를 보시라" 고 안내할 주소는
  `https://erp.nahshonmep.com` (도메인 붙이는 중, 2026-09-03 지시) 이고, 붙기 전까지는
  `https://nahshon-smart-erp-nahshon-mep-hntasf.laravel.cloud` 입니다.
  같은 앱(`nahshon-smart-erp`) 안의 별도 환경이고, 그 앱의 `main` 환경은 DASOL 것입니다 —
  앱 이름만 보고 «나손 = main» 으로 읽지 마세요(`DEPLOYMENT_ENVIRONMENTS.md`).
  `staging` 과 `main` 에 같은 커밋을 올리는 지금 절차면 나손에도 코드가 갑니다.
  staging 검증(전체 테스트 통과)이 끝난 작업은 `staging` 푸시 후 `main` 에도 반영한다.
  방법: staging 을 main 으로 병합(트리 = staging 트리, 되감기 없음). 시험이 하나라도
  깨진 상태로는 main 에 올리지 않는다 — 그 전 규칙("별도 승인 필요")은 이 지시로 폐기.
- 로컬에서 재현하거나 테스트할 수 없는 경우에는 staging에서 검증할 수 있으며, 그 이유와 영향 범위를 David에게 설명하고 결과를 공유합니다.
- 배포 환경 마이그레이션 실행 여부·시드 정책·환경변수(키/토큰) 관리 방식은 **§6 질문에서 CODEX 확인 필요**.
- 비밀값(AI 키, Telegram 토큰, Google 자격증명)은 절대 소스에 커밋 금지 — `.env` / Laravel Cloud 환경변수로만.

---

## 6. CODEX에게 묻는 질문 (David가 CODEX에 전달 → 답을 이 섹션에 기록)

> Cowork가 신규 모듈을 시작하기 전에 확인이 필요한 항목입니다. CODEX가 답변을 아래에 채워주세요.

1. **모듈 분담 확정** — ✅ 확정. Cowork = 안전/장비/재고. 유일 겹침은 `SmartCompanyData`·`/api/smart-company/*` → 사전 공유 후 수정.

2. ~~**Filament 리소스 공통 베이스**~~ *(2026-06 답변 — Filament 는 2026-07 에 제거됐다. 아래는 그때의 기록이다)* — 공통 베이스/트레이트 **없음**. 모든 리소스가 `Filament\Resources\Resource` 직접 상속. 표준 권한 메서드도 아직 없음. 유일한 표준은 `User::canAccessPanel()` — `access_role ∈ {super_admin, admin, hr_manager, site_manager, safety_manager, payroll}` 이면 admin 패널 접근 가능. **신규 리소스는 각 Resource에 `canViewAny/canCreate/canEdit` 명시적 작성 권장.** 반복되면 추후 `App\Filament\Concerns\AuthorizesResourceAccess` trait로 묶기.

3. **접근제어 적용법** — 공통 쿼리 스코프 헬퍼 **없음**. 각 리소스 `getEloquentQuery()`에서 직접 제한. 기준 필드(`users`): `access_role, access_scope, allowed_company_id, allowed_site_id, allowed_team_id, employee_id, account_status`. **해석 규칙:**
   - `super_admin/admin` 또는 `access_scope=all_sites` → 전체
   - `company` → `company_id = allowed_company_id`
   - `site` → `site_id = allowed_site_id`
   - `team` → `team_id = allowed_team_id`
   - `self` → `employee_id = auth()->user()->employee_id`
   → **안전/장비/재고 테이블에 `company_id`, `site_id`, (필요시) `team_id`, `employee_id` 컬럼을 넣어 scope 적용이 쉽게 할 것.**

4. **마이그레이션 시퀀스** — ✅ 대역 방식 동의. 기존 `..000001`~`..000006` 사용 중 (`000004` 중복 존재). **Cowork는 `2026_06_20_000100_*` 부터 사용.** CODEX는 `000200_*` 이후. 규칙: 정렬 순서상 기존 테이블 생성 이후 실행되고 파일명 유일할 것.

5. **다국어 정책** — Filament 라벨은 **한국어/영어 인라인 혼합으로 충분**. 신규 모듈도 인라인. `ko/en/es` 별도 번역 리소스는 만들지 않음. 단, **메인 대시보드에 새 문구를 노출하면** `public/js/smart-language.js`의 en/es 매핑도 같이 추가.

6. **Laravel Cloud 배포** — `composer.json`의 `deploy:prod`에 `php artisan migrate --force` 포함 (단 Cloud build/deploy command 설정은 UI 확인 필요). 원칙: 스키마 변경은 migration으로, **일반 배포 시 seed 자동 실행 안 함**, 최초/명시적 보정 때만 `migrate --seed --force`, seeder는 `updateOrCreate`로 idempotent 유지. 새 환경변수는 Laravel Cloud → Environment/Variables, 로컬은 `.env`, 문서는 README/배포문서에 기록.

7. **테스트 DB** — ✅ **PostgreSQL 기준**. `phpunit.xml`에 `DB_CONNECTION=pgsql`, GitHub Actions는 Postgres 17 서비스 띄우고 `migrate --force` → `php artisan test`. **신규 migration/test는 SQLite 전용 문법 금지, pgsql에서 통과해야 함. JSON/array/distinct 쿼리도 pgsql 기준 확인.**

---

## 7. 진행 로그 → **[`WORK_LOG.md`](./WORK_LOG.md) 참조**

> 작업 진행 기록(타임라인·작업자별 상세)은 루트의 **`WORK_LOG.md`** 한 곳에서 관리합니다.
> 이 `AGENTS.md`는 **규칙·모듈 분담·컨벤션**만 담고, 완료 로그는 남기지 않습니다.
> 작업을 마치면 `WORK_LOG.md`의 타임라인 테이블과 본인 에이전트 섹션에 기록하세요.

---

## 8. 막혔을 때

- 다른 에이전트 소유 영역이 필요하면 **직접 고치지 말고** 이 문서 §6 또는 GitHub Issue로 요청.
- `main`이 깨졌다고 의심되면 push 멈추고 David에게 알림.
- 불확실한 도메인 규칙은 CODEX에게 먼저 확인 (CODEX가 원본 GAS 로직 기준).

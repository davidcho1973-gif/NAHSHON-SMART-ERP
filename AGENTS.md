# AGENTS.md — NAHSHON SMART ERP 멀티 에이전트 협업 가이드

> 이 문서는 **CODEX · Antigravity · Cowork(Claude)** 세 AI 에이전트가 같은 저장소에서
> 동시에 작업할 때 충돌 없이 협업하기 위한 단일 조율 문서입니다.
> **작업 시작 전 반드시 이 파일을 먼저 읽고, 끝낼 때 "진행 로그"를 갱신하세요.**

- Repo: https://github.com/davidcho1973-gif/NAHSHON-SMART-ERP
- 배포: https://cloud.laravel.com/davidcho1973/nahshon-smart-erp/main (`main` 브랜치 → Laravel Cloud 자동 배포)
- 오너: David (davidcho1973@gmail.com)
- 최종 갱신: 2026-06-20 (작성: Cowork)

---

## 1. 프로젝트 개요

기존 Google Apps Script "SMART COMPANY" ERP를 **Laravel + Filament**로 이전하는 프로젝트.

| 항목 | 값 |
|---|---|
| 프레임워크 | Laravel 13, Filament 5 |
| PHP | 8.3+ |
| DB | PostgreSQL (`pgsql`) |
| 프론트(레거시) | Blade + `public/js/smart-language.js` (ko/en/es 다국어), `public/css/smart-company.css` |
| 테스트 | PHPUnit 12 (`tests/Feature`, `tests/Unit`) |
| CI | GitHub Actions — `tests.yml`, `pull-requests.yml`, `issues.yml`, `update-changelog.yml`, `dependabot-auto-merge.yml` |

레거시 UI는 `/api/smart-company/{method}` 어댑터로 유지되며, 모듈은 점진적으로 Filament로 이전합니다.

---

## 2. 에이전트별 모듈 분담 (CODEX 확정 — 2026-06-20)

> CODEX가 먼저 시작했으므로 **핵심 도메인은 CODEX 소유**입니다. CODEX가 분담을 확정했습니다.
> CODEX 현재 진행 축: `인원관리 / 회원등록 / 접근제어 / 계정 UI / 관리자 진입 연결`.
> **유일한 겹침 지점은 레거시 `App\Support\SmartCompanyData` 와 `/api/smart-company/*` 응답** —
> Cowork가 안전/장비/재고 API를 실제 DB 기반으로 바꿀 때 이 파일들은 **사전 공유 후** 수정.
> **다른 에이전트 소유 모듈의 파일은 직접 수정하지 말고, 필요하면 PR/질문으로 요청하세요.**

| 에이전트 | 담당 모듈 | 주요 디렉터리 |
|---|---|---|
| **CODEX** (핵심·기준) | 회사/현장/직원, 스마트멤버 등록, 문서, SmartRecord, 근태·AI, 접근제어/인증, **재무·급여(Payroll), WBS, 협력사(Vendor)** | `app/Filament/Resources/{Companies,Sites,Employees,MemberRegistrations,MemberDocuments,SmartRecords}`, `app/Models` (핵심), `app/Filament/Pages/Auth` |
| **Cowork (Claude)** | **안전(Safety), 장비(Equipment), 재고(Inventory)** | `app/Filament/Resources/{Safety,Equipment,Inventory}` (신규), 해당 모델·마이그레이션·테스트 |
| **Antigravity** | **프론트엔드/UI, 차량(Vehicle), 임대·숙소(Rental/Housing)**, 다국어(ko/en/es) | `resources/views`, `public/js`, `public/css`, `app/Filament/Resources/{Vehicle,Housing}` (신규) |

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

**Filament 5 리소스 구조** — `app/Filament/Resources/<Module>/<Module>Resource.php` + `Pages/Manage<Module>.php`.
- `form(Schema $schema)` / `table(Table $table)` 시그니처 사용 (Filament 5 신규 API).
- import: `Filament\Schemas\Schema`, `Filament\Actions\{EditAction,BulkActionGroup,DeleteBulkAction}`, `Filament\Tables\...`.
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

## 5. 배포 노트

- `main` 머지 시 Laravel Cloud가 자동 배포.
- 배포 환경 마이그레이션 실행 여부·시드 정책·환경변수(키/토큰) 관리 방식은 **§6 질문에서 CODEX 확인 필요**.
- 비밀값(AI 키, Telegram 토큰, Google 자격증명)은 절대 소스에 커밋 금지 — `.env` / Laravel Cloud 환경변수로만.

---

## 6. CODEX에게 묻는 질문 (David가 CODEX에 전달 → 답을 이 섹션에 기록)

> Cowork가 신규 모듈을 시작하기 전에 확인이 필요한 항목입니다. CODEX가 답변을 아래에 채워주세요.

1. **모듈 분담 확정** — ✅ 확정. Cowork = 안전/장비/재고. 유일 겹침은 `SmartCompanyData`·`/api/smart-company/*` → 사전 공유 후 수정.

2. **Filament 리소스 공통 베이스** — 공통 베이스/트레이트 **없음**. 모든 리소스가 `Filament\Resources\Resource` 직접 상속. 표준 권한 메서드도 아직 없음. 유일한 표준은 `User::canAccessPanel()` — `access_role ∈ {super_admin, admin, hr_manager, site_manager, safety_manager, payroll}` 이면 admin 패널 접근 가능. **신규 리소스는 각 Resource에 `canViewAny/canCreate/canEdit` 명시적 작성 권장.** 반복되면 추후 `App\Filament\Concerns\AuthorizesResourceAccess` trait로 묶기.

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

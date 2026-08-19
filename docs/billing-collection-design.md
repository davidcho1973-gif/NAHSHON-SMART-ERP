심사 3건 중 2건이 A(mvp)를 승자로 지목했고, 심사단이 검증한 인용 중 유일한 오류(AGENTS.md:144)와 커밋 64e2162 실존 여부를 방금 실코드로 재확인했다(144행은 "AdminUI 위에 조립한다"가 맞고, 64e2162는 재무 대시보드 라벨-데이터 교정 커밋으로 실존). 이를 반영해 최종 청사진을 작성한다.

---

# 기성 청구·수금·미수금(AR) 관리 — 최종 설계 청사진

**대상**: NAHSHON SMART ERP (Laravel 13 + PostgreSQL, SPA = `resources/views/smart-company/index.blade.php`, API = `app/Support/SmartCompanyData.php` api_* 디스패치, 현재 브랜치 staging = 배포 코드)

---

## 0. 설계 결정 요약

### 0.1 승자

**설계안 A (design:mvp)** — 심사 3건 중 2건 승자 지목 (25 / 22 / 25점). 채택 이유: 인용 정확도 최고(전수 실존), 저장소 관례 밀착도 최고(테이블 2개·신규 라우트 0개·api_get* 접두사·DB_DEFAULTED·applyScope 복제), 규모 추정(~1,900줄/2주)이 저장소 실측(admin 모듈 337~477줄, 서비스 621줄) 대비 유일하게 현실적, "사장 혼자 이번 달부터 회차당 5필드 입력"이라는 가치 전달 속도 최고. **Phase 1은 SOV 라인 없이 총액 기반으로 간다** — B·C의 Phase 1 SOV 필수화는 "카드 복원이라는 첫 가치 전에 전 계약 SOV 세팅 강제"라는 선행 비용 때문에 기각.

### 0.2 이식 (심사단 지목 요소 통합)

| # | 출처 | 이식 내용 |
|---|---|---|
| G1 | B·C → A | **`approved_amount` 컬럼** (nullable 1개). GC 삭감을 "draft 되돌려 수정 후 재제출"로 처리하던 A의 워크플로 폐기 — 청구액과 승인액을 분리 저장해 제출 이력 보존. 3심사 전원 지목 |
| G2 | C → A | **AIA 정합 연속성 규칙**: D열 = 전회 (D+E), F(보관 자재)는 매회 "현재 보관분"을 재기재하는 **스톡**. A의 "누계 흐름 가산" 모델 폐기 — Phase 1 헤더 산식부터 이 의미로 고정 (§4.1) |
| G3 | C → A | **유보금 누계 기준 재계산**(누계에 1회 반올림, `retainage_held` 누계 컬럼). 회차별 반올림 합산 방식 폐기 — 오차 누적 차단 + 중도 유보율 인하 자동 소급 처리 |
| G4 | C → A | **수금의 회차 미배정 허용**: `billing_receipts.project_contract_id`(필수) + `pay_application_id`(nullable). 입금이 먼저 오고 매칭은 나중인 현실 수용 |
| G5 | B → A | **`payment_terms` 정규식 파싱**(`/net\s*(\d+)/i`)으로 due_on 기본값 제안(실패 시 제출일+45일, 수동 편집 유지) + **제출 후 경과일 배지**(45일 주황/75일 빨강) |
| G6 | B·C → A | **`intelligent_document_id` FK를 지금 추가** (source_ref 문자열과 별도) — Phase 3 BillingConnector 때 후속 마이그레이션 불필요 |
| G7 | B → A | **`submittedPending` / `collectionRate` 지표 분리** — 미인증(submitted) 청구를 billedTotal·AR에서 제외해 과대계상 차단 |
| G8 | B → A | **`BillingCalculator` 순수 산식 클래스 분리** (DB 무관 순수 함수) — saving 훅 분산 대신 단일 정본, 단위검증 용이, Phase 2 SOV 확장 시 재사용 |
| G9 | B → A | **유보 해제 상한 검증** (해제액 ≤ 직전 승인 회차 유보 잔액)을 제출 게이트에 명시 |
| G10 | 심사 2 → A | **`deduction_accepted` 3상태화** (null=미판단 / true=인정 / false=불인정·분쟁) — 분쟁 상계액의 구조화 집계 가능 |
| G11 | C → Phase 2·3 | SOV 라인 "청구 이력 있으면 삭제 대신 removed" / AI 초안 확정 시 `intelligent_documents.project_contract_id` 역방향 귀속 |
| G12 | B → Phase 2 | G702-style 인쇄 뷰 + CSV를 Phase 2 초입에 배치 ("출력물이 없으면 엑셀 병행이 계속된다") |

### 0.3 수정 (심사단 지적 결함 전량 반영)

| # | 지적 | 수정 |
|---|---|---|
| F1 | [A·B 공통] AGENTS.md:144 인용 날조 ("버튼 숨기는 건 방어가 아니다"는 존재하지 않음) | 인용 교체. AGENTS.md:144-148의 실제 내용은 **"AdminUI 위에 조립한다(표·폼·모달을 새로 짜지 않는다) / 새 부품은 AdminUI에 추가"** — 이 규칙으로 §7 인용. "권한 방어는 항상 서버(서비스 가드)에서, 프론트 `canManage`는 노출 제어일 뿐"이라는 원칙은 저장소 관행(ContractAdminService 가드 패턴)으로 서술하고 허위 인용 없이 유지 |
| F2 | [A] 보관자재를 누계 흐름으로 가산 → 시공 전환 시 이중청구 경로 | G2로 해소. `stored_materials_amount`는 스냅샷(F), `previous_billed_amount` = 전회 (D+E) = 전회 누계 − 전회 F. 검산 예시에 **보관자재→시공 전환 시나리오를 정면 포함** (§4.2 #3 회차) |
| F3 | [A] 상태 머신 구멍 — withdraw/unapprove가 최신 회차로 제한되지 않아 previous 사슬 파손 | **withdraw·unapprove 모두 "수금 0건 AND 최신 회차만"** 으로 제한 (§3.1) |
| F4 | [A] 자동 paid 판정 기준 3곳 상충 (Σ≥ / 잔액≤0 / 정확 0만) | **단일 기준으로 통일: outstanding ≤ 0.00** (저장값 전부 2자리라 허용오차 불필요). 과입금은 paid + 음수 잔액 '과입금' 배지. 잔액이 양수로 남은 회수 포기만 수동 종결(write-off) (§3.1) |
| F5 | [A] billedTotal에 submitted 포함 → AR 과대계상 | G7로 해소. billedTotal = approved/paid만, submitted는 `submittedPending` 별도 키 (§5.1) |
| F6 | [A] GC 삭감 시 제출 이력 소실 | G1로 해소. approved_amount 저장, 청구 원본 불변 |
| F7 | [A] 유보 해제 상한 검증 부재 (음수 수기 입력이 잔액 초과 가능) | G3+G9로 해소. 음수 수기 입력 방식 폐기 — `retainage_released`(양수) 별도 컬럼 + 제출 시 상한 검증 |
| F8 | [A] 유보 회차별 합산 → 유보율 중도 인하 표현 불가 | G3로 해소. 누계 기준 재계산이라 율 변경이 자동 소급 |
| F9 | [C 지적이나 A에도 해당] 소급 백필 전략 | A의 "통합 1회차" 유지 + B의 "개시 잔액 회차(App #0)" 를 대안으로 병기, 사장 결정지로 (§11-3) |
| F10 | [B] D=전회 G 규칙 / lien_notice_required의 waiver 하드 게이트 전용 / 직전 회차 approved 필수 제출 게이트 | 전부 **이식하지 않음**. 연속성은 C의 AIA 정합 규칙, waiver는 기록+경고만(차단 없음), 회차 생성 게이트는 A의 "직전 회차 submitted 이상" 유지(인증 지연이 차기 청구를 막지 않음) |
| F11 | [C] billedToDate 이중 계상 유발형 스펙 | 본 설계의 모든 누계형 지표는 **"계약별 최신 회차의 누계 컬럼"**으로 산식 명시 — 회차 합산 금지 (§5.1 retainageHeld) |
| F12 | [심사 3] 커밋 64e2162 미확인 | 실존 확인 완료 (fix(finance): 재무 대시보드 라벨-데이터 불일치 전면 교정) |

---

## 1. 목표와 비범위 (Non-goals)

### 1.1 목표

- 제거된 재무 대시보드 '기성 수금액(고객사 지급)' 카드에 **진짜 데이터 공급원**을 만든다. 카드가 직원 경비를 보여주다 제거된 근본 원인은 수금 원장 부재다 (교정 커밋 64e2162 실존 확인).
- 수주 계약(`project_contracts`, `direction='receivable'`)을 부모로 **기성 회차(pay application) → 수금(receipt) → 미수금(AR)·유보금 잔액**의 트랜잭션 원장을 신설한다. 발주(payable) 쪽 "계약 대비 발주 누계" 패턴(`ContractAdminService.php:125-128`, migration `2026_07_20_000230`)의 수주 측 대칭.
- 회차 간 연속성(이번 회차 D = 전회 D+E)을 **AIA G703 정합 규칙으로** 시스템이 보증한다 — 엑셀에서 가장 자주 깨지는 무결성.
- 부분 입금·상계(backcharge, 인정/미인정/미판단 3상태)·유보금(누계 기준)·미배정 입금을 구분 기록하고, 미수 잔액을 자동 산출한다.
- 청구액과 GC 승인액을 분리 보존한다 — 삭감 이력은 분쟁·협상의 1차 기록이다.

### 1.2 비범위

- **Phase 1에서 하지 않는 것**: SOV 라인 분해(총액 단위 입력, G703 라인별은 Phase 2), G702-style 출력(Phase 2 — 그때까지 GC 제출물은 기존 엑셀 병행, ERP는 대장 역할), 문서 AI 자동 생성(Phase 3), AR aging 30/60/90 버킷(Phase 2 — Phase 1은 due_on 연체 배지 + 제출 후 경과일 배지만), 모바일 타일(Phase 2).
- **영구 비범위**: AIA 공식 서식 재현(라이선스 — "G702-style" 자체 서식만), WIP/over-under billing 회계, GC 포털(Textura/GCPay) 연동, 전자서명·공증, 라인별 유보율 차등, 다중 통화(USD 고정 — 피닉스 현지 조달 원칙), 하위 협력사 waiver 수취 자동화(계약 문서함 수동 편철로 충분), 복식부기 GL 통합(CoA에 4xxx 수익 계정 없음 — §11-5).
- **Filament 반영 금지**: 관리 기능은 `Services/Admin` + SPA 전용 (메모리 규약: /admin 제거됨 2026-08-09).
- **경비 원장(mobile_expenses)에 절대 넣지 않는다**: `tests/Feature/DocumentMoneyRoutingTest.php:98-106`("수입을 지출로 둔갑시키지 않는다")을 깨지 않는 독립 테이블 원장.

---

## 2. 도메인 모델·ERD

### 2.1 관계도

```
project_contracts (기존, direction='receivable' 만 부모 자격)
  │ 재사용: current_amount(=original+approved_change 자동계산, ProjectContract.php:107-113),
  │        retainage_percent, payment_terms, currency, lien_notice_required
  │        (migration 2026_07_20_000230:14-42, ProjectContract.php:57-97)
  ├─ hasMany ─ pay_applications (신규: 기성 회차)
  ├─ hasMany ─ billing_receipts (신규: 수금 — 계약 직속, 회차는 nullable 매핑) ← G4
  └─ hasMany ─ procurement_items (기존 대칭 선례: 발주 누계)
```

### 2.2 신규 마이그레이션

파일: `database/migrations/2026_08_19_000100_create_progress_billing_tables.php`
(대역 000N00은 구현 주체에 맞춰 조정 — AGENTS.md:154-156: Cowork 000100 / CODEX 000200 / Antigravity 000300. 익명 클래스 + 한국어 docblock으로 "왜"를 설명 — `2026_08_18_000300` 관례. 복수 테이블 1파일은 `2026_07_20_000230` 선례.)

docblock에 명기할 의도적 관례 이탈: **계약 FK restrictOnDelete** (관례는 소유 자식 cascade지만 재무 원장은 1차 기록이므로 DB가 삭제를 거부; `ContractAdminService::delete`의 문서 가드(:411-414)와 동일한 "기성 회차가 N건 있습니다" 서비스 가드 병행).

#### 테이블 A: `pay_applications` (기성 회차)

| 컬럼 | 타입 | 설명 |
|---|---|---|
| id, timestamps | | |
| project_contract_id | foreignId → project_contracts, **restrictOnDelete** | 부모 계약 |
| company_id / site_id / project_id | foreignId nullable, nullOnDelete | 스코프 컬럼 필수(AGENTS.md:157-158). 생성 시 계약에서 복사 |
| internal_reference | string(80) unique | 자동 채번 `PA-2026-00001` — `ProjectContract::nextInternalReference()`(:201-212) do-while 패턴 복제 |
| application_no | smallInteger | 계약 내 자동 max+1. `unique(['project_contract_id','application_no'], 'pay_app_contract_no_uq')` |
| type | string(20) default 'progress' | `// progress\|retainage_release\|final` — enum 금지, string+인라인 주석 관례 |
| status | string(20) default 'draft' index | `// draft\|submitted\|approved\|paid` (§3) |
| period_start / period_end | date nullable / date | 청구 대상 기간. period_end 필수 |
| submitted_on / approved_on | date nullable | 제출일 / GC 승인일 |
| due_on | date nullable | 지급 기일. **기본값 = `/net\s*(\d+)/i` 파싱 성공 시 submitted_on+N일, 실패 시 +45일** (제출~입금 45~75일 현실), 수동 편집 허용 ← G5 |
| this_period_amount | decimal(15,2) default 0 | **E**: 금회 시공분 (보관 자재의 당월 시공 전환분 포함) |
| stored_materials_amount | decimal(15,2) default 0 | **F**: 현재 보관 자재 스냅샷 — **매회 재기재되는 스톡, 누계 가산 아님** ← G2/F2. 인라인 주석에 "F는 스톡: 시공되면 E로 옮기고 F에서 뺀다" 명기 |
| previous_billed_amount | decimal(15,2) default 0 | **D** = 전회 (D+E) = 전회 cumulative − 전회 F. **서버 계산, 사용자 입력 무시** |
| cumulative_amount | decimal(15,2) default 0 | **G** = D+E+F (청구 누계). 서버 계산 |
| retainage_percent | decimal(5,2) nullable | 계약 `retainage_percent` 스냅샷, 회차별 수정 가능 (율 인하 시 이 회차부터 새 율) |
| retainage_released | decimal(15,2) default 0 | 금회 유보 해제액 (≥0, retainage_release/final 회차만). **제출 시 상한 검증: ≤ 직전 승인 회차의 retainage_held** ← G9/F7 |
| retainage_held | decimal(15,2) default 0 | **누계 유보 잔액 (G702 line5)** = round(G × r/100, 2) − Σ해제누계. 서버 계산 ← G3/F8. 음수 수기 입력 방식 폐기 |
| earned_less_retainage | decimal(15,2) default 0 | line6 = G − retainage_held. 서버 계산 |
| previous_certificates | decimal(15,2) default 0 | line7 = 전회 회차의 line6 스냅샷. 서버 계산 |
| amount_due | decimal(15,2) default 0 | **line8 = line6 − line7** (금회 순청구액). 서버 계산 |
| approved_amount | decimal(15,2) nullable | **GC 승인 순청구액** — 삭감 시 amount_due와 분리 기록, null이면 amount_due 그대로 승인 ← G1/F6 |
| conditional_waiver_on / unconditional_waiver_on | date nullable | 회차별 lien waiver 발행일 (AZ A.R.S. §33-1008). **기록만, 게이트 없음** (F10 — lien_notice_required를 제출 차단에 전용하지 않는다). 파일은 기존 계약 문서함 `lien_waiver` 유형(`ProjectContractDocument.php:27`)에 수동 편철 |
| paid_at / paid_by_user_id | timestampTz nullable / FK users nullOnDelete | 수금 완료 확정 (timestampTz 관례 — payroll_runs, `2026_06_24_000002:31-32`) |
| intelligent_document_id | foreignId → intelligent_documents nullable, nullOnDelete | Phase 3 문서 역참조 ← G6 |
| source_ref | string(120) unique nullable | Phase 3 커넥터 멱등 키 (`document:{id}`) |
| notes | text nullable | |
| payload | json nullable | 확장 슬롯 (수동 종결 사유 `{closedManually, reason}` 등) |

인덱스: 위 unique + `index(['company_id','site_id','status'], 'pay_app_scope_status_idx')` — 복합 인덱스 이름 지정 관례(project_contracts:58-60).

#### 테이블 B: `billing_receipts` (수금)

| 컬럼 | 타입 | 설명 |
|---|---|---|
| id, timestamps | | |
| project_contract_id | foreignId → project_contracts, **restrictOnDelete** | **계약 직속** — 미배정 입금의 귀속처 ← G4 |
| pay_application_id | foreignId → pay_applications **nullable**, nullOnDelete | null = "매칭 대기". 배정·재배정 가능 |
| company_id / site_id | foreignId nullable, nullOnDelete | 계약에서 복사 |
| received_on | date | 입금일 |
| amount | decimal(15,2) | 입금액 (> 0) |
| method | string(20) default 'check' | `// check\|ach\|wire\|other` |
| reference | string(120) nullable | Check # / ACH trace # |
| deduction_amount | decimal(15,2) default 0 | 이 입금과 함께 GC가 상계한 금액 |
| deduction_reason | string(20) nullable | `// backcharge\|discount\|adjustment\|other` |
| deduction_accepted | **boolean nullable** default null | **3상태**: null=미판단 / true=인정 / false=불인정(분쟁) ← G10. **true만 회차 잔액에서 차감**, null·false는 잔액에 남고 false는 '분쟁' 배지 |
| recorded_by_user_id | FK users nullOnDelete | 기록자 |
| intelligent_document_id | FK nullable, nullOnDelete | ← G6 |
| source_ref | string(120) unique nullable | Phase 3 멱등 키 |
| memo | text nullable | |

인덱스: `index(['project_contract_id','pay_application_id'], 'billing_receipt_contract_app_idx')`, `index(['company_id','site_id','received_on'], 'billing_receipt_scope_date_idx')`.

수금은 상태가 없다 — 입금은 사실 기록. 수정 없음(틀리면 삭제 후 재입력, 장부성 단순화). 단 **회차 배정 변경**(`pay_application_id`만 갱신)은 별도 액션으로 허용 — 매칭 대기 해소 동선.

`down()`: dropIfExists 역순 2건.

### 2.3 신규 모델

- `app/Models/PayApplication.php`: `HasFactory`, `$fillable`, new-style `casts()`(금액 `'decimal:2'`, 날짜 `'date'`, paid_at `'datetime'`, payload `'array'` — `MobileExpense.php:47-56` 패턴). `STATUS_OPTIONS`/`TYPE_OPTIONS` public const 맵("영문 / 한국어" — `ProjectContract::STATUS_OPTIONS`:40-48 형식). `booted()`: 채번만. **산식 훅 없음 — 파생 금액은 BillingCalculator 정본** ← G8. 관계: `contract(): BelongsTo`, `receipts(): HasMany`, `intelligentDocument(): BelongsTo` — 반환 타입·FK 명시.
- `app/Models/BillingReceipt.php`: 동일 관례. `METHOD_OPTIONS`, `DEDUCTION_REASON_OPTIONS`. `contract()`, `application(): BelongsTo(PayApplication::class, 'pay_application_id')`, `recordedBy()`.
- 기존 `ProjectContract`에 `payApplications(): HasMany`, `billingReceipts(): HasMany` 추가 — `procurementItems()`(:163-166)의 수주 측 대칭.
- **`app/Services/Finance/BillingCalculator.php`** (신규, ← G8): DB 무관 순수 함수 모음 — `derive(previous, thisPeriod, stored, rate, releasedToDate, prevLine6): array{D,G,held,line6,line7,due}`, `outstanding(expected, receipts): float`, `resolveStatus(...)`, `parseNetDays(?string): ?int`, 연속성·해제 상한·과청구 검증. §4.2 예시 숫자가 그대로 단위 테스트가 된다.

---

## 3. 상태 머신

### 3.1 기성 회차 (`pay_applications.status`)

```
draft ──제출──▶ submitted ──GC승인 기록──▶ approved ──잔액≤0──▶ paid
  ▲                │(approved_amount 선택 입력)   │                │
  └─회수(수금0+최신)┘        승인취소(수금0+최신)──┘  수금삭제로 잔액 재발생┘(자동 복귀)
```

| 전이 | 조건 (방어는 항상 서버 — 프론트 `canManage`는 노출 제어일 뿐, ContractAdminService 가드 관행) | 권한 |
|---|---|---|
| draft → submitted | period_end·금액 입력 완료. **검증**: ① D·G·held·line6·line7·due를 서버가 BillingCalculator로 강제 재계산(사용자 입력 무시) ② `type=retainage_release/final`이면 `retainage_released ≤ 직전 승인 회차 retainage_held` (← G9) ③ 과청구는 경고만(§4.1). submitted_on 기록, due_on 기본값 산정(← G5) | MANAGE_ROLES |
| submitted → draft (withdraw) | **수금 0건 AND 최신 회차만** ← F3 | MANAGE_ROLES |
| submitted → approved | approved_on 기록. **approved_amount 선택 입력**(GC 삭감액; 미입력 시 null=청구액 그대로). 청구 원본(amount_due 등)은 불변 ← G1 | MANAGE_ROLES |
| approved → paid | **자동, 단일 기준**: outstanding ≤ 0.00 (§4.4) → paid_at/paid_by 기록. 과입금(음수 잔액)도 paid + '과입금' 배지 ← F4. **수동 종결(write-off)**: 양수 잔액 회수 포기 — 사유 필수(payload 기록), DELETE_ROLES |
| approved → submitted (unapprove) | **수금 0건 AND 최신 회차만** ← F3 | DELETE_ROLES |
| paid → approved | 수금 삭제로 잔액 재발생 시 자동 복귀 + paid 필드 소거 (`MobileExpenseController::update:252-259` "되돌림 시 지급 필드 소거" 패턴) | (수금 삭제가 DELETE_ROLES) |

**불변 규칙** (커넥터 원칙 "확정 후 불변" 계승, `DocumentExpenseConnector.php:58-60`):
- 금액 필드 수정은 `draft`에서만. approved 이후 정정은 **다음 회차 조정으로**.
- 삭제는 `draft` + **최신 회차만** (연속성 사슬 보호). DELETE_ROLES.
- 새 회차 생성은 직전 회차가 **submitted 이상**일 때만 (draft 동시 2건 금지). B의 "직전 approved 필수" 게이트는 기각 — GC 인증 지연이 차기 월 청구를 막으면 안 된다 (← F10). 제출 시점에 직전 회차의 최신 확정값 기준으로 D·line7을 재확정한다.

### 3.2 수금 레코드
- 생성: 대상 회차가 `approved`/`paid`(추가 입금)일 때, 또는 **회차 미배정(계약 직속)**으로. MANAGE_ROLES.
- 배정 변경: 미배정 → 회차 배정 / 재배정 (`pay_application_id`만 갱신) → 관련 회차 잔액 재계산. MANAGE_ROLES.
- 삭제: DELETE_ROLES. 부모 회차 잔액 재계산 → paid였다면 approved 자동 복귀.
- 금액·날짜 수정: 없음. 삭제 후 재입력.
- `deduction_accepted` 판단 변경(null→true/false)은 허용 (분쟁 해소 반영) → 잔액 재계산. MANAGE_ROLES.

### 3.3 권한 상수 (`BillingAdminService`)

`ContractAdminService.php:29-33` 전형값 그대로:
- `VIEW_ROLES = ['super_admin','admin','site_manager','payroll']` — payroll은 열람만
- `MANAGE_ROLES = ['super_admin','admin','site_manager']`
- `DELETE_ROLES = ['super_admin','admin']`
- `canView()/canManage()`: `account_status==='active'` + in_array (:49-65). 권한 실패는 예외가 아니라 `['success'=>false,'error'=>'...권한이 없습니다.']`.
- `applyScope()`: `ContractAdminService:591-613` 복제 — admin/payroll/all_sites 전체, site 스코프 site_id 정확 매칭, company 스코프 company_id, 그 외 `whereRaw('1 = 0')` 폐쇄.

B의 APPROVE_ROLES 별도 상수는 기각 — MANAGE와 동일 구성인 장식적 상수(심사 3 지적).

---

## 4. 금액 산식

### 4.1 정의 (AIA G702/G703 정합 — Phase 1은 헤더 총액, Phase 2 라인도 동일 규칙)

```
D  (previous_billed_amount) = 전회 회차의 (D + E)  =  전회 cumulative − 전회 F     ← G2 핵심
     · F(보관 자재)는 D로 이월되지 않는다. 시공되면 그만큼 E로 계상되고 F에서 빠진다.
     · 첫 회차는 0. 서버 강제 계산(사용자 입력 무시).
G  (cumulative_amount)      = D + E + F                                      (청구 누계, G702 line4)
held (retainage_held)       = round(G × r / 100, 2) − Σ(이번 회차까지의 retainage_released)
     · 누계에 1회 half-up 반올림 ← G3. 회차별 반올림 합산 금지 — 오차 표류 차단.
     · r(retainage_percent)을 중도 인하하면 그 회차부터 누계 전체가 새 율로 자동 소급 재산정 ← F8.
line6 (earned_less_retainage) = G − held
line7 (previous_certificates) = 전회 회차의 line6 (서버 스냅샷)
line8 (amount_due)            = line6 − line7                                (금회 순청구액)
balance_to_finish             = contract.current_amount − G                  (파생·비저장)

expected(수금 기대액)  = approved_amount ?? amount_due                        ← G1
outstanding(회차 잔액) = expected − Σ receipts.amount − Σ deduction_amount(accepted=true 만)
     · 미판단(null)·불인정(false) 차감은 잔액에 남는다. 불인정분은 '분쟁 잔액'으로 별도 집계 ← G10.
금회 유보 발생액(표시용 파생) = held_n − held_{n−1} + released_n
```

**연속성 불변식**: 회차 n의 D = 회차 n−1의 (D+E). 저장·제출 시 서버가 강제 계산하고, withdraw/unapprove/삭제가 전부 "최신 회차만"이므로 사슬이 깨질 경로가 없다 (← F3으로 비로소 참이 된 주장).

**과청구 경고 (차단 아님)**: `G > contract.current_amount`이면 alerts 경고 — CO 승인 지연 중 선청구가 현실. 발주 초과 경고 선례(`admin-contracts.js:107-116`)와 대칭. CO 확정 → `approved_change_amount` 갱신 → `current_amount` 자동 재계산(`ProjectContract.php:107-113`)으로 해소.

**반올림**: 저장 금액 전부 2자리 고정. 반올림은 `round(G × r/100, 2)` 한 곳뿐 — 나머지는 2자리 값들의 가감산이라 오차가 생길 수 없다. 예: G=123,456.78, r=10% → 12,345.678 → **12,345.68**.

### 4.2 검증 예시 — 계약액 $1,200,000, 유보율 10% (전 수치 검산 완료)

**보관자재 스톡 전환을 정면 포함** (← F2, A의 예시 회피 지적 해소):

| 회차 | type | E | F | D(서버) | G | held | line6 | line7 | **line8(due)** |
|---|---|---|---|---|---|---|---|---|---|
| #1 | progress | 200,000.00 | 0 | 0 | 200,000.00 | 20,000.00 | 180,000.00 | 0 | **180,000.00** |
| #2 | progress | 150,000.00 | 30,000.00 | 200,000.00 | 380,000.00 | 38,000.00 | 342,000.00 | 180,000.00 | **162,000.00** |
| #3 | progress | 100,000.00 | 0 | **350,000.00** | 450,000.00 | 45,000.00 | 405,000.00 | 342,000.00 | **63,000.00** |
| #4 | retainage_release | 0 | 0 | 450,000.00 | 450,000.00 | **25,000.00** | 425,000.00 | 405,000.00 | **20,000.00** |

검산:
- **#2 D** = #1의 (D+E) = 0+200,000 = 200,000 ✓ (F 아님·G 아님)
- **#3 (스톡 전환 회차)**: #2의 보관자재 30,000 전량 시공 + 신규 70,000 → E = 100,000, F = 0. **D = #2의 (D+E) = 200,000+150,000 = 350,000 — 전회 F 30,000이 D에 없으므로 이중청구 불가**. 금회 순증 = E+F−전회F = 100,000+0−30,000 = 70,000 → due 검산 = 70,000 − (45,000−38,000) = 63,000 ✓
- **#4 (부분 해제)**: released 20,000, 상한 검증 20,000 ≤ #3 held 45,000 ✓. held = round(450,000×10%)−20,000 = 25,000. due = line6 증가분 = 20,000 = 해제액 ✓
- 유보율 인하 검증(가정): #3에서 r을 5%로 내리면 held = round(450,000×5%) = 22,500 (누계 자동 소급) → due = 70,000 − (22,500−38,000) = **85,500** (환급분 15,500 자동 반영) — 회차별 합산 방식으로는 불가능한 계산

수금 시나리오 (#1 paid, #2·#3 approved, #4 submitted 가정):
- **#1**: R1 입금 175,000 (check#1234) + 차감 5,000 (backcharge, **accepted=true**) → outstanding = 180,000−175,000−5,000 = **0** → 자동 paid ✓
- **#2**: GC가 2,000 삭감 → **approved_amount = 160,000** (청구 원본 162,000 보존 ← G1). R2 입금 100,000 (ACH) → 잔액 60,000, '부분수금' 배지. R3 입금 57,000 + 차감 3,000 (**accepted=false, 분쟁**) → outstanding = 160,000−157,000 = **3,000** = 분쟁 잔액 3,000 배지 ✓
- **#3**: 미입금 → outstanding = 63,000. 미배정 입금이 오면 계약 직속으로 기록 후 '매칭 대기' 배지 → 배정 시 잔액 반영 (← G4)

대시보드 집계 검산:
- `billedTotal` = Σ (approved∪paid)의 (approved_amount ?? amount_due) = 180,000 + 160,000 + 63,000 = **403,000** (#4 submitted 제외 ← F5)
- `submittedPending` = 20,000 (#4) ← G7
- `receivedTotal` = 175,000+100,000+57,000 = **332,000**
- `arOutstanding` = 403,000 − 332,000 − 5,000(인정 차감) = **66,000** = #2 잔액 3,000 + #3 잔액 63,000 ✓ 교차 검산 일치
- `disputedDeductions` = 3,000 / `retainageHeld` = **최신 approved+ 회차(#3)의 held = 45,000** (회차 합산 금지 ← F11; #4 승인 시 25,000으로) / `collectionRate` = 332,000 ÷ 403,000 = 82.4%

---

## 5. 재무 대시보드 연동

### 5.1 `financeStats()` 확장 (`app/Support/SmartCompanyData.php:721-809`)

반환 배열(:786-802)에 키 7개 추가. 기존 receivable 계약 쿼리(:747-757)와 같은 자리, 같은 `applyFinanceSiteScope`(:908-919 — `site_id = X OR site_id IS NULL` 본사 공통 규약) 적용:

| 키 | 산식 |
|---|---|
| `billedTotal` | status ∈ (approved, paid)의 `COALESCE(approved_amount, amount_due)` 합 — **submitted 제외** ← F5 |
| `submittedPending` | status = submitted의 amount_due 합 (미확정 파이프라인) ← G7 |
| `receivedTotal` | billing_receipts.amount 합 (미배정 포함 — 계약 스코프 경유) |
| `arOutstanding` | billedTotal − receivedTotal − Σ(accepted=true 차감) |
| `disputedDeductions` | Σ(accepted=false 차감) ← G10 |
| `retainageHeld` | **계약별 최신 (approved∪paid) 회차의 retainage_held를 계약 단위로 합산** — 회차 합산 절대 금지(이중 계상) ← F11. 구현: 계약별 `MAX(application_no) where status in (...)` 서브쿼리 후 해당 행의 held 합 |
| `collectionRate` | receivedTotal ÷ billedTotal (0 나눗셈 가드) |

### 5.2 KPI 카드 (`index.blade.php:5168-5174`)

현 4장은 "계약 vs 지출" 프레임. 카드 2장 추가 + 라벨 1건 수정:
- **기성 수금액 (고객사 지급)** = `receivedTotal` — 제거된 카드의 복원, 이번엔 진짜 소스
- **미수금 (AR)** = `arOutstanding`, 부기: 유보금 잔액 `retainageHeld` / 제출 대기 `submittedPending` / 분쟁 차감 `disputedDeductions`
- 기존 '실행 예산 잔액'(`contractBalance` = 수주−지출, :800)은 **"실행 예산 잔액 (원가 기준)"**으로 라벨 명확화 — 원가 프레임과 수금 프레임을 한 카드에 섞지 않는다. 사전예산(`ExpensePreApproval`:760-771)은 지출 통제용으로 접점 없음.

`renderFinance`(:5090-5199)는 `Promise.all`이 이미 `API.getFinanceStats()`를 호출 — 서버 키 추가 + 카드 HTML 삽입만으로 끝. aging 버킷은 Phase 2 (Phase 1은 `due_on < today` 연체 배지 + 제출 후 경과일 배지 45일 주황/75일 빨강 ← G5).

---

## 6. API 설계

라우트 추가 불필요 — `POST /smart-company-api/{method}` 와일드카드(`routes/web.php:229-231`)가 전부 수용. `SmartCompanyData::handle()` match(:71-364) 재무 블록(:102-109) 근처에 도메인 주석 블록 + arm 9개:

| 메서드 | 방향 | 입력 (args) | 출력 |
|---|---|---|---|
| `api_getBillingContracts` | 조회 | `[{siteId?, status?}]` | `{success, rows:[계약+청구누계+수금누계+미수+유보(최신 회차 held)+미배정 건수 집계], canManage}` — `selectRaw+groupBy+keyBy` 1쿼리 (poTotals 선례 :125-128) |
| `api_getBillings` | 조회 | `[{contractId}]` | `{success, contract:{요약}, rows:[회차 + receipts:[...]], unassignedReceipts:[...], canManage}` |
| `api_getBillingOptions` | 조회 | `[]` | receivable·active 계약 목록, STATUS/TYPE/METHOD/DEDUCTION 옵션 (`{value,label}`, `options()`:218-241 형식) |
| `api_saveBilling` | 쓰기 | `[{id?, projectContractId, periodStart, periodEnd, thisPeriodAmount, storedMaterialsAmount, retainagePercent, retainageReleased, dueOn, type, waiver 날짜 2개, notes}]` | draft만. `{success, id, computed:{D,G,held,line6,line7,due}, alerts}` / `{success:false, errors:{field:msg}}` |
| `api_setBillingStatus` | 쓰기 | `[{id, action, approvedAmount?, memo?}]` — action ∈ submit\|withdraw\|approve\|unapprove\|close(수동 종결, memo 필수)\|reopen | `{success, status}` |
| `api_deleteBilling` | 쓰기 | `[id]` | draft+최신만. 아니면 "제출된 회차는 삭제할 수 없습니다. 다음 회차에서 조정하세요." |
| `api_saveBillingReceipt` | 쓰기 | `[{projectContractId, payApplicationId?, receivedOn, amount, method, reference, deductionAmount, deductionReason, deductionAccepted(null허용), memo}]` | `{success, id, applicationStatus?}` (자동 paid 전이 결과 동봉) |
| `api_assignBillingReceipt` | 쓰기 | `[{id, payApplicationId, deductionAccepted?}]` | 매칭 대기 배정/재배정/차감 판단 변경 → `{success, applicationStatus}` ← G4/G10 |
| `api_deleteBillingReceipt` | 쓰기 | `[id]` | `{success, applicationStatus}` (paid→approved 자동 복귀 포함) |

명명이 곧 보안: 조회 3개는 `api_get*` 접두사라 읽기전용 계정 게이트(`SmartCompanyApiController.php:17-20, 27-32`)와 프론트 캐시(`index.blade.php:976`)가 공짜로 작동, 쓰기 6개는 read-only 계정에 403.

구현체 `app/Services/Admin/BillingAdminService.php`: 배열 반환·예외 없음·수동 검증(Validator 아님)·필드별 `errors` 맵·`DB_DEFAULTED` unset 패턴(:44-47, :380-384)·rows camelCase + `statusLabel`·서버 계산 `alerts`(과청구·연체·경과일·분쟁·매칭대기) 동봉·에러 메시지 전부 한국어 — `ContractAdminService` 관례 그대로. 산식은 전부 `BillingCalculator` 위임. 쓰기 흐름은 `DB::transaction`.

`window.API`(`index.blade.php:1073`)에 래퍼 9줄 추가(`getExpenses` 옆 :1174-1175 형식). 멀티파트 없음 → 신규 컨트롤러·라우트 0개.

---

## 7. 화면 설계 (SPA)

### 7.1 신규 뷰 `billing-admin` — "기성 청구 · 수금"

등록 4곳 (Phase 1은 데스크톱만):
1. `index.blade.php:25` 부근 `<script src="js/admin-billing.js?v={filemtime}" defer>`
2. `:1346-1389` routes 맵에 `'billing-admin': { title: '기성 청구 · 수금', render: () => window.AdminBilling.render() }` (:1371-1380 위임 패턴)
3. 사이드바 재무 그룹 `nav-finance`(:218-220) 아래 `<li data-view="billing-admin">`
4. 모바일 타일(:322 부근)은 Phase 2

### 7.2 `public/js/admin-billing.js` 구조

IIFE + `window.AdminBilling` 공개, 내부 `state {view, contracts, detail, options}`, `gsRun` 호출. **AGENTS.md:144-148 실제 규칙 준수: `AdminUI` 위에 조립한다(표·폼·모달을 새로 짜지 않는다), 새 부품이 필요하면 AdminUI에 추가** — `AdminUI.table/formModal/confirmDanger` 조립(admin-contracts.js:11-28, 466-476 관례). Phase 1은 총액 폼이라 커스텀 그리드 불필요(B의 800줄 그리드 리스크 원천 회피).

**화면 1 — 계약 목록**: receivable 계약 테이블. 계약명 | 계약액 | 청구누계(G) | 수금누계 | 미수금 | 유보금(held) | Balance to Finish | 최근 회차 상태 | 매칭 대기 건수. 행 클릭 → 상세.

**화면 2 — 계약 상세**:
- 상단 요약 스트립 6칸: 계약액 / 청구누계(%) / 수금누계 / 미수금 / 유보금 잔액 / Balance to Finish
- 회차 테이블: # | 유형 | 기간 | E | F | 유보(파생 표시) | 순청구(due) | **승인액(삭감 시 취소선으로 청구액 병기)** | 수금액 | 잔액 | 상태 배지(부분수금·연체·과청구·**분쟁**·**과입금**·경과일 45/75) | 액션(제출/승인/수금/삭제 — `canManage` 노출 제어, 방어는 서버)
- 회차 행 확장 → 수금 서브테이블: 입금일 | 금액 | 수단/번호 | 차감(사유·**3상태 판단**) | 기록자 | 재배정 | 삭제
- **미배정 입금 섹션**: 계약 직속 수금 목록 + "회차 배정" 버튼 ← G4
- "새 회차" 모달(`AdminUI.formModal`): 기간, **금회 시공분(E) — 도움말 "보관 자재를 이번에 시공했으면 여기에 포함"**, **보관 자재(F) — 도움말 "현재 창고 보관분만, 매회 다시 적는다"**, 유보율(계약값 프리필), 해제액(release 유형만), 지급기일(Net N 파싱 프리필), waiver 발행일 2개, 메모. D·G·held·순청구는 읽기전용 실시간 표시(`api_saveBilling`의 computed 응답 활용)
- "승인 기록" 모달: 승인일 + 승인액(선택 — 비우면 청구액 그대로) ← G1
- "수금 입력" 모달: 입금일·금액·수단·번호·차감액·사유·**인정 여부(미판단/인정/불인정)**·메모. 응답 `applicationStatus`로 자동 paid 즉시 반영

수기 입력 동선(사장 혼자): 월말 → 계약 선택 → 새 회차(실질 입력 E·F·기간 3~5필드) → 제출 → GC 승인 오면 승인 클릭(깎였으면 승인액만 입력) → 입금 오면 수금 입력 → 잔액 0이면 자동 완료.

### 7.3 기존 화면 접점
- `renderFinance` KPI 카드 2장 + 라벨 수정 (§5.2), 카드 클릭 → `window.goToView('billing-admin')`(:1604-1613)
- 계약 관리 화면(admin-contracts.js) receivable 행에 "청구누계" 컬럼 + 기성 화면 이동 링크 — poTotal 컬럼의 대칭 (선택, 15분 작업)
- 쓰기 성공 시 `window.apiCache = {}` 캐시 전체 무효화 규약(:1009, :5082-5083 패턴) 준수

---

## 8. 문서함 AI 연동 (Phase 3)

Phase 1·2는 100% 수기. Phase 3에서 커넥터 원칙(멱등 source_ref / 자동 생성은 미확정 / 확정 후 불변 / 날짜 고정 / 지어내지 않음 — `DocumentExpenseConnector.php:23-27, 55-60, 95-106`) 계승:

1. **`app/Services/Finance/BillingConnector.php` 신설** — `DocumentExpenseConnector.php:44-107` 골격 복제. 호출부는 `DocumentIntelligenceService.php:101-105` 옆 **별도 try/catch** (한쪽 실패가 다른 쪽을 막지 않게). PayrollExpenseConnector의 구형 delete-재생성 패턴은 따르지 않음.
2. **라우팅**: `ai_payload['money'].flow === 'in'` 문서(현재 `DocumentExpenseConnector.php:51`에서 조용히 버려짐)를 받아 — `kind=invoice_issued` → pay_applications **draft** 생성(AI의 누계·유보 값은 payload 참고 저장, 사람이 확인·제출) / `kind=payment_received` → billing_receipts 생성하되 **회차 미배정(계약 직속) 상태로** — G4 구조 덕에 "매칭 대기"가 자동 생성분의 자연스러운 착지점이 된다. 계약 매칭 실패 시 **생성하지 않고 return**(문서는 이미 편철·검색 가능, 유실 없음).
3. **Analyzer money 스키마 확장** (`DocumentIntelligenceAnalyzer.php:137-148` + 프롬프트 :93-101): `kind`(라우팅 키), `payer`, `contract_reference`, `billing_period_start/end`, `due_on`, `retainage_amount`, `cumulative_amount/previous_amount`(amount는 "금회" 의미 고정), `reference_number`. flow=in 전용 프롬프트 규칙 1항(check stub·ACH remittance·은행 명세 → payment_received).
4. **역방향 귀속**: AI 초안 확정 시 `intelligent_documents.project_contract_id`(기존 컬럼, `IntelligentDocument.php:65`)를 채운다 ← G11. 양 테이블의 `intelligent_document_id`(← G6)로 문서↔원장 양방향 완성.
5. **불변 보장**: `DocumentMoneyRoutingTest.php:98-106`(flow=in은 MobileExpense 0건) 그대로 통과 — BillingConnector는 경비를 만들지 않는다. `analyzedDocument()` 헬퍼(:30-50)·"길이 살아있는가" 가드(:164-186, 클래스명 단언) 복제.

---

## 9. 단계별 구현 계획

### Phase 1 — MVP (2주, staging push = Laravel Cloud 자동 배포 — 기능 브랜치에서 완성 후 병합)

| # | 파일 | 작업 | 규모 |
|---|---|---|---|
| 1 | `database/migrations/2026_08_19_000100_create_progress_billing_tables.php` | 테이블 2개 | ~150줄 |
| 2 | `app/Services/Finance/BillingCalculator.php` | 순수 산식 정본 ← G8 | ~160줄 |
| 3 | `app/Models/PayApplication.php` | 모델·채번·상수 | ~130줄 |
| 4 | `app/Models/BillingReceipt.php` | 모델 | ~70줄 |
| 5 | `app/Models/ProjectContract.php` | 관계 2개 | +8줄 |
| 6 | `app/Services/Admin/BillingAdminService.php` | 9 액션 + 스코프·권한·alerts | ~520줄 |
| 7 | `app/Services/Admin/ContractAdminService.php` | delete에 회차 가드 (:411-414 옆) | +6줄 |
| 8 | `app/Support/SmartCompanyData.php` | arm 9개 + financeStats 키 7개 | +55줄 |
| 9 | `public/js/admin-billing.js` | SPA 모듈 (목록/상세/모달 3종) | ~600줄 |
| 10 | `resources/views/smart-company/index.blade.php` | script·routes·사이드바·API 래퍼 9줄·카드 2장·라벨 수정 | +45줄 |
| 11 | `tests/Feature/BillingCalculatorTest.php` + `BillingAdminTest.php` | §10 | ~500줄 |

합계 약 2,240줄. 1주차 = 1~8 + 테스트(백엔드 완결·API 스모크 통과), 2주차 = 9~10 화면 + 실데이터 소급 입력(§11-3) + staging 배포.

### Phase 2 — SOV·출력·aging
- `contract_sov_lines`(item_no, description, scheduled_value, is_change_order, sort, **status active|removed** ← G11, ΣC=계약액 경고) + `pay_application_lines`(sov_line_id, this_period, stored_materials — **D는 라인별로도 전회 (D+E) 파생, F 재기재** ← G2 명문화)
- **G702-style 인쇄 뷰 + G703 CSV** ← G12: `GET /billing/pay-applications/{payApplication}/print|export` (계약 문서 다운로드 선례 routes/web.php:170-171 + `canAccessContract`:74 스코프 재검사 패턴), `@media print` Letter 가로, 브라우저 인쇄→PDF (PDF 라이브러리 신규 의존성 없음)
- AR aging 30/60/90 (due_on 기준), 모바일 타일, lien waiver ↔ `ProjectContractDocument` 연결·경고(차단 아님)

### Phase 3 — 자동화 (§8)
- BillingConnector + Analyzer 확장 + `DocumentBillingRoutingTest`
- CoA 수익 계정(4101 Contract Revenue) 신설 여부 결정
- 제출~입금 경과일 경보의 CommandCenter 노출

---

## 10. 테스트 계획

`tests/Feature/`, `RefreshDatabase`, 헬퍼 `user($role)`(User 팩토리만; Company/Site는 `::create()` — MobileExpenseTest:29-62), `svc()`, 픽스처 `contract()`(receivable·유보 10%). assertion 메시지는 사업적 결과를 한국어로 (ContractAdminTest 스타일).

**`BillingCalculatorTest.php`** (순수 단위 — ← G8의 회수):
1. §4.2 표 4행 전 수치 그대로 (D·G·held·line6·line7·due)
2. **보관자재 전환**: #3의 D에 전회 F가 포함되지 않는다 — "보관 자재는 이월 청구 누계가 아니다. 시공되는 달에 한 번만 돈이 된다"
3. 유보 누계 반올림 1회(123,456.78×10%→12,345.68), 유보율 중도 인하 소급(§4.2 85,500 검산), 해제 상한 위반 검출
4. `parseNetDays`: "Net 30"→30, "net45"→45, 자유 텍스트→null(+45 폴백)

**`BillingAdminTest.php`**:
5. 권한: worker 거부 / payroll 열람만 / site_manager 자기 현장만(applyScope 폐쇄)
6. 채번·연속성: application_no 자동 증가, D 서버 강제(사용자 입력 무시), 중간 회차 삭제 불가, draft 동시 2건 금지, **withdraw·unapprove는 최신 회차만** ← F3 ("중간 회차를 되돌리면 후속 회차의 전회 누계가 낡는다")
7. 상태 머신: 전이 화이트리스트, 수금 있는 회차 withdraw/unapprove 거부, approved 금액 수정 거부("승인된 기성은 다음 회차에서 조정한다")
8. **승인액 분리**: approvedAmount 입력 시 잔액이 승인액 기준, 청구 원본 불변 ← G1
9. 수금: 부분 입금 잔액, **3상태 차감**(인정만 차감·미판단/불인정은 잔액 유지·불인정은 disputedDeductions 집계) ← G10, **outstanding ≤ 0 단일 기준 자동 paid + 과입금 배지** ← F4, 수금 삭제 → approved 복귀+paid 필드 소거, **미배정 입금 생성→배정→잔액 반영** ← G4
10. 유보 해제: 상한 초과 제출 거부 ← G9
11. 과청구: G > current_amount 시 alerts 경고·저장은 성공
12. financeStats: 7키 검증(**billedTotal에 submitted 불포함** ← F5, **retainageHeld는 최신 회차값 — "유보금은 회차 합산이 아니라 누계다"** ← F11) + 사이트 스코프(site_id OR NULL)
13. 경비 원장 불가침: 회차·수금 생성 후 MobileExpense 0건
14. API 스모크: `postJson('/smart-company-api/api_getBillingContracts', ['args'=>[[]], 'siteId'=>'ALL'])->assertOk()->assertJsonPath('success', true)` + read-only 계정의 `api_saveBilling` 403 (ContractAdminTest:339-348 형식)
15. 계약 삭제 가드: 회차 붙은 계약 delete 거부

주의(메모리 규약): `php artisan test`는 로컬 개발 DB를 초기화한다 — 실데이터 소급 입력 후 로컬 실행 전 백업 필수.

---

## 11. 리스크와 결정 필요 사항

| # | 항목 | 제안 | 결정 |
|---|---|---|---|
| 1 | 금액 정본 이원화 (`Project.contract_amount/retainage_percent` 중복) | **ProjectContract가 정본** — 기성 모듈은 Project 필드를 읽지 않는다. Project 쪽은 참조용 동결 | 승인만 |
| 2 | contractBalance 카드 의미 | "실행 예산 잔액 (원가 기준)" 라벨 명확화, 수금 기준은 신설 미수금 카드 | 승인만 |
| 3 | 과거 진행분 소급 입력 ← F9 | 진행 중 계약(LG ESS 등)은 **1안: 통합 소급 1회차**(#1, 메모 '기존 누계 통합') 또는 **2안: 개시 잔액 회차 App #0**(D만 있는 이월 회차). 권장 1안(입력 최소) — 어느 쪽이든 F(현재 보관분)는 실사값으로 | **사장 결정** |
| 4 | 수동 종결(write-off) | 자동 paid는 outstanding ≤ 0 단일 기준. 양수 잔액 회수 포기만 DELETE_ROLES + 사유(payload 기록) | 승인만 |
| 5 | CoA 수익 계정 부재 (4xxx 없음) | Phase 1은 독립 원장으로 CoA 무관. Phase 3에서 4101 신설 여부(기존 CATEGORY_MAP 문자열 불일치 정리와 함께) | Phase 3 유보 |
| 6 | 통화 | 자식 테이블 currency 컬럼 없이 계약값 상속 표시. non-USD 계약 청구 생성 시 경고 | 승인만 |
| 7 | GC 삭감분의 후속 처리 | approved_amount로 이력은 보존됨. 삭감분 재청구(다음 회차 E에 재포함) 여부는 건별 사업 판단 — 시스템은 "미회수 삭감 누계"를 계약 상세에 표시만 | 승인만 |
| 8 | restrictOnDelete 관례 이탈 | 재무 이력 보호. 서비스 가드 + DB 백스톱 이중화, docblock 명기 | 승인만 |
| 9 | 배포 리스크 (staging push = 즉시 배포) | 순수 신규 테이블 2개 — 기존 데이터 무영향. financeStats는 키 추가만(기존 키 불변)이라 구버전 SPA 캐시와 호환. 기능 브랜치에서 완성 후 1커밋 병합 | 없음 |
| 10 | 월중 복수 청구(T&M 병행) | Phase 1은 월 1회 기성만. T&M 인보이스는 Phase 2에서 type 확장 검토 | Phase 2 유보 |
| 11 | 읽기전용(원청 열람) 계정 노출 | api_get* 게이트로 조회가 열린다 — 원청 계정에 자사 미수·유보를 보여줄지 정책 결정. 차단 시 VIEW_ROLES에서 client 제외 | 사장 결정 |
| 12 | 마이그레이션 파일명 대역 | 구현 담당 에이전트 확정 시 000100/000200/000300 결정 | 구현 시 |

---

## 부록: 용어 매핑 (화면 라벨 기준)

기성 회차 = Pay Application(App #) / 금회 시공분 = This Period(E) / 보관 자재 = Materials Presently Stored(F — 현재 보관분 스냅샷) / 전회 기성 = From Previous Application(D = 전회 D+E) / 청구 누계 = Total Completed & Stored(G) / 잔여 계약액 = Balance to Finish / 유보금 잔액 = Retainage Held(누계) / 유보 해제 = Retainage Release / 순청구액 = Current Payment Due(line8) / 승인액 = Certified Amount / 수금 = Payment Received / 미수금 = AR Outstanding / 상계 = Backcharge(인정·불인정·미판단) / 매칭 대기 = Unapplied Receipt / 유치권 포기서 = Lien Waiver(Conditional/Unconditional, AZ A.R.S. §33-1008) / 변경계약 = Change Order(`approved_change_amount` 기존 필드).
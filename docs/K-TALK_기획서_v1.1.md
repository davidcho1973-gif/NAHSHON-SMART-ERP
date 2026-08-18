# K-TALK 기획서 v1.1

**NAHSHON-SMART-ERP 통합 현장 메신저 + AI 판단 엔진 (K-BRAIN)**

| 항목 | 내용 |
|---|---|
| 제품명 | **K-TALK** (케이톡) — 현장용 사내 메신저. 표시명은 배포별 ORG 설정 따름 |
| AI 엔진 | **K-BRAIN** — 대화·문서 분석 및 ERP 연동 판단 계층 |
| 모기 시스템 | NAHSHON-SMART-ERP (레포명 기준. 배포별 명칭은 `org:rename`으로 관리) |
| 기술 스택 | **Laravel 13.8 + Livewire 4.3 + Tailwind 4 + Vite** (Filament 미사용 — 제거 확정) |
| 작성일 | 2026-08-18 (v1.1) |
| 작성 | DASOLUSA LLC / David Cho (davidcho@dasolusa.com) |

---

## 0. v1.0 → v1.1 변경 요지

1. **Filament 전제 전면 삭제.** 실코드 확인 결과 Filament는 완전 제거됨(composer에 없음).
   관리 화면은 `app/Services/Admin/*` 서비스 + Blade/Livewire SPA 패턴으로 확정.
2. **그린필드 전제 폐기.** `communication_rooms/members/messages/reads` 4테이블,
   방 자동생성, DM, 알림, 읽음추적이 **이미 구현되어 있음**(마이그레이션 102개 확인).
   → v1.1은 "새로 짓기"가 아니라 **"현행 Communication 모듈 확장 + K-BRAIN 탑재"**.
3. **연동 목표 테이블을 실물로 교체.** 가상의 `purchase_orders` 대신 실존
   `procurement_items` (`eta` date, 상태: 발주대기→발주완료→생산중→선적중→통관중→입고완료),
   `wbs_items`(CPM 필드 보유), `intelligent_documents`, `field_drawings` 를 직접 타깃.
4. Phase 재편성: 기존 자산 재사용 기준. 총 개발량 v1.0 대비 약 40% 감소 추정.

---

## 1. 확정된 아키텍처 결정 (변경 금지)

| # | 결정 | 근거 |
|---|---|---|
| D1 | Filament 복귀 금지. Services/Admin + Livewire SPA 유지 | 주 사용자가 현장 모바일(백오피스 아님), AI 에이전트 개발과 바닐라 코드 궁합, 이미 테스트 147개가 이 구조 위에 존재 |
| D2 | 기존 `communication_*` 테이블 유지·확장 (신규 테이블로 대체 금지) | 방 자동생성·DM·읽음추적 이미 동작. 데이터 연속성 |
| D3 | 방 삭제 금지 원칙 유지 | 현장 지시·확인의 유일한 기록 (기존 코드 주석의 설계 철학 계승) |
| D4 | 1:1 DM은 K-BRAIN 분석 영구 제외 (코드 레벨 가드) | 신뢰 보호. `type=direct → skip` |
| D5 | K-BRAIN 출력은 전부 "제안(proposal)"까지. 사람 승인 후 원장 반영 | OSHA 연계 기록의 법적 안전성, 감사 대응 |
| D6 | 근거(evidence) 없는 AI 답변 출력 금지 | 모든 답에 원본 메시지/문서/셀 링크 |
| D7 | Livewire 컴포넌트 분할 원칙: 화면당 1 컴포넌트 금지 | FieldCommandApp(770줄) 전철 방지. RoomList / MessageThread / Composer / BrainPanel 분리 |
| D8 | 공용 UI 킷 선행 구축: `<x-admin.table>` `<x-admin.form-section>` `<x-admin.modal>` | Filament 부재로 인한 화면별 스타일 파편화 방지. AI 에이전트 지시 단순화 |

---

## 2. 현행 시스템 갭 분석 (2026-08-18 레포 기준)

### 2.1 이미 있는 것 (재사용)

| 자산 | 위치 | K-TALK에서의 역할 |
|---|---|---|
| 방/멤버/메시지/읽음 테이블 | `communication_*` 4테이블 | 코어 스키마 그대로 사용 |
| 방 자동 생성 | `CommunicationService::ensureSiteRooms()` 등 | 현장 생성 → 채팅방+공지방 자동 |
| 1:1 DM | `directRoomFor()` (2026-07-05 추가) | 유지. AI 제외 대상 |
| 알림 | `communication_notifications` + 미읽음 카운트 | 확장 (푸시 채널 추가) |
| 답장/고정/우선순위 | `parent_id`, `is_pinned`, `priority` | 그대로 |
| 출근 경보 → 방 게시 | `publishAttendanceAlert()` | K-BRAIN 알림 패턴의 선례 |
| 관리 화면 | `CommunicationAdminService` (356줄) | 방 관리·구성원 동기화 유지 |
| 문서 저장 | `intelligent_documents` (discipline, document_number, category 등) | 문서금고(Vault) 메타 테이블로 승격 |
| 문서 폴더/수동 파일링 | 2026-07-25 마이그레이션 | 방-폴더 연결에 사용 |
| 도면 | `field_drawings` + `FieldDrawingMessage`(role, content, **sources**) + `DrawingVisionService` + `PdfText` | 도면 질의응답의 기반. sources 패턴이 D6의 선례 |
| 조달 | `procurement_items` — **`eta`, 상태 6단계, wbs_item 연결, po_no, vendor** | **K-BRAIN ETA 제안의 직접 타깃** |
| 공정 | `wbs_items` (CPM 필드, trade) | 공정 영향 판단·진도 제안 타깃 |
| 일일 운영 | `ops_intake_batches`(사진 AI 분석, async), `daily_closing_reports`, `ops_action_items` | K-BRAIN 배치 처리 패턴의 선례 (async 분석 이미 구현) |
| 권한 패턴 | `canView()/canManage()` + VIEW_ROLES/MANAGE_ROLES 상수 | K-BRAIN 서비스도 동일 패턴 |
| PDF 파서 | `smalot/pdfparser` (composer) | 벡터 텍스트 추출에 활용 (좌표 필요 시 Python 사이드카 병행) |

### 2.2 없는 것 (신규 개발)

| 필요 기능 | 현행 상태 | 개발 항목 |
|---|---|---|
| 실시간 수신 | `setInterval` 폴링 | **Laravel Reverb + Echo** 도입 (폴링은 폴백 유지) |
| 파일/사진 첨부 | 메시지에 첨부 컬럼 없음 | `communication_message_files` 테이블 + R2 업로드 + 썸네일 큐 |
| 웹 푸시 | 없음 | VAPID Web Push (안드로이드/PC). iOS는 PWA 설치 전제, 커스텀 알림음 불가 고지 |
| 한↔영 번역 | 없음 | 메시지 길게 누르기 번역 (Claude Haiku, `translated_body` 캐시 컬럼) |
| K-BRAIN 배치 | 없음 | 일일 파이프라인 (§4) |
| 제안/승인 큐 | 없음 | `brain_proposals` + `BrainProposalAdminService` + 승인 UI |
| 별칭 사전 | 없음 | `brain_lexicon` (자가 학습) |
| 산출 규칙집 | 없음 | `takeoff_rules` (관리자 편집) |
| 발화자 권위 | 없음 | `speaker_authority` 또는 employees 확장 |
| 도면 Rev 관리 | `field_drawings.version` 문자열만 | `rev`, `issue_type`(PERMIT/IFC), `is_vector`, `page_rotation`, `issue_date` 컬럼 추가 + Rev 차분 |
| 스케줄표 파싱 | 없음 (Vision 요약만) | 좌표 기반 표 파서 → `drawing_schedule_items` |
| 메시지 검색 | 없음(방 스크롤만) | 1차: MySQL FULLTEXT / 2차: Meilisearch |

### 2.3 고칠 것 (기존 확장)

| 대상 | 변경 |
|---|---|
| `communication_rooms` | `zone`(varchar), `ai_enabled`(bool, 기본 false), `ai_scope`(json), `doc_folder_id`(FK) 컬럼 추가. 🧠 아이콘·고지 배너는 `ai_enabled` 기준 |
| `communication_messages` | `translated_body`(text), soft deletes 추가 (attendance_logs에 2026-08-12 추가한 것과 동일 패턴) |
| `field_drawings` | 2.2의 Rev 관련 컬럼 추가. 기존 version과 병행 후 이관 |
| 메시지 화면 | 폴링 → Echo 구독. 컴포넌트 분할(D7) 하면서 리팩터 |

---

## 3. 방 설계 (기존 스키마 위 델타)

방 타입은 기존 `type` 컬럼 값으로 구분 (기존 값 유지 + 추가):

| type | 이름 예 | ai_enabled 기본 | ai_scope |
|---|---|---|---|
| `notice` (기존) | 현장 공지 | true | directive |
| `site` / `chat` (기존) | 현장 채팅 | 선택 | progress, safety, equipment |
| `team` (기존) | 작업조 방 | 선택 | progress, safety |
| `safety` (신규) | 통합안전 | true | safety (최우선) |
| `procurement` (신규) | 자재/발주 | true | procurement |
| `direct` (기존 DM) | 1:1 | **false 고정** | — (코드 가드) |

- 방 목록에서 `ai_enabled` 방은 🧠 표시 + 최초 입장 시 1회 고지 배너
- 신규 타입 방은 `CommunicationService`에 `ensureSafetyRoom()`, `ensureProcurementRoom()` 추가로 생성

---

## 4. K-BRAIN 설계

### 4.1 서비스 구조 (기존 패턴 준수)

```
app/Services/Brain/
├── BrainPipelineService.php      일일 배치 오케스트레이션
├── BrainContextBuilder.php       방 문맥 + lexicon + 활성 procurement/wbs 조립
├── BrainExtractionService.php    Claude API 호출, JSON 스키마 검증
├── BrainProposalService.php      제안 생성·커밋 (승인 시 procurement_items.eta 갱신 등)
└── BrainAnswerService.php        @브레인 온디맨드 질의 (근거 강제)
app/Services/Admin/
└── BrainProposalAdminService.php 승인 큐 (canView/canManage 패턴 동일)
app/Jobs/
├── RunBrainDailyAnalysis.php     (ops_intake의 async 분석 패턴 참조)
└── TranslateMessage.php
```

### 4.2 일일 파이프라인

```
매일 21:00 America/Phoenix — Scheduler
1. ai_enabled 방별 24h 메시지 수집 (direct 제외 — 코드 가드)
2. 노이즈 필터 → BrainContextBuilder 로 문맥 조립
3. Claude API (Sonnet) → 추출 JSON (v1.0 §6.2 스키마 유지)
4. brain_proposals 생성 (evidence = source_message_ids)
5. 관리 방에 요약 게시 (publishAttendanceAlert 패턴 재사용)
```

### 4.3 제안 → 원장 커밋 매핑 (실물 테이블)

| 제안 타입 | 승인 시 반영 대상 |
|---|---|
| procurement_eta | `procurement_items.eta` 갱신 + note에 근거 추가 |
| procurement_status | `procurement_items.status` 전이 제안 (예: 선적중→통관중) |
| progress_update | `wbs_items` 진도/실적 필드 갱신 제안 |
| safety_observation | 안전 모듈 관찰 기록 생성 (**안전관리자 승인 필수**) |
| manpower_change | 일일 크루 리포트/배치 참고 정보 |
| directive | ops_action_items 후보 생성 |

판단 규칙(출고일≠도착일, 리드타임 +2~3일, 고소작업 무조건 safety 플래그,
발화자 권위 0.85/0.5/0.3, confidence<0.5 저신뢰 배지)은 v1.0 §6.3 그대로.

### 4.4 신규 테이블

```
brain_proposals(type, status[pending|approved|rejected|modified], confidence,
    room_id, payload_json, evidence_message_ids(json), target_type, target_id,
    reviewed_by_id, review_note, committed_at)
brain_lexicon(site_id, term, entity_type, entity_id, confidence, hits)
brain_runs(ran_at, rooms, messages, proposals, tokens, cost_usd)
takeoff_rules(category, key, value_json, updated_by_id)
communication_message_files(message_id, intelligent_document_id, kind, thumb_path)
drawing_schedule_items(field_drawing_id, schedule_type, mark, row_json, source_bbox)
```

---

## 5. 도면·문서 계층 (검증된 방식 반영)

1. 업로드 → `intelligent_documents` 등록 → 타입 분기 (기존 dropzone 흐름 확장)
2. 도면 PDF: 벡터/래스터 판별 → 타이틀블록(도번·Rev·발행일·issue_type) 추출
   - **W202 M0.1 검증 결과 반영: 270° 회전 페이지는 좌표 기반 행 재구성 필수**
   - `issue_type=PERMIT` 도면으로 조달 제안 생성 시 ⚠️ 경고 부착
3. 스케줄표 파싱 → `drawing_schedule_items` → @브레인 즉답 소스
4. Rev 차분: 동일 drawing_no의 신규 Rev 업로드 시 텍스트 집합 비교 → 변경 알림을
   해당 현장 조달방·관리방에 자동 게시
5. 물량 질의 4단계 정책(즉답/초안/규칙계산/거부)은 v1.0 §5.4 유지

---

## 6. UI 개발 원칙

1. **공용 킷 선행 (P0):** `<x-admin.table>`(정렬·페이지네이션·필터 슬롯),
   `<x-admin.form-section>`, `<x-admin.modal>`, `<x-admin.badge>`.
   이후 모든 관리 화면(승인 큐 포함)은 이 킷으로만 조립.
2. **채팅 화면 컴포넌트 분할:** `Chat\RoomList`, `Chat\MessageThread`,
   `Chat\Composer`, `Chat\BrainPanel`, `Chat\FileUploader` — 단일 거대 컴포넌트 금지.
3. 모바일 우선 (현장 사용 90%가 폰). 데스크톱은 그 다음.
4. 승인 큐 UI: 제안 카드 + 원본 대화 인라인 + [승인/수정/거절] 원클릭 + 거절 사유 입력.

---

## 7. 개발 로드맵 (재편성 — Claude Code 작업 단위)

### Phase 0 — 기반 (1주)
```
P0-1  공용 UI 킷 (x-admin.*) + 채팅 컴포넌트 뼈대 분할
P0-2  Reverb 설치·설정, Echo 구독 1개 방 PoC (폴링 폴백 유지)
DoD: 기존 메시지 화면이 Reverb로 실시간 수신, 기존 테스트 전부 green
```

### Phase 1 — 메신저 완성 (2~3주)
```
P1-1  rooms/messages 컬럼 확장 마이그레이션 (§2.3) + 모델·테스트
P1-2  파일 첨부: message_files + R2 업로드 + 썸네일 큐 + UI
P1-3  Web Push (VAPID) + 방별 알림 설정 + 방해금지(안전 긴급 예외)
P1-4  번역 (Haiku + translated_body 캐시)
P1-5  메시지 검색 (MySQL FULLTEXT 1차)
P1-6  safety/procurement 방 타입 + ensure 메서드 + 🧠 고지 배너
DoD: 현장 10명 파일럿 1주, 사진 첨부·푸시·번역 실사용
```

### Phase 2 — 문서·도면 강화 (2주)
```
P2-1  field_drawings Rev 컬럼 확장 + 벡터 판별 + 타이틀블록 자동 추출
P2-2  스케줄표 파서 → drawing_schedule_items (W202 M3.0으로 검증)
P2-3  Rev 차분 + 방 자동 알림
P2-4  @브레인 온디맨드 질의 (BrainAnswerService, 근거 강제)
DoD: "@브레인 RTU-1 용량" 정답+근거 링크, M-세트 전량 인덱싱
```

### Phase 3 — K-BRAIN 배치 + 승인 큐 (3주)
```
P3-1  brain_* 테이블 + BrainProposalAdminService + 승인 큐 화면 (x-admin 킷)
P3-2  일일 파이프라인 (RunBrainDailyAnalysis — ops_intake async 패턴 참조)
P3-3  procurement_eta / progress_update / safety_observation 3종 우선
P3-4  lexicon 자가학습 + 거절사유 few-shot 루프
DoD: 1주 실대화 제안 승인율 ≥ 60%
```

---

## 8. 보안·컴플라이언스 (v1.0 유지 + 보강)

1. 모니터링 서면 동의(온보딩 문서에 조항 추가) + ai_enabled 상시 표시
2. `direct` 방 K-BRAIN 제외 — 파이프라인 쿼리 레벨 + 테스트로 보장
3. 안전 기록 사람 승인 필수 (안전관리자 롤)
4. 메시지 soft delete (UI 숨김, DB 보존) — attendance_logs 패턴 동일
5. 첨부 접근: 방 멤버만, R2 temporaryUrl(15분)
6. Claude API 전송 전 PII 마스킹 (전화·주소)
7. 방 삭제 금지 유지 (기존 설계 철학)

---

## 9. Claude Code 착수 가이드

1. 본 문서를 `docs/K-TALK_기획서_v1.1.md`로 커밋, `CLAUDE.md`에 참조 명시
2. 작업은 P번호 단위. 첫 프롬프트:
   > "docs/K-TALK_기획서_v1.1.md의 P0-1을 구현해줘.
   > 기존 CommunicationAdminService와 attendance-app 뷰의 스타일을 먼저 읽고
   > x-admin 컴포넌트 킷을 거기에 맞춰 만들어."
3. 모든 P는: 기존 관련 코드 읽기 → 구현 → Pest/PHPUnit 테스트 → 기존 147개 테스트 green 확인
4. 마이그레이션은 반드시 신규 파일 추가 (기존 파일 수정 금지 — 멀티 배포 전제)
5. Reverb 도입 시 Laravel Cloud 배포 설정 확인 후 진행

---

*v1.1 — 2026-08-18. 레포 실사(마이그레이션 102개, 서비스 71개, 테스트 147개) 기반 전면 수정.*

---

## 부록 A — 코드 대조 결과 (2026-08-13, Claude)

기획서를 저장소에 넣기 전에 §2 의 전제를 실제 코드와 하나씩 맞춰 봤다. 틀린 전제
위에 몇 주를 쓰는 것이 가장 비싸기 때문이다.

### 맞는 것 — 그대로 진행해도 된다

| 문서 주장 | 실제 |
|---|---|
| 마이그레이션 102개 | **102개** ✅ |
| 서비스 71개 | **71개** ✅ |
| 테스트 파일 147개 | 146개 (이후 1개 추가돼 지금 147개) ✅ |
| Filament 완전 제거 | composer 에 0건 ✅ |
| Laravel 13.8 · Livewire 4.3 · smalot/pdfparser | `^13.8` · `^4.3` · `^2.12` ✅ |
| `procurement_items` 에 `eta` · 6단계 상태 · `wbs_item_id` · `po_no` · `vendor` | 전부 있음 ✅ |
| `communication_rooms` 에 `zone`·`ai_enabled`·`ai_scope`·`doc_folder_id` 없음 | 없음 — §2.3 대로 추가 필요 ✅ |
| `field_drawings` 가 `version` 문자열만 | 맞음 — `rev`·`issue_type`·`is_vector` 없음 ✅ |
| `intelligent_documents` 의 풍부한 메타 | 확인 ✅ |

### 고쳐야 하는 것 — ⚠️ 진행 전 반드시

**§2.2 와 P1-5 의 "MySQL FULLTEXT" 는 이 저장소에서 동작하지 않는다.**

이 시스템은 **PostgreSQL** 이다 (`.env` `DB_CONNECTION=pgsql`, 로컬·스테이징·운영 전부).
MySQL 의 `FULLTEXT` 인덱스는 Postgres 에 없다. 그대로 마이그레이션을 쓰면 배포에서
죽는다 — 그것도 배포 시점에야 드러난다.

Postgres 에서 같은 일을 하는 방법은 둘이다.

| 방법 | 언제 |
|---|---|
| `tsvector` + GIN 인덱스 | 단어 단위 검색. 표준적이고 빠르다 |
| `pg_trgm` + GIN | 부분 문자열·오타 허용. 한국어 혼용 대화에 유리할 수 있다 |

한국어 메시지가 섞이는 현장 대화라면 `to_tsvector('simple', body)` 로 시작하는 것이
안전하다 — Postgres 기본 사전에 한국어 형태소 분석이 없어서 `korean` 설정을 쓰면
서버마다 결과가 달라진다.

참고로 `intelligent_documents` 에 이미 `search_text` 컬럼이 있다. 메시지 검색도 같은
방식으로 맞추면 두 검색이 따로 놀지 않는다.

### 확인해 둘 것

- `communication_*` 는 4표가 아니라 **5표**다 — rooms · members · messages · reads ·
  **notifications**. §2.1 의 "알림" 항목이 그 다섯 번째다.
- `communication_rooms` 에 이미 `payload`(json) 가 있다. `ai_scope` 를 새 컬럼으로 둘지
  `payload` 안에 둘지는 정하고 가는 편이 좋다 — 나중에 둘 다 쓰이면 어느 쪽이 맞는지
  모르게 된다.
- D8 의 공용 UI 킷은 이미 부분적으로 존재한다: `public/js/admin-shell.js` 의 `AdminUI`
  (`table` · `formModal` · `pageHeader` · `confirmDanger` · `badge` 등). Blade 컴포넌트로
  새로 만들기 전에 이것과의 관계를 정해야 한다 — 안 그러면 공용 킷이 두 벌이 된다.

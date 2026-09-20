# 회사 이메일 AI 분석함

## 목적

각 직원이 자신의 Microsoft 365 Outlook 계정을 OAuth로 연결하면 받은편지함과 보낸편지함을 서버가 증분 동기화한다. 이메일 원문과 허용된 첨부파일은 기존 AI 문서 분석 파이프라인으로 들어가며, 화면을 닫아도 큐에서 계속 처리된다.

개인 이메일은 연결한 본인만 볼 수 있다. 시스템 관리자도 원문을 우회 열람할 수 없다. 사용자가 프로젝트를 고르고 **프로젝트 문서함에 공유**를 실행한 이메일만 기존 문서함과 해당 프로젝트 권한자에게 공개된다. AI 질문도 `IntelligentDocument::visibleTo()`와 기존 회사·현장·재무 권한을 통과한 자료만 사용한다.

## 사용자 흐름

1. ERP **문서 → 회사 이메일 분석함**을 연다.
2. **Outlook 연결**을 누르고 Microsoft 로그인 화면에서 읽기 권한을 승인한다.
3. 서버가 최근 이메일을 가져오고 이후 5분마다 새 메일만 증분 동기화한다.
4. 이메일 대화를 열어 한글 요약, 분류, 회신 필요 여부, 기한과 첨부 분석을 확인한다.
5. 회사 업무로 쓸 메일만 프로젝트를 선택해 공유한다.
6. “방문은 언제 입고된다고 했어?”처럼 질문하면 현재 사용자가 열람할 수 있는 문서 안에서만 답한다.

## Microsoft Entra 설정

Entra ID에서 Web 앱 등록을 만들고 다음 delegated 권한을 설정한다.

- `openid`
- `profile`
- `offline_access`
- `User.Read`
- `Mail.Read`

`Mail.ReadWrite`와 `Mail.Send`는 요청하지 않는다. 운영 Redirect URI는 다음과 같다.

```text
https://nahshon-smart-erp-nahshon-mep-hntasf.laravel.cloud/email-ai/microsoft/callback
```

커스텀 도메인이 실제 연결된 뒤에는 아래 URI도 추가한다.

```text
https://erp.nahshonmep.com/email-ai/microsoft/callback
```

Laravel Cloud NAHSHON MEP 환경에 다음 변수를 등록한다.

```dotenv
MICROSOFT_MAIL_TENANT=organizations
MICROSOFT_MAIL_CLIENT_ID=
MICROSOFT_MAIL_CLIENT_SECRET=
MICROSOFT_MAIL_REDIRECT_URI=https://nahshon-smart-erp-nahshon-mep-hntasf.laravel.cloud/email-ai/microsoft/callback
MICROSOFT_MAIL_INITIAL_DAYS=90
MICROSOFT_MAIL_MAX_PAGES_PER_SYNC=4
```

비밀값은 저장소에 넣지 않는다. 연결 사용자의 access/refresh token은 Laravel encrypted cast로 암호화해 데이터베이스에 보관한다. ERP에는 Microsoft 비밀번호를 저장하지 않는다.

## 운영과 장애 확인

- `php artisan mailboxes:sync`가 연결된 메일함의 동기화 작업을 큐에 넣는다.
- 스케줄러가 5분마다 위 명령을 실행한다.
- 작업은 `document-analysis` 연결의 `documents` 큐에서 실행된다.
- 연결 카드의 **최근 동기화**와 **확인 필요** 상태로 오류를 확인한다.
- 연결 해제 시 OAuth 토큰과 증분 커서를 제거한다. 사용자가 이미 프로젝트에 공유한 문서는 보존한다.
- 같은 Microsoft 계정은 두 ERP 사용자에게 동시에 연결할 수 없다.

## 보안 규칙

- 개인 원문: `access_level=private`, `owner_user_id`가 일치할 때만 열람.
- 프로젝트 공유: 소유자가 직접 프로젝트를 선택한 경우에만 `access_level=project`로 전환.
- 다른 사용자는 기존 ERP 회사·현장·역할 권한을 통과해야 공유 메일을 열람.
- 첨부파일 해시 중복 판정에도 소유자와 접근수준을 포함해 다른 직원의 개인 파일과 합쳐지지 않음.
- OAuth state 검증으로 연결 위조를 막고, Microsoft 계정 중복 연결을 차단.
- AI 답변은 같은 문서 접근 쿼리를 사용하므로 검색과 질문이 원문 권한을 우회하지 않음.


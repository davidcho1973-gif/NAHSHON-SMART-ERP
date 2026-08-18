# 배포 환경

코드는 한 벌(`davidcho1973-gif/NAHSHON-SMART-ERP`), 배포는 고객마다 하나.
아래 두 개 말고 다른 배포를 만들지 않는다.

새 고객을 세우는 절차는 `docs/새-고객-배포.md`, 도메인을 붙이는 절차는
`docs/도메인-전환.md` 에 있다. 이 문서는 **지금 서 있는 것**만 적는다.

---

## 지금 열려 있는 것 (2026-08-13 기준)

**운영 배포가 코드를 못 받고 있다.** 저장소는 `8955c73`, 서버는 `3eba77d` 에 머물러 있다.
화면은 멀쩡히 열리므로 눈으로는 알 수 없다.

원인 두 가지가 겹쳤다 — 배포가 나가는 두 길이 **둘 다 막혀 있다.**

### 1. 배포 훅 시크릿이 잘린 값이다

Actions 로그가 그대로 말해 준다.

    POST + commit_hash → HTTP 302
    ...네 조합 모두 → Redirecting to https://cloud.laravel.com/sign-in

    호스트: cloud.laravel.com   전체 86자
    경로: /deploy / <36자> / <16자>      ← 마지막 토큰 구간이 짧다

**고치는 법**

1. Laravel Cloud → `nahshon-smart-erp` → `main` → Settings → Deployments → Deploy hook
   → **칸 옆의 복사 버튼**으로 복사 (드래그 금지 — 이게 원인이다)
2. GitHub → Settings → Secrets and variables → Actions → **Secrets** 탭
   → `LARAVEL_CLOUD_DEPLOY_HOOK_PRODUCTION` 연필 아이콘 → 새 값으로 덮어쓰기

### 2. `Push to deploy` 가 꺼져 있다

훅이 실패해도 이게 켜져 있으면 푸시만으로 배포가 나간다. 10분 동안 서버가 안 바뀐 것은
**두 길이 다 막혔다**는 뜻이다.

Settings → Deployments → `Push to deploy` → 켠다.

### 3. 그다음

Deployments 탭에서 수동 **Deploy** 한 번 → `https://erp.dasolusa.com/build-version` 의
`commit_short` 가 `8955c73` 인지 확인.

### 이미 끝난 것 — 다시 하지 않는다

| | |
|---|---|
| 도메인 `erp.dasolusa.com` | Hostinger A 레코드 → Laravel Cloud, HTTPS 발급 완료 |
| 구글 로그인 | 리디렉션 URI 등록, 로그인 확인됨 |
| 스케줄러 | `running=true`, 심장박동 확인됨 |
| `ORG_NAME` · `ORG_CODE` · `CACHE_STORE` | 들어감 (`configured: true`, `store: file`) |
| GitHub `PRODUCTION_URL` | 정상 (`BASE: https://erp.dasolusa.com`) |
| `org:rename` · `org:provision` | 완료 — 회사 `DASOL USA`, 최고 관리자 세움 |

### 급하지 않은 것

- 구글 OAuth 클라이언트 `NAHSHON SMART ERP Staging` 에 보안 비밀이 둘이다.
  하나로 줄인다 — **`Disable` 먼저, 며칠 뒤 `Delete`.** 잘못 지우면 KSR 로그인이 즉시 막힌다.
- 클라이언트를 `SMART ERP` 하나로 모으고 옛 이름이 든 것을 지운다.
  두 배포 다 옮기고 로그인 확인한 뒤에.
- KSR 에도 자체 도메인 붙이기 — 다만 KSR 은 이미 쓰고 있어서 QR 포스터·휴대폰
  재설치가 따라온다. 현장이 쉬는 날에.
- `dasol-prism-erp` 앱 삭제 (아래 정리 대상 참고)
- 조직 설정에서 DASOL USA 로고 그림·대표 색 넣기

---

## 지금 있는 배포

| | **운영** | **스테이징** |
|---|---|---|
| 누구 것 | **DASOL USA** — 우리 회사. 원본 | **KSR** — 첫 시험 고객 (무상) |
| Laravel Cloud 앱 | `nahshon-smart-erp` | `nahshon-smart-erp-staging` |
| 환경 이름 | `main` | `main` |
| 브랜치 | `main` | `staging` |
| 주소 | **`https://erp.dasolusa.com`** | `https://nahshon-smart-erp-staging-main-tj7e94.laravel.cloud` |
| 옛 주소 | `https://nahshon-smart-erp-main-m9veux.laravel.cloud` (아직 열림) | — |
| `ORG_NAME` | `"DASOL USA"` (따옴표 필수 — 띄어쓰기) | `KSR` |
| `ORG_CODE` | `DASOLUSA` | — |
| 데이터 | 거의 비어 있음 | **실제 데이터** |

> **두 앱 모두 환경 이름이 `main` 이다.** 화면 위쪽 파란 네모만 보면 구별이 안 된다.
> 그 왼쪽의 **앱 이름에 `-staging` 이 붙었는지**로 가린다. 여기서 헷갈리면 KSR 의
> 실제 데이터를 건드리게 된다.

KSR 은 "연습용"이 아니라 실제로 쓰는 첫 고객이다. 화면에 "연습용" 같은 띠를 붙이지
않는 이유이고, 데이터를 함부로 비우지 않는 이유이기도 하다.

### 정리 대상

- Laravel Cloud 앱 `dasol-prism-erp` (`https://dasol-prism-erp-main-ttend5.laravel.cloud`)
- 옛 별도 앱이고 지금 흐름에 들어 있지 않다. 데이터베이스·저장 파일·환경변수·도메인에
  필요한 것이 없는지 확인한 뒤 지운다.

---

## 규칙

- 새 작업은 **staging(KSR)에서 먼저** 확인하고, 확인된 코드를 `main`(원본)으로 올린다.
- `main` 은 원본이다. 여기서 새 고객을 뽑아내므로 **뒤처져 있으면 안 된다** — 뒤처진
  원본에서 뽑은 고객은 이미 고친 문제를 그대로 안고 선다.
- `main` 을 맞출 때 **되감기(force push)를 하지 않는다.** 지난 기록은 이 코드가 왜
  이렇게 생겼는지에 대한 유일한 설명이다. 트리만 staging 과 같게 맞추고 그 위에 커밋을
  얹는다.
- 두 배포는 같은 코드를 돌리되 **데이터베이스는 일부러 따로다.** 환경 사이로 데이터를
  옮기지 않는다.
- `APP_KEY` 는 배포마다 다르다. 복사해 쓰지 않는다.

---

## 도메인 · DNS

`dasolusa.com` 은 **Hostinger** 에서 샀고 DNS 도 거기서 관리한다
(네임서버 `BYTE.DNS-PARKING.COM` / `PIXEL.DNS-PARKING.COM`).

운영 배포에 붙인 레코드는 이것 하나다.

| Type | Name | Value | TTL |
|---|---|---|---|
| A | `erp` | *(Laravel Cloud 가 Domains 화면에서 알려 준 IP)* | 300 |

- **Name 칸에는 `erp` 만** 넣는다. 전체 주소를 넣으면 `erp.dasolusa.com.dasolusa.com`
  이 되는데, 화면에는 그럴듯하게 보여서 왜 연결이 안 되는지 한참 찾게 된다.
- Hostinger 가 "이 도메인을 웹사이트에 연결할까요?" 하고 물으면 **하지 않는다.**
  호스팅이 아니라 DNS 레코드 한 줄만 필요하다.
- Laravel Cloud 의 도메인 추가 대화상자 토글 세 개(wildcard / Cloudflare / uninterrupted
  transfer)는 **전부 끈다.** 이 도메인은 Cloudflare 를 쓰지 않고, 옮겨 올 서비스도 없다.

고객마다 주소가 하나씩 늘어난다. `erp.<고객도메인>` 또는 `<고객>.erp.dasolusa.com` 중
하나로 규칙을 정해 두면 열 번째 고객에서 다시 정하지 않아도 된다.

---

## 배포가 나가는 두 가지 길

1. **Laravel Cloud 자동 배포** — 대시보드의 `Push to deploy` 설정에 의존
2. **GitHub Actions** (`.github/workflows/tests.yml`) — 시크릿만 있으면 동작

**1번만 믿으면 안 된다.** 대시보드 설정은 조용히 꺼질 수 있고, 꺼지면 푸시해도 아무 일도
일어나지 않는다. 실패로도 안 잡힌다.

2번을 켜려면 배포마다 값 두 개를 넣는다. 한 번만 하면 된다.

| 배포 | 브랜치 | 시크릿 (Secrets 탭) | 주소 (Variables 탭) |
|---|---|---|---|
| 운영 (DASOL USA) | `main` | `LARAVEL_CLOUD_DEPLOY_HOOK_PRODUCTION` | `PRODUCTION_URL` |
| 스테이징 (KSR) | `staging` | `LARAVEL_CLOUD_DEPLOY_HOOK_STAGING` | `STAGING_URL` |

`저장소 → Settings → Secrets and variables → Actions`. **Secrets 와 Variables 는 탭이 다르다.**

**변수 이름을 바꿔 넣으면 조용히 틀린다.** 운영 주소를 `STAGING_URL` 에 넣으면 배포는
성공했다고 나오고 확인만 엉뚱한 서버를 본다. 배포가 실제로 됐는지 아무도 모르게 된다.

주소 값은 `https://` 로 시작하고 **끝에 `/` 를 붙이지 않는다.**

시크릿 값은 Laravel Cloud → 해당 환경 → `Settings` → `Deployments` → **Deploy hook URL**
이다. 토글을 켜야 주소가 나타난다.

> **입력칸에서 마우스로 드래그해 복사하지 말 것.** 칸이 좁아 토큰이 `QQj…` 처럼 잘려
> 보이는데, 그렇게 복사하면 앞부분만 들어간다. 그러면 Laravel Cloud 가 훅으로 알아보지
> 못해 로그인 화면으로 리다이렉트(HTTP 302)한다. **옆의 복사 버튼을 쓸 것.**
> (2026-08-08 에 실제로 이것 때문에 반나절을 썼다.)
>
> 그 옆의 재발급 버튼은 누르지 말 것 — 주소가 바뀌어 시크릿을 다시 넣어야 한다.

넣고 나면 해당 브랜치에 푸시할 때마다 이렇게 돈다.

    푸시 → 테스트 1,185개 → 통과하면 Deploy Hook 호출 → {URL}/build-version 이
    그 커밋으로 바뀔 때까지 최대 10분 확인 → Actions 화면에 결과 표시

시크릿이 없으면 배포 단계를 **조용히 건너뛴다.** 그래서 설정 전에도 저장소가 빨개지지
않는다 — 편하지만, 그 조용함이 아래 사고의 원인이었다.

### 훅이 실패해도 잡은 안 죽는다

훅은 유일한 배포 경로가 아니다. 대시보드의 `Push to deploy` 가 켜져 있으면 푸시만으로도
배포가 나간다. 그래서 훅 호출 실패는 **경고**이고, 성패는 하나로만 가른다 —
**서버가 실제로 그 커밋으로 바뀌었는가.** `scripts/deploy/verify-build.sh` 가 서버에
직접 물어본다. 배포는 서버가 바뀌는 일이지 요청이 200 을 받는 일이 아니다.

훅이 실패하면 `scripts/deploy/diagnose-hook.sh` 가 주소의 **모양만** 찍는다 —
호스트, 경로 구간, 길이. 토큰은 길이만 세고 값은 절대 출력하지 않는다.
토큰 구간이 짧게 나오면 위에서 말한 "잘라서 복사" 다.

### 운영에 사람 확인을 한 번 더 두려면

지금은 `main` 에 올리면 바로 배포된다. `main` 에 올리는 것 자체가 의도적인 행동이라
따로 승인 단계를 두지 않았다. 확인을 한 번 더 받고 싶으면 GitHub `Settings → Environments`
에서 `production` 환경을 만들고 required reviewers 를 건 뒤, `deploy-production` 잡에
`environment: production` 한 줄만 붙이면 된다.

---

## 겪은 사고

### 운영 배포가 6일간 멈춰 있었다 (2026-08-13 기록)

`main` 에 두 번 올렸는데 배포가 나가지 않았다. 마지막 성공 배포는 8월 6일이었고,
서버는 그 코드로 멀쩡히 돌고 있었다. **화면이 열렸기 때문에 아무도 몰랐다.**

- 대시보드의 자동 배포가 꺼져 있었고,
- GitHub Actions 쪽 시크릿(`LARAVEL_CLOUD_DEPLOY_HOOK_PRODUCTION`)도 없어서
  `Deploy production` 잡이 **0초 만에 끝나며 조용히 건너뛰었다.**

두 번째 경로가 준비만 되어 있고 켜져 있지 않으면 없는 것과 같다. 지금은 켰다.

**알아보는 법:** `/build-version` 의 `commit_short` 가 방금 올린 커밋과 다르면 배포가
안 나간 것이다. Actions 의 `Deploy production` 잡이 **1분 안쪽에 끝났다면** 건너뛴 것이고,
1~2분 걸렸다면 실제로 일한 것이다.

### 빌드 명령은 대시보드에 있다 — 저장소에서 못 고친다 (2026-08-09 기록)

`composer.json` 이나 워크플로에는 없고 Laravel Cloud → 해당 환경 → `Settings` →
`Deployments` → **Build commands** 에만 있다. 그래서 저장소에서 패키지를 지워도
빌드 명령은 그대로 남고, 다음 배포에서 "없는 명령"을 부르다 죽는다.

실제로 겪은 일: 관리자 패널(Filament)을 걷어내면서 `filament/filament` 를 제거했는데
빌드 명령에 `php artisan filament:assets` 가 남아 있어 배포가 15초 만에 실패했다.
시험은 전부 통과했고 서버는 직전 버전으로 멀쩡히 돌고 있어서 더 헷갈렸다.

**패키지를 지우거나 추가할 때는 빌드 명령도 함께 본다.** 지금 값은 이렇다.

```
# Build commands
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --audit false
npm run build

# Deploy commands
php artisan migrate --force
```

`Deploy commands` 의 `migrate --force` 가 빠지면 새 코드가 옛 스키마 위에서 돌아
화면 곳곳이 500 이 된다. 원인이 코드가 아니라 스키마라는 걸 알아내는 데 오래 걸린다.

증상으로 알아보는 법: `/build-version` 이 **옛 커밋을 정상 응답**하면 서버가 죽은 게
아니라 새 빌드가 못 올라온 것이다. Deployments 탭에서 그 커밋의 빌드 로그 마지막 줄을 본다.

---

## 배포됐는지 확인하는 법

`{URL}/build-version` 을 연다. 로그인 없이 열린다.

```json
{
  "commit_short": "8955c73",
  "env": "production",
  "org":       { "name": "DASOL USA", "code": "DASOLUSA", "configured": true },
  "domain":    { "matches": true, "google_redirect": "https://erp.dasolusa.com/auth/google/callback" },
  "cache":     { "store": "file", "wakes_database_every_minute": false },
  "scheduler": { "running": true, "last_beat_at": "..." },
  "has":       { "old_company_name": false }
}
```

| 값 | 아니면 |
|---|---|
| `commit_short` | 방금 올린 커밋과 다르면 배포가 안 나간 것 |
| `org.configured: false` | `ORG_NAME` 이 안 들어갔다. 화면에는 남의 이름 또는 `ERP` 가 나간다 |
| `domain.matches: false` | `APP_URL` 이 옛 주소. 화면은 멀쩡한데 QR·앱 설치 카드·매니페스트가 옛 주소를 가리킨다 |
| `google_redirect: null` | 구글 로그인 환경변수가 없다 — 아무도 못 들어온다 |
| `cache.store: "database"` | 스케줄러가 매분 데이터베이스를 깨운다. `CACHE_STORE=file` 로 |
| `scheduler.running: false` | 저녁 자동 마감·서류 만료 알림이 안 돈다 |
| 404 | 배포가 8월 7일 이전 코드다 (`/build-version` 이 그때 생겼다) |

`scheduler.last_beat_at` 은 스케줄러가 실제로 돌 때마다 데이터베이스에 남기는 심장박동이다.
**"켰다고 표시된 것"이 아니라 "진짜 돌았다"** 는 증거라서, 이 값이 없으면 안 도는 것이다.

마지막으로 **로그인을 한 번 해 본다.** 구글 리디렉션이 맞는지 확인하는 유일한 방법이다.

---

## 새 배포를 세울 때 빠뜨리기 쉬운 것

`docs/새-고객-배포.md` 에 전체 절차가 있다. 그중 **빠뜨려도 화면이 멀쩡해 보이는** 것들:

1. `php artisan org:provision` — 안 하면 계정이 0개다. 구글 인증은 성공해도 못 들어간다
2. 스케줄러 켜기 — 안 켜면 몇 주 뒤 "왜 마감이 안 돼 있지?" 로 알게 된다
3. `CACHE_STORE=file` — 없으면 `database` 가 기본값이라 매분 데이터베이스를 깨운다
4. GitHub 의 `*_URL` 변수와 배포 훅 시크릿 — 없으면 배포가 멈춰도 아무도 모른다
5. 구글 콘솔의 리디렉션 URI — 앱 쪽만 맞추면 `redirect_uri_mismatch` 가 난다

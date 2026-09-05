# 배포 환경

코드는 한 벌(`davidcho1973-gif/NAHSHON-SMART-ERP`), 배포는 고객마다 하나.
아래 적힌 것 말고 다른 배포를 만들지 않는다.

새 고객을 세우는 절차는 `docs/새-고객-배포.md`, 도메인을 붙이는 절차는
`docs/도메인-전환.md` 에 있다. 이 문서는 **지금 서 있는 것**만 적는다.

---

## 지금 열려 있는 것 (2026-08-19 기준)

**운영 배포는 다시 흐른다.** 2026-08-19 에 `Push to deploy` 를 켜서 6일 막혔던 운영이
`b99d21b` 로 올라왔다(Actions 로그: "운영 — b99d21b 가 돌고 있습니다"). 지금은
**main 에 푸시하면 즉시 배포**되는 상태다 — 그래서 main 에는 전체 테스트를 통과한
것만 푸시한다(AGENTS.md §5).

### 훅이 302 로 튕길 때 — 「잘렸다」가 아니다 (2026-09-05 정정)

> **이 문서가 틀린 진단을 적어 두어 하루를 잡아먹었다.** 여기 「마지막 토큰이 짧다」고
> 적혀 있었는데, 실제로 재어 보니 **잘 되는 훅도 토큰이 똑같이 16자**다. 즉 `86자 =
> `/deploy/<36자>/<16자>` 는 Laravel Cloud 훅의 <b>정상 모양</b>이고, 길이로는 아무것도
> 알 수 없다. 2026-09-05 에 나손 훅이 302 로 튕겼을 때 이 문장을 믿고 «복사할 때
> 잘렸다» 고 판단했다가 틀렸다. 지금은 진단이 두 훅의 길이를 나란히 재서 말한다
> (`scripts/deploy/diagnose-hook.sh` 의 `REFERENCE_HOOK`).

**302 → sign-in 이 뜻하는 것은 하나다: Laravel Cloud 가 그 주소를 모른다.** 이유는 셋이다.

| 무엇 | 어떻게 알아보나 |
|---|---|
| 훅 토글이 꺼졌다 | 대시보드에서 토글이 켜져 있는지, 새로고침해도 켜져 있는지 |
| 훅이 재발급됐다 | 화면의 주소와 시크릿에 든 값이 다르다(시크릿은 볼 수 없으니 그냥 다시 넣는 편이 빠르다) |
| 값이 잘렸거나 다른 것이 들어갔다 | 길이를 비교한다 — 잘 되는 훅과 같으면 잘린 것이 아니다 |

**고치는 법 (어느 이유든 이 한 가지로 정리된다)**

1. Laravel Cloud → `nahshon-smart-erp` → **고칠 환경** → Settings → Deployments → Deploy hook
   → **🔄 재생성 버튼으로 새 주소를 만든 뒤** 그 옆 **📋 복사 버튼**으로 복사.
   (재생성부터 하는 이유: 지금 저장된 값이 이미 안 통하므로 잃을 것이 없고, 옛 값과
   헷갈릴 일이 없어진다. 화면 캡처에 일부가 노출된 적이 있어 어차피 바꾸는 것이 맞다.
   **드래그로 복사하지 말 것** — 그것도 실제 실패 원인 중 하나다.)
2. GitHub → Settings → Secrets and variables → Actions → **Secrets** 탭
   → `LARAVEL_CLOUD_DEPLOY_HOOK_PRODUCTION` 연필 아이콘 → 새 값으로 덮어쓰기
3. 훅이 통하는 것을 확인한 뒤(Actions 의 Deploy production 이 훅 302 경고 없이 초록),
   원하면 `Push to deploy` 를 다시 꺼서 "테스트 통과 후에만 배포" 로 되돌린다.

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

| | **DASOL (원본)** | **NAHSHON MEP** | **스테이징** |
|---|---|---|---|
| 누구 것 | **DASOL USA** — 우리 회사. 원본 | **NAHSHON MEP** | **KSR** — 첫 시험 고객 (무상) |
| Laravel Cloud 앱 | `nahshon-smart-erp` | `nahshon-smart-erp` | `nahshon-smart-erp-staging` |
| 환경 이름 | `main` | **`nahshon-mep`** | `main` |
| 브랜치 | `main` | `main` 또는 `staging` (아래 참고) | `staging` |
| 주소 | **`https://erp.dasolusa.com`** | **`https://erp.nahshonmep.com`** — 붙이는 중 (2026-09-03, 아래 참고) | `https://nahshon-smart-erp-staging-main-tj7e94.laravel.cloud` |
| 옛 주소 | `https://nahshon-smart-erp-main-m9veux.laravel.cloud` (아직 열림) | `https://nahshon-smart-erp-nahshon-mep-hntasf.laravel.cloud` (도메인이 붙을 때까지는 이것) | — |
| `ORG_NAME` | `"DASOL USA"` (따옴표 필수 — 띄어쓰기) | `"NAHSHON MEP"` | `KSR` |
| `ORG_CODE` | `DASOLUSA` | — | — |
| 데이터 | 거의 비어 있음 | 쓰는 중 | **실제 데이터** |

> **같은 앱 안에 환경이 둘이다(`main`, `nahshon-mep`).** 그리고 스테이징 앱의 환경
> 이름도 `main` 이라, 화면 위쪽 파란 네모만 보면 셋이 구별되지 않는다. 가리는 법:
>
> - 파란 네모가 **`NAHSHON MEP`** → 나손 앱
> - 파란 네모가 `main` 인데 앱 이름에 **`-staging` 이 붙음** → KSR (실제 데이터)
> - 파란 네모가 `main` 이고 앱 이름이 그냥 `nahshon-smart-erp` → DASOL 원본
>
> 여기서 헷갈리면 KSR 의 실제 데이터를 건드리게 된다.

> **앱 이름과 회사 이름이 다르다.** `nahshon-smart-erp` 는 코드 저장소에서 온 이름이고,
> 그 앱의 `main` 환경은 DASOL 것이다. 이름만 보고 «나손 = main» 이라고 읽으면 틀린다
> (2026-09-01 에 실제로 이 착각으로 잘못된 주소를 안내한 적이 있다).

### 나손 환경이 어느 브랜치를 보는가

`nahshon-mep` <b>이라는 브랜치는 저장소에 없다.</b> 그러니 그 환경은 `main` 아니면
`staging` 중 하나를 본다. 배포할 때 <b>둘 다에 같은 커밋을 올리므로</b> 어느 쪽이든
코드는 간다 — 그래서 이 칸이 비어 있어도 지금까지 문제가 되지 않았다.

다만 «배포했다» 고 말하려면 어느 쪽인지 알아야 한다. 확인하는 법:
Laravel Cloud → `nahshon-smart-erp` → **NAHSHON MEP** → Settings → 소스 브랜치.
확인되면 이 칸을 짐작이 아니라 **확인된 값**으로 바꾼다.

### 어디부터 확인하는가 — 나손이 먼저다 (David 지시 2026-09-01)

> "현재 모든 작업은 여기에 배포해줘 우선적으로"

새 작업이 실제로 도는지 보는 곳은 **NAHSHON MEP** 이다. 화면 확인·문제 보고가
거기서 나오므로, 배포 뒤 «어디를 보시라» 고 안내할 주소도 그곳이다.

  https://erp.nahshonmep.com                                   ← 도메인이 붙은 뒤
  https://nahshon-smart-erp-nahshon-mep-hntasf.laravel.cloud   ← 붙을 때까지 (붙은 뒤에도 열림)

### 나손이 배포 1순위다 (David 지시 2026-09-05)

> "지금 내가 작업하는 것은 나손이고 앞으로 나손으로 배포해줘"

훅이 들어왔다(`LARAVEL_CLOUD_DEPLOY_HOOK_NAHSHON`, 2026-09-05). 이제 나손도 다른 둘과
같은 모양으로 돈다 — **시험 통과 → 그 커밋을 콕 집어 배포 → 서버가 실제로 바뀌었는지 확인.**
바뀌지 않으면 잡이 빨개진다. 지금까지는 나손만 이 안전장치가 없어 조용히 뒤처졌다.

`main` 에서만 돈다. `staging` 에도 같은 커밋을 올리므로 양쪽에서 돌리면 같은 커밋을 두 번
배포하게 된다 — 빌드 시간만 쓰고 서버는 공연히 한 번 더 재시작한다.

배포 절차 자체는 그대로다(staging 푸시 → main 푸시). 달라진 것은 **main 푸시가 나손까지
책임진다**는 것이다.

### 나손이 어느 커밋을 돌고 있는가 — 이제 CI 가 매번 적는다 (2026-09-05)

**「나손에 배포됐어?」 에 오랫동안 아무도 답할 수 없었다.** 나손은 배포 훅이 없어
Laravel Cloud 의 `Push to deploy` 로만 나가고, 그 경로는 GitHub 에 아무 기록을 남기지
않는다. 에이전트 세션은 망 정책상 그 주소에 닿지도 못한다. 그래서 사람이 대시보드를
열어 보는 것 말고는 길이 없었고, 실제로 이틀 동안 나손이 `ff211f7` 에 멈춰 있는 동안
그 위로 커밋 14개가 쌓였는데 아무도 몰랐다(2026-09-03~05).

이제 `Tests` 워크플로에 **Deploy NAHSHON** 잡이 있다. 훅으로 배포를 걸고, 서버가 그
커밋으로 바뀔 때까지 확인한다. 시크릿 이름이 틀려 값이 비어 오면 배포를 걸 수 없으므로
그때만 «읽어서 적기» 로 물러선다(아래 표) — 내가 시키지도 못한 배포가 늦다고 빨간 X 를
놓으면 사람은 곧 그 X 를 무시하는 법을 배우기 때문이다.

| 요약에 적히는 것 | 뜻 |
|---|---|
| 「나손 — 최신입니다」 | 방금 올린 커밋이 나손에서 돌고 있다. 볼 것 없음 |
| 「나손 — 옛 커밋에 멈춰 있습니다」 | 표에 나손 커밋과 방금 올린 커밋이 나란히 찍힌다. **Deploy 를 눌러야 한다** |
| 「나손 — 응답 없음」 | 주소가 바뀌었거나 서버가 내려가 있다 |

**이 잡은 배포를 시키지 않고, 실패시키지도 않는다.** 훅이 없어 시킬 수가 없고,
내가 일으키지 않은 배포가 늦다고 빨간 X 를 놓으면 사람은 곧 그 X 를 무시하는 법을
배운다 — 그러면 진짜 실패도 함께 묻힌다. 경고만 남기고 잡은 초록으로 끝난다.

주소는 GitHub `Variables` 의 `NAHSHON_URL` 로 바꿀 수 있다(도메인이 붙으면 그때
`https://erp.nahshonmep.com` 으로). 변수가 없으면 지금 기본 주소를 쓴다 — 변수를
안 넣었다고 확인이 조용히 꺼지면, 확인이 없던 지금 상태로 되돌아가기 때문이다.

**나손에 훅을 만들면** 이 잡을 위의 둘과 같은 모양(훅 호출 → `verify-build.sh`)으로
올릴 수 있다. 그때는 시크릿 이름을 `LARAVEL_CLOUD_DEPLOY_HOOK_NAHSHON` 으로 둔다.

### 나손 도메인 `erp.nahshonmep.com` — 붙이는 중 (David 지시 2026-09-03)

> "이 주소를 nahshonmep.com 홈페이지 주소로 만들고 싶어. erp.nahshonmep.com 주소로 만들어줘"

절차는 `docs/도메인-전환.md` 맨 아래 «나손에 붙일 때». 코드는 손댈 것이 없고(주소는 전부
`APP_URL` 에서 나온다) 사람이 할 일이 네 가지다 — 전부 **`nahshon-mep` 환경**에서 한다.

| # | 어디서 | 무엇 | 됐나 |
|---|---|---|---|
| 1 | Laravel Cloud → NAHSHON MEP → Domains | `erp.nahshonmep.com` 추가, 알려 주는 DNS 값 적기 | ☐ |
| 2 | **Netlify** → nahshonmep.com → DNS | `erp` 레코드 한 줄 추가 (A 또는 CNAME) | ☐ |
| 3 | Google Cloud 콘솔 → OAuth 클라이언트 | `https://erp.nahshonmep.com/auth/google/callback` 추가 | ☐ |
| 4 | Laravel Cloud → NAHSHON MEP → 환경변수 | `APP_URL`, `GOOGLE_REDIRECT_URI` 를 새 주소로 → 재배포 | ☐ |

확인: `https://erp.nahshonmep.com/build-version` 의 `domain.matches` 가 `true`, 그리고
구글 로그인 한 번. 에이전트는 이 주소에 직접 닿지 못하므로 Actions 의 «Check production»
버튼으로 대신 읽는다.

**`nahshonmep.com` 의 DNS 는 Netlify 가 관리한다** (네임서버 `dns1~4.p03.nsone.net`,
`MAIL_SETUP.md`). 루트의 MX·SPF·DKIM·`www` 는 회사 메일과 홈페이지가 쓰고 있으니
건드리지 않는다 — `erp` 한 줄만 더한다.

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

`nahshonmep.com` 은 규칙대로 `erp.nahshonmep.com` 이다. DNS 는 **Netlify** 에 있다
(GoDaddy 화면에 레코드를 넣어도 효과가 없다). 레코드는 위와 같이 `erp` 한 줄.

---

## 배포가 나가는 두 가지 길

1. **Laravel Cloud 자동 배포** — 대시보드의 `Push to deploy` 설정에 의존
2. **GitHub Actions** (`.github/workflows/tests.yml`) — 시크릿만 있으면 동작

**1번만 믿으면 안 된다.** 대시보드 설정은 조용히 꺼질 수 있고, 꺼지면 푸시해도 아무 일도
일어나지 않는다. 실패로도 안 잡힌다.

2번을 켜려면 배포마다 값 두 개를 넣는다. 한 번만 하면 된다.

| 배포 | 브랜치 | 시크릿 (Secrets 탭) | 주소 (Variables 탭) |
|---|---|---|---|
| **나손 (NAHSHON MEP)** | `main` | `LARAVEL_CLOUD_DEPLOY_HOOK_NAHSHON` | `NAHSHON_URL` (없으면 기본값) |
| 운영 (DASOL USA) | `main` | `LARAVEL_CLOUD_DEPLOY_HOOK_PRODUCTION` | `PRODUCTION_URL` |
| 스테이징 (KSR) | `staging` | `LARAVEL_CLOUD_DEPLOY_HOOK_STAGING` | `STAGING_URL` |

> **이름이 현실을 못 따라왔다.** `PRODUCTION` 은 «운영» 이 아니라 **DASOL 원본**이고,
> `STAGING` 은 «시험» 이 아니라 **KSR 실제 데이터**다. 이 이름들은 나손을 세우기 전에
> 붙었고, 지금 매일 쓰는 곳은 나손이다. 2026-09-05 에 David 가 시크릿 목록을 보고
> «나손이 지금 PRODUCTION 아닌가?» 라고 물은 것이 그 증거다 — 업무 기준으로는 맞는
> 읽기인데 이름이 다른 곳을 가리킨다.
>
> 고치려면 시크릿을 지우고 다시 만들어야 하고(이름 변경 불가), 그러면 훅 주소 두 개를
> 다시 복사해 와야 한다. 그 복사가 예전에 반나절을 잡아먹은 자리라(아래 «잘라서 복사»)
> 지금은 이름을 그대로 두고 **여기 적어 둔다.** 헷갈리면 이 표를 본다.

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

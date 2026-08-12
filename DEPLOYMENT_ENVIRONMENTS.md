# Deployment Environments

This project should use only the environments below.

## Production

- Laravel Cloud app: `nahshon-smart-erp`
- URL: `https://nahshon-smart-erp-main-m9veux.laravel.cloud`
- GitHub repository: `davidcho1973-gif/NAHSHON-SMART-ERP`
- Branch: `main`
- Purpose: final owner-approved production ERP.

## Staging / Official Test

- Laravel Cloud app: `nahshon-smart-erp-staging`
- URL: `https://nahshon-smart-erp-staging-main-tj7e94.laravel.cloud`
- GitHub repository: `davidcho1973-gif/NAHSHON-SMART-ERP`
- Recommended branch: `staging`
- Purpose: official test ERP with test applicant and employee data.

## Retire / Delete After Backup Check

- Laravel Cloud app: `dasol-prism-erp`
- URL: `https://dasol-prism-erp-main-ttend5.laravel.cloud`
- GitHub repository: `davidcho1973-gif/dasol-prism-erp`
- Reason: old separate application. It is not part of the current DASOL PRISM SMART ERP workflow.

Before deleting this app, confirm there is no needed database, storage file, or environment variable inside it.

## Cleanup Rules

- Do not use multiple Laravel Cloud apps for the same active workflow unless their purpose is production versus staging.
- Production deploys from `main`.
- Staging deploys from `staging`.
- New work is verified in Staging first. After David approves the test result, the same tested code can be promoted to Production.
- If staging must temporarily deploy from `main`, document the reason in `WORK_LOG.md`.
- Do not migrate data between environments casually. Decide which environment is official before copying data.
- Production and Staging can run the same code, but their databases are intentionally separate.

## 자동 배포가 멈췄을 때 (2026-08-07 추가)

Laravel Cloud 의 GitHub 자동 배포는 대시보드 설정에 달려 있어서, 연결 브랜치가 바뀌거나
자동 배포가 꺼지면 푸시해도 아무 일이 일어나지 않는다. 실패로도 안 잡히기 때문에 알아채기
어렵다. 그래서 두 번째 경로를 만들어 뒀다.

### 배포 경로 두 가지

1. Laravel Cloud 자동 배포 — 대시보드 설정에 의존
2. GitHub Actions (`.github/workflows/tests.yml` 의 `deploy-staging` 잡) — 시크릿만 있으면 동작

2번을 켜려면 환경마다 값 두 개를 넣는다. 한 번만 하면 된다.

| 환경 | 브랜치 | 시크릿 (Secrets 탭) | 주소 (Variables 탭) |
| --- | --- | --- | --- |
| staging | `staging` | `LARAVEL_CLOUD_DEPLOY_HOOK_STAGING` | `STAGING_URL` |
| 운영 | `main` | `LARAVEL_CLOUD_DEPLOY_HOOK_PRODUCTION` | `PRODUCTION_URL` |

`Settings → Secrets and variables → Actions` 에서 넣는다. Secrets 와 Variables 는 **탭이 다르다.**

시크릿 값은 Laravel Cloud → 해당 환경 → `Settings` → 오른쪽 위 드롭다운을 `General` 에서
**`Deployments`** 로 바꾸면 나오는 **Deploy hook URL** 이다. 토글을 켜야 주소가 나타난다.

> **입력칸에서 마우스로 드래그해 복사하지 말 것.** 칸이 좁아 토큰이 `QQj…` 처럼 잘려
> 보이는데, 그렇게 복사하면 앞부분만 들어간다. 그러면 Laravel Cloud 가 훅으로 알아보지
> 못해 로그인 화면으로 리다이렉트(HTTP 302)한다. **옆의 복사 버튼을 쓸 것.**
> (2026-08-08 에 실제로 이것 때문에 반나절을 썼다.)
>
> 그 옆의 재발급 버튼은 누르지 말 것 — 주소가 바뀌어 시크릿을 다시 넣어야 한다.

주소 변수 값은 `https://` 로 시작하고 끝에 `/` 를 붙이지 않는다.

넣고 나면 해당 브랜치에 푸시할 때마다 이렇게 돈다.

    푸시 → 테스트 719개 → 통과하면 Deploy Hook 호출 → /build-version 이 그 커밋으로
    바뀔 때까지 최대 10분 확인 → Actions 화면에 결과 표시

시크릿이 없으면 배포 단계를 조용히 건너뛰므로, 설정 전에도 저장소가 빨개지지 않는다.

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

### 배포됐는지 확인하는 법

`{URL}/build-version` 을 연다. 로그인 없이 열린다.

    {
      "commit_short": "9db1bfd",
      "subject": "Merge ...",
      "has": {
        "admin_screens": true,      // ERP 안의 관리 화면
        "spa_only_admin": true,     // false 면 옛 /admin 링크가 남아 있는 것
        "ops_room": true,           // 현장 상황실
        "old_company_name": false   // true 면 배포가 안 된 것
      }
    }

404 가 나오거나 `old_company_name` 이 `true` 면 배포가 반영되지 않은 것이다.

### 빌드 명령은 대시보드에 있다 — 저장소에서 못 고친다 (2026-08-09 추가)

`composer.json` 이나 워크플로에는 없고 Laravel Cloud → 해당 환경 → `Settings` →
`Deployments` → **Build commands** 에만 있다. 그래서 저장소에서 패키지를 지워도
빌드 명령은 그대로 남고, 다음 배포에서 "없는 명령"을 부르다 죽는다.

실제로 겪은 일: 관리자 패널(Filament)을 걷어내면서 `filament/filament` 를 제거했는데
빌드 명령에 `php artisan filament:assets` 가 남아 있어 배포가 15초 만에 실패했다.
시험은 전부 통과했고 서버는 직전 버전으로 멀쩡히 돌고 있어서 더 헷갈렸다.

**패키지를 지우거나 추가할 때는 빌드 명령도 함께 본다.** 지금 staging 의 빌드 명령은
이렇다 — 여기 없는 것을 부르면 배포가 죽는다.

```
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --audit false
npm run build
php artisan optimize
```

증상으로 알아보는 법: `/build-version` 이 **옛 커밋을 정상 응답**하면 서버가 죽은 게
아니라 새 빌드가 못 올라온 것이다. Deployments 탭에서 그 커밋의 빌드 로그 마지막 줄을
본다.

### staging 도 CI 를 돈다

`tests.yml` 이 `main` 만 보고 있어서 staging 은 테스트가 한 번도 돌지 않았다.
배포되는 브랜치일수록 CI 가 먼저 봐야 하므로 `staging` 을 트리거에 넣었다.

## Laravel Cloud Dashboard Actions

These actions must be done in the Laravel Cloud dashboard by an owner/admin account.

1. Open `dasol-prism-erp`.
2. Confirm there is no needed database, storage file, environment variable, or custom domain.
3. Delete `dasol-prism-erp`.
4. Open `nahshon-smart-erp-staging`.
5. Confirm the connected branch is `staging`.
6. Deploy `staging` after each test-ready change.
7. If deployment fails, open the failed deployment log and fix the reported error.
8. Verify `https://nahshon-smart-erp-staging-main-tj7e94.laravel.cloud/build-version` reports the commit you just pushed.

Current rule confirmed by David: test in Staging first; after the test passes, deploy/promote to Production.

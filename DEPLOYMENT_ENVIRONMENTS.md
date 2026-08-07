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

2번을 켜려면 저장소에 값 두 개를 넣는다. 한 번만 하면 된다.

- `Settings → Secrets and variables → Actions → Secrets → New repository secret`
  - 이름: `LARAVEL_CLOUD_DEPLOY_HOOK_STAGING`
  - 값: Laravel Cloud → `nahshon-smart-erp-staging` → 환경 → **Deploy Hook** 의 URL
- `Settings → Secrets and variables → Actions → Variables → New repository variable`
  - 이름: `STAGING_URL`
  - 값: `https://nahshon-smart-erp-staging-main-tj7e94.laravel.cloud`

넣고 나면 `staging` 에 푸시할 때마다 이렇게 돈다.

    푸시 → 테스트 719개 → 통과하면 Deploy Hook 호출 → /build-version 이 그 커밋으로
    바뀔 때까지 최대 10분 확인 → Actions 화면에 결과 표시

시크릿이 없으면 배포 단계를 조용히 건너뛰므로, 설정 전에도 저장소가 빨개지지 않는다.

### 배포됐는지 확인하는 법

`{URL}/build-version` 을 연다. 로그인 없이 열린다.

    {
      "commit_short": "9db1bfd",
      "subject": "Merge ...",
      "has": {
        "admin_screens": true,      // 새 관리자 화면 6개
        "ops_room": true,           // 현장 상황실
        "old_company_name": false   // true 면 배포가 안 된 것
      }
    }

404 가 나오거나 `old_company_name` 이 `true` 면 배포가 반영되지 않은 것이다.

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
8. Verify `https://nahshon-smart-erp-staging-main-tj7e94.laravel.cloud/debug-build-sec-53298bfd9a` returns `member_registration_has_badge_keyvalue: false`.

Current rule confirmed by David: test in Staging first; after the test passes, deploy/promote to Production.

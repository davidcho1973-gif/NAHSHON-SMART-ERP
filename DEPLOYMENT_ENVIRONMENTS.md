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

- Laravel Cloud app: `nahshon-erp`
- URL: `https://nahshon-erp-main-ttend5.laravel.cloud`
- GitHub repository: `davidcho1973-gif/nahshon-erp`
- Reason: old separate application. It is not part of the current NAHSHON SMART ERP workflow.

Before deleting this app, confirm there is no needed database, storage file, or environment variable inside it.

## Cleanup Rules

- Do not use multiple Laravel Cloud apps for the same active workflow unless their purpose is production versus staging.
- Production deploys from `main`.
- Staging deploys from `staging`.
- New work is verified in Staging first. After David approves the test result, the same tested code can be promoted to Production.
- If staging must temporarily deploy from `main`, document the reason in `WORK_LOG.md`.
- Do not migrate data between environments casually. Decide which environment is official before copying data.
- Production and Staging can run the same code, but their databases are intentionally separate.

## Laravel Cloud Dashboard Actions

These actions must be done in the Laravel Cloud dashboard by an owner/admin account.

1. Open `nahshon-erp`.
2. Confirm there is no needed database, storage file, environment variable, or custom domain.
3. Delete `nahshon-erp`.
4. Open `nahshon-smart-erp-staging`.
5. Confirm the connected branch is `staging`.
6. Deploy `staging` after each test-ready change.
7. If deployment fails, open the failed deployment log and fix the reported error.
8. Verify `https://nahshon-smart-erp-staging-main-tj7e94.laravel.cloud/debug-build-sec-53298bfd9a` returns `member_registration_has_badge_keyvalue: false`.

Current rule confirmed by David: test in Staging first; after the test passes, deploy/promote to Production.

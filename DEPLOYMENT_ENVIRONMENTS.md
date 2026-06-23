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
- Staging should deploy from `staging`.
- If staging must temporarily deploy from `main`, document the reason in `WORK_LOG.md`.
- Do not migrate data between environments casually. Decide which environment is official before copying data.

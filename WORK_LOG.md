# WORK LOG

NAHSHON SMART ERP shared work log for David, Antigravity, CODEX, and Cowork.

## Log Rules

- Add new timeline rows at the top.
- Keep one clear row per completed task or deployable checkpoint.
- Include commit hash when available.
- Do not edit another worker's detailed notes except to correct an obvious typo.
- Record verification clearly: local build, migration, tests, staging URL, or manual owner check.
- If a task is only planned, put it in the worker detail section under `Planned`, not in the completed timeline.

## Current Module Ownership

| Area | Primary owner | Notes |
| --- | --- | --- |
| Owner decisions and manual business validation | David | Final product direction, staging validation, workflow approval |
| HR / employee registration / access control / account UI | CODEX | Active ownership as of 2026-06-20 |
| Safety / equipment / inventory modules | Cowork | Approved to begin, avoid editing CODEX-owned HR/access files without coordination |
| Cross-module refactor / integration support | Antigravity | Coordinate before touching shared layout/API files |

## Timeline

| Date | Worker | Area | Summary | Commit / Status | Verification |
| --- | --- | --- | --- | --- | --- |
| 2026-06-24 | CODEX | Applicant site QR intake | Added reusable site-based public application QR links so walk-in applicants can scan a field QR and submit without pre-created name/email/phone invitation records. | Local branch `codex/project-management` | PHP lint passed; `ApplicantIntakeTest` passed (7 tests, 56 assertions); route list shows `member/site/{site}/apply` and `/apply/qr`; in-app browser confirmed the `현장 공용 QR` admin action is visible. |
| 2026-06-24 | Antigravity | AI Vehicle Management | Overhauled vehicle management: implemented multimodal AI scan (PDF contract + 4 photos), database save, manual/NFC assignment/return, detail modal, and vertical chronology timeline. Fixed pre-existing database sequence leak in tests. | Local branch `antigravity/vehicle-ui` | All 69 tests passed successfully (`php artisan test`). |
| 2026-06-24 | CODEX | Admin navigation cleanup | Removed the Filament admin resources/routes for 거래처 마스터, ERP Records, and 숙소 관리 so PROJECT 관리 is the remaining SMART COMPANY management entry with Sites. | Local branch `codex/project-management` | `artisan optimize:clear` passed; targeted `ProjectManagementTest` + `PostgresSchemaTest` passed; `route:list --path=admin` shows only `admin/projects` among the removed route patterns. |
| 2026-06-24 | CODEX | Project management UX | Reworked the PROJECT create form from a dense multi-card layout into a cleaner 5-step wizard with compact step labels and wider modal spacing. | Local branch `codex/project-management` | PHP lint passed; `ProjectManagementTest` passed; in-app browser visual check completed at `/admin/projects`. |
| 2026-06-24 | CODEX | Project management | Added dedicated PROJECT management with construction project metadata, vendor tier/contract structure, US compliance, finance/WBS, workforce/resource planning, and moved the old company menu wording to 거래처 마스터. | Local branch `codex/project-management` | PHP lint passed for new project files; targeted `ProjectManagementTest` + `PostgresSchemaTest` passed (2 tests, 66 assertions); full `php artisan test` passed 63/64 with order-dependent `SmartCompanySeedRecordsTest` failure, and that test passes alone. |
| 2026-06-23 | Antigravity | AI Safety Management | Overhauled the AI safety management module into a 5-step foreman-led TBM & close report flow, repairing Mojibake strings. | Local change | `php artisan test` passed (77 tests) |
| 2026-06-23 | CODEX | Mobile expense wizard | Added an uploaded receipt image preview directly inside the AI receipt upload area so users can see the selected receipt while OCR results appear below. | Local change | `npm run build` passed; local `/mobile-expense/wizard` returned 200 OK. |
| 2026-06-23 | David / CODEX | Deployment policy | Reverted the workflow back to Staging-based testing: agents should run local checks when possible, deploy test changes to the `staging` environment for David validation, and reserve Production deploys for explicit approval. | Documentation update | Recorded in `AGENTS.md` and this work log. |
| 2026-06-23 | David / CODEX | Deployment policy | Updated the agent collaboration rule: all agents must test locally first and must not deploy to staging or production without David's explicit approval; if local testing is not possible, ask David before changing any remote environment. | Documentation update | Recorded in `AGENTS.md` and this work log. |
| 2026-06-23 | CODEX | Mobile expense save | Fixed the remaining `/mobile-expense/store` 500 by storing receipt DB backup images as base64-safe payloads and decoding them when served, while keeping legacy raw receipt rows viewable. | Staging deploy | `php artisan test tests/Feature/MobileExpenseTest.php` passed, 26 tests; full `php artisan test` passed, 77 tests; `npm run build` passed. |
| 2026-06-23 | CODEX | Mobile expense save | Hardened mobile expense save/update and desktop AI expense save so optional newly migrated columns are filtered against the live DB schema during Laravel Cloud deploys, preventing 500 errors when code and migrations briefly drift; also clears cached accounting-account values out of department/class input. | Staging deploy | PHP lint passed for changed files; `php artisan test tests/Feature/MobileExpenseTest.php` passed, 24 tests; full `php artisan test` passed, 75 tests; `npm run build` passed. |
| 2026-06-23 | CODEX | AI expense accounting | Replaced the limited manual expense category step with chart-of-accounts based accounting account selection, separated accounting account from department/class, clarified approved pre-approval budget linking, and added a migration to clean legacy account values out of `class`. | Staging deploy | PHP lint passed for changed files; `php artisan test tests/Feature/GeminiReceiptAnalyzerTest.php tests/Feature/MobileExpenseTest.php tests/Feature/SmartCompanySeedRecordsTest.php` passed, 26 tests; full `php artisan test` passed, 74 tests; `npm run build` passed. |
| 2026-06-23 | CODEX | AI expense registration | Enhanced receipt OCR so upload results show immediately below the photo picker, Gemini extracts an accounting account and handwritten notes, and saved expense descriptions/accounts flow into Finance. | Staging deploy | PHP lint passed for changed files; `php artisan test tests/Feature/GeminiReceiptAnalyzerTest.php tests/Feature/MobileExpenseTest.php` passed, 25 tests; `npm run build` passed. |
| 2026-06-23 | CODEX | Finance expense sync | Fixed finance dashboard receipt sync by including Global/Office expenses in selected-site finance views and passing the current site context into the AI expense wizard. | Staging deploy | PHP lint passed for changed files; `php artisan test tests/Feature/MobileExpenseTest.php` passed, 22 tests; `npm run build` passed. |
| 2026-06-23 | CODEX | Mobile expenses | Fixed the receipt edit page so it can scroll on desktop/mobile and the save button remains reachable despite the global ERP overflow rule. | Staging deploy | `npm run build` passed; `php artisan test tests/Feature/MobileExpenseTest.php` passed, 20 tests. |
| 2026-06-23 | CODEX | Finance DB workflow | Linked approved expense pre-approvals to receipt expenses with a real FK, added review/payment audit fields, admin approve/reject actions, paid status handling, and DB-backed finance dashboard/API aggregation. | Staging deploy | PHP lint passed for changed files; targeted finance tests passed, 29 tests; full `php artisan test` passed, 71 tests. |
| 2026-06-23 | CODEX | Finance dashboard | Removed the unused Google Drive receipt scan status panel and legacy drive scan JavaScript from the Finance dashboard so the page focuses on DB-backed expense, pre-approval, and AI receipt flows. | Staging deploy | `php artisan test tests/Feature/MobileExpenseTest.php tests/Feature/ExpensePreApprovalTest.php tests/Feature/SmartCompanySeedRecordsTest.php` passed, 22 tests. |
| 2026-06-23 | CODEX | Desktop finance expenses | Exposed receipt view/edit/delete actions directly in the desktop Finance expense table and added API fields so admins can manage scanned receipt records from the visible list. | Staging deploy | PHP lint passed for changed files; `php artisan test tests/Feature/MobileExpenseTest.php tests/Feature/SmartCompanySeedRecordsTest.php` passed, 17 tests. |
| 2026-06-23 | CODEX | Mobile expenses | Added receipt expense edit/delete flow: workers can modify their own draft/pending/rejected records, admins/HR/payroll can manage all records, and replacement receipt images are persisted in the DB. | Staging deploy | PHP lint passed for changed files; `php artisan test tests/Feature/MobileExpenseTest.php tests/Feature/SmartCompanySeedRecordsTest.php` passed, 16 tests. |
| 2026-06-22 | CODEX | HR badge save | Removed the remaining KeyValue component from Applicants, added explicit Laravel/Filament cache clearing during deploy, and verified Badge/NFC save works even when applicant and badge names differ. | Main deploy | PHP lint passed for changed files; `php artisan test` passed, 53 tests; `npm run build` passed. |
| 2026-06-22 | CODEX | HR badge save | Fixed the remaining Badge/NFC save 500 by allowing FileUpload update hooks to receive stored string/array state, and added a Livewire table-action regression test for saving Gemini badge payloads. | Main deploy | PHP lint passed for changed files; `php artisan test` passed, 53 tests; `npm run build` passed. |
| 2026-06-22 | CODEX | HR badge analysis | Fixed Badge/NFC save failures after Gemini analysis by replacing the Filament KeyValue analysis display with a read-only JSON preview and normalizing the stored payload for Applicants and Employees. | Main deploy | PHP lint passed for changed files; `php artisan test` passed, 52 tests; `npm run build` passed. |
| 2026-06-22 | CODEX | HR onboarding A-plan | Enforced activation-only Employee/account creation: removed the Employee draft step, added `safety_completed`, gated buttons by the real sequence, and added a cleanup migration for old `employee_registration` statuses. | Local changes | PHP lint passed for changed files; `php artisan test` passed, 52 tests; `npm run build` passed. |
| 2026-06-22 | CODEX | Admin access | Reset the owner admin login to `davidcho1973@gmail.com` with the requested temporary password and updated login defaults. | Local branch `codex/set-owner-admin-login` | PHP lint passed for changed files; `php artisan test` passed, 50 tests; `npm run build` passed. |
| 2026-06-22 | CODEX | Admin access | Added a one-time production admin password reset migration for `admin@nahshonmep.com` without committing the plaintext password, and made deploy start with `artisan up` to avoid stuck maintenance mode. | Local branch `codex/reset-production-admin-password` | PHP lint passed for migration; `php artisan test` passed, 50 tests; `npm run build` passed. |
| 2026-06-22 | Antigravity | Desktop Universal Scanner | Shifted universal scanner API routes from routes/api.php to routes/web.php under /smart-company-api/ prefix to prevent CDNs and load balancers from stripping session cookies on stateless /api/* paths. | Branch pushed | All 49 phpunit tests passing locally. |
| 2026-06-22 | Antigravity | Desktop Universal Scanner | Connected the legacy Apps Script universal scanner (EXPENSE/OFFICE categories) on the desktop view to the Laravel backend. Decodes base64 uploads, parses via Gemini OCR, and saves to the mobile_expenses table. | Main deploy | All 49 tests passed (`php artisan test`); PR merged and deployed. |
| 2026-06-22 | CODEX | HR onboarding statuses | Removed legacy applicant onboarding statuses from the active code path and centralized the current HR status list. | Local branch `codex/remove-legacy-onboarding-statuses` | PHP lint passed for changed files; `php artisan test` passed, 50 tests; `npm run build` passed. |
| 2026-06-22 | CODEX | HR architecture / onboarding | Audited and tightened the HR flow: Applicants now require interview pass before Employee draft, support safety/badge/NFC activation actions, collect nationality, and only active Employees can use NFC attendance. | Local branch `codex/hr-flow-audit` | PHP lint passed for changed files; targeted HR tests passed, 21 tests; full `php artisan test` passed, 48 tests; `npm run build` passed. |
| 2026-06-22 | Antigravity | Mobile Expense Wizard | Fixed a frontend JavaScript error where querySelector for csrf-token returned null during receipt upload by adding the missing meta tag to the wizard head. | Main deploy | Manual staging verification and all 45 phpunit tests passing. |
| 2026-06-22 | Antigravity | Mobile Expense / Pre-Approval | Implemented My Expenses dashboard, AI OCR Wizard with custom virtual keypad, and Expense Pre-Approval modules. Verified with full feature test coverage (45 tests total passing). | Main deploy | All tests passed (`php artisan test`); PR merged. |
| 2026-06-22 | CODEX | HR employees | Fixed Employee registration saves by auto-generating Employee IDs, normalizing blank optional unique fields, and cleaning existing blank employee values. | Main deploy | PHP lint passed for changed files; `php artisan test` passed, 33 tests; `npm run build` passed. |
| 2026-06-22 | CODEX | HR onboarding | Changed applicant email invitations to fall back to a prefilled mail draft instead of stopping on missing SMTP configuration. | Main deploy | PHP lint passed for changed files; `php artisan test` passed, 31 tests; `npm run build` passed. |
| 2026-06-22 | CODEX | HR onboarding | Added real-mail configuration guard for applicant invitation emails and documented Laravel Cloud SMTP setup. | Main deploy | PHP lint passed for changed files; `php artisan test` passed, 31 tests; `npm run build` passed. |
| 2026-06-22 | CODEX | HR onboarding | Renamed the Applicants create action for application intake and added email-link invitation plus QR-code application entry actions. | Main deploy | PHP lint passed for changed files; `php artisan test` passed, 30 tests; `npm run build` passed; application/QR routes verified. |
| 2026-06-22 | CODEX | HR onboarding | Guarded malformed public application invite links so placeholder or broken tokens return 404 instead of PostgreSQL UUID server errors. | Main deploy | PHP lint passed for changed files; `php artisan test` passed, 29 tests; `npm run build` passed. |
| 2026-06-22 | CODEX | HR onboarding | Corrected HR flow so the public application contains only applicant-facing fields, creates an applicant code on submit, and moves passed applicants into pending Employees registration. | Main deploy | PHP lint passed for changed files; `php artisan test` passed, 28 tests; `npm run build` passed. |
| 2026-06-22 | CODEX | HR onboarding | Rebuilt the employee intake flow around multilingual applicant forms, interview, Hoffman safety training, badge photo/Gemini extraction, NFC normalization, and final employee activation. | Local branch `codex/hr-onboarding-flow` | PHP lint passed for changed files; `php artisan test` passed, 27 tests. |
| 2026-06-21 | CODEX | Mobile bottom navigation | Reworked phone bottom navigation into Attendance, Message, More, Schedule, and Receipt actions, with a Solis-style More bottom sheet for the remaining modules. | Main deploy | `npm run build`, `php artisan test`, Playwright mobile 390px More sheet check. |
| 2026-06-21 | CODEX | Mobile login / app shell | Kept the desktop login unchanged while turning the phone login/home entry into an app-like mobile screen with PWA manifest/icon metadata. | Main deploy | `npm run build`, `php artisan test`, Playwright desktop/mobile login screenshots, manifest/icon 200 responses. |
| 2026-06-21 | Antigravity | Universal AI Scanner | Implemented WebRTC webcam stream capture and file upload hybrid UI for the Universal AI Scanner Modal in index.blade.php. | Local verified | `npm run build` completed, and all 23 phpunit tests passed. |
| 2026-06-21 | Antigravity | HR Attendance | Implemented Solis-style HR submenus (Directory, Attendance Record, Attendance Summary) in index.blade.php. Added backend pending review approval/rejection APIs and verified them with a full test suite. | Local verified | `php artisan test` passed locally (23 tests passed). |
| 2026-06-21 | CODEX | Mobile ERP UI | Made the ERP shell and Google login page phone-friendly with sticky mobile header, bottom tab navigation, horizontal-safe tables, compact panels, and mobile HR tab behavior. | Main deploy | `npm run build`, `php artisan test`, Playwright mobile smoke test at 390px passed locally. |
| 2026-06-21 | CODEX | Employee badge AI | Added employee badge photo capture/upload, Gemini 3.5 Flash extraction, badge image storage, and employee form autofill fields. | Main deploy | `php artisan test` passed locally. |
| 2026-06-21 | Antigravity | Core resources | Added individual delete action (DeleteAction) to all core resource lists (Companies, Employees, Member Documents, Sites, ERP Records, Access Control). | `15f6981` | `php artisan test` passed locally. |
| 2026-06-21 | Antigravity | Member registration | Added individual delete action to Member Registration resource. | `0b3d27a` | `php artisan test` passed locally. |
| 2026-06-20 | CODEX | Member documents / employee sync | Changed Member Documents to one row per member with a per-member document detail page; repaired mismatched Member Registration to Employee links. | Main deploy | `php artisan test` passed locally. |
| 2026-06-20 | CODEX | Auth / access | Required Google OAuth for ERP entry and linked successful Google login to active ERP users. | Main deploy | `php artisan test` passed locally. |
| 2026-06-20 | CODEX | Member registration | Audited and fixed Member Registration to Employee/Access/Documents sync; added active/approved backfill migration and resync UI. | Main deploy | `php artisan test` passed locally. |
| 2026-06-20 | CODEX | Admin navigation | Linked topbar settings gear to `/admin/login`. | `8e82f5f` | Local click verified; staging click verified to `/admin/login`. |
| 2026-06-20 | External agent | PostgreSQL / Filament | Fixed pgsql JSON distinct issue in many-to-many select fields. | `75662e6` | Integrated through rebase before CODEX push. |
| 2026-06-20 | CODEX | Account UI | Removed remaining quick menu toggle CSS and translation remnants. | `439338a` | `npm run build`, `artisan optimize:clear`, staging verified. |
| 2026-06-20 | CODEX | Account UI | Removed account page right-side Quick Menu panel and widened account layout. | `a531789` | Local and staging account profile verified without Quick Menu. |
| 2026-06-20 | CODEX | Account UI | Stabilized account dropdown item navigation. | `7a91a1a` | Local and staging UI Settings navigation verified. |
| 2026-06-20 | CODEX | Account UI / i18n | Added My Account dropdown/pages and Spanish language option/translations. | `dfda43d` | `npm run build`, staging Spanish UI verified. |
| 2026-06-20 | External agent | Company / site data model | Added many-to-many site and company management resources. | `c56c8bf` | Integrated through rebase before CODEX push. |
| 2026-06-20 | CODEX | HR / access data | Backfilled approved employee site assignments. | `647f8a8` | Staging API verified real employee data. |
| 2026-06-20 | CODEX | Employee management | Started real employee management workflow and removed fake HR dashboard data. | `3773d99` | Local migration/build and staging data check completed. |
| 2026-06-20 | CODEX | Member registration | Added smart member registration workflow. | `178c345` | Filament admin workflow verified during staging setup. |

## David Owner Log

Use this section for manual owner checks, business decisions, and final approvals.

### Completed

- 2026-06-20: Confirmed project direction to continue Laravel Cloud staging first.
- 2026-06-20: Requested removal of account Quick Menu and settings gear redirect to admin login.

### Planned / Open Decisions

- Decide final production hosting path after staging stabilizes.
- Decide exact employee registration invite channels: Gmail, WhatsApp, KakaoTalk.

## CODEX Log

### Completed

- 2026-06-20: Prepared GitHub/Laravel Cloud staging workflow and kept main branch synced.
- 2026-06-20: Implemented admin login default credentials and improved login/admin UI direction.
- 2026-06-20: Implemented access-control fields on users: `access_role`, `access_scope`, `account_status`, allowed company/site/team.
- 2026-06-20: Added/updated Filament resources for employee management and access control.
- 2026-06-20: Replaced fake HR dashboard data with DB-backed real employee/attendance data.
- 2026-06-20: Added My Account dropdown and account pages: profile, update profile, UI settings, change password.
- 2026-06-20: Added Spanish language option to main frontend localization.
- 2026-06-20: Removed account Quick Menu panel and related toggle remnants.
- 2026-06-20: Connected topbar settings gear to `/admin/login`.
- 2026-06-20: Answered Cowork coordination questions on module ownership, Filament resource conventions, access scope, migrations, i18n, deployment, and test DB.
- 2026-06-20: Added Google OAuth ERP entry flow for registered active users, with remember-session login and role-aware landing.
- 2026-06-20: Fixed Member Registration downstream sync to Employees, Access Control, and Member Documents, including existing active/approved registration backfill.
- 2026-06-20: Changed Member Documents into a member-level list with per-member uploaded document management.
- 2026-06-20: Repaired Member Registration to Employee sync when existing data points a registration at another worker's employee record.
- 2026-06-21: Added Gemini 3.5 Flash badge photo analysis to the Employees create/edit form with camera/file upload and stored badge images.
- 2026-06-21: Added mobile-friendly ERP shell/login layout with bottom tab navigation, sticky mobile topbar, safer table scrolling, and mobile HR parent-tab routing.
- 2026-06-21: Refined `/login` so desktop remains the existing card layout while mobile renders as an app-like entry screen with install metadata and app icon.
- 2026-06-21: Reworked the phone bottom navigation into four primary actions plus a centered More sheet containing the remaining modules.
- 2026-06-22: Rebuilt HR onboarding around Spanish/English/Korean applicant intake, uploaded ID/certification documents, interview/safety/badge stages, Hoffman badge Gemini extraction, and NFC ID normalization (`N-` + last 9 characters).
- 2026-06-22: Corrected the HR application flow so applicant intake is applicant-only, creates an `applicant_code` on submit, and pass processing creates a pending Employee registration draft without creating Access Control.
- 2026-06-22: Audited HR architecture and tightened the workflow links: interview pass is required before Employee draft, Applicants can complete safety/badge/NFC/activation, nationality is collected on intake, and NFC attendance accepts active Employees only.
- 2026-06-22: Removed legacy applicant onboarding states from live forms/filters and centralized the current status list as `draft`, `invited`, `submitted`, `under_review`, `interview_passed`, `employee_registration`, `badge_pending`, `active`, `rejected`, `archived`.
- 2026-06-22: Added a one-time production admin password reset migration for the configured SMART COMPANY admin account and hardened deploy startup to avoid leaving the app in maintenance mode.
- 2026-06-22: Reset the owner admin login defaults and production DB credential migration to `davidcho1973@gmail.com` with the requested temporary password.
- 2026-06-22: Separated applicant-owned intake from admin HR review: admins now create/send links or QR codes, applicant fields are read-only in review, submitted public forms are locked, and workflow status changes stay on HR actions.
- 2026-06-22: Applied HR A-plan sequence: application submit -> interview passed -> safety completed -> Badge/NFC registered -> active; Employee, Access Control, and HR Documents sync only when the applicant becomes active.
- 2026-06-22: Fixed Gemini Badge/NFC save failures by removing the KeyValue analysis field from the save path, using a read-only JSON preview, and normalizing analysis payload storage for Applicants and Employees.
- 2026-06-22: Fixed the remaining Badge/NFC save 500 from the FileUpload state update hook by accepting non-temporary stored file state and added a Livewire regression test for the exact save action.
- 2026-06-22: Removed the last Applicants KeyValue field, added deploy-time Laravel/Filament cache clearing, and confirmed Badge/NFC save does not require applicant and badge names to match.
- 2026-06-22: Reverted deploy-time cache clearing from the production script after Laravel Cloud staging failed deployments while the main app deployed the same commits successfully.
- 2026-06-22: Documented the Laravel Cloud cleanup plan: keep `nahshon-smart-erp` as production, keep `nahshon-smart-erp-staging` as official testing, and retire the old `nahshon-erp` app after backup review.
- 2026-06-22: Added the Laravel Cloud dashboard action sequence for deleting the old app, switching staging to the `staging` branch, reading failed deployment logs, and verifying the debug build route.
- 2026-06-22: Fixed staging deploy seeding failure caused by DB-backed mobile expenses missing the legacy `vendor` key in `SmartCompanyData::seedRecords`.
- 2026-06-23: Confirmed deployment policy with David: test changes on Staging first, then promote the same tested code to Production after approval; Staging and Production use separate databases.
- 2026-06-23: Updated the deployment policy per David: do not deploy to Staging or Production by default; test locally first, and ask David before any remote deploy or when a local test cannot cover the issue.
- 2026-06-23: Reverted the deployment policy per David: use Staging as the official remote test environment again after local checks when possible; keep Production protected until David explicitly asks for production deploy.
- 2026-06-23: Added a receipt image preview to the AI expense wizard upload area so uploaded receipt photos remain visible while AI analysis results are reviewed below.
- 2026-06-23: Fixed receipt image 403 viewing by serving uploaded mobile expense receipts through an authenticated route: workers can view their own receipts and admins/HR/payroll can view all receipts.
- 2026-06-23: Added mobile expense edit/delete support with ownership/admin permissions, replacement receipt upload, DB-backed receipt persistence, and regression tests for worker/admin access.
- 2026-06-23: Added the same receipt view/edit/delete actions to the desktop Finance expense table so scanned receipt records can be managed from the screen David is using.
- 2026-06-23: Removed the inactive Google Drive receipt scan status widget and related legacy JavaScript from the Finance dashboard to keep the workflow aligned with the current AI receipt and DB-backed expense system.
- 2026-06-23: Added database-backed receipt file storage for new mobile expenses so receipt images remain viewable even if Laravel Cloud local storage is cleared by deployment.
- 2026-06-23: Tightened the finance DB workflow: approved pre-approvals can now be linked to receipt expenses, pending pre-approvals cannot be linked, admins can approve/reject pre-approvals, paid expense records keep reviewer/payment audit fields, and finance stats/expense APIs are calculated from scoped DB records.
- 2026-06-23: Fixed the mobile expense edit page scroll behavior by overriding the global hidden body overflow on that page and adding safe bottom spacing so the save button can be reached.
- 2026-06-23: Fixed finance dashboard receipt visibility by showing Global/Office expenses alongside the selected site and preselecting the current site when launching the AI expense wizard from Finance.
- 2026-06-23: Enhanced AI expense registration to display OCR results immediately after upload, recommend accounting accounts, capture handwritten receipt notes, and save those values into Finance expense records.
- 2026-06-23: Replaced the limited AI expense category buttons with chart-of-accounts based `accounting_account` selection, kept department/class separate, clarified approved pre-approval budget linking, and added legacy data cleanup for account values stored in `class`.
- 2026-06-23: Hardened mobile and desktop AI expense saves against Laravel Cloud migration timing drift by filtering write payloads to columns that exist in the live `mobile_expenses` table and stripping account-code values from department/class.
- 2026-06-23: Fixed the remaining mobile expense final-save 500 by encoding receipt image DB backups as base64-safe payloads, decoding them through the authenticated receipt route, and preserving support for older raw receipt rows.
- 2026-06-24: Added PROJECT management as a dedicated Filament resource backed by a `projects` table for construction project metadata, vendor tier/contract structure, US site/compliance tracking, finance/WBS fields, and workforce/resource planning; relabeled the old company screen as 거래처 마스터.
- 2026-06-24: Reworked the PROJECT create form into a cleaner wizard flow (`기본`, `계약`, `현장 일정`, `재무 WBS`, `규정/리소스`) after visual review showed the first dense card layout was too cramped.
- 2026-06-24: Removed Filament admin resources/routes for 거래처 마스터, ERP Records, and 숙소 관리 while leaving their underlying tables/models available where core relationships still depend on them.
- 2026-06-24: Added field-office public application QR flow: admins can open a `현장 공용 QR` from Applicants, applicants scan `/member/site/{site}/apply/qr`, and a Member Registration record is created only after the applicant submits the form.

### Current Boundaries

- CODEX currently owns HR/member registration/access-control/account UI unless reassigned by David.
- Cowork can proceed on safety/equipment/inventory modules.
- Shared files that require coordination before large edits:
  - `resources/views/smart-company/index.blade.php`
  - `public/js/smart-language.js`
  - `app/Support/SmartCompanyData.php`
  - `routes/web.php`
  - `routes/api.php`

### Planned / Next

- Design employee registration invite links for Gmail, WhatsApp, and KakaoTalk.
- Add persistent invite/send-log tables if David approves the channel plan.

## Cowork Log

### Completed

- Pending.

### Planned / Next

- Safety module.
- Equipment module.
- Inventory module.
- Recommended migration prefix: `2026_06_20_000100_*`.
- Apply access scope using `company_id`, `site_id`, `team_id`, and `employee_id` columns where applicable.

## Antigravity Log

### Completed

- 2026-06-24: Overhauled the vehicle management module. Replaced legacy Apps Script and mock views with a Laravel + PostgreSQL database-backed system. Supports multimodal AI upload (4-direction photos + rental contract), Gemini-based metadata extraction & verification, driver assignment/returns, detailed modal with photo slider, and chronological timeline of assignments. Fixed the database sequence leak in `SmartCompanySeedRecordsTest` to ensure robust test runs.
- 2026-06-20: Added many-to-many site and company management resources and resolved PostgreSQL distinct JSON query error.
- 2026-06-21: Added individual delete action (DeleteAction) to Member Registration resource.
- 2026-06-21: Added individual delete action (DeleteAction) to all other core resource lists (Companies, Employees, Member Documents, Sites, ERP Records, Access Control).
- 2026-06-21: Implemented Solis-style HR submenus (Directory, Attendance Record, Attendance Summary) in index.blade.php. Added backend pending review approval/rejection APIs and verified them with a full test suite.
- 2026-06-21: Implemented WebRTC webcam stream capture and file upload hybrid UI for the Universal AI Scanner Modal in index.blade.php with clear preview reset and stream release checks.
- 2026-06-22: Shifted the universal scanner and compatibility adapter API routes from routes/api.php to routes/web.php under the /smart-company-api/ prefix, ensuring requests run completely under the web session and auth middleware and preventing CDNs or load balancers from stripping session cookies on stateless /api/* paths.
- 2026-06-22: Implemented Mobile Expense dashboard (/mobile-expense/index), AI Expense Registration Wizard (/mobile-expense/wizard-ai) with Gemini OCR and a custom virtual keypad, and Expense Pre-Approval module (/expense-pre-approval/index). Created a full suite of automated feature tests (MobileExpenseTest, ExpensePreApprovalTest, GeminiReceiptAnalyzerTest) and successfully merged the implementation to main.
- 2026-06-22: Fixed a frontend JavaScript error where `document.querySelector('meta[name="csrf-token"]')` returned null on the AI Expense Wizard page (throwing "Cannot read properties of null (reading 'getAttribute')" when uploading a receipt) by adding the missing csrf-token meta tag to the document head.
- 2026-06-22: Connected the desktop web app's legacy Apps Script universal scanner (specifically the EXPENSE and OFFICE categories) to the Laravel backend database. The scanner now decodes base64 uploads, parses them via the Gemini OCR service, uploads the receipt image, and saves the record in the mobile_expenses table. Added comprehensive integration tests and successfully merged to main.




### Planned / Next

- Coordinate with David/CODEX/Cowork before modifying shared frontend shell, API compatibility layer, or core auth/access files.

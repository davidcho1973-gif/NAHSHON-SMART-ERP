# ERP integration audit and Korean operating guide — 2026-09-20

## Scope and method

Owner requested autonomous review and repair across modules, followed by a complete user guide. This pass reviewed the 35 SPA sidebar destinations, the literal frontend API dispatch links, access control, worker onboarding, procurement/finance/document/rental connectors, prior remediation records, and the existing automated regression suite. Browser checks used fictional records in a separate PostgreSQL database; no production records were edited and no external messages were sent.

The 35-menu browser pass was performed as a super administrator. It is a page-load/API smoke check, **not** a claim that every button, every role, all historical data and all external integrations have been exercised. Role and mutation paths are additionally covered by existing tests and the new regression tests below.

## Repaired causes

| Area | Cause / correction | Evidence |
| --- | --- | --- |
| Account management | HR could request a lower target role while editing an existing higher-role account. Check the existing role before editing or changing status. | HR cannot edit/demote/suspend admin regression |
| Employee/account identity | An employee could be assigned to another login account without checking its existing link. Reject nonexistent employees and duplicate account links. | Duplicate employee-link regression |
| Procurement → finance | Only amount/description changes triggered updates, losing vendor/site/project-only changes. Synchronize all source fields on pending entries; preserve original expense date. | Same-name replacement-vendor test |
| Receipt reversal | Unreceived or zero-value procurement left a stale automatic pending cost. Remove only the matching unapproved automatic entry. | Receipt reversal and approved-entry preservation test |
| Document → finance | Reclassification and site/category changes could leave stale pending costs. Propagate current fields; remove pending output when no longer an outgoing cost. Finalized records are untouched. | Document site/reclassification test |
| Rental → finance | Same amount/title prevented corrected site attribution from reaching pending monthly cost. Compare all attributes. | Housing monthly accrual site correction test |
| Procurement delay | ETA already passed could remain on-time if WBS need-by was later. A past ETA with no receipt is late; use the supplied reporting date consistently. | Explicit-date ETA regression |
| Procurement partial success | Finance sync exceptions were logged while the user saw complete success. Preserve procurement save and return/display a finance warning. | Connector-failure regression |
| Housing page | Renderer expected legacy room/utility fields that the real API never returned, causing `undefined.toLocaleString`. Render actual house name/address/capacity/rent fields. Summary uses the same scoped rows. | Backend numeric contract + JS empty/populated/XSS tests + browser |
| Housing unavailable actions | Legacy NFC assignment backend explicitly rejects requests; AI housing registration has no housing persistence path. Remove those misleading page actions; disclose current read-only scope. | Source inspection + rendered-page test |
| Supplier list | An empty vendor table displayed fictional supplier names/contacts. Return a real empty state. | Empty-vendor-table regression |
| Document status | Hardcoded “AI engine operating normally” was shown without checking provider/queue status. Replace with a neutral feature label. | Source inspection and browser |
| Frontend API inventory | Unused `api_getAvailableDates` wrapper had no dispatch implementation. Remove it; test literal SPA/admin `gsRun` dispatch names. | Static API contract regression |
| User guidance | No comprehensive accessible manual. Add authenticated `/help`, ERP sidebar and attendance-app links. | Authenticated route/menu coverage test; browser desktop/mobile search |

## Review map

| Workflow | Menus reviewed | Result / boundary |
| --- | --- | --- |
| Daily oversight | Dashboard, AI command, alerts | Browser load; scheduler warnings are visible in the isolated environment where scheduler is deliberately off. |
| Field execution | WBS, ops room, daily report, correspondence, safety | Browser load; existing workflow regression coverage. Actual daily-report email delivery not sent. |
| Workforce | Attendance logs, own attendance, HR, employee administration, applicants, access control, payroll, pay profiles | Browser load and full regression suite; HR-only worker enrollment already deployed in PR73/74. Foremen have no registration approval/comments. |
| Materials/assets | Inventory, item master, equipment checks, BOQ, suppliers, vehicle, housing | Browser load; repaired housing and supplier empty state. Housing is a legacy read-only baseline view; personal move-in/out, repairs and damage acceptance are not implemented. |
| Money | Finance, billing, WBS procurement | Browser load and connector regression tests; no real payments, invoices or financial approvals performed. |
| Documents | AI document hub, integrated documents, contracts, submittals | Browser load; full existing document tests; no actual provider billing or production-key check performed. |
| Administration | Company/team setup, Kakao reminders, sites/projects, organization settings, messenger/admin | Browser load; actual Kakao/SMTP/push delivery not tested. |

## Remaining operational limitations (not reported as fixed)

- Housing needs a separate implemented workflow for personal occupancy, before/after photos, signed rules, damage assessment and worker requests. The current database `beds` value is treated as capacity, not a verified bedroom count.
- Incoming Outlook/Gmail/Slack/Kakao content collection is not established by this audit. External authorization and implemented ingestion paths must be verified separately. Correspondence ledger is outgoing ERP correspondence, not a complete mailbox.
- Automatic expense connectors can encounter the same transaction through multiple sources. Existing duplicate-suspect handling and human approval remain necessary. No automatic historical reconciliation was run.
- Currency labels do not establish exchange-rate conversion or consolidated multicurrency accounting. Review external-currency amounts before approval.
- Production queue/scheduler health, provider quotas, persistent document storage, GPS at the physical site, notification delivery and backup restoration require operational verification. Local absence of these services is not evidence that production is misconfigured.
- Existing already-approved expenses remain immutable to connector refresh. Changes require the normal financial review/correction process.

## User guide

`resources/manuals/user-manual-ko.html` is a self-contained searchable Korean guide: 45 chapters, all 35 sidebar destinations plus onboarding, mobile use, daily operation and troubleshooting. `/help` requires login. Sidebar and mobile attendance links expose it without changing access permissions. The accompanying 48-page PDF is an offline artifact, with Korean font embedding and rendered layout review.

## Validation record

- Browser final pass: 35 menus, no JavaScript/console errors, local HTTP failures or API `success:false` responses in those page-load paths.
- Manual: 45 chapters; search filters content; mobile width 390px has no horizontal overflow.
- PDF: 48 pages, no empty pages; sampled cover/contents/chapters/final page rendered and visually checked.
- JavaScript: 27 tests passed, including two housing renderer cases.
- PHP: 2,111 passed / 1 Windows-only skipped / 7,992 assertions on the isolated PostgreSQL database (186.9 seconds). Linux CI is the additional platform gate.
- Static asset build and `git diff --check` passed.

Shared `SmartCompanyData`, SPA views and routes were changed because the root causes cross the UI/API boundary and the owner explicitly authorized system-wide repairs. No migrations, bulk updates, seeds, scheduled-job execution or financial data cleanup are included in this release.

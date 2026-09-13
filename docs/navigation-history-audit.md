# Navigation history audit — 2026-09-13

## Root cause
The SPA changed page-container content without pushState or popstate. URL retained the initial view/document. Document selection and worker tabs only changed UI. Delayed 120ms navigation callbacks could run after a later selection. QR cleanup discarded the entire query and history state.

## Changes
- Shared ERPHistory controller for initial/deep-link entries, navigation, replacement, native Back/Forward and safe in-app Back fallback.
- All registered administrator routes use it through loadView: sidebar, mobile navigation, account screens and goToView calls.
- URL follows view/site; restore active menu, breadcrumb, WBS project/view, eligible query controls and identified scroll containers. Stop restoration on user interaction. Do not serialize edit forms, passwords or uploads.
- Cancel delayed transitions; invalidate pending WBS render generations when navigating away.
- Embedded document hub uses parent history for list/detail/filter/page/preview, avoiding separate iframe route ownership. Standalone hub uses the same controller. Restore document query after leaving/reload; remove timed deep-link opening.
- Worker home/work/pay/me tabs, mobile operations batch details, global HR site and team board get explicit navigation entries.
- QR cleanup removes only team_code; retains route and unrelated query parameters.
- Add administrator toolbar Back. Native browser Back at the first entry still follows browser behavior; in-app Back uses a safe fallback.

## Verification
- Related PHPUnit suite: 281 passed, 1,143 assertions (ERP navigation, mobile operations, locale and document tests).
- Node controller tests: 9 passed, including ordering, duplicate refresh, document selection, forward-branch replacement, deep links and in-app fallback.
- Chrome with rendered Laravel fixtures and synthetic intercepted APIs: WBS → document list → document1 → document2 → Back/Forward; preview Back/Forward; search/page after leaving and reload; worker tabs; mobile operations detail/back/forward. No page errors.
- JavaScript syntax checked in four rendered templates; git diff --check passed.

## Scope and deployment status
- LOCAL VERIFIED PATCH. Full Linux CI, production deployment and authenticated NAHSHON checks have not been performed.
- All route entry points share the fix. This does not constitute manual functional verification of every business screen with real records.
- Restoration covers identified search/filter/query/period controls, WBS state, document list state and named scrollers. Other module-local tabs, unnamed scrollers and custom widgets require explicit adapters if they do not already retain state.
- Other modules' edit/confirmation modals are not reconstructed by Forward. Form contents must not be serialized to imitate this. Document preview and listed detail routes are explicitly covered.
- Server-page links preserve native history. Links labeled Home remain Home.
- No production data, attendance records, mail messages or configuration changed during tests.
- The user authorized publication to the named public GitHub repository and NAHSHON deployment on 2026-09-13, replying to the explicit publication/deployment question. Deployment verification follows CI.
- Preserve existing document-hardening changes and unrelated daily-archive-pr.txt; do not blanket-add the working tree.

## CODEX next steps
Run full CI, merge through staging/main and verify Deploy NAHSHON. Check real WBS → documents and staff app history without mutating business data; document any module-specific state adapters still needed.

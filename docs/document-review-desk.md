# Document review desk — design 04

The old page reserved the second column for upload controls and opened document details over the list. The selected design gives that column to the document being reviewed. Uploads now use a native dialog; maintenance and document mutation tools are disclosed on demand.

- Warm neutral palette, compact five-part site summary, two-column list/detail workspace.
- Existing server permissions, document storage, AI pipelines, original viewer, edits, deletions, extraction and action completion remain the source of behavior. No data migration or cleanup.
- All existing list filters are preserved, with the existing API status filter and pagination exposed in the UI. The top totals describe site scope; the result count describes the filtered list.
- Detail fetches have a generation check so rapid selection or clearing cannot show an older response. Search changes clear the selected document. List fetches also discard late responses.
- Inline preview is opt-in and uses the same protected server preview URL and response CSP as the existing full viewer. Upload content never becomes application markup.
- At 780px and below, detail stacks beneath the list; selecting a document brings its detail into view.

## Verification

`php -d extension=gd vendor/bin/phpunit --filter DocumentIntelligenceHubTest` covers server access and the embedded review workspace, including read-only roles without management controls.

UI fixture checks (no live user records or external calls):

1. `php scripts/render-review-desk.php`
2. Set `PLAYWRIGHT_MODULE` to a locally installed Playwright package when not on the module path.
3. `node scripts/test-review-desk.mjs` (installed Chrome, isolated headless profile).

Checks list/detail selection, native upload dialog and Escape, inline/full preview, overlapping requests, clearing while loading, status filtering, pagination, horizontal overflow at 1180/980/780/390px, and JavaScript errors. Screenshots are written under ignored `storage/app/review-desk-checks`.

Run full Linux CI before merging to staging and main. Report NAHSHON deployment only after its deployment job and live build verification pass.

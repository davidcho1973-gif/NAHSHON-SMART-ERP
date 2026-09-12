# Daily report room

Daily closing writes the existing report composition into durable document storage and the document hub's built-in `DAY` / 일일 보고서 folder before returning status `done`. No recipient setup or email delivery is required. The existing mail sender calls the same archive writer, retaining its own explicit/previously configured delivery behavior.

Documents are identified by kind, site code and date. Re-closing refreshes both the intelligent document and the existing integrated-document mirror, including file bytes; no new parallel report table is introduced. Failed archiving marks closing failed and surfaces its error. Global is retained as a separate report scope; selecting a physical site remains necessary for that site's report.

The folder is visible even when empty. Completed newly archived reports offer a direct open link in both the situation room and daily report screen. The stored file is printable HTML. Existing historical reports are not regenerated on deployment; re-closing archives them with newly calculated content.

Verification: DailyReportArchiveTest covers no-email filing, idempotent refresh of both files, separate Global/site artifacts, closing lifecycle and failure visibility. No production reports are sent by tests or deployment.

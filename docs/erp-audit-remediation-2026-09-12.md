# ERP audit remediation — 2026-09-12

The legacy adapter had operations that bypassed domain authorization and returned success without performing work. This change enforces role and site boundaries at those entry points and connects available domain services.

## Implemented

- Legacy document original, status, folder, search and dashboard queries share site and sensitive-document filtering. Confirmation/deletion/link operations require management rights. Suspended/disabled sessions are denied on the next web request.
- Operational action and intake mutations validate management authority and the record site. Site-filtered legacy calls cannot expand a scoped user to Global. Asset API mutation routes and NFC vehicle assignment require management authority and validate referenced records.
- Shared-room AI responses are restricted to technical facts even when the question was asked by an administrator. Financial questions use the private ask screen. Document-expiry alerts follow recipient site and financial visibility.
- Vendor email uses OutboundMailer and its durable result ledger. No SMTP/Graph delivery means failure, not a successful mailto handoff. Translation/drafting uses the configured Anthropic provider. Employee status updates persist through EmployeeAdminService. Finance export produces an XLSX archive from scoped expense rows.
- Vendor replies query real received messages through CorrespondenceService's existing scope; text is escaped in the browser. This does not connect an external mailbox.
- Legacy housing assignment, Drive bulk import, rental setup/sample generation and the unfinished legacy employee-photo API no longer claim success. These adapters are not implemented; this change does not invent records or return empty success payloads as proof of completion. Existing separate management screens remain the operational entry points.
- SMTP legacy MAIL_SCHEME=tls becomes smtp with mandatory STARTTLS; ssl becomes smtps. Credentials were not changed and no external test email was sent.
- Attendance closing, indirect clock-out, daily report sending, digest and morning briefing run in separate New York/Phoenix schedules. Each command filters sites to its timezone. Existing manually invoked command behavior remains available. SITE_SCHEDULE_TIMEZONES must include timezones of any future sites outside GA/AZ; app.timezone is also included. No scheduler database query is added to each minute's route loading.
- Contract-versus-expense balance and payroll labels were clarified with EN/ES translations.

## Verification

Local full run: 1,939 tests, 1,929 passed; seven known Windows Bash-dependent failures, one existing Windows backup-path error, one skipped test, and one legacy permission-envelope failure. The envelope was corrected, then the affected tests were rerun: 54 tests / 163 assertions passed. Static asset build, JavaScript syntax, Blade compilation and diff checks passed. Linux CI is the final full-suite gate before merge/deployment.

WbsTest's null-site global fixture now uses a global administrator; it is a form-normalization test, not a permission grant. LegacyBoundaryTest separately verifies scoped denials. DocumentExpiryAlertTest now checks assigned managers receive alerts and unassigned managers/workers do not, replacing the unsafe all-managers assumption.

## Needs operational configuration or separate implementation

- Kakao provider credentials, approved business channel/templates, verified +1 delivery support.
- Daily-report recipients and site GPS/Wi-Fi evidence; no recipients or coordinates were invented.
- Outlook/Gmail/Kakao inbound ingestion requires a configured and authorized connector; no inbound mailbox integration was claimed.
- Physical phones, actual external delivery, and backup restoration were not exercised by automated tests.
- The retired legacy housing/Drive/photo/rental adapters above remain unavailable; making them new operational workflows is not covered by a false-success fix.
- Claude owns document classification, queue recovery, and other data cleanup. No production cleanup command or operational record changes were run for this patch.

Deployment status must be confirmed by the NAHSHON deployment job and live build version, not the unrelated DASOL environment.

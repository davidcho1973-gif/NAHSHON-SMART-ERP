# Attendance action-button verification

Approval and rejection handlers were truncated by unescaped double quotes inside generated HTML `onclick` attributes. The shared AdminUI button helpers now escape the attribute once; the crew screen no longer pre-escapes its handler. Backend permissions, payroll rules, audit history and soft-delete behavior are unchanged.

The production edit form and deletion confirmation opened correctly during read-only inspection. No production approval, rejection, edit/save or deletion was executed. In an isolated browser fixture, all four operations completed and updated the displayed synthetic record.

## Verification

- 14 new handler/action regressions reproduce the original failures and cover approval, rejection, cancellation, edit/save, deletion/restoration, history, permission controls, API errors and crew navigation.
- All 25 JavaScript checks pass. Syntax checks, static build and diff whitespace checks pass.
- Dedicated local PostgreSQL attendance suite: 134 tests / 411 assertions pass.
- Full PHP run: 1,978 tests executed. After fixing local Bash PATH and two existing test-fixture issues, the targeted rerun passed 70 tests / 171 assertions. Combined coverage: 1,977 passing tests; one Windows-only merge-marker test skipped because its shell command redirects to Unix `/dev/null`. The equivalent tracked-text-file marker scan passed.
- DeviceLabelLengthTest now uses a neutral company name; its real long User-Agent and length assertions are unchanged. PurgeEquipmentTest passes its backup filename through structured Artisan options, preserving Windows paths; its backup/deletion assertions are unchanged.

## Delivery status

The user explicitly authorized publication and production deployment after being informed this repository is public. The release branch is rebased onto eaff7d0, preserving the upstream neutral company fixture. PR CI, staging, and NAHSHON production verification are the delivery gates; final run/PR/build evidence will be recorded in the local release record.

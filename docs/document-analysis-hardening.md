# Document analysis reliability — 2026-09-13

## Confirmed incident

CEILING_마감공사_시방서.docx contained valid Korean UTF-8. Byte-mode
/\R{3,}/ consumed a continuation byte (0x85) before blank paragraphs.
JSON serialization failed before HTTP transmission. Changing AI keys cannot fix it.
Production synthetic Gemini, Claude and OpenAI probes all returned OK (Cloud command 83).

## Implemented safeguards

| Stage inspected | Finding/control | Verification |
|---|---|---|
| Intake / storage | Existing scope-aware deduplication retained; preflight checks original SHA-256, size and missing sources | Storage, scope and preflight tests |
| DOCX / text / email | Unicode-safe newlines, ASCII email separators, decoded control filtering | Korean blank paragraphs, accents, emoji |
| XLSX extraction | Resolve shared/inline strings into cell-address/value pairs; enumerate worksheet entries; identify formulas without cached values | ZIP XML fixtures and malformed references |
| Office expansion | 8 MiB per XML entry, 32 MiB total, checked before expansion; reject Excel DTD/entities | Expansion/entity tests |
| AI request | Shared prepare() for actual analysis and audit; UTF-8/JSON validation before HTTP | Invalid text never calls AI |
| AI response | Empty/non-array response is failure, not completed data | Empty-response test |
| Retry | Do not repeat auth, quota or encoding errors as smaller text-only requests | Classification tests |
| Long text | Text-only truncation requires review; skip action/connector/knowledge generation from partial results | Truncation test and review marker |
| Cross-check | Current extracted text replaces stale/missing database text; sanitize failure notes | Current-versus-old source test |
| Result save / scope | Existing row locks, run tokens, scope uniqueness and transaction retained | Queue/scope regressions |
| Filing | Existing copy then commit then cleanup retains source on DB failure | Storage regressions |
| Diagnostics | Failed documents prevent misleading all-clear; bulk preflight uses actual preparation code | Command tests |
| Recovery / alerts | Existing bounded stale-run retry and failure alerts retained | Stuck/queue regressions |

## Operating procedure

1. php artisan docs:diagnose — key presence, queues, stale states and failures.
2. php artisan docs:preflight --limit=200 — originals, hashes, extraction, prompt validity.
   JSON lines contain metadata/counts, never document bodies or keys. No external AI calls
   or record changes. Exit 1 means a source failed.
3. If remaining is nonzero, continue with --after-id=last_id. Never call a limited batch
   a complete audit. --document=id selects a recovery check.
4. Fix the classified cause, then retry exact failed records through the existing queue
   request method. Preserve original document IDs.
5. Confirm original hash, final status, text and scope after recovery.
6. Repeat preflight after parser changes and before bulk imports. CI before staging,
   staging before main; verify Deploy NAHSHON.

## Next-stage prevention design (not silently enabled)

Persist findings keyed by document ID, check code, source hash and parser version.
Only new/worsened findings create scoped admin alerts, with source link, cause,
responsible person, next action and acknowledgement. Do not automatically retry encoding,
auth, corrupt sources or ambiguous scope. Transient failures can use bounded backoff
with queue-wide budgets. An hourly state scan can complement batched source checks;
measure storage cost and runtime before enabling recurring full-source parsing.

## Remaining limits

- Preflight checks input integrity, not factual correctness of AI conclusions.
- Excel formatting, charts, merged layout and images are not rendered. Dates may be serial
  values; cached formulas are not recalculated. Verify quantities against original/rendered sheets.
- Legacy DOC/XLS, encoded multipart email, RTF Unicode escapes, unsupported image types and
  image-only Office need conversion or explicit failed-source handling.
- Native PDFs still depend on provider limits/timeouts. Model fallback budgets need to fit
  the 600-second worker deadline.
- Cross-check is additional evidence, not approval. Existing financial pending/approval
  workflow remains; AI cannot authorize payments.
- Connector/embedding failures need a durable retry ledger and scoped alerts. Source readability
  does not prove all downstream actions completed.
- Old default-queue jobs are not replayed or deleted by this patch.

Record actual production audit and recovery results separately after deployment.

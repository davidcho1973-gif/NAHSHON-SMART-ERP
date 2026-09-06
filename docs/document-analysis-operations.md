# Document analysis queue and duplicate recovery

The intake scope used to differ from the scope inferred by AI. Assigning a project could violate `intelligent_documents_scope_hash_unique`; the exception handler then saved the same dirty model again. Uploads also ran after the HTTP response while retries used an unserved database queue.

## Required worker

New document jobs use connection `document-analysis`, queue `documents`, and the existing database jobs table. The connection's retry window is at least 900 seconds; the document job timeout is 600 seconds. Run one Laravel Cloud background process:

```text
php artisan queue:work document-analysis --queue=documents --sleep=3 --tries=1 --timeout=600 --max-time=3600 --memory=192
```

The supervisor must restart the process after exit or deployment. In **all three environments**, permanently subscribe this worker only to `documents`. Do not add the shared `default` queue: even if today's jobs are documents, future jobs may perform unrelated business actions. Diagnostics and the reaper may still read legacy document jobs in `default`; reading that backlog does not authorize executing the general queue.

Review legacy document jobs separately. Any one-time move must be limited to the reviewed job IDs, exact job class and payload hashes, unreserved/unattempted state, and verified linked-document states, all rechecked in a transaction immediately before changing only the queue field. NAHSHON MEP's 85 legacy jobs were all `AnalyzeIntelligentDocumentJob` at the September 5 incident inspection; that observation is not authorization to subscribe to `default` or a reusable assumption for another environment. Completed, failed, review-required and already-running documents are not reprocessed by old jobs; only a queued document can be claimed. Explicit reanalysis first returns the selected document to queued.

This code is shared by NAHSHON MEP, DASOL and KSR/staging. Install the document worker in **every environment receiving this deployment**, immediately alongside the code rollout. All three lacked background processes during the incident inspection. Keep the existing compute size; no new worker cluster is needed for the initial single-worker setup. Disable App cluster Scale to Zero for these self-managed workers: Cloud can interrupt a running queue job when the sleep timeout expires. See [Cloud compute documentation](https://laravel.com/cloud/docs/compute#scheduled-tasks-and-queues). The former five-minute sleep timeout was shorter than the ten-minute document timeout. Re-enable sleeping only with a queue configuration that safely completes pending work independently of app sleep.

Cloud Scheduler is independent of this background process. A scheduled reaper can enqueue retries but cannot execute them. Pending database jobs are allowed to wait their turn without the reaper marking them failed. Stale running work is recovered after at least 15 minutes, longer than the worker timeout.

## Duplicate handling

`DocumentScope` owns scope normalization and final-scope duplicate handling for intake, AI analysis and human corrections. The database unique constraint remains in place. Legacy project-only records can be recognized within their project's normalized scope. Different explicit companies/projects remain separate.

A duplicate retains its original scope, file, analysis and references, is marked review-required, and links to the existing document. Its analysis does not replace action items or create downstream records. The UI reveals the existing-document link only to users who may access it. A manual move to a verified different scope clears the duplicate annotation only after checking the destination.

## Recover the verified incident records

First review each pair, without `--apply`:

```text
php artisan docs:resolve-duplicate 96 88
php artisan docs:resolve-duplicate 97 91
php artisan docs:resolve-duplicate 98 93
```

The command checks exact hashes, a ready existing document, source state, scope compatibility, uploader visibility and both original files. It refuses active or completed source documents. Applying the reviewed pair adds the existing-document annotation only; it performs no AI call, file deletion or reference reassignment:

```text
php artisan docs:resolve-duplicate 96 88 --apply
php artisan docs:resolve-duplicate 97 91 --apply
php artisan docs:resolve-duplicate 98 93 --apply
```

These IDs are NAHSHON MEP database records, not universal seed data. Never execute them against another environment. Inspect current data before use. Once the verified duplicates are resolved, enable the worker and verify `php artisan docs:diagnose`, queue counts, Cloud process health and a normal new upload. Do not replay all historical successful documents to clear old queue records.

## Deployment and rollback

No schema migration is introduced. Validate on disposable PostgreSQL, open a PR and wait for CI before merging into staging and main. Configure the worker only after the connection/job changes are deployed. Before rolling code back, stop this worker; old code cannot safely interpret or process the new queue behavior. Existing original files and normal document records are retained throughout this repair.

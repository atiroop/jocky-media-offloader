# Queue and transfer architecture

## Configuration and connection test

The Settings and Diagnostics screens retain the Milestone 1 behavior. `CredentialsProvider` resolves secrets from wp-config.php constants and then process environment variables. `Configuration` validates the account ID and bucket and derives the Cloudflare endpoint. The administrator-triggered connection check issues only `HeadBucket`.

## Persistent queue

`QueueSchema` installs versioned InnoDB tables for attachment jobs and individual objects. An attachment job stores a versioned manifest and has `pending`, `processing`, `uploaded`, `verified`, `failed`, or `pending_optimization` status. Each object tracks upload state and resumable multipart data. Job keys and object keys are unique, and object keys include a stable site ID, attachment ID, manifest digest, file digest, and filename.

`JobRepository::claim_next()` recovers expired leases and claims one available job with a conditional `UPDATE ... ORDER BY ... LIMIT 1`; the random token identifies the successful claimant. Failures receive bounded exponential delay and stop after five attempts. A single scheduled WordPress cron hook processes one object step at a time. A worker tick is capped at three steps and 45 seconds, and each multipart request transfers at most one 8 MiB part. Admin processing uses the same bounds.

## Manifest and file safety

`ManifestBuilder` reads the canonical attachment file, WordPress metadata sizes, original image, backup sizes, and JIO derivative records whose local SHA-256 matches JIO's recorded digest. It hashes every candidate and records size, MIME type, and filesystem identity. Paths must resolve to regular files beneath the uploads root; traversal and symlinked files are rejected. Manifests are deduplicated and limited to 250 files and 1 MiB of persisted JSON. A worker checks the attachment metadata fingerprint and revalidates each file path and identity before transfer.

Regenerated metadata or changed files invalidate the job rather than silently extending a stale manifest. Queue the attachment again to capture its current state.

## JIO readiness

JIO 0.6.1-beta.1 exposes `jio_derivative_updated` after a derivative ownership record changes. It is not an attachment-level completion signal. While JIO is active, JPEG/PNG attachment jobs remain in `pending_optimization`; no manifest is enqueued automatically. An administrator can inspect a dry-run and explicitly attest that JIO has finished before queueing. JMO only reads and copies local JIO outputs; it never edits or removes them. Milestone 3 should add a documented JIO completion hook or queryable per-attachment terminal state.

## R2 transfer and verification

`R2ClientFactory` uses the existing credentials and validated account endpoint. Small files are streamed through conditional `PutObject`; large files use resumable multipart uploads with a configurable threshold and 8 MiB parts. Each request is bounded by the R2 client timeout. Uploads use create-only conditions, so existing unrelated keys are not overwritten. If a concurrent object creation wins, JMO verifies its stored digest and size before treating it as the same content.

R2 validates `Content-MD5` for individual requests. JMO stores the full SHA-256 as object metadata, verifies `HeadObject` size and digest metadata, and validates a full-object SHA-256 checksum when the server returns one. Multipart ETags are retained as identifiers only; they are not treated as whole-file checksums. Multipart progress is reconciled with `ListParts` after interruption.

## Keep Local Files

JMO only reads local media. It does not delete, rename, move, replace, or rewrite local files, attachment paths, GUIDs, original URLs, or Elementor references. It does not rewrite frontend URLs or delete R2 objects. Delivery and restoration workflows are outside this milestone.

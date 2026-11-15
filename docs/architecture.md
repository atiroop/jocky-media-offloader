# Architecture

## Milestone 1 boundaries

Plugin wires two capability-gated admin pages. Configuration validates and derives non-secret R2 configuration. CredentialsProvider resolves a complete key pair from wp-config.php constants before environment variables. StorageClientFactory returns the storage contract implementation. R2StorageClient owns the AWS SDK boundary and performs only HeadBucket. ConnectionResult carries safe status fields to a short-lived transient.

The R2 endpoint is built only as https://<32-hex-account-id>.r2.cloudflarestorage.com, with region auto, path-style addressing, and bounded HTTP timeouts. No arbitrary endpoint is accepted. A test factory seam allows mocked requests without credentials or network access.

## Future workflow contract (design only)

The eventual workflow should be:

1. WordPress accepts a media upload.
2. WordPress completes attachment metadata generation.
3. JIO processes supported images, if installed and applicable.
4. JMO waits for confirmed JIO completion; for unprocessed media, it waits until the WordPress metadata workflow is complete.
5. JMO creates a stable manifest of the original and all required derived variants.
6. A background worker uploads the manifest.
7. Remote objects are verified before success is recorded.
8. Offload state is persisted.
9. CDN delivery becomes eligible only after verified state.
10. Local files remain untouched.

Future jobs should be idempotent, retryable, concurrency-safe, and recoverable after attachment regeneration. The design must account for multipart uploads, remote verification, restoration, and WP-CLI operations. No queue, upload listener, file processing, or URL rewrite is part of this milestone.

## JIO findings

The public JIO repository is atiroop/jocky-image-optimizer; its plugin header names jocky-image-optimizer.php, text domain jocky-image-optimizer, and version constant JIO_VERSION. Version inspected: 0.6.1-beta.1. The jio_derivative_updated action is emitted when a derivative record is updated, but this is not an attachment-level completion event. JMO does not bind to it. A future event should document attachment identity, final status, stable manifest visibility, and whether all derivative work is finished.

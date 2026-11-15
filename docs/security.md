# Security decisions

- R2 access keys and secrets come from wp-config.php constants or process environment variables. The plugin does not save secrets in WordPress options.
- The endpoint is derived from the validated Cloudflare account ID. Admin screens require `manage_options`; state-changing forms use WordPress nonces.
- R2 exceptions are converted to fixed diagnostic codes. Credentials, authorization headers, request bodies, and raw SDK errors are never persisted.
- Automatic offloading is OFF by default. The plugin does not enqueue the existing Media Library for bulk migration.
- Uploads are limited to attachment metadata and JIO ownership paths that resolve to regular files under the uploads root. Symlink escapes and traversal are rejected.
- Object writes are create-only. Existing objects are accepted only after remote size and SHA-256 metadata verification. No R2 objects are automatically deleted.
- Local files, attachment paths, GUIDs, original URLs, and JIO outputs are read-only. No frontend URL rewriting is registered.
- Worker leases, retry counts, execution time, batch size, request timeouts, file count, manifest size, multipart thresholds, and multipart part size are bounded.
- CI uses R2 fakes and does not use production credentials. No production media is uploaded during development.

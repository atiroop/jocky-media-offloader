# Milestone 3 delivery and compatibility

## Compatibility

| Component | Supported behavior | Limitation |
| --- | --- | --- |
| WordPress | 6.6+ attachment URL, image source, responsive srcset, and core-recognized content-image filters | Unknown IDs and arbitrary HTML remain local |
| JIO | 0.6.2+ terminal state contract v1 and URL resolver filter | Older/incompatible builds retain explicit manual confirmation |
| Elementor | Image and Image Box final widget HTML URL mapping | Background controls/generated CSS unsupported; Element Caching can serve stored markup outside hooks |
| WP Rocket / edge / browser caches | Safe local fallback for uncached requests after kill switch | Existing cached HTML may continue to contain a prior CDN URL; purge affected page, Elementor cache, WP Rocket/page cache, Cloudflare edge, and browser cache |
| CORS | Normal browser `<img>` / `<picture>` display | Canvas/script reads requiring CORS are unsupported and not certified by the HEAD test |

## URL eligibility

JMO maps only the exact uploads-relative file belonging to a verified object row. It also checks the latest verified job, manifest and metadata fingerprint, all manifest object rows, current local device/inode/size/time identity, configured HTTPS host, and—when JIO 0.6.2+ is active—the current terminal JIO generation/revision. It uses no S3 request during a frontend render. Missing or stale matches preserve the original local URL. Content-addressed keys come from persisted records; JMO never substitutes a hostname or guesses a key.

The CDN readiness action is administrator-only and nonce-protected. It performs a read-only R2 HeadBucket diagnostic and an HTTPS HEAD request to one current, fully verified object. It checks public HTTP status, TLS validation, MIME, and content length, and stores only a sanitized outcome, timestamp, and keyed credential identity fingerprint. It does not upload or delete test objects. Configuration, credentials, or test-object identity changes mark the result stale.

## Production rollout (approval required)

1. Merge the JIO contract PR and install JIO 0.6.2+ where JIO completion integration is required.
2. Review and merge this JMO PR; install JMO 0.3.0 as a staged artifact.
3. Keep Automatic Offload OFF. Confirm CDN domain and run the CDN Readiness Test against a previously verified object.
4. Confirm the rendered HTML and browser network response in staging. Review Elementor Element Caching state and clear the relevant caches.
5. Enable CDN Delivery only after explicit production approval, with one eligible attachment and cache purges scoped to affected pages.

## Emergency rollback

Clear **Enable CDN Delivery** in JMO Settings to restore local URLs for uncached requests. Keep Local Files enabled. Purge affected WordPress/Elementor/WP Rocket and Cloudflare edge caches because disabling the switch cannot revoke URLs in cached HTML. No R2 object deletion is needed or performed.

Background CSS delivery, bulk historical migration, automatic CDN activation, and CDN cache invalidation are outside this release. Automatic Queueing remains off unless an administrator separately opts in.

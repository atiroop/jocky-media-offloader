# Jocky Media Offloader

Jocky Media Offloader (JMO) 0.3.1 provides secure Cloudflare R2 diagnostics, an opt-in background copy queue, and default-off CDN delivery using current, fully verified object records.

## Safety contract

- Local media retention is permanently enabled in this release.
- JMO never deletes or moves local media or changes attachment paths or GUIDs. Optional frontend URLs resolve only exact current verified mappings.
- Automatic offloading is disabled by default. No media is copied unless an administrator queues it or enables automatic queueing.
- Large transfers run through a bounded background worker, not during Media Library uploads.
- The Settings page's Test Connection sends only an authenticated HeadBucket request. An offload job sends PutObject or multipart requests only after explicit queueing or opt-in.
- JIO is optional; JMO activates and operates without it. JIO 0.6.2 or newer provides the versioned completion contract. Current terminal `completed` and all-skipped outcomes are eligible; pending, processing, partial, failed, stale, and malformed results remain held. Older/incompatible JIO keeps the manual completion workflow.
- CDN Delivery defaults off. A successful authenticated R2 test alone does not prove the custom domain publicly serves verified objects; Diagnostics explicitly reports that CDN public readiness has not been tested.

## Requirements

- PHP 8.3+
- WordPress 6.6+
- Composer dependencies are bundled in the installable ZIP.

## Installation and configuration

Install build/jocky-media-offloader-0.3.1.zip from **Plugins → Add Plugin → Upload Plugin**. Set the account ID, bucket, and optional CDN hostname in **Jocky Media Offloader → Settings**. Use **Offload Jobs** to preview a manifest, queue an attachment, process a bounded batch, and inspect results.

Configure secrets in wp-config.php:

    define('JMO_R2_ACCOUNT_ID', 'your-account-id');
    define('JMO_R2_ACCESS_KEY_ID', 'your-access-key-id');
    define('JMO_R2_SECRET_ACCESS_KEY', 'your-secret-key');

Or set JMO_R2_ACCOUNT_ID, JMO_R2_ACCESS_KEY_ID, and JMO_R2_SECRET_ACCESS_KEY in the PHP process environment. Credential precedence is wp-config.php constants, then environment variables. Account ID, bucket, and CDN hostname are non-secret options. The endpoint is derived from the account ID and is restricted to the Cloudflare R2 endpoint pattern.

The connection test issues a signed, non-mutating S3 HeadBucket request with five-second connect and ten-second request timeouts. Some credentials may authenticate but lack bucket inspection permission; diagnostics label this as insufficient permissions and do not call it operational readiness. Error text is deliberately generic and never includes SDK exception details. The background transfer client uses the same credential provider and validated endpoint, with bounded request timeouts.

## Development

    composer install
    composer validate --no-check-publish
    composer lint
    composer test

Create the production ZIP with composer package on Windows or run the packaging commands in .github/workflows/ci.yml on Linux. The archive contains a single jocky-media-offloader/ root and production Composer dependencies only.

## Documentation

- [Queue and transfer architecture](docs/architecture.md)
- [Milestone 3 delivery and compatibility](docs/milestone-3.md)
- [Security decisions](docs/security.md)
- [Milestones](docs/milestones.md)

## JIO detection

JMO identifies JIO from the published plugin name/text domain and reads `JIO_VERSION` when available. JIO 0.6.2 adds the `jio_attachment_optimization_state_v1` filter and `jio_attachment_optimization_terminal_v1` action. JMO snapshots only after the expected work set is terminal and rechecks generation/revision across manifest hashing. Older or incompatible JIO retains explicit manual completion confirmation.

## CDN delivery limits and rollback

Supported paths include WordPress attachment URLs, image source/srcset APIs, core-recognized content images, JIO picture sources, and Elementor Image/Image Box widgets when their final render hook runs. Elementor background controls and generated CSS are unsupported. Active Elementor Element Caching may return stored markup without running the rewrite filter; clear Elementor cache and regenerate files/data, then purge relevant WP Rocket/page cache, Cloudflare edge cache, and browser cache. JMO does not purge a whole site for each object. Disabling CDN Delivery immediately restores local URLs for uncached requests, but cannot revoke HTML already stored in external caches. Public CDN readiness (HTTPS, object retrieval, MIME, and length) remains an explicit precondition and is not inferred from R2 HeadBucket.

# Jocky Media Offloader

Jocky Media Offloader (JMO) 0.2.1 provides secure Cloudflare R2 diagnostics and an opt-in, background media copy queue. Milestone 2 adds durable jobs, confined manifests, streaming/multipart uploads, and remote verification.

## Safety contract

- Local media retention is permanently enabled in this release.
- JMO never deletes or moves local media, changes attachment paths or GUIDs, rewrites frontend URLs, or intercepts optimization.
- Automatic offloading is disabled by default. No media is copied unless an administrator queues it or enables automatic queueing.
- Large transfers run through a bounded background worker, not during Media Library uploads.
- The Settings page's Test Connection sends only an authenticated HeadBucket request. An offload job sends PutObject or multipart requests only after explicit queueing or opt-in.
- JIO is optional; JMO activates and operates without it.

## Requirements

- PHP 8.3+
- WordPress 6.6+
- Composer dependencies are bundled in the installable ZIP.

## Installation and configuration

Install build/jocky-media-offloader-0.2.1.zip from **Plugins → Add Plugin → Upload Plugin**. Set the account ID, bucket, and optional CDN hostname in **Jocky Media Offloader → Settings**. Use **Offload Jobs** to preview a manifest, queue an attachment, process a bounded batch, and inspect results.

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
- [Security decisions](docs/security.md)
- [Milestones](docs/milestones.md)

## JIO detection

JMO identifies JIO from the published plugin name/text domain and reads the JIO_VERSION constant when available. JIO 0.6.1-beta.1 was inspected. JMO queues JPEG/PNG images as `pending_optimization` while JIO is active. An administrator must explicitly confirm JIO completion before JMO snapshots core media and hash-matching JIO derivative records. JIO's derivative update action is per derivative and does not prove attachment-level completion. A supported completion contract remains a Milestone 3 dependency.

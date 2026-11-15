# Jocky Media Offloader

Jocky Media Offloader (JMO) is an independent WordPress plugin foundation for future media storage offloading. Milestone 1 provides secure Cloudflare R2 configuration and an administrator-triggered, read-only bucket connection test. It does **not** offload media.

## Safety contract

- Local media retention is permanently enabled in this release.
- JMO never deletes or moves local media, changes attachment paths or GUIDs, rewrites frontend URLs, or intercepts optimization.
- No R2 requests occur during ordinary page loads. Test Connection sends only an authenticated HeadBucket request.
- JIO is optional; JMO activates and operates without it.

## Requirements

- PHP 8.3+
- WordPress 6.6+
- Composer dependencies are bundled in the installable ZIP.

## Installation and configuration

Install build/jocky-media-offloader-0.1.0.zip from **Plugins → Add Plugin → Upload Plugin**. Set the account ID, bucket, and optional CDN hostname in **Jocky Media Offloader → Settings**.

Configure secrets in wp-config.php:

    define('JMO_R2_ACCOUNT_ID', 'your-account-id');
    define('JMO_R2_ACCESS_KEY_ID', 'your-access-key-id');
    define('JMO_R2_SECRET_ACCESS_KEY', 'your-secret-key');

Or set JMO_R2_ACCOUNT_ID, JMO_R2_ACCESS_KEY_ID, and JMO_R2_SECRET_ACCESS_KEY in the PHP process environment. Credential precedence is wp-config.php constants, then environment variables. Account ID, bucket, and CDN hostname are non-secret options. The endpoint is derived from the account ID and is restricted to the Cloudflare R2 endpoint pattern.

The test issues a signed, non-mutating S3 HeadBucket request with five-second connect and ten-second request timeouts. Some credentials may authenticate but lack bucket inspection permission; diagnostics label this as insufficient permissions and do not call it operational readiness. Error text is deliberately generic and never includes SDK exception details.

## Development

    composer install
    composer validate --no-check-publish
    composer lint
    composer test

Create the production ZIP with composer package on Windows or run the packaging commands in .github/workflows/ci.yml on Linux. The archive contains a single jocky-media-offloader/ root and production Composer dependencies only.

## Documentation

- [Architecture and future workflow](docs/architecture.md)
- [Security decisions](docs/security.md)
- [Milestones](docs/milestones.md)

## JIO detection

JMO identifies JIO from the published plugin name/text domain and reads the JIO_VERSION constant when available. JIO 0.6.1-beta.1 was inspected. Its public jio_derivative_updated action indicates that a derivative record changed; it does not establish that every image-processing job for an attachment has completed. A future integration needs a documented completion contract.

=== Jocky Media Offloader ===
Contributors: atiroop
Tags: cloudflare, r2, media, storage
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure Cloudflare R2 diagnostics and opt-in background media copies for WordPress.

== Description ==

JMO adds secure R2 settings, a manual bucket connection diagnostic, and an administrator-controlled offload queue. Automatic queueing is disabled by default. Files stay on the local server; attachment metadata and URLs are unchanged. Jocky Image Optimizer is optional and JPEG/PNG uploads wait for explicit completion confirmation when JIO is active.

== Installation ==

1. Upload the ZIP through Plugins > Add Plugin > Upload Plugin.
2. Activate Jocky Media Offloader.
3. Configure the Cloudflare account ID and bucket under Jocky Media Offloader.
4. Define credentials in wp-config.php or the process environment.
5. Use Test Connection when you want to make a read-only bucket access request.
6. Use Offload Jobs to dry-run a single attachment manifest or explicitly queue one attachment for background upload and verification.

== Credentials ==

Define JMO_R2_ACCOUNT_ID, JMO_R2_ACCESS_KEY_ID, and JMO_R2_SECRET_ACCESS_KEY in wp-config.php, or set the corresponding JMO_R2_* environment variables. Secrets are not stored in WordPress options.

== Privacy ==

Test Connection sends an authenticated HEAD Bucket request only after an administrator selects the action. Offload jobs send media only after an administrator queues an attachment or enables automatic queueing. JMO does not delete, move, or rewrite local media, URLs, GUIDs, or JIO output files.

== Changelog ==

= 0.2.1 =
* Add accessible AJAX R2 connection feedback, persistent results, and stale-result detection.

= 0.2.0 =
* Secure R2 configuration and read-only connection diagnostics.
* Durable opt-in queue, safe manifests, bounded worker, conditional streaming/multipart uploads, and remote verification.
* Keep Local Files enforcement, JIO pending-optimization state, admin dry-run, and job status controls.

= 0.1.0 =
* Initial foundation release with secure R2 configuration and read-only connection diagnostics.

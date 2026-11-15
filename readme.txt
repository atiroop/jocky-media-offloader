=== Jocky Media Offloader ===
Contributors: atiroop
Tags: cloudflare, r2, media, storage
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure, read-only Cloudflare R2 connectivity diagnostics for WordPress media.

== Description ==

Milestone 1 adds secure Cloudflare R2 settings and a manual, read-only connection diagnostic. It does not upload media, change attachment metadata, rewrite URLs, or delete local files. Jocky Image Optimizer is optional.

== Installation ==

1. Upload the ZIP through Plugins > Add Plugin > Upload Plugin.
2. Activate Jocky Media Offloader.
3. Configure the Cloudflare account ID and bucket under Jocky Media Offloader.
4. Define credentials in wp-config.php or the process environment.
5. Use Test Connection when you want to make a read-only bucket access request.

== Credentials ==

Define JMO_R2_ACCOUNT_ID, JMO_R2_ACCESS_KEY_ID, and JMO_R2_SECRET_ACCESS_KEY in wp-config.php, or set the corresponding JMO_R2_* environment variables. Secrets are not stored in WordPress options.

== Privacy ==

JMO sends an authenticated HEAD Bucket request to Cloudflare R2 only after an administrator explicitly selects Test Connection. It does not send media files.

== Changelog ==

= 0.1.0 =
* Initial foundation release with read-only R2 connectivity diagnostics.

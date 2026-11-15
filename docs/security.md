# Security

- Access keys and secrets are read from JMO_R2_* constants first and environment variables second. Secret options are not registered or stored.
- The interface shows only readiness and credential source. Credentials are not exposed through a public endpoint.
- R2 endpoint input is not accepted; it is derived from a validated Cloudflare account ID. CDN configuration accepts a hostname only.
- All admin routes and pages require manage_options. Settings use the Settings API; the connection action checks a WordPress nonce.
- Connection errors are mapped to generic statuses and messages. Raw SDK exception messages, response bodies, request headers, and credentials are not persisted.
- The only storage operation is read-only HeadBucket, triggered by an administrator and bounded by connection/request timeouts.
- Keep Local Files is permanently enabled. No filesystem or media-delivery integration is registered.
- Deactivation and uninstall preserve JMO settings, local media, remote objects, and shared media metadata.

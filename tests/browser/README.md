# JMO attachment image browser regression

`attachment-image-delivery.mjs` validates the WordPress attachment image fallback and the browser-selected `src`/`srcset` resource against the current verified JMO manifest. It deliberately checks the fallback `img.src` even when JIO's WebP `<source>` is selected, because browsers may use the fallback.

Run it from an isolated WordPress staging environment with JMO CDN Delivery enabled and the page containing the test attachment already rendered:

```powershell
$env:JMO_BROWSER_TEST_URL = 'https://staging.example.test/attachment-test/'
$env:JMO_BROWSER_EXPECTED_SRC = '<exact CDN URL from the current verified manifest>'
$env:JMO_BROWSER_EXPECTED_SHA256 = '<manifest SHA-256 for that exact object>'
$env:JMO_BROWSER_EXPECTED_MIME = 'image/png'
$env:JMO_BROWSER_IMAGE_SELECTOR = 'img'
node tests/browser/attachment-image-delivery.mjs
```

Use Playwright from the isolated browser test environment. For a local self-signed S3Mock gateway only, set `JMO_BROWSER_IGNORE_HTTPS_ERRORS=1`. The script does not write to WordPress or object storage.

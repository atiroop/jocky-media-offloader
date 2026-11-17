import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { chromium } from 'playwright';

const required = ['JMO_BROWSER_TEST_URL', 'JMO_BROWSER_EXPECTED_SRC', 'JMO_BROWSER_EXPECTED_SHA256', 'JMO_BROWSER_EXPECTED_MIME'];
for (const name of required) {
  if (!process.env[name]) throw new Error(`Missing required environment variable: ${name}`);
}

const expectedUrl = process.env.JMO_BROWSER_EXPECTED_SRC;
const expectedSha256 = process.env.JMO_BROWSER_EXPECTED_SHA256.toLowerCase();
const expectedMime = process.env.JMO_BROWSER_EXPECTED_MIME.toLowerCase();
const selector = process.env.JMO_BROWSER_IMAGE_SELECTOR || 'img';
const browser = await chromium.launch({
  headless: true,
  executablePath: process.env.JMO_BROWSER_EXECUTABLE_PATH || undefined,
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
const context = await browser.newContext({
  ignoreHTTPSErrors: process.env.JMO_BROWSER_IGNORE_HTTPS_ERRORS === '1',
});
const page = await context.newPage();
const imageResponses = [];
const consoleErrors = [];
const failedRequests = [];
page.on('console', (message) => {
  if (message.type() === 'error') consoleErrors.push(message.text().slice(0, 500));
});
page.on('pageerror', (error) => consoleErrors.push(String(error).slice(0, 500)));
page.on('requestfailed', (request) => {
  failedRequests.push({ url: request.url(), error: request.failure()?.errorText || 'unknown' });
});
page.on('response', async (response) => {
  if (response.request().resourceType() !== 'image') return;
  const headers = await response.allHeaders();
  let sha256 = null;
  let bytes = null;
  try {
    const body = await response.body();
    bytes = body.length;
    sha256 = crypto.createHash('sha256').update(body).digest('hex');
  } catch (_) {}
  imageResponses.push({
    url: response.url(),
    status: response.status(),
    contentType: headers['content-type'] || null,
    contentLength: headers['content-length'] || null,
    bytes,
    sha256,
  });
});

try {
  const pageResponse = await page.goto(process.env.JMO_BROWSER_TEST_URL, { waitUntil: 'networkidle', timeout: 30000 });
  assert.ok(pageResponse, 'The test page returned no response.');
  assert.equal(pageResponse.status(), 200, 'The test page must return HTTP 200.');

  const image = page.locator(selector).first();
  await image.waitFor({ state: 'attached', timeout: 10000 });
  await image.evaluate(async (element) => {
    if (!element.complete) {
      await new Promise((resolve) => {
        element.addEventListener('load', resolve, { once: true });
        element.addEventListener('error', resolve, { once: true });
        setTimeout(resolve, 8000);
      });
    }
    try { await element.decode(); } catch (_) {}
  });
  const rendered = await image.evaluate((element) => ({
    src: element.src,
    currentSrc: element.currentSrc,
    srcset: element.srcset,
    sizes: element.sizes,
    complete: element.complete,
    naturalWidth: element.naturalWidth,
    naturalHeight: element.naturalHeight,
    html: element.closest('picture')?.outerHTML || element.outerHTML,
  }));
  const otherImages = await page.locator('img').evaluateAll((elements) => elements.slice(1).map((element) => ({
    src: element.src,
    currentSrc: element.currentSrc,
    complete: element.complete,
    naturalWidth: element.naturalWidth,
    naturalHeight: element.naturalHeight,
  })));
  await page.waitForTimeout(50);

  assert.equal(rendered.src, expectedUrl, 'The fallback src must exactly match the current verified manifest URL.');
  assert.equal(rendered.complete, true, 'The browser did not complete the image load.');
  assert.ok(rendered.naturalWidth > 0 && rendered.naturalHeight > 0, 'The selected image has no intrinsic dimensions.');
  assert.ok(rendered.currentSrc, 'The browser did not select an image URL.');
  assert.ok(imageResponses.some((response) => response.url === rendered.currentSrc && response.status === 200), 'The browser-selected resource did not return HTTP 200.');
  assert.ok(!consoleErrors.length, `Browser console errors: ${consoleErrors.join(' | ')}`);
  assert.ok(!failedRequests.length, `Failed browser requests: ${JSON.stringify(failedRequests)}`);
  if (process.env.JMO_BROWSER_EXPECT_LOCAL_IMAGE_HOST) {
    for (const other of otherImages) {
      assert.equal(new URL(other.src).hostname, process.env.JMO_BROWSER_EXPECT_LOCAL_IMAGE_HOST, 'An unrelated attachment src was rewritten.');
      assert.ok(other.complete && other.naturalWidth > 0, 'An unrelated local image failed to load.');
    }
  }

  // Exercise the generated fallback src even when a supported <picture> source is selected.
  const fallback = await context.request.get(expectedUrl, { timeout: 15000 });
  const fallbackBody = await fallback.body();
  const fallbackSha256 = crypto.createHash('sha256').update(fallbackBody).digest('hex');
  const fallbackMime = (fallback.headers()['content-type'] || '').split(';')[0].toLowerCase();
  assert.equal(fallback.status(), 200, 'The fallback src does not resolve to a public object.');
  assert.equal(fallbackMime, expectedMime, 'The fallback src MIME type does not match its manifest.');
  assert.equal(fallbackSha256, expectedSha256, 'The fallback src bytes do not match the verified manifest hash.');

  console.log(JSON.stringify({
    pageStatus: pageResponse.status(),
    pageCacheHeaders: {
      cacheControl: pageResponse.headers()['cache-control'] || null,
      age: pageResponse.headers().age || null,
      cfCacheStatus: pageResponse.headers()['cf-cache-status'] || null,
    },
    image: rendered,
    otherImages,
    imageResponses,
    fallback: {
      status: fallback.status(),
      contentType: fallbackMime,
      contentLength: fallback.headers()['content-length'] || String(fallbackBody.length),
      bytes: fallbackBody.length,
      sha256: fallbackSha256,
    },
    consoleErrors,
    failedRequests,
  }, null, 2));
} finally {
  await context.close();
  await browser.close();
}

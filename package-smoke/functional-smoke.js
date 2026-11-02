// Functional-smoke harness that package-smoke.php runs against each install.
//
// Walks the key user flows on a RUNNING install and records, per page: HTTP
// status, a fatal/critical-error marker, console-error count, and a 2x
// screenshot. This is a "does the site load and work as a user" check, not a
// security test.
// Each flow records up to 10 script_failures with URL, status, and content type.
//
// Requires Playwright. Install it standalone and point PW_PATH at it.
//
// Usage:
//   SMOKE_BASE=http://localhost:8889 node functional-smoke.js <outDir>
// Env:
//   SMOKE_BASE   site URL under test        (default http://localhost:8889)
//   SMOKE_USER   admin login                (default admin)
//   SMOKE_PASS   admin password             (default password)
//   PW_PATH      path to the playwright module (default: normal resolution)

const PW = process.env.PW_PATH || 'playwright';
const { chromium } = require(PW);
const fs = require('node:fs');

const BASE = process.env.SMOKE_BASE || 'http://localhost:8889';
const USER = process.env.SMOKE_USER || 'admin';
const PASS = process.env.SMOKE_PASS || 'password';
const OUT = process.argv[2] || './smoke-out';
// Optional: path to the install's debug.log (needs WP_DEBUG_LOG=true). If set, the
// run reports any NEW `PHP Fatal error` lines written while it ran. Fatals are the
// signal we care about — a fatal blocks the page; warnings/notices usually do not.
const DEBUG_LOG = process.env.DEBUG_LOG || null;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const logSize = () => { try { return fs.statSync(DEBUG_LOG).size; } catch { return 0; } };
const newFatals = (fromByte) => {
  try {
    const buf = fs.readFileSync(DEBUG_LOG);
    return buf.slice(fromByte).toString('utf8').split('\n').filter((l) => /PHP Fatal error/i.test(l)).map((l) => l.slice(0, 200));
  } catch { return []; }
};

const PUBLIC_FLOWS = [
  ['01-home', '/'],
  ['02-single-post', '/?p=1'],
  ['03-sample-page', '/?page_id=2'],
];
const ADMIN_FLOWS = [
  ['05-dashboard', '/wp-admin/'],
  ['06-posts-list', '/wp-admin/edit.php'],
  ['07-post-editor', '/wp-admin/post-new.php'],
  ['08-pages', '/wp-admin/edit.php?post_type=page'],
  ['09-media', '/wp-admin/upload.php'],
  ['10-users', '/wp-admin/users.php'],
  ['11-settings-general', '/wp-admin/options-general.php'],
  ['12-permalinks', '/wp-admin/options-permalink.php'],
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const logBaseline = DEBUG_LOG ? logSize() : 0; // only new lines count as this run's fatals
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const out = { base: BASE, flows: [] };

  const visit = async (slug, path) => {
    const consoleErrs = [];
    const scriptFailures = [];
    const onmsg = (m) => {
      if (m.type() === 'error') {
        const location = m.location();
        consoleErrs.push(m.text().slice(0, 1000) + (location.url ? ` (at ${location.url}:${location.lineNumber + 1})` : ''));
      }
    };
    const onerr = (e) => {
      const frame = (e.stack || '').split('\n').find((l) => /^\s*at\s.*(?:https?|file):\/\//.test(l));
      consoleErrs.push('pageerror: ' + e.message.slice(0, 1000) + (frame ? ` (${frame.trim()})` : ''));
    };
    const onresponse = (r) => {
      if (r.request().resourceType() !== 'script' || scriptFailures.length >= 10 || (r.status() >= 300 && r.status() < 400)) return;
      const contentType = r.headers()['content-type'] || '';
      const isJavaScript = /^(?:application\/(?:x-)?(?:java|ecma)script|text\/(?:(?:x-)?(?:java|ecma)script|javascript1\.[0-5]|jscript|livescript))$/i.test(contentType.split(';')[0].trim());
      if (r.status() >= 400 || !isJavaScript) scriptFailures.push({ url: r.url(), status: r.status(), content_type: contentType });
    };
    page.on('console', onmsg); page.on('pageerror', onerr); page.on('response', onresponse);
    let status = 'ERR';
    try { const r = await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 25000 }); status = r ? r.status() : 'ERR'; } catch (e) {}
    await sleep(900);
    const info = await page.evaluate(() => {
      const html = document.body ? document.body.innerHTML : '';
      const text = document.body ? document.body.innerText : '';
      return {
        title: document.title,
        fatal: /Fatal error|There has been a critical error|call to undefined/i.test(html),
        needsBuild: /without JavaScript and CSS files/i.test(text),
        snippet: text.replace(/\s+/g, ' ').slice(0, 120),
      };
    }).catch(() => ({}));
    await page.screenshot({ path: `${OUT}/${slug}.png` }).catch(() => {});
    page.off('console', onmsg); page.off('pageerror', onerr); page.off('response', onresponse);
    const verdict = (status === 200 && !info.fatal && !info.needsBuild)
      ? 'OK' : (info.needsBuild ? 'NEEDS-BUILD' : (info.fatal ? 'FATAL' : `HTTP ${status}`));
    out.flows.push({
      slug,
      path,
      status,
      verdict,
      console_errors: consoleErrs.length,
      console_error_messages: consoleErrs.slice(0, 3),
      script_failures: scriptFailures,
    });
    return verdict;
  };

  for (const [slug, path] of PUBLIC_FLOWS) await visit(slug, path);

  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => {});
  // wp-login.php focuses and selects #user_login 200ms after load; fill after that or the password lands in the wrong field.
  await page.waitForFunction(() => document.activeElement && document.activeElement.id === 'user_login', null, { timeout: 3000 }).catch(() => {});
  await page.fill('#user_login', USER).catch(() => {});
  await page.fill('#user_pass', PASS).catch(() => {});
  await Promise.all([page.waitForLoadState('domcontentloaded').catch(() => {}), page.click('#wp-submit').catch(() => {})]);
  await sleep(1200);
  const loggedIn = await page.evaluate(() => !!document.getElementById('wpadminbar') || /Dashboard/i.test(document.title)).catch(() => false);
  await page.screenshot({ path: `${OUT}/04-after-login.png` }).catch(() => {});
  out.login = { logged_in: loggedIn };
  // A "Database Update Required" screen here means the shared DB needs `wp core update-db`, not a defect.

  if (loggedIn) { for (const [slug, path] of ADMIN_FLOWS) await visit(slug, path); }

  await page.goto(`${BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' }).catch(() => {});
  const logoutHref = await page.evaluate(() => { const a = [...document.querySelectorAll('a')].find((a) => /action=logout/.test(a.href)); return a ? a.href : null; }).catch(() => null);
  if (logoutHref) {
    await page.goto(logoutHref, { waitUntil: 'domcontentloaded' }).catch(() => {});
    const confirm = await page.$('a[href*="action=logout"][href*="_wpnonce"]').catch(() => null);
    if (confirm) await confirm.click().catch(() => {});
    await sleep(700);
    out.logout = { confirmed: await page.evaluate(() => /logged out|user_login|loginform/i.test(document.body.innerText + document.body.innerHTML)).catch(() => false) };
    await page.screenshot({ path: `${OUT}/13-logout.png` }).catch(() => {});
  } else out.logout = { confirmed: 'no-link' };

  if (DEBUG_LOG) {
    out.new_fatals = newFatals(logBaseline); // any `PHP Fatal error` logged during this run
    if (out.new_fatals.length) out.verdict = 'FATALS-IN-LOG';
  }
  fs.writeFileSync(`${OUT}/result.json`, JSON.stringify(out, null, 2));
  console.log(JSON.stringify(out, null, 2));
  await browser.close().catch(() => {});
  process.exit(0);
})().catch((e) => { console.error('SMOKE ERR:', e.message); process.exit(1); });

#!/usr/bin/env node
// Tiny browser CLI for parallel QA agents. One persistent Chromium profile per
// role (cookies survive between invocations), headless, no shared state.
//
//   node qa.js <profile> <cmd> [args...] [-- <cmd> [args...] ...]
//
// commands:
//   goto <url>                    navigate (prints snapshot after)
//   login <email> <password>      sign in at /signin (prints snapshot after)
//   click <selector>              click
//   fill <selector> <value>       clear + type
//   select <selector> <value>     choose option by label or value
//   check <selector> [true|false] checkbox
//   press <key>                   keyboard key on focused element (Enter, Escape…)
//   wait <ms|selector>            sleep or wait for selector
//   snap [selector]               aria snapshot (whole body by default)
//   text <selector>               innerText
//   html <selector>               outerHTML (first 4000 chars)
//   count <selector>              number of matches
//   shot <file.png>               full-page screenshot
//   eval <js expression>          evaluate in page, print JSON
//   url                           current URL + title
//   dialogs                       auto-accept dialogs is on; prints any seen
//
// selectors: any Playwright selector — css, text=…, role=button[name="…"],
// label=…, placeholder=…, nth=… etc. Quote args with spaces.
//
// Every run ends by printing URL, title, any dialog text seen, and (unless
// --quiet) the aria snapshot of the page, so the agent sees where it landed.

const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright-core');

const BASE = process.env.QA_BASE || 'https://erp.adi.com.bd';
const argv = process.argv.slice(2);
const profile = argv.shift();
if (!profile) { console.error('usage: node qa.js <profile> <cmd> ...'); process.exit(2); }
const quiet = argv.includes('--quiet');
const maxLines = (() => { const i = argv.indexOf('--max'); return i >= 0 ? parseInt(argv[i + 1], 10) : 400; })();
const args = argv.filter((a, i, arr) => a !== '--quiet' && a !== '--max' && arr[i - 1] !== '--max');

// split into command groups on "--"
const groups = [];
let cur = [];
for (const a of args) { if (a === '--') { if (cur.length) groups.push(cur); cur = []; } else cur.push(a); }
if (cur.length) groups.push(cur);
if (!groups.length) { console.error('no command'); process.exit(2); }

const abs = (u) => (/^https?:/.test(u) ? u : BASE + (u.startsWith('/') ? u : '/' + u));

(async () => {
  const dir = path.join(__dirname, 'profiles', profile);
  fs.mkdirSync(dir, { recursive: true });
  const ctx = await chromium.launchPersistentContext(dir, {
    headless: true,
    viewport: { width: 1366, height: 900 },
    locale: 'bn-BD',
    ignoreHTTPSErrors: true,
  });
  const page = ctx.pages()[0] || (await ctx.newPage());
  const dialogs = [];
  page.on('dialog', async (d) => { dialogs.push(`${d.type()}: ${d.message()}`); await d.accept(); });
  const consoleErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200)); });
  page.on('response', (r) => { if (r.status() >= 500) consoleErrors.push(`HTTP ${r.status()} ${r.url()}`); });

  const restore = path.join(dir, 'last-url.txt');
  let needSnap = !quiet;
  const out = (s) => process.stdout.write(s + '\n');

  try {
    // resume where the previous invocation left off
    const firstCmd = groups[0][0];
    if (firstCmd !== 'goto' && firstCmd !== 'login' && fs.existsSync(restore)) {
      const last = fs.readFileSync(restore, 'utf8').trim();
      if (last) await page.goto(last, { waitUntil: 'domcontentloaded' });
    }

    for (const g of groups) {
      const [cmd, ...a] = g;
      switch (cmd) {
        case 'goto':
          await page.goto(abs(a[0]), { waitUntil: 'domcontentloaded' });
          await page.waitForLoadState('networkidle').catch(() => {});
          break;
        case 'login': {
          await page.goto(abs('/signin'), { waitUntil: 'domcontentloaded' });
          await page.getByRole('textbox', { name: /ব্যবহারকারীর নাম|username|email/i }).fill(a[0]);
          await page.getByRole('textbox', { name: /পাসওয়ার্ড|password/i }).fill(a[1]);
          await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
            page.getByRole('button', { name: /প্রবেশ করুন|sign in|login/i }).click(),
          ]);
          await page.waitForLoadState('networkidle').catch(() => {});
          break;
        }
        case 'click':
          await page.locator(a[0]).first().click({ timeout: 15000 });
          await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
          break;
        case 'fill':
          await page.locator(a[0]).first().fill(a.slice(1).join(' '), { timeout: 15000 });
          break;
        case 'select': {
          const loc = page.locator(a[0]).first();
          const v = a.slice(1).join(' ');
          try { await loc.selectOption({ label: v }, { timeout: 8000 }); }
          catch { await loc.selectOption(v, { timeout: 8000 }); }
          break;
        }
        case 'check':
          await page.locator(a[0]).first().setChecked(a[1] !== 'false', { timeout: 15000 });
          break;
        case 'press':
          await page.keyboard.press(a[0]);
          await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
          break;
        case 'wait':
          if (/^\d+$/.test(a[0])) await page.waitForTimeout(parseInt(a[0], 10));
          else await page.locator(a[0]).first().waitFor({ timeout: 20000 });
          break;
        case 'snap': {
          const loc = a[0] ? page.locator(a[0]).first() : page.locator('body');
          out('### snapshot' + (a[0] ? ` (${a[0]})` : ''));
          out(clip(await loc.ariaSnapshot()));
          needSnap = false;
          break;
        }
        case 'text':
          out(clip(await page.locator(a[0]).first().innerText()));
          break;
        case 'html':
          out((await page.locator(a[0]).first().evaluate((el) => el.outerHTML)).slice(0, 4000));
          break;
        case 'count':
          out(String(await page.locator(a[0]).count()));
          break;
        case 'shot': {
          const f = path.isAbsolute(a[0]) ? a[0] : path.resolve(a[0]);
          fs.mkdirSync(path.dirname(f), { recursive: true });
          await page.screenshot({ path: f, fullPage: true });
          out('screenshot: ' + f);
          break;
        }
        case 'eval':
          out(JSON.stringify(await page.evaluate(a.join(' ')), null, 1));
          break;
        case 'url':
          break;
        case 'dialogs':
          break;
        default:
          throw new Error('unknown command: ' + cmd);
      }
    }
  } catch (e) {
    out('!! ERROR: ' + (e.message || e).toString().split('\n')[0]);
    process.exitCode = 1;
  }

  out(`### url: ${page.url()}`);
  out(`### title: ${await page.title().catch(() => '')}`);
  if (dialogs.length) out('### dialogs: ' + dialogs.join(' | '));
  if (consoleErrors.length) out('### console/5xx: ' + [...new Set(consoleErrors)].slice(0, 5).join(' | '));
  if (needSnap) { out('### snapshot'); out(clip(await page.locator('body').ariaSnapshot().catch(() => ''))); }
  fs.writeFileSync(restore, page.url());
  await ctx.close();

  function clip(s) {
    const lines = (s || '').split('\n');
    return lines.length > maxLines ? lines.slice(0, maxLines).join('\n') + `\n… (${lines.length - maxLines} more lines, use --max N or snap <selector>)` : s;
  }
})();

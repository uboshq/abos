// Builds one PDF from the live-QA markdown reports. Chromium does the text
// shaping, so Bangla conjuncts render correctly (reportlab does not shape them).
const fs = require('fs');
const path = require('path');
const { marked } = require('marked');
const { chromium } = require('playwright-core');

const R = 'C:/ABOS/reports';
const SHOTS = `${R}/shots`;
const OUT_PDF = `${R}/ABOS-live-QA-report-2026-09-27.pdf`;
const OUT_HTML = path.join(__dirname, 'out', 'report.html');

const CR = String.fromCharCode(13), LF = String.fromCharCode(10), BOM = String.fromCharCode(0xFEFF);
const read = (f) =>
  fs.readFileSync(`${R}/${f}`, 'utf8').split(BOM).join('').split(CR + LF).join(LF);
const bn = (n) => String(n).replace(/\d/g, (d) => '০১২৩৪৫৬৭৮৯'[d]);

const steps = [
  { id: 'ka', file: '2026-09-27-live-tcl-step-ka.md', title: 'ধাপ ক · টাকা ও হিসাব', who: 'owner, accountant' },
  { id: 'kha', file: '2026-09-27-live-tcl-step-kha.md', title: 'ধাপ খ · লোক ও পক্ষ', who: 'hr, manager' },
  { id: 'ga', file: '2026-09-27-live-tcl-step-ga.md', title: 'ধাপ গ · কেনা', who: 'manager, warehouse, accountant' },
  { id: 'gha', file: '2026-09-27-live-tcl-step-gha.md', title: 'ধাপ ঘ · বেচা', who: 'sr, counter, accountant, manager, warehouse' },
  { id: 'uma', file: '2026-09-27-live-tcl-step-uma.md', title: 'ধাপ ঙ · রিপোর্ট ও বাকি সব', who: 'auditor, owner, accountant' },
  { id: 'ux', file: '2026-09-27-ux-review-live.md', title: 'UI/UX পর্যালোচনা', who: 'manager' },
];

const SEV = [
  ['⛔', 'red', 'টাকা বা মাল ভুল'],
  ['⚠️', 'amber', 'কাজ আটকায়'],
  ['ⓘ', 'blue', 'দেখতে খারাপ'],
  ['✅', 'green', 'ঠিক আছে'],
];

function counts(md) {
  const c = {};
  for (const [s] of SEV) c[s] = (md.match(new RegExp(`^\\| ${s}`, 'gm')) || []).length;
  return c;
}

function render(md) {
  // drop the file's own H1 (the section gets one from us) and demote headings
  md = md.replace(/^# .*\n/, '');
  md = md.replace(/^### /gm, '#### ').replace(/^## /gm, '### ');
  let html = marked.parse(md, { gfm: true });
  for (const [s, cls] of SEV) {
    html = html.replace(new RegExp(`<tr>\\s*<td>${s}`, 'g'), `<tr class="sev-${cls}"><td class="sev">${s}`);
  }
  html = html.replace(/<table>([\s\S]*?)<\/table>/g, (whole, inner) => {
    const head = (inner.match(/<thead>[\s\S]*?<\/thead>/) || [''])[0];
    // "<th" alone also matches "<thead>" and counts one column too many
    const cols = (head.match(/<th[ >]/g) || []).length;
    return `<table class="${cols === 6 ? 'findings' : 'plain'}">${inner}</table>`;
  });
  return html;
}

// today's part of summary.md only — everything above the next top-level
// heading, which is where the previous update (another server) begins
const summaryAll = read('summary.md');
const nextH1 = summaryAll.indexOf('\n# ', 2);
const summaryMd = (nextH1 > 0 ? summaryAll.slice(0, nextH1) : summaryAll)
  .split('\n')
  .filter((line) => line.trim() !== '---')
  .join('\n');

const data = steps.map((s) => {
  const md = read(s.file);
  return { ...s, md, c: counts(md) };
});
const total = {};
for (const [s] of SEV) total[s] = data.reduce((a, d) => a + d.c[s], 0);

const img = (rel, cap) => {
  const p = `${SHOTS}/${rel}`;
  if (!fs.existsSync(p)) return '';
  const b64 = fs.readFileSync(p).toString('base64');
  return `<figure><img src="data:image/png;base64,${b64}"><figcaption>${cap}</figcaption></figure>`;
};

const appendix = [
  ['live-tcl-2026-09-27/ক-capital-receipt-wrong-account.png', 'ধাপ ক ⛔ — নগদ মূলধন ব্যাংক/MFS খাতে (RCV-0001)'],
  ['live-tcl-2026-09-27/ক-payment-cash-no-from-account.png', 'ধাপ ক ⛔ — পরিশোধ ভাউচারে "যে খাত থেকে" ঘর নেই'],
  ['live-tcl-2026-09-27/ক-finance-dash-capital-zero.png', 'ধাপ ক ⛔ — অর্থ ড্যাশবোর্ডে "মূলধন এসেছে 0.00"'],
  ['live-tcl-2026-09-27/খ-units-create-403.png', 'ধাপ খ ⚠️ — Manager লগইনে একক তৈরি 403'],
  ['live-tcl-2026-09-27/খ-hr-bank-account-no-field.png', 'ধাপ খ ⚠️ — কর্মী ফর্মে ব্যাংক হিসাব নম্বরের ঘর নেই'],
  ['live-tcl-2026-09-27/গ-grn-approval-outside-tcl.png', 'ধাপ গ ⛔ — GRN অনুমোদনে আটকে, সই দেওয়ার লোক পরীক্ষার হাতে নেই'],
  ['live-tcl-2026-09-27/গ-payment1-confirm.png', 'ধাপ গ ⛔ — পরিশোধ PMT-0001 (1,000) নিশ্চিত; এর পরও ক্রয় ড্যাশবোর্ডের "মোট দেনা" কমেনি'],
  ['live-tcl-2026-09-27/ঘ-owner-invoice-save-403.png', 'ধাপ ঘ ⛔ — চালান থেকে বিল সংরক্ষণ 403, মালিকের লগইনেও'],
  ['live-tcl-2026-09-27/ঘ-direct-approval-lost.png', 'ধাপ ঘ ⛔ — সরাসরি বিক্রয়ের অনুমোদনের অনুরোধ হারিয়ে যায়'],
  ['live-tcl-2026-09-27/ঘ-dc3-wall-blocks-paid-sale.png', 'ধাপ ঘ ⛔ — পুরো টাকা নগদে দেওয়া বিক্রয়ও সার্ভারের দেয়ালে আটকায়'],
  ['live-tcl-2026-09-27/ঘ-sales-dashboard-final.png', 'ধাপ ঘ ⛔ — বিক্রয় ড্যাশবোর্ডের "মোট বকেয়া" বিলের মোট অঙ্ক দেখায়'],
  ['live-tcl-2026-09-27/ঘ-wall-over-limit-cart.png', 'ধাপ ঘ ✅ — বাকির সীমা পার হলে দেয়াল আটকায়, স্পষ্ট বার্তা সহ'],
  ['mobile-0.4.0/final-03-launcher-b.png', 'Mobile 0.4.0 — "সইয়ের অপেক্ষায়" উইজেট, 2 × 2'],
  ['mobile-0.4.0/final-02-launcher-a.png', 'Mobile 0.4.0 — "আজকের হিসাব" উইজেট, লাইভের সংখ্যা সহ'],
  ['mobile-0.4.0/final-01-home.png', 'Mobile 0.4.0 — নতুন হোম, লাইভের সংখ্যা সহ'],
  ['ux-2026-09-27/03-receipt-list-mobile.png', 'UI/UX ⚠️ — mobile তালিকায় card-এর উপরে ভাঙা table header'],
  ['ux-2026-09-27/04-dashboard-mobile.png', 'UI/UX ⚠️ — mobile header-এ কোম্পানির নাম নেই'],
  
  
].map(([f, c]) => img(f, c)).filter(Boolean).join('\n');

const countRow = (label, c, who) =>
  `<tr><td>${label}</td><td class="muted">${who}</td>${SEV.map(([s, cls]) => `<td class="num n-${cls}">${c[s]}</td>`).join('')}</tr>`;

const html = `<!doctype html><html lang="bn"><head><meta charset="utf-8">
<title>ABOS লাইভ পরীক্ষা — ২৭ সেপ্টেম্বর ২০২৬</title>
<style>
  @page { size: A4 landscape; margin: 14mm 12mm 16mm 12mm; }
  * { box-sizing: border-box; }
  body { font-family: 'Kalpurush', 'Nirmala UI', 'Segoe UI', 'Segoe UI Emoji', sans-serif;
         font-size: 10.5pt; line-height: 1.5; color: #15201b; margin: 0; }
  h1 { font-size: 22pt; margin: 0 0 4pt; color: #0f6b4c; }
  h2 { font-size: 17pt; margin: 0 0 8pt; color: #0f6b4c; border-bottom: 2px solid #0f6b4c;
       padding-bottom: 4pt; page-break-before: always; }
  h3 { font-size: 13pt; margin: 14pt 0 6pt; color: #0a4a34; }
  h4 { font-size: 11pt; margin: 10pt 0 4pt; }
  p, li { orphans: 3; widows: 3; }
  code { font-family: Consolas, 'Kalpurush', monospace; font-size: 9pt; background: #eef2f0;
         padding: 0 3px; border-radius: 3px; word-break: break-all; }
  table { border-collapse: collapse; width: 100%; margin: 6pt 0 10pt; font-size: 9pt; }
  table.findings { table-layout: fixed; }
  th, td { border: 1px solid #cfd8d3; padding: 4pt 5pt; vertical-align: top;
           overflow-wrap: anywhere; }
  th { background: #0f6b4c; color: #fff; font-weight: 700; text-align: left; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  .findings th:nth-child(1), .findings td:nth-child(1) { width: 5%; text-align: center; }
  .findings th:nth-child(2), .findings td:nth-child(2) { width: 17%; }
  .findings th:nth-child(3), .findings td:nth-child(3) { width: 14%; }
  .findings th:nth-child(4), .findings td:nth-child(4) { width: 22%; }
  .findings th:nth-child(5), .findings td:nth-child(5) { width: 34%; }
  .findings th:nth-child(6), .findings td:nth-child(6) { width: 8%; }
  td.sev { font-size: 12pt; text-align: center; }
  tr.sev-red td { background: #fdecea; }
  tr.sev-amber td { background: #fff6e5; }
  tr.sev-blue td { background: #f1f6fb; }
  tr.sev-green td { background: #f0f8f2; }
  .cover { height: 165mm; display: flex; flex-direction: column; justify-content: center; }
  .cover .sub { font-size: 13pt; color: #44524b; margin-bottom: 14pt; }
  .meta { font-size: 10pt; color: #44524b; margin: 2pt 0; }
  .counts { width: 72%; font-size: 11pt; margin-top: 14pt; table-layout: auto; }
  .counts td.num { text-align: center; font-weight: 700; font-size: 13pt; }
  .n-red { color: #b3261e; } .n-amber { color: #9a6300; } .n-blue { color: #1f5f99; } .n-green { color: #1e7a3d; }
  .counts tr.total td { background: #eef2f0; font-weight: 700; }
  .muted { color: #5b655f; font-size: 9.5pt; }
  .legend { font-size: 9.5pt; color: #44524b; margin-top: 8pt; }
  .callout { border-left: 4px solid #b3261e; background: #fdecea; padding: 8pt 10pt; margin: 10pt 0;
             page-break-inside: avoid; }
  .callout h4 { margin: 0 0 4pt; color: #b3261e; }
  figure { margin: 0 0 10pt; page-break-inside: avoid; text-align: center; }
  figure img { max-width: 100%; max-height: 150mm; border: 1px solid #cfd8d3; }
  figcaption { font-size: 9.5pt; color: #44524b; margin-top: 3pt; }
  .shots { columns: 2; column-gap: 10mm; }
</style></head><body>

<section class="cover">
  <h1>ABOS — লাইভে পুরো ব্যবসার পরীক্ষা</h1>
  <div class="sub">Test Company Limited (TCL) · ধাপ ক, খ, গ, ঘ, ঙ ও UI/UX পর্যালোচনা</div>
  <div class="meta"><b>তারিখ:</b> ২৭ সেপ্টেম্বর ২০২৬ &nbsp;·&nbsp; <b>সার্ভার:</b> erp.adi.com.bd (লাইভ, MariaDB)</div>
  <div class="meta"><b>পরীক্ষক:</b> hp2 (ডিপোর QA সেশন) ও তার সাহায্যকারী এজেন্ট &nbsp;·&nbsp; <b>সমন্বয়ক:</b> abos-1d</div>
  <div class="meta"><b>পদ্ধতি:</b> প্রতিটা ভূমিকা নিজের লগইনে, কেবল browser দিয়ে; কোনো কোড ছোঁয়া হয়নি। প্রতিটা কাগজ ৫,০০০ টাকার নিচে।</div>

  <table class="counts">
    <thead><tr><th>ধাপ</th><th>লগইন</th>${SEV.map(([s]) => `<th style="text-align:center">${s}</th>`).join('')}</tr></thead>
    <tbody>
      ${data.map((d) => countRow(d.title, d.c, d.who)).join('\n')}
      <tr class="total"><td>মোট</td><td></td>${SEV.map(([s, cls]) => `<td class="num n-${cls}">${total[s]}</td>`).join('')}</tr>
    </tbody>
  </table>
  <div class="legend">${SEV.map(([s, , t]) => `${s} ${t}`).join(' &nbsp;·&nbsp; ')}</div>

  <div class="callout">
    <h4>সবচেয়ে জরুরি — টাকা ও মালের হিসাব যেখানে মেলে না</h4>
    চালান থেকে বিল সংরক্ষণ 403 (মালিকের লগইনেও): মাল বেরিয়ে গেছে, পাওনা খাতায় বসানোর পথ নেই। সরাসরি বিক্রয়ের অনুমোদনের অনুরোধ হারিয়ে যায়। ক্রয় ও বিক্রয় ড্যাশবোর্ডের "মোট দেনা" আর "মোট বকেয়া" পরিশোধ, আদায় ও ফেরত বাদ দেয় না। খালি টিল থেকে নগদ পরিশোধ হয়ে যায়।
  </div>
</section>

<h2>সারসংক্ষেপ</h2>
${render(summaryMd)}

${data.map((d) => `<h2>${d.title}</h2>
<p class="muted">লগইন: ${d.who} &nbsp;·&nbsp; ${SEV.map(([s]) => `${s} ${d.c[s]}`).join(' &nbsp; ')}</p>
${render(d.md)}`).join('\n')}

<h2>পরিশিষ্ট — বাছাই করা screenshot</h2>
<div class="shots">${appendix}</div>

</body></html>`;

// Whether a password reached the report is checked from outside this file,
// against a list that is itself never written down: QA_SECRET_PATTERN in the
// environment, a regular expression. Unset, the check is skipped and says so.
const secret = process.env.QA_SECRET_PATTERN;
if (secret && new RegExp(secret).test(html)) {
  console.error('A SECRET WAS FOUND IN THE REPORT — aborting');
  process.exit(1);
}
if (!secret) console.log('secret check skipped: QA_SECRET_PATTERN is not set');

fs.mkdirSync(path.dirname(OUT_HTML), { recursive: true });
fs.writeFileSync(OUT_HTML, html, 'utf8');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  await page.goto('file:///' + OUT_HTML.replace(/\\/g, '/'), { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  const fonts = await page.evaluate(() =>
    ['Kalpurush', 'Nirmala UI'].map((f) => `${f}=${document.fonts.check(`12px "${f}"`)}`).join(' '));
  await page.pdf({
    path: OUT_PDF,
    format: 'A4',
    landscape: true,
    printBackground: true,
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate:
      '<div style="width:100%;font-size:8px;color:#5b655f;padding:0 12mm;display:flex;justify-content:space-between;font-family:Kalpurush,Nirmala UI,sans-serif">' +
      '<span>ABOS লাইভ পরীক্ষা · TCL · ২৭ সেপ্টেম্বর ২০২৬</span>' +
      '<span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>',
    margin: { top: '14mm', bottom: '16mm', left: '12mm', right: '12mm' },
  });
  // a picture of the first screen, to check the Bangla by eye
  await page.setViewportSize({ width: 1400, height: 900 });
  await page.screenshot({ path: path.join(__dirname, 'out', 'report-cover-check.png') });
  await browser.close();
  console.log('fonts:', fonts);
  console.log('counts:', JSON.stringify(data.map((d) => [d.id, d.c])), 'total', JSON.stringify(total));
  console.log('pdf:', OUT_PDF, fs.statSync(OUT_PDF).size, 'bytes');
})();

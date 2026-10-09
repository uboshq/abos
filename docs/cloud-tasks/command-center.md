# Cloud task: command-center

> Work on this already started: continue on the existing branch `cloud/command-center` (check `git log` first and build on what is there; do not redo finished steps). Push after every step so progress is never lost.

You are working on ABOS, a Laravel 12 / PHP multi-company ERP (repo github.com/uboshq/abos, branch main). Build the owner's "Executive Command Center + Analytics & BI" (Bengali name: "মালিকের কেন্দ্র") on a NEW branch `cloud/command-center`. Push the branch and open a pull request into main. NEVER push to main, never force-push, never rewrite history. You have no server access; do not deploy anything. Another cloud session is building the Documents module on branch `cloud/documents-phase1` at the same time — do not touch app/Modules/Documents.

## The approved design (by the team, 6 Oct 2026; translated summary)
One menu fold "মালিকের কেন্দ্র" with five pages; every page fits one 1920x1080 screen; NO AI anywhere — every number comes from the ledger, stock or documents and every number is clickable down to its source.

1. আজ / Today (command screen):
   - Group's 8 headline figures: today's sales, today's collections, receivables outstanding, supplier payables, cash+bank total, stock value, this month's profit, signatures waiting.
   - A company × branch grid: one row per branch, the figures as columns, a group total row; the grid scrolls inside itself if more than 10 rows.
   - Right side: alerts and signatures waiting. Bottom: 30-day sales vs collections line chart (values on points, date range under it); top 5 customers and top 5 products this month.
   - Filters on top: company (all ▾), branch (all ▾), period today / this week / this month.
   - Drill-down: grid cell → that company and branch's module dashboard → report → document.
   - Height budget: top bar 56px, headline figures 90px, grid+alerts 420px, trend 260px.
2. তুলনা / Compare: company vs company, branch vs branch, this month vs previous month or same month last year. Columns: sales, collections, receivables, profit, expenses, cash flow. Cell → that company's "branches side by side" or monthly report.
3. বিশ্লেষণ / Analysis: top 10 customers, products, areas, salespeople; low-profit or loss-making customers; margin, receivable ageing, slow and dead stock. Row → that customer's or product's ledger.
4. সতর্কতা / Alerts: customers over credit limit and overdue; stock low, negative or dead; late deliveries; failed sign-in attempts and back-dated documents. Across all companies. Figure → the exact list.
5. রিপোর্ট / Reports: link into the existing report centre (/reports), saved views, scheduled reports, exports.

What already exists (find and REUSE, do not rebuild): ~125 reports on a central `ReportEngine` (app/Core/Engines/Report: filters, date range, totals, top-N, period-over-period compare, streaming); 16 module dashboards on `DashboardEngine` (Stat, Series, Tile, Breakdown, Listing) plus `overall()`; home KPIs and money boxes; `accounts.branches_side_by_side`; `SalesAnalytics`; `sales.margin`, `customer.ageing`, `supplier.ageing`; `stock_alerts`, `slow_dead`; Governance security events; the approval/signature centre; `SaleTracking`; `CashForecast`; `/reports`, `ListExport`, `SavedView`, `report_schedules`; the accounts "group ledger" which already reads across companies via `CompanyContext::forCompany()`.

What is NEW and you must build:
a) Reading every company's figures in one place: enter each company with `CompanyContext::forCompany($id, fn () => ...)` and take the module's OWN figure (same source as that company's dashboard), never a second calculation. Cache per company for 5 minutes, with a "refresh now" button.
b) A nightly snapshot: a table holding the 8 headline figures per company and branch per day, written by a scheduled command at 23:55 (register it the way other scheduled commands are registered), kept 3 years (a prune step). Then a KPI history view ("what were receivables on this day last month").
c) Profit by customer: a new ReportEngine report summing each sales line's actual FIFO cost per customer (find how cost of goods sold is recorded per sale line / cost layers; use it, do not estimate).
d) Drill-down links from every figure to its source.

## Owner's open questions — use these answers (they are the design's recommendations; the coordinator will confirm with the owner before merge)
1. Placement: a separate menu fold "মালিকের কেন্দ্র"; the home page stays as it is.
2. Intercompany elimination (IFRS 10): build it, but data-driven and safe: a small settings table that links a party in one company to a sister company (e.g. company A's customer X = company B). Group totals subtract sales/receivables/payables between linked sister parties and show the note "ভাই-কোম্পানি বাদ". With no links set, nothing is eliminated. Never guess links by name.
3. Snapshot: 23:55 nightly, 8 figures per company and branch, kept 3 years.
4. Scheduled reports by e-mail: NOT now; stay in-app.
5. Drag-drop report builder, OLAP, data warehouse: NOT now.
6. Who sees it: a new permission key `executive.view`. The owner (super admin in every company) sees all their companies; anyone else with the key sees only the companies they belong to (`company_user`) and inside each only the branches their branch scope allows; the header's chosen branch is respected exactly like the existing dashboards. Another person's company must never appear, not even its name.
7. Sales forecast: NOT now.

## Analytics & BI part (from the team's earlier measurement)
- Check whether report export already offers Excel (xlsx) and JSON besides CSV and PDF. If xlsx or JSON is missing, add them as plug-ins of the existing export path so every report gets them at once. For xlsx use `openspout/openspout` (MIT, streaming); this one new dependency is allowed. Keep the existing CSV-injection guard and export audit journal for the new formats.
- A reusable trend helper (daily/weekly/monthly/quarterly/yearly) on top of the existing period-compare, used by the Compare page. No second report engine.

## House rules (strict)
- Copy conventions of neighbouring code: a module under app/Modules (e.g. `Executive`) declaring menu, permissions, dashboards and reports in its module.php like the others; check the module-boundary rules (tests/Feature/Architecture has guards, e.g. a module must not import another module's classes unless declared; read them before you design).
- All user-facing text through lang files in BOTH `bn` and `en`; natural, plain Bengali (the owner reads only Bengali). Money formatted the Bangladeshi way (lakh/crore grouping), like the rest of the app.
- Charts use the chart colour tokens, light AND dark mode (resources/css/tokens.css), every page fits 1920x1080 without page scroll.
- Live DB is MariaDB with ONLY_FULL_GROUP_BY: every GROUP BY must be valid under it; index names ≤ 64 characters.
- Comments in Bengali, matching the codebase's density and style.

## Tests (must be real, not decorative)
- Under tests/Feature/Modules/Executive (or the module's name). At least: every grid cell equals the same figure on that company's own dashboard; the group total equals the sum of the rows; a user never sees a company they do not belong to (also not its name); a branch-limited user sees only their branch; the header branch narrows the page; intercompany elimination subtracts only linked parties and nothing when no links exist; the snapshot command writes one row per company/branch/day, is idempotent if run twice, and prunes beyond 3 years; profit-by-customer equals revenue minus the recorded FIFO cost; xlsx/JSON export (if added) carries the same rows as CSV and is audited.
- Prove each test can fail: break the guarded line, see red, restore.
- Set up MySQL or MariaDB in the sandbox if needed (see phpunit.xml / .env.testing). Run your tests AND the whole tests/Feature/Architecture directory; all green, or prove in the PR that a red was already red on main.
- `vendor/bin/pint` only on files you changed.

## Delivery
- Build in this order, one or more commits per step: (1) Today page with drill-down; (2) Compare and Alerts; (3) nightly snapshot + KPI history; (4) Analysis with profit by customer; (5) xlsx/JSON export + trend helper; (6) intercompany links and elimination.
- Commit messages in English, plain sentences, ending with:
  Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
- PR into main titled "Owner's command center and analytics, phase 1". Body: what was built per page and per step, every migration, every permission, every scheduled command, test counts, what is left and why. End with: 🤖 Generated with [Claude Code](https://claude.com/claude-code)
- Do not merge. The coordinator reviews, merges and deploys after the live business's change freeze (about 21 October 2026).
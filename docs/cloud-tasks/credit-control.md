# Cloud task: credit-control

> Work on branch `cloud/credit-control` (this file is its first commit). Check `git log` first and build on what is there; do not redo finished steps. Push after every step so progress is never lost. Before each pull request, merge the latest `origin/main` into the branch.

You are working on ABOS, a Laravel 13 / PHP 8.3 multi-company ERP (repo github.com/uboshq/abos). Modules live in `app/Modules/<Name>` and are declared by `app/Modules/<Name>/module.php` (read `app/Core/Module/ModuleDefinition.php`). The owner wants a **Credit Control module** ("বাকি নিয়ন্ত্রণ") built from his "Credit Control & Credit Risk Management v2.0" specification, **adapted to his own final rules below**. Open one pull request into `main` per phase. NEVER push to `main`, never force-push, never rewrite history. You have no server access; do not deploy. The coordinator (session "fe") merges and deploys.

**Language:** the owner reads only Bengali. Every PR description, every question to the owner, every user-visible string (`lang/bn/*` with an `en` twin) and every code comment is in Bengali, like the code around it.

## 0. Rules that must not be broken

1. **One calculator.** All credit figures come from `app/Modules/Sales/Services/CreditExposure.php` (and what it calls). Never write a second exposure formula anywhere — a dashboard, report, API or check that computes "due + held − paid" on its own will one day disagree with the wall. If you need a figure it does not expose, add a method there.
2. One commit per change. Every change of behaviour has a claim (feature test) proven by a mutant: put the old source back, see it go red, restore.
3. Run before every PR, in your own cloud environment/CI (never on the owner's computer): `tests/Feature/Architecture` (whole folder), `tests/Feature/Design`, `tests/Feature/Modules/Sales`, `tests/Feature/Modules/Customer`, `tests/Feature/Modules/Accounts`, `tests/Feature/Api`, every test whose name or path contains Credit, Limit, Overdue, Cheque, Collection or Deposit, and `npx vitest run`. If CSS changes, rebuild the bundle (`npm run build`) and commit it. A red you did not cause: prove it is red on `main` too.
4. Production is MariaDB with ONLY_FULL_GROUP_BY; index names ≤ 64 characters. Every migration: guarded with `hasTable`/`hasColumn`, runs on a fresh DB, `up → down → up` runs cleanly on MySQL (drop a foreign key before the index it relies on), and leaves existing live rows untouched. Money columns are `decimal(18,4)`; money arithmetic uses `bcmath` strings, never floats.
5. Every screen fits one 1920×1080 screen; both menus stay; dates use the shared Bengali date component; colours/heights/paddings from tokens; icons from the icon set, no typed emoji (the Design tests enforce this).
6. Company and branch walls everywhere (server side, not only hidden buttons); the header's chosen branch filters everything; a salesperson sees only their own dealers.
7. Every new approval kind respects the company's approval switch exactly like the existing ones, and you never seed an active approval flow (UB has all approvals off; a seeded active flow there was a real incident).

## 1. The owner's final rules (they win over his v2.0 document wherever they differ)

**A. The credit limit is absolute** (owner, 1 Oct 2026, final): *"limit is 100% a limit — nobody can cross it, not even the owner. The only way is that the super admin raises the limit first, then the sale goes."* Therefore:
- **Do NOT build** "Overdue Exception", "Blocked Order Exception", "Risk Override", or any override/bypass button, reason box or path. No exception requests.
- **No wall at the sales order.** An order is an estimate; customers and SRs can always place one; at most show the figures and a warning there. The wall stands at the **delivery order / challan / bill (including the counter's direct sale)** — where it already stands today (`CreditExposure::assertRoom()` / `assertRoomLocked()`).
- The formula is the existing one: **old ledger due + held (challans, draft bills, approved DOs) + this paper − money paid now ≤ limit.** Paying only this bill is not enough when an old due already sits over the limit. Limit 0 = no credit at all.
- The company switch `customer.credit_limit_enabled` (super-admin only) stays: off means no credit checks at all, for businesses that do not want them.

**B. No customer advance account.** Extra money a customer pays is a credit (Cr) in that customer's own ledger, so it is already inside "ledger due". **Drop the spec's "A — advance adjustment" term**; subtracting it again would count the money twice.

**C. No AI, no multi-currency.** Risk rating is rule-based with every reason shown. Ignore the spec's currency filters.

**D. The owner's answers of 11 Oct 2026 for this module:**
1. **Overdue stops new credit.** Once any bill is past its due date, the customer gets no new credit even if limit room remains (paying in full is still allowed — the wall stops only new credit, see `newCredit()`).
2. **Credit days are per customer.** `customers.credit_days` already exists and the counter writes `due_on` on the bill from it. Make it a first-class, audited field on the credit profile; every bill path must set `due_on` from it.
3. **Temporary limit: yes.** Extra limit until a date, then it falls back automatically to the normal limit (no job needed to "undo" it: the effective limit is read as `temporary limit if today ≤ its end date, else normal limit`). Granting it follows rule D5.
4. **A bounced cheque blocks the customer automatically, and only the owner releases the block** — not a payment, not time, not anyone else. "The owner" = the super admin.
5. **Raising the limit:** the accounts staff or a manager writes a **request**; it takes effect only when **the owner signs**. The request never changes the effective limit before that signature. A rejected/expired request is kept (never deleted). The super admin may still set a limit directly, as today. Lowering a limit needs no signature (it lowers risk), as today.

## 2. What already exists — read it, reuse it, do not rebuild it

| Need | Already in ABOS |
|---|---|
| Exposure, room, wall | `CreditExposure`: `check()`, `assertRoom()`, `assertRoomLocked()` (row lock — two counters cannot pass the limit together), `pending()`, `heldFor()`, `openOrders()`, `approvedDeliveryOrders()` |
| Uncleared cheques are not money | `CreditExposure::unclearedCheques()`; `app/Modules/Accounts/Models/Cheque.php` (`BOUNCED`), `ChequeService::bounce()`, `CollectionService::bounceReceivedCheque()` |
| Overdue wall | `CreditExposure::stopsFor()/stopFor()/overdueBills()`, company setting `customer.overdue_block_days` (0 = off; see `app/Modules/Customer/module.php` around line 442), bill `due_on` |
| Manual block | `Customer::isCreditBlocked()`, `credit_blocked_at`, `credit_blocked_by`, `credit_block_reason`; the wall already refuses a blocked customer |
| Limit-raise signature | `app/Modules/Customer/module.php` `'approvals' => ['credit_limit' => …]` (raising asks for a signature, lowering does not); `app/Core/Engines/Approval/DocumentApproval.php` |
| Ageing and due lists | `app/Modules/Customer/Reports/PartyReports.php` (ageing), `app/Modules/Sales/Reports/CollectionDueReport.php` |
| Reports, exports, dashboards | `app/Core/Engines/Report` (ReportEngine, filters, export, schedules), `app/Core/Engines/Dashboard` |
| Audit | the central audit trail (`app/Core/Engines/Audit`, `PaperTrail`) |
| Owner's centre | `app/Modules/Executive` (credit figures go there too; no second executive dashboard) |
| Notifications | the notification centre / `NotificationKinds` |

Read the claims around the wall before you change anything near it: tests with CreditLimit, Limit, Overdue, TwoCountersSoldPastTheLimit, TheCreditSwitchIsTheCompanysOwnChoice, NoLimitMeansNoCreditNotNoSale in their names.

## 3. Phase 1 — the module, dashboard, profile and evidence (PR 1)

1. **Thin module** `app/Modules/CreditControl/module.php` (code `credit_control`, bn "বাকি নিয়ন্ত্রণ", en "Credit Control"), its own colour token, icon (both tables in `resources/views/components/ui/icon.blade.php`) and `tokens.css` entry, placed in the menu next to Customer/Sales. Code may live in this module or in Customer/Sales — follow module boundaries the Architecture tests enforce; rows that borrow another module's routes use `'from' => '<module>'`.
2. **Credit dashboard** (one screen): total limits, current exposure, available, overdue receivables, utilisation %, customers over limit, customers stopped by overdue, blocked customers, pending limit requests, exposure by branch and by salesperson, an overdue ageing chart (Not due, 1–7, 8–15, 16–30, 31–60, 61–90, 90+), recent limit changes, recent blocks/releases. Every figure drills down to its list and from there to the customer and the bill. Figures only from `CreditExposure` and existing reports.
3. **Customer credit profile page**: limit, temporary limit and its end date, effective limit, credit days, status (Active / Over limit / Overdue-stopped / Blocked), ledger due, held, uncleared cheques, available, open bills with due dates and days overdue, and the full change history.
4. **Limit change history**: every change of limit, temporary limit or credit days stored append-only (who, when, old, new, reason, request/approval reference). Show it on the profile.
5. **Wall evidence record**: each time the wall decides at DO / challan / bill / counter, store what it saw (customer, paper, limit used, due, held, this paper, paid now, result pass/stopped, reason code). Append-only. This answers "why was this bill stopped / allowed" later. It must not slow the counter noticeably: one insert inside the same transaction.
6. Claims: the dashboard figures equal `CreditExposure` for the same customers (no second calculator); a salesperson sees only own dealers; branch walls; the evidence row is written for pass and for stop.

## 4. Phase 2 — the owner's five answers (PR 2)

1. **Overdue stops new credit** (D1). The owner's choice means "past due date" itself stops new credit. Make the company setting able to express that (today `customer.overdue_block_days = 0` means *off*; add a clear on/off plus grace days, default for new companies: on, 0 grace — but **do not change the value of any existing company's setting in a migration**; the coordinator will set live companies after the owner confirms). Claims: a bill one day past due stops new credit; paying in full still sells; limit room does not override it.
2. **Per-customer credit days** (D2): on the profile, audited; every path that creates a bill (counter, challan→bill, DO, phone, portal) sets `due_on` from it. Claim per path.
3. **Temporary limit** (D3): fields for amount and end date; the effective limit is computed (no undo job); granting it is a limit raise → rule D5. Claims: before the end date it counts, the day after it does not; a pending request does not count.
4. **Bounced cheque auto-block** (D4): when a customer's cheque becomes `BOUNCED` (all bounce paths), set the credit block with reason "চেক ফেরত — <cheque no>" and notify the owner. Release only by the super admin (server-side check), with a reason, logged. A later payment does not release it. Claims: bounce → blocked; a non-super-admin release attempt is refused (403/422) server-side; payment does not unblock; the release is logged.
5. **Limit-raise request** (D5): accounts or manager role writes a request (customer, current limit, requested limit or temporary limit + end date, reason, attachment via the existing attachment engine); it goes to the owner's signature through the existing approval engine (`credit_limit` approval kind) and takes effect only on that signature; rejected/expired kept. Separation of duties: the requester cannot sign it. If the company's approvals are off, a request cannot be raised by non-super-admins at all (only the super admin sets limits directly) — do not auto-approve. Claims for each.

## 5. Phase 3 — rule-based risk and reports (PR 3)

1. **Risk rating** LOW / MEDIUM / HIGH / CRITICAL from configurable rules: payment delay vs credit days, overdue amount and age, limit-breach attempts (from the evidence record), bounced cheques, block history. Show the score's reasons. The rating informs; it never changes a limit or opens a wall by itself.
2. **Reports** on the ReportEngine, each with its permission key and company/branch/salesperson walls: credit profile, utilisation, available credit, exposure, overdue ageing, high-risk customers, limit-breach attempts, blocked customers, limit request history, pending requests, wall evidence (pass/stop), limit change audit, payment behaviour, branch-wise exposure, salesperson-wise exposure, temporary limits expiring, risk trend.
3. Add the headline credit figures (exposure, overdue, blocked count, pending requests) to the owner's centre.
4. Notifications through the existing notification centre: a temporary limit ending in 3 days, a customer blocked by a bounced cheque, a new limit request waiting for the owner.

## 6. Acceptance claims to include (adapted from the owner's CC-001…CC-018)

- Limit 5,00,000, exposure 3,00,000 → available 2,00,000.
- A new paper adds to exposure correctly; nothing counted twice (bill + its challan + its DO).
- Over the limit at DO/challan/bill/counter → stopped, for every role including the super admin; at the sales order → allowed with a warning.
- An uncleared cheque is not counted as paid.
- A blocked customer → stopped at every wall.
- A pending limit request → the old limit still applies; rejected → nothing changes.
- Two concurrent papers for one customer cannot together pass the limit.
- A non-super-admin cannot release a block (server side).
- A temporary limit past its end date no longer counts.
- A payment reversal or cheque bounce raises exposure again.
- Another branch's customers are invisible without access.
- A retried request / duplicate event does not create two records.
- If the calculation cannot run (exception), the paper is stopped, never silently passed.

## 7. Done means (every PR)

All tests in rule 0.3 green (or each red proven red on `main`); every new behaviour has a claim with its mutant killed; migrations meet rule 0.4; screenshots at 1920×1080 in the PR; the PR description in Bengali (what changed, what did not, open questions); end with the word "শেষ" and the last commit hash.

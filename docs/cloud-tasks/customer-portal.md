# Cloud task: customer-portal

> Work on branch `cloud/customer-portal` (this file is its first commit). Check `git log` first and build on what is there; do not redo finished steps. Push after every step so progress is never lost. Before each pull request, merge the latest `origin/main` into the branch.

You are working on ABOS, a Laravel 13 / PHP 8.3 multi-company ERP (repo github.com/uboshq/abos). The owner wants the **customer self-service portal** grown to his "Customer Portal" plan, so customers stop phoning the depot to ask "how much do I owe, where is my order, how much commission". **The portal already exists — extend it, do not build a second one.** Open one pull request into `main` per phase. NEVER push to `main`, never force-push, never rewrite history. You have no server access; do not deploy. The coordinator (session "fe") merges and deploys.

**Language:** the owner and the customers read Bengali. Every PR description, every question to the owner, every user-visible string (`lang/bn/*` with an `en` twin) and every code comment is in Bengali, like the code around it.

## 0. Rules that must not be broken

1. **The portal is a separate door.** It has its own guard (`config/auth.php` → `portal`, provider `app/Modules/Sales/Auth/CustomerProvider.php`); staff, SRs and users never enter it, and a customer never enters the staff side (`tests/Feature/Core/ADealerOnThePortalIsNotAStaffMemberTest.php`, `ThePortalDoorHadNoLockTest.php`). Every portal query is narrowed to **the logged-in customer and that customer's company** — server side, on every screen and every file download (`tests/Feature/Architecture/EveryPortalScreenAsksTheNarrowPathTest.php` — every new screen must pass it). Another customer's data must be impossible to reach, including by changing an id or a public id in the URL.
2. **Wording:** the portal never calls the customer "dealer" (`tests/Feature/Architecture/ThePortalDoesNotCallThemDealersAgainTest.php`).
3. **No second calculation.** Due, limit and available credit come from `app/Modules/Sales/Services/CreditExposure.php`; the ledger from the same source the office ledger uses; commission from the existing scheme/commission records; tracking from the existing tracking story. The portal only *shows*.
4. One commit per change; every change of behaviour has a claim (feature test) proven by a mutant (old source back → red → restore).
5. Run before every PR, in your own cloud environment/CI (never on the owner's computer): `tests/Feature/Architecture` (whole folder), `tests/Feature/Design`, `tests/Feature/Core`, `tests/Feature/Modules/Sales`, `tests/Feature/Modules/Customer`, `tests/Feature/Api`, every test with Portal, Claim, Deposit, Ledger or Tracking in its name, and `npx vitest run`. If CSS changes, `npm run build` and commit the bundle. A red you did not cause: prove it is red on `main` too.
6. Migrations (only where truly needed): guarded with `hasTable`/`hasColumn`, fresh-DB safe, `up → down → up` clean on MySQL (drop a foreign key before the index it uses), live rows untouched; production is MariaDB with ONLY_FULL_GROUP_BY; index names ≤ 64 characters; money `decimal(18,4)` and `bcmath`.
7. Screens: mobile-responsive (customers use phones) and they also fit 1920×1080 on a desktop; dates use the shared Bengali date component; colours/heights/paddings from tokens; icons from the icon set, no typed emoji.
8. Every file a customer downloads (PDF bill, statement) goes through the same narrow path and is logged (who, what, when).

## 1. The owner's decisions (they win over his plan wherever they differ)

- **No customer advance account.** Money a customer paid beyond their bills is a credit (Cr) in their own ledger. The portal shows it as "আপনার জমা ৳…" taken from the ledger balance — never a separate "advance balance" figure.
- **Stock is shown as a band, not a number:** "আছে / অল্প আছে / নেই" (have / low / none). Never the real quantity (the owner also hides stock on the SR phone). The thresholds come from the product's reorder level; a product with no reorder level shows only আছে / নেই.
- **Prices:** the order and DO forms already show the customer's own price (10 Oct 2026); **tracking stays without prices** (as today); the **invoice centre and its PDF show full prices** — the bill is the customer's own paper.
- **Returns and damage/shortage claims from the portal: yes, as a request.** The customer submits a request with a reason (and photos through the existing attachment/documents engine); it goes to the office; the office checks it and makes the sales return through the existing return path. The request never creates a return, a credit note or a ledger entry by itself.
- **No broker login now.** Only customers log in. (A broker/agent door is a later decision.)
- **Notifications:** inside the portal (and by e-mail where the customer has an address). **SMS is off** and WhatsApp is not connected — do not add either.
- **Credit rules:** the limit is absolute; the portal shows the limit, the due, what is held and what is left, and when an order would go over the limit it says so plainly (an order can still be placed; the wall stands at DO/challan/bill — owner's rule). Never suggest "ask to raise the limit".
- **No AI, no multi-currency, no QR payments.**

## 2. What already exists — read it, reuse it

| Plan section | In ABOS today |
|---|---|
| Login / logout | `PortalController::showLogin/login/logout`, own guard, login lock, journal |
| Dashboard | `PortalController::home` (`views/portal/home.blade.php`) — due, limit, room |
| Ledger | `PortalController::ledger` (`views/portal/ledger.blade.php`), Dr/Cr and balance |
| New order, list, submit | `PortalOrderController` (`order-form`, `order-index`, `order-show`) — `TheCustomerWritesTheOrderOnThePortalTest` |
| DO | `PortalDeliveryOrderController` (`do-form`, `do-index`, `do-show`) |
| Tracking | `PortalController::tracking/trackingStory` (`tracking`, `tracking-show`) — the same story the office sees, without prices |
| Deposit claim + slip | `PortalController::showClaim/storeClaim/showOwnClaim/ownClaimSlip`, `DepositClaimService` (picks the bills it pays) |
| Goods received | `DeliveryScanController::dealer/received` (scan the challan code) |
| Office side: who gets a portal login | `app/Modules/Customer/Http/Controllers/CustomerPortalController.php` (its own permission) |
| Layout | `views/components/portal/layout.blade.php` |
| Commission / schemes | `app/Modules/Sales` — `SchemeController`, `CommissionClaimController`, `Models/CommissionClaim.php` |
| Sales returns | `app/Modules/Sales/Models/SalesReturn.php` and its service/controller |
| Files and papers | `app/Modules/Documents` and the attachment engine (`app/Core/Engines/Attachment`) |
| Bill print/PDF | the existing invoice print designs and PDF path (`SalesPrintController`) |
| Reports | `app/Core/Engines/Report` |

## 3. Phase 1 — invoices, statement, profile (PR 1)

1. **Invoice centre:** list (open / paid / all, date filter), detail, PDF download and print using the existing invoice design and PDF path, only the customer's own posted bills; due vs paid per bill (same figures as the office's collection-due view).
2. **Statement:** the ledger with date filters (today, this month, custom) and "by bill" / "by voucher" views; every row links to its bill/voucher/collection *inside the portal*; PDF download. Keep the ledger's existing start-date behaviour and its claim.
3. **"আপনার জমা"** shown from the ledger when the balance is in the customer's favour (rule §1).
4. **Profile:** contact details (view; changes go to the office as a request — the customer cannot rewrite their own master record), trade licence / NID / BIN papers uploaded through the Documents/attachment engine, password change (with the existing password rules and journal).
5. Claims: another customer's bill/statement/PDF/paper is 404 even with a guessed id; downloads are logged; prices show on bills and not on tracking.

## 4. Phase 2 — catalogue, commission, returns (PR 2)

1. **Product catalogue:** search, brand/category filter, the customer's own price (the same price the order then takes), campaign products, new arrivals, and the stock band (rule §1). Ordering from the catalogue fills the existing order form.
2. **Commission centre (view only):** the customer's schemes, earned and pending/paid commission, a commission ledger — read from the existing scheme/commission records; nothing is calculated in the portal.
3. **Return / damage / shortage request:** a new request record (customer, bill/challan, lines, quantity, reason from the existing reason list, photos), statuses submitted → checked → accepted / refused, visible to the customer; an office screen to check it and open the normal sales return from it. Claims: the request creates no stock or ledger movement; only the office's return does; a customer cannot request against another customer's bill or more than was delivered.

## 5. Phase 3 — notifications, support, reports (PR 3)

1. **Notifications** in the portal (and e-mail): order accepted/refused, DO approved, goods loaded/dispatched/delivered, bill posted, bill due in N days and overdue, deposit claim accepted/refused, scheme news. Use the notification centre (`NotificationKinds`, notification management module) — no SMS.
2. **Support:** ticket/complaint/suggestion with status and replies, routed to the customer's branch office; contact details of the depot; a short FAQ the office can edit.
3. **Reports:** monthly purchases, product-wise purchases, bills and payments summary, commission summary, statement — on the ReportEngine with the portal's narrow path.
4. **Dashboard** additions: this month's purchases, orders by state (pending / approved / in transit), latest notifications.

## 6. Done means (every PR)

All tests in rule 0.5 green (or each red proven red on `main`); every new behaviour has a claim with its mutant killed; every new portal screen passes `EveryPortalScreenAsksTheNarrowPathTest`; migrations meet rule 0.6; screenshots on a phone width (≈400 px) and at 1920×1080 in the PR; the PR description in Bengali (what changed, what did not, open questions); end with the word "শেষ" and the last commit hash.

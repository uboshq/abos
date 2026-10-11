# Cloud task: pos-module

> Work on branch `cloud/pos-module` (this file is its first commit). Check `git log` first and build on what is there; do not redo finished steps. Push after every step so progress is never lost. Before each pull request, merge the latest `origin/main` into the branch.

You are working on ABOS, a Laravel 13 / PHP 8.3 multi-company ERP (repo github.com/uboshq/abos). Modules live in `app/Modules/<Name>` and are declared by `app/Modules/<Name>/module.php` (read `app/Core/Module/ModuleDefinition.php`). The owner wants **POS taken out of Sales into its own module**, following his "POS & Retail Management" plan (summarised in section 6), in three phases. Open one pull request into `main` per phase. NEVER push to `main`, never force-push, never rewrite history. You have no server access; do not deploy anything. The coordinator (session "fe") merges and deploys.

**Language:** the owner reads only Bengali. Every PR description, every question to the owner, every user-visible string (`lang/bn/*`, with an `en` twin) and every code comment is in Bengali, like the code around it.

## 0. Rules that must not be broken

1. **Phases 1 and 2 change no behaviour and add no migration.** Route names (`sales.pos.*`, `sales.shift.*`), the permission key `sales.pos`, the screen switch `sales.screen_pos`, table names, setting keys and the invoice number series stay exactly as they are. The phone app and bookmarks depend on them.
2. One commit per change. Every change of behaviour comes with a claim (a feature test), and you prove the claim with a mutant: put the old source back, see the claim go red, restore.
3. Run before every PR, in your own cloud environment/CI (never on the owner's computer):
   - `tests/Feature/Architecture` (the whole folder)
   - `tests/Feature/Design`
   - `tests/Feature/Modules/Sales`, `tests/Feature/Api`
   - every test file with Pos, Shift, Counter, Parked or Till in its name or path (about 93 files)
   - `npx vitest run` (includes the CSP check for Alpine in Blade)
   - If CSS changes, rebuild the bundle (`npm run build`) and commit it; `AClassTheBundleNeverHeardOfDoesNothingTest` and `TheBuiltAssetsMatchTheSourceTest` check this.
   A red you did not cause: prove it is red on `main` too and say so in the PR.
4. Production is MariaDB with ONLY_FULL_GROUP_BY; index names ≤ 64 characters. Every new migration: guarded with `hasTable`/`hasColumn`, runs on a fresh DB, and `up → down → up` runs cleanly on MySQL (drop a foreign key before the unique index it relies on).
5. Every screen fits one 1920×1080 screen. Both menus (top and side) stay. Dates use the shared Bengali date component, not the browser's own date box; colours, heights and paddings come from tokens; icons come from the icon set, never typed emoji (the Design tests enforce this).
6. Company and branch walls everywhere: a user never sees another company, and a branch-limited user only their branches; the header's chosen branch filters everything.

## 1. The owner's decisions (they win over the plan where they differ)

- **No QR sale.** The owner dropped QR. Barcode stays.
- **No AI, no external models, no multi-currency.**
- **No customer advance account (no 2150).** Extra money a customer pays sits as a credit (Cr) in that customer's own ledger. So a "wallet" is the customer's own ledger credit, never a separate pot of money.
- **Every discount needs the owner's signature**, unless approvals are switched off in that company (UB has all approvals off). Never seed an active approval flow.
- **No automatic approval. The credit limit is absolute.** The walk-in/cash customer (limit 0) can never buy on credit.
- **Lots always.** One cart line stays inside one lot; no automatic splitting across lots — the seller picks the next lot.
- **No separate dashboard.** The plan's "Executive Dashboard" and "Live Store Monitor" figures go into the owner's centre, `app/Modules/Executive` (already live).
- **No separate cash management.** Tills, cash counts and handovers live in Accounts: `CashTill`, `CashCount`, `TillHandover`, `CashTillService`, `CashCountService`, `TillHandoverService`. POS calls them.
- **No separate inventory, approval, audit or report engine.** Use Inventory, `app/Core/Engines/Approval`, the central audit trail and `app/Core/Engines/Report`.
- **The depot's direct sale stays in Sales.** `sales.direct.*`, `DirectSaleController`, `CounterSaleBundle`, `CounterSaleSources`, the "depot check" — that is the B2B depot counter the business uses every day. It is NOT POS. Do not move it.
- **Restaurant POS is already its own module** (`app/Modules/Restaurant`). Do not touch it.

## 2. What exists today (read it; do not rebuild it)

| Part | Files |
|---|---|
| Counter screen | `app/Modules/Sales/Http/Controllers/PosController.php`, `Resources/views/pos/index.blade.php` |
| Shift | `ShiftController.php`, `Services/ShiftService.php`, `Models/CounterShift.php`, `views/shift/*` |
| Sale service | `Services/PosService.php` — park/resume, double-press idempotency, credit limit, and (since 11 Oct) a refused confirm deletes the draft this call created so it cannot block the next sale |
| Parked-bill stock | `Services/ParkedStockReservation.php` |
| Counter VAT and signature | `Services/CounterVat.php`, `Services/CounterApproval.php` |
| Thermal print | `views/print/invoice-thermal_pos_standard.blade.php` |
| Menu | `app/Modules/Sales/module.php`: the rows `sales::menu.pos` and `sales::menu.shift`, behind the switch `sales.screen_pos` |
| Phone counter | the API counter path; claim `ThePhoneCounterHoldsEveryWallOfTheWebCounterTest` |
| Offers and coupons | `app/Modules/Promotion` |

## 3. Phase 1 — a thin POS module (menu only) — PR 1

A "thin" module is only a `module.php` whose menu rows borrow another module's routes with `'from' => '<module>'` (see the comment around `$item['from']` in `ModuleDefinition.php`: route, key and switch must all start with the host module's name).

1. Create `app/Modules/Pos/module.php`:
   - `code` `pos`, name bn "পিওএস ও খুচরা বিক্রি" / en "POS & Retail".
   - `nav` placed right after Sales (read how `nav.section`/`nav.order` are used by the other modules and by the menu-order tests).
   - `depends_on` `['sales']`.
   - Two rows, POS and Shift, each with `'from' => 'sales'`, the same routes, the key `sales.pos` and the switch `sales.screen_pos`.
   - Its own colour token, its own icon in the icon set (`resources/views/components/ui/icon.blade.php`, both the glyph table and the line-icon table) and its token in `tokens.css` — the way Executive, Documents and Notification did it.
2. Remove those two rows from `app/Modules/Sales/module.php`.
3. Bengali and English names everywhere the tests ask: the module name, and if any new subject or verb reaches the role screen, `system_admin::permission.subjects`/`verbs` (`TheRoleScreenSaidCoaAndTillTest`).
4. Claims: with the switch on, POS and Shift appear in the POS fold and not in Sales; with the switch off they appear nowhere; the old URLs and the key still work; the existing menu-order claims are rewritten for the new order (do not weaken them).
5. PR description (Bengali): what moved in the menu, and "আচরণ বদলায়নি".

## 4. Phase 2 — move the code, behaviour identical — PR 2

1. Move into `App\Modules\Pos\…`: `PosController`, `ShiftController`, `PosService`, `ShiftService`, `ParkedStockReservation`, the `CounterShift` model, the `pos/*` and `shift/*` views, the thermal print view, and the POS part of the lang files. Register routes from the Pos module but keep the **same route names, URLs and middleware**.
2. Stays in Sales, and POS calls it: the invoice and its number (`SalesInvoiceService`, the S series), the credit check (`CreditExposure`), `CounterVat`, `CounterApproval`, posting to the books and stock-out. Where POS needs them, expose a contract in `app/Core/Contracts` (look at how existing contracts such as `CustomerTrade` or `ApprovalBundles` are bound in `module.php`) so POS never reaches into Sales internals. The Architecture tests check module boundaries.
3. Move the ~93 POS/shift/counter tests with the code; only namespaces and paths change. **Do not weaken any assertion.**
4. The PR description lists every file: from → to, and states "আচরণ বদলায়নি, সব পুরনো দাবি সবুজ".

## 5. Phase 3 — new retail parts (one PR each; ask the owner first)

Do them in this order. Before starting each one, write the questions below to the owner in Bengali (as a PR comment or in the session) and **wait for his answer**. Do not start a part without it.

1. **Customers, loyalty, membership.** Use Customer module customers (no second customer table). Points earn/redeem rules, Silver/Gold/Platinum tiers, birthday/anniversary/referral rewards. Questions: what is one point worth in taka; do points expire; which account does a points discount land in; does a points discount need the owner's signature like every other discount?
2. **Wallet and gift voucher.** Wallet = the customer's own ledger credit (no new account). Question: a gift voucher can be bought by one person and used by another — where does the money of a sold-but-unused voucher sit, given the owner's "no advance account" rule?
3. **Offers.** Reuse Promotion (product/category/brand/bill discount, buy X get Y, coupons). Add only what is missing: happy hour, weekend/festival windows, combo and mix-and-match. Every offer needs the owner's signature when approvals are on.
4. **Counter audit and security.** Price/discount override log, refund log, cash-drawer open log, device log — all into the central audit trail, not a POS-only log.
5. **Hardware.** Barcode scanner and receipt printer already work. New: cash drawer kick, customer display, weighing-scale barcodes. Fingerprint and NFC only if the owner asks.
6. **Offline sale.** Use the central Sync engine (`app/Core/Engines/Sync`). Questions: how does an offline counter number its bills; what happens when two offline counters sell the same lot?
7. **Reports.** New reports on the ReportEngine, each with its key and the company/branch walls (hourly, cashier, counter, payment method, discount/offer, loyalty, wallet, coupon, return, profit). Add the POS figures to the owner's centre (today's POS sales, average basket, active counters, loyalty summary) — no new dashboard.

## 6. The owner's plan, summarised (for reference)

Status in his plan: optional future channel module (Phase 3 of the depot/distributor DMS), needed only for a supermarket/chain-retail B2C counter channel; it must reuse the central engines and build only what is unique to retail (loyalty, wallet, coupon, counter/shift, POS hardware). Sections: executive dashboard; POS sales (new/quick/barcode/weighing-scale sale, hold/resume/park, reprint, void, history); counter operations (terminal setup, check-in/out, opening cash, shift start/end/handover, counter transfer, cashier assignment, emergency close, blind cash count, cash variance synced to Accounts, counter audit); customer & loyalty; offers & promotion; payments (cash/card/mobile banking/bank transfer/wallet/voucher/coupon, split/partial/advance/refund); cash management (counter level only; the ledger stays in Accounts); return & exchange with reason codes and approval; inventory integration (live view, batch/serial/expiry, reservation, price check — no new inventory engine); hardware; offline sync; reports; audit & security; settings and receipt templates. Flow: POS sale → stock reduced → journal posted → loyalty and wallet updated → owner's centre and analytics updated.

## 7. Done means (every PR)

- All tests in rule 3 green, or each red proven red on `main` too.
- Every new behaviour has a claim whose mutant was killed.
- Migrations (phase 3 only) meet rule 4 and leave the live rows untouched.
- Screenshots at 1920×1080 in the PR.
- The PR description in Bengali: what changed, what did not, which owner questions are still open.
- End with the word "শেষ" and the last commit hash.

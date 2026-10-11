# Cloud task: Sales and print fixes from the 9 Oct 2026 full-ERP re-audit

Repo `uboshq/abos` (Laravel 12, PHP 8.5, MariaDB). Work ONLY on branch `cloud/sales-print-fixes` (it already holds this file; build on it). Push after every fix so progress is never lost. NEVER push to `main`, never force-push, never rewrite history, never merge. No server access; do not deploy.

Other people work at the same time — stay out of their files:
- Vehicle fare (another session): `app/Modules/Sales/Services/FarePayment.php`, `ChallanFareController.php`, the fare parts of `DeliveryChallanService.php`, `DirectSaleApiController::translate()`, trips/loading sheets. Do not touch fare code.
- Stock cost (another session): `app/Modules/Inventory/Services/CostLayerService.php`, `StockTransferService.php`, `StockService.php`, `BatchAllocator.php`.
- Cloud branch `cloud/purchase-fixes`: `app/Modules/Purchase`, `app/Modules/Supplier`.

The live business is in a change freeze (until about 21 Oct 2026): fixes only, the smallest correct change, no new screens. Each fix gets its own commit and a claim test.

## Read first
- `app/Modules/Sales` (counter = DirectSale, challans, invoices, collections, deposit claims, coupons, quotations, print controllers and templates), `app/Core/Services/DataScope.php`, `app/Core/Support/ViewedBranch.php`, `app/Core/Concerns/ScopedToUserBranch.php`, `app/Core/Engines/Print`, `app/Core/Support/AmountInWords.php`, `app/Core/Support/Money.php`.
- Comments are Bengali and explain *why*; match that. Every user-facing string in lang files, BOTH `bn` and `en`, natural plain Bengali (the owner reads only Bengali). MariaDB `ONLY_FULL_GROUP_BY`. Money never float (strings + bcmath via `Money`).

## Fixes, in this order (most money risk first)

### Sales
1. **A signed counter sale or office challan does not finish when the maker's header shows another branch.** `HeldCounterSaleFinisher.php:152,276-278`, `DirectSaleService.php:1546`, `SignedChallanConfirmer.php:90-94` → `DeliveryChallanService.php:399` (only the order-lookup line, not fare code). The finisher runs as the maker, and the branch wall uses the signer's/maker's header branch, so a bill in another branch "does not exist" ("No query results for model SalesInvoice"); for office challans `$challan->order` comes back null and the order's reserved stock is never released. Fix: run the finish/confirm in the paper's own branch (e.g. `CompanyContext::forCompany(company, fn…)` with the paper's branch, or `acrossBranches()` lookups for the paper and its order) without widening what any person can see. Claims: maker viewing branch B, bill in branch A → finishes; office challan from an order → the order's reservation is released; `returnToDraft()` works the same way.
2. **A coupon's credit note lands in the header branch, not the bill's.** `SalesCouponPapers.php:53` → `NoteService.php:94`. Pass the bill's branch. Claim test.
3. **Accepting a deposit claim looks up the picked bills in the accepter's header branch.** `DepositClaimService.php:223` (`sharesAt()`); bills of another branch are silently skipped and the money goes on account. Fix the lookup to the claim's customer and company (respecting the bill's own branch). Claim test.
4. **Editing a draft collection skips the duplicate slip-number check.** `CollectionService.php:155` (`update()`); `create()` checks at `:89`. Same check on update. Claim test.
5. **A manual bill number is checked only inside the person's own branch.** `SalesInvoiceService.php:344-346` uses the walled query. Check across the company (as the purchase side does with `acrossBranches()`). Claim test.
6. **A rejected discount signature keeps the bill blocked even after the discount is lowered.** `SalesInvoiceService.php:191-195`. A new, lower discount must be able to ask for a new signature. Claim test.
7. **Converting a web quotation copies its line discounts into the order without the owner's discount rule.** `SalesQuotationService.php:411,501-523`. Discounts on the converted order must go through the same discount-signature rule as any order. Claim test.
8. **Dashboard margin counts freight charged on the bill as sales.** `Sales/Dashboard/SalesCharts.php:163` uses `SUM(total - tax)`; subtract `freight_charge` as `MonthlySalesReport.php:98` does. Claim test.
9. **Portal ledger default date** (if not already handled elsewhere): starts at 1970 (`PortalController.php:345`) — check `main` first; skip if fixed.

### Print
10. **Draft papers print like final papers.** The draft mark exists only on the collection receipt (`SalesPrintController.php:1378-1391`, `isDraftMoney`). Add the same "খসড়া" box and watermark to: draft challan (`:755`), gate pass (`:870`), order (`:954,996`), draft receipt voucher (`app/Modules/Accounts/.../VoucherPrintController.php:271-273`) and purchase prints (`PurchasePrintController.php:463`) — the last two outside Sales: touch only the draft-mark lines. Claim test per paper.
11. **Amount in words is one paisa short.** `AmountInWords.php:101` truncates with `bcadd($amount,'0',2)`; round with the project's `Money` rounding. Claim with 10.005-style amounts.
12. **"With money" challan borrows another bill's amounts.** `SalesPrintController.php:275-279` (unordered `->first()`, drafts included). Pick the challan's own posted bill deterministically. Claim test.
13. **No DUPLICATE mark on reprints** of gate pass (`:870`), receipt (`:1028`), challan stage prints (`:755,814`), order (`:954,996`) — pass `type`/`id` to `pdf()` the way the invoice does. Claim test.
14. **Inclusive VAT rows do not add up.** `totals()` `:1833-1846` adds a plain tax row; label it "VAT included" and keep the grand total right. Claim test.
15. **Sample-style templates drop paisa in words** (`:606` strips "taka"). Claim test.
16. **`?paper[]=` gives a 500.** `PaperSize::chosen()` fed a raw array (`:234,306,1922,1960`) → validate to a string, 422 otherwise. Claim test.
17. **`/draft` prints a posted bill as "not final"** (`:615-668`) → refuse for posted bills. Claim test.
18. **Branch letterhead and logo missing** on purchase, stock, transfer and payslip prints (no `during(` call in `PurchasePrintController`, `StockPrintController`, `MoneyTransferPrintController`, `PayslipPrintController`). Use the same branch-header helper the sales prints use. Claim test.
19. **English text left in Bengali bill designs**: `invoice-acct_card.blade.php:113,118`, `invoice-acct_classic.blade.php:128,181`, `invoice-a5_special_db.blade.php:197`, `lang/bn/settings.php:80` ('Special for DB'). Move to lang keys.

## Do NOT do (owner decisions or later)
- VAT base (before or after the bill discount), Bengali digits on paper, free goods at the gate when `sales.invoice_at_goods_issue` is on (switch is off on live) — list as owner questions in the PR.
- Anything about vehicle fare.

## Tests (must be real)
- Feature tests in the style of `tests/Feature/Modules/Sales` (`RefreshDatabase`, `DemoSeeder`, `owner@abos.test`). For every fix a test that fails on the old code and passes on the new — break the line, see red, restore. Branch fixes need a branch-limited actor and a second branch.
- Set up MariaDB/MySQL in the sandbox if needed (see `phpunit.xml`). Run `tests/Feature/Modules/Sales`, the print tests, `tests/Feature/Api` and the whole `tests/Feature/Architecture` directory; all green, or prove in the PR that a red was already red on `main`.
- `vendor/bin/pint` only on files you changed.

## Delivery
- Commit messages in English, plain sentences, ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- One PR into `main` titled "Sales and print fixes from the 9 Oct re-audit". Body: each fix with file:line and its test, anything skipped and why, owner questions. End with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`. Do not merge; the coordinator reviews and deploys.

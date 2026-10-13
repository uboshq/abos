# Cloud task: Purchase and Supplier fixes from the 9 Oct 2026 full-ERP re-audit

Repo `uboshq/abos` (Laravel 12, PHP 8.5, MariaDB). Work ONLY on branch `cloud/purchase-fixes` (it already holds this file; build on it). Push after every fix so progress is never lost. NEVER push to `main`, never force-push, never rewrite history, never merge. No server access; do not deploy.

Other people work at the same time — stay out of their files:
- Stock cost (another session): `app/Modules/Inventory/Services/CostLayerService.php`, `StockTransferService.php`, `StockService.php`, `BatchAllocator.php`, `OpeningStockService.php`. If a fix below needs a change there, do NOT make it — describe the exact change needed in the PR instead.
- Cloud branch `cloud/sales-print-fixes`: `app/Modules/Sales` and print controllers.

The live business is in a change freeze (until about 21 Oct 2026): fixes only, the smallest correct change, no new screens. Each fix gets its own commit and a claim test.

## Read first
- `app/Modules/Purchase` (bills, receipts/GRN, returns, payments, payment proposals, reports), `app/Modules/Supplier`, the posting engine `app/Core/Engines/Posting/PostingEngine.php`, `app/Core/Services/DataScope.php`, `app/Core/Support/ViewedBranch.php`, `app/Core/Concerns/ScopedToUserBranch.php`, `app/Core/Support/Money.php`.
- Note: commit 976d399b on `main` already made a bill count payments, vouchers and returns across the whole company (double payment from two branches is fixed). Build on it.
- Comments are Bengali and explain *why*; match that. Every user-facing string in lang files, BOTH `bn` and `en`, plain Bengali. MariaDB `ONLY_FULL_GROUP_BY`. Money never float.

## Fixes, in this order (most money risk first)
1. **A posted bill can be edited below what is already paid.** `PurchaseBillService.php:1113-1160` (`updatePosted`): no row lock, no "new total ≥ paid (+ returned)" check, no approval re-check. Lock, refuse with a Bengali reason, re-run the approval rule when the amount rises. Claim tests.
2. **Old unpaid bills cannot be picked when paying a supplier.** `PaymentController` loads `->limit(200)->get()->filter(...)`; a `stillOwed()` style query that returns every bill still owed (paged/searchable) is needed. Check `main` first — a fix may already have been committed; skip if so. Claim test.
3. **The ledger posts in one branch while the goods land in another.** `PurchaseBillService.php:204` (`$data['branch_id'] ?? CompanyContext::branchId()`) and `PurchaseReturnService`: the paper's branch must follow its warehouse's branch (or be validated to match it). Claim test.
4. **Goods-received-not-billed report counts draft bills.** `PurchaseReports.php` — only posted bills reduce GRNI. Claim test with a draft bill.
5. **Price history and registers add up rates and include draft bills.** `PurchaseRegisterReports`, `PurchaseReports` (ⓘ16, ⓘ17) — rates are not summed; drafts excluded. Claim tests.
6. **Cancelling a goods receipt checks without a lock** (`PurchaseReceiptService.php`). Lock the row and re-check. Claim test.
7. **VAT-inclusive rates break receipts and returns.** `CalculatesLineTotals`, `PurchaseReturnService` — a receipt/return at a VAT-inclusive rate must split net and VAT the same way the bill does. Claim test.
8. **Freight on the goods-receipt path does not reach stock cost.** If the fix needs `CostLayerService` changes, stop at the Purchase side and describe the needed Inventory change in the PR.
9. **Supplier side:**
   - Payables ageing has no FIFO (payments not applied to oldest bills first) and advances show as negative dues netted into totals (`Supplier/Reports/PartyReports.php`). Apply FIFO, show advances separately.
   - Branch-limited staff can open/change suppliers of any branch (`SupplierPolicy`, `SupplierRequest`). Enforce the person's branch reach.
   - The payment schedule does not agree with the payables ledger (`Supplier/Reports/PartyReports.php:170-181`).
   Claim tests for each.

## Do NOT do (owner decisions or later)
- Supplier's different rate changing stock cost (needs `CostLayerService`; describe it in the PR as a follow-up for the stock session).
- Separating payables from GRNI in the supplier ledger (design decision — owner question).

## Tests (must be real)
- Feature tests in the style of `tests/Feature/Modules/Purchase` and `.../Supplier` (`RefreshDatabase`, `DemoSeeder`, `owner@abos.test`). For every fix a test that fails on the old code and passes on the new — break the line, see red, restore. Branch fixes need a branch-limited actor and a second branch.
- Set up MariaDB/MySQL in the sandbox if needed (see `phpunit.xml`). Run `tests/Feature/Modules/Purchase`, `.../Supplier`, `.../Inventory` and the whole `tests/Feature/Architecture` directory; all green, or prove in the PR that a red was already red on `main`.
- `vendor/bin/pint` only on files you changed.

## Delivery
- Commit messages in English, plain sentences, ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- One PR into `main` titled "Purchase and supplier fixes from the 9 Oct re-audit". Body: each fix with file:line and its test, anything skipped and why, owner questions. End with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`. Do not merge; the coordinator reviews and deploys.

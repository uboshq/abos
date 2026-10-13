# Cloud task: HR, Customer, Promotion and MasterData fixes from the 9 Oct 2026 full-ERP re-audit

Repo `uboshq/abos` (Laravel 12, PHP 8.5, MariaDB). Work ONLY on branch `cloud/people-fixes` (it already holds this file; build on it). Push after every fix so progress is never lost. NEVER push to `main`, never force-push, never rewrite history, never merge. No server access; do not deploy. Other cloud sessions work on `cloud/accounts-fixes`, `cloud/finance-fixes` and `cloud/security-fixes` at the same time: stay inside `app/Modules/Hr`, `app/Modules/Customer`, `app/Modules/Promotion`, `app/Modules/MasterData` (and their tests) unless a fix below names another file.

The live business is in a change freeze (until about 21 Oct 2026): fixes only, the smallest correct change, no new screens or features. Each fix gets its own commit and a claim test.

## Read first
- The modules above, `app/Core/Services/DataScope.php`, `app/Core/Support/ViewedBranch.php`, `app/Core/Concerns/ScopedToUserBranch.php`, the posting engine `app/Core/Engines/Posting/PostingEngine.php`, `app/Core/Support/Money.php`.
- Comments are Bengali and explain *why*; match that. Every user-facing string in lang files, BOTH `bn` and `en`, natural plain Bengali (the owner reads only Bengali). MariaDB `ONLY_FULL_GROUP_BY`. Money never float (strings + bcmath via `Money`).
- Do NOT touch the setting `hr.shared_across_companies` in `app/Modules/Hr/module.php` — its fix already exists elsewhere.

## Fixes, in this order (most money risk first)

### HR
1. **Advance deducted again from salary.** `PayrollService.php:383-386` caps the deduction at the employee's open advance as of month end and at draft time; `:206-218` confirm never re-checks; `AdvanceBalance.php:39`. If the advance is returned in cash or settled by an expense claim after month end (or between draft and confirm), salary still deducts it and 1131 goes negative. Fix: measure the open advance at confirm time, under a lock, and cap at it. Claim test for both cases.
2. **Two claims of the same employee approved at once both take the same advance.** `ExpenseClaimService.php:197-229` locks only the claim row. Lock the employee (or the advance balance) while settling. Claim test.
3. **A departed or deleted employee can still raise claims/advances.** `ExpenseClaimService.php:65-69` uses `withoutGlobalScopes()` (drops SoftDeletes) and never checks `is_active`/`leaving_date`. Refuse with a Bengali reason. Claim test.
4. **"1e5" in a claim amount breaks the page (500).** `ExpenseClaimController.php:103,154` uses `numeric`; use the project's decimal-money validation rule used elsewhere. Claim test (web and phone door).
5. **The payroll run list shows a branch-limited manager the whole company's totals.** `PayrollController.php:50-63`, `payroll/index.blade.php:10-15`. Narrow to the person's reach. Claim test.
6. **A whole-company payroll posts in the clerk's branch.** `PayrollService.php:217` (`branchId: $run->branch_id`). Post each employee's lines in the employee's branch (or the company's head branch if the run is company-wide) — follow how other company-wide postings choose the branch. Claim test.
7. **Attendance from the phone accepts any date and any status.** `AttendanceSync.php:157-180`. Allow only today (and a small configured back-window if one exists), never the future; status "leave" only from an approved leave; times and lateness computed on the server. Claim test.
8. **Leave: half-day, bulk approval, approving your own leave.** `LeaveService.php:66-82,160-178`: lock, re-check the balance, refuse self-approval, record half days correctly. Claim tests.
9. **Salary heads can point at any account.** `SalaryHeadController.php:169-170` checks only company `exists`. Allow only postable expense/liability accounts of the right type. Claim test.
10. Small: `EmployeeController.php:347` `exists:users,id` must be limited to the company; managers list must respect the branch wall; `format('F Y')` English month names in `PayslipPrintController.php:111`, `payroll/show.blade.php:12`, `index.blade.php:7` → Bengali; `LeaveService.php:111-114` must not delete attendance of a month already paid.

### Customer
11. **Branch-limited staff can create/edit customers of any branch.** `CustomerPolicy.php:26-38` checks only the key; `CustomerRequest.php:107-110` checks only the company. Enforce the person's branch reach on create, update and view. Claim test.
12. **Receivable ageing does not apply collections to the oldest dues (FIFO), and advances show as negative dues netted into totals.** `Customer/Reports/PartyReports.php:222-305`. Apply payments FIFO; show advances separately. Claim test with a known small set.
13. **Customer summary page ignores the viewed branch.** `CustomerSummaryController.php:55`. Claim test.
14. **"Dues above" widget mixes two walls and loads every customer into memory.** `CustomerWidgets.php:88-89`. One query, the right wall.
15. **Inactive or deleted customers can still use the dealer portal.** `PortalController.php:136-140`, `EnsurePortalStillOpen.php:43`. Refuse them; also give the portal ledger a sensible default date range instead of 1970 (`:345`). Claim test.
16. **Lifting a credit block needs only `customer.update`.** `CreditBlockController.php:27`. Require the credit-limit permission the rest of the limit flow uses. Claim test.
17. Small: `zero_limit_blocks` switch does nothing (`module.php:394`, `Customer.php:426-427`) — make it work as its label says or remove it from the control panel if the owner's rule makes it meaningless (explain in the PR); "1e5" in list filters and opening balance (`CustomerListFilters.php:186-188`, `CustomerRequest`); the limit importer's number parsing (`CustomerLimitImporter.php:94`); the importer silently drops `payment_term_id` (`CustomerImporter.php:152`) — say so to the user instead of dropping.

### Promotion
18. **A money coupon cut on an order never reaches the ledger even after billing.** `SalesCouponPapers.php:39-45` handles only sales invoices. Carry it to the bill's credit note. Claim test.
19. **Commission claims can be made on the wrong customer's bill or twice on one bill.** `CommissionClaimService.php:57-59`. Match the customer, one claim per bill line. Claim test.
20. **Override does not reach the bill and does not check the draft state.** `PromotionDesk.php:192-248`. Claim test.
21. **No branch wall in promotion applications and gifts; commission claims ignore the header branch.** Add the wall the same way other modules do (`CommissionClaim.php:39`, GiftController). Claim test.
22. Salesperson commission never posted to the ledger (CommissionEngine) — implement only if it is a fix of an existing promised posting; if it needs new accounts or an owner rule, list it as an owner question instead.

### MasterData
23. **Area responsibility can be given to a user of another company.** `LocationController.php:387` (`exists:users,id`). Limit to the company. Claim test.
24. **Units, taxes and payment methods in use can have their money-relevant fields changed.** `MasterListService.php` freezes only deletion (`:307`). Freeze the fields that change past papers once the record is used. Claim test.

## Do NOT do (owner decisions or later)
- Third-party staff in a separate HR, back-dated payroll rule (owner rules not yet specified in code — list as questions).
- Free goods booked as promotion cost vs cost of sales; loyalty points as a liability (accounting policy — owner questions).

## Tests (must be real)
- Feature tests in the style of each module's `tests/Feature/Modules/<Module>` (`RefreshDatabase`, `DemoSeeder`, `owner@abos.test`). For every fix a test that fails on the old code and passes on the new — break the line, see red, restore.
- Set up MariaDB/MySQL in the sandbox if needed (see `phpunit.xml`). Run the four modules' tests and the whole `tests/Feature/Architecture` directory; all green, or prove in the PR that a red was already red on `main`.
- `vendor/bin/pint` only on files you changed.

## Delivery
- Commit messages in English, plain sentences, ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- One PR into `main` titled "HR, customer, promotion and master data fixes from the 9 Oct re-audit". Body: each fix with file:line and its test, anything skipped and why, owner questions. End with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`. Do not merge; the coordinator reviews and deploys.

# Cloud task: Accounts fixes from the 9 Oct 2026 full-ERP re-audit

Repo `uboshq/abos` (Laravel 12, PHP 8.5, MariaDB). Work ONLY on branch `cloud/accounts-fixes` (it already holds this file; build on it). Push after every fix so progress is never lost. NEVER push to `main`, never force-push, never rewrite history, never merge. No server access; do not deploy. Another cloud session is fixing the Finance module on branch `cloud/finance-fixes` at the same time: stay out of `app/Modules/Finance` unless a fix below names it.

The live business is in a change freeze (until about 21 Oct 2026): fixes only, the smallest correct change, no new screens or features. Each fix gets its own commit and a claim test.

## Read first
- `app/Modules/Accounts`, `app/Core/Engines/Posting/PostingEngine.php`, `app/Core/Security/LedgerChain.php`, `app/Core/Support/Money.php`, `app/Core/Services/DataScope.php`, `app/Core/Support/ViewedBranch.php`, `app/Core/Concerns/ScopedToUserBranch.php`.
- Comments in this codebase are Bengali and explain *why*; match that style. Every user-facing string goes through lang files in BOTH `bn` and `en` (natural, plain Bengali — the owner reads only Bengali).
- MariaDB with `ONLY_FULL_GROUP_BY`: every GROUP BY must be valid under it. Money is never float: strings and bcmath via `Money`.

## Fixes, in this order (most money risk first)
1. **Cancelling an adjusting journal leaves its automatic reversal posted.** `app/Modules/Accounts/Services/AdjustingReversals.php:41-104` posts the reversal with `reversal_of_id`; `VoucherService::cancel()` (`:874-947`) never looks at it. Fix: cancelling the original must also reverse the auto-reversal if it was posted (or refuse with a clear Bengali reason if the reversal's month is locked). Claim: adjusting JV → hourly job reverses it → cancel original → net effect on both accounts is zero.
2. **An auto-reversal that fails because its month is locked fails silently every hour.** `AdjustingReversals.php:59,83`. Fix: tell the accountant/owner once through the central notification service (no repeat every hour) and keep it visible as stuck. Claim test.
3. **The year-end closing paper (page and PDF) ignores the branch wall.** `YearEndService.php:131-137`, used by `YearEndController.php:147,160`. A person limited to branch A sees every branch's closing lines. Fix with the existing view rules (`DataScope::inView` / `ViewedBranch::narrow`). Claim: branch-limited reader sees only their branch.
4. **Group report shows whole-company totals to a branch-limited person.** `GroupLedgerService.php:151-200` (`sumsByCompanyAndType`). Apply each company's branch reach. Claim test.
5. **Party ledger vs control account.** New party lines are already refused on accounts that do not hold that party (`PostingEngine.php:396-407`), but there is no check that each party's balance adds up to its control account. Add a read-only reconciliation check (a report row or a month-end checklist item using the existing `MonthEndChecklist`) that lists the difference per control account. No data is changed.
6. **Period lock order.** A later month can be locked while an earlier one is open (`PeriodLockController.php`). Refuse locking a month while an earlier month of the same financial year is open, with a Bengali reason. (Bank reconciliation/till count as a lock precondition is a policy change — do NOT add it; list it in the PR as an owner question.)
7. **Profit and loss and cash flow presentation (IAS 1 / IAS 7).** The engine's P&L has no cost of sales / gross profit split, and the cash flow (`CoreReports.php:640-672`) counts cash-to-bank moves as money in and out. Fix: show cost of sales and gross profit using the existing account types; exclude transfers between the company's own money accounts from cash flow and group it into operating / investing / financing using account types. Claim tests on a small seeded set.
8. **The two balance sheets still differ** (advances not shown separately in the engine version). Make the engine version show customer/supplier advances on their own lines so both agree. Claim: both versions give the same totals on the same data.
9. Small: `expense-bill-tag.blade.php:156` uses `number_format` (must use the project's money formatter with lakh commas); check the custody list per-row queries and the posted-list grouping named in the old audit and fix if still slow.

## Do NOT do (owner decisions or later)
- Opening balances default account (3100 owner capital vs an "opening balance equity" account) — owner decision.
- Hash-chain version change and the engine's amount conversion — another session already has these done locally (ⓘ18, ⓘ19); leave `LedgerChain.php` and the amount conversion in `PostingEngine.php` alone.
- Till handover "received" step — a new flow, after the freeze.

## Tests (must be real)
- Feature tests in the style of `tests/Feature/Modules/Accounts` (`RefreshDatabase`, `DemoSeeder`, `owner@abos.test`). For every fix: a test that fails on the old code and passes on the new — break the line, see red, restore.
- Set up MariaDB/MySQL in the sandbox if needed (see `phpunit.xml`). Run `tests/Feature/Modules/Accounts`, `tests/Feature/Modules/Finance` and the whole `tests/Feature/Architecture` directory; all green, or prove in the PR that a red was already red on `main`.
- `vendor/bin/pint` only on files you changed.

## Delivery
- Commit messages in English, plain sentences, ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- One PR into `main` titled "Accounts fixes from the 9 Oct re-audit". Body: each fix with file:line and its test, anything skipped and why, owner questions. End with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`. Do not merge; the coordinator reviews and deploys.

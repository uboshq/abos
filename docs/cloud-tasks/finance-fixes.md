# Cloud task: Finance module fixes from the 9 Oct 2026 full-ERP re-audit

Repo `uboshq/abos` (Laravel 12, PHP 8.5, MariaDB). Work ONLY on branch `cloud/finance-fixes` (it already holds this file; build on it). Push after every fix so progress is never lost. NEVER push to `main`, never force-push, never rewrite history, never merge. No server access; do not deploy. Another cloud session is fixing Accounts on branch `cloud/accounts-fixes` at the same time: stay in `app/Modules/Finance` (and its tests) unless a fix below names another file.

The live business is in a change freeze (until about 21 Oct 2026): fixes only, the smallest correct change, no new screens. Each fix gets its own commit and a claim test.

## Read first
- `app/Modules/Finance` (rent contracts and accruals, hand loans, insurance, capital, profit distribution, interest accrual, bank charges, account analysis), how it posts through `app/Core/Engines/Posting/PostingEngine.php`, and the branch rules in `app/Core/Services/DataScope.php`, `app/Core/Support/ViewedBranch.php`, `app/Core/Concerns/ScopedToUserBranch.php`.
- Comments are Bengali and explain *why*; match that. Every user-facing string in lang files, BOTH `bn` and `en`, plain Bengali. MariaDB `ONLY_FULL_GROUP_BY`. Money never float (strings + bcmath via `Money`).

## Fixes, in this order (most money risk first)
1. **Prepaid rent stays an asset forever when a contract closes early.** Rent paid ahead goes to 1137 Prepaid Rent; `RentalContractService::close()` (`:514-597`) does not deal with months paid ahead, and `RentalAccrualService::run()` only walks active contracts (`:74-77`). Fix: on close, release or refund the unused prepaid months correctly (owner's rule: never silently lose money) — refuse to close with a clear Bengali reason while prepaid months remain, and say what to do. Claim test.
2. **A closed contract's accrued but unpaid months cannot be paid.** `adjustMonth` calls `assertActive` (`:192,247`) and `close()` does not check unpaid accruals. Fix: paying an accrued month stays possible after close; closing with unpaid accruals is refused or warned clearly. Claim test.
3. **Finance papers post in the user's current header branch instead of the paper's own branch.** Still wrong in: hand loan moves (`HandLoanService.php:188`), insurance claim receipt and journal (`InsuranceClaimService.php:139,303`), capital (`CapitalService.php:63,315`), profit distribution (`ProfitDistribution.php:223,641`). Rent was already fixed in commit 09f2e352 — follow the same pattern (the record's own branch). Claim: post from a header showing another branch; the ledger rows carry the paper's branch.
4. **Interest and insurance month-end use the header's branch for the preview but reverse across all branches.** `InterestAccrualService.php:63`, `InsurancePrepaymentService.php:70`. Make preview and run cover the same set (the whole company the person may act on), consistently. Claim test.
5. **The next run's reversal of last month's accrual gets stuck when that month is locked.** `InterestAccrualService.php:169-200`. Date and handle the reversal so a locked month does not block it, or refuse with a clear reason before posting anything. Claim test.
6. **Finance records open by id across branches.** The models only use the local scope `ListedInViewedBranch`, so route-model binding is not walled; any id opens another branch's rent/hand loan/insurance. Add the proper branch wall (the same trait other modules use) or authorize in the controllers. Claim: a branch-limited user gets 404/403 on another branch's record.
7. **Bank charges and account analysis ignore the branch reach.** `BankCharges.php:132-138`; `AccountAnalysis.php:49-53` (`ViewedBranch::one()` only). Use the existing view rules. Claim tests.
8. **Insurance claims post without any signature.** `InsuranceClaimService.php:156,312`. Route them through the existing `ApprovalEngine` the way other Finance papers do (the flow is configured by the owner; if the company has all flows off, behave like the rest of the app — see how `ClaimSigner` respects "approvals off"). Claim test.
9. **One bad contract stops `RentalAccrualService::run()` for the rest of the company.** Catch per contract, report it, continue. Claim test.
10. Small: `month-link.blade.php:6` uses `format('M Y')` (English month) — use the project's Bengali date formatting.

## Do NOT do (owner decisions or later)
- Withholding tax on rent (needs the owner's rates and accounts).
- Investor fixed return as an expense with its own liability (a new feature; owner decided the rule on 6 Oct but it is scheduled after the freeze).
- Splitting hand loans given vs taken into two accounts (chart change — owner question).
List these in the PR as open questions.

## Tests (must be real)
- Feature tests in the style of `tests/Feature/Modules/Finance` (`RefreshDatabase`, `DemoSeeder`, `owner@abos.test`). For every fix, a test that fails on the old code and passes on the new — break the line, see red, restore.
- Set up MariaDB/MySQL in the sandbox if needed (see `phpunit.xml`). Run `tests/Feature/Modules/Finance`, `tests/Feature/Modules/Accounts` and the whole `tests/Feature/Architecture` directory; all green, or prove in the PR that a red was already red on `main`.
- `vendor/bin/pint` only on files you changed.

## Delivery
- Commit messages in English, plain sentences, ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- One PR into `main` titled "Finance fixes from the 9 Oct re-audit". Body: each fix with file:line and its test, anything skipped and why, owner questions. End with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`. Do not merge; the coordinator reviews and deploys.

# Cloud task: security, reports and dashboard fixes from the 9 Oct 2026 full-ERP re-audit

Repo `uboshq/abos` (Laravel 12, PHP 8.5, MariaDB). Work ONLY on branch `cloud/security-fixes` (it already holds this file; build on it). Push after every fix so progress is never lost. NEVER push to `main`, never force-push, never rewrite history, never merge. No server access; do not deploy. Other cloud sessions work on `cloud/accounts-fixes`, `cloud/finance-fixes` and `cloud/people-fixes` at the same time: stay in `app/Modules/SystemAdmin`, `app/Modules/Governance`, `app/Core/Engines/Report`, `app/Core/Engines/Dashboard`, the module `Dashboard` folders, `bootstrap/app.php`, auth, and their tests, unless a fix below names another file. Do NOT touch `app/Modules/Approval/Services/ApprovalFacts.php` or `ApprovalInboxController.php` (just fixed elsewhere).

The live business is in a change freeze (until about 21 Oct 2026): fixes only, the smallest correct change, no new screens. Each fix gets its own commit and a claim test.

## Read first
- `app/Core/Services/DataScope.php`, `app/Core/Support/ViewedBranch.php`, `app/Core/Concerns/ScopedToUserBranch.php`, `app/Core/Services/SettingsService.php`, the 2-step code (`SuperAdminMustHaveTwoSteps`, `CredentialCheck`, `AuthController`), `app/Core/Engines/Report/ReportEngine.php`, `app/Core/Engines/Dashboard`.
- Comments are Bengali and explain *why*; match that. Every user-facing string in lang files, BOTH `bn` and `en`, natural plain Bengali (the owner reads only Bengali). MariaDB `ONLY_FULL_GROUP_BY`. Money never float.

## Fixes, in this order (most risk first)
1. **A super admin's mandatory 2-step can be skipped on the phone.** `SuperAdminMustHaveTwoSteps` is only in the web group (`bootstrap/app.php:263`); the API login (`AuthController`, `CredentialCheck.php:130`) checks only whether 2-step is on, not whether it is required. Make the phone login refuse (Bengali message: set up 2-step on the web first) for anyone for whom 2-step is required but not set up, and require the code when it is set up. Claim tests.
2. **Role permission changes have no limits and leave no audit trace.** `RoleController.php` store/update `syncPermissions` freely; `permissions.*` only `exists`. A person may grant only permissions they hold themselves (owner/super admin excepted), never owner-only keys; every change writes an audit row (who, role, added, removed) through the project's audit service. Claim tests.
3. **Dashboards show branch-limited people company-wide figures.** `AccountsFacts::sumOf/bankBalance` (`:45-50,87-100`), `assetValue` (`:262`), `topDue` (`:389`) used by `AccountsDashboard.php:109,127,173,263` and `AccountsDashboardController.php:63`; `StockFacts::value()` (`:168-181`) under "all branches". Use the same reach rules the fixed parts already use (`balanceInView()`). Claim test with a branch-limited user.
4. **The approval dashboard shows everyone every pending request in the company.** `ApprovalDashboard.php:42-83` needs only `approval.view`. Show a person their own queue and requests; company-wide only with the reporting permission, and then within their branch reach. Claim test.
5. **HR's pending leave counts ignore the branch wall.** `HrDashboard.php:84,111`, `HrWidgets.php:45` (`LeaveApplication` has no branch scope). Claim test.
6. **A bad date in the report centre gives a 500; search breaks the running balance.** `ReportEngine.php:630` (`Carbon::parse` unvalidated) → 422 with a Bengali message; `:966` the opening running balance must apply the same search filter. Claim tests.
7. **Totals add up rates and averages; exports have no totals row.** Only money/quantity columns are summed; rates and averages are left blank or recomputed; exports carry the same totals row as the screen. Claim test.
8. **A scheduled "PDF" report is actually a CSV, and filters are not saved.** `ScheduledReportRunner.php:237-241` (no PDF branch — use the existing `PrintEngine`/report PDF path), `ReportScheduleController::validated()` (no `filters`). Claim tests.
9. **"10,000" typed in a number setting breaks approvals.** `ControlPanelController` stores `number` settings unvalidated; `ApprovalEngine.php:1176` `bccomp` then fails. Validate and normalise numbers (strip lakh commas, Bengali digits) on save. Claim test.
10. **A colleague's login can be blocked with their mobile number.** `ProfileController.php:112`, `UserController.php:979` — mobile must be a valid, unique-per-login phone in the format the login accepts. Claim test.
11. Small: role lookup by name without the company filter (`UserController.php:922`); `ReportFilters::resolve()` silently drops arrays (`:65`) and inventory casts an array warehouse to 1 — return 422; dashboard raw queries that do not filter `deleted_at`; dashboard ⚠️ items still open in the old audit (collections chart vs headline base, accounts "today" figures meaning, one margin behind two keys, three sources of stock value) — fix where it is a clear bug, list as a question where it is a definition the owner must choose.

## Do NOT do
- Anything in Approval facts/inbox (done), HR's `shared_across_companies` switch (done elsewhere), backup encryption or new security features (after the freeze — list as questions).

## Tests (must be real)
- Feature tests in the style of the existing ones (`RefreshDatabase`, `DemoSeeder`, `owner@abos.test`). Security claims need the dangerous actor: a person WITHOUT the right, a branch-limited person, a second company. Break the line, see red, restore.
- Set up MariaDB/MySQL in the sandbox if needed (see `phpunit.xml`). Run the touched modules' tests, `tests/Feature/Api` and the whole `tests/Feature/Architecture` directory; all green, or prove in the PR that a red was already red on `main`.
- `vendor/bin/pint` only on files you changed.

## Delivery
- Commit messages in English, plain sentences, ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- One PR into `main` titled "Security, report and dashboard fixes from the 9 Oct re-audit". Body: each fix with file:line and its test, anything skipped and why, owner questions. End with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`. Do not merge; the coordinator reviews and deploys.

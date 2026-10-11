# Cloud task: documents

> Work on this already started: continue on the existing branch `cloud/documents-phase1` (check `git log` first and build on what is there; do not redo finished steps). Push after every step so progress is never lost.

You are working on ABOS, a Laravel 12 / PHP ERP (repo github.com/uboshq/abos, branch main). Your job: build PHASE 1 of the Documents (document management) module, on a NEW branch `cloud/documents-phase1`. Push that branch and open a pull request into main. NEVER push to main, never force-push, never rewrite history. You have no access to any server; do not try to deploy anything. Another cloud session is building the owner's command center on branch `cloud/command-center` at the same time — do not touch its files.

## What exists today
- `app/Modules/Documents/` is a placeholder: `module.php` (21 menu rows that all open `documents.screen` plan pages), `Http/Controllers/PlanController.php`, `Support/DocumentPlan.php` (25 sections), `Dashboard/DocumentsDashboard.php`, lang `bn`/`en` (`plan.php`, `menu.php`, `screen.php`, ...).
- The owner's full plan: `docs/ডকুমেন্ট-ম্যানেজমেন্ট-পরিকল্পনা.md` (25 sections). READ IT FIRST, completely. Also read `app/Modules/Documents/Resources/lang/bn/plan.php`.

## Phase 1 scope (only these plan sections)
- s1 storage core: documents table + versions table + folders/categories, metadata (name, type, category, department, owner, document date, expiry date, confidentiality, tags, description), archive (soft) state.
- s4 Document Center: list with search, filter, sort; folders: company papers, contracts, HR, finance, sales, purchase, inventory, legal, compliance.
- s5 Document detail: preview (PDF/image inline; others download), details, versions tab, audit tab.
- s6 Upload: single and multiple file upload (no scanner/camera yet), the metadata fields above, validation (type allow-list, size limit), files stored on the private disk, never in public/.
- s9 Version control: v1.0 → v1.1 → v2.0; upload new version, list, download old, restore-as-new-version, comment, author, date. An approved document is never edited in place; a change makes a new version.
- s12 (only the data part): expiry date column and a "expiring in 7/30/90 days" filter on the center. No reminders/notifications yet.
- s13 security: permissions per action (view, upload, edit, delete, download, print, share, archive, restore) registered the way other modules register permissions in their module.php; company wall (tenant) and branch wall exactly like the rest of the codebase (look at `App\Core\Concerns\ScopedToUserBranch`, `App\Core\Support\CompanyContext`, `App\Core\Support\ViewedBranch`); confidentiality levels enforced on every read path (list, detail, download, preview).
- Audit: every view/download/upload/new version/archive/restore writes an audit row using the project's existing audit/paper-trail service (find it, e.g. `App\Core\Services\PaperTrail`); do not invent a new audit table if one exists.
- Replace the plan-page menu rows for center, upload, mine, recent and the document detail with the real screens. Every other menu row keeps opening its plan page.
OUT of scope now (leave their plan pages): OCR/scan (s7), ABE intelligence (s8), approval workflow (s10), digital signature (s11), sharing (s14+), reminders, retention, any external AI or external service (the owner forbids sending any document to an outside server).

## House rules (strict)
- Copy the conventions of neighbouring modules (look at Customer, Supplier, Hr): migrations inside the module's Database/Migrations folder with the same date-name style, models with `public_id` (UUID v7 like other models), routes in the module's Routes, controllers thin, services do the work, Blade views with the same layout components and design tokens (`resources/css/tokens.css`), light AND dark mode, every screen must fit 1920x1080 without horizontal scroll.
- ALL user-facing text through lang files in BOTH `bn` and `en`; the Bengali must be natural, plain Bengali (the owner reads only Bengali). No hard-coded strings in Blade.
- MySQL/MariaDB with ONLY_FULL_GROUP_BY: every GROUP BY must be valid under it. Index names must be ≤ 64 characters.
- Comments: the codebase writes explanatory comments in Bengali; match that density and style.
- No new composer/npm dependency unless truly unavoidable; if you add one, explain why in the PR.
- If the module has an on/off switch pattern, the new screens must respect it.

## Tests
- Write feature tests under `tests/Feature/Modules/Documents/` (look at how other module tests are written: `RefreshDatabase`, `DemoSeeder`, users like `owner@abos.test`). Cover at least: upload creates v1.0 and an audit row; a new version bumps the number and keeps the old file downloadable; a user from another company and a user limited to another branch get 404/403 on list, detail, download, preview; a confidential document is hidden from someone without the right; archive hides from the center and restore brings it back; the file is not reachable under public/.
- For each test, check it can fail: temporarily break the guarded line, see the test go red, restore it.
- Set up MySQL or MariaDB in the sandbox if needed to run the suite (check phpunit.xml / .env.testing for what the tests expect). Run your Documents tests AND the whole `tests/Feature/Architecture` directory (house guards: module boundaries, money, lang keys, etc.). All must be green; if an Architecture guard was already red on main before your change, say so in the PR with proof.
- Run `vendor/bin/pint` only on the files you changed (never on a whole directory).

## Delivery
- Small, clear commits on `cloud/documents-phase1`. Commit messages in English, plain sentences, ending with:
  Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
- Open a PR into main titled "Documents module, phase 1: center, upload, versions, security". The PR body must list: what was built per plan section, every migration, every permission added, test results (counts), anything left undone and why. End the PR body with: 🤖 Generated with [Claude Code](https://claude.com/claude-code)
- Do not merge it. A coordinator will review, merge and deploy later (the live business is in a change freeze until about 21 October 2026).
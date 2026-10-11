# Cloud task: Notification Management module (owner's spec, 10 Oct 2026)

Repo `uboshq/abos`. Work ONLY on branch `cloud/notification-management` (it already holds this file and the owner's spec next to it: `docs/cloud-tasks/notification-management-spec-bn.md`). Push after every step so progress is never lost. NEVER push to `main`, never force-push, never rewrite history, never merge. No server access; do not deploy. Open one pull request per phase into `main` and continue the next phase on the same branch.

## Read this first — the owner's spec assumes the wrong stack
The owner's spec (Bengali, in `notification-management-spec-bn.md`) is the requirement. Its §16 "Technology Stack" says Python/FastAPI, PostgreSQL, React, Redis, Celery, WebSockets — **ABOS is not that**. ABOS is **Laravel 12 / PHP 8.5, MariaDB (ONLY_FULL_GROUP_BY), Blade + Alpine.js (CSP build)**, and live runs on **shared cPanel hosting**: no root, no Redis, no long-running daemons, no WebSocket server. Background work runs through Laravel's database queue and scheduler, started by cron. Build every requirement of the spec on THIS stack; where the spec's mechanism cannot exist here, use the closest safe equivalent and say so in the PR:
- Queue/workers → Laravel database queue + `schedule:run` by cron (how the project already runs jobs; find it).
- Transactional outbox → write the notification event row inside the business transaction; dispatch `afterCommit`.
- Real-time bell → the existing approach plus a polling fallback; the owner has **parked live polling until after 21 Oct 2026**, so build polling behind a control-panel switch that is OFF by default.
- Web Push → standard Web Push with VAPID keys (a PHP library such as `minishlink/web-push` is acceptable; explain the dependency).
- SMS → a pluggable gateway adapter interface with NO provider wired; the channel shows "not connected" until the owner gives a provider and credentials (spec §7: never show a channel as working without an approved provider).
- Email → Laravel mail (SMTP from `.env`); never put credentials in the database in plain text — encrypt with Laravel's encrypter.
- Mobile push already exists (FCM device tokens, `PushTokenController`) — reuse it as a channel, do not rebuild.

## Do not rebuild what exists — extend it
Read these before designing anything:
- `app/Core/Services/NotificationService.php`, `app/Core/Support/NotificationKinds.php`, `app/Models/Notification.php`, `app/Models/NotificationChoice.php` (per-user choices), `app/Http/Controllers/NotificationController.php`, `app/Http/Controllers/Api/NotificationApiController.php`, the header bell component, `app/Notifications/*`.
- The notice board / notice center (`app/Core/Services/Notice*.php`) — a different feature (broadcast notices); keep them separate but consistent.
- The approval engine's own reminders/escalation (`app/Core/Engines/Approval`, `abos:approvals-due`) — the notification module must USE them, not duplicate escalation logic.
- Module registration in each `app/Modules/*/module.php` (how modules declare menus, permissions, settings, notification kinds).
- `app/Core/Services/DataScope.php`, `ViewedBranch`, `ScopedToUserBranch` (company/branch walls), the audit service, `SettingsService` (control panel switches), `ReportEngine`, `DashboardEngine`.

## Hard rules
- **No AI of any kind** (owner's rule): rules, schedules, conditions and deterministic logic only.
- A notification only informs. It never changes a business record (no auto-approve, no stock adjustment, no payment).
- Every read path respects company → branch → record access: a notification about a paper is shown/opened only if the recipient may see that paper.
- Minimal sensitive data in email/SMS bodies; never secrets, passwords or tokens in notification rows or logs.
- Every user-facing string through lang files in BOTH `bn` and `en`, natural plain Bengali (the owner reads only Bengali). Comments in Bengali, matching the codebase. Screens fit 1920×1080, light and dark mode via `resources/css/tokens.css`. MariaDB `ONLY_FULL_GROUP_BY`, index names ≤ 64 characters, money never float.
- New module or extension of the core — follow the module-boundary guards in `tests/Feature/Architecture` (read them first).

## Phases (spec §19), each its own PR
1. **Core engine:** tables per spec §11 mapped onto the existing `notifications` / choices tables (extend, migrate carefully; existing rows keep working), event intake from every module's existing `NotificationService::send()` calls, per-recipient read/seen/archive status, priority and category, the bell + drawer + Notification Center + My Notifications (spec §9 A/B), RBAC and audit log. Duplicate prevention by idempotency key.
2. **Delivery channels:** In-app, Email, Web Push, Mobile push (existing FCM), SMS adapter (not connected); delivery queue, attempts log, provider references, retry with exponential backoff + jitter, dead-letter list with manual retry (permissioned), channel connection test, channel health.
3. **Rules and templates:** no-code rule editor (module, event, conditions, recipients by user/role/branch/department/responsible person, channels, priority, template, schedule, expiry, active/version/audit), template studio (bn + en, approved variables only, escaped output, preview, test send, versions, publish, rollback), recipient groups, schedule, user preferences, quiet hours and daily/weekly digest.
4. **Enterprise operations:** escalation through the approval engine's existing escalation, archive and retention (scheduled, idempotent), analytics dashboard on `DashboardEngine`, the 17 reports of spec §17 on `ReportEngine` (CSV/XLSX/PDF per permission).
5. **Readiness:** security tests (cross-company, branch-limited, no secrets in logs, template injection), failure simulation (provider down → in-app still works, ERP transaction never blocked), load sanity on the queue.

## Tests (must be real)
- Feature tests under `tests/Feature/Modules/<module or Core>/` in the house style (`RefreshDatabase`, `DemoSeeder`, `owner@abos.test`). Prove each test can fail: break the guarded line, see red, restore.
- Must cover: same event twice → one notification; a branch-limited or other-company user never sees/opens another's notification; read status is per user; a failed provider never fails the business transaction; retry stops on permanent errors; quiet hours defer normal but not critical; nothing in the module calls an AI or an unknown outside host.
- Set up MariaDB/MySQL in the sandbox if needed (`phpunit.xml`). Run the new tests, every module test that sends notifications, and the whole `tests/Feature/Architecture` directory; all green, or prove in the PR that a red was already red on `main`.
- `vendor/bin/pint` only on files you changed.

## Delivery
- Commit messages in English, plain sentences, ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- One PR per phase into `main`, titled "Notification Management, phase N: …". Body: what was built against each spec section, migrations, permissions, scheduled commands, switches (and their defaults), test counts, what is left and why, owner questions (SMS provider, email sender, quiet-hour defaults, escalation times). End with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
- Do not merge. The coordinator reviews and deploys after the live business's change freeze (about 21 October 2026).

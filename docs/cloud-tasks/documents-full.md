# Cloud task: the whole Documents (DOC) module, phase by phase

Repo `uboshq/abos` (Laravel 12, PHP 8.5, MariaDB). Work ONLY on branch `cloud/documents-phase1` (already started: check `git log` and build on what is there; never redo a finished step). Push after every step so progress is never lost. NEVER push to `main`, never force-push, never rewrite history, never merge. You have no server access; do not deploy. When a phase is done, open (or update) one pull request into `main` per phase and continue with the next phase on the same branch.

The owner's full design is `docs/ডকুমেন্ট-ম্যানেজমেন্ট-পরিকল্পনা.md` (25 sections). Read it completely first, and `app/Modules/Documents/Resources/lang/bn/plan.php`. This file turns it into an ordered build plan.

## Three hard rules from the owner (never break them)
1. **No external AI, ever.** "Document Intelligence" means ABE: rules, patterns, history and statistics, run on our own server or in the user's browser. No document or text may be sent to any outside server or API. OCR must be offline. No handwriting recognition by AI.
2. **Do not rebuild what exists.** Use the project's own engines: `ApprovalEngine` (approvals and signatures), the central `NotificationService` / notification kinds, `NumberSeriesEngine` (document numbers), the audit / `PaperTrail` services, the attachment engine, `ReportEngine` (reports), `DashboardEngine` (dashboard), `SettingsService` (control panel switches), `PrintEngine` (mPDF). Find them under `app/Core` and read how other modules use them before writing anything.
3. **The wall is Company → Branch → Department → Employee → Document**, using the existing `CompanyContext`, `DataScope`, `ScopedToUserBranch`, `ViewedBranch` rules. Every read path (list, detail, preview, download, print, search, report, export, API) must respect it.

## Environment facts that shape the design
- Live runs on shared cPanel hosting: PHP + MariaDB + Laravel scheduler by cron. No root, no long-running daemons, no system packages (so no server-side Tesseract binary). Plan OCR accordingly (see phase 5).
- MariaDB with `ONLY_FULL_GROUP_BY`: every GROUP BY must be valid under it. Index names ≤ 64 characters.
- The owner reads only Bengali: every user-facing string through lang files in BOTH `bn` and `en`, natural plain Bengali. Comments in the code are in Bengali, matching the codebase's style.
- Every screen fits 1920×1080 without page scroll; light AND dark mode via `resources/css/tokens.css`; document links open in the shared peek popup (`resources/views/components/shell/peek.blade.php`).
- Files live on the private disk, never under `public/`. Store a SHA-256 hash per file version.

## Phases (build in this order; each phase = its own commits and its own PR)

### Phase 1 — storage core (plan §1, §4, §5, §6, §9, §12 data part, §13 basic) — partly done on this branch
Documents and append-only versions tables, folders/categories, metadata, Document Center (search/filter/sort, folders), Upload (single + multiple), Document Detail (preview, details, versions, audit tabs), version control (v1.0 → v1.1 → v2.0, restore-as-new-version, an approved document is never overwritten), expiry date + "expiring in 7/30/90 days" filter, per-action permissions, company/branch walls, audit row on every view/download/upload/version/archive/restore. Finish what is missing, then its tests (below).

### Phase 2 — classification, permissions, admin, bin, search (§13, §14, §16, §19, §20 basic, §21, §22)
- Security levels PUBLIC / INTERNAL / CONFIDENTIAL / HIGHLY CONFIDENTIAL / RESTRICTED; RESTRICTED needs an extra permission. Enforced on every read path.
- Department and employee scope (use the HR module's departments/employees if present; otherwise a documents-owned department list).
- Document-level permissions (grant a user or role VIEW/DOWNLOAD/PRINT/SHARE/EDIT on one document).
- Status system: DRAFT, SUBMITTED, UNDER_REVIEW, CHANGES_REQUESTED, APPROVED, REJECTED, PUBLISHED, EXPIRED, ARCHIVED, DELETED.
- Recycle bin (who/when deleted, restore; permanent delete only with its own permission; audit kept).
- Archive screen.
- Advanced search: type, category, company, branch, department, owner, date range, expiry, status, version, security level, tags, and content (filled later by OCR text). Use MariaDB FULLTEXT where useful.
- Administration: document types, categories, number series (via `NumberSeriesEngine`), metadata fields, tags, storage settings (size limits, allowed types) — control-panel switches through `SettingsService`.

### Phase 3 — approval, notifications, expiry reminders (§10, §12, §23)
- Approval workflow through `ApprovalEngine` (declare the document action the way other modules declare approvals; flows are configured in the existing approval-flow screens): Draft → Submitted → Under Review → (Changes requested → Returned) → Approved → Published → Archived. Approval Queue page = a filtered view of the existing signature inbox.
- Notifications via the central notification service and kinds: new document, shared, approval required, approved, rejected, signature required/completed, expiry warning, expired, renewal required, updated, permission changed.
- Expiry reminders by a scheduled command: 90 → 60 → 30 → 15 → 7 → 1 days before, each sent once (idempotent), registered like the other scheduled commands.

### Phase 4 — signatures, sharing, relationships (§11, §15, share tab of §5)
- Signature Center on the existing approval/signature engine: single, multi, sequential signers; request, verify, history; sign / reject / request changes. Record who signed, when, and the version hash signed; a later version needs a new signature.
- Sharing inside ABOS (to users or roles, with optional expiry date and view-only/download rights). No public internet links in this phase.
- Relationships: link a document to a customer, supplier, purchase paper (RFQ, PO, bill), employee; show linked documents on those records using each module's existing attachment/drill points, without one module importing another's classes (check `tests/Feature/Architecture` boundary guards; use the registries/contracts the codebase already uses for cross-module facts).

### Phase 5 — Scan & OCR, offline (§7)
- Camera/scanner capture in the browser (file input with `capture`, multi-page).
- OCR must run without any outside server and without system packages: use **tesseract.js (WebAssembly) in the user's browser**, with the `ben` and `eng` trained-data files and the worker/core files **self-hosted from our own server** (committed or published as assets; never loaded from a CDN at runtime). Result text is sent back to our server and stored with the version (`document_ocr`), then indexed for content search.
- Review screen: show the recognised text and extracted fields (invoice no, date, supplier, amount) for a human to correct before saving.
- If tesseract.js cannot be self-hosted cleanly, stop and explain in the PR rather than using any external service.

### Phase 6 — Document Intelligence (ABE), rules only (§8)
- Classify: suggest type/category from keywords and patterns per document type (rules editable in administration).
- Extract data: regex/pattern extractors per type (invoice/bill no, dates, amounts in Bengali and English digits, party names matched against existing customers/suppliers).
- Summarize: extractive (key lines by rules), clearly labelled as such.
- Compare: text and metadata diff between two versions.
- Ask document: keyword search inside the document's own text with highlighted hits.
- Translate: only a small offline glossary for field labels; say so on screen. Never a model.

### Phase 7 — templates, editor, reports, dashboard, audit, retention (§3, §17, §18, §20 rest)
- Templates with variables and a simple editor; generate PDF through `PrintEngine` (mPDF, Bengali conjuncts).
- Reports on `ReportEngine`: register, summary, by type/department/branch/employee, upload/download, approval/rejection, signature, expiry/renewal, archive, version, storage; access and activity history (download/print/share/delete/restore).
- Dashboard on `DashboardEngine`: the KPIs of §3, activity chart, status split, recent documents.
- Audit Trail screen: user, action, date/time, IP, device; nobody can delete audit rows.
- Retention and archive policies (scheduled, idempotent, never deletes without the recycle-bin path).

## Tests for every phase (must be real)
- Feature tests under `tests/Feature/Modules/Documents/` in the style of the other modules (`RefreshDatabase`, `DemoSeeder`, users like `owner@abos.test`).
- Always cover: another company's user and a user limited to another branch get 404/403 on every read path; security level and document-level permissions hold on list/detail/preview/download/print/search/report/API; an approved document cannot be overwritten; every audited action writes its row; scheduled commands are idempotent; nothing ever calls an outside host (assert no HTTP client use in the module).
- Prove each test can fail: break the guarded line, see red, restore.
- Set up MariaDB/MySQL in the sandbox if needed (see `phpunit.xml`). Run the Documents tests AND the whole `tests/Feature/Architecture` directory each phase; all green, or prove in the PR that a red was already red on `main`.
- `vendor/bin/pint` only on files you changed.

## Delivery
- Commit messages in English, plain sentences, ending with:
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`
- One PR per phase into `main`, titled "Documents module, phase N: …". Body: what was built per plan section, every migration, every permission, every scheduled command, test counts, what is left and why. End with: `🤖 Generated with [Claude Code](https://claude.com/claude-code)`
- Do not merge. The coordinator reviews, merges and deploys (the live business is in a change freeze until about 21 October 2026).

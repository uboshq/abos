<!--
Architecture §12, as checkboxes.

Not every box applies to every change — a bug fix in a report does not
need a migration. Strike through what does not apply and say why in a
few words. An unticked box with no explanation is the one thing a
reviewer should stop on.

The box people skip is the browser one, and it is the box that catches
the most. A screen that passes every test still ships broken at 375px
if nobody looked at it there.
-->

## What changed, and why

<!-- The failure this fixes, or the thing this makes possible. Not a
list of files — the diff already says that. -->

## How it was verified

<!-- What you actually ran, and what it said. "Tests pass" is not a
sentence; "1829 passed, 0 failed" is. -->

---

## §12 — is it done

**The code**

- [ ] Migration · Model · Service · Policy · Request · Controller · Routes
- [ ] Business rules live in the Service, not the Controller
- [ ] `module.php` declares menu · permissions · doc_types · drill_sources · reports · settings

**What a user sees**

- [ ] Both languages, and the key sets match
- [ ] List · form · detail — all three, with pagination
- [ ] Every optional field has its Control Panel switch
- [ ] Looked at in a browser at **375 · 768 · 1280 · 1920 px**
- [ ] Printing: 58mm · 80mm · A4

**What must be true**

- [ ] Every figure is clickable through to what it is made of (Rule 1)
- [ ] Report totals reconcile with the ledger — **proved by a test**, not by reading
- [ ] Permission test: a user without the permission cannot open any screen
- [ ] Tenant test: one company cannot see another's data
- [ ] Deactivating works **and so does bringing it back**
- [ ] Money is DECIMAL and arithmetic is bcmath — no floats anywhere near it

**Before merging**

- [ ] CI is green (Pint · build · migrate:fresh · tests · rollback)
- [ ] No new migration edits an already-shipped one
- [ ] Anything deliberately left out is written down, with the reason

---

<!--
For the reviewer:

Do not merge with the browser box unticked. Everything else on this
list has a test somewhere that would eventually catch it; that one has
nobody but you.
-->

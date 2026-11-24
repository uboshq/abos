<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models\Concerns;

/**
 * ⭐ A chat and a call belong to the people in them, not to the company that was open when they started (owner, 24 Nov 2026:
 * "কল … যাকে করা হয় তার অ্যাপসেও যায় না, ওয়েবেও যায় না, এবং এর ডিটেলসও কোনো কিছু থাকতেছে না").
 *
 * ── What went wrong on live ─────────────────────────────────────────────
 * The owner (open company 5) called Abu Kawser (ADI, company 3). The call row was stamped company 5, and every read of a call
 * or a chat was filtered by the reader's *open* company — so Abu Kawser's "is someone calling me" never saw it, the ring
 * timed out as missed after 45 s, and both call logs showed nothing. Chats started across companies vanished the same way.
 *
 * ── Why this is safe ────────────────────────────────────────────────────
 * Every messenger read is already bound to the person: threads through membership ([[MessengerAccess::member()]],
 * `Thread::of()`), messages through their thread, calls through caller/callee ([[CallService::isParty()]]). The company
 * wall added nothing there but this bug. ⛔ The one company-wide view — the audit page — filters by the open company
 * explicitly ([[MessengerAuditController]]).
 * ⓘ Rows are still stamped with the company they started in ([[BelongsToCompany]] creating hook) — only the read wall lifts.
 */
trait FollowsThePerson
{
    public static function sharesAcrossCompanies(): bool
    {
        return true;
    }
}

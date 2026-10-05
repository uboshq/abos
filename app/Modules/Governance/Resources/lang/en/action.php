<?php

declare(strict_types=1);

return [
    'created' => 'Created',
    'updated' => 'Edited',
    'deleted' => 'Deleted',
    'restored' => 'Restored',
    'confirmed' => 'Confirmed',
    'cancelled' => 'Cancelled',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'end_session' => 'Sign it out',
    'end_other_sessions' => 'Sign out everywhere else',
    'only_failed' => 'Only failures',
    'mark_seen' => 'Seen',
    'show_seen_too' => 'Include seen',
    'acknowledge_all' => 'Mark all seen — clear the list',
    'portal_enabled' => 'Portal opened',
    'portal_disabled' => 'Portal closed',
    'portal_password_set' => 'Portal password set',
    'password_set' => 'Password set',
    'password_reset' => 'Password reset by the user',
    'roles_changed' => 'Roles changed',
    // Kept separate from roles_changed on purpose: an audit starts with
    // "who holds the biggest key, and since when". Buried under the same
    // label as ten other role edits, that answer takes opening every row.
    'ownership_transferred' => 'Ownership transferred',
    'companies_changed' => 'Company access changed',
    'scopes_changed' => 'Data scope changed',
    'look_published' => 'Look published',
    'look_reverted' => 'Look reverted',
    'reopened' => 'Reopened',
    'discount_approved' => 'Discount approved',
    'overridden' => 'Duplicate allowed',
    'two_step_reset' => 'Two-step reset',
    'two_step_off' => 'Two-step turned off',
    /*
     * Not only the eight in [[AuditTrail::ACTIONS]] — services that name their
     * own action land here too. These four were added on 29 September 2026,
     * after the audit trail printed `governance::action.repriced` on live.
     */
    'repriced' => 'Lot repriced',
    'expiry_corrected' => 'Expiry corrected',
    'shift_closed' => 'Shift closed',
    'sent_back' => 'Sent back',
    // Credit block set and cleared, with a reason (credit and collections, 5 Oct 2026)
    'credit_blocked' => 'Credit blocked',
    'credit_unblocked' => 'Credit allowed again',
    'db_restored' => 'Books restored from backup',
    'db_restore_failed' => 'Books restore failed',
    // ⭐ উল্টো কাগজ আর বাতিল-ইনভয়েস (মালিকের সংস্করণ ২, ৪ অক্টোবর ২০২৬)
    'reversed' => 'Reversed by a reversal paper',
    'cancelled_by_cxl' => 'Reversed by a cancellation invoice',
    'counter_bill_voided' => 'Counter bill voided',
    // ⭐ একই বিল জেনেশুনে আবার — কাউন্টারের "আবার করুন" টিক (মালিক, ৫ অক্টোবর ২০২৬)
    'counter_repeat_bill' => 'Same bill made again on purpose',
    // ⭐ গুদাম বদলে একই মানুষ পাঠিয়ে গ্রহণ — দুজনের কাজের সুইচে কেবল সুপার অ্যাডমিন পারেন (অডিট ম৪, ৫ অক্টোবর ২০২৬)
    'received_by_its_sender' => 'Received by the person who sent it (super admin)',
    // ⓘ লট বলা ছিল, কিন্তু সেই লটের স্তরে খরচ নেই — বাকিটা আগের-আসা নিয়মে, নীরবে নয় ([[CostLayerService::issue()]])
    'lot_cost_fell_back' => 'Lot had no cost layer left; cost drawn first-in-first-out',
];

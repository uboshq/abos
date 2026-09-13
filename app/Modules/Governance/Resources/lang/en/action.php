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
];

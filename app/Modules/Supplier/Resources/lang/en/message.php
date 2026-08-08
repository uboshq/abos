<?php

declare(strict_types=1);

return [
    'code_auto' => 'Leave blank and the code will be filled in.',

    'bn_name_hint' => 'Without it the English name is shown everywhere.',
    'contact_hint' => 'At a large supplier, the office number gets you nowhere.',
    'bin_hint' => 'Needed to withhold VAT at source on purchases.',

    'credit_limit_hint' => 'How much credit they give us. 0 means no stated limit. '
        .'Going over blocks nothing — it is only shown, because the limit is their decision.',

    'opening_note' => 'What was owed to them before this system. Can only be set now — '
        .'to change it later, post a journal voucher, or the ledger and this list would disagree.',

    'search_placeholder' => 'Search supplier or mobile...',

    'none_yet' => 'No suppliers yet.',
    'created' => 'Supplier added.',
    'updated' => 'Supplier updated.',
    'deactivated' => 'Supplier deactivated.',
    'activated' => 'Supplier is active again.',

    'no_transactions' => 'No transactions for this supplier yet.',
    'over_limit' => 'Owed more than the limit they allow — the next delivery may be held.',
    'deactivate_confirm' => 'This supplier will be deactivated. Past transactions and balances stay. Continue?',

    'count' => '{0} No suppliers|{1} 1 supplier|[2,*] :count suppliers',
    'confirm_deactivate' => 'Deactivate this supplier? Their history and dues stay; only new buying stops.',
];

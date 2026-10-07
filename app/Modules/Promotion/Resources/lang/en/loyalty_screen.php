<?php

declare(strict_types=1);

/*
 * ⓘ Loyalty points screen — [[PromotionLoyaltyController]].
 *
 * ⚠️ A separate file from `loyalty.php`, which holds the ledger service's messages.
 */
return [
    'title' => 'Customer points',
    'search' => 'Name, code or phone',

    'customer' => 'Customer',
    'code' => 'Code',
    'balance' => 'Spendable points',
    'worth' => 'Worth in taka',
    'open' => 'View ledger',
    'back' => 'All customers',

    'when' => 'When',
    'kind' => 'Kind',
    'points' => 'Points',
    'expires_on' => 'Spend by',
    'never' => 'Never expires',
    'source' => 'Document',
    'running' => 'Balance then',

    'balance_note' => 'Expired points are left out, even before the nightly run writes them off.',

    'none' => 'No customer holds points right now.',
    'no_entries' => 'Nothing has been written to this customer\'s ledger yet.',
];

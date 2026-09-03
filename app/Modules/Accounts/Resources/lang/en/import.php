<?php

declare(strict_types=1);

return [
    'chart_of_accounts' => 'Chart of accounts',
    'opening_balance' => 'Account opening balances',

    // Shown on the result screen when some opening-balance rows failed. Opening
    // balances are a document, not a list: a partial load leaves the balance
    // sheet wrong while the trial balance still ties, and no one sees it. Points
    // to Books check rather than running it (that walks the whole ledger).
    'opening_partial_warning' => '⚠️ Opening balances loaded only in part, so the books do not balance — the balance sheet will be wrong. Fix the remaining rows and upload again, then run Accounts → Books check.',

    /*
     * Nothing is written now instead of writing half, so the message
     * changed with it: the one above reports damage, this one reports
     * that none was done.
     */
    'opening_refused' => 'Nothing was loaded — opening balances go in whole or not at all. Half of them would leave the books out of balance, and working out which rows went in is the hard part. Fix the rows listed below and upload again; nothing in your system has changed yet.',
];

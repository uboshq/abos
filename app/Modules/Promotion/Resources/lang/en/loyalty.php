<?php

declare(strict_types=1);

/* ⓘ Loyalty points ledger — [[LoyaltyLedger]], [[LoyaltyKind]] */
return [
    'kind' => [
        'earn' => 'Earned',
        'redeem' => 'Redeemed',
        'expire' => 'Expired',
        'reverse' => 'Reversed',
    ],

    'points' => 'Points',
    'balance' => 'Points balance',
    'expires_on' => 'Valid until',

    'not_positive' => 'Points to redeem must be more than zero.',
    'not_enough' => 'Only :balance points are available; :asked were asked for.',
    'already_redeemed' => 'Points have already been redeemed on this document.',
    'customer_unknown' => 'This customer was not found in this company.',

    'setting_valid_days' => 'Loyalty points stay valid for (days, 0 = never expire)',
    'setting_point_value' => 'Value of one loyalty point (taka)',
];

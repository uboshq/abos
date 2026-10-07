<?php

declare(strict_types=1);

// Deposit request with a bank slip — 1 October 2026 ([[DepositSlip]])
return [
    'title' => 'Deposit request — with the bank slip',
    'hint' => 'When the shop pays into the bank, send a photo of the slip. The due drops only after accounts check and accept it.',
    'customer' => 'Shop / customer',
    'slip' => 'Bank slip (photo or PDF, up to 5 MB)',
    'required' => 'A bank deposit request needs the slip photo.',
    'sent' => 'Request sent (no. :no) — accounts will check it.',
    'by' => '[sent by: :name]',
    'new' => 'New request with slip',
    'view' => 'View slip',
    'none' => 'No slip',

    'bills' => 'Against which bills (optional)',
    'bills_hint' => 'Pick bills and the money is matched to them when accepted; pick none and the whole amount goes to your account.',
    'bills_none' => 'No open bills.',
    'bills_show' => 'Show this customer\'s open bills',
    'bill_due' => 'Due',
    'bill_pay' => 'On this bill',
    'bill_not_theirs' => 'The bill picked is not this customer\'s.',
    'bill_not_open' => 'Bill :no is not confirmed; a deposit cannot be shown against it.',
    'bill_twice' => 'Bill :no is picked twice.',
    'bill_over_due' => 'Bill :no has ৳:due due; no more than that can go on it.',
    'bills_over_amount' => 'The bills add up to ৳:sum but the deposit is ৳:amount; the bills cannot take more than the deposit.',
    'bills_named' => 'Bills',
];

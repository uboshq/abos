<?php

declare(strict_types=1);

// ⭐ The overview before a voucher or note is posted ([[VoucherOverview]], 4 Oct 2026)
return [
    'title' => ':type :no: overview',
    'date' => 'Date',
    'party' => 'Party',
    'narration' => 'For',
    'debit' => 'Debit',
    'credit' => 'Credit',
    'debit_total' => 'Total debit',
    'credit_total' => 'Total credit',
    'unbalanced' => 'Debit and credit totals do not match. It will not post like this.',
    'signed' => 'Already signed.',
    'rejected' => 'The signature was refused and the voucher has not changed since. It will not post; settle the reason first.',
    'awaiting' => 'This voucher needs a signature. Posting sends it for signature; it reaches the books only once signed.',
];

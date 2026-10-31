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
    'signed' => 'Already approved.',
    'rejected' => 'The approval was refused and the voucher has not changed since. It will not post; settle the reason first.',
    // ⓘ The five approval states ([[VoucherApproval::decide()]]); the list and the phone say the same (24 Oct 2026; ERP words, 25 Oct)
    'awaiting' => 'Awaiting approval. It reaches the books only once approved.',
    'stale' => 'The voucher changed after the approval was asked for. Posting sends it for approval again.',
    'needs' => 'This voucher needs an approval. Posting sends it for approval; it reaches the books only once approved.',
];

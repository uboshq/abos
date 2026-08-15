<?php

declare(strict_types=1);

return [
    'invoice_total' => 'An invoice total matches its lines',
    'invoice_total_q' => 'Whether the stored total equals the sum of the invoice lines.',
    'invoice_total_broken' => 'When they differ the customer sees one figure on paper and another reaches the books — the argument starts when you ask for the money.',

    'unposted' => 'Confirmed invoices reached the ledger',
    'unposted_q' => 'Whether any confirmed invoice is sitting there with no posting.',
    'unposted_broken' => 'Goods have gone, paper is printed, and neither the income nor the receivable was ever booked — the sales list still shows the invoice as fine.',

    'total_detail' => 'On the invoice :stored · lines add to :lines',
    'unposted_detail' => ':amount invoice with no posting',
];

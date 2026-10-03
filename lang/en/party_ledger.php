<?php

declare(strict_types=1);

return [
    'search' => 'Number, narration or amount',
    'kind' => 'Document type',
    'any_kind' => 'Every type',
    'side' => 'Side',
    'any_side' => 'Debit and credit',
    'debit_only' => 'Debit only',
    'credit_only' => 'Credit only',
    'kind_customer' => [
        'bill' => 'Sale',
        'money' => 'Receipt',
    ],
    'kind_supplier' => [
        'bill' => 'Purchase',
        'money' => 'Payment',
    ],
    'kind_return' => 'Return',
    'kind_note' => 'Note',
    'kind_opening' => 'Opening balance',
    'kind_journal' => 'Journal and other',
    'balance_hint' => 'Under a filter too, each balance counts every entry in the books',
];

<?php

declare(strict_types=1);

return [
    'method' => [
        'legend' => 'Method',
        'cash' => 'Cash',
        'mfs' => 'MFS',
        'transfer' => 'Bank transfer',
        'deposit' => 'Cash deposit',
        'cheque' => 'Cheque',
        'card' => 'Card',
    ],

    'field' => [
        'transfer_mode' => 'Transfer mode',
        'reference' => 'Reference / TrxID',
        'trx_id' => 'TrxID',
        'value_date' => 'Value date',
        'bank_charge' => 'Bank charge (৳)',
        'mfs_charge' => 'MFS charge (৳)',
        'deposit_slip' => 'Deposit slip no.',
        'cheque_no' => 'Cheque no.',
        'cheque_date' => 'Cheque date',
        'cheque_bank' => 'Cheque bank',
    ],

    'hint' => [
        'reference_needed' => 'Needed to post',
        'value_date' => 'The day the bank actually moved the money',
    ],

    'charge_line' => [
        'in' => 'Bank charge ৳:charge is our expense — it goes to ":head"; the party is credited the full ৳:amount.',
        'out' => 'Bank charge ৳:charge is our expense — it goes to ":head"; the party\'s balance drops by the full ৳:amount.',
        'head_bank' => 'Bank charges',
        'head_mfs' => 'Mobile banking charges',
    ],

    'validation' => [
        'reference_needed' => 'Posting by ":method" needs its reference — it is how the bank statement is matched and the same money is never taken twice.',
    ],

    'pdc' => [
        'in' => 'Post-dated cheque — it stays "in clearing" and reaches the bank only when it clears.',
        'out' => 'Post-dated cheque — no money leaves the bank before the cheque date.',
    ],
];

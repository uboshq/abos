<?php

declare(strict_types=1);

return [
    'title' => 'Conduct',
    'none' => 'No conduct recorded for this party.',
    'add' => 'Record conduct',
    'type_field' => 'What happened',
    'note' => 'Note',
    'note_hint' => 'Required when the type is “Other”.',
    'record' => 'Record',
    'retire' => 'Retire',
    'retired' => 'Retired',
    'active_heading' => 'Active',
    'retired_heading' => 'No longer flagged',
    // :who recorded it, :date when — an old flag should read lighter than a fresh one
    'by_line' => ':who · :date',
    'retired_by_line' => 'Retired by :who · :date',

    // OTHER needs a note, or it is a free-text back door
    'note_required' => 'Choose “Other” only with a note saying what happened.',
    'invalid_type' => 'That is not a known conduct type.',
    'recorded' => 'Conduct recorded.',
    'was_retired' => 'The flag was retired — the history stays.',

    'severity' => [
        'good' => 'Good',
        'notice' => 'Worth knowing',
        'risk' => 'Risk',
    ],

    'group' => [
        'money' => 'Money',
        'delivery' => 'Delivery',
        'relationship' => 'Relationship',
    ],

    'type' => [
        'LATE_PAYMENT' => 'Pays late',
        'CHEQUE_DISHONOURED' => 'Cheque dishonoured',
        'DISPUTES_INVOICE' => 'Disputes the invoice',
        'PAYS_ON_TIME' => 'Pays on time',
        'PAYS_IN_ADVANCE' => 'Pays in advance',
        'SLOW_UNLOADING' => 'Slow to unload',
        'ADVANCE_NOTICE_REQUIRED' => 'Needs advance notice',
        'FIXED_DELIVERY_WINDOW' => 'Fixed delivery window',
        'NO_LARGE_VEHICLE_ACCESS' => 'No large-vehicle access',
        'REFUSES_AT_GATE' => 'Refuses at the gate',
        'QUICK_UNLOADING' => 'Quick to unload',
        'KEY_ACCOUNT' => 'Key account',
        'DORMANT' => 'Dormant',
        'OTHER' => 'Other',
    ],
];

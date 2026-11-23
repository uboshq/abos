<?php

declare(strict_types=1);

// ⭐ Messages to parties — the log: WhatsApp and SMS in one list, each with its state (step 3, owner "ok" 23 Nov 2026; sample HUXqpfhxng8823eEFrvH54 tab গ)
return [
    'title' => 'Messages to parties — log',
    'tabs' => [
        'all' => 'All',
        'delivered' => 'Delivered',
        'waiting' => 'Waiting',
        'failed' => 'Failed',
    ],
    'tabs_label' => 'By state',
    'noun' => 'messages',
    'search' => 'Number, party or paper number',
    'empty' => 'Nothing has been sent to anyone yet.',

    // today's figures
    'today' => [
        'sent' => 'Sent today',
        'delivered' => 'Delivered',
        'waiting' => 'Waiting',
        'failed' => 'Failed',
    ],

    // columns
    'when' => 'When',
    'party' => 'Party',
    'phone' => 'Number',
    'paper' => 'Paper',
    'road' => 'Channel',
    'how' => 'Who/how',
    'state' => 'State',

    // filter
    'date' => 'Date',
    'who' => 'Sent by',
    'party_account' => 'Party account (reminder)',

    // the rule's name in the paper column (when there is no template)
    'rules' => [
        'due_reminder' => 'Due reminder',
        'payment_received' => 'Payment received',
    ],

    // who/how
    'button' => ':who · button',
    'auto' => 'Automatic · :road',
    'cloud' => 'Cloud',
    'office_phone' => 'Office phone',

    // state
    'states' => [
        'opened' => 'Opened',
        'waiting' => 'Waiting',
        'waiting_phone' => 'Waiting on the phone',
        'sent' => 'Sent :time',
        'delivered' => 'Delivered :time',
        'read' => 'Read :time',
        'failed' => 'Failed',
    ],
    'fell_back' => ':reason → went by SMS',
    'no_reason' => 'Reason not known',

    // row actions
    'menu' => [
        'view' => 'See the text',
        'again' => 'Send again',
        'other' => 'Send the other way',
        'ledger' => 'Open the party ledger',
    ],
    'other_to' => [
        'cloud' => 'WhatsApp',
        'wame' => 'WhatsApp',
        'phone' => 'SMS',
    ],
    'road_off' => [
        'whatsapp' => 'The WhatsApp road is off',
        'sms' => 'The SMS road is off, or no office phone is set up',
        'sms_abroad' => 'SMS goes to Bangladesh mobiles only',
        'cloud' => 'WhatsApp Cloud is not ready, or this text is not approved by Meta',
    ],
    'resent' => 'Sent again — a new row in the log.',
    'resent_failed' => 'Could not send: :reason',

    // the text page
    'message_title' => 'The text that went',
    'language' => 'Language',
    'template' => 'From the text',
    'back' => 'Back to the log',

    // the app's doors
    'party_phone' => 'Party number',
    'rule' => 'Automatic',
    'rules' => ['due_reminder' => 'Due reminder', 'payment_received' => 'Payment received'],
];
